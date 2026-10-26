<?php

namespace App\Services;

/**
 * KnowledgeService — document ingestion (chunking), best-effort embedding via a
 * node's Ollama embeddings endpoint, and retrieval (vector cosine when embeddings
 * exist, keyword fallback otherwise).
 */
class KnowledgeService
{
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Ingest text into a KB as a document + chunks, then try to embed.
     * Returns ['document_id', 'chunks', 'embedded'].
     */
    public function ingest(int $kbId, string $title, string $text, string $sourceType = 'text', array $fileMeta = []): array
    {
        $kb   = $this->db->table('knowledge_bases')->where('id', $kbId)->get()->getRowArray();
        $size = (int) ($kb['chunk_size'] ?? 800);
        $now  = date('Y-m-d H:i:s');

        $docId = (int) ($this->db->table('knowledge_documents')->insert([
            'kb_id' => $kbId, 'title' => $title, 'source_type' => $sourceType,
            'filename' => $fileMeta['filename'] ?? null, 'mime' => $fileMeta['mime'] ?? null,
            'size_bytes' => $fileMeta['size'] ?? strlen($text), 'status' => 'processing', 'created_at' => $now,
        ], true) ? $this->db->insertID() : 0);

        $chunks = $this->chunk($text, $size);
        $rows = [];
        foreach ($chunks as $i => $c) {
            $rows[] = ['kb_id' => $kbId, 'document_id' => $docId, 'chunk_index' => $i,
                'content' => $c, 'tokens' => (int) ceil(str_word_count($c) * 1.3), 'created_at' => $now];
        }
        if ($rows) {
            $this->db->table('knowledge_chunks')->insertBatch($rows);
        }

        $embedded = $this->embedDocument($kbId, $docId, $kb);
        $this->db->table('knowledge_documents')->where('id', $docId)->update([
            'status' => 'ready', 'chunk_count' => count($chunks), 'embedded' => $embedded ? 1 : 0,
        ]);

        return ['document_id' => $docId, 'chunks' => count($chunks), 'embedded' => $embedded];
    }

    /** Split text into overlapping chunks on paragraph/sentence boundaries. */
    public function chunk(string $text, int $size = 800, int $overlap = 100): array
    {
        $text = trim(preg_replace('/\s+\n/', "\n", $text));
        if ($text === '') {
            return [];
        }
        $paras = preg_split('/\n{2,}/', $text);
        $chunks = [];
        $buf = '';
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (strlen($buf) + strlen($p) + 1 <= $size) {
                $buf = $buf === '' ? $p : "$buf\n\n$p";
            } else {
                if ($buf !== '') $chunks[] = $buf;
                // long paragraph → hard-split
                if (strlen($p) > $size) {
                    foreach (str_split($p, $size) as $piece) $chunks[] = $piece;
                    $buf = '';
                } else {
                    $buf = $p;
                }
            }
        }
        if ($buf !== '') $chunks[] = $buf;

        // Add a small tail overlap between consecutive chunks for context continuity.
        if ($overlap > 0 && count($chunks) > 1) {
            for ($i = 1; $i < count($chunks); $i++) {
                $prevTail = substr($chunks[$i - 1], -$overlap);
                $chunks[$i] = trim($prevTail) . "\n" . $chunks[$i];
            }
        }
        return $chunks;
    }

    /** Embed a document's chunks via a reachable node. Returns true if embeddings stored. */
    public function embedDocument(int $kbId, int $docId, ?array $kb = null): bool
    {
        $kb     = $kb ?: $this->db->table('knowledge_bases')->where('id', $kbId)->get()->getRowArray();
        $server = $this->embedServer($kb);
        if (! $server) {
            return false;
        }
        $client = new OllamaService($server['api_url'], $server['auth_token'] ?? null);
        $chunks = $this->db->table('knowledge_chunks')->where('document_id', $docId)->get()->getResultArray();
        $any = false;
        foreach ($chunks as $c) {
            try {
                $res = $client->embeddings($kb['embed_model'], $c['content']);
                $vec = $res['embeddings'][0] ?? ($res['embedding'] ?? null);
                if (is_array($vec)) {
                    $this->db->table('knowledge_chunks')->where('id', $c['id'])->update(['embedding' => json_encode($vec)]);
                    $any = true;
                }
            } catch (\Throwable) {
                return $any; // node failed mid-way; keep what we have
            }
        }
        return $any;
    }

    /**
     * Retrieve the top-K most relevant chunks for a query. Uses cosine similarity
     * when the KB has embeddings and the query can be embedded; otherwise a
     * keyword LIKE search. Returns chunks with a 'score' and 'mode'.
     */
    public function search(int $kbId, string $query, int $k = 5): array
    {
        $kb     = $this->db->table('knowledge_bases')->where('id', $kbId)->get()->getRowArray();
        $server = $this->embedServer($kb);
        $qvec   = null;
        if ($server) {
            try {
                $res  = (new OllamaService($server['api_url'], $server['auth_token'] ?? null))->embeddings($kb['embed_model'], $query);
                $qvec = $res['embeddings'][0] ?? ($res['embedding'] ?? null);
            } catch (\Throwable) {
                $qvec = null;
            }
        }

        if (is_array($qvec)) {
            $rows = $this->db->table('knowledge_chunks')->where('kb_id', $kbId)->where('embedding IS NOT NULL')->get()->getResultArray();
            $scored = [];
            foreach ($rows as $r) {
                $vec = json_decode($r['embedding'], true);
                if (is_array($vec)) {
                    $scored[] = ['content' => $r['content'], 'document_id' => $r['document_id'], 'score' => round($this->cosine($qvec, $vec), 4)];
                }
            }
            usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
            return ['mode' => 'vector', 'results' => array_slice($scored, 0, $k)];
        }

        // Keyword fallback — tokenise the query, match chunks containing any word,
        // and rank by how many distinct query words appear.
        $words = array_values(array_unique(array_filter(
            preg_split('/\W+/', strtolower($query)),
            static fn ($w) => strlen($w) >= 3,
        )));
        if (! $words) {
            $words = [strtolower(trim($query))];
        }
        $b = $this->db->table('knowledge_chunks')->where('kb_id', $kbId)->groupStart();
        foreach ($words as $i => $w) {
            $i === 0 ? $b->like('content', $w) : $b->orLike('content', $w);
        }
        $b->groupEnd();
        $rows = $b->limit(50)->get()->getResultArray();

        $scored = [];
        foreach ($rows as $r) {
            $lc = strtolower($r['content']);
            $hits = 0;
            foreach ($words as $w) {
                if (str_contains($lc, $w)) $hits++;
            }
            $scored[] = ['content' => $r['content'], 'document_id' => $r['document_id'],
                'score' => round($hits / count($words), 2)];
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return ['mode' => 'keyword', 'results' => array_slice($scored, 0, $k)];
    }

    /** Pick the node to embed against: the KB's server if online, else any online node. */
    private function embedServer(?array $kb): ?array
    {
        $b = $this->db->table('servers')->where('deleted_at', null)->where('is_enabled', 1)->whereIn('status', ['online', 'degraded']);
        if (! empty($kb['server_id'])) {
            $s = (clone $b)->where('id', $kb['server_id'])->get()->getRowArray();
            if ($s) return $s;
        }
        return $b->orderBy('id', 'ASC')->get()->getRowArray();
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        return ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
