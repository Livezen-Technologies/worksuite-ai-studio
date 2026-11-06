import { createHmac } from 'node:crypto';

/**
 * Thin client for the AI Studio admin API (`/api/*`). Every JSON endpoint answers
 * `{ status, message, data }`; we return `data` and throw ApiError with the server's message.
 *
 * Sign-in: email + password (+ TOTP secret when the account has two-factor on), or a refresh
 * token. Access tokens last an hour; on expiry or a 401 we refresh, then fall back to a fresh login.
 */
export class ApiError extends Error {
  constructor(message: string, public status: number, public data?: unknown) {
    super(message);
  }
}

export interface ClientConfig {
  baseUrl: string;
  email?: string;
  password?: string;
  totpSecret?: string;
  refreshToken?: string;
}

export function configFromEnv(env = process.env): ClientConfig {
  const baseUrl = (env.AISTUDIO_URL ?? '').replace(/\/+$/, '').replace(/\/api$/, '');
  if (!baseUrl) throw new Error('AISTUDIO_URL is required (e.g. https://aistudio.example.com).');
  const cfg: ClientConfig = {
    baseUrl,
    email: env.AISTUDIO_EMAIL || undefined,
    password: env.AISTUDIO_PASSWORD || undefined,
    totpSecret: env.AISTUDIO_TOTP_SECRET || undefined,
    refreshToken: env.AISTUDIO_REFRESH_TOKEN || undefined,
  };
  if (!(cfg.email && cfg.password) && !cfg.refreshToken) {
    throw new Error('Set AISTUDIO_EMAIL and AISTUDIO_PASSWORD, or AISTUDIO_REFRESH_TOKEN.');
  }
  return cfg;
}

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s), matching app/Services/TotpService.php. */
export function totp(secretBase32: string, now = Date.now()): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const clean = secretBase32.toUpperCase().replace(/[^A-Z2-7]/g, '');
  let bits = '';
  for (const c of clean) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(now / 1000 / 30)));
  const h = createHmac('sha1', Buffer.from(bytes)).update(counter).digest();
  const o = h[h.length - 1] & 0x0f;
  const code = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(code % 1_000_000).padStart(6, '0');
}

type Envelope<T> = { status?: string | number; message?: string; data?: T };

export class AiStudioClient {
  private access: string | null = null;
  private accessExp = 0;
  private refresh: string | null;
  private signingIn: Promise<void> | null = null;

  constructor(private cfg: ClientConfig) {
    this.refresh = cfg.refreshToken ?? null;
  }

  get baseUrl() {
    return this.cfg.baseUrl;
  }

  private async raw(path: string, init: RequestInit & { token?: string | null } = {}) {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
    if (init.token) headers.set('Authorization', `Bearer ${init.token}`);
    return fetch(this.cfg.baseUrl + '/api' + path, { ...init, headers });
  }

  private async parse<T>(res: Response): Promise<T> {
    const text = await res.text();
    let body: Envelope<T> | undefined;
    try {
      body = text ? (JSON.parse(text) as Envelope<T>) : undefined;
    } catch {
      if (!res.ok) throw new ApiError(`HTTP ${res.status}: ${text.slice(0, 300)}`, res.status);
      return text as unknown as T;
    }
    if (!res.ok || body?.status === 'error') {
      throw new ApiError(body?.message || `HTTP ${res.status}`, res.status, body?.data);
    }
    return (body && 'data' in body ? body.data : body) as T;
  }

  private setAccess(token: string, expiresIn?: number) {
    this.access = token;
    this.accessExp = Date.now() + Math.max(60, (expiresIn ?? 3600) - 60) * 1000;
  }

  private async login() {
    const { email, password, totpSecret } = this.cfg;
    if (!email || !password) throw new ApiError('Session expired and no AISTUDIO_EMAIL/AISTUDIO_PASSWORD to sign in again.', 401);
    let d = await this.parse<Record<string, any>>(
      await this.raw('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) }),
    );
    if (d?.mfa_setup_required) {
      throw new ApiError('This account must finish two-factor setup in the web app before the MCP server can sign in.', 401);
    }
    if (d?.mfa_required) {
      if (!totpSecret) throw new ApiError('This account has two-factor sign-in on: set AISTUDIO_TOTP_SECRET.', 401);
      d = await this.parse<Record<string, any>>(
        await this.raw('/auth/mfa', { method: 'POST', body: JSON.stringify({ mfa_token: d.mfa_token, code: totp(totpSecret) }) }),
      );
    }
    if (!d?.access_token) throw new ApiError('Sign-in did not return an access token.', 401);
    this.setAccess(d.access_token, d.expires_in);
    if (d.refresh_token) this.refresh = d.refresh_token;
  }

  private async renew() {
    if (this.refresh) {
      try {
        const d = await this.parse<{ access_token: string; expires_in?: number }>(
          await this.raw('/auth/refresh', { method: 'POST', body: JSON.stringify({ refresh_token: this.refresh }) }),
        );
        this.setAccess(d.access_token, d.expires_in);
        return;
      } catch (e) {
        if (!this.cfg.password) throw e;
      }
    }
    await this.login();
  }

  private async token(force = false) {
    if (!force && this.access && Date.now() < this.accessExp) return this.access;
    this.signingIn ??= this.renew().finally(() => (this.signingIn = null));
    await this.signingIn;
    return this.access!;
  }

  /** Call an admin endpoint. `path` is relative to /api, e.g. "/servers/3". */
  async request<T = unknown>(method: string, path: string, opts: { query?: Record<string, unknown>; body?: unknown } = {}): Promise<T> {
    const res = await this.send(method, path, opts);
    return this.parse<T>(res);
  }

  /** For NDJSON streaming endpoints (/chat, /generate): collect deltas into one reply. */
  async stream(path: string, body: unknown) {
    const res = await this.send('POST', path, { body });
    if (!res.ok) return this.parse(res);
    const text = await res.text();
    let reply = '';
    const meta: Record<string, unknown> = {};
    for (const line of text.split('\n')) {
      if (!line.trim()) continue;
      let o: Record<string, any>;
      try { o = JSON.parse(line); } catch { continue; }
      if (o.error) throw new ApiError(String(o.error), 502, o);
      if (typeof o.delta === 'string') reply += o.delta;
      else for (const [k, v] of Object.entries(o)) if (k !== 'meta' && k !== 'done') meta[k] = v;
    }
    return { reply, ...meta };
  }

  private async send(method: string, path: string, { query, body }: { query?: Record<string, unknown>; body?: unknown }) {
    let qs = '';
    if (query) {
      const p = new URLSearchParams();
      for (const [k, v] of Object.entries(query)) if (v !== undefined && v !== null && v !== '') p.set(k, String(v));
      qs = p.toString() ? `?${p}` : '';
    }
    const init = { method, body: body === undefined ? undefined : JSON.stringify(body) };
    let res = await this.raw(path + qs, { ...init, token: await this.token() });
    if (res.status === 401) res = await this.raw(path + qs, { ...init, token: await this.token(true) });
    return res;
  }
}
