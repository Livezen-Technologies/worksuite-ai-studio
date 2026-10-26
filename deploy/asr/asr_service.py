"""AI Studio speech-to-text service: Whisper Sinhala (faster-whisper, CPU int8), kept loaded in memory.

Internal only (127.0.0.1). AI Studio's PHP API forwards uploads here.
  POST /transcribe?language=si|en|mix|auto&model=sinhala|tiny|base|small|medium|turbo&words=1&fast=1   body = the audio or video file's bytes
       model: Whisper Sinhala (default) or a multilingual Whisper size, loaded on first use (mix always uses Sinhala + Small).
       mix = Sinhala and English together: speech is cut at pauses, each phrase's language is identified by the
             multilingual model, Sinhala phrases go to Whisper Sinhala and English phrases to Whisper small.
       words=1  adds per-word timings (for caption splitting); fast=1 uses greedy decoding (live chunks).
       numerals=0 keeps spoken numbers as words (default: digits, "දහයක්" → "10ක්", see numfix.py).
       Silence is skipped (VAD) and punctuation-only lines are dropped in both modes.
  GET  /health
"""
import json, os, subprocess, tempfile, threading, time
import numpy as np
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse
from faster_whisper import WhisperModel
from faster_whisper.vad import VadOptions, get_speech_timestamps
from numfix import normalise as numerals  # spoken Sinhala numbers → digits

# Device: the AI Studio server runs on CPU (int8); a GPU node's agent sets ASR_DEVICE=cuda (int8 weights, fp16 compute).
DEVICE = os.environ.get('ASR_DEVICE', 'cpu')
COMPUTE = os.environ.get('ASR_COMPUTE', 'int8_float16' if DEVICE == 'cuda' else 'int8')
FFMPEG = os.environ.get('ASR_FFMPEG', 'ffmpeg')
MODEL_DIR = os.environ.get('ASR_MODEL', '/srv/sinhala-data/models/whisper-small-sinhala-ct2-int8')
MODEL_NAME = 'Whisper Sinhala (SinhaSpeech whisper-small run11, int8)'
MAX_BYTES = 500 * 1024 * 1024  # video uploads for captions
THREADS = int(os.environ.get('ASR_THREADS', '4'))
# Transcriptions that may run at once: a GPU handles several streams; on CPU two jobs of 4 threads fill 8 cores.
# Each model gets as many CTranslate2 workers, so two people on the same model run side by side instead of queueing.
PARALLEL = int(os.environ.get('ASR_PARALLEL', '3' if DEVICE == 'cuda' else '2'))
model = WhisperModel(MODEL_DIR, device=DEVICE, compute_type=COMPUTE, cpu_threads=THREADS, num_workers=PARALLEL)
# Multilingual Whisper small (OpenAI, MIT) — language identification and English phrases in mixed speech.
MULTI_DIR = os.environ.get('ASR_MULTI_MODEL', '/srv/sinhala-data/models/faster-whisper-small')
multi = WhisperModel(MULTI_DIR, device=DEVICE, compute_type=COMPUTE, cpu_threads=THREADS, num_workers=PARALLEL) if os.path.isdir(MULTI_DIR) else None
# A smaller multilingual model only tells Sinhala from English per phrase — the same answer for a quarter of the work.
LID_DIR = os.environ.get('ASR_LID_MODEL', '/srv/sinhala-data/models/faster-whisper-base')
lid = WhisperModel(LID_DIR, device=DEVICE, compute_type=COMPUTE, cpu_threads=THREADS, num_workers=PARALLEL) if os.path.isdir(LID_DIR) else multi
RATE = 16000

# Other Whisper sizes (multilingual, OpenAI MIT, CTranslate2 int8), loaded on first use. One of them stays in memory at a
# time besides the always-loaded Sinhala/LID models, so the large ones never sit in RAM together.
MODELS_DIR = os.environ.get('ASR_MODELS_DIR', '/srv/sinhala-data/models')
CATALOG = {
    'sinhala': {'label': 'Whisper Small · Sinhala', 'dir': MODEL_DIR, 'note': 'Fine-tuned for Sinhala (SinhaSpeech run11). Default.'},
    'sinhala_numbers': {'label': 'Whisper Small · Sinhala — numbers (new)', 'dir': 'whisper-small-sinhala-numbers-ct2-int8',
                        'note': 'Run11 fine-tuned on number-heavy speech (training job #5, 2 × T4). Being benchmarked; not the default yet.'},
    'tiny':    {'label': 'Whisper Tiny', 'dir': 'faster-whisper-tiny', 'note': 'Fastest, lower accuracy.'},
    'base':    {'label': 'Whisper Base', 'dir': 'faster-whisper-base', 'note': 'Fast; also used to detect the language in mixed speech.'},
    'small':   {'label': 'Whisper Small', 'dir': 'faster-whisper-small', 'note': 'Good balance of speed and accuracy.'},
    'medium':  {'label': 'Whisper Medium', 'dir': 'faster-whisper-medium', 'note': 'Higher accuracy, slower.'},
    'turbo':   {'label': 'Whisper Large v3 Turbo', 'dir': 'faster-whisper-large-v3-turbo', 'note': 'Best multilingual accuracy; slow on this CPU, best for uploads.'},
}
# Other models stay loaded once used (least recently used is dropped beyond ASR_KEEP), and ASR_PRELOAD ones are loaded
# at start, so nobody waits seconds for a model to load from disk on their first piece.
KEEP = int(os.environ.get('ASR_KEEP', '3'))
_extra = {}  # name -> WhisperModel, in use order
_load_lock = threading.Lock()


def _dir(name):
    d = CATALOG[name]['dir']
    return d if os.path.isabs(d) else os.path.join(MODELS_DIR, d)


def get_model(name):
    if name == 'sinhala':
        return model
    if name == 'small' and multi is not None:
        return multi
    if name == 'base' and lid is not None and lid is not multi:
        return lid
    with _load_lock:
        if name in _extra:
            _extra[name] = _extra.pop(name)                     # most recently used last
        else:
            while len(_extra) >= KEEP:
                _extra.pop(next(iter(_extra)))                  # drop the least recently used
            _extra[name] = WhisperModel(_dir(name), device=DEVICE, compute_type=COMPUTE, cpu_threads=THREADS, num_workers=PARALLEL)
        return _extra[name]


def _preload():
    for name in filter(None, os.environ.get('ASR_PRELOAD', 'sinhala_numbers').split(',')):
        try:
            if name in CATALOG and os.path.exists(os.path.join(_dir(name), 'model.bin')):
                get_model(name); print(f'preloaded {name}', flush=True)
        except Exception as e:
            print(f'preload {name} failed: {e}', flush=True)


def catalog():
    return [{'value': k, 'label': v['label'], 'note': v['note'], 'installed': k == 'sinhala' or os.path.exists(os.path.join(_dir(k), 'model.bin'))}
            for k, v in CATALOG.items()]


def _rows(segs, words, offset=0.0, lang=None):
    out = []
    for sg in segs:
        if not any(ch.isalnum() for ch in sg.text):  # punctuation-only output from noise
            continue
        row = {'start': round(sg.start + offset, 2), 'end': round(sg.end + offset, 2), 'text': sg.text.strip()}
        if lang:
            row['language'] = lang
        if words and sg.words:
            row['words'] = [{'start': round(w.start + offset, 2), 'end': round(w.end + offset, 2), 'word': w.word} for w in sg.words]
        out.append(row)
    return out


def transcribe_mixed(pcm, words, fast):
    """Phrase-level code switching: cut at pauses, identify each phrase's language, transcribe with the right model."""
    spans = get_speech_timestamps(pcm, VadOptions(min_silence_duration_ms=400, max_speech_duration_s=15, speech_pad_ms=150))
    # 1. language of each phrase (one encoder pass per phrase)
    tagged = []
    for sp in spans:
        if sp['end'] - sp['start'] < RATE * 0.3:
            continue
        _, _, probs = lid.detect_language(pcm[sp['start']:sp['end']])
        p = dict(probs)
        tagged.append((sp['start'], sp['end'], 'en' if p.get('en', 0) > p.get('si', 0) else 'si'))
    # 2. neighbouring phrases in the same language share one pass (Whisper pads every input to 30 s anyway)
    groups = []
    for a, b, lang in tagged:
        if groups and groups[-1][2] == lang and b - groups[-1][0] <= RATE * 28:
            groups[-1][1] = b
        else:
            groups.append([a, b, lang])
    rows, counts = [], {'si': 0.0, 'en': 0.0}
    for a, b, lang in groups:
        m = multi if lang == 'en' else model
        segs, _ = m.transcribe(pcm[a:b], language=lang, beam_size=1 if fast else 3, vad_filter=False, word_timestamps=words,
                               condition_on_previous_text=False)
        rows += [r for r in _rows(segs, words, a / RATE, lang) if r['end'] - r['start'] >= 0.35]  # drop sub-word tails
        counts[lang] += (b - a) / RATE
    main = max(counts, key=counts.get) if any(counts.values()) else 'si'
    return rows, main, round(sum(counts.values()) and counts[main] / sum(counts.values()), 3)
slots = threading.BoundedSemaphore(PARALLEL)  # at most PARALLEL transcriptions at once
_active = [0]
_active_lock = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    def _send(self, code, body):
        data = json.dumps(body, ensure_ascii=False).encode()
        try:
            self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(data)))
            self.end_headers(); self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError):  # the browser gave up waiting; nothing to deliver
            pass

    def log_message(self, *a):  # quiet
        pass

    def do_GET(self):
        if urlparse(self.path).path == '/health':
            return self._send(200, {'ok': True, 'model': MODEL_NAME, 'busy': _active[0] >= PARALLEL, 'active': _active[0], 'parallel': PARALLEL, 'mixed': multi is not None, 'models': catalog(), 'device': DEVICE})
        self._send(404, {'error': 'not found'})

    def do_POST(self):
        u = urlparse(self.path)
        if u.path != '/transcribe':
            return self._send(404, {'error': 'not found'})
        n = int(self.headers.get('Content-Length') or 0)
        if n <= 0 or n > MAX_BYTES:
            return self._send(413 if n > MAX_BYTES else 400, {'error': 'Send an audio or video file up to 500 MB.'})
        # Spool to a temp file: MP4/MOV keep their index at the end, so ffmpeg cannot read them from a pipe.
        tmp = tempfile.NamedTemporaryFile(prefix='asr-', delete=False)
        try:
            left = n
            while left > 0:
                chunk = self.rfile.read(min(left, 1 << 20))
                if not chunk:
                    break
                tmp.write(chunk); left -= len(chunk)
            tmp.close()
            return self._transcribe(u, tmp.name)
        finally:
            try: os.unlink(tmp.name)
            except OSError: pass

    def _transcribe(self, u, path):
        q = parse_qs(u.query)
        lang = (q.get('language', ['si'])[0] or 'si').lower()
        words = q.get('words', ['0'])[0] == '1'
        fast = q.get('fast', ['0'])[0] == '1'
        name = (q.get('model', ['sinhala'])[0] or 'sinhala').lower()
        if name not in CATALOG:
            return self._send(422, {'error': f'Unknown model {name}. Choose one of: ' + ', '.join(CATALOG)})
        if not next(m for m in catalog() if m['value'] == name)['installed']:
            return self._send(503, {'error': f"{CATALOG[name]['label']} is not installed on this server yet."})
        t0 = time.time()
        # ffmpeg decodes anything a browser or phone produces (WebM/Opus, M4A, MP3, OGG, WAV, FLAC) to 16 kHz mono.
        dec = subprocess.run([FFMPEG, '-nostdin', '-loglevel', 'error', '-i', path, '-vn', '-f', 'f32le', '-ac', '1', '-ar', '16000', 'pipe:1'],
                             capture_output=True)
        pcm = np.frombuffer(dec.stdout, dtype=np.float32)
        if dec.returncode != 0 or pcm.size < 1600:
            return self._send(422, {'error': 'Could not read audio from this file. Try WAV, MP3, M4A, OGG, WebM, FLAC, MP4 or MOV, at least a second long.'})
        peak = float(np.abs(pcm).max()) if pcm.size else 0.0
        if peak < 0.005:  # silence (or a muted / blocked microphone): nothing to transcribe, no model work
            print(f"transcribe model={name} lang={lang} fast={int(fast)} audio={pcm.size / RATE:.1f}s peak={peak:.3f} silent", flush=True)
            return self._send(200, {'text': '', 'segments': [], 'language': lang, 'main_language': lang, 'language_probability': 0,
                                    'duration': round(pcm.size / RATE, 2), 'processing_seconds': round(time.time() - t0, 2), 'model': MODEL_NAME, 'silent': True})
        if lang == 'mix' and multi is None:
            return self._send(503, {'error': 'Mixed Sinhala + English needs the multilingual model, which is not installed on this server.'})
        try:
            t_wait = time.time()
            with slots:
                with _active_lock: _active[0] += 1
                waited = time.time() - t_wait
                t_run = time.time()
                if lang == 'mix':
                    segments, detected, prob = transcribe_mixed(pcm, words, fast)
                    duration = pcm.size / RATE
                else:
                    m = get_model(name)
                    segs, info = m.transcribe(pcm, language=None if lang == 'auto' else lang, beam_size=1 if fast else 5,
                                                  vad_filter=True, word_timestamps=words)  # VAD also for live chunks: silence otherwise yields '.' lines
                    segments = _rows(segs, words)
                    detected, prob, duration = info.language, info.language_probability, info.duration
                with _active_lock: _active[0] -= 1
        except Exception as e:  # undecodable or empty audio
            with _active_lock: _active[0] = max(0, _active[0] - 1)
            return self._send(422, {'error': f'Could not read this audio: {type(e).__name__}. Try WAV, MP3, M4A, OGG, WebM or FLAC.'})
        if q.get('numerals', ['1'])[0] == '1':  # on by default; numerals=0 keeps numbers as spoken words
            for sg in segments:
                sg['text'] = numerals(sg['text'])
        text = ' '.join(s['text'] for s in segments).strip()
        print(f"transcribe model={name} lang={lang} fast={int(fast)} audio={pcm.size / RATE:.1f}s peak={peak:.3f} queued={waited:.1f}s ran={time.time() - t_run:.1f}s chars={len(text)}", flush=True)
        self._send(200, {
            'text': text, 'segments': segments,
            'language': 'mix' if lang == 'mix' else detected, 'main_language': detected, 'language_probability': round(prob, 3),
            'duration': round(duration, 2), 'processing_seconds': round(time.time() - t0, 2),
            'model': MODEL_NAME if name == 'sinhala' or lang == 'mix' else CATALOG[name]['label'],
        })


if __name__ == '__main__':
    threading.Thread(target=_preload, daemon=True).start()
    ThreadingHTTPServer(('127.0.0.1', int(os.environ.get('ASR_PORT', '8096'))), Handler).serve_forever()
