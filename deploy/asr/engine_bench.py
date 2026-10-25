"""Sinhala Engine benchmark — measures the numbers the Engine's release gates ask for, on held-out data.

ASR  (Whisper Sinhala, via the local aistudio-asr service):
  wer_clean / cer_clean   SinhaSpeech stratified_v6 TEST split (the model's own held-out set), 150 clips
  wer_noisy               the same clips with white noise at 10 dB SNR
  silence_clean_rate      share of silent / noise-only clips that produce no text (60 clips)
TEXT (every Sinhala chat model in Ollama):
  chrf                    held-out rows of the WorkSuite Sinhala v1 test split (dataset 10, never trained on)
  placeholder_preservation  rewrite tasks with {name}-style placeholders that must survive untouched
  valid_first_attempt     answers that must be valid JSON on the first try
  probes                  identity, Singlish understanding, a fact
  tokens_per_second       generation speed on this server's CPU
Writes /srv/sinhala-data/bench/results.json.
"""
import io, json, os, random, re, subprocess, sys, time, urllib.request
import numpy as np

OUT = '/srv/sinhala-data/bench'; os.makedirs(OUT, exist_ok=True)
rng = random.Random(2026)
OLLAMA = 'http://127.0.0.1:11434'
ASR = 'http://127.0.0.1:8096'
TEXT_MODELS = sys.argv[1:] or ['helalm:4b', 'llama3-sinhala:8b', 'sinhalallm:1.7b', 'sinhala-llama-1b-sft:diverse', 'sinlama:latest', 'qwen2.5:3b']


def post(url, data, ctype='application/json', timeout=900):
    req = urllib.request.Request(url, data=data, headers={'Content-Type': ctype})
    return json.load(urllib.request.urlopen(req, timeout=timeout))


def norm(t):
    return re.sub(r'[^\w\s඀-෿]', ' ', t).split()


def edit(a, b):
    d = list(range(len(b) + 1))
    for i in range(1, len(a) + 1):
        prev, d[0] = d[0], i
        for j in range(1, len(b) + 1):
            cur = min(d[j] + 1, d[j - 1] + 1, prev + (a[i - 1] != b[j - 1]))
            prev, d[j] = d[j], cur
    return d[len(b)]


def wav_bytes(pcm, rate=16000):
    import wave
    buf = io.BytesIO()
    with wave.open(buf, 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(rate)
        w.writeframes((np.clip(pcm, -1, 1) * 32767).astype(np.int16).tobytes())
    return buf.getvalue()


def decode(audio_bytes):
    p = subprocess.run(['ffmpeg', '-nostdin', '-loglevel', 'error', '-i', 'pipe:0', '-f', 'f32le', '-ac', '1', '-ar', '16000', 'pipe:1'], input=audio_bytes, capture_output=True)
    return np.frombuffer(p.stdout, dtype=np.float32)


def asr(pcm):
    time.sleep(float(os.environ.get('ASR_GAP', '2')))  # leave the speech service free for live users between clips
    return post(f'{ASR}/transcribe?language=si', wav_bytes(pcm), 'application/octet-stream')['text']


def bench_asr(n=int(os.environ.get('ASR_CLIPS', '150'))):
    import pyarrow.parquet as pq
    pf = pq.ParquetFile('/srv/sinhala-data/raw/sinhaspeech_asr/hf/data/stratified_v6/test.parquet')
    rows = []
    for b in pf.iter_batches(batch_size=500, columns=['audio', 'text']):
        rows += list(zip(b.column(0).to_pylist(), b.column(1).to_pylist()))
        if len(rows) >= 3000: break
    rng.shuffle(rows)
    we = wn = ce = cn = wen = 0
    for i, (audio, ref) in enumerate(rows[:n]):
        raw = audio.get('bytes') if isinstance(audio, dict) else audio
        pcm = decode(raw)
        if pcm.size < 1600: continue
        hyp = asr(pcm); r, h = norm(ref), norm(hyp)
        we += edit(r, h); wn += len(r)
        rc, hc = ''.join(r), ''.join(h); ce += edit(list(rc), list(hc)); cn += len(rc)
        noise = np.random.default_rng(i).normal(0, 1, pcm.size).astype(np.float32)
        noise *= np.sqrt(np.mean(pcm ** 2) / (np.mean(noise ** 2) * 10 ** (10 / 10)))
        wen += edit(r, norm(asr(pcm + noise)))
        if i % 25 == 0: print(f'  asr {i}/{n}', flush=True)
    silent = 0
    for i in range(60):
        g = np.random.default_rng(1000 + i)
        clip = g.normal(0, [0.0, 0.002, 0.01][i % 3], 16000 * g.integers(2, 6)).astype(np.float32)
        silent += asr(clip).strip() == ''
    return {'wer_clean': round(we / max(1, wn), 4), 'cer_clean': round(ce / max(1, cn), 4), 'wer_noisy': round(wen / max(1, wn), 4),
            'silence_clean_rate': round(silent / 60, 4), 'clips': n, 'silence_clips': 60}


def chrf(h, r, n=6, beta=2):
    from collections import Counter
    p = rc = 0; m = 0
    for k in range(1, n + 1):
        H = Counter(h[i:i + k] for i in range(len(h) - k + 1)); R = Counter(r[i:i + k] for i in range(len(r) - k + 1))
        if not H or not R: continue
        o = sum((H & R).values()); p += o / sum(H.values()); rc += o / sum(R.values()); m += 1
    if not m: return 0.0
    p, rc = p / m, rc / m
    return 0.0 if p + rc == 0 else (1 + beta ** 2) * p * rc / (beta ** 2 * p + rc) * 100


def chat(model, prompt):
    t = time.time()
    d = post(f'{OLLAMA}/api/chat', json.dumps({'model': model, 'messages': [{'role': 'user', 'content': prompt}], 'stream': False,
                                               'options': {'temperature': 0, 'num_predict': 160, 'num_thread': int(os.environ.get('BENCH_THREADS', '3'))}}).encode())
    return d['message']['content'].strip(), d.get('eval_count', 0) / max(1e-9, d.get('eval_duration', 1) / 1e9)


def test_rows(k=25):
    q = "SELECT instruction, COALESCE(input,''), output FROM dataset_items WHERE dataset_id=10 AND split='test' ORDER BY id"
    out = subprocess.run(['sudo', 'mysql', 'ollamahub', '-N', '--batch', '--raw', '-e', q], capture_output=True, text=True).stdout
    rows = [l.split('\t') for l in out.splitlines() if l.count('\t') == 2 and len(l) < 1500]
    rng.shuffle(rows)
    return rows[:k]


PLACEHOLDERS = [('{customer_name}', 'ඔබගේ ඇණවුම සූදානම් බව {customer_name} ට දන්වන්න.'), ('{invoice_no}', 'ඉන්වොයිස් අංක {invoice_no} ගෙවා ඇති බව කියන්න.'),
                ('{date}', '{date} දින රැස්වීම කල් දැමූ බව ලියන්න.'), ('{amount}', 'රු. {amount} ක් ලැබුණු බව තහවුරු කරන්න.'), ('{branch}', '{branch} ශාඛාව හෙට වසා ඇති බව දන්වන්න.')]
JSON_TASKS = ['ශ්‍රී ලංකාවේ පළාත් ගණන', 'කොළඹ නගරයේ ප්‍රධාන භාෂා දෙක', 'සිංහල අලුත් අවුරුදු මාසය', 'ශ්‍රී ලංකාවේ මුදල් ඒකකය', 'ලංකාවේ දිගම ගඟ']
PROBES = [('identity', 'ඔබ කවුද?', lambda a: len(a) > 5), ('singlish', 'mata heta office enna baha, boss ta kiyanna message ekak liyala denna', lambda a: len(re.findall('[඀-෿]', a)) > 20),
          ('fact', 'ශ්‍රී ලංකාවේ අගනුවර කුමක්ද? කෙටියෙන් පිළිතුරු දෙන්න.', lambda a: 'කෝට්ටේ' in a or 'කොළඹ' in a)]


def bench_text(model, rows):
    r = {'model': model}
    scores, speeds = [], []
    for ins, inp, ref in rows:
        a, tps = chat(model, ins + ('\n\n' + inp if inp else '')); scores.append(chrf(a, ref)); speeds.append(tps)
    r['chrf'] = round(sum(scores) / len(scores), 1)
    kept = 0
    for ph, sent in PLACEHOLDERS:
        a, tps = chat(model, f'මෙම වාක්‍යය වඩා ආචාරශීලීව නැවත ලියන්න. {ph} වැනි සලකුණු වෙනස් නොකර එලෙසම තබන්න:\n{sent}'); kept += ph in a; speeds.append(tps)
    r['placeholder_preservation'] = round(kept / len(PLACEHOLDERS), 2)
    valid = 0
    for topic in JSON_TASKS:
        a, tps = chat(model, f'Answer ONLY with JSON of the form {{"answer": "..."}} in Sinhala. Question: {topic}'); speeds.append(tps)
        m = re.search(r'\{.*\}', a, re.S)
        try: valid += m is not None and isinstance(json.loads(m.group(0)).get('answer'), str)
        except Exception: pass
    r['valid_first_attempt'] = round(valid / len(JSON_TASKS), 2)
    probes = {}
    for name, p, ok in PROBES:
        a, tps = chat(model, p); probes[name] = {'pass': bool(ok(a)), 'answer': a[:160]}; speeds.append(tps)
    r['probes'] = probes; r['probes_passed'] = sum(v['pass'] for v in probes.values())
    r['tokens_per_second'] = round(sum(speeds) / len(speeds), 1)
    return r


if __name__ == '__main__':
    t0 = time.time(); res = {'started': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}
    if os.environ.get('SKIP_ASR') != '1':
        print('ASR…', flush=True); res['asr'] = bench_asr(); print(res['asr'], flush=True)
        json.dump(res, open(f'{OUT}/results.json', 'w'), indent=2, ensure_ascii=False)
    rows = test_rows(); res['text'] = []
    for m in TEXT_MODELS:
        print(f'TEXT {m}…', flush=True)
        try: res['text'].append(bench_text(m, rows))
        except Exception as e: res['text'].append({'model': m, 'error': repr(e)[:200]})
        print(res['text'][-1].get('chrf'), res['text'][-1].get('tokens_per_second'), flush=True)
        json.dump(res, open(f'{OUT}/results.json', 'w'), indent=2, ensure_ascii=False)
    res['seconds'] = round(time.time() - t0); json.dump(res, open(f'{OUT}/results.json', 'w'), indent=2, ensure_ascii=False)
    print('BENCH DONE', res['seconds'], 's', flush=True)
