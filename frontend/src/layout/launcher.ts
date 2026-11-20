/**
 * The WorkSuite Cloud 9-dot launcher's app list (staff SSO contract, part 2/3).
 * The feed is editable data; this built-in copy is used whenever it cannot be reached.
 */
export interface LauncherApp { id: string; name: string; url: string; icon?: string }

export const LAUNCHER_FEED = 'https://account.worksuitecloud.com/launcher/v1/apps';
export const CURRENT_APP = 'aistudio';

export const FALLBACK_APPS: LauncherApp[] = [
  { id: 'admin', name: 'Cloud Admin', url: 'https://account.worksuitecloud.com/admin/' },
  { id: 'panel', name: 'CloudPanel', url: 'https://panel.livezentech.com/admin' },
  { id: 'iot', name: 'IoT', url: 'https://iot.worksuitecloud.com/' },
  { id: 'desk', name: 'Desk', url: 'https://desk.worksuitecloud.com/' },
  { id: 'aistudio', name: 'AI Studio', url: 'https://aistudio.worksuitecloud.com/' },
  { id: 'updates', name: 'Update Center', url: 'https://download.worksuitecloud.com/admin/' },
];

const https = (u: unknown): u is string => typeof u === 'string' && /^https:\/\/[^\s"'<>]+$/i.test(u);

/** Keep only well-formed https entries; anything unusable falls back to the built-in list. */
export function parseFeed(data: unknown): LauncherApp[] {
  const rows = (data as { apps?: unknown })?.apps;
  if (!Array.isArray(rows)) return FALLBACK_APPS;
  const apps = rows.flatMap((r): LauncherApp[] => {
    const a = r as Record<string, unknown>;
    if (typeof a?.id !== 'string' || typeof a.name !== 'string' || !https(a.url)) return [];
    return [{ id: a.id, name: a.name, url: a.url, ...(https(a.icon) ? { icon: a.icon } : {}) }];
  });
  return apps.length ? apps : FALLBACK_APPS;
}

export async function fetchLauncherApps(fetcher: typeof fetch = fetch): Promise<LauncherApp[]> {
  try {
    const r = await fetcher(LAUNCHER_FEED, { credentials: 'omit', headers: { Accept: 'application/json' } });
    return r.ok ? parseFeed(await r.json()) : FALLBACK_APPS;
  } catch {
    return FALLBACK_APPS;
  }
}

/** Staff = AI Studio admins. */
export const isStaff = (role?: string) => role === 'super_admin' || role === 'admin';
