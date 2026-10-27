<?php

namespace App\Services;

use ZipArchive;

/**
 * Speech datasets: your own recordings with their transcripts, for fine-tuning Whisper (recipe whisper_custom).
 *
 * A zip holds audio files (wav, mp3, m4a, ogg, flac, webm, opus) and their transcripts, either
 *   - a list: metadata.csv / .tsv / transcripts.txt with "file, transcript" per line (a header row is optional), or
 *   - a sidecar: clip001.wav + clip001.txt with the transcript.
 * Audio goes to /srv/aistudio-models/speech-data/<dataset>/; each recording becomes a dataset row with
 * instruction = "audio:custom/<dataset>/<file>" and output = the transcript (the same shape as SinhaSpeech rows).
 */
class SpeechDatasetService
{
    public const ROOT = '/srv/aistudio-models/speech-data';
    private const AUDIO = ['wav', 'mp3', 'm4a', 'ogg', 'flac', 'webm', 'opus', 'aac', 'mp4'];

    public static function audioPath(string $instruction): ?string
    {
        return preg_match('#^audio:custom/(\d+)/([^/\\\\]+)$#', $instruction, $m) ? self::ROOT . "/{$m[1]}/{$m[2]}" : null;
    }

    /** @return array{imported:int, skipped:int, issues:string[]} */
    public function importZip(int $datasetId, string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new \RuntimeException('That file is not a readable zip.');
        $audio = []; $sidecar = []; $lists = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '__MACOSX/') || str_ends_with($name, '/') || basename($name)[0] === '.') continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $stem = strtolower(pathinfo($name, PATHINFO_FILENAME));
            if (in_array($ext, self::AUDIO, true)) $audio[$stem] = $name;
            elseif (in_array($ext, ['csv', 'tsv'], true) || preg_match('/(metadata|transcripts?|labels?)\.txt$/i', $name)) $lists[] = $name;
            elseif ($ext === 'txt') $sidecar[$stem] = $name;
        }
        if (! $audio) throw new \RuntimeException('No audio files found in the zip (wav, mp3, m4a, ogg, flac, webm, opus).');

        // transcripts: from the list(s) first, then sidecars
        $text = [];
        foreach ($lists as $l) {
            foreach (preg_split('/\R/u', (string) $zip->getFromName($l)) as $n => $line) {
                if (trim($line) === '') continue;
                $sep = str_contains($line, "\t") ? "\t" : (str_contains($line, '|') ? '|' : ',');
                $cols = str_getcsv($line, $sep);
                if (count($cols) < 2) continue;
                $file = strtolower(pathinfo(trim($cols[0]), PATHINFO_FILENAME));
                $t = trim(implode($sep === ',' ? ', ' : ' ', array_slice($cols, 1)));
                if ($n === 0 && ! isset($audio[$file])) continue;          // header row
                if ($t !== '') $text[$file] = $t;
            }
        }
        foreach ($sidecar as $stem => $name) {
            if (! isset($text[$stem]) && isset($audio[$stem])) $text[$stem] = trim((string) $zip->getFromName($name));
        }

        $dir = self::ROOT . '/' . $datasetId;
        if (! is_dir($dir) && ! mkdir($dir, 0755, true)) throw new \RuntimeException("Cannot create {$dir}.");
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $imported = 0; $issues = []; $noText = 0; $tooBig = 0; $rows = [];
        foreach ($audio as $stem => $name) {
            $t = preg_replace('/\s+/u', ' ', $text[$stem] ?? '');
            if ($t === '') { $noText++; continue; }
            $stat = $zip->statName($name);
            if (($stat['size'] ?? 0) > 50 * 1024 * 1024) { $tooBig++; continue; }   // one recording, not an hour of audio
            $safe = substr(preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)), -120);
            $dest = $dir . '/' . $safe;
            if (! copy("zip://{$zipPath}#{$name}", $dest)) { $issues[] = "could not save {$name}"; continue; }
            $instr = "audio:custom/{$datasetId}/{$safe}";
            $rows[] = ['dataset_id' => $datasetId, 'instruction' => $instr, 'input' => null, 'output' => $t,
                       'tokens' => (int) ceil(strlen($t) / 3), 'hash' => sha1($instr . '||' . $t), 'created_at' => $now];
            $imported++;
            if (count($rows) >= 500) { $db->table('dataset_items')->insertBatch($rows); $rows = []; }
        }
        if ($rows) $db->table('dataset_items')->insertBatch($rows);
        $zip->close();
        if ($noText) $issues[] = "{$noText} recordings had no transcript (add metadata.csv or a .txt beside each file)";
        if ($tooBig) $issues[] = "{$tooBig} files over 50 MB were skipped (split long recordings into clips of up to 30 s)";
        (new DatasetService())->dedupe($datasetId);
        $db->table('datasets')->where('id', $datasetId)->update(['task' => 'asr', 'category' => 'speech', 'updated_at' => $now]);
        return ['imported' => $imported, 'skipped' => $noText + $tooBig, 'issues' => $issues];
    }
}
