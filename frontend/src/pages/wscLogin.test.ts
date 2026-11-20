import { describe, expect, it } from 'vitest';
import { wscStartUrl } from './Login';

describe('WorkSuite Cloud sign-in start URL', () => {
  it('carries the return path and the silent flag', () => {
    expect(wscStartUrl('/')).toBe('/api/auth/wsc/start');
    expect(wscStartUrl('/models?x=1')).toBe('/api/auth/wsc/start?from=%2Fmodels%3Fx%3D1');
    expect(wscStartUrl('/', true)).toBe('/api/auth/wsc/start?silent=1');
  });
});
