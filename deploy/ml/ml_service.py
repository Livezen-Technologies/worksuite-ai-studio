"""AI Studio ML service: predictions from trained text classifiers (and, later, table models). Internal only.

  POST /classify   {"path": "/srv/aistudio-models/ml/<model>", "texts": ["…"], "top": 3}
                   → {"results": [{"label": "…", "score": 0.93, "scores": [{"label": "…", "score": …}, …]}, …]}
  GET  /health

Models are loaded on first use and the 4 most recently used stay in memory. Only folders under ML_ROOT are served.
Runs as www-data with the shared toolkit (/opt/aistudio-llm/venv) — systemd unit aistudio-ml.
"""
import json, os, threading
from collections import OrderedDict
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer

ROOT = os.path.realpath(os.environ.get('ML_ROOT', '/srv/aistudio-models/ml'))
KEEP = int(os.environ.get('ML_KEEP', '4'))
torch.set_num_threads(int(os.environ.get('ML_THREADS', '4')))
_models = OrderedDict()
_lock = threading.Lock()


def load(path):
    real = os.path.realpath(path)
    if not real.startswith(ROOT + os.sep) or not os.path.isfile(os.path.join(real, 'config.json')):
        raise FileNotFoundError('not a model folder under ' + ROOT)
    with _lock:
        if real in _models:
            _models.move_to_end(real); return _models[real]
        tok = AutoTokenizer.from_pretrained(real)
        model = AutoModelForSequenceClassification.from_pretrained(real).eval()
        _models[real] = (tok, model)
        while len(_models) > KEEP: _models.popitem(last=False)
        return _models[real]


def classify(path, texts, top):
    tok, model = load(path)
    labels = model.config.id2label
    out = []
    with torch.no_grad():
        for i in range(0, len(texts), 32):
            enc = tok(texts[i:i + 32], truncation=True, max_length=512, padding=True, return_tensors='pt')
            probs = torch.softmax(model(**enc).logits, dim=-1)
            for row in probs:
                ranked = sorted(((labels[j] if j in labels else labels[str(j)], float(p)) for j, p in enumerate(row)), key=lambda x: -x[1])
                out.append({'label': ranked[0][0], 'score': round(ranked[0][1], 4),
                            'scores': [{'label': l, 'score': round(s, 4)} for l, s in ranked[:top]]})
    return out


class H(BaseHTTPRequestHandler):
    def _send(self, code, body):
        data = json.dumps(body, ensure_ascii=False).encode()
        try:
            self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(data)))
            self.end_headers(); self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError):
            pass

    def log_message(self, *a): pass

    def do_GET(self):
        if self.path == '/health': return self._send(200, {'ok': True, 'loaded': len(_models)})
        self._send(404, {'error': 'not found'})

    def do_POST(self):
        if self.path != '/classify': return self._send(404, {'error': 'not found'})
        try:
            body = json.loads(self.rfile.read(int(self.headers.get('Content-Length') or 0)) or b'{}')
            texts = [str(t) for t in body.get('texts', [])][:256]
            if not texts: return self._send(422, {'error': 'texts is required'})
            return self._send(200, {'results': classify(body.get('path', ''), texts, int(body.get('top', 3)))})
        except FileNotFoundError as e:
            return self._send(404, {'error': str(e)})
        except Exception as e:
            print('classify failed:', type(e).__name__, e, flush=True)
            return self._send(500, {'error': f'{type(e).__name__}: {str(e)[:200]}'})


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', int(os.environ.get('ML_PORT', '8097'))), H).serve_forever()
