"""Side-by-side ASR benchmark of two faster-whisper models on the same held-out clips, scored the same way.

Number accuracy: values (numfix on both sides, article එක not counted) on test clips with numbers (critical_bench).
WER/CER clean + noisy (10 dB) on 100 test clips, silence rate on 60 synthetic clips (engine_bench). Greedy decoding.
"""
import json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import numpy as np
from faster_whisper import WhisperModel
import engine_bench, critical_bench
from numfix import normalise

MODELS = {"current": "/srv/sinhala-data/models/whisper-small-sinhala-ct2-int8",
          "numbers_ft": "/srv/sinhala-data/models/whisper-small-sinhala-numbers-ct2-int8"}
out = {}
for name, path in MODELS.items():
    m = WhisperModel(path, device="cpu", compute_type="int8", cpu_threads=4)
    def asr(pcm, m=m):
        if pcm.size == 0 or float(np.abs(pcm).max()) < 0.005: return ""
        segs, _ = m.transcribe(pcm.astype(np.float32), language="si", beam_size=1, vad_filter=True)
        return normalise(" ".join(s.text for s in segs if any(c.isalnum() for c in s.text)).strip())
    engine_bench.asr = asr; critical_bench.asr = asr; engine_bench.ASR_GAP = 0
    os.environ["ASR_GAP"] = "0"
    r = engine_bench.bench_asr(100)
    print(name, "general", r, flush=True)
    critical_bench.OUT = "/tmp/cmp_" + name; os.makedirs(critical_bench.OUT, exist_ok=True)
    critical_bench.main(120)
    r.update(json.load(open(critical_bench.OUT + "/results.json"))["asr"])
    out[name] = r
    print(name, json.dumps(r), flush=True)
json.dump(out, open("/srv/sinhala-data/bench/compare_numbers_ft.json", "w"), indent=2)
print("COMPARE DONE", json.dumps(out), flush=True)
