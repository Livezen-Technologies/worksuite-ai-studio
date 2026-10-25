<?php

namespace App\Commands;

use App\Services\DatasetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `spark datasets:polish [ids…] [--cross] [--drop-empty] [--dry-run]` — clean and de-duplicate datasets in the database.
 *
 * Runs as set-based SQL so datasets of millions of rows finish in minutes (the Datasets page's Clean walks rows in PHP).
 * Per dataset:
 *   1. Normalise text: strip control characters and invisible characters (zero-width space / non-joiner, BOM, soft
 *      hyphen) but KEEP the zero-width joiner U+200D, which Sinhala needs (ශ්‍රී, ක්‍ය); collapse runs of spaces and
 *      tabs; at most one blank line; trim. The row hash and token estimate are recomputed exactly as an import does.
 *   2. Drop rows with an empty instruction or output, an output identical to its instruction, or an output over
 *      8,000 characters.
 *   3. Remove exact duplicates inside the dataset (keeps the earliest row).
 * With --cross, a row that also exists in another dataset is kept only once: in an approved dataset rather than a
 * quarantined one, then in the larger one. --drop-empty deletes datasets left with no rows.
 *
 * Frozen datasets (with a manifest hash) are never changed — a frozen version is immutable (TRNPIPE 003) — and are not
 * used as the "keeper" for cross-dataset duplicates either.
 */
class DatasetsPolish extends BaseCommand
{
    protected $group       = 'OllamaHub';
    protected $name        = 'datasets:polish';
    protected $description = 'Normalise text, drop junk rows and remove duplicates (inside and across datasets) in SQL.';
    protected $usage       = 'datasets:polish [id …] [--cross] [--drop-empty] [--dry-run]';

    private const HASH   = "SHA1(CONCAT(instruction, '|', COALESCE(input, ''), '|', output))";
    private const TOKENS = "CEIL(LENGTH(CONCAT(instruction, COALESCE(input, ''), output)) / 3)";

    public function run(array $params)
    {
        $db  = \Config\Database::connect();
        $dry = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);
        $ids = array_values(array_filter(array_map('intval', array_filter($params, 'is_int', ARRAY_FILTER_USE_KEY))));
        $q   = $db->table('datasets')->select('id, name, item_count, rights_status, manifest_hash');
        if ($ids) $q->whereIn('id', $ids);
        $all = $q->orderBy('id')->get()->getResultArray();

        $svc = new DatasetService();
        foreach ($all as $d) {
            if (! empty($d['manifest_hash'])) {
                CLI::write("#{$d['id']} {$d['name']}: frozen — left as is.", 'yellow');
                continue;
            }
            $id = (int) $d['id'];
            $t0 = microtime(true);
            $n0 = (int) $db->query('SELECT COUNT(*) n FROM dataset_items WHERE dataset_id = ?', [$id])->getRow()->n;
            $clean = static fn (string $col, bool $nullable = false) => "TRIM(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE("
                . ($nullable ? "COALESCE({$col}, '')" : $col)
                . ", '[\\\\x{0000}-\\\\x{0008}\\\\x{000B}\\\\x{000C}\\\\x{000E}-\\\\x{001F}\\\\x{007F}\\\\x{200B}\\\\x{200C}\\\\x{FEFF}\\\\x{00AD}]', ''),"
                . " '[ \\t]+', ' '), '\\n( ?\\n)+', '\\n\\n'))";
            if ($dry) {
                $changed = (int) $db->query("SELECT COUNT(*) n FROM dataset_items WHERE dataset_id = ? AND (instruction <> {$clean('instruction')} OR output <> {$clean('output')} OR COALESCE(input,'') <> {$clean('input', true)})", [$id])->getRow()->n;
                CLI::write("#{$id} {$d['name']}: {$n0} rows, {$changed} would be normalised (dry run).");
                continue;
            }
            // 1. normalise (only rows that change), then refresh hash + tokens for those rows
            $db->query("UPDATE dataset_items SET instruction = {$clean('instruction')}, input = NULLIF({$clean('input', true)}, ''), output = {$clean('output')}
                        WHERE dataset_id = ? AND (instruction <> {$clean('instruction')} OR output <> {$clean('output')} OR COALESCE(input,'') <> {$clean('input', true)})", [$id]);
            $norm = $db->affectedRows();
            $db->query('UPDATE dataset_items SET hash = ' . self::HASH . ', tokens = ' . self::TOKENS . ' WHERE dataset_id = ? AND hash <> ' . self::HASH, [$id]);
            // 2. junk rows
            $db->query("DELETE FROM dataset_items WHERE dataset_id = ? AND (instruction = '' OR output = '' OR output = instruction OR CHAR_LENGTH(output) > 8000)", [$id]);
            $junk = $db->affectedRows();
            // 3. duplicates inside the dataset
            $dups = $svc->dedupe($id);
            $svc->recount($id);
            $n1 = (int) $db->query('SELECT COUNT(*) n FROM dataset_items WHERE dataset_id = ?', [$id])->getRow()->n;
            CLI::write(sprintf('#%d %s: %d → %d rows (normalised %d, junk %d, duplicates %d) in %.0fs', $id, $d['name'], $n0, $n1, $norm, $junk, $dups, microtime(true) - $t0), 'green');
        }

        if (! $dry && (CLI::getOption('cross') !== null || array_key_exists('cross', $params))) {
            $this->crossDedupe($db, $svc, $ids);
        }
        if (! $dry && (CLI::getOption('drop-empty') !== null || array_key_exists('drop-empty', $params))) {
            foreach ($db->query("SELECT d.id, d.name FROM datasets d WHERE d.manifest_hash IS NULL
                                 AND NOT EXISTS (SELECT 1 FROM dataset_items i WHERE i.dataset_id = d.id)
                                 AND NOT EXISTS (SELECT 1 FROM training_jobs j WHERE j.dataset_id = d.id)")->getResultArray() as $e) {
                $db->table('datasets')->where('id', $e['id'])->delete();
                CLI::write("Deleted empty dataset #{$e['id']} {$e['name']} (its rows live in a larger dataset).", 'yellow');
            }
        }
        return EXIT_SUCCESS;
    }

    /** Keep each row once across datasets: approved before quarantined, then the larger dataset. Frozen sets untouched. */
    private function crossDedupe($db, DatasetService $svc, array $ids): void
    {
        $rank = $db->query("SELECT id, name, item_count, rights_status FROM datasets WHERE manifest_hash IS NULL
                            ORDER BY (rights_status = 'approved') DESC, item_count DESC, id ASC")->getResultArray();
        foreach ($rank as $i => $loser) {
            if ($ids && ! in_array((int) $loser['id'], $ids, true)) continue;
            $keepers = array_map(static fn ($r) => (int) $r['id'], array_slice($rank, 0, $i));
            if (! $keepers) continue;
            $db->query('DELETE l FROM dataset_items l
                        JOIN dataset_items k ON k.hash = l.hash AND k.dataset_id IN (' . implode(',', $keepers) . ')
                        WHERE l.dataset_id = ?', [(int) $loser['id']]);
            $gone = $db->affectedRows();
            if ($gone) {
                $svc->recount((int) $loser['id']);
                CLI::write("#{$loser['id']} {$loser['name']}: removed {$gone} rows that already exist in a preferred dataset.", 'green');
            }
        }
    }
}
