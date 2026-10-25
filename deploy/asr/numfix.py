"""Sinhala inverse text normalisation for transcripts: spoken numbers become digits.

  "වෑංජන දහයක්"        → "වෑංජන 10ක්"
  "පනස් හයේ මැතිවරණය"   → "56 මැතිවරණය"   (case endings after a number are kept: 56ේ is not written, the ending is dropped)
  "10 ක්"               → "10ක්"            (Whisper often splits the indefinite ending from a digit)
  "රුපියල් දෙදහස් පන්සියයක්" → "රුපියල් 2500ක්"

Bare එක / එකක් stay words: in speech they are far more often the article or pronoun ("the one", "that") than the number 1.
Only whole tokens are converted, so words that merely contain a number word (පහර, දෙකට්ටු) are untouched.
"""
import re

UNITS = {
    'බින්දුව': 0, 'එක': 1, 'දෙක': 2, 'තුන': 3, 'හතර': 4, 'පහ': 5, 'හය': 6, 'හත': 7, 'අට': 8, 'නවය': 9, 'නමය': 9,
}
TEENS = {
    'දහය': 10, 'දහ': 10, 'එකොළහ': 11, 'දොළහ': 12, 'දහතුන': 13, 'දාහතර': 14, 'දහහතර': 14, 'පහළොව': 15, 'පසළොස': 15,
    'දහසය': 16, 'දාසය': 16, 'දාහත': 17, 'දහහත': 17, 'දහඅට': 18, 'දාඅට': 18, 'දහනවය': 19, 'දහනමය': 19,
}
TENS = {  # standalone value / combining prefix
    'විස්ස': 20, 'විසි': 20, 'තිහ': 30, 'තිස්': 30, 'හතළිහ': 40, 'හතළිස්': 40, 'පනහ': 50, 'පනස්': 50,
    'හැට': 60, 'හැත්තෑව': 70, 'හැත්තෑ': 70, 'අසූව': 80, 'අසූ': 80, 'අනූව': 90, 'අනූ': 90,
}
# multiplier prefixes for hundreds / thousands: "දෙ"සිය, "තුන්"දහස …
PREFIX = {'එක්': 1, 'දෙ': 2, 'තුන්': 3, 'හාර': 4, 'හතර': 4, 'පන්': 5, 'හය': 6, 'හත්': 7, 'අට': 8, 'නව': 9, 'නම': 9}
HUNDRED = ('සියය', 'සිය', 'සීය')
THOUSAND = ('දහස', 'දහස්', 'දාහ', 'දාස්')
LAKH = ('ලක්ෂය', 'ලක්ෂ', 'ලක්ෂයක්')
# endings that may follow a number word: indefinite ක්, dative ට, genitive ේ / යේ / ගේ, emphatic යි, ordinal වෙනි / වන
ENDINGS = ('ක්', 'කට', 'යි', 'ට', 'යේ', 'ගේ', 'ේ', 'වෙනි', 'වන', 'වැනි')
KEEP_ENDINGS = ('ක්', 'කට', 'ට', 'වෙනි', 'වන', 'වැනි')  # written after the digit: 10ක්, 3ට, 2වෙනි


def _strip(tok):
    """(stem, ending) — the longest known ending removed."""
    for e in sorted(ENDINGS, key=len, reverse=True):
        if tok.endswith(e) and len(tok) > len(e) + 1:
            return tok[: -len(e)], e
    return tok, ''


def _stem_value(stem):
    """Value of one number word (no ending) or None. Handles teens, tens, units and prefixed hundreds/thousands."""
    for table in (UNITS, TEENS, TENS):
        if stem in table:
            return table[stem]
    for word, scale in ((HUNDRED, 100), (THOUSAND, 1000), (LAKH, 100000)):
        for w in word:
            if stem == w:
                return scale
            if stem.endswith(w):
                head = stem[: -len(w)]
                if head in PREFIX:
                    return PREFIX[head] * scale
                v = _stem_value(head)
                if v is not None and 0 < v < 100:
                    return v * scale
    # tens prefix glued to a unit: විසිඑක, තිස්පහ
    for t, tv in TENS.items():
        if stem.startswith(t) and stem != t:
            rest = stem[len(t):]
            if rest in UNITS:
                return tv + UNITS[rest]
    return None


def _value(tok):
    stem, end = _strip(tok)
    v = _stem_value(stem)
    if v is None and end:
        v = _stem_value(tok)  # the word itself ends in what looks like an ending (දහය → දහ + ය is not an ending)
        end = '' if v is not None else end
    # vowel-final stems lose their last letter before an ending: දහය + ක් → දහයක් (kept) but පහ + ක් → පහක්
    return v, end


def normalise(text: str) -> str:
    out = re.sub(r'(\d)\s+(ක්|ට|වෙනි|වන|වැනි)(?=\s|$|[.,!?])', r'\1\2', text)  # "10 ක්" → "10ක්"
    toks = out.split(' ')
    res, i = [], 0
    while i < len(toks):
        raw = toks[i]
        core = raw.rstrip('.,!?')
        punct = raw[len(core):]
        v, end = _value(core) if core else (None, '')
        if v is None or core in ('එක', 'එකක්', 'එකට', 'එකේ') or (v == 1 and not end and core not in UNITS):
            res.append(raw); i += 1; continue
        total, last_end, last_punct, j = v, end, punct, i + 1
        # compound numbers across words: "දෙදහස් පන්සියයක්", "පනස් හයේ", "සිය විස්ස"
        while j < len(toks) and not last_punct and not last_end:
            nxt = toks[j].rstrip('.,!?')
            nv, nend = _value(nxt)
            if nv is None or nv >= total or (total % 10 == 0 and nv >= 10 and total < 100):
                if nv is not None and total in TENS.values() and nv < 10 and total < 100:
                    pass
                else:
                    break
            if total < 100 and nv >= 10:
                break
            total += nv; last_end = nend; last_punct = toks[j][len(nxt):]; j += 1
        if total == 1 and j == i + 1 and not last_end:
            res.append(raw); i += 1; continue
        res.append(f"{total}{last_end if last_end in KEEP_ENDINGS else ''}{last_punct}")
        i = j
    return ' '.join(res)


if __name__ == '__main__':
    for s in ['වෑංජන දහයක් ගෙනල්ලා', 'පනස් හයේ මැතිවරණය', 'දාන වැංජන 10 ක් ගෙනා', 'රුපියල් දෙදහස් පන්සියයක් දෙන්න',
              'පන්සිල් පද පහ රකින්න', 'තව එකක් කිය', 'ඒක මම කියන', 'පහරක් ගැහුවා', 'විසිපහ වෙනි දා', 'තුන්වෙනි පාර']:
        print(s, '→', normalise(s))
