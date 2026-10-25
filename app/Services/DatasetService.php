<?php

namespace App\Services;

use App\Models\DatasetModel;
use App\Models\DatasetItemModel;

/**
 * DatasetService — parse, clean and export instruction-tuning datasets
 * (Alpaca style: instruction / input / output). Accepts JSON arrays, JSONL,
 * and CSV. Token counts are a byte-length estimate (~1 token / 3 bytes) that
 * works reasonably for both Sinhala (UTF-8 multibyte) and English.
 */
class DatasetService
{
    protected DatasetModel $datasets;
    protected DatasetItemModel $items;

    public function __construct()
    {
        $this->datasets = new DatasetModel();
        $this->items    = new DatasetItemModel();
    }

    /**
     * Import file content into a dataset. Returns ['imported'=>int,'skipped'=>int].
     * Duplicate rows (same instruction+input+output) already in the dataset are skipped.
     *
     * @throws \InvalidArgumentException on unparseable content
     */
    public function import(int $datasetId, string $content, string $ext): array
    {
        $rows = match (strtolower($ext)) {
            'jsonl'        => $this->parseJsonl($content),
            'json'         => $this->parseJson($content),
            'csv'          => $this->parseCsv($content),
            default        => $this->parseAny($content),
        };
        if (! $rows) {
            throw new \InvalidArgumentException('No instruction/output rows found in the file.');
        }

        $existing = array_column(
            $this->items->builder()->select('hash')->where('dataset_id', $datasetId)->get()->getResultArray(),
            'hash'
        );
        $seen = array_fill_keys($existing, true);

        $now = date('Y-m-d H:i:s');
        $batch = [];
        $imported = 0;
        $skipped  = 0;
        foreach ($rows as $r) {
            $instruction = trim((string) ($r['instruction'] ?? ''));
            $input       = trim((string) ($r['input'] ?? ''));
            $output      = trim((string) ($r['output'] ?? ''));
            if ($instruction === '' || $output === '') { $skipped++; continue; }
            $hash = sha1($instruction . '|' . $input . '|' . $output);
            if (isset($seen[$hash])) { $skipped++; continue; }
            $seen[$hash] = true;
            $batch[] = [
                'dataset_id'  => $datasetId,
                'instruction' => $instruction,
                'input'       => $input !== '' ? $input : null,
                'output'      => $output,
                'tokens'      => $this->estimateTokens($instruction . $input . $output),
                'hash'        => $hash,
                // Rows that come from the same source item (a speaker, a document, or a sentence and its Singlish
                // form) share a group_key, so freeze() keeps them in one split (TRNPIPE 003).
                'group_key'   => ($g = trim((string) ($r['group_key'] ?? ''))) !== '' ? mb_substr($g, 0, 191) : null,
                'created_at'  => $now,
            ];
            $imported++;
            if (count($batch) >= 500) {
                $this->items->builder()->insertBatch($batch);
                $batch = [];
            }
        }
        if ($batch) {
            $this->items->builder()->insertBatch($batch);
        }
        $this->recount($datasetId);
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** Remove exact duplicates (keeps the earliest row per hash). */
    public function dedupe(int $datasetId): int
    {
        $db = $this->items->db;
        $before = $this->items->where('dataset_id', $datasetId)->countAllResults();
        $db->query(
            'DELETE d1 FROM dataset_items d1
             JOIN dataset_items d2 ON d2.dataset_id = d1.dataset_id AND d2.hash = d1.hash AND d2.id < d1.id
             WHERE d1.dataset_id = ?', [$datasetId]
        );
        $this->recount($datasetId);
        return $before - $this->items->where('dataset_id', $datasetId)->countAllResults();
    }

    /**
     * Clean pass: trim/normalise whitespace, drop rows with an empty
     * instruction or output after trimming. Returns rows removed.
     */
    public function clean(int $datasetId): int
    {
        $removed = 0;
        $page = 0;
        while (true) {
            $rows = $this->items->where('dataset_id', $datasetId)
                ->orderBy('id', 'ASC')->findAll(500, $page * 500);
            if (! $rows) break;
            foreach ($rows as $r) {
                $instruction = trim(preg_replace('/[ \t]+/', ' ', (string) $r['instruction']));
                $input       = trim(preg_replace('/[ \t]+/', ' ', (string) ($r['input'] ?? '')));
                $output      = trim((string) $r['output']);
                if ($instruction === '' || $output === '') {
                    $this->items->delete($r['id']);
                    $removed++;
                    continue;
                }
                if ($instruction !== $r['instruction'] || $input !== (string) ($r['input'] ?? '') || $output !== $r['output']) {
                    $this->items->update($r['id'], [
                        'instruction' => $instruction,
                        'input'       => $input !== '' ? $input : null,
                        'output'      => $output,
                        'hash'        => sha1($instruction . '|' . $input . '|' . $output),
                        'tokens'      => $this->estimateTokens($instruction . $input . $output),
                    ]);
                }
            }
            $page++;
        }
        $this->recount($datasetId);
        return $removed;
    }

    /** Refresh the dataset's item_count / token_estimate rollups. */
    public function recount(int $datasetId): void
    {
        $row = $this->items->builder()
            ->select('COUNT(*) n, COALESCE(SUM(tokens),0) t')
            ->where('dataset_id', $datasetId)->get()->getRowArray();
        $this->datasets->update($datasetId, [
            'item_count'     => (int) ($row['n'] ?? 0),
            'token_estimate' => (int) ($row['t'] ?? 0),
        ]);
    }

    /** Stream the dataset as Alpaca JSONL, optionally limited to frozen splits. Yields one line per item. */
    public function exportJsonl(int $datasetId, ?array $splits = null): \Generator
    {
        $page = 0;
        while (true) {
            $q = $this->items->where('dataset_id', $datasetId);
            if ($splits !== null) $q = $q->whereIn('split', $splits); // locked test split never leaves (TRNPIPE 003)
            $rows = $q->orderBy('id', 'ASC')->findAll(500, $page * 500);
            if (! $rows) break;
            foreach ($rows as $r) {
                yield json_encode([
                    'instruction' => $r['instruction'],
                    'input'       => (string) ($r['input'] ?? ''),
                    'output'      => $r['output'],
                ], JSON_UNESCAPED_UNICODE) . "\n";
            }
            $page++;
        }
    }

    /** ~1 token per 3 bytes — a workable estimate for mixed Sinhala/English. */
    public function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 3);
    }

    // ---- Parsers -------------------------------------------------------------

    private function parseJsonl(string $content): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $j = json_decode($line, true);
            if (is_array($j)) $rows[] = $this->normaliseRow($j);
        }
        return array_filter($rows);
    }

    private function parseJson(string $content): array
    {
        $j = json_decode($content, true);
        if (! is_array($j)) {
            throw new \InvalidArgumentException('Invalid JSON.');
        }
        // Either a bare array of rows or {data:[...]} style.
        $list = array_is_list($j) ? $j : ($j['data'] ?? $j['items'] ?? []);
        return array_values(array_filter(array_map(
            fn ($r) => is_array($r) ? $this->normaliseRow($r) : null,
            $list
        )));
    }

    private function parseCsv(string $content): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $header = fgetcsv($fh);
        if (! $header) { fclose($fh); return []; }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        $rows = [];
        while (($cols = fgetcsv($fh)) !== false) {
            $row = [];
            foreach ($header as $i => $h) {
                $row[$h] = $cols[$i] ?? '';
            }
            if ($n = $this->normaliseRow($row)) $rows[] = $n;
        }
        fclose($fh);
        return $rows;
    }

    /** Sniff: try JSON first, then JSONL, then CSV. */
    private function parseAny(string $content): array
    {
        $t = ltrim($content);
        if (str_starts_with($t, '[') || str_starts_with($t, '{')) {
            try { return $this->parseJson($content); } catch (\Throwable) {}
            $rows = $this->parseJsonl($content);
            if ($rows) return $rows;
        }
        $rows = $this->parseJsonl($content);
        return $rows ?: $this->parseCsv($content);
    }

    /** Map common field aliases onto instruction/input/output. */
    private function normaliseRow(array $r): ?array
    {
        $get = static function (array $r, array $keys): string {
            foreach ($keys as $k) {
                if (isset($r[$k]) && is_string($r[$k]) && trim($r[$k]) !== '') return $r[$k];
            }
            return '';
        };
        $instruction = $get($r, ['instruction', 'question', 'prompt', 'q']);
        $output      = $get($r, ['output', 'answer', 'response', 'completion', 'a']);
        if ($instruction === '' || $output === '') return null;
        return [
            'instruction' => $instruction,
            'input'       => $get($r, ['input', 'context']),
            'output'      => $output,
            'group_key'   => $get($r, ['group_key', 'group']),
        ];
    }
}
