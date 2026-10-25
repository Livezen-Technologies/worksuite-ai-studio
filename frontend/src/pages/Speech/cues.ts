export interface Word { start: number; end: number; word: string }
export interface Segment { start: number; end: number; text: string; words?: Word[] }
export interface Cue { start: number; end: number; text: string }
export interface CueRules { maxChars: number; maxLines: number; maxSeconds: number }

export const DEFAULT_RULES: CueRules = { maxChars: 42, maxLines: 2, maxSeconds: 6 };

/** Words with times: the model's word timings when present, otherwise the segment's words spread evenly over it. */
function wordsOf(segments: Segment[]): Word[] {
  const out: Word[] = [];
  for (const s of segments) {
    if (s.words?.length) {
      out.push(...s.words.map((w) => ({ ...w, word: w.word.trim() })).filter((w) => w.word));
      continue;
    }
    const parts = s.text.split(/\s+/).filter(Boolean);
    const step = (s.end - s.start) / Math.max(1, parts.length);
    parts.forEach((p, i) => out.push({ word: p, start: s.start + i * step, end: s.start + (i + 1) * step }));
  }
  return out;
}

function wrap(words: string[], maxChars: number): string[] {
  const lines: string[] = [];
  let line = '';
  for (const w of words) {
    if (line && (line + ' ' + w).length > maxChars) { lines.push(line); line = w; } else line = line ? `${line} ${w}` : w;
  }
  if (line) lines.push(line);
  return lines;
}

/**
 * Caption cues that follow subtitle conventions: at most `maxLines` lines of `maxChars`, at most `maxSeconds` on
 * screen, a new cue after a pause of a second or at the end of a sentence.
 */
export function buildCues(segments: Segment[], rules: CueRules = DEFAULT_RULES): Cue[] {
  const words = wordsOf(segments);
  const cues: Cue[] = [];
  let cur: Word[] = [];
  const flush = () => {
    if (!cur.length) return;
    cues.push({ start: cur[0].start, end: Math.max(cur[cur.length - 1].end, cur[0].start + 0.6), text: wrap(cur.map((w) => w.word), rules.maxChars).join('\n') });
    cur = [];
  };
  for (const w of words) {
    if (cur.length) {
      const prev = cur[cur.length - 1];
      const lines = wrap([...cur, w].map((x) => x.word), rules.maxChars);
      const tooLong = lines.length > rules.maxLines || w.end - cur[0].start > rules.maxSeconds;
      const pause = w.start - prev.end > 1;
      const sentence = /[.?!।]$/.test(prev.word);
      if (tooLong || pause || (sentence && w.end - cur[0].start > 1.5)) flush();
    }
    cur.push(w);
  }
  flush();
  // never overlap the next cue
  for (let i = 0; i < cues.length - 1; i++) cues[i].end = Math.min(cues[i].end, cues[i + 1].start);
  return cues;
}

const pad = (n: number, w = 2) => String(Math.floor(n)).padStart(w, '0');
export function stamp(t: number, sep: ',' | '.'): string {
  const ms = Math.round((t % 1) * 1000);
  return `${pad(t / 3600)}:${pad((t % 3600) / 60)}:${pad(t % 60)}${sep}${pad(ms, 3)}`;
}
export const toSrt = (cues: Cue[]) => cues.map((c, i) => `${i + 1}\n${stamp(c.start, ',')} --> ${stamp(c.end, ',')}\n${c.text}\n`).join('\n');
export const toVtt = (cues: Cue[]) => 'WEBVTT\n\n' + cues.map((c, i) => `${i + 1}\n${stamp(c.start, '.')} --> ${stamp(c.end, '.')}\n${c.text}\n`).join('\n');
export const clock = (s: number) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/** "0:03.5" or "1:02:03.5" → seconds; null when unreadable. */
export function parseClock(v: string): number | null {
  const parts = v.trim().split(':').map(Number);
  if (!parts.length || parts.some((p) => Number.isNaN(p))) return null;
  return parts.reduce((acc, p) => acc * 60 + p, 0);
}
