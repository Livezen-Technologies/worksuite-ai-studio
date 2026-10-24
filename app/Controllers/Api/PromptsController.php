<?php

namespace App\Controllers\Api;

/**
 * Prompt Library.  GET/POST /api/prompts, PUT/DELETE /api/prompts/{id}
 * Reusable prompt templates with {{variables}} and categories.
 */
class PromptsController extends BaseApiController
{
    private function table() { return \Config\Database::connect()->table('prompt_templates'); }

    public function index()
    {
        if ($r = $this->requirePermission('prompts.view')) return $r;
        $uid = $this->userId();
        $rows = $this->table()
            ->groupStart()->where('is_public', 1)->orWhere('user_id', $uid)->groupEnd()
            ->orderBy('category', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['variables'] = $r['variables'] ? json_decode($r['variables'], true) : [];
        }
        return $this->ok($rows);
    }

    public function create()
    {
        if ($r = $this->requirePermission('prompts.manage')) return $r;
        $in = $this->input();
        if ($errors = $this->validateFields($in, [
            'name'    => 'required|max_length[150]',
            'content' => 'required',
        ])) {
            return $this->failure('Name and content are required.', 422, $errors);
        }
        $id = $this->table()->insert($this->fields($in, ['user_id' => $this->userId()]), true);
        return $this->ok($this->one((int) $id), 'Prompt saved.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('prompts.manage')) return $r;
        if (! $this->one((int) $id)) return $this->failure('Prompt not found.', 404);
        $this->table()->where('id', (int) $id)->update($this->fields($this->input()));
        return $this->ok($this->one((int) $id), 'Prompt updated.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('prompts.manage')) return $r;
        if (! $this->one((int) $id)) return $this->failure('Prompt not found.', 404);
        $this->table()->where('id', (int) $id)->delete();
        return $this->ok(null, 'Prompt deleted.');
    }

    private function fields(array $in, array $base = []): array
    {
        $out = $base;
        foreach (['name', 'category', 'description', 'content', 'is_public'] as $f) {
            if (array_key_exists($f, $in)) $out[$f] = $in[$f];
        }
        // Auto-extract {{variables}} from the content.
        if (isset($out['content'])) {
            preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $out['content'], $m);
            $out['variables'] = json_encode(array_values(array_unique($m[1] ?? [])));
        }
        $out['updated_at'] = date('Y-m-d H:i:s');
        if (! isset($out['created_at']) && empty($in['id'])) $out['created_at'] = $out['updated_at'];
        return $out;
    }

    private function one(int $id): ?array
    {
        $r = $this->table()->where('id', $id)->get()->getRowArray();
        if ($r) $r['variables'] = $r['variables'] ? json_decode($r['variables'], true) : [];
        return $r;
    }
}
