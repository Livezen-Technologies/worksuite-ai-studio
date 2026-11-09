#!/usr/bin/env python3
"""WorkSuite AI Studio text-to-speech service (aistudio-tts, 127.0.0.1:8098).

  GET  /health                       {ok, voices:[...], normalizer}
  POST /normalize  {text, voice}     {text, normalized, notes[]}   preview what will be read (SRS FR TTS 002)
  POST /synthesize {text, voice, speed?, format?}  audio bytes; headers X-Duration, X-Normalized-Chars, X-Voice

Only approved voices speak (SRS FR TTS 001 / 005). Each voice declares language, licence, speaker consent
reference, sample rate, runtime and the speed range it was validated for. A voice marked revoked is refused for
new audio. There is no voice-cloning endpoint.

Voices come from two places:
  - built in: espeak-ng formant voices for Sinhala and English (synthetic, no recorded speaker; always available
    when espeak-ng is installed);
  - the voice catalog, TTS_VOICES (default /srv/aistudio-models/tts/voices.json), re-read when it changes:
      {"my-voice": {"label": "...", "language": "si", "runtime": "vits", "path": "/srv/.../dir",
                    "license": "...", "consent_ref": "...", "sample_rate": 22050, "speed": [0.8, 1.2],
                    "approved": true, "revoked": false, "quality": "MOS 3.9 on 200 sentences"}}
    runtime "vits" loads a Hugging Face VITS checkpoint (transformers + torch, e.g. a model trained on licensed
    recordings); runtime "piper" runs a Piper .onnx voice with the piper binary.

Output is WAV; mp3, opus, aac and flac are encoded with ffmpeg. Standard library only for the HTTP side, like the
speech-to-text service.
"""
import io
import json
import os
import re
import shutil
import subprocess
import tempfile
import threading
import time
import wave
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HOST = os.environ.get('TTS_HOST', '127.0.0.1')
PORT = int(os.environ.get('TTS_PORT', '8098'))
CATALOG = os.environ.get('TTS_VOICES', '/srv/aistudio-models/tts/voices.json')
LEXICON = os.environ.get('TTS_LEXICON', '/srv/aistudio-models/tts/lexicon.json')
MAX_CHARS = int(os.environ.get('TTS_MAX_CHARS', '5000'))
ESPEAK = shutil.which('espeak-ng') or shutil.which('espeak')
FFMPEG = shutil.which('ffmpeg')
PIPER = shutil.which('piper')
NORMALIZER_VERSION = 'norm-1'

FORMATS = {  # ffmpeg arguments per output format
    'wav': None,
    'mp3': ['-f', 'mp3', '-codec:a', 'libmp3lame', '-q:a', '4'],
    'opus': ['-f', 'ogg', '-codec:a', 'libopus', '-b:a', '48k'],
    'aac': ['-f', 'adts', '-codec:a', 'aac', '-b:a', '96k'],
    'flac': ['-f', 'flac'],
    'pcm': ['-f', 's16le', '-acodec', 'pcm_s16le', '-ac', '1', '-ar', '24000'],
}
MIME = {'wav': 'audio/wav', 'mp3': 'audio/mpeg', 'opus': 'audio/ogg', 'aac': 'audio/aac', 'flac': 'audio/flac', 'pcm': 'audio/pcm'}

BUILT_IN = {
    'espeak-si': {'label': 'Sinhala (espeak-ng, synthetic)', 'language': 'si', 'runtime': 'espeak', 'espeak_voice': 'si',
                  'license': 'GPL-3.0 engine; output unrestricted', 'consent_ref': 'synthetic voice, no recorded speaker',
                  'sample_rate': 22050, 'speed': [0.5, 2.0], 'approved': True, 'revoked': False,
                  'quality': 'Robotic but clear; always available. Train a VITS voice for natural Sinhala.'},
    'espeak-en': {'label': 'English (espeak-ng, synthetic)', 'language': 'en', 'runtime': 'espeak', 'espeak_voice': 'en-us',
                  'license': 'GPL-3.0 engine; output unrestricted', 'consent_ref': 'synthetic voice, no recorded speaker',
                  'sample_rate': 22050, 'speed': [0.5, 2.0], 'approved': True, 'revoked': False,
                  'quality': 'Robotic but clear; always available.'},
}

_voices = {}
_mtime = [0.0]
_lex = {}
_lex_mtime = [0.0]
_models = {}           # voice key -> loaded VITS (model, tokenizer)
_load_lock = threading.Lock()
_slots = threading.Semaphore(int(os.environ.get('TTS_PARALLEL', '2')))


def _refresh():
    """Built-ins plus the catalog file (re-read when it changes). A catalog entry never shadows a built-in."""
    try:
        m = os.path.getmtime(CATALOG)
    except OSError:
        m = 0.0
    if m == _mtime[0] and _voices:
        return
    _mtime[0] = m
    voices = {k: dict(v) for k, v in BUILT_IN.items()} if ESPEAK else {}
    if m:
        try:
            extra = json.load(open(CATALOG, encoding='utf-8'))
            for k, v in extra.items():
                if k in BUILT_IN or not re.fullmatch(r'[a-z0-9_-]{1,40}', k):
                    continue
                voices[k] = v
                _models.pop(k, None)  # a replaced voice is reloaded
        except Exception as e:  # a broken catalog must not take the built-ins down
            print(f'voice catalog unreadable: {e}', flush=True)
    _voices.clear()
    _voices.update(voices)


def _installed(key, v):
    rt = v.get('runtime')
    if rt == 'espeak':
        return bool(ESPEAK)
    if rt == 'piper':
        return bool(PIPER) and os.path.exists(v.get('path', ''))
    if rt == 'vits':
        return os.path.isdir(v.get('path', '')) or bool(re.fullmatch(r'[\w.-]+/[\w.-]+', v.get('path', '')))
    return False


def voices():
    _refresh()
    out = []
    for k, v in _voices.items():
        out.append({
            'value': k, 'label': v.get('label', k), 'language': v.get('language'), 'runtime': v.get('runtime'),
            'license': v.get('license'), 'consent_ref': v.get('consent_ref'), 'sample_rate': v.get('sample_rate'),
            'speed': v.get('speed', [1.0, 1.0]), 'quality': v.get('quality', ''),
            'approved': bool(v.get('approved')), 'revoked': bool(v.get('revoked')), 'installed': _installed(k, v),
        })
    return out


# ---- Normalization (versioned front end) -------------------------------------------------------

SI_ONES = ['', 'එක', 'දෙක', 'තුන', 'හතර', 'පහ', 'හය', 'හත', 'අට', 'නවය']
SI_TEENS = ['දහය', 'එකොළහ', 'දොළහ', 'දහතුන', 'දාහතර', 'පහළොව', 'දාසය', 'දාහත', 'දහඅට', 'දහනවය']
SI_TENS_ALONE = ['', 'දහය', 'විස්ස', 'තිහ', 'හතළිහ', 'පනහ', 'හැට', 'හැත්තෑව', 'අසූව', 'අනූව']
# combining forms: a count followed by a multiplier (දෙදහස්, පනස් ලක්ෂ) or a tens word followed by ones (විසිඑක)
SI_ONES_C = ['', 'එක්', 'දෙ', 'තුන්', 'හාර', 'පන්', 'හය', 'හත්', 'අට', 'නව']
SI_TEENS_C = ['දස', 'එකොළොස්', 'දොළොස්', 'දහතුන්', 'දාහතර', 'පහළොස්', 'දාසය', 'දාහත්', 'දහඅට', 'දහනව']
SI_TENS_C = ['', 'දස', 'විසි', 'තිස්', 'හතළිස්', 'පනස්', 'හැට', 'හැත්තෑ', 'අසූ', 'අනූ']
# Sri Lankan grouping: කෝටි (10^7), ලක්ෂ (10^5), දහස් (10^3), සිය (10^2); [combining, final]
SI_SCALES = [(10 ** 7, 'කෝටි', 'කෝටිය'), (10 ** 5, 'ලක්ෂ', 'ලක්ෂය'), (1000, 'දහස්', 'දහස'), (100, 'සිය', 'සියය')]


def _si_small(n, combining):
    """1-99, standalone (පනහ) or combining (පනස්)."""
    if n < 10:
        return (SI_ONES_C if combining else SI_ONES)[n]
    if n < 20:
        return (SI_TEENS_C if combining else SI_TEENS)[n - 10]
    t, o = divmod(n, 10)
    if o == 0:
        return (SI_TENS_C if combining else SI_TENS_ALONE)[t]
    return SI_TENS_C[t] + (SI_ONES_C if combining else SI_ONES)[o]


def _si_count(n):
    """A count before a multiplier, in combining form (up to 99 සිය / 99 දහස් / 99 ලක්ෂ; larger via කෝටි)."""
    if n < 100:
        return _si_small(n, True)
    return si_number(n) + ' '  # rare (e.g. 150 කෝටි): read the count, then the multiplier


def si_number(n):
    """Integer → Sinhala words, Sri Lankan grouping (lakh, crore). 2026 → දෙදහස් විසිහය."""
    if n == 0:
        return 'බිංදුව'
    if n < 0:
        return 'ඍණ ' + si_number(-n)
    parts = []
    for size, comb, final in SI_SCALES:
        if n >= size:
            q, n = divmod(n, size)
            count = '' if (q == 1 and size in (100, 1000)) else _si_count(q)
            parts.append(count + (comb if n else final))
    if n:
        parts.append(_si_small(n, False))
    return ' '.join(p.strip() for p in parts if p.strip())


def _lexicon():
    try:
        m = os.path.getmtime(LEXICON)
    except OSError:
        return {}
    if m != _lex_mtime[0]:
        _lex_mtime[0] = m
        try:
            _lex.clear()
            _lex.update(json.load(open(LEXICON, encoding='utf-8')))
        except Exception as e:
            print(f'lexicon unreadable: {e}', flush=True)
    return _lex


def normalize(text, language):
    """Expand what a voice cannot read as written. Returns (text, notes)."""
    notes = []
    out = text
    for word, say in _lexicon().items():  # the local pronunciation lexicon wins over every rule
        out = re.sub(r'(?<!\w)' + re.escape(word) + r'(?!\w)', say, out)
    if language == 'si':
        out = re.sub(r'(?:Rs\.?|LKR|රු\.?)\s*([\d,]+(?:\.\d+)?)', lambda m: m.group(1) + ' රුපියල්', out)
        out = re.sub(r'%', ' ප්‍රතිශතය', out)

        def date(m):
            y, mo, d = int(m.group(1)), int(m.group(2)), int(m.group(3))
            notes.append(f'{m.group(0)} read as a date')
            return f'{si_number(y)} {si_number(mo)} වන මාසයේ {si_number(d)}'
        out = re.sub(r'\b(\d{4})-(\d{2})-(\d{2})\b', date, out)

        def time_(m):
            return f'{si_number(int(m.group(1)))} යි {si_number(int(m.group(2)))}' if int(m.group(2)) else f'{si_number(int(m.group(1)))} ට'
        out = re.sub(r'\b(\d{1,2}):(\d{2})\b', time_, out)

        def num(m):
            raw = m.group(0)
            whole, _, frac = raw.replace(',', '').partition('.')
            if len(whole) > 9:
                notes.append(f'{raw} is long: read digit by digit')
                return ' '.join(SI_ONES[int(c)] or 'බිංදුව' for c in whole)
            words = si_number(int(whole))
            if frac:
                words += ' දශම ' + ' '.join(SI_ONES[int(c)] or 'බිංදුව' for c in frac)
            return words
        out = re.sub(r'\d[\d,]*(?:\.\d+)?', num, out)
        if re.search(r'[A-Za-z]{2,}', out):
            notes.append('English words are read by the Sinhala voice and may sound wrong; use an English voice for English text')
    else:
        out = re.sub(r'(?:Rs\.?|LKR)\s*([\d,]+(?:\.\d+)?)', lambda m: m.group(1) + ' rupees', out)
    return re.sub(r'\s+', ' ', out).strip(), notes


# ---- Synthesis ---------------------------------------------------------------------------------

def _espeak(v, text, speed):
    rate = int(175 * speed)  # espeak-ng words per minute; 175 is its default
    with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as f:
        path = f.name
    try:
        r = subprocess.run([ESPEAK, '-v', v.get('espeak_voice', 'en'), '-s', str(rate), '-w', path, '--stdin'],
                           input=text.encode('utf-8'), capture_output=True, timeout=120)
        if r.returncode != 0:
            raise RuntimeError(r.stderr.decode('utf-8', 'replace')[:200] or 'espeak-ng failed')
        return open(path, 'rb').read()
    finally:
        os.unlink(path)


def _piper(v, text, speed):
    with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as f:
        path = f.name
    try:
        r = subprocess.run([PIPER, '--model', v['path'], '--output_file', path, '--length_scale', f'{1 / speed:.3f}'],
                           input=text.encode('utf-8'), capture_output=True, timeout=300)
        if r.returncode != 0:
            raise RuntimeError(r.stderr.decode('utf-8', 'replace')[:200] or 'piper failed')
        return open(path, 'rb').read()
    finally:
        os.unlink(path)


def _vits(key, v, text, speed):
    import numpy as np   # loaded only when a VITS voice is used
    import torch
    from transformers import AutoTokenizer, VitsModel
    with _load_lock:
        if key not in _models:
            _models[key] = (VitsModel.from_pretrained(v['path']), AutoTokenizer.from_pretrained(v['path']))
    model, tok = _models[key]
    model.speaking_rate = speed
    with torch.no_grad():
        audio = model(**tok(text, return_tensors='pt')).waveform[0].numpy()
    pcm = (np.clip(audio, -1, 1) * 32767).astype('<i2').tobytes()
    buf = io.BytesIO()
    with wave.open(buf, 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(int(model.config.sampling_rate)); w.writeframes(pcm)
    return buf.getvalue()


def _wav_duration(data):
    with wave.open(io.BytesIO(data)) as w:
        return w.getnframes() / float(w.getframerate())


def _encode(wav, fmt):
    if fmt == 'wav':
        return wav
    if not FFMPEG:
        raise RuntimeError(f'{fmt} needs ffmpeg on this server; ask for wav')
    r = subprocess.run([FFMPEG, '-nostdin', '-loglevel', 'error', '-f', 'wav', '-i', 'pipe:0', *FORMATS[fmt], 'pipe:1'],
                       input=wav, capture_output=True, timeout=120)
    if r.returncode != 0:
        raise RuntimeError('encoding failed: ' + r.stderr.decode('utf-8', 'replace')[:200])
    return r.stdout


def synthesize(key, text, speed=1.0, fmt='wav'):
    _refresh()
    v = _voices.get(key)
    if not v:
        raise LookupError(f'Unknown voice {key}. Choose one of: ' + ', '.join(_voices))
    if v.get('revoked'):
        raise PermissionError(f"The voice {v.get('label', key)} has been withdrawn and cannot make new audio.")
    if not v.get('approved'):
        raise PermissionError(f"The voice {v.get('label', key)} is not approved for use (licence or consent not verified).")
    if not _installed(key, v):
        raise LookupError(f"The voice {v.get('label', key)} is not installed on this server.")
    lo, hi = (v.get('speed') or [1.0, 1.0])[:2]
    speed = min(max(float(speed), lo), hi)  # only the validated range (FR TTS 004)
    normalized, notes = normalize(text, v.get('language'))
    with _slots:
        rt = v.get('runtime')
        wav = _espeak(v, normalized, speed) if rt == 'espeak' else _piper(v, normalized, speed) if rt == 'piper' else _vits(key, v, normalized, speed)
    return _encode(wav, fmt), _wav_duration(wav), normalized, notes, speed


class Handler(BaseHTTPRequestHandler):
    def _json(self, code, body):
        data = json.dumps(body, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *a):  # quiet
        pass

    def _body(self):
        n = int(self.headers.get('Content-Length') or 0)
        if n > MAX_CHARS * 8:
            raise ValueError('request too large')
        b = json.loads(self.rfile.read(n) or b'{}')
        if not isinstance(b, dict):
            raise ValueError('JSON object expected')
        text = str(b.get('text', '')).strip()
        if not text:
            raise ValueError('text is required')
        if len(text) > MAX_CHARS:
            raise ValueError(f'text is limited to {MAX_CHARS} characters per request')
        return b, text

    def do_GET(self):
        if self.path == '/health':
            return self._json(200, {'ok': True, 'voices': voices(), 'normalizer': NORMALIZER_VERSION, 'espeak': bool(ESPEAK), 'ffmpeg': bool(FFMPEG)})
        return self._json(404, {'error': 'not found'})

    def do_POST(self):
        try:
            b, text = self._body()
        except Exception as e:
            return self._json(422, {'error': str(e)})
        key = str(b.get('voice', 'espeak-si'))
        if self.path == '/normalize':
            _refresh()
            v = _voices.get(key)
            if not v:
                return self._json(422, {'error': f'Unknown voice {key}'})
            norm, notes = normalize(text, v.get('language'))
            return self._json(200, {'text': text, 'normalized': norm, 'notes': notes, 'normalizer': NORMALIZER_VERSION})
        if self.path != '/synthesize':
            return self._json(404, {'error': 'not found'})
        fmt = str(b.get('format', 'wav')).lower()
        if fmt not in FORMATS:
            return self._json(422, {'error': 'format must be one of: ' + ', '.join(FORMATS)})
        t0 = time.time()
        try:
            audio, duration, normalized, notes, speed = synthesize(key, text, float(b.get('speed', 1.0) or 1.0), fmt)
        except PermissionError as e:
            return self._json(403, {'error': str(e)})
        except LookupError as e:
            return self._json(422, {'error': str(e)})
        except Exception as e:
            print(f'synthesize failed voice={key}: {e}', flush=True)
            return self._json(500, {'error': f'Synthesis failed: {e}'})
        print(f'synthesize voice={key} chars={len(text)} fmt={fmt} audio={duration:.1f}s ran={time.time() - t0:.2f}s', flush=True)
        self.send_response(200)
        self.send_header('Content-Type', MIME[fmt])
        self.send_header('Content-Length', str(len(audio)))
        self.send_header('X-Duration', f'{duration:.2f}')
        self.send_header('X-Speed', f'{speed:.2f}')
        self.send_header('X-Voice', key)
        self.send_header('X-Normalizer', NORMALIZER_VERSION)
        self.send_header('X-Normalized-Text', json.dumps(normalized, ensure_ascii=True)[:4000])
        if notes:
            self.send_header('X-Notes', json.dumps(notes, ensure_ascii=True)[:2000])
        self.end_headers()
        self.wfile.write(audio)


if __name__ == '__main__':
    _refresh()
    print(f'aistudio-tts on {HOST}:{PORT} — voices: {", ".join(_voices) or "none (install espeak-ng or add a voice)"}', flush=True)
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
