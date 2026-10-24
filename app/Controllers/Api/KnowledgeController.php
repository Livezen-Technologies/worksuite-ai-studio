<?php

namespace App\Controllers\Api;

use App\Services\KnowledgeService;

/**
 * Knowledge Base — collections, documents (text/file ingest → chunk → embed),
 * retrieval, and re-embedding.
 *
 *   GET/POST    /api/knowledge                bases (list/create)
 *   GET/DELETE  /api/knowledge/{id}           base detail (+docs) / delete
 *   POST        /api/knowledge/{id}/documents add a document (text or file)
 *   DELETE      /api/knowledge/documents/{id} delete a document
 *   POST        /api/knowledge/{id}/search    retrieve relevant chunks
 *   POST        /api/knowledge/{id}/reindex   (re)embed all documents
 */
class KnowledgeController extends BaseApiController
{
    private function bases() { return \Config\Database::connect()->table('knowledge_bases'); }
    private function docs()  { return \Config\Database::connect()->table('knowledge_documents'); }

    public function index()
    {
        if ($r = $this->requirePermission('knowledge.view')) return $r;
        $db = \Config\Database::connect();
        $rows = $db->table('knowledge_bases kb')
            ->select('kb.*, COUNT(DISTINCT d.id) AS document_count, COALESCE(SUM(d.chunk_count),0) AS chunk_count')
            ->join('knowledge_documents d', 'd.kb_id = kb.id', 'left')
            ->groupBy('kb.id')->orderBy('kb.id', 'DESC')->get()->getResultArray();
        return $this->ok($rows);
    }

    public function create()
    {
        if ($r = $this->requirePermission('knowledge.manage')) return $r;
        $in = $this->input();
        if ($errors = $this->validateFields($in, ['name' => 'required|max_length[150]'])) {
            return $this->failure('A name is required.', 422, $errors);
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) ($this->bases()->insert([
            'user_id' => $this->userId(), 'name' => $in['name'], 'description' => $in['description'] ?? null,
            'embed_model' => $in['embed_model'] ?? 'nomic-embed-text', 'server_id' => $in['server_id'] ?? null,
            'chunk_size' => (int) ($in['chunk_size'] ?? 800), 'created_at' => $now, 'updated_at' => $now,
        ], true) ? \Config\Database::connect()->insertID() : 0);
        return $this->ok($this->bases()->where('id', $id)->get()->getRowArray(), 'Knowledge base created.', 201);
    }

    public function show($id = null)
    {
        if ($r = $this->requirePermission('knowledge.view')) return $r;
        $kb = $this->bases()->where('id', (int) $id)->get()->getRowArray();
        if (! $kb) return $this->failure('Knowledge base not found.', 404);
        $kb['documents'] = $this->docs()->where('kb_id', (int) $id)->orderBy('id', 'DESC')->get()->getResultArray();
        return $this->ok($kb);
    }

    public function delete($id = null)
    {
        if ($r = $this->requirePermission('knowledge.manage')) return $r;
        if (! $this->bases()->where('id', (int) $id)->get()->getRowArray()) return $this->failure('Not found.', 404);
        $this->bases()->where('id', (int) $id)->delete();
        return $this->ok(null, 'Knowledge base deleted.');
    }

    public function addDocument($id = null)
    {
        if ($r = $this->requirePermission('knowledge.manage')) return $r;
        $kb = $this->bases()->where('id', (int) $id)->get()->getRowArray();
        if (! $kb) return $this->failure('Knowledge base not found.', 404);

        // File upload or pasted text.
        $file = $this->request->getFile('file');
        if ($file && $file->isValid()) {
            $text = $this->extractText($file);
            if ($text === null) {
                return $this->failure('Unsupported file type. Use txt, md, csv, html, json (or paste text).', 422);
            }
            $meta = ['filename' => $file->getClientName(), 'mime' => $file->getClientMimeType(), 'size' => $file->getSize()];
            $title = $this->input()['title'] ?? pathinfo($file->getClientName(), PATHINFO_FILENAME);
            $res = (new KnowledgeService())->ingest((int) $id, $title, $text, 'file', $meta);
        } else {
            $in = $this->input();
            $text = (string) ($in['content'] ?? '');
            if (trim($text) === '') return $this->failure('Provide file or text content.', 422);
            $res = (new KnowledgeService())->ingest((int) $id, $in['title'] ?? 'Untitled', $text, 'text');
        }
        return $this->ok($res, $res['embedded'] ? 'Document added and embedded.' : 'Document added (embeddings pending — no online node).', 201);
    }

    public function deleteDocument($docId = null)
    {
        if ($r = $this->requirePermission('knowledge.manage')) return $r;
        if (! $this->docs()->where('id', (int) $docId)->get()->getRowArray()) return $this->failure('Document not found.', 404);
        $this->docs()->where('id', (int) $docId)->delete();
        return $this->ok(null, 'Document deleted.');
    }

    public function search($id = null)
    {
        if ($r = $this->requirePermission('knowledge.view')) return $r;
        $q = trim((string) ($this->input()['query'] ?? ''));
        if ($q === '') return $this->failure('A query is required.', 422);
        return $this->ok((new KnowledgeService())->search((int) $id, $q, (int) ($this->input()['k'] ?? 5)));
    }

    public function reindex($id = null)
    {
        if ($r = $this->requirePermission('knowledge.manage')) return $r;
        $svc = new KnowledgeService();
        $docs = $this->docs()->where('kb_id', (int) $id)->get()->getResultArray();
        $embedded = 0;
        foreach ($docs as $d) {
            if ($svc->embedDocument((int) $id, (int) $d['id'])) {
                $this->docs()->where('id', $d['id'])->update(['embedded' => 1]);
                $embedded++;
            }
        }
        return $this->ok(['documents' => count($docs), 'embedded' => $embedded],
            $embedded ? 'Re-embedded.' : 'No online node available to embed.');
    }

    /** Extract plain text from supported upload types (text-based only). */
    private function extractText($file): ?string
    {
        $ext = strtolower($file->getClientExtension() ?: pathinfo($file->getClientName(), PATHINFO_EXTENSION));
        $textExts = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'log', 'html', 'htm', 'xml', 'yml', 'yaml'];
        if (! in_array($ext, $textExts, true)) {
            return null; // PDF/DOCX need a converter (future)
        }
        $raw = file_get_contents($file->getTempName());
        if ($raw === false) return null;
        if (in_array($ext, ['html', 'htm', 'xml'], true)) {
            $raw = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
        }
        return $raw;
    }
}
