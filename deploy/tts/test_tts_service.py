"""Tests for the text-to-speech service: `python3 -m unittest deploy/tts/test_tts_service.py` (or run directly).

Synthesis tests run when espeak-ng is installed (CI installs it); the rest need nothing beyond Python.
"""
import importlib.util
import json
import os
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
TMP = tempfile.mkdtemp()
CATALOG = os.path.join(TMP, 'voices.json')
LEXICON = os.path.join(TMP, 'lexicon.json')
os.environ['TTS_VOICES'] = CATALOG
os.environ['TTS_LEXICON'] = LEXICON
json.dump({
    'withdrawn': {'label': 'Withdrawn', 'language': 'si', 'runtime': 'espeak', 'espeak_voice': 'si', 'approved': True, 'revoked': True, 'speed': [1, 1]},
    'research': {'label': 'Research only', 'language': 'si', 'runtime': 'espeak', 'espeak_voice': 'si', 'approved': False, 'speed': [1, 1]},
    'espeak-si': {'label': 'cannot shadow a built-in', 'runtime': 'piper', 'path': '/nope', 'approved': True},
    '../bad': {'label': 'bad key', 'runtime': 'espeak', 'approved': True},
}, open(CATALOG, 'w'))
json.dump({'WSAI': 'වර්ක්සුට් ඒ අයි'}, open(LEXICON, 'w', encoding='utf-8'), ensure_ascii=False)

spec = importlib.util.spec_from_file_location('tts', os.path.join(HERE, 'tts_service.py'))
tts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tts)


class Numbers(unittest.TestCase):
    def test_sinhala_numbers_use_lakh_and_crore(self):
        cases = {7: 'හත', 21: 'විසිඑක', 50: 'පනහ', 100: 'සියය', 250: 'දෙසිය පනහ', 2026: 'දෙදහස් විසිහය',
                 100000: 'එක්ලක්ෂය', 1250000: 'දොළොස්ලක්ෂ පනස්දහස', 25000000: 'දෙකෝටි පනස්ලක්ෂය', 0: 'බිංදුව'}
        for n, words in cases.items():
            self.assertEqual(tts.si_number(n), words, n)

    def test_normalizer_expands_currency_dates_times_and_uses_the_lexicon(self):
        out, notes = tts.normalize('WSAI: රු. 1,250.50 ගෙව්වා 2026-11-09 10:30 ට', 'si')
        self.assertIn('වර්ක්සුට් ඒ අයි', out)
        self.assertIn('දහස් දෙසිය පනහ දශම පහ බිංදුව රුපියල්', out)
        self.assertIn('දෙදහස් විසිහය', out)
        self.assertIn('දහය යි තිහ', out)
        self.assertTrue(any('date' in n for n in notes))

    def test_english_in_sinhala_text_is_flagged(self):
        _, notes = tts.normalize('ආයුබෝවන් hello', 'si')
        self.assertTrue(any('English' in n for n in notes))


class Voices(unittest.TestCase):
    def test_catalog_rules(self):
        keys = {v['value']: v for v in tts.voices()}
        self.assertNotIn('../bad', keys)
        if tts.ESPEAK:
            self.assertEqual(keys['espeak-si']['runtime'], 'espeak', 'a catalog entry never shadows a built-in')

    def test_withdrawn_and_unapproved_voices_are_refused(self):
        with self.assertRaises(PermissionError):
            tts.synthesize('withdrawn', 'x')
        with self.assertRaises(PermissionError):
            tts.synthesize('research', 'x')
        with self.assertRaises(LookupError):
            tts.synthesize('nope', 'x')


@unittest.skipUnless(tts.ESPEAK, 'espeak-ng is not installed')
class Synthesis(unittest.TestCase):
    def test_sinhala_wav_and_speed_range(self):
        audio, duration, normalized, _, speed = tts.synthesize('espeak-si', 'ආයුබෝවන් 25', speed=9)
        self.assertEqual(audio[:4], b'RIFF')
        self.assertGreater(duration, 0.3)
        self.assertIn('විසිපහ', normalized)
        self.assertEqual(speed, 2.0, 'speed is kept within the voice range')

    @unittest.skipUnless(tts.FFMPEG, 'ffmpeg is not installed')
    def test_mp3(self):
        audio = tts.synthesize('espeak-en', 'Hello there.', fmt='mp3')[0]
        self.assertTrue(audio[:3] == b'ID3' or audio[0] == 0xFF)


if __name__ == '__main__':
    unittest.main()
