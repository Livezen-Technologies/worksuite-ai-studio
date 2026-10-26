"""LoRA fine-tune of a chat model on an AI Studio instruction dataset — training-pipeline recipe `text_sft` (CUDA).

Run by the node runner with job.env loaded: BASE, EPOCHS, BATCH, GRAD_ACCUM, LR, LORA_R, MAX_LEN, JOB_ID.
Data: train.jsonl / dev.jsonl ({instruction, input, output}) staged by the server (the locked test split stays there).
fp16 base with gradient checkpointing (fits a 16 GB T4 for a 3B model); LoRA weights in fp32 so the grad scaler works.
Reports progress to AI Studio, keeps the epoch with the best dev loss, uploads the adapter as adapter.tar.gz.
"""
import os
os.environ.setdefault('PYTORCH_CUDA_ALLOC_CONF', 'expandable_segments:True')  # before torch touches CUDA
import json, math, os, random, subprocess, sys, tarfile, time, urllib.request

import torch
from peft import LoraConfig, get_peft_model
from transformers import AutoModelForCausalLM, AutoTokenizer

BASE = os.environ.get('BASE', 'Qwen/Qwen2.5-3B-Instruct')
EPOCHS = int(os.environ.get('EPOCHS', '2'))
BATCH = int(os.environ.get('BATCH', '4'))
ACCUM = int(os.environ.get('GRAD_ACCUM', '4'))
LR = float(os.environ.get('LR', '2e-4'))
RANK = int(os.environ.get('LORA_R', '16'))
MAX_LEN = int(os.environ.get('MAX_LEN', '1024'))
URL, TOKEN = os.environ.get('AISTUDIO_JOB_URL', ''), os.environ.get('AISTUDIO_JOB_TOKEN', '')
OUT = 'out/adapter'
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


tok = AutoTokenizer.from_pretrained(BASE)
if tok.pad_token is None: tok.pad_token = tok.eos_token


def encode(row):
    user = row['instruction'] + (('\n\n' + row['input']) if row.get('input') else '')
    prompt = tok.apply_chat_template([{'role': 'user', 'content': user}], tokenize=False, add_generation_prompt=True)
    p_ids = tok(prompt, add_special_tokens=False).input_ids
    a_ids = tok(row['output'] + tok.eos_token, add_special_tokens=False).input_ids
    ids = (p_ids + a_ids)[:MAX_LEN]
    labels = ([-100] * len(p_ids) + a_ids)[:MAX_LEN]                      # learn the answer, not the question
    return ids, labels


def load(name):
    rows = [json.loads(l) for l in open(name, encoding='utf-8') if l.strip()]
    data = [encode(r) for r in rows]
    return [d for d in data if any(x != -100 for x in d[1])]


def collate(part):
    n = max(len(i) for i, _ in part)
    ids = torch.full((len(part), n), tok.pad_token_id); lab = torch.full((len(part), n), -100); att = torch.zeros((len(part), n), dtype=torch.long)
    for k, (i, l) in enumerate(part):
        ids[k, :len(i)] = torch.tensor(i); lab[k, :len(l)] = torch.tensor(l); att[k, :len(i)] = 1
    return ids.to(dev), att.to(dev), lab.to(dev)


report(status='running', phase='loading data and model')
train, devset = load('train.jsonl'), load('dev.jsonl')
devset = devset[:500]                                                         # a steady, quick check each epoch
train.sort(key=lambda x: len(x[0]))                                           # length-bucketed batches waste less padding
batches = [train[i:i + BATCH] for i in range(0, len(train), BATCH)]
say(f'train {len(train)}  dev {len(devset)}  base {BASE}  epochs {EPOCHS}  batch {BATCH}x{ACCUM}  lora r={RANK}  device {dev}')

model = AutoModelForCausalLM.from_pretrained(BASE, torch_dtype=torch.float16 if dev.type == 'cuda' else torch.float32).to(dev)
model.gradient_checkpointing_enable(); model.enable_input_require_grads()
model = get_peft_model(model, LoraConfig(r=RANK, lora_alpha=RANK * 2, lora_dropout=0.05, task_type='CAUSAL_LM',
                                         target_modules=['q_proj', 'k_proj', 'v_proj', 'o_proj', 'gate_proj', 'up_proj', 'down_proj']))
for p in model.parameters():
    if p.requires_grad: p.data = p.data.float()                               # fp32 adapters for the grad scaler
say('trainable parameters: ' + str(sum(p.numel() for p in model.parameters() if p.requires_grad)))
N_GPU = max(1, torch.cuda.device_count())
class LossOnly(torch.nn.Module):
    """Each GPU returns only its loss. DataParallel otherwise gathers every output (vocabulary logits for every token,
    hidden states) onto GPU 0, which ran a 16 GB T4 out of memory."""
    def __init__(self, m):
        super().__init__(); self.m = m

    def forward(self, **kw):
        return self.m(**kw).loss.reshape(1)


net = torch.nn.DataParallel(LossOnly(model)) if N_GPU > 1 else LossOnly(model)   # every GPU takes a share of each batch
if N_GPU > 1:
    batches = [train[i:i + BATCH * N_GPU] for i in range(0, len(train), BATCH * N_GPU)]   # BATCH is per GPU
    say(f'{N_GPU} GPUs: {BATCH * N_GPU} rows per step')


def dev_loss():
    model.eval(); tot = n = 0
    with torch.no_grad():
        for i in range(0, len(devset), BATCH):
            ids, att, lab = collate(devset[i:i + BATCH])
            with torch.autocast('cuda', dtype=torch.float16, enabled=dev.type == 'cuda'):
                tot += model(input_ids=ids, attention_mask=att, labels=lab).loss.item() * len(devset[i:i + BATCH])
            n += len(devset[i:i + BATCH])
    model.train(); return round(tot / max(1, n), 4)


steps = EPOCHS * math.ceil(len(batches) / ACCUM)
report(phase='evaluating base', total_steps=steps, step=0)
best = before = dev_loss(); say(f'[before] dev loss {before}')
report(phase='training', metrics={'before': {'dev_loss': before}})
opt = torch.optim.AdamW([p for p in model.parameters() if p.requires_grad], lr=LR, weight_decay=0.0)
warm = max(10, steps // 20)
sched = torch.optim.lr_scheduler.LambdaLR(opt, lambda s: min(1, (s + 1) / warm) * max(0.0, (steps - s) / max(1, steps - warm)))
scaler = torch.cuda.amp.GradScaler(enabled=dev.type == 'cuda')
step, t0, saved = 0, time.time(), False
for ep in range(EPOCHS):
    random.shuffle(batches)
    for b, part in enumerate(batches):
        ids, att, lab = collate(part)
        with torch.autocast('cuda', dtype=torch.float16, enabled=dev.type == 'cuda'):
            loss = net(input_ids=ids, attention_mask=att, labels=lab).mean() / ACCUM   # one loss per GPU → mean
        scaler.scale(loss).backward()
        if (b + 1) % ACCUM == 0 or b == len(batches) - 1:
            scaler.unscale_(opt); torch.nn.utils.clip_grad_norm_([p for p in model.parameters() if p.requires_grad], 1.0)
            scaler.step(opt); scaler.update(); sched.step(); opt.zero_grad(); step += 1
            if step % 10 == 0 or step == steps:
                el = time.time() - t0
                say(f'ep {ep + 1} step {step}/{steps} loss {loss.item() * ACCUM:.4f}')
                report(step=step, total_steps=steps, epoch=round(ep + (b + 1) / len(batches), 3), loss=round(loss.item() * ACCUM, 4),
                       lr=sched.get_last_lr()[0], sec_per_step=round(el / step, 2), eta_seconds=int(el / step * (steps - step)))
    report(phase=f'evaluating epoch {ep + 1}')
    dl = dev_loss(); say(f'[epoch {ep + 1}] dev loss {dl} (best {best})')
    if dl < best:
        best = dl; model.save_pretrained(OUT); tok.save_pretrained(OUT); saved = True; say('  saved (best dev loss so far)')
    report(phase='training', metrics={f'epoch_{ep + 1}': {'dev_loss': dl}})

if not saved:
    say('No epoch beat the base model on dev loss; nothing to upload.')
    report(status='failed', phase='no improvement', metrics={'after': {'dev_loss': best}}); sys.exit(1)
report(phase='uploading', metrics={'after': {'dev_loss': best}})
with tarfile.open('adapter.tar.gz', 'w:gz') as t:
    t.add(OUT, arcname='adapter')
req = urllib.request.Request(URL.replace('/progress', '/output/adapter.tar.gz'), data=open('adapter.tar.gz', 'rb').read(), method='PUT',
                             headers={'Authorization': f'Bearer {TOKEN}', 'Content-Type': 'application/octet-stream'})
res = json.load(urllib.request.urlopen(req, timeout=1800))
say(f'uploaded {res["data"]["size_bytes"]} bytes, sha256 {res["data"]["sha256"]}')
say('REPORT ' + json.dumps({'base': BASE, 'train_rows': len(train), 'epochs': EPOCHS, 'train_minutes': round((time.time() - t0) / 60, 1),
                            'before': {'dev_loss': before}, 'after': {'dev_loss': best}}))
report(status='completed', phase='done', metrics={'after': {'dev_loss': best}, 'output_sha256': res['data']['sha256']})
