/**
 * The OllamaHub API client. Every JSON endpoint answers `{ status, message, data }`
 * (BaseApiController); `api()` returns `data` and throws ApiError with the server's message.
 * A 401 refreshes the access token once (POST /api/auth/refresh) and retries.
 */
export class ApiError extends Error {
  constructor(message: string, public status: number, public data?: unknown) {
    super(message);
  }
  /** Field errors from a 422, as `{ field: message }`. */
  get fields(): Record<string, string> {
    return this.data && typeof this.data === 'object' ? (this.data as Record<string, string>) : {};
  }
}

const ACCESS = 'aistudio.access';
const REFRESH = 'aistudio.refresh';

export const tokens = {
  get access() { return read(ACCESS); },
  get refresh() { return read(REFRESH); },
  set(access: string | null, refresh?: string | null) {
    write(ACCESS, access);
    if (refresh !== undefined) write(REFRESH, refresh);
  },
  clear() { write(ACCESS, null); write(REFRESH, null); },
};

function read(k: string): string | null {
  try { return localStorage.getItem(k); } catch { return null; }
}
function write(k: string, v: string | null) {
  try { if (v) localStorage.setItem(k, v); else localStorage.removeItem(k); } catch { /* private mode */ }
}

/** Called when the session cannot be refreshed; AuthProvider points it at sign-out. */
export let onSessionExpired: () => void = () => {};
export function setSessionExpiredHandler(fn: () => void) { onSessionExpired = fn; }

let refreshing: Promise<boolean> | null = null;

async function refreshAccess(): Promise<boolean> {
  const refresh = tokens.refresh;
  if (!refresh) return false;
  refreshing ??= fetch('/api/auth/refresh', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ refresh_token: refresh }),
  })
    .then(async (r) => {
      if (!r.ok) return false;
      const body = await r.json();
      tokens.set(body?.data?.access_token ?? null);
      return Boolean(body?.data?.access_token);
    })
    .catch(() => false)
    .finally(() => { refreshing = null; });
  return refreshing;
}

export type Query = Record<string, string | number | boolean | null | undefined>;

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: Query;
  signal?: AbortSignal;
}

export function url(path: string, query?: Query): string {
  const p = path.startsWith('/api') ? path : `/api${path.startsWith('/') ? '' : '/'}${path}`;
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(query ?? {})) if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
  const s = qs.toString();
  return s ? `${p}?${s}` : p;
}

/** Raw fetch with auth and one refresh-and-retry; used directly for streams and downloads. */
export async function authFetch(path: string, init: RequestInit & { query?: Query } = {}): Promise<Response> {
  const go = () => {
    const headers = new Headers(init.headers);
    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
    if (tokens.access) headers.set('Authorization', `Bearer ${tokens.access}`);
    return fetch(url(path, init.query), { ...init, headers });
  };
  let res = await go();
  if (res.status === 401 && tokens.refresh && !path.startsWith('/auth/')) {
    if (await refreshAccess()) res = await go();
    else onSessionExpired();
  }
  return res;
}

export async function api<T = unknown>(path: string, opts: RequestOptions = {}): Promise<T> {
  const isForm = typeof FormData !== 'undefined' && opts.body instanceof FormData;
  const res = await authFetch(path, {
    method: opts.method ?? (opts.body === undefined ? 'GET' : 'POST'),
    query: opts.query,
    signal: opts.signal,
    headers: opts.body === undefined || isForm ? undefined : { 'Content-Type': 'application/json' },
    body: opts.body === undefined ? undefined : isForm ? (opts.body as FormData) : JSON.stringify(opts.body),
  });
  let body: { status?: string; message?: string; data?: unknown } | null = null;
  try { body = await res.json(); } catch { /* empty or non-JSON */ }
  if (!res.ok || body?.status === 'error') {
    throw new ApiError(body?.message || `Request failed (${res.status})`, res.status, body?.data);
  }
  return body?.data as T;
}

export const get = <T>(path: string, query?: Query) => api<T>(path, { query });
export const post = <T>(path: string, body: unknown = {}) => api<T>(path, { method: 'POST', body });
export const put = <T>(path: string, body: unknown = {}) => api<T>(path, { method: 'PUT', body });
export const del = <T>(path: string) => api<T>(path, { method: 'DELETE' });

/**
 * Read an NDJSON stream (chat, generate, model create/pull). Calls `onLine` for each parsed line.
 */
export async function streamNdjson(path: string, body: unknown, onLine: (line: Record<string, unknown>) => void, signal?: AbortSignal) {
  const res = await authFetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/x-ndjson' },
    body: JSON.stringify(body),
    signal,
  });
  if (!res.ok || !res.body) {
    let msg = `Request failed (${res.status})`;
    try { msg = (await res.json())?.message ?? msg; } catch { /* stream */ }
    throw new ApiError(msg, res.status);
  }
  const reader = res.body.getReader();
  const dec = new TextDecoder();
  let buf = '';
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    buf += dec.decode(value, { stream: true });
    let i;
    while ((i = buf.indexOf('\n')) >= 0) {
      const line = buf.slice(0, i).trim();
      buf = buf.slice(i + 1);
      if (line) try { onLine(JSON.parse(line)); } catch { /* partial or non-JSON */ }
    }
  }
  if (buf.trim()) try { onLine(JSON.parse(buf)); } catch { /* ignore */ }
}

/** Download an authenticated file (dataset export, training kit). */
export async function download(path: string, fallbackName: string) {
  const res = await authFetch(path, { headers: { Accept: '*/*' } });
  if (!res.ok) throw new ApiError(`Download failed (${res.status})`, res.status);
  const cd = res.headers.get('Content-Disposition') ?? '';
  const name = /filename\*?=(?:UTF-8'')?"?([^";]+)/i.exec(cd)?.[1] ?? fallbackName;
  const href = URL.createObjectURL(await res.blob());
  const a = Object.assign(document.createElement('a'), { href, download: decodeURIComponent(name) });
  a.click();
  setTimeout(() => URL.revokeObjectURL(href), 1000);
}

export interface Paged<T> { rows: T[]; total: number; page: number; per_page: number }
