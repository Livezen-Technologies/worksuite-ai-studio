import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  ArrowDownTrayIcon, ArrowUpTrayIcon, ChatBubbleLeftRightIcon, ClipboardDocumentIcon, FilmIcon, MicrophoneIcon, TrashIcon,
} from '@heroicons/react/24/outline';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, Meter, PageHeader, Select, Spinner, Tabs, Textarea, cx, useToast } from '../components/ui';
import { ApiError, api, get } from '../lib/api';
import { uploadWithProgress } from './SinLama/upload';
import { LiveRecorder } from './Speech/LiveRecorder';
import { CaptionEditor, CaptionPreview } from './Speech/CaptionTools';
import { buildCues, clock, DEFAULT_RULES, toSrt, toVtt, type Cue, type CueRules, type Segment } from './Speech/cues';

interface Status { available: boolean; model: string | null; busy: boolean; max_mb: number; languages: { value: string; label: string }[]; models?: { value: string; label: string; note: string; installed: boolean }[]; run_on?: { value: string; label: string; device: string | null; ready?: boolean }[] }
interface Result { text: string; segments: Segment[]; language: string; duration: number; processing_seconds: number; model: string; ran_on?: { name: string; device: string } }

const ACCEPT = 'audio/*,video/*,.wav,.mp3,.m4a,.ogg,.oga,.opus,.webm,.flac,.aac,.mp4,.mov,.mkv';

function save(name: string, text: string, type = 'text/plain') {
  const url = URL.createObjectURL(new Blob([text], { type: `${type};charset=utf-8` }));
  const a = Object.assign(document.createElement('a'), { href: url, download: name });
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
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
      <div className="mx-auto flex w-fit gap-2 text-slate-400">
        <ArrowUpTrayIcon aria-hidden className="size-9" /><FilmIcon aria-hidden className="size-9" />
      </div>
      <p className="mt-3 text-sm text-slate-700 dark:text-slate-300">
        Drop audio or video here, or{' '}
        <button type="button" disabled={disabled} onClick={() => input.current?.click()} className="font-semibold text-brand-600 hover:text-brand-500 dark:text-blue-400">choose a file</button>
      </p>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Audio: WAV, MP3, M4A, OGG, WebM, FLAC · Video: MP4, MOV, WebM · up to {maxMb} MB</p>
      <input ref={input} type="file" accept={ACCEPT} className="sr-only" onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ''; }} />
    </div>
  );
}

export default function Speech() {
  const toast = useToast();
  const navigate = useNavigate();
  const status = useQuery({ queryKey: ['speech', 'status'], queryFn: () => get<Status>('/speech/status'), refetchInterval: 30_000 });
  const [mode, setMode] = useState<'live' | 'upload'>('live');
  const [view, setView] = useState<'transcript' | 'captions'>('transcript');
  const [language, setLanguage] = useState('si');
  const [model, setModel] = useState('sinhala');
  const modelRef = useRef(model);
  modelRef.current = model;
  const [runOn, setRunOn] = useState('auto');
  const runOnRef = useRef(runOn);
  runOnRef.current = runOn;
  const [segments, setSegments] = useState<Segment[]>([]);
  const [text, setText] = useState('');
  const [textEdited, setTextEdited] = useState(false);
  const [rules, setRules] = useState<CueRules>(DEFAULT_RULES);
  const [cues, setCues] = useState<Cue[]>([]);
  const [media, setMedia] = useState<{ url: string; name: string; video: boolean } | null>(null);
  const [busy, setBusy] = useState<false | 'live' | 'upload'>(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [meta, setMeta] = useState<{ duration: number; seconds: number; where?: string } | null>(null);
  const [playerTime, setPlayerTime] = useState(0);
  const [seekTo, setSeekTo] = useState<number | null>(null);
  const langRef = useRef(language);
  langRef.current = language;
  const liveEnd = useRef<HTMLDivElement>(null);

  // the transcript text follows the segments until someone edits it
  useEffect(() => { if (!textEdited) setText(segments.map((s) => s.text).join(' ').trim()); }, [segments, textEdited]);
  useEffect(() => { setCues(buildCues(segments, rules)); }, [segments, rules]);
  useEffect(() => { if (busy === 'live') liveEnd.current?.scrollIntoView({ block: 'nearest' }); }, [segments, busy]);
  useEffect(() => () => { if (media) URL.revokeObjectURL(media.url); }, [media]);

  const reset = () => {
    if (media) URL.revokeObjectURL(media.url);
    setMedia(null); setSegments([]); setText(''); setTextEdited(false); setCues([]); setMeta(null); setProgress(null);
  };

  // one short piece of a live recording
  const transcribeChunk = useCallback(async (wav: Blob) => {
    const fd = new FormData();
    fd.append('audio', wav, 'chunk.wav');
    fd.append('language', langRef.current);
    fd.append('model', modelRef.current);
    fd.append('run_on', runOnRef.current);
    fd.append('fast', '1');
    fd.append('words', '1');
    const r = await api<Result>('/speech/transcribe', { method: 'POST', body: fd });
    return r.segments;
  }, []);

  const upload = async (f: File) => {
    const maxMb = status.data?.max_mb ?? 500;
    if (f.size > maxMb * 1048576) return toast.error(`Files can be up to ${maxMb} MB.`);
    reset();
    setMedia({ url: URL.createObjectURL(f), name: f.name, video: f.type.startsWith('video/') || /\.(mp4|mov|mkv)$/i.test(f.name) });
    setBusy('upload'); setProgress(0);
    const fd = new FormData();
    fd.append('audio', f);
    fd.append('language', language);
    fd.append('model', model);
    fd.append('run_on', runOn);
    fd.append('words', '1');
    try {
      const { data } = await uploadWithProgress<Result>('/speech/transcribe', fd, setProgress);
      setSegments(data.segments.filter((g) => /[\p{L}\p{N}]/u.test(g.text)));
      setMeta({ duration: data.duration, seconds: data.processing_seconds, where: data.ran_on ? `${data.ran_on.name} (${data.ran_on.device})` : undefined });
      if (!data.text) toast.info('No speech found', 'Try a clearer or longer recording.');
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Transcription failed.');
    } finally {
      setBusy(false); setProgress(null);
    }
  };

  const base = (media?.name ?? 'transcript').replace(/\.[^.]+$/, '');
  const down = status.data && !status.data.available;
  const hasResult = segments.length > 0 || !!text;
  const words = useMemo(() => text.split(/\s+/).filter(Boolean).length, [text]);

  return (
    <>
      <PageHeader
        title="Speech to Text"
        description="Speak or upload Sinhala audio or video: get the transcript live, then captions and subtitles. Transcribed on your AI Studio server; nothing is stored."
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
            <Tabs value={mode} onChange={(k) => !busy && setMode(k as 'live' | 'upload')} tabs={[{ key: 'live', label: 'Live recording' }, { key: 'upload', label: 'Upload audio or video' }]} />
          </div>
          {mode === 'live' ? (
            <LiveRecorder
              disabled={!!busy || down}
              transcribe={transcribeChunk}
              onStart={() => { reset(); setBusy('live'); setView('transcript'); }}
              onSegments={(segs) => setSegments((prev) => [...prev, ...segs.filter((g) => /[\p{L}\p{N}]/u.test(g.text))].sort((a, b) => a.start - b.start))}
              onFinished={(file) => {
                setMedia({ url: URL.createObjectURL(file), name: file.name, video: false });
                setBusy(false);
              }}
            />
          ) : busy === 'upload' ? (
            <div className="space-y-3 px-6 py-12" aria-live="polite">
              {progress !== null && progress < 1 ? (
                <><p className="text-center text-sm text-slate-600 dark:text-slate-300">Uploading…</p><Meter value={progress * 100} label="Upload" /></>
              ) : (
                <div className="flex flex-col items-center gap-3 text-slate-500 dark:text-slate-400">
                  <Spinner className="size-7 text-brand-600" />
                  <p className="text-sm">Transcribing on the server — roughly a tenth of the media's length.</p>
                </div>
              )}
            </div>
          ) : (
            <Uploader onPicked={upload} disabled={!!busy || down} maxMb={status.data?.max_mb ?? 500} />
          )}
          <div className="border-t border-slate-200 px-4 py-4 sm:px-6 dark:border-white/10">
            <Select label="Language" value={language} disabled={!!busy} onChange={(e) => setLanguage(e.target.value)}
              options={status.data?.languages ?? [{ value: 'si', label: 'Sinhala' }, { value: 'mix', label: 'Sinhala + English (mixed)' }, { value: 'en', label: 'English' }, { value: 'auto', label: 'Detect automatically' }]}
              hint={language === 'mix'
                ? 'Each phrase is identified as Sinhala or English and written in its own script. English words inside a Sinhala sentence stay in Sinhala letters. Slower than Sinhala only.'
                : 'Sinhala is the most accurate. Choose Sinhala + English when speakers switch between the two.'} />
            {language !== 'mix' && (status.data?.models?.length ?? 0) > 1 && (
              <Select label="Model" wrapperClassName="mt-4" value={model} disabled={!!busy} onChange={(e) => setModel(e.target.value)}
                options={(status.data?.models ?? []).filter((m) => m.installed).map((m) => ({ value: m.value, label: m.label }))}
                hint={status.data?.models?.find((m) => m.value === model)?.note} />
            )}
            {(status.data?.run_on?.length ?? 0) > 1 && (
              <Select label="Run on" wrapperClassName="mt-4" value={runOn} disabled={!!busy} onChange={(e) => setRunOn(e.target.value)}
                options={(status.data?.run_on ?? []).map((t) => ({ value: t.value, label: t.label, disabled: t.ready === false }))}
                hint="A node transcribes faster than this server (a GPU node several times faster). Auto picks the fastest ready node and falls back to this server." />
            )}
          </div>
          {media && (
            <div className="border-t border-slate-200 px-4 py-4 sm:px-6 dark:border-white/10">
              <p className="mb-2 truncate text-xs text-slate-500 dark:text-slate-400" title={media.name}>{media.name}</p>
              <CaptionPreview url={media.url} isVideo={media.video} cues={cues} onTime={setPlayerTime} seekTo={seekTo} />
            </div>
          )}
        </Card>

        <Card className="lg:col-span-3">
          <CardHeader
            title={<span className="inline-flex items-center gap-2">Result {busy === 'live' && <Badge tone="red" dot>Live</Badge>}</span>}
            description={meta ? `${clock(meta.duration)} of media · transcribed in ${meta.seconds.toFixed(1)} s${meta.where ? ` on ${meta.where}` : ''} · ${words} words · ${cues.length} captions`
              : hasResult ? `${words} words · ${cues.length} captions` : undefined}
            actions={hasResult && !busy && (
              <>
                <Button size="sm" icon={ClipboardDocumentIcon} onClick={() => navigator.clipboard.writeText(text).then(() => toast.success('Copied'))}>Copy</Button>
                <Button size="sm" icon={ArrowDownTrayIcon} onClick={() => save(`${base}.txt`, text)}>.txt</Button>
                <Button size="sm" icon={ArrowDownTrayIcon} disabled={!cues.length} onClick={() => save(`${base}.srt`, toSrt(cues), 'application/x-subrip')}>.srt</Button>
                <Button size="sm" icon={ArrowDownTrayIcon} disabled={!cues.length} onClick={() => save(`${base}.vtt`, toVtt(cues), 'text/vtt')}>.vtt</Button>
                <Button size="sm" variant="soft" icon={ChatBubbleLeftRightIcon} disabled={!text} onClick={() => navigate('/chat', { state: { prompt: text } })}>Send to chat</Button>
                <Button size="sm" variant="ghost" icon={TrashIcon} onClick={reset}>Clear</Button>
              </>
            )}
          />
          {!hasResult && !busy ? (
            <EmptyState icon={MicrophoneIcon} title="Nothing transcribed yet" description="Start a live recording and the words appear here as you speak — or upload audio or video for captions and subtitles." />
          ) : (
            <div className="p-4 sm:p-6">
              <Tabs className="mb-4" value={view} onChange={(k) => setView(k as 'transcript' | 'captions')}
                tabs={[{ key: 'transcript', label: 'Transcript' }, { key: 'captions', label: 'Captions & subtitles', count: cues.length }]} />
              {view === 'transcript' ? (
                busy ? (
                  <div className="max-h-[28rem] space-y-1 overflow-y-auto" aria-live="polite">
                    {segments.map((s, i) => (
                      <p key={i} className="flex gap-3 text-sm/6 motion-safe:animate-slide-up">
                        <span className="w-12 shrink-0 font-mono text-xs/6 text-slate-400 tabular-nums">{clock(s.start)}</span>
                        <span className="text-slate-800 dark:text-slate-100">{s.text}</span>
                      </p>
                    ))}
                    <p className="flex gap-3 text-sm/6 text-slate-400">
                      <span className="w-12 shrink-0" />
                      <span className="inline-flex gap-1" aria-label="Listening">
                        {[0, 1, 2].map((d) => <span key={d} className="size-1.5 rounded-full bg-current motion-safe:animate-bounce" style={{ animationDelay: `${d * 150}ms` }} />)}
                      </span>
                    </p>
                    <div ref={liveEnd} />
                  </div>
                ) : (
                  <Textarea aria-label="Transcript" rows={Math.min(16, Math.max(6, Math.ceil(text.length / 70)))} value={text}
                    onChange={(e) => { setText(e.target.value); setTextEdited(true); }} className="text-base/7"
                    hint={textEdited ? 'Edited — captions keep the original timed words; edit them in Captions.' : undefined} />
                )
              ) : (
                <CaptionEditor cues={cues} onChange={setCues} rules={rules} onRules={setRules}
                  onRegenerate={() => setCues(buildCues(segments, rules))}
                  playerTime={playerTime} onSeek={(t: number) => setSeekTo(t)} />
              )}
            </div>
          )}
        </Card>
      </div>
    </>
  );
}
