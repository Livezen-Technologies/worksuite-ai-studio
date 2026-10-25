"""AI Studio speech-to-text service: Whisper Sinhala (faster-whisper, CPU int8), kept loaded in memory.

Internal only (127.0.0.1). AI Studio's PHP API forwards uploads here.
  POST /transcribe?language=si|en|auto&timestamps=1   body = the audio file's bytes
  GET  /health
"""
import json, os, subprocess, threading, time
import numpy as np
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse
from faster_whisper import WhisperModel

MODEL_DIR = os.environ.get('ASR_MODEL', '/srv/sinhala-data/models/whisper-small-sinhala-ct2-int8')
MODEL_NAME = 'Whisper Sinhala (SinhaSpeech whisper-small run11, int8)'
MAX_BYTES = 60 * 1024 * 1024
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
            return self._send(413 if n > MAX_BYTES else 400, {'error': 'Send an audio file up to 60 MB.'})
        audio = self.rfile.read(n)
        q = parse_qs(u.query)
        lang = (q.get('language', ['si'])[0] or 'si').lower()
        t0 = time.time()
        # ffmpeg decodes anything a browser or phone produces (WebM/Opus, M4A, MP3, OGG, WAV, FLAC) to 16 kHz mono.
        dec = subprocess.run(['ffmpeg', '-nostdin', '-loglevel', 'error', '-i', 'pipe:0', '-f', 'f32le', '-ac', '1', '-ar', '16000', 'pipe:1'],
                             input=audio, capture_output=True)
        pcm = np.frombuffer(dec.stdout, dtype=np.float32)
        if dec.returncode != 0 or pcm.size < 1600:
            return self._send(422, {'error': 'Could not read this audio. Try WAV, MP3, M4A, OGG, WebM or FLAC, at least a second long.'})
        try:
            with lock:
                segs, info = model.transcribe(pcm, language=None if lang == 'auto' else lang, beam_size=5, vad_filter=True)
                segments = [{'start': round(s.start, 2), 'end': round(s.end, 2), 'text': s.text.strip()} for s in segs]
        except Exception as e:  # undecodable or empty audio
            return self._send(422, {'error': f'Could not read this audio: {type(e).__name__}. Try WAV, MP3, M4A, OGG, WebM or FLAC.'})
        self._send(200, {
            'text': ' '.join(s['text'] for s in segments).strip(), 'segments': segments,
            'language': info.language, 'language_probability': round(info.language_probability, 3),
            'duration': round(info.duration, 2), 'processing_seconds': round(time.time() - t0, 2), 'model': MODEL_NAME,
        })


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', int(os.environ.get('ASR_PORT', '8096'))), Handler).serve_forever()
