"""Text classifier — training-pipeline recipe `text_classifier`.

Fine-tunes a multilingual encoder (default XLM-RoBERTa base: Sinhala + English) to put a text into one of the dataset's
labels. Data: train.jsonl / dev.jsonl staged by the server; text = `input` (or `instruction` when there is no input),
label = `output`. Runs on a GPU (fp16) or on CPU. Reports accuracy and macro-F1 on the dev rows per epoch, keeps the
best epoch, writes labels.json + metrics.json beside the model, and uploads classifier.tar.gz.
"""
import os
os.environ.setdefault('PYTORCH_CUDA_ALLOC_CONF', 'expandable_segments:True')
import json, math, random, subprocess, sys, tarfile, time, urllib.request
from collections import Counter

import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer

BASE = os.environ.get('BASE', 'FacebookAI/xlm-roberta-base')
EPOCHS = int(os.environ.get('EPOCHS', '4'))
BATCH = int(os.environ.get('BATCH', '16'))
LR = float(os.environ.get('LR', '2e-5'))
MAX_LEN = int(os.environ.get('MAX_LEN', '256'))
URL, TOKEN = os.environ.get('AISTUDIO_JOB_URL', ''), os.environ.get('AISTUDIO_JOB_TOKEN', '')
OUT = 'out/classifier'
dev = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
if dev.type == 'cpu': torch.set_num_threads(os.cpu_count() or 4)
torch.manual_seed(2026); random.seed(2026)
log_buf = []


def say(msg):
    print(msg, flush=True); log_buf.append(msg)


def gpu():
    try:
        rows = [l.split(', ') for l in subprocess.run(['nvidia-smi', '--query-gpu=utilization.gpu,memory.used,memory.total,temperature.gpu,name',
                '--format=csv,noheader,nounits'], capture_output=True, text=True, timeout=5).stdout.strip().splitlines()]
        used, total = sum(float(r[1]) for r in rows), sum(float(r[2]) for r in rows)
        return {'gpu_pct': round(sum(float(r[0]) for r in rows) / len(rows), 1), 'vram_used_mb': used, 'vram_total_mb': total,
                'vram_pct': round(used / total * 100, 1), 'gpu_temp_c': max(float(r[3]) for r in rows), 'gpu_name': rows[0][4]}
    except Exception:
        return {}


def report(**kw):
    if not URL: return
    body = dict(kw, host=os.uname().nodename, device=str(dev), **gpu())
    if log_buf: body['log'] = log_buf[:]; log_buf.clear()
    try:
        req = urllib.request.Request(URL, data=json.dumps(body).encode(), headers={'Content-Type': 'application/json', 'Authorization': f'Bearer {TOKEN}'})
        urllib.request.urlopen(req, timeout=20).read()
    except Exception as e:
        print('report failed:', e, flush=True)


def upload(path, name):
    size, piece = os.path.getsize(path), 32 * 1024 * 1024
    parts = max(1, math.ceil(size / piece))
    base = URL.replace('/progress', '/output/' + name)
    with open(path, 'rb') as fh:
        for k in range(parts):
            chunk = fh.read(piece)
            for attempt in range(5):
                try:
                    req = urllib.request.Request(f'{base}?part={k}&parts={parts}', data=chunk, method='PUT',
                                                 headers={'Authorization': f'Bearer {TOKEN}', 'Content-Type': 'application/octet-stream'})
                    res = json.load(urllib.request.urlopen(req, timeout=600)); break
                except Exception as e:
                    if attempt == 4: raise
                    say(f'upload part {k + 1}/{parts} failed ({e}); retrying'); time.sleep(10 * (attempt + 1))
    return res


def rows(name):
    out = []
    for l in open(name, encoding='utf-8'):
        if not l.strip(): continue
        r = json.loads(l)
        text = (r.get('input') or '').strip() or r['instruction'].strip()
        out.append((text, r['output'].strip()))
    return out


report(status='running', phase='loading data and model')
train, devset = rows('train.jsonl'), rows('dev.jsonl')
labels = sorted({l for _, l in train} | {l for _, l in devset})
lid = {l: i for i, l in enumerate(labels)}
counts = Counter(l for _, l in train)
say(f'train {len(train)}  dev {len(devset)}  labels {len(labels)}  base {BASE}  device {dev}')
say('labels: ' + ', '.join(f'{l} ({counts[l]})' for l in labels))
if len(labels) < 2: report(status='failed', phase='bad data', error='The dataset has fewer than two labels.'); sys.exit(1)

tok = AutoTokenizer.from_pretrained(BASE)
model = AutoModelForSequenceClassification.from_pretrained(BASE, num_labels=len(labels), id2label=dict(enumerate(labels)), label2id=lid).to(dev)


def batches(data, shuffle):
    idx = list(range(len(data)))
    if shuffle: random.shuffle(idx)
    for i in range(0, len(idx), BATCH):
        part = [data[j] for j in idx[i:i + BATCH]]
        enc = tok([t for t, _ in part], truncation=True, max_length=MAX_LEN, padding=True, return_tensors='pt')
        yield {k: v.to(dev) for k, v in enc.items()}, torch.tensor([lid[l] for _, l in part], device=dev)


def evaluate():
    model.eval(); pred, gold, loss_sum = [], [], 0.0
    with torch.no_grad():
        for enc, y in batches(devset, False):
            with torch.autocast('cuda', dtype=torch.float16, enabled=dev.type == 'cuda'):
                out = model(**enc, labels=y)
            loss_sum += out.loss.item() * len(y)
            pred += out.logits.argmax(-1).tolist(); gold += y.tolist()
    model.train()
    acc = sum(p == g for p, g in zip(pred, gold)) / max(1, len(gold))
    f1s = []
    for c in range(len(labels)):
        tp = sum(p == c and g == c for p, g in zip(pred, gold)); fp = sum(p == c and g != c for p, g in zip(pred, gold)); fn = sum(p != c and g == c for p, g in zip(pred, gold))
        prec = tp / (tp + fp) if tp + fp else 0.0; rec = tp / (tp + fn) if tp + fn else 0.0
        f1s.append(2 * prec * rec / (prec + rec) if prec + rec else 0.0)
    return {'dev_loss': round(loss_sum / max(1, len(gold)), 4), 'accuracy': round(acc, 4), 'macro_f1': round(sum(f1s) / len(f1s), 4)}


steps = EPOCHS * math.ceil(len(train) / BATCH)
opt = torch.optim.AdamW(model.parameters(), lr=LR, weight_decay=0.01)
warm = max(10, steps // 10)
sched = torch.optim.lr_scheduler.LambdaLR(opt, lambda s: min(1, (s + 1) / warm) * max(0.0, (steps - s) / max(1, steps - warm)))
scaler = torch.cuda.amp.GradScaler(enabled=dev.type == 'cuda')
before = evaluate(); say(f'[before] {json.dumps(before)}')
report(phase='training', total_steps=steps, step=0, metrics={'before': before})
best, step, t0, saved = -1.0, 0, time.time(), None
for ep in range(EPOCHS):
    for enc, y in batches(train, True):
        with torch.autocast('cuda', dtype=torch.float16, enabled=dev.type == 'cuda'):
            loss = model(**enc, labels=y).loss
        scaler.scale(loss).backward(); scaler.unscale_(opt); torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
        scaler.step(opt); scaler.update(); sched.step(); opt.zero_grad(set_to_none=True); step += 1
        if step % 10 == 0 or step == steps:
            el = time.time() - t0
            say(f'ep {ep + 1} step {step}/{steps} loss {loss.item():.4f}')
            report(step=step, total_steps=steps, epoch=round(ep + 1, 3), loss=round(loss.item(), 4), lr=sched.get_last_lr()[0],
                   sec_per_step=round(el / step, 2), eta_seconds=int(el / step * (steps - step)))
    report(phase=f'evaluating epoch {ep + 1}')
    r = evaluate(); say(f'[epoch {ep + 1}] {json.dumps(r)}')
    if r['macro_f1'] > best:
        best, saved = r['macro_f1'], r
        model.save_pretrained(OUT); tok.save_pretrained(OUT)
        json.dump(labels, open(f'{OUT}/labels.json', 'w'), ensure_ascii=False)
        json.dump({'base': BASE, 'train_rows': len(train), 'dev_rows': len(devset), 'epoch': ep + 1, **r, 'label_counts': counts}, open(f'{OUT}/metrics.json', 'w'), ensure_ascii=False)
        say('  saved (best macro-F1 so far)')
    report(phase='training', metrics={f'epoch_{ep + 1}': r})

report(phase='uploading', metrics={'after': saved})
with tarfile.open('classifier.tar.gz', 'w:gz') as t:
    t.add(OUT, arcname='classifier')
res = upload('classifier.tar.gz', 'classifier.tar.gz')
say(f'uploaded {res["data"]["size_bytes"]} bytes, sha256 {res["data"]["sha256"]}')
say('REPORT ' + json.dumps({'base': BASE, 'labels': len(labels), 'train_minutes': round((time.time() - t0) / 60, 1), 'before': before, 'after': saved}))
report(status='completed', phase='done', metrics={'after': saved, 'output_sha256': res['data']['sha256']})
