import { MicrophoneIcon, StopIcon } from '@heroicons/react/24/outline';
import { Alert, cx } from '../../components/ui';
import { clock, type Segment } from './cues';
import { micErrorText, useLiveTranscriber } from './useLiveTranscriber';
import { Visualizer } from './Visualizer';

/** The Speech page's recorder: big button, live bars, timer — transcription runs while you speak. */
export function LiveRecorder({
  transcribe, onStart, onSegments, onFinished, disabled,
}: {
  transcribe: (wav: Blob) => Promise<Segment[]>;
  onStart: () => void;
  onSegments: (segs: Segment[]) => void;
  onFinished: (recording: File) => void;
  disabled?: boolean;
}) {
  const live = useLiveTranscriber({ transcribe, onSegments });
  const start = async () => { if (await live.start()) onStart(); };
  const stop = async () => { const f = await live.stop(); if (f) onFinished(f); };
  const err = micErrorText(live.error);
  return (
    <div className="flex flex-col items-center gap-5 px-6 py-8 text-center">
      {err && <Alert tone="danger" className="w-full max-w-md text-left">{err}</Alert>}
      {live.chunkError && <Alert tone="warning" className="w-full max-w-md text-left">{live.chunkError}</Alert>}
      <div className={cx('relative w-full max-w-md rounded-xl px-4 py-3 transition-colors', live.recording ? 'bg-red-50 text-red-500 dark:bg-red-500/10 dark:text-red-400' : 'bg-slate-50 text-slate-300 dark:bg-white/5 dark:text-slate-600')}>
        <Visualizer analyser={live.analyser} active={live.recording} className="h-20 w-full" />
        {live.recording && (
          <span className="absolute top-2 left-3 inline-flex items-center gap-1.5 rounded-full bg-red-600 px-2 py-0.5 text-[11px] font-semibold tracking-wide text-white uppercase">
            <span className="size-1.5 rounded-full bg-white motion-safe:animate-pulse" /> Live
          </span>
        )}
      </div>
      <button
        type="button"
        disabled={disabled && !live.recording}
        onClick={live.recording ? stop : start}
        aria-label={live.recording ? 'Stop recording' : 'Start recording'}
        className={cx(
          'relative flex size-20 items-center justify-center rounded-full text-white shadow-lg transition focus-visible:outline-2 focus-visible:outline-offset-4 disabled:opacity-50',
          live.recording ? 'bg-red-600 hover:bg-red-500 focus-visible:outline-red-600' : 'bg-brand-600 hover:bg-brand-500 focus-visible:outline-brand-600',
        )}
      >
        {live.recording && <span aria-hidden className="absolute inset-0 rounded-full bg-red-500 opacity-30 motion-safe:animate-ping" />}
        {live.recording ? <StopIcon aria-hidden className="relative size-9" /> : <MicrophoneIcon aria-hidden className="relative size-9" />}
      </button>
      <div aria-live="polite">
        <p className="text-2xl font-semibold text-slate-900 tabular-nums dark:text-white">{clock(live.seconds)}</p>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          {live.recording ? 'Listening — text appears as you speak. Tap to stop.' : live.pending > 0 ? 'Finishing the last words…' : 'Tap to record. Text appears live, up to 10 minutes.'}
        </p>
        {live.pending > 0 && <p className="mt-1 text-xs text-slate-400">{live.pending} piece{live.pending === 1 ? '' : 's'} being transcribed</p>}
      </div>
    </div>
  );
}
