"""Turn a trained LoRA adapter into an Ollama model: merge into the base, convert to GGUF, quantize.

Run on the AI Studio server by LlmFinalizeService with the shared toolkit (/opt/aistudio-llm):
  finalize_llm.py --adapter adapter.tar.gz --base Qwen/Qwen2.5-3B-Instruct --out /srv/aistudio-models/<slug> --quant Q4_K_M
Prints "PHASE: …" lines for the Training page and a final "RESULT {json}" line. Base models are cached in HF_HOME
(/srv/aistudio-hf); the merged weights and the f16 GGUF are deleted once the quantized file exists (disk).
"""
import argparse, hashlib, json, os, shutil, subprocess, sys, tarfile, time

TOOLS = os.environ.get('AISTUDIO_LLM_TOOLS', '/opt/aistudio-llm')


def phase(msg):
    print(f'PHASE: {msg}', flush=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--adapter', required=True)
    ap.add_argument('--base', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--quant', default='Q4_K_M')
    a = ap.parse_args()
    t0 = time.time()
    os.makedirs(a.out, exist_ok=True)
    work = os.path.join(a.out, 'work')
    shutil.rmtree(work, ignore_errors=True); os.makedirs(work)

    phase('unpacking the adapter')
    with tarfile.open(a.adapter) as t:
        t.extractall(work, filter='data') if sys.version_info >= (3, 12) else t.extractall(work)
    adapter = os.path.join(work, 'adapter')
    if not os.path.isfile(os.path.join(adapter, 'adapter_config.json')):
        raise SystemExit('the upload holds no LoRA adapter (adapter/adapter_config.json missing)')

    import torch
    from peft import PeftModel
    from transformers import AutoModelForCausalLM, AutoTokenizer
    phase(f'loading the base model {a.base} (first use downloads it)')
    base = AutoModelForCausalLM.from_pretrained(a.base, torch_dtype=torch.float16, low_cpu_mem_usage=True)
    tok = AutoTokenizer.from_pretrained(adapter if os.path.exists(os.path.join(adapter, 'tokenizer_config.json')) else a.base)
    phase('merging the adapter into the base')
    merged = PeftModel.from_pretrained(base, adapter).merge_and_unload()
    mdir = os.path.join(work, 'merged')
    merged.save_pretrained(mdir, safe_serialization=True); tok.save_pretrained(mdir)
    del merged, base

    phase('converting to GGUF (f16)')
    f16 = os.path.join(work, 'model-f16.gguf')
    subprocess.run([sys.executable, os.path.join(TOOLS, 'llama.cpp', 'convert_hf_to_gguf.py'), mdir, '--outtype', 'f16', '--outfile', f16],
                   check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    phase(f'quantizing to {a.quant}')
    out = os.path.join(a.out, f'model-{a.quant.lower()}.gguf')
    env = dict(os.environ, LD_LIBRARY_PATH=os.path.join(TOOLS, 'llama.cpp', 'bin'))
    subprocess.run([os.path.join(TOOLS, 'llama.cpp', 'bin', 'llama-quantize'), f16, out, a.quant],
                   check=True, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    shutil.rmtree(work, ignore_errors=True)
    os.chmod(out, 0o644)

    phase('hashing')
    h = hashlib.sha256()
    with open(out, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''): h.update(chunk)
    print('RESULT ' + json.dumps({'gguf': out, 'size_bytes': os.path.getsize(out), 'sha256': h.hexdigest(), 'quant': a.quant,
                                  'base': a.base, 'minutes': round((time.time() - t0) / 60, 1)}), flush=True)


if __name__ == '__main__':
    main()
