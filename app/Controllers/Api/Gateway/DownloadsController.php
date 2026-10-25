<?php

namespace App\Controllers\Api\Gateway;

use App\Controllers\Api\BaseApiController;
use App\Services\DatasetService;
use App\Services\EnginePackageService;

/**
 * API-key downloads of model packages and datasets (`/api/v1`, ApiKeyFilter).
 *
 *   GET /api/v1/catalog                                   what this key may download (scopes download_models / download_datasets)
 *   GET /api/v1/packages/{package_id}/files?path=…        one package file; supports Range, so large files resume
 *   GET /api/v1/datasets/{id}/download?split=train,dev    a dataset as JSONL
 *
 * Packages: every state except revoked (a candidate is listed with its state, so a client can choose to take only
 * approved or active ones). Datasets: rights-approved only, with their permitted use and rights reference — the
 * licence terms travel with the data. A frozen dataset's locked test split never leaves the server (TRNPIPE 003).
 */
class DownloadsController extends BaseApiController
{
    private function key(): array { return $_SERVER['ohub_api_key'] ?? []; }

    private function has(string $scope): bool
    {
        return in_array($scope, explode(',', (string) ($this->key()['scopes'] ?? '')), true);
    }

    private function need(string $scope)
    {
        return $this->has($scope) ? null : $this->failure("This key lacks the '{$scope}' scope.", 403);
    }

    public function catalog()
    {
        if (! $this->has('download_models') && ! $this->has('download_datasets')) {
            return $this->failure("This key lacks the 'download_models' or 'download_datasets' scope.", 403);
        }
        $db   = \Config\Database::connect();
        $base = rtrim(site_url('api/v1'), '/');
        $out  = [];
        if ($this->has('download_models')) {
            $svc = new EnginePackageService();
            $out['packages'] = [];
            foreach ($db->table('engine_packages')->where('state !=', 'revoked')->orderBy('id')->get()->getResultArray() as $p) {
                $files = [];
                foreach (json_decode((string) $p['artifacts'], true) ?: [] as $a) {
                    if (! is_file($svc->artifactDir($p) . $a['path'])) continue; // only files actually stored for download
                    $files[] = ['path' => $a['path'], 'sha256' => $a['sha256'], 'size_bytes' => (int) $a['size_bytes'],
                                'url' => "{$base}/packages/{$p['package_id']}/files?path=" . rawurlencode($a['path'])];
                }
                $out['packages'][] = [
                    'package_id' => $p['package_id'], 'version' => $p['version'], 'task' => $p['task'], 'state' => $p['state'],
                    'languages' => $p['languages'], 'runtime' => $p['runtime_name'], 'runtime_range' => $p['runtime_range'],
                    'base_model' => $p['base_model'], 'quantization' => $p['quantization'], 'license' => $p['license_id'],
                    'manifest_sha256' => $p['manifest_sha256'], 'signed' => ! empty($p['signature']), 'min_ram_gib' => (float) $p['min_ram_gib'],
                    'files' => $files,
                ];
            }
        }
        if ($this->has('download_datasets')) {
            $out['datasets'] = [];
            foreach ($db->table('datasets')->where('rights_status', 'approved')->where('item_count >', 0)->orderBy('id')->get()->getResultArray() as $d) {
                $frozen = ! empty($d['manifest_hash']);
                $out['datasets'][] = [
                    'id' => (int) $d['id'], 'name' => $d['name'], 'category' => $d['category'] ?? null, 'language' => $d['language'],
                    'rows' => (int) $d['item_count'], 'tokens_estimate' => (int) $d['token_estimate'],
                    'permitted_use' => $d['permitted_use'], 'rights_ref' => $d['rights_ref'], 'source' => $d['source_ref'],
                    'frozen' => $frozen, 'manifest_hash' => $d['manifest_hash'], 'splits' => $frozen ? ['train', 'dev'] : null,
                    'url' => "{$base}/datasets/{$d['id']}/download",
                ];
            }
        }
        return $this->ok($out);
    }

    public function file($packageId = null)
    {
        if ($r = $this->need('download_models')) return $r;
        $db  = \Config\Database::connect();
        $pkg = $db->table('engine_packages')->where('package_id', (string) $packageId)->get()->getRowArray();
        if (! $pkg || $pkg['state'] === 'revoked') return $this->failure('Package not found.', 404);
        $path = (string) ($this->request->getGet('path') ?? '');
        $art  = null;
        foreach (json_decode((string) $pkg['artifacts'], true) ?: [] as $a) {
            if ($a['path'] === $path) $art = $a;
        }
        if (! $art) return $this->failure('No such file in this package.', 404);   // only declared artifacts, so no traversal
        $file = (new EnginePackageService())->artifactDir($pkg) . $art['path'];
        if (! is_file($file)) return $this->failure('This file is not stored for download on the server.', 404);

        $size  = filesize($file);
        $start = 0;
        $end   = $size - 1;
        $range = $this->request->getHeaderLine('Range');
        if ($range !== '') {
            if (! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) || ($m[1] === '' && $m[2] === '')) {
                return $this->response->setStatusCode(416)->setHeader('Content-Range', "bytes */{$size}");
            }
            if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
            else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
            if ($start > $end || $start >= $size) {
                return $this->response->setStatusCode(416)->setHeader('Content-Range', "bytes */{$size}");
            }
        }
        @set_time_limit(0);
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code($range !== '' ? 206 : 200);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($art['path']) . '"');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('X-Content-SHA256: ' . $art['sha256']);
        if ($range !== '') header("Content-Range: bytes {$start}-{$end}/{$size}");
        $fh = fopen($file, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && ! feof($fh) && ! connection_aborted()) {
            $chunk = fread($fh, (int) min(1 << 20, $left));
            echo $chunk; flush();
            $left -= strlen($chunk);
        }
        fclose($fh);
        exit;
    }

    public function dataset($id = null)
    {
        if ($r = $this->need('download_datasets')) return $r;
        $ds = \Config\Database::connect()->table('datasets')->where('id', (int) $id)->get()->getRowArray();
        if (! $ds) return $this->failure('Dataset not found.', 404);
        if ($ds['rights_status'] !== 'approved') return $this->failure('Only rights-approved datasets can be downloaded.', 403);

        $want   = array_values(array_filter(array_map('trim', explode(',', (string) ($this->request->getGet('split') ?? '')))));
        $splits = $want ?: null;
        if (! empty($ds['manifest_hash'])) {
            if (in_array('test', $want, true)) return $this->failure('The locked test split of a frozen dataset never leaves the server.', 403);
            $splits = $want ?: ['train', 'dev'];
        }
        if ($splits && array_diff($splits, ['train', 'dev', 'test'])) return $this->failure('split must be train, dev or test.', 422);

        $slug = trim(preg_replace('/[^a-z0-9_-]+/i', '-', $ds['name']), '-') ?: 'dataset';
        @set_time_limit(0);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/jsonl; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$slug}.jsonl\"");
        header('X-Dataset-Permitted-Use: ' . rawurlencode((string) $ds['permitted_use']));
        header('X-Dataset-Rights: ' . rawurlencode((string) $ds['rights_ref']));
        foreach ((new DatasetService())->exportJsonl((int) $id, $splits) as $line) {
            echo $line;
        }
        exit;
    }
}
