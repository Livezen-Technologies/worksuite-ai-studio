"""AI Studio speech-to-text service: Whisper Sinhala (faster-whisper, CPU int8), kept loaded in memory.

Internal only (127.0.0.1). AI Studio's PHP API forwards uploads here.
  POST /transcribe?language=si|en|auto&words=1&fast=1   body = the audio or video file's bytes
       words=1  adds per-word timings (for caption splitting); fast=1 uses greedy decoding (live chunks).
       Silence is skipped (VAD) and punctuation-only lines are dropped in both modes.
  GET  /health
"""
import json, os, subprocess, tempfile, threading, time
import numpy as np
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse
from faster_whisper import WhisperModel

MODEL_DIR = os.environ.get('ASR_MODEL', '/srv/sinhala-data/models/whisper-small-sinhala-ct2-int8')
MODEL_NAME = 'Whisper Sinhala (SinhaSpeech whisper-small run11, int8)'
MAX_BYTES = 500 * 1024 * 1024  # video uploads for captions
model = WhisperModel(MODEL_DIR, device='cpu', compute_type='int8', cpu_threads=int(os.environ.get('ASR_THREADS', '4')))
lock = threading.Lock()  # one transcription at a time keeps the CPU for the rest of the box


class Handler(BaseHTTPRequestHandler):
    def _send(self, code, body):
        data = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(data)))
        self.end_headers(); self.wfile.write(data)

    def log_message(self, *a):  # quiet
        pass

    def do_GET(self):
        if urlparse(self.path).path == '/health':
            return self._send(200, {'ok': True, 'model': MODEL_NAME, 'busy': lock.locked()})
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
        t0 = time.time()
        # ffmpeg decodes anything a browser or phone produces (WebM/Opus, M4A, MP3, OGG, WAV, FLAC) to 16 kHz mono.
        dec = subprocess.run(['ffmpeg', '-nostdin', '-loglevel', 'error', '-i', path, '-vn', '-f', 'f32le', '-ac', '1', '-ar', '16000', 'pipe:1'],
                             capture_output=True)
        pcm = np.frombuffer(dec.stdout, dtype=np.float32)
        if dec.returncode != 0 or pcm.size < 1600:
            return self._send(422, {'error': 'Could not read audio from this file. Try WAV, MP3, M4A, OGG, WebM, FLAC, MP4 or MOV, at least a second long.'})
        try:
            with lock:
                segs, info = model.transcribe(pcm, language=None if lang == 'auto' else lang, beam_size=1 if fast else 5,
                                              vad_filter=True, word_timestamps=words)  # VAD also for live chunks: silence otherwise yields '.' lines
                segments = []
                for sg in segs:
                    if not any(ch.isalnum() for ch in sg.text):  # punctuation-only output from noise
                        continue
                    row = {'start': round(sg.start, 2), 'end': round(sg.end, 2), 'text': sg.text.strip()}
                    if words and sg.words:
                        row['words'] = [{'start': round(w.start, 2), 'end': round(w.end, 2), 'word': w.word} for w in sg.words]
                    segments.append(row)
        except Exception as e:  # undecodable or empty audio
            return self._send(422, {'error': f'Could not read this audio: {type(e).__name__}. Try WAV, MP3, M4A, OGG, WebM or FLAC.'})
        self._send(200, {
            'text': ' '.join(s['text'] for s in segments).strip(), 'segments': segments,
            'language': info.language, 'language_probability': round(info.language_probability, 3),
            'duration': round(info.duration, 2), 'processing_seconds': round(time.time() - t0, 2), 'model': MODEL_NAME,
        })


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', int(os.environ.get('ASR_PORT', '8096'))), Handler).serve_forever()
