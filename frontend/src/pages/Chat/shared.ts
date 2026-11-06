/** Types, constants and helpers shared by the Chat page and its parts. */
export interface Session { id: number; title: string; model: string | null; server_id: number | null; system_prompt: string | null; updated_at: string; message_count: number | string }
export interface Msg {
  id?: number; role: 'user' | 'assistant' | 'system'; content: string; images?: string[]; pending?: boolean; error?: boolean;
  prompt_tokens?: number | null; completion_tokens?: number | null; total_ms?: number | null;
}
export interface ModelRow { id: number; name: string; server_id: number; server_name: string; server_status: string; is_default?: number | string | boolean; family?: string | null }
/** Embedding models can't hold a conversation; keep them out of the picker. */
export const isChatModel = (m: ModelRow) => !/(embed|bge-|minilm|e5-)/i.test(`${m.name} ${m.family ?? ''}`);
export interface Agent { id: number; name: string; avatar: string | null; model: string | null; server_id: number | null; system_prompt: string | null; status: string }
export interface Prompt { id: number; name: string; category: string; content: string; variables: string[] }

export const VISION = /llava|llama3\.2-vision|llama3\.1-vision|moondream|minicpm-v|bakllava|qwen2(\.5)?-?vl|gemma3|cogvlm|llava-phi|gpt-4o|gpt-4\.1|gpt-4-turbo|chatgpt-4o|^o[134]|claude|gemini/i;
export const keyOf = (server_id: number | null, model: string | null | undefined) => `${server_id}::${model ?? ''}`;
export const split = (k: string) => { const [s, ...r] = k.split('::'); return { server_id: Number(s), model: r.join('::') }; };
export interface ModelOption { value: string; label: string }
