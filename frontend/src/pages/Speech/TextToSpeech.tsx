import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowDownTrayIcon, EyeIcon, PlayIcon, SpeakerWaveIcon, StopIcon } from '@heroicons/react/24/outline';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, Select, Textarea, useToast } from '../../components/ui';
import { ApiError, authFetch, get, post } from '../../lib/api';

interface Voice {
  value: string; label: string; language: string | null; runtime: string; license: string | null; consent_ref: string | null;
  sample_rate: number | null; speed: [number, number]; quality: string; default?: boolean;
}
interface Voices { available: boolean; voices: Voice[]; formats: string[]; max_chars: number }
interface Preview { normalized: string; notes: string[]; normalizer: string }

const LANG: Record<string, string> = { si: 'Sinhala', en: 'English' };

/**
 * Text to speech with the studio's approved voices: preview how the text will be read (numbers, dates and
 * currency written out), play it, stop it, and download WAV or MP3. Only approved voices are offered.
 */
export default function TextToSpeech() {
  const toast = useToast();
  const q = useQuery({ queryKey: ['speech', 'voices'], queryFn: () => get<Voices>('/speech/voices') });
  const voices = q.data?.voices ?? [];
  const [voice, setVoice] = useState('');
  const v = voices.find((x) => x.value === voice) ?? voices[0];
  const [text, setText] = useState('');
  const [speed, setSpeed] = useState(1);
  const [format, setFormat] = useState<'wav' | 'mp3'>('wav');
  const [preview, setPreview] = useState<Preview | null>(null);
  const [audio, setAudio] = useState<{ url: string; duration: number; format: string } | null>(null);
  const player = useRef<HTMLAudioElement>(null);
  const abort = useRef<AbortController | null>(null);

  useEffect(() => () => { if (audio) URL.revokeObjectURL(audio.url); }, [audio]);
  // Speed stays inside what the chosen voice was validated for.
  const [lo, hi] = v?.speed ?? [1, 1];
  const clamped = Math.min(Math.max(speed, lo), hi);

  const previewIt = useMutation({
    mutationFn: () => post<Preview>('/speech/tts/preview', { text, voice: v?.value }),
    onSuccess: setPreview,
    onError: (e) => toast.error(e),
  });
  const speak = useMutation({
    mutationFn: async () => {
      abort.current?.abort();
      const ctl = new AbortController();
      abort.current = ctl;
      const res = await authFetch('/api/speech/tts', {
        method: 'POST', signal: ctl.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'audio/*' },
        body: JSON.stringify({ text, voice: v?.value, speed: clamped, format }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new ApiError(body?.message ?? 'Speech could not be made.', res.status, body);
      }
      const blob = await res.blob();
      return { url: URL.createObjectURL(blob), duration: Number(res.headers.get('X-Duration') ?? 0), format, normalized: decodeURIComponent(res.headers.get('X-Normalized-Text') ?? '') };
    },
    onSuccess: (r) => {
      setAudio((old) => { if (old) URL.revokeObjectURL(old.url); return r; });
      setTimeout(() => void player.current?.play().catch(() => {}), 0);
    },
    onError: (e) => { if ((e as Error).name !== 'AbortError') toast.error(e); },
  });
  const stop = () => { abort.current?.abort(); player.current?.pause(); if (player.current) player.current.currentTime = 0; };

  if (q.isLoading) return null;
  if (q.data && !q.data.available) {
    return <Alert tone="danger" title="The text-to-speech service is offline">Ask an administrator to start the aistudio-tts service on the server.</Alert>;
  }
  if (!voices.length) {
    return (
      <Card><EmptyState icon={SpeakerWaveIcon} title="No voice is available to you"
        description="Text to speech needs an approved voice. An administrator can install espeak-ng for the built-in voices, add an approved voice to the voice catalog, or give your role access in Models → Registry." /></Card>
    );
  }

  const max = q.data?.max_chars ?? 5000;
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
      <Card className="lg:col-span-2">
        <CardHeader title="Voice" />
        <div className="space-y-4 px-4 pb-5 sm:px-6">
          <Select label="Voice" value={v?.value ?? ''} onChange={(e) => { setVoice(e.target.value); setPreview(null); }}
            options={voices.map((x) => ({ value: x.value, label: `${x.label}${x.default ? ' (default)' : ''}` }))} />
          {v && (
            <dl className="grid grid-cols-2 gap-2 text-xs">
              <div><dt className="text-slate-500 dark:text-slate-400">Language</dt><dd className="font-medium text-slate-900 dark:text-white">{LANG[v.language ?? ''] ?? v.language ?? '—'}</dd></div>
              <div><dt className="text-slate-500 dark:text-slate-400">Sample rate</dt><dd className="font-medium text-slate-900 dark:text-white">{v.sample_rate ? `${(v.sample_rate / 1000).toFixed(1)} kHz` : '—'}</dd></div>
              <div className="col-span-2"><dt className="text-slate-500 dark:text-slate-400">Licence</dt><dd className="text-slate-700 dark:text-slate-200">{v.license ?? '—'}</dd></div>
              <div className="col-span-2"><dt className="text-slate-500 dark:text-slate-400">Speaker consent</dt><dd className="text-slate-700 dark:text-slate-200">{v.consent_ref ?? '—'}</dd></div>
              {v.quality && <div className="col-span-2 text-slate-500 dark:text-slate-400">{v.quality}</div>}
            </dl>
          )}
          {hi > lo ? (
            <div>
              <label htmlFor="tts-speed" className="flex justify-between text-sm font-medium text-slate-900 dark:text-white">
                <span>Speed</span><span className="tabular-nums text-slate-500 dark:text-slate-400">{clamped.toFixed(2)}×</span>
              </label>
              <input id="tts-speed" type="range" min={lo} max={hi} step={0.05} value={clamped} onChange={(e) => setSpeed(Number(e.target.value))} className="mt-2 w-full accent-brand-600" />
              <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">This voice is validated from {lo}× to {hi}×.</p>
            </div>
          ) : <p className="text-xs text-slate-500 dark:text-slate-400">This voice speaks at one speed.</p>}
          <Select label="Download as" value={format} onChange={(e) => setFormat(e.target.value as 'wav' | 'mp3')}
            options={[{ value: 'wav', label: 'WAV (lossless)' }, { value: 'mp3', label: 'MP3 (smaller)' }]} />
        </div>
      </Card>

      <Card className="lg:col-span-3">
        <CardHeader title="Text" description="Numbers, dates, times and amounts in rupees are read out in words. Preview to check how." />
        <div className="space-y-4 px-4 pb-5 sm:px-6">
          <Textarea id="tts-text" aria-label="Text to speak" rows={7} value={text} maxLength={max} onChange={(e) => { setText(e.target.value); setPreview(null); }}
            placeholder="ආයුබෝවන්. අද රු. 1,250 ක් ගෙවිය යුතුයි." hint={`${text.length.toLocaleString()} / ${max.toLocaleString()} characters`} />
          <div className="flex flex-wrap gap-2">
            <Button variant="primary" icon={PlayIcon} loading={speak.isPending} disabled={!text.trim()} onClick={() => speak.mutate()}>Speak</Button>
            <Button icon={StopIcon} disabled={!speak.isPending && !audio} onClick={stop}>Stop</Button>
            <Button variant="ghost" icon={EyeIcon} loading={previewIt.isPending} disabled={!text.trim()} onClick={() => previewIt.mutate()}>Preview reading</Button>
          </div>
          {preview && (
            <div className="rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-slate-200 dark:bg-white/5 dark:ring-white/10">
              <div className="mb-1 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400"><span>Read as</span><Badge tone="gray">{preview.normalizer}</Badge></div>
              <p className="text-slate-900 dark:text-slate-100">{preview.normalized}</p>
              {preview.notes.map((n) => <p key={n} className="mt-1 text-xs text-amber-700 dark:text-amber-400">{n}</p>)}
            </div>
          )}
          {audio && (
            <div className="space-y-2">
              <audio ref={player} controls src={audio.url} className="w-full" />
              <div className="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                <span>{audio.duration ? `${audio.duration.toFixed(1)} s` : ''}</span>
                <a href={audio.url} download={`speech.${audio.format}`} className="inline-flex items-center gap-1 font-semibold text-brand-600 hover:underline dark:text-blue-400">
                  <ArrowDownTrayIcon aria-hidden className="size-4" />Download {audio.format.toUpperCase()}
                </a>
              </div>
            </div>
          )}
        </div>
      </Card>
    </div>
  );
}
