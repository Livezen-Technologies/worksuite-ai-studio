import { useEffect, useRef, useState } from 'react';
import { MicrophoneIcon, StopIcon } from '@heroicons/react/24/outline';
import { Alert, cx } from '../../components/ui';
import { encodeWav, resample, rms } from './audio';
import { clock, type Segment } from './cues';
import { Visualizer } from './Visualizer';

const RATE = 16000;
const MIN_CHUNK = 4, MAX_CHUNK = 12, PAUSE_MS = 350, MAX_SECONDS = 600;

/**
 * Records from the microphone and transcribes as you speak: audio is cut into short pieces at natural pauses
 * (4–12 s), each piece goes to the server while recording continues, and its lines come back with their times
 * shifted to the recording's clock. The whole recording is also kept for playback and caption preview.
 */
export function LiveRecorder({
  transcribe, onStart, onSegments, onFinished, disabled,
}: {
  transcribe: (wav: Blob) => Promise<Segment[]>;
  onStart: () => void;
  onSegments: (segs: Segment[]) => void;
  onFinished: (recording: File) => void;
  disabled?: boolean;
}) {
  const [recording, setRecording] = useState(false);
  const [seconds, setSeconds] = useState(0);
  const [pending, setPending] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [analyser, setAnalyser] = useState<AnalyserNode | null>(null);

  const r = useRef<{
    stream?: MediaStream; ctx?: AudioContext; proc?: ScriptProcessorNode; rec?: MediaRecorder; chunks: Blob[];
    buf: Float32Array[]; bufLen: number; offset: number; quietMs: number; started: number; timer?: number;
    queue: Promise<void>; stopping: boolean;
  }>({ chunks: [], buf: [], bufLen: 0, offset: 0, quietMs: 0, started: 0, queue: Promise.resolve(), stopping: false });

  const sendChunk = (final = false) => {
    const s = r.current;
    if (s.bufLen < RATE * (final ? 0.6 : 1)) { if (final) { s.buf = []; s.bufLen = 0; } return; }
    const pcm = new Float32Array(s.bufLen);
    let o = 0;
    for (const b of s.buf) { pcm.set(b, o); o += b.length; }
    const offset = s.offset;
    s.offset += s.bufLen / RATE;
    s.buf = []; s.bufLen = 0; s.quietMs = 0;
    const wav = encodeWav(pcm, RATE);
    setPending((n) => n + 1);
    s.queue = s.queue.then(async () => {
      try {
        const segs = await transcribe(wav);
        onSegments(segs.map((g) => ({ ...g, start: g.start + offset, end: g.end + offset, words: g.words?.map((w) => ({ ...w, start: w.start + offset, end: w.end + offset })) })));
      } catch (e) {
        setError(e instanceof Error ? e.message : 'A piece of the recording could not be transcribed.');
      } finally {
        setPending((n) => n - 1);
      }
    });
  };

  const teardown = () => {
    const s = r.current;
    window.clearInterval(s.timer);
    s.proc?.disconnect();
    s.stream?.getTracks().forEach((t) => t.stop());
    s.ctx?.close().catch(() => {});
    setAnalyser(null);
  };
  useEffect(() => () => teardown(), []);

  const start = async () => {
    setError(null);
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true } });
      const ctx = new AudioContext();
      const src = ctx.createMediaStreamSource(stream);
      const an = ctx.createAnalyser();
      an.fftSize = 1024; an.smoothingTimeConstant = 0.6;
      src.connect(an);
      const proc = ctx.createScriptProcessor(4096, 1, 1);
      src.connect(proc); proc.connect(ctx.destination);
      const s = r.current;
      Object.assign(s, { stream, ctx, proc, chunks: [], buf: [], bufLen: 0, offset: 0, quietMs: 0, started: Date.now(), queue: Promise.resolve(), stopping: false });
      proc.onaudioprocess = (e) => {
        const frame = resample(new Float32Array(e.inputBuffer.getChannelData(0)), ctx.sampleRate, RATE);
        s.buf.push(frame); s.bufLen += frame.length;
        const ms = (frame.length / RATE) * 1000;
        s.quietMs = rms(frame) < 0.012 ? s.quietMs + ms : 0;
        const secs = s.bufLen / RATE;
        if ((secs >= MIN_CHUNK && s.quietMs >= PAUSE_MS) || secs >= MAX_CHUNK) sendChunk();
      };
      const type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find((t) => MediaRecorder.isTypeSupported(t)) ?? '';
      const rec = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
      rec.ondataavailable = (e) => e.data.size && s.chunks.push(e.data);
      rec.start(1000);
      s.rec = rec;
      s.timer = window.setInterval(() => {
        const sec = (Date.now() - s.started) / 1000;
        setSeconds(sec);
        if (sec >= MAX_SECONDS) stop();
      }, 200);
      setAnalyser(an);
      setSeconds(0);
      setRecording(true);
      onStart();
    } catch (e) {
      teardown();
      setError(e instanceof DOMException && e.name === 'NotAllowedError'
        ? 'Microphone access was blocked. Allow the microphone for this site in your browser, then try again.'
        : 'No microphone was found, or this browser cannot record audio.');
    }
  };

  const stop = () => {
    const s = r.current;
    if (s.stopping) return;
    s.stopping = true;
    setRecording(false);
    sendChunk(true);
    const rec = s.rec;
    const finish = () => {
      teardown();
      const mime = rec?.mimeType || 'audio/webm';
      const ext = mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm';
      const file = new File(s.chunks, `recording-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.${ext}`, { type: mime });
      s.queue.then(() => onFinished(file));
    };
    if (rec && rec.state !== 'inactive') { rec.onstop = finish; rec.stop(); } else finish();
  };

  return (
    <div className="flex flex-col items-center gap-5 px-6 py-8 text-center">
      {error && <Alert tone="danger" className="w-full max-w-md text-left">{error}</Alert>}
      <div className={cx('relative w-full max-w-md rounded-xl px-4 py-3 transition-colors', recording ? 'bg-red-50 text-red-500 dark:bg-red-500/10 dark:text-red-400' : 'bg-slate-50 text-slate-300 dark:bg-white/5 dark:text-slate-600')}>
        <Visualizer analyser={analyser} active={recording} className="h-20 w-full" />
        {recording && (
          <span className="absolute top-2 left-3 inline-flex items-center gap-1.5 rounded-full bg-red-600 px-2 py-0.5 text-[11px] font-semibold tracking-wide text-white uppercase">
            <span className="size-1.5 rounded-full bg-white motion-safe:animate-pulse" /> Live
          </span>
        )}
      </div>
      <button
        type="button"
        disabled={disabled && !recording}
        onClick={recording ? stop : start}
        aria-label={recording ? 'Stop recording' : 'Start recording'}
        className={cx(
          'relative flex size-20 items-center justify-center rounded-full text-white shadow-lg transition focus-visible:outline-2 focus-visible:outline-offset-4 disabled:opacity-50',
          recording ? 'bg-red-600 hover:bg-red-500 focus-visible:outline-red-600' : 'bg-brand-600 hover:bg-brand-500 focus-visible:outline-brand-600',
        )}
      >
        {recording && <span aria-hidden className="absolute inset-0 rounded-full bg-red-500 opacity-30 motion-safe:animate-ping" />}
        {recording ? <StopIcon aria-hidden className="relative size-9" /> : <MicrophoneIcon aria-hidden className="relative size-9" />}
      </button>
      <div aria-live="polite">
        <p className="text-2xl font-semibold text-slate-900 tabular-nums dark:text-white">{clock(seconds)}</p>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          {recording ? 'Listening — text appears as you speak. Tap to stop.' : pending > 0 ? 'Finishing the last words…' : 'Tap to record. Text appears live, up to 10 minutes.'}
        </p>
        {pending > 0 && <p className="mt-1 text-xs text-slate-400">{pending} piece{pending === 1 ? '' : 's'} being transcribed</p>}
      </div>
    </div>
  );
}
