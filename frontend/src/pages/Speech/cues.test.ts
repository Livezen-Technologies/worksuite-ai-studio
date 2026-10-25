import { describe, expect, it } from 'vitest';
import { buildCues, parseClock, stamp, toSrt, toVtt, type Segment } from './cues';

const w = (word: string, start: number, end: number) => ({ word: ` ${word}`, start, end });

describe('caption cues', () => {
  it('splits at a pause and keeps word timing', () => {
    const segs: Segment[] = [{ start: 0, end: 6, text: 'අපට කිසිසේත් ම මෙවැනි දේ ගැන', words: [w('අපට', 0, 0.5), w('කිසිසේත්', 0.5, 1), w('ම', 1, 1.1), w('මෙවැනි', 3, 3.5), w('දේ', 3.5, 3.8), w('ගැන', 3.8, 4.2)] }];
    const cues = buildCues(segs);
    expect(cues).toHaveLength(2);
    expect(cues[0]).toMatchObject({ start: 0, text: 'අපට කිසිසේත් ම' });
    expect(cues[1]).toMatchObject({ start: 3, end: 4.2, text: 'මෙවැනි දේ ගැන' });
  });

  it('respects characters per line, lines per caption and time on screen', () => {
    const words = Array.from({ length: 40 }, (_, i) => w(`word${i}`, i * 0.3, i * 0.3 + 0.25));
    const cues = buildCues([{ start: 0, end: 12, text: '', words }], { maxChars: 20, maxLines: 2, maxSeconds: 4 });
    for (const c of cues) {
      const lines = c.text.split('\n');
      expect(lines.length).toBeLessThanOrEqual(2);
      lines.forEach((l) => expect(l.length).toBeLessThanOrEqual(20));
      expect(c.end - c.start).toBeLessThanOrEqual(4);
    }
    expect(cues.map((c) => c.text.replace(/\n/g, ' ')).join(' ')).toBe(words.map((x) => x.word.trim()).join(' '));
  });

  it('spreads words evenly when the model gave none, and never overlaps cues', () => {
    const cues = buildCues([{ start: 10, end: 14, text: 'one two three four' }, { start: 13.5, end: 15, text: 'five six' }]);
    expect(cues[0].start).toBe(10);
    for (let i = 0; i < cues.length - 1; i++) expect(cues[i].end).toBeLessThanOrEqual(cues[i + 1].start);
  });

  it('writes SRT and WebVTT', () => {
    const cues = [{ start: 61.25, end: 63.5, text: 'ආයුබෝවන්\nhello' }];
    expect(stamp(3723.042, ',')).toBe('01:02:03,042');
    expect(toSrt(cues)).toBe('1\n00:01:01,250 --> 00:01:03,500\nආයුබෝවන්\nhello\n');
    expect(toVtt(cues)).toBe('WEBVTT\n\n1\n00:01:01.250 --> 00:01:03.500\nආයුබෝවන්\nhello\n');
  });

  it('reads clock times typed in the editor', () => {
    expect(parseClock('1:02.5')).toBeCloseTo(62.5);
    expect(parseClock('1:00:00')).toBe(3600);
    expect(parseClock('abc')).toBeNull();
  });
});
