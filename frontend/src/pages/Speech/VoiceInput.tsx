import { useEffect, useState } from 'react';
import { MicrophoneIcon, StopIcon } from '@heroicons/react/24/solid';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { ChevronUpIcon } from '@heroicons/react/20/solid';
import { cx, useToast } from '../../components/ui';
import { api } from '../../lib/api';
import { clock } from './cues';
import { micErrorText, transcribePiece, useLiveTranscriber } from './useLiveTranscriber';
import { Visualizer } from './Visualizer';

const LANGS = [
  { value: 'si', label: 'සිංහල', long: 'Sinhala' },
  { value: 'mix', label: 'සිං + EN', long: 'Sinhala + English (mixed)' },
  { value: 'en', label: 'EN', long: 'English' },
];
const KEY = 'aistudio.voice.lang';

/**
 * Microphone button for a text box: speak and the words are typed in as you talk (Whisper Sinhala on the server).
 * `onText` receives each new piece of text; the caller appends it to its draft.
 */
export function VoiceInput({ onText, disabled, maxSeconds = 300 }: { onText: (text: string) => void; disabled?: boolean; maxSeconds?: number }) {
  const toast = useToast();
  const [lang, setLang] = useState(() => { try { return localStorage.getItem(KEY) || 'si'; } catch { return 'si'; } });
  const live = useLiveTranscriber({
    maxSeconds,
    transcribe: (wav) => transcribePiece(wav, lang, api),
    onSegments: (segs) => { const t = segs.map((s) => s.text).join(' ').trim(); if (t) onText(t); },
  });
  useEffect(() => { const e = micErrorText(live.error); if (e) toast.error(e); }, [live.error]);
  useEffect(() => { if (live.chunkError) toast.error(live.chunkError); }, [live.chunkError]);
  const pick = (v: string) => { setLang(v); try { localStorage.setItem(KEY, v); } catch { /* ignore */ } };
  const current = LANGS.find((l) => l.value === lang) ?? LANGS[0];

  if (live.recording || live.pending > 0) {
    return (
      <div className="flex h-10 shrink-0 items-center gap-2 rounded-md bg-red-50 pr-1 pl-2 text-red-500 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/30" aria-live="polite">
        <Visualizer analyser={live.analyser} active={live.recording} className="hidden h-6 w-20 sm:block" />
        <span className="text-xs font-semibold tabular-nums">{live.recording ? clock(live.seconds) : 'Finishing…'}</span>
        <button
          type="button"
          onClick={() => void live.stop()}
          disabled={!live.recording}
          aria-label="Stop voice input"
          className="relative flex size-8 items-center justify-center rounded-full bg-red-600 text-white hover:bg-red-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:opacity-60"
        >
          {live.recording && <span aria-hidden className="absolute inset-0 rounded-full bg-red-500 opacity-40 motion-safe:animate-ping" />}
          <StopIcon aria-hidden className="relative size-4" />
        </button>
      </div>
    );
  }

  return (
    <div className="flex h-10 shrink-0 items-stretch rounded-md ring-1 ring-slate-200 dark:ring-white/10">
      <button
        type="button"
        disabled={disabled}
        onClick={() => void live.start()}
        title={`Speak — ${current.long}`}
        aria-label={`Voice input (${current.long})`}
        className="flex w-10 items-center justify-center rounded-l-md text-slate-500 hover:bg-slate-50 hover:text-brand-600 focus-visible:outline-2 focus-visible:outline-brand-600 disabled:opacity-40 dark:text-slate-400 dark:hover:bg-white/5 dark:hover:text-blue-400"
      >
        <MicrophoneIcon aria-hidden className="size-5" />
      </button>
      <Menu as="div" className="relative">
        <MenuButton disabled={disabled} aria-label={`Voice language: ${current.long}`} className="flex h-full items-center gap-0.5 rounded-r-md border-l border-slate-200 px-1.5 text-[11px] font-medium text-slate-500 hover:bg-slate-50 disabled:opacity-40 dark:border-white/10 dark:text-slate-400 dark:hover:bg-white/5">
          {current.label}<ChevronUpIcon aria-hidden className="size-3" />
        </MenuButton>
        <MenuItems transition anchor="top start" className="z-50 mb-1 w-56 rounded-md bg-white py-1 shadow-lg ring-1 ring-slate-900/5 transition data-closed:scale-95 data-closed:opacity-0 dark:bg-slate-800 dark:ring-white/10">
          {LANGS.map((l) => (
            <MenuItem key={l.value}>
              <button type="button" onClick={() => pick(l.value)} className={cx('flex w-full items-center justify-between px-3 py-2 text-left text-sm data-focus:bg-slate-50 dark:data-focus:bg-white/5', l.value === lang ? 'font-semibold text-brand-600 dark:text-blue-400' : 'text-slate-700 dark:text-slate-200')}>
                {l.long}<span className="text-xs text-slate-400">{l.label}</span>
              </button>
            </MenuItem>
          ))}
        </MenuItems>
      </Menu>
    </div>
  );
}
