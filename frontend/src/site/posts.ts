/**
 * Blog posts = AI Studio's own release notes. CHANGELOG.md is read at build time ("## [1.19.0] — 2026-10-27 — Title"
 * followed by bullets), so every release is a post with no CMS and nothing to keep in sync.
 */
import changelog from '../../../CHANGELOG.md?raw';

export interface Post { slug: string; version: string; date: string; title: string; body: string; summary: string; category: string }

function slugify(s: string) {
  return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80);
}

/** A rough category from the title, for the blog filter. */
function categorise(t: string): string {
  const s = t.toLowerCase();
  if (/speech|whisper|transcri|voice|caption/.test(s)) return 'Speech';
  if (/train|pipeline|llm|fine-tune|agent|gpu/.test(s)) return 'Training';
  if (/dataset|data/.test(s)) return 'Data';
  if (/node|server|tunnel|enrol/.test(s)) return 'Nodes';
  if (/engine|package|gate|evaluation/.test(s)) return 'Engine';
  return 'Product';
}

let cache: Post[] | null = null;

export function posts(): Post[] {
  if (cache) return cache;
  const out: Post[] = [];
  const re = /^## \[([^\]]+)\]\s*—\s*(\d{4}-\d{2}-\d{2})\s*—\s*(.+)$/gm;
  const heads = [...changelog.matchAll(re)];
  heads.forEach((m, i) => {
    const start = (m.index ?? 0) + m[0].length;
    const end = i + 1 < heads.length ? heads[i + 1].index ?? changelog.length : changelog.length;
    const body = changelog.slice(start, end).trim();
    const first = body.split('\n').find((l) => l.startsWith('- ')) ?? '';
    const summary = first.replace(/^- /, '').replace(/\*\*|`/g, '').replace(/\[([^\]]+)\]\([^)]+\)/g, '$1');
    const [, version, date, title] = m;
    out.push({ slug: `${slugify(version)}-${slugify(title)}`, version, date, title: title.trim(), body, summary: summary.length > 220 ? summary.slice(0, 217) + '…' : summary, category: categorise(title) });
  });
  cache = out;
  return out;
}

export const latestPost = (): Post | undefined => posts()[0];
export const findPost = (slug: string): Post | undefined => posts().find((p) => p.slug === slug);

export function formatDate(d: string) {
  return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
}
