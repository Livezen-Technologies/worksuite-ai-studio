import { describe, expect, it, vi } from 'vitest';
import { FALLBACK_APPS, fetchLauncherApps, isStaff, parseFeed } from './launcher';

describe('launcher feed', () => {
  it('keeps valid https apps and drops the rest', () => {
    const apps = parseFeed({ apps: [
      { id: 'a', name: 'A', url: 'https://a.example/', icon: 'https://a.example/i.svg' },
      { id: 'b', name: 'B', url: 'javascript:alert(1)' },
      { id: 'c', name: 'C', url: 'https://c.example/', icon: 'http://insecure/i.svg' },
    ] });
    expect(apps).toEqual([
      { id: 'a', name: 'A', url: 'https://a.example/', icon: 'https://a.example/i.svg' },
      { id: 'c', name: 'C', url: 'https://c.example/' },
    ]);
  });
  it('falls back to the built-in list on bad data, errors and non-200', async () => {
    expect(parseFeed(null)).toBe(FALLBACK_APPS);
    expect(parseFeed({ apps: [] })).toBe(FALLBACK_APPS);
    expect(await fetchLauncherApps(vi.fn().mockRejectedValue(new Error('offline')))).toBe(FALLBACK_APPS);
    expect(await fetchLauncherApps(vi.fn().mockResolvedValue({ ok: false }) as unknown as typeof fetch)).toBe(FALLBACK_APPS);
  });
  it('built-in list includes AI Studio', () => {
    expect(FALLBACK_APPS.find((a) => a.id === 'aistudio')?.url).toBe('https://aistudio.worksuitecloud.com/');
  });
  it('is for admins only', () => {
    expect([isStaff('super_admin'), isStaff('admin'), isStaff('viewer'), isStaff(undefined)]).toEqual([true, true, false, false]);
  });
});
