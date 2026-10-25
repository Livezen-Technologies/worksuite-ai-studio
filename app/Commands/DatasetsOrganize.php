<?php

namespace App\Commands;

use App\Services\DatasetService;
use App\Services\EngineDatasetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `spark datasets:organize [--mix] [--target 60000] [--dry-run]` — categorise every dataset for training and, with
 * --mix, build one balanced, frozen training mix from the approved trainable ones.
 *
 * Categories: instruction, qa, transliteration, translation, corpus (raw text, shown as continuation tasks), speech,
 * evaluation (held-out test sets, never trained on) and reference (kept but not for training).
 *
 * The mix gives each trainable category a fixed share (MIX_SHARES) so one huge source cannot drown the rest (7.1M
 * Singlish word pairs would otherwise be 46% of all rows). Inside a category, sources get a share proportional to the
 * square root of their size (big sources still give more, small specialised ones are not lost). Rows are picked in
 * hash order — deterministic and effectively random — after a quality filter: output at least 30% Sinhala letters
 * (transliteration: any Sinhala), 2–4,000 characters. The mix is frozen 80/10/10 by group, so the training run has a
 * locked held-out test split and a manifest hash for lineage.
 */
class DatasetsOrganize extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'datasets:organize';
    protected $description = 'Categorise datasets for training and build a balanced, frozen training mix.';
    protected $usage       = 'datasets:organize [--mix] [--target 60000] [--dry-run]';

    public const CATEGORIES = ['instruction', 'qa', 'transliteration', 'translation', 'corpus', 'speech', 'evaluation', 'reference'];

    /** Share of the mix per trainable category. */
    private const MIX_SHARES = ['instruction' => 0.40, 'qa' => 0.20, 'transliteration' => 0.15, 'translation' => 0.10, 'corpus' => 0.15];

    /** First matching rule wins: [pattern on name, category]. Explicit evaluation/reference rules come first. */
    private const RULES = [
        ['/NOT for training|restricted|reference|evaluation only|\btest\b/i', null], // decided by rights below
        ['/\bASR\b|SinhaSpeech|OpenSLR/', 'speech'], // not "figures of speech"
        ['/triplets|translation|Sinhala–English|sin2eng/i', 'translation'],
        ['/word pairs|transliteration|Singlish|Romanised|code-mixed/i', 'transliteration'],
        ['/Q&A|QA\b|question/i', 'qa'],
        ['/corpus|continuation|SinhalaLM data\)$|Acts and Bills|historical|news categories|text \(/i', 'corpus'],
    ];

    public const MIX_NAME = 'WorkSuite Sinhala v2 — balanced training mix';

    public function run(array $params)
    {
        $db     = \Config\Database::connect();
        $dry    = $this->flag($params, 'dry-run');
        $target = (int) ($this->opt($params, 'target') ?: 60000);

        $all = $db->table('datasets')->select('id, name, task, category, item_count, rights_status, manifest_hash')->orderBy('id')->get()->getResultArray();
        CLI::write('Categories', 'yellow');
        foreach ($all as &$d) {
            if ($d['name'] === self::MIX_NAME || str_starts_with($d['name'], 'WorkSuite Sinhala v1')) {
                $cat = 'instruction';
            } else {
                $cat = $this->categorise($d['name']);
            }
            $task = match ($cat) { 'speech' => 'asr', 'transliteration' => 'transliteration', 'translation' => 'translation', default => 'text' };
            CLI::write(sprintf('  #%-3d %-15s %-10s %9s  %s', $d['id'], $cat, $d['rights_status'], number_format((int) $d['item_count']), $d['name']));
            if (! $dry && ($d['category'] !== $cat || $d['task'] !== $task)) {
                $db->table('datasets')->where('id', $d['id'])->update(['category' => $cat, 'task' => $task]);
            }
            $d['category'] = $cat;
        }
        unset($d);

        if (! $this->flag($params, 'mix')) return EXIT_SUCCESS;

        // ---- the mix
        $plan = [];
        foreach (self::MIX_SHARES as $cat => $share) {
            $src = array_values(array_filter($all, fn ($d) => $d['category'] === $cat && $d['rights_status'] === 'approved'
                && (int) $d['item_count'] > 0 && $d['name'] !== self::MIX_NAME && empty($d['manifest_hash']))); // frozen sets keep their test split out
            if (! $src) { CLI::write("  {$cat}: no approved source — its share is left out.", 'yellow'); continue; }
            $weights = array_map(fn ($d) => sqrt((int) $d['item_count']), $src);
            $sum     = array_sum($weights);
            foreach ($src as $i => $d) {
                $plan[] = ['id' => (int) $d['id'], 'name' => $d['name'], 'cat' => $cat,
                           // small specialised sources (identity, dental Q&A) are taken whole up to 500 rows
                           'want' => min((int) $d['item_count'], max(500, (int) round($target * $share * $weights[$i] / $sum)))];
            }
        }
        CLI::write("\nMix plan (target " . number_format($target) . ' rows)', 'yellow');
        foreach ($plan as $p) CLI::write(sprintf('  %-15s #%-3d %7s  %s', $p['cat'], $p['id'], number_format($p['want']), $p['name']));
        if ($dry) return EXIT_SUCCESS;

        $old = $db->table('datasets')->where('name', self::MIX_NAME)->get()->getRowArray();
        if ($old && $old['manifest_hash'] && $db->table('training_jobs')->where('dataset_id', $old['id'])->countAllResults()) {
            CLI::error("The existing mix #{$old['id']} is frozen and used by a training job; rename it before building a new one.");
            return EXIT_ERROR;
        }
        if ($old) {
            $db->table('dataset_items')->where('dataset_id', $old['id'])->delete();
            $db->table('datasets')->where('id', $old['id'])->delete();
        }
        $now = date('Y-m-d H:i:s');
        $db->table('datasets')->insert([
            'name' => self::MIX_NAME, 'language' => 'si', 'task' => 'text', 'category' => 'instruction',
            'description' => 'Built by datasets:organize from approved sources with fixed category shares. Non-commercial (CC BY-NC-SA 4.0).',
            'permitted_use' => 'non-commercial', 'rights_status' => 'approved', 'rights_ref' => 'Inherits the approvals of its sources',
            'source_ref' => 'datasets:organize', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $mixId = (int) $db->insertID();

        $sinhalaShare = "CHAR_LENGTH(REGEXP_REPLACE(output, '[^\\\\x{0D80}-\\\\x{0DFF}]', '')) / CHAR_LENGTH(output)";
        foreach ($plan as $p) {
            if ($p['want'] <= 0) continue;
            $quality = in_array($p['cat'], ['transliteration', 'translation'], true) // either side may be English or Latin
                ? "CONCAT(instruction, COALESCE(input, ''), output) REGEXP '[\\\\x{0D80}-\\\\x{0DFF}]'"
                : "{$sinhalaShare} >= 0.3";
            $t0 = microtime(true);
            // Corpus rows become continuation tasks with the source's own instruction; group_key keeps a source's
            // groups together so the frozen split cannot leak one document across train and test.
            $db->query("INSERT INTO dataset_items (dataset_id, instruction, input, output, tokens, hash, created_at, group_key)
                        SELECT ?, instruction, input, output, tokens, hash, ?, CONCAT(?, ':', COALESCE(group_key, hash))
                        FROM dataset_items
                        WHERE dataset_id = ? AND CHAR_LENGTH(output) BETWEEN 2 AND 4000 AND {$quality}
                        ORDER BY hash LIMIT {$p['want']}", [$mixId, $now, $p['id'], $p['id']]);
            CLI::write(sprintf('  #%-3d %7s rows in %.0fs', $p['id'], number_format($db->affectedRows()), microtime(true) - $t0));
        }
        $svc = new DatasetService();
        $dups = $svc->dedupe($mixId);
        $svc->recount($mixId);
        $frozen = (new EngineDatasetService())->freeze($mixId);
        CLI::write(sprintf("\nMix #%d: %s rows (%d duplicates across sources removed), frozen v%d — train %s / dev %s / test %s",
            $mixId, number_format((int) $frozen['item_count']), $dups, $frozen['version'],
            number_format($frozen['split_counts']['train']), number_format($frozen['split_counts']['dev']), number_format($frozen['split_counts']['test'])), 'green');
        return EXIT_SUCCESS;
    }

    private function categorise(string $name): string
    {
        if (preg_match(self::RULES[0][0], $name)) {
            return preg_match('/test|evaluation/i', $name) ? 'evaluation' : 'reference';
        }
        foreach (array_slice(self::RULES, 1) as [$re, $cat]) {
            if (preg_match($re, $name)) return $cat;
        }
        return 'instruction';
    }

    private function flag(array $params, string $name): bool
    {
        return CLI::getOption($name) !== null || array_key_exists($name, $params) || in_array("--{$name}", $params, true);
    }

    /** Accepts `--opt value` and `--opt=value` (spark ignores the latter on its own). */
    private function opt(array $params, string $name): ?string
    {
        $v = CLI::getOption($name);
        if (is_string($v) && $v !== '') return $v;
        foreach ($params as $p) {
            if (is_string($p) && str_starts_with($p, "--{$name}=")) return substr($p, strlen($name) + 3);
        }
        return null;
    }
}
