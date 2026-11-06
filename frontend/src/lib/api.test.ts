import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, api, get, setSessionExpiredHandler, tokens, url } from './api';

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('url()', () => {
  it('prefixes /api and drops empty query values', () => {
    expect(url('/servers', { search: 'gpu', page: 2, empty: '', none: null, missing: undefined })).toBe('/api/servers?search=gpu&page=2');
    expect(url('/api/models')).toBe('/api/models');
    expect(url('apilogs', { per_page: 8 })).toBe('/api/apilogs?per_page=8');
  });
});

describe('api()', () => {
  const fetchMock = vi.fn();
  beforeEach(() => {
    localStorage.clear();
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
  });
  afterEach(() => vi.unstubAllGlobals());

  it('returns data and sends the bearer token', async () => {
    tokens.set('A1', 'R1');
    fetchMock.mockResolvedValueOnce(json(200, { status: 200, data: { ok: true } }));
    await expect(get('/servers')).resolves.toEqual({ ok: true });
    expect(new Headers(fetchMock.mock.calls[0][1].headers).get('Authorization')).toBe('Bearer A1');
  });

  it('refreshes once on 401 and retries with the new token', async () => {
    tokens.set('OLD', 'R1');
    fetchMock
      .mockResolvedValueOnce(json(401, { status: 'error', message: 'expired' }))
      .mockResolvedValueOnce(json(200, { data: { access_token: 'NEW' } }))
      .mockResolvedValueOnce(json(200, { data: [1, 2] }));
    await expect(get('/models')).resolves.toEqual([1, 2]);
    expect(fetchMock.mock.calls[1][0]).toBe('/api/auth/refresh');
    expect(new Headers(fetchMock.mock.calls[2][1].headers).get('Authorization')).toBe('Bearer NEW');
    expect(tokens.access).toBe('NEW');
  });

  it('signs out when the session was revoked (refresh refused)', async () => {
    const expired = vi.fn();
    setSessionExpiredHandler(expired);
    tokens.set('OLD', 'REVOKED');
    fetchMock
      .mockResolvedValueOnce(json(401, { status: 'error', message: 'Unauthorized: session revoked' }))
      .mockResolvedValueOnce(json(401, { status: 'error', message: 'Your session has ended.' }));
    await expect(get('/models')).rejects.toBeInstanceOf(ApiError);
    expect(expired).toHaveBeenCalledOnce();
  });

  it('surfaces the server message and field errors', async () => {
    fetchMock.mockResolvedValueOnce(json(422, { status: 'error', message: 'Please check the server details.', data: { api_url: 'Blocked' } }));
    const err = (await api('/servers', { method: 'POST', body: {} }).catch((e: unknown) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.message).toBe('Please check the server details.');
    expect(err.fields).toEqual({ api_url: 'Blocked' });
  });

  it('reports non-JSON errors (e.g. a web-server 404) with the status', async () => {
    fetchMock.mockResolvedValueOnce(new Response('<html>Not Found</html>', { status: 404 }));
    await expect(get('/apilogs')).rejects.toThrow('Request failed (404)');
  });
});
