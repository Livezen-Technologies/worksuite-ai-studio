const nf = new Intl.NumberFormat();
export const num = (n: unknown) => (n === null || n === undefined || n === '' ? '—' : nf.format(Number(n)));
export const compact = (n: unknown) =>
  n === null || n === undefined ? '—' : new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(Number(n));
export function bytes(n: unknown): string {
  let v = Number(n);
  if (!Number.isFinite(v)) return '—';
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
  return `${v.toFixed(v >= 10 || i === 0 ? 0 : 1)} ${u[i]}`;
}
/** The API stores `Y-m-d H:i:s` in server time (UTC on the box); show it in the viewer's zone. */
export function parseTs(s: unknown): Date | null {
  if (!s) return null;
  const str = String(s);
  const d = new Date(/[zZ]|[+-]\d\d:?\d\d$/.test(str) ? str : str.replace(' ', 'T') + 'Z');
  return Number.isNaN(d.getTime()) ? null : d;
}
export function dateTime(s: unknown): string {
  const d = parseTs(s);
  return d ? d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}
export function ago(s: unknown): string {
  const d = parseTs(s);
  if (!d) return '—';
  const sec = Math.round((Date.now() - d.getTime()) / 1000);
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
  if (Math.abs(sec) < 60) return rtf.format(-sec, 'second');
  if (Math.abs(sec) < 3600) return rtf.format(-Math.round(sec / 60), 'minute');
  if (Math.abs(sec) < 86400) return rtf.format(-Math.round(sec / 3600), 'hour');
  return rtf.format(-Math.round(sec / 86400), 'day');
}
export const ms = (n: unknown) => (n === null || n === undefined ? '—' : Number(n) >= 1000 ? `${(Number(n) / 1000).toFixed(1)} s` : `${Math.round(Number(n))} ms`);
