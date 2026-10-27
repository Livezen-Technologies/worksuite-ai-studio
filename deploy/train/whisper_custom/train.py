"""Custom speech model — training-pipeline recipe `whisper_custom`: fine-tune Whisper on your own recordings.

Data (staged by the server): train.jsonl / dev.jsonl ({"audio": file, "text": transcript}) with the recordings in
audio.tar, and — unless GENERAL_RATIO is 0 — general_train/general_dev.parquet of ordinary SinhaSpeech clips.
The gentle recipe from the numbers fine-tune report: the encoder stays frozen (FREEZE_ENCODER=1), low learning rate,
GENERAL_RATIO ordinary clips per recording mixed into training. After every epoch it measures word and character
error on YOUR dev recordings and on general Sinhala, and keeps the best epoch whose general word error has not risen
by more than 3 points — so a model that forgets everyday Sinhala is not the one uploaded.
"""
import os
os.environ.setdefault('PYTORCH_CUDA_ALLOC_CONF', 'expandable_segments:True')
import io, json, math, random, re, subprocess, sys, tarfile, time, urllib.request

import librosa, numpy as np, soundfile as sf, torch
from transformers import WhisperForConditionalGeneration, WhisperProcessor

BASE = os.environ.get('BASE', 'SinhaSpeech/whisper-small-sinhala-run11')
EPOCHS = int(os.environ.get('EPOCHS', '2'))
BATCH = int(os.environ.get('BATCH', '16'))
LR = float(os.environ.get('LR', '5e-6'))
GENERAL_RATIO = float(os.environ.get('GENERAL_RATIO', '1'))
FREEZE_ENCODER = os.environ.get('FREEZE_ENCODER', '1') not in ('0', '0.0', 'false')
LANG = os.environ.get('LANGUAGE', 'si')
URL, TOKEN = os.environ.get('AISTUDIO_JOB_URL', ''), os.environ.get('AISTUDIO_JOB_TOKEN', '')
OUT, RESULT = 'out/whisper-custom', 'whisper-custom.tar.gz'
dev = torch.device('cuda' if torch.cuda.is_available() else 'cpu')
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
                'vram_pct': round(used / total * 100, 1), 'gpu_temp_c': max(float(r[3]) for r in rows),
                'gpu_name': (f'{len(rows)} × ' if len(rows) > 1 else '') + rows[0][4]}
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
            if k % 4 == 0 or k == parts - 1: report(phase=f'uploading {k + 1}/{parts}')
    return res


# A retry whose training already finished uploads that result instead of training again.
if os.path.exists(RESULT) and os.path.getsize(RESULT) > 1_000_000:
    say('A finished result from an earlier attempt of this job is here; uploading it instead of training again.')
    res = upload(RESULT, RESULT)
    report(status='completed', phase='done', metrics={'output_sha256': res['data']['sha256']}); sys.exit(0)

report(status='running', phase='unpacking recordings')
os.makedirs('audio', exist_ok=True)
with tarfile.open('audio.tar') as t:
    t.extractall('audio')


def pcm_from(b):
    a, sr = sf.read(io.BytesIO(b), dtype='float32', always_2d=False)
    if a.ndim > 1: a = a.mean(1)
    if sr != 16000: a = librosa.resample(a, orig_sr=sr, target_sr=16000)
    return a[: 16000 * 30]


def load_audio(name):
    try:
        return pcm_from(open(os.path.join('audio', name), 'rb').read())
    except Exception:
        a, _ = librosa.load(os.path.join('audio', name), sr=16000, mono=True)   # mp3 / m4a / webm via audioread
        return a[: 16000 * 30].astype(np.float32)


def own(split):
    rows = []
    for l in open(f'{split}.jsonl', encoding='utf-8'):
        if l.strip():
            r = json.loads(l); rows.append(('own', r['audio'], r['text']))
    return rows


def general(split, limit):
    p = f'general_{split}.parquet'
    if not os.path.exists(p) or limit <= 0: return []
    import pyarrow.parquet as pq
    t = pq.read_table(p)
    idx = list(range(t.num_rows)); random.Random(7).shuffle(idx)
    a, x = t.column('audio').to_pylist(), t.column('text').to_pylist()
    return [('bytes', a[i], x[i]) for i in idx[:limit]]


def audio_of(src, ref):
    return load_audio(ref) if src == 'own' else pcm_from(ref)


train_own, dev_own = own('train'), own('dev')
train_gen = general('train', int(len(train_own) * GENERAL_RATIO))
dev_gen = general('dev', 150)
train = train_own + train_gen
say(f'recordings: {len(train_own)} train / {len(dev_own)} dev · general Sinhala mixed in: {len(train_gen)} · general check clips: {len(dev_gen)}')
say(f'base {BASE} · epochs {EPOCHS} · lr {LR} · encoder {"frozen" if FREEZE_ENCODER else "trained"} · device {dev}')

proc = WhisperProcessor.from_pretrained(BASE)
model = WhisperForConditionalGeneration.from_pretrained(BASE).to(dev)
model.config.forced_decoder_ids = None
model.generation_config.forced_decoder_ids = None
if FREEZE_ENCODER:
    for p in model.model.encoder.parameters(): p.requires_grad = False
prefix = proc.tokenizer.convert_tokens_to_ids(['<|startoftranscript|>', f'<|{LANG}|>', '<|transcribe|>', '<|notimestamps|>'])
N_GPU = max(1, torch.cuda.device_count())


class LossOnly(torch.nn.Module):
    """Each GPU returns only its loss (DataParallel would otherwise gather every output onto GPU 0)."""
    def __init__(self, m):
        super().__init__(); self.m = m

    def forward(self, **kw):
        return self.m(**kw).loss.reshape(1)


net = torch.nn.DataParallel(LossOnly(model)) if N_GPU > 1 else LossOnly(model)
STEP = BATCH * N_GPU


def batch(rows):
    feats = proc.feature_extractor([audio_of(s, r) for s, r, _ in rows], sampling_rate=16000, return_tensors='pt').input_features.to(dev)
    ids = [prefix + proc.tokenizer(t, add_special_tokens=False).input_ids[:440] + [proc.tokenizer.eos_token_id] for _, _, t in rows]
    n = max(map(len, ids))
    lab = torch.full((len(ids), n), -100, dtype=torch.long)
    for i, x in enumerate(ids): lab[i, : len(x)] = torch.tensor(x)
    dec_in = lab[:, :-1].clone(); dec_in[dec_in == -100] = proc.tokenizer.eos_token_id
    return feats, dec_in.to(dev), lab[:, 1:].to(dev)


def words(t):
    return re.sub(r'[^\w\s඀-෿]', ' ', t.lower()).split()


def edit(a, b):
    d = list(range(len(b) + 1))
    for i in range(1, len(a) + 1):
        prev, d[0] = d[0], i
        for j in range(1, len(b) + 1):
            cur = min(d[j] + 1, d[j - 1] + 1, prev + (a[i - 1] != b[j - 1])); prev, d[j] = d[j], cur
    return d[len(b)]


def score(rows):
    """Word and character error rate on rows, transcribed greedily."""
    if not rows: return None
    model.eval(); we = wn = ce = cn = 0
    with torch.no_grad():
        for i in range(0, len(rows), BATCH):
            part = rows[i:i + BATCH]
            f = proc.feature_extractor([audio_of(s, r) for s, r, _ in part], sampling_rate=16000, return_tensors='pt').input_features.to(dev)
            with torch.autocast('cuda', dtype=torch.float16, enabled=dev.type == 'cuda'):
                gen = model.generate(input_features=f, language=LANG, task='transcribe', max_new_tokens=200)
            for (_, _, ref), hyp in zip(part, proc.batch_decode(gen, skip_special_tokens=True)):
                r, h = words(ref), words(hyp); we += edit(r, h); wn += len(r)
                rc, hc = ''.join(r), ''.join(h); ce += edit(list(rc), list(hc)); cn += len(rc)
    model.train()
    return {'wer': round(we / max(1, wn), 4), 'cer': round(ce / max(1, cn), 4)}


def evaluate(label):
    r = {'yours': score(dev_own), 'general': score(dev_gen)}
    say(f'[{label}] your recordings: {r["yours"]} · general Sinhala: {r["general"]}')
    return r


steps = EPOCHS * math.ceil(len(train) / STEP)
report(phase='evaluating the base model', total_steps=steps, step=0)
before = evaluate('before')
report(phase='training', metrics={'before': {'your_wer': before['yours']['wer'], 'general_wer': (before['general'] or {}).get('wer')}})
params = [p for p in model.parameters() if p.requires_grad]
opt = torch.optim.AdamW(params, lr=LR, weight_decay=0.01)
warm = max(10, steps // 10)
sched = torch.optim.lr_scheduler.LambdaLR(opt, lambda s: min(1, (s + 1) / warm) * max(0.0, (steps - s) / max(1, steps - warm)))
scaler = torch.cuda.amp.GradScaler(enabled=dev.type == 'cuda')
step, t0, saved, best = 0, time.time(), None, None
for ep in range(EPOCHS):
    random.shuffle(train)
    for i in range(0, len(train), STEP):
        f, d, l = batch(train[i:i + STEP])
        with torch.autocast('cuda', dtype=torch.float16, enabled=dev.type == 'cuda'):
            loss = net(input_features=f, decoder_input_ids=d, labels=l).mean()
        scaler.scale(loss).backward(); scaler.unscale_(opt); torch.nn.utils.clip_grad_norm_(params, 1.0)
        scaler.step(opt); scaler.update(); sched.step(); opt.zero_grad(set_to_none=True); step += 1
        if step % 10 == 0 or step == steps:
            el = time.time() - t0
            say(f'ep {ep + 1} step {step}/{steps} loss {loss.item():.4f}')
            report(step=step, total_steps=steps, epoch=round(ep + (i + STEP) / len(train), 3), loss=round(loss.item(), 4),
                   lr=sched.get_last_lr()[0], sec_per_step=round(el / step, 2), eta_seconds=int(el / step * (steps - step)))
    report(phase=f'evaluating epoch {ep + 1}')
    r = evaluate(f'epoch {ep + 1}')
    gw, gw0 = (r['general'] or {}).get('wer'), (before['general'] or {}).get('wer')
    forgot = gw is not None and gw0 is not None and gw - gw0 > 0.03
    if forgot: say(f'  general Sinhala word error rose {round((gw - gw0) * 100, 1)} points — not keeping this epoch')
    if not forgot and (best is None or r['yours']['wer'] < best):
        best, saved = r['yours']['wer'], r
        model.save_pretrained(OUT, state_dict={k: v.detach().to('cpu', torch.float16) for k, v in model.state_dict().items()})
        proc.save_pretrained(OUT)
        json.dump({'base': BASE, 'language': LANG, 'epoch': ep + 1, 'before': before, 'after': r, 'train_recordings': len(train_own)},
                  open(f'{OUT}/metrics.json', 'w'), ensure_ascii=False)
        say('  saved (best on your recordings without losing general Sinhala)')
    report(phase='training', metrics={f'epoch_{ep + 1}': {'your_wer': r['yours']['wer'], 'general_wer': gw}})

if not saved or saved['yours']['wer'] >= before['yours']['wer']:
    say('No epoch improved on your recordings without hurting general Sinhala; nothing to upload. Try more recordings or one more epoch.')
    report(status='failed', phase='no improvement', error='No epoch improved word error on your recordings without hurting general Sinhala.'); sys.exit(1)
report(phase='uploading', metrics={'after': {'your_wer': saved['yours']['wer'], 'general_wer': (saved['general'] or {}).get('wer')}})
with tarfile.open(RESULT, 'w:gz') as t:
    t.add(OUT, arcname='whisper-custom')
res = upload(RESULT, RESULT)
say(f'uploaded {res["data"]["size_bytes"]} bytes, sha256 {res["data"]["sha256"]}')
say('REPORT ' + json.dumps({'base': BASE, 'before': before, 'after': saved, 'train_minutes': round((time.time() - t0) / 60, 1)}))
report(status='completed', phase='done', metrics={'output_sha256': res['data']['sha256']})
