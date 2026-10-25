import { useEffect, useMemo, useRef, useState } from 'react';
import { PlusIcon, TrashIcon } from '@heroicons/react/20/solid';
import { Button, IconButton, Select, cx } from '../../components/ui';
import { clock, parseClock, toVtt, type Cue, type CueRules } from './cues';

/** Editable caption cues: start, end and text per line, with the rules that generated them. */
export function CaptionEditor({ cues, onChange, rules, onRules, onRegenerate, playerTime, onSeek }: {
  cues: Cue[]; onChange: (c: Cue[]) => void; rules: CueRules; onRules: (r: CueRules) => void; onRegenerate: () => void;
  playerTime: number; onSeek: (t: number) => void;
}) {
  const set = (i: number, patch: Partial<Cue>) => onChange(cues.map((c, j) => (j === i ? { ...c, ...patch } : c)));
  const active = cues.findIndex((c) => playerTime >= c.start && playerTime < c.end);
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-4 sm:items-end">
        <Select label="Characters per line" value={rules.maxChars} onChange={(e) => onRules({ ...rules, maxChars: Number(e.target.value) })}
          options={[32, 37, 42, 50].map((n) => ({ value: n, label: `${n}${n === 42 ? ' (standard)' : ''}` }))} />
        <Select label="Lines per caption" value={rules.maxLines} onChange={(e) => onRules({ ...rules, maxLines: Number(e.target.value) })}
          options={[{ value: 1, label: '1 line' }, { value: 2, label: '2 lines' }]} />
        <Select label="Longest on screen" value={rules.maxSeconds} onChange={(e) => onRules({ ...rules, maxSeconds: Number(e.target.value) })}
          options={[3, 4, 5, 6, 7].map((n) => ({ value: n, label: `${n} seconds` }))} />
        <Button onClick={onRegenerate}>Rebuild captions</Button>
      </div>
      <p className="text-xs text-slate-500 dark:text-slate-400">Edit any time or line below; click a time to jump there. Rebuilding replaces your edits.</p>
      <ol className="divide-y divide-slate-100 rounded-md ring-1 ring-slate-200 dark:divide-white/5 dark:ring-white/10">
        {cues.map((c, i) => (
          <li key={i} className={cx('grid grid-cols-[auto_1fr_auto] items-start gap-3 px-3 py-2', i === active && 'bg-brand-50 dark:bg-blue-500/10')}>
            <div className="flex w-28 flex-col gap-1 pt-1">
              <TimeInput value={c.start} onChange={(t) => set(i, { start: t })} onFocusTime={onSeek} label={`Caption ${i + 1} start`} />
              <TimeInput value={c.end} onChange={(t) => set(i, { end: t })} onFocusTime={onSeek} label={`Caption ${i + 1} end`} />
            </div>
            <textarea
              aria-label={`Caption ${i + 1} text`}
              value={c.text}
              rows={Math.max(1, c.text.split('\n').length)}
              onChange={(e) => set(i, { text: e.target.value })}
              className="block w-full resize-none rounded-md bg-white px-2 py-1.5 text-sm text-slate-900 outline-1 -outline-offset-1 outline-slate-200 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 dark:bg-white/5 dark:text-white dark:outline-white/10"
            />
            <div className="flex flex-col">
              <IconButton label="Add a caption after this one" icon={PlusIcon} onClick={() => onChange([...cues.slice(0, i + 1), { start: c.end, end: c.end + 2, text: '' }, ...cues.slice(i + 1)])} />
              <IconButton label="Remove this caption" icon={TrashIcon} onClick={() => onChange(cues.filter((_, j) => j !== i))} />
            </div>
          </li>
        ))}
      </ol>
    </div>
  );
}

function TimeInput({ value, onChange, onFocusTime, label }: { value: number; onChange: (t: number) => void; onFocusTime: (t: number) => void; label: string }) {
  const fmt = (t: number) => `${clock(t)}.${Math.round((t % 1) * 10)}`;
  const [draft, setDraft] = useState(fmt(value));
  useEffect(() => setDraft(fmt(value)), [value]);
  return (
    <input
      aria-label={label}
      value={draft}
      onFocus={() => onFocusTime(value)}
      onChange={(e) => setDraft(e.target.value)}
      onBlur={() => { const t = parseClock(draft); if (t !== null) onChange(Math.max(0, t)); else setDraft(fmt(value)); }}
      className="w-full rounded bg-transparent px-1 font-mono text-xs text-slate-600 tabular-nums outline-1 -outline-offset-1 outline-transparent hover:outline-slate-200 focus:outline-brand-600 dark:text-slate-300 dark:hover:outline-white/10"
    />
  );
}

/** The recording or uploaded video, playing with its captions — over the picture for video, under the player for audio. */
export function CaptionPreview({ url, isVideo, cues, onTime, seekTo }: { url: string; isVideo: boolean; cues: Cue[]; onTime: (t: number) => void; seekTo: number | null }) {
  const media = useRef<HTMLVideoElement & HTMLAudioElement>(null);
  const [t, setT] = useState(0);
  const vttUrl = useMemo(() => URL.createObjectURL(new Blob([toVtt(cues)], { type: 'text/vtt' })), [cues]);
  useEffect(() => () => URL.revokeObjectURL(vttUrl), [vttUrl]);
  useEffect(() => { if (seekTo !== null && media.current) media.current.currentTime = seekTo; }, [seekTo]);
  const current = cues.find((c) => t >= c.start && t < c.end);
  const update = () => { const now = media.current?.currentTime ?? 0; setT(now); onTime(now); };
  return (
    <div className="space-y-3">
      {isVideo ? (
        <div className="relative overflow-hidden rounded-lg bg-black">
          <video ref={media} src={url} controls onTimeUpdate={update} className="aspect-video w-full" crossOrigin="anonymous">
            <track kind="captions" srcLang="si" label="Sinhala" src={vttUrl} default />
          </video>
        </div>
      ) : (
        <>
          <audio ref={media} src={url} controls onTimeUpdate={update} className="w-full" />
          <div className="flex min-h-24 items-center justify-center rounded-lg bg-slate-900 px-6 py-5 text-center dark:bg-black/60" aria-live="polite">
            {current
              ? <p className="text-lg/7 whitespace-pre-line text-white">{current.text}</p>
              : <p className="text-sm text-slate-400">Captions appear here as the audio plays.</p>}
          </div>
        </>
      )}
    </div>
  );
}
