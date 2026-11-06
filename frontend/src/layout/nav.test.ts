import { describe, expect, it } from 'vitest';
import { perms } from '../App';
import { ALL_ITEMS } from './nav';

describe('route permissions', () => {
  it('every nav item has a route whose Guard permission matches its perm', () => {
    for (const item of ALL_ITEMS) {
      expect(perms[item.to as keyof typeof perms], item.to).toBe(item.perm);
    }
  });
  it('every guarded route is reachable from the nav', () => {
    const navPaths = new Set(ALL_ITEMS.map((i) => i.to));
    expect(Object.keys(perms).filter((p) => !navPaths.has(p))).toEqual([]);
  });
});
