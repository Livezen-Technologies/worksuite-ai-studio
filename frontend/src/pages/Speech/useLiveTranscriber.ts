import { useCallback, useEffect, useRef, useState } from 'react';
import { encodeWav, resample, rms } from './audio';
import type { Segment } from './cues';

const RATE = 16000;
const MIN_CHUNK = 4, MAX_CHUNK = 12, PAUSE_MS = 350;

export type MicError = 'blocked' | 'unavailable' | null;

/**
 * Microphone → text while you speak. Audio is cut into short pieces at natural pauses (4–12 s); each piece is
 * transcribed while recording continues and its lines come back with times on the recording's clock. The whole
 * recording is returned on stop. Shared by the Speech page and the Chat composer.
 */
export function useLiveTranscriber({ transcribe, onSegments, maxSeconds = 600 }: {
  transcribe: (wav: Blob) => Promise<Segment[]>;
  onSegments: (segs: Segment[]) => void;
  maxSeconds?: number;
}) {
  const [recording, setRecording] = useState(false);
  const [seconds, setSeconds] = useState(0);
  const [pending, setPending] = useState(0);
  const [error, setError] = useState<MicError>(null);
  const [chunkError, setChunkError] = useState<string | null>(null);
  const [analyser, setAnalyser] = useState<AnalyserNode | null>(null);
  const cb = useRef({ transcribe, onSegments });
  cb.current = { transcribe, onSegments };

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
        const segs = await cb.current.transcribe(wav);
        cb.current.onSegments(
          segs.filter((g) => /[\p{L}\p{N}]/u.test(g.text)).map((g) => ({
            ...g, start: g.start + offset, end: g.end + offset, words: g.words?.map((w) => ({ ...w, start: w.start + offset, end: w.end + offset })),
          })),
        );
      } catch (e) {
        setChunkError(e instanceof Error ? e.message : 'A piece of the recording could not be transcribed.');
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

  const start = useCallback(async () => {
    setError(null); setChunkError(null);
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
        s.quietMs = rms(frame) < 0.012 ? s.quietMs + (frame.length / RATE) * 1000 : 0;
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
        if (sec >= maxSeconds) void stop();
      }, 200);
      setAnalyser(an); setSeconds(0); setRecording(true);
      return true;
    } catch (e) {
      teardown();
      setError(e instanceof DOMException && e.name === 'NotAllowedError' ? 'blocked' : 'unavailable');
      return false;
    }
  }, [maxSeconds]);

  /** Stops, waits for the last pieces, and resolves with the whole recording. */
  const stop = useCallback((): Promise<File | null> => {
    const s = r.current;
    if (s.stopping) return s.queue.then(() => null);
    s.stopping = true;
    setRecording(false);
    sendChunk(true);
    const rec = s.rec;
    return new Promise((resolve) => {
      const finish = () => {
        teardown();
        const mime = rec?.mimeType || 'audio/webm';
        const ext = mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm';
        const file = new File(s.chunks, `recording-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.${ext}`, { type: mime });
        void s.queue.then(() => resolve(file));
      };
      if (rec && rec.state !== 'inactive') { rec.onstop = finish; rec.stop(); } else finish();
    });
  }, []);

  return { start, stop, recording, seconds, pending, error, chunkError, analyser };
}

export const micErrorText = (e: MicError) =>
  e === 'blocked' ? 'Microphone access was blocked. Allow the microphone for this site in your browser, then try again.'
    : e === 'unavailable' ? 'No microphone was found, or this browser cannot record audio.' : null;

/** One short piece → segments, via the AI Studio API. */
export async function transcribePiece(wav: Blob, language: string, api: <T>(p: string, o: { method: 'POST'; body: FormData }) => Promise<T>) {
  const fd = new FormData();
  fd.append('audio', wav, 'piece.wav');
  fd.append('language', language);
  fd.append('fast', '1');
  fd.append('words', '1');
  const r = await api<{ segments: Segment[] }>('/speech/transcribe', { method: 'POST', body: fd });
  return r.segments;
}
