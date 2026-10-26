"""Fine-tune Whisper Sinhala (SinhaSpeech run11) on number-heavy speech — AI Studio training job runner (CUDA).

Run by run.sh on a training node. Reads train.parquet / dev.parquet (audio bytes + text) from the job folder, reports
step, loss, ETA and GPU figures to AI Studio (AISTUDIO_JOB_URL / AISTUDIO_JOB_TOKEN), scores number accuracy on the
dev clips before and after (number VALUES via numfix, the article එක not counted — the same rule as the Engine
benchmark), saves the best epoch in fp16 and uploads it to the server as whisper-sinhala-numbers.tar.gz.

Encoder frozen for the first half (the change lands in the decoder, which writes the number words), then joins.
"""
import io, json, math, os, random, re, subprocess, sys, tarfile, time, urllib.request
from collections import Counter

import numpy as np, pyarrow.parquet as pq, soundfile as sf, librosa, torch
from transformers import WhisperForConditionalGeneration, WhisperProcessor
from numfix import normalise

BASE = os.environ.get('BASE', 'SinhaSpeech/whisper-small-sinhala-run11')
EPOCHS = int(os.environ.get('EPOCHS', '3'))
BATCH = int(os.environ.get('BATCH', '16'))
LR = float(os.environ.get('LR', '1e-5'))
OUT = 'out/whisper-sinhala-numbers'
URL, TOKEN = os.environ.get('AISTUDIO_JOB_URL', ''), os.environ.get('AISTUDIO_JOB_TOKEN', '')
dev = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
torch.manual_seed(2026); random.seed(2026)
log_buf = []


def say(msg):
    print(msg, flush=True); log_buf.append(msg)


def gpu():
    try:
        q = subprocess.run(['nvidia-smi', '--query-gpu=utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,name',
                            '--format=csv,noheader,nounits'], capture_output=True, text=True, timeout=5).stdout.splitlines()[0].split(', ')
        return {'gpu_pct': float(q[0]), 'vram_used_mb': float(q[1]), 'vram_total_mb': float(q[2]), 'vram_pct': round(float(q[1]) / float(q[2]) * 100, 1),
                'gpu_temp_c': float(q[3]), 'gpu_power_w': float(q[4]) if q[4] not in ('[N/A]', '') else None, 'gpu_name': q[5]}
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


def values(text):
    return [re.match(r'\d+', w).group(0) for w in re.sub(r'[^\w\s඀-෿]', ' ', normalise(text)).split() if re.match(r'\d', w)]


proc = WhisperProcessor.from_pretrained(BASE)
model = WhisperForConditionalGeneration.from_pretrained(BASE).to(dev)
model.config.forced_decoder_ids = None
model.generation_config.forced_decoder_ids = None
prefix = proc.tokenizer.convert_tokens_to_ids(['<|startoftranscript|>', '<|si|>', '<|transcribe|>', '<|notimestamps|>'])


def load(split):
    t = pq.read_table(f'{split}.parquet')
    return list(zip(t.column('audio').to_pylist(), t.column('text').to_pylist()))


def pcm(b):
    a, sr = sf.read(io.BytesIO(b), dtype='float32', always_2d=False)
    if a.ndim > 1: a = a.mean(1)
    if sr != 16000: a = librosa.resample(a, orig_sr=sr, target_sr=16000)
    return a[: 16000 * 30]


def feats(rows):
    return proc.feature_extractor([pcm(a) for a, _ in rows], sampling_rate=16000, return_tensors='pt').input_features.to(dev)


def batch(rows):
    ids = [prefix + proc.tokenizer(t, add_special_tokens=False).input_ids[:440] + [proc.tokenizer.eos_token_id] for _, t in rows]
    n = max(map(len, ids))
    lab = torch.full((len(ids), n), -100, dtype=torch.long)
    for i, x in enumerate(ids): lab[i, : len(x)] = torch.tensor(x)
    dec_in = lab[:, :-1].clone(); dec_in[dec_in == -100] = proc.tokenizer.eos_token_id
    return feats(rows), dec_in.to(dev), lab[:, 1:].to(dev)


def evaluate(rows, label):
    """dev loss + number accuracy (values) on the dev clips."""
    model.eval(); tot = n = 0; hit = want_n = 0
    with torch.no_grad():
        for i in range(0, len(rows), BATCH):
            part = rows[i:i + BATCH]
            f, d, l = batch(part)
            with torch.autocast('cuda', dtype=torch.float16, enabled=dev.type == 'cuda'):
                tot += model(input_features=f, decoder_input_ids=d, labels=l).loss.item() * len(part)
                gen = model.generate(input_features=f, language='si', task='transcribe', max_new_tokens=200)
            n += len(part)
            for (_, ref), hyp in zip(part, proc.batch_decode(gen, skip_special_tokens=True)):
                w, g = Counter(values(ref)), Counter(values(hyp))
                hit += sum(min(c, g[k]) for k, c in w.items()); want_n += sum(w.values())
    model.train()
    r = {'dev_loss': round(tot / n, 4), 'number_accuracy': round(hit / max(1, want_n), 4), 'number_tokens': want_n}
    say(f'[{label}] {json.dumps(r)}')
    return r


train, devset = load('train'), load('dev')
say(f'train {len(train)}  dev {len(devset)}  device {dev}  base {BASE}  epochs {EPOCHS}  batch {BATCH}')
steps = EPOCHS * math.ceil(len(train) / BATCH)
report(status='running', phase='evaluating base', total_steps=steps, step=0)
before = evaluate(devset, 'before')
report(phase='training', metrics={'before': before})
best = before['dev_loss']
opt = torch.optim.AdamW(model.parameters(), lr=LR, weight_decay=0.01)
warm = max(20, steps // 20)
sched = torch.optim.lr_scheduler.LambdaLR(opt, lambda s: min(1, (s + 1) / warm) * max(0.0, (steps - s) / max(1, steps - warm)))
scaler = torch.cuda.amp.GradScaler(enabled=dev.type == 'cuda')
step, t0, saved, after = 0, time.time(), False, before
for ep in range(EPOCHS):
    random.shuffle(train)
    for p in model.model.encoder.parameters(): p.requires_grad = ep >= EPOCHS / 2
    for i in range(0, len(train), BATCH):
        f, d, l = batch(train[i:i + BATCH])
        with torch.autocast('cuda', dtype=torch.float16, enabled=dev.type == 'cuda'):
            loss = model(input_features=f, decoder_input_ids=d, labels=l).loss
        scaler.scale(loss).backward(); scaler.unscale_(opt); torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
        scaler.step(opt); scaler.update(); sched.step(); opt.zero_grad(); step += 1
        if step % 10 == 0 or step == steps:
            el = time.time() - t0
            say(f'ep {ep + 1} step {step}/{steps} loss {loss.item():.4f}')
            report(step=step, total_steps=steps, epoch=round(ep + (i + BATCH) / len(train), 3), loss=round(loss.item(), 4),
                   lr=sched.get_last_lr()[0], sec_per_step=round(el / step, 2), eta_seconds=int(el / step * (steps - step)))
    report(phase=f'evaluating epoch {ep + 1}')
    r = evaluate(devset, f'epoch {ep + 1}')
    if r['dev_loss'] < best:
        best, after = r['dev_loss'], r
        model.save_pretrained(OUT, state_dict={k: v.detach().to('cpu', torch.float16) for k, v in model.state_dict().items()})
        proc.save_pretrained(OUT); saved = True; say('  saved (best dev loss so far)')
    report(phase='training', metrics={f'epoch_{ep + 1}': r})

if not saved:
    say('No epoch beat the base model on dev loss; nothing to upload.')
    report(status='failed', phase='no improvement', metrics={'after': after}); sys.exit(1)
report(phase='uploading', metrics={'after': after})
with tarfile.open('whisper-sinhala-numbers.tar.gz', 'w:gz') as t:
    t.add(OUT, arcname='whisper-sinhala-numbers')
up = URL.replace('/progress', '/output/whisper-sinhala-numbers.tar.gz')
req = urllib.request.Request(up, data=open('whisper-sinhala-numbers.tar.gz', 'rb').read(), method='PUT',
                             headers={'Authorization': f'Bearer {TOKEN}', 'Content-Type': 'application/octet-stream'})
res = json.load(urllib.request.urlopen(req, timeout=1800))
say(f'uploaded {res["data"]["size_bytes"]} bytes, sha256 {res["data"]["sha256"]}')
say('REPORT ' + json.dumps({'base': BASE, 'train_utts': len(train), 'dev_utts': len(devset), 'epochs': EPOCHS,
                            'train_minutes': round((time.time() - t0) / 60, 1), 'before': before, 'after': after}))
report(status='completed', phase='done', metrics={'after': after, 'output_sha256': res['data']['sha256']})
