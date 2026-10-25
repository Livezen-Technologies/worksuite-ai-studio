"""Critical-token accuracy for Whisper Sinhala: numbers in held-out SinhaSpeech test clips.

Scored on number VALUES: reference and transcript both go through numfix.normalise (spoken numbers → digits), so
"දහයක්" and "10ක්" agree. The article/pronoun එක / එකක් is not a number and is not scored (it was 33 of 62
"misses" in the first, word-matching version, which therefore read 70% instead of ~37%).

Picks test-split clips whose reference contains digits or Sinhala number words, transcribes them through the
aistudio-asr service, and scores the share of those reference tokens found in the transcript (multiset match).
Adds `critical_token_acc` (and its clip/token counts) to the ASR block of results.json.
"""
import json, os, re, sys, time
from collections import Counter
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from engine_bench import decode, asr, norm, OUT

NUM = set('''බින්දුව එක එකක් දෙක දෙකක් තුන තුනක් හතර හතරක් පහ පහක් හය හයක් හත හතක් අට අටක් නවය නවයක් දහය දහයක්
එකොළහ දොළහ දහතුන දාහතර පහළොව දහසය දාහත දහඅට දහනවය විස්ස විසි තිහ තිස් හතළිහ හතළිස් පනහ පනස් හැට හැත්තෑව හැත්තෑ
අසූව අසූ අනූව අනූ සියය සිය දහස දාහ දහසක් ලක්ෂ ලක්ෂය මිලියන කෝටි පළමු දෙවන තෙවන සියයට'''.split())

def fix(text):
    """Post-processing under test (identity unless NUMFIX is importable)."""
    try:
        from numfix import normalise
        return normalise(text)
    except ImportError:
        return text


def values(text):
    from numfix import normalise
    return [re.match(r'\d+', w).group(0) for w in norm(normalise(text)) if re.match(r'\d', w)]


def crit(tokens):
    return [t for t in tokens if t in NUM or re.fullmatch(r'\d+([.,]\d+)?', t)]

def main(n=int(os.environ.get('CRIT_CLIPS', '120'))):
    import pyarrow.parquet as pq
    pf = pq.ParquetFile('/srv/sinhala-data/raw/sinhaspeech_asr/hf/data/stratified_v6/test.parquet')
    picked = []
    for b in pf.iter_batches(batch_size=500, columns=['audio', 'text']):
        for audio, ref in zip(b.column(0).to_pylist(), b.column(1).to_pylist()):
            if values(ref): picked.append((audio, ref))
        if len(picked) >= n: break
    hit = total = clips = 0; pairs = []
    for i, (audio, ref) in enumerate(picked[:n]):
        pcm = decode(audio.get('bytes') if isinstance(audio, dict) else audio)
        if pcm.size < 1600: continue
        hyp = asr(pcm)
        pairs.append({'ref': ref, 'hyp': hyp})
        want = Counter(values(ref)); got = Counter(values(hyp))
        hit += sum(min(c, got[t]) for t, c in want.items()); total += sum(want.values()); clips += 1
        if i % 20 == 0: print(f'  crit {i}/{n} acc so far {hit / max(1, total):.3f}', flush=True)
    json.dump(pairs, open(f'{OUT}/critical_pairs.json', 'w'), ensure_ascii=False, indent=1)
    path = f'{OUT}/results.json'
    res = json.load(open(path)) if os.path.exists(path) else {}
    res.setdefault('asr', {}).update({'critical_token_acc': round(hit / max(1, total), 4), 'critical_clips': clips, 'critical_tokens': total})
    json.dump(res, open(path, 'w'), indent=2, ensure_ascii=False)
    print('CRIT DONE', res['asr'], flush=True)

if __name__ == '__main__':
    main()
