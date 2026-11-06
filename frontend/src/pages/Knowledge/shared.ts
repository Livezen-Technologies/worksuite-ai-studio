/** Types and constants shared by the Knowledge page and its detail modal. */
export interface Base {
  id: number; name: string; description: string | null; embed_model: string; server_id: number | null; chunk_size: number | string;
  document_count?: number | string; chunk_count?: number | string; created_at: string | null;
}
export interface Doc {
  id: number; title: string; source_type: string; filename: string | null; mime: string | null; size_bytes: number | null;
  status: string; chunk_count: number | string; embedded: number | string; created_at: string | null;
}
export interface SearchResult { mode: string; results: { content: string; document_id: number; score?: number | null }[] }
export interface Server { id: number; name: string; status?: string; is_enabled?: boolean }


export const ACCEPT = '.txt,.md,.markdown,.csv,.tsv,.json,.log,.html,.htm,.xml,.yml,.yaml';
