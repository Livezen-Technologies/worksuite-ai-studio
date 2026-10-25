import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import {
  ArrowDownTrayIcon, ArrowUpTrayIcon, ChatBubbleLeftRightIcon, ClipboardDocumentIcon, MicrophoneIcon, StopIcon, TrashIcon,
} from '@heroicons/react/24/outline';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, PageHeader, Select, Spinner, Tabs, Textarea, Toggle, cx, useToast } from '../components/ui';
import { ApiError, api, get } from '../lib/api';

interface Status { available: boolean; model: string | null; busy: boolean; max_mb: number; languages: { value: string; label: string }[] }
interface Segment { start: number; end: number; text: string }
interface Result { text: string; segments: Segment[]; language: string; language_probability: number; duration: number; processing_seconds: number; model: string }

const ACCEPT = 'audio/*,.wav,.mp3,.m4a,.ogg,.oga,.opus,.webm,.flac,.aac';
const MAX_SECONDS = 600;

const clock = (s: number) => {
  const m = Math.floor(s / 60), r = Math.floor(s % 60);
  return `${m}:${String(r).padStart(2, '0')}`;
};
const srtTime = (s: number) => {
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = Math.floor(s % 60), ms = Math.round((s % 1) * 1000);
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')},${String(ms).padStart(3, '0')}`;
};
function save(name: string, text: string, type = 'text/plain') {
  const url = URL.createObjectURL(new Blob([text], { type: `${type};charset=utf-8` }));
  const a = Object.assign(document.createElement('a'), { href: url, download: name });
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Microphone recorder with a live level meter and a 10-minute cap. */
function Recorder({ onRecorded, disabled }: { onRecorded: (f: File) => void; disabled?: boolean }) {
  const [recording, setRecording] = useState(false);
  const [seconds, setSeconds] = useState(0);
  const [level, setLevel] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const rec = useRef<MediaRecorder | null>(null);
  const stream = useRef<MediaStream | null>(null);
  const timer = useRef<number | undefined>(undefined);
  const raf = useRef<number | undefined>(undefined);

  const cleanup = () => {
    window.clearInterval(timer.current);
    if (raf.current) cancelAnimationFrame(raf.current);
    stream.current?.getTracks().forEach((t) => t.stop());
    stream.current = null;
    setLevel(0);
  };
  useEffect(() => cleanup, []);

  const start = async () => {
    setError(null);
    try {
      const s = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
      stream.current = s;
      const type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find((t) => MediaRecorder.isTypeSupported(t)) ?? '';
      const r = new MediaRecorder(s, type ? { mimeType: type } : undefined);
      const chunks: Blob[] = [];
      r.ondataavailable = (e) => e.data.size && chunks.push(e.data);
      r.onstop = () => {
        cleanup();
        const mime = r.mimeType || 'audio/webm';
        const ext = mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm';
        onRecorded(new File(chunks, `recording-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.${ext}`, { type: mime }));
      };
      r.start(1000);
      rec.current = r;
      setSeconds(0);
      setRecording(true);
      const started = Date.now();
      timer.current = window.setInterval(() => {
        const sec = (Date.now() - started) / 1000;
        setSeconds(sec);
        if (sec >= MAX_SECONDS) stop();
      }, 250);
      const ctx = new AudioContext();
      const an = ctx.createAnalyser();
      an.fftSize = 512;
      ctx.createMediaStreamSource(s).connect(an);
      const buf = new Uint8Array(an.frequencyBinCount);
      const tick = () => {
        an.getByteTimeDomainData(buf);
        let peak = 0;
        for (const v of buf) peak = Math.max(peak, Math.abs(v - 128));
        setLevel(Math.min(1, peak / 64));
        raf.current = requestAnimationFrame(tick);
      };
      tick();
    } catch (e) {
      cleanup();
      setError(e instanceof DOMException && e.name === 'NotAllowedError'
        ? 'Microphone access was blocked. Allow the microphone for this site in your browser, then try again.'
        : 'No microphone was found, or the browser cannot record audio.');
    }
  };
  const stop = () => {
    if (rec.current && rec.current.state !== 'inactive') rec.current.stop();
    setRecording(false);
  };

  return (
    <div className="flex flex-col items-center gap-5 px-6 py-10 text-center">
      {error && <Alert tone="danger" className="w-full max-w-md text-left">{error}</Alert>}
      <button
        type="button"
        disabled={disabled}
        onClick={recording ? stop : start}
        aria-label={recording ? 'Stop recording' : 'Start recording'}
        className={cx(
          'relative flex size-24 items-center justify-center rounded-full text-white shadow-lg transition focus-visible:outline-2 focus-visible:outline-offset-4 disabled:opacity-50',
          recording ? 'bg-red-600 hover:bg-red-500 focus-visible:outline-red-600' : 'bg-brand-600 hover:bg-brand-500 focus-visible:outline-brand-600',
        )}
      >
        {recording && <span aria-hidden className="absolute inset-0 rounded-full bg-red-500 opacity-30 motion-safe:animate-ping" style={{ transform: `scale(${1 + level * 0.35})` }} />}
        {recording ? <StopIcon aria-hidden className="relative size-10" /> : <MicrophoneIcon aria-hidden className="relative size-10" />}
      </button>
      <div>
        <p className="text-2xl font-semibold text-slate-900 tabular-nums dark:text-white">{clock(seconds)}</p>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          {recording ? 'Recording… tap the button to stop.' : 'Tap to record. Up to 10 minutes.'}
        </p>
      </div>
      {recording && (
        <div className="h-2 w-48 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10" role="meter" aria-label="Input level" aria-valuenow={Math.round(level * 100)} aria-valuemin={0} aria-valuemax={100}>
          <div className="h-full rounded-full bg-red-500 transition-[width] duration-75" style={{ width: `${level * 100}%` }} />
        </div>
      )}
    </div>
  );
}

function Uploader({ onPicked, disabled, maxMb }: { onPicked: (f: File) => void; disabled?: boolean; maxMb: number }) {
  const [over, setOver] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const pick = (f?: File | null) => f && onPicked(f);
  return (
    <div
      onDragOver={(e) => { e.preventDefault(); setOver(true); }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => { e.preventDefault(); setOver(false); pick(e.dataTransfer.files?.[0]); }}
      className={cx('m-4 rounded-lg border-2 border-dashed px-6 py-12 text-center transition-colors sm:m-6',
        over ? 'border-brand-500 bg-brand-50 dark:bg-blue-500/10' : 'border-slate-300 dark:border-white/15')}
    >
      <ArrowUpTrayIcon aria-hidden className="mx-auto size-10 text-slate-400" />
      <p className="mt-3 text-sm text-slate-700 dark:text-slate-300">
        Drop an audio file here, or{' '}
        <button type="button" disabled={disabled} onClick={() => input.current?.click()} className="font-semibold text-brand-600 hover:text-brand-500 dark:text-blue-400">choose a file</button>
      </p>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">WAV, MP3, M4A, OGG, WebM or FLAC · up to {maxMb} MB</p>
      <input ref={input} type="file" accept={ACCEPT} className="sr-only" onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ''; }} />
    </div>
  );
}

export default function Speech() {
  const toast = useToast();
  const navigate = useNavigate();
  const status = useQuery({ queryKey: ['speech', 'status'], queryFn: () => get<Status>('/speech/status'), refetchInterval: 30_000 });
  const [mode, setMode] = useState<'record' | 'upload'>('record');
  const [language, setLanguage] = useState('si');
  const [timestamps, setTimestamps] = useState(true);
  const [file, setFile] = useState<File | null>(null);
  const [audioUrl, setAudioUrl] = useState<string | null>(null);
  const [result, setResult] = useState<Result | null>(null);
  const [text, setText] = useState('');

  useEffect(() => () => { if (audioUrl) URL.revokeObjectURL(audioUrl); }, [audioUrl]);

  const run = useMutation({
    mutationFn: (f: File) => {
      const fd = new FormData();
      fd.append('audio', f);
      fd.append('language', language);
      return api<Result>('/speech/transcribe', { method: 'POST', body: fd });
    },
    onSuccess: (r) => {
      setResult(r);
      setText(r.text);
      if (!r.text) toast.info('No speech found', 'Try a clearer or longer recording.');
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.message : 'Transcription failed.'),
  });

  const take = (f: File) => {
    const maxMb = status.data?.max_mb ?? 60;
    if (f.size > maxMb * 1048576) return toast.error(`Audio files can be up to ${maxMb} MB.`);
    if (audioUrl) URL.revokeObjectURL(audioUrl);
    setFile(f);
    setAudioUrl(URL.createObjectURL(f));
    setResult(null);
    setText('');
    run.mutate(f);
  };
  const reset = () => {
    if (audioUrl) URL.revokeObjectURL(audioUrl);
    setFile(null); setAudioUrl(null); setResult(null); setText('');
  };
  const base = (file?.name ?? 'transcript').replace(/\.[^.]+$/, '');
  const srt = () => (result?.segments ?? []).map((s, i) => `${i + 1}\n${srtTime(s.start)} --> ${srtTime(s.end)}\n${s.text}\n`).join('\n');
  const down = status.data && !status.data.available;

  return (
    <>
      <PageHeader
        title="Speech to Text"
        description="Record or upload Sinhala speech and get the text. Audio is transcribed on your AI Studio server and is not stored."
        meta={status.data?.model && (
          <span className="inline-flex items-center gap-2">
            <Badge tone={status.data.available ? 'green' : 'red'} dot>{status.data.available ? (status.data.busy ? 'Busy' : 'Ready') : 'Offline'}</Badge>
            {status.data.model}
          </span>
        )}
      />
      {down && <Alert tone="danger" className="mb-6" title="The speech service is offline">Ask an administrator to start the aistudio-asr service on the server.</Alert>}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <Card className="lg:col-span-2">
          <div className="border-b border-slate-200 px-4 pt-2 sm:px-6 dark:border-white/10">
            <Tabs value={mode} onChange={(k) => setMode(k as 'record' | 'upload')} tabs={[{ key: 'record', label: 'Record' }, { key: 'upload', label: 'Upload' }]} />
          </div>
          {mode === 'record'
            ? <Recorder onRecorded={take} disabled={run.isPending || down} />
            : <Uploader onPicked={take} disabled={run.isPending || down} maxMb={status.data?.max_mb ?? 60} />}
          <div className="space-y-4 border-t border-slate-200 px-4 py-4 sm:px-6 dark:border-white/10">
            <Select label="Language" value={language} onChange={(e) => setLanguage(e.target.value)}
              options={status.data?.languages ?? [{ value: 'si', label: 'Sinhala' }, { value: 'en', label: 'English' }, { value: 'auto', label: 'Detect automatically' }]}
              hint="The model is tuned for Sinhala; English and detection work but are less accurate." />
            <Toggle checked={timestamps} onChange={setTimestamps} label="Show timestamps" description="Time-coded lines, also used for the .srt subtitle download." />
          </div>
          {audioUrl && (
            <div className="border-t border-slate-200 px-4 py-4 sm:px-6 dark:border-white/10">
              <p className="mb-2 truncate text-xs text-slate-500 dark:text-slate-400" title={file?.name}>{file?.name}</p>
              <audio controls src={audioUrl} className="w-full" />
            </div>
          )}
        </Card>

        <Card className="lg:col-span-3">
          <CardHeader
            title="Transcript"
            description={result ? `${clock(result.duration)} of audio · ${result.language === 'si' ? 'Sinhala' : result.language} · transcribed in ${result.processing_seconds.toFixed(1)} s` : undefined}
            actions={result && (
              <>
                <Button size="sm" icon={ClipboardDocumentIcon} onClick={() => navigator.clipboard.writeText(text).then(() => toast.success('Copied'))}>Copy</Button>
                <Button size="sm" icon={ArrowDownTrayIcon} onClick={() => save(`${base}.txt`, text)}>.txt</Button>
                {result.segments.length > 0 && <Button size="sm" icon={ArrowDownTrayIcon} onClick={() => save(`${base}.srt`, srt(), 'application/x-subrip')}>.srt</Button>}
                <Button size="sm" variant="soft" icon={ChatBubbleLeftRightIcon} disabled={!text} onClick={() => navigate('/chat', { state: { prompt: text } })}>Send to chat</Button>
                <Button size="sm" variant="ghost" icon={TrashIcon} onClick={reset}>Clear</Button>
              </>
            )}
          />
          {run.isPending ? (
            <div className="flex flex-col items-center gap-3 px-6 py-16 text-slate-500 dark:text-slate-400" aria-live="polite">
              <Spinner className="size-7 text-brand-600" />
              <p className="text-sm">Transcribing… about as long as the audio on this server.</p>
            </div>
          ) : !result ? (
            <EmptyState icon={MicrophoneIcon} title="No transcript yet" description="Record or upload audio and the text appears here." />
          ) : (
            <div className="space-y-4 p-4 sm:p-6">
              <Textarea aria-label="Transcript" rows={Math.min(14, Math.max(5, Math.ceil(text.length / 70)))} value={text} onChange={(e) => setText(e.target.value)} className="text-base/7" />
              {timestamps && result.segments.length > 0 && (
                <ol className="divide-y divide-slate-100 rounded-md ring-1 ring-slate-200 dark:divide-white/5 dark:ring-white/10">
                  {result.segments.map((s, i) => (
                    <li key={i} className="flex gap-4 px-3 py-2 text-sm">
                      <span className="w-24 shrink-0 font-mono text-xs/6 text-slate-500 tabular-nums dark:text-slate-400">{clock(s.start)}–{clock(s.end)}</span>
                      <span className="text-slate-800 dark:text-slate-200">{s.text}</span>
                    </li>
                  ))}
                </ol>
              )}
            </div>
          )}
        </Card>
      </div>
    </>
  );
}
