<?php

namespace App\Controllers\Api;

/**
 * AI Agents — named assistants with a system prompt, default model, temperature,
 * optional knowledge base, tools, and gateway access.
 *
 *   GET/POST /api/agents, PUT/DELETE /api/agents/{id}
 */
class AgentsController extends BaseApiController
{
    private function table() { return \Config\Database::connect()->table('agents'); }

    public function index()
    {
        if ($r = $this->requirePermission('agents.view')) return $r;
        $db = \Config\Database::connect();
        $rows = $db->table('agents a')
            ->select('a.*, kb.name AS kb_name, s.name AS server_name')
            ->join('knowledge_bases kb', 'kb.id = a.kb_id', 'left')
            ->join('servers s', 's.id = a.server_id', 'left')
            ->orderBy('a.id', 'DESC')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['tools'] = $r['tools'] ? json_decode($r['tools'], true) : [];
        }
        return $this->ok($rows);
    }

    public function create()
    {
        if ($r = $this->requirePermission('agents.manage')) return $r;
        $in = $this->input();
        if ($errors = $this->validateFields($in, ['name' => 'required|max_length[150]'])) {
            return $this->failure('A name is required.', 422, $errors);
        }
        $id = (int) ($this->table()->insert($this->fields($in, ['user_id' => $this->userId()]), true)
            ? \Config\Database::connect()->insertID() : 0);
        return $this->ok($this->one($id), 'Agent created.', 201);
    }

    public function update($id = null)
    {
        if ($r = $this->requirePermission('agents.manage')) return $r;
        if (! $this->one((int) $id)) return $this->failure('Agent not found.', 404);
        $this->table()->where('id', (int) $id)->update($this->fields($this->input()));
        return $this->ok($this->one((int) $id), 'Agent updated.');
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('agents.manage')) return $r;
        if (! $this->one((int) $id)) return $this->failure('Agent not found.', 404);
        $this->table()->where('id', (int) $id)->delete();
        return $this->ok(null, 'Agent deleted.');
    }

    private function fields(array $in, array $base = []): array
    {
        $out = $base;
        foreach (['name', 'description', 'avatar', 'system_prompt', 'model', 'server_id', 'temperature', 'kb_id', 'api_access', 'status'] as $f) {
            if (array_key_exists($f, $in)) $out[$f] = $in[$f] === '' ? null : $in[$f];
        }
        if (array_key_exists('tools', $in)) {
            $out['tools'] = is_array($in['tools']) ? json_encode(array_values($in['tools'])) : null;
        }
        $out['updated_at'] = date('Y-m-d H:i:s');
        if (empty($in['id']) && ! isset($base['created_at'])) $out['created_at'] = $out['updated_at'];
        return $out;
    }

    private function one(int $id): ?array
    {
        $r = $this->table()->where('id', $id)->get()->getRowArray();
        if ($r) $r['tools'] = $r['tools'] ? json_decode($r['tools'], true) : [];
        return $r;
    }
}
