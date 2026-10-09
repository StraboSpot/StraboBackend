"""python3 -m unittest discover -s tests  (from voicestations/worker)"""

import json
import os
import unittest

from vsworker.vadmap import normalize, parse_spans, to_original

HERE = os.path.dirname(__file__)


def fixture(name):
    with open(os.path.join(HERE, 'fixtures', name), encoding='utf-8') as f:
        return f.read()


SPANS = [(1.85, 3.21, 0.00, 1.36), (4.02, 7.72, 1.56, 5.26), (7.74, 14.89, 5.46, 12.61), (15.03, 18.79, 12.81, 16.57)]


class VadMap(unittest.TestCase):
    def test_parse(self):
        self.assertEqual(parse_spans(fixture('station01_server.log').splitlines()), SPANS)

    def test_inside_spans(self):
        self.assertAlmostEqual(to_original(0.0, SPANS), 1.85)
        self.assertAlmostEqual(to_original(2.0, SPANS), 4.46)
        self.assertAlmostEqual(to_original(15.68, SPANS), 17.90)

    def test_gap_interpolates(self):
        # halfway through the 1.36 -> 1.56 overlap gap = halfway 3.21 -> 4.02
        self.assertAlmostEqual(to_original(1.46, SPANS), 3.615)

    def test_outside(self):
        self.assertAlmostEqual(to_original(17.0, SPANS), 19.22)
        self.assertEqual(to_original(5.0, []), 5.0)

    def test_monotonic(self):
        ts = [i / 100 for i in range(0, 1700)]
        out = [to_original(t, SPANS) for t in ts]
        self.assertTrue(all(b >= a for a, b in zip(out, out[1:])))


class Normalize(unittest.TestCase):
    def test_real_whisper_output(self):
        out, method = normalize(fixture('station01_verbose.json'), fixture('station01_server.log').splitlines())
        self.assertEqual(method, 'vad_remapped')
        self.assertTrue(out['text'].startswith('Station one, bedding in a gray sandstone'))
        self.assertNotIn('\n', out['text'])
        self.assertEqual(len(out['segments']), 4)
        w = {x['w'].strip(',.'): x for x in out['words']}
        self.assertIn('bedding', w)   # whisper tokens 'bed' + 'ding' joined
        self.assertIn('045', w)       # '0' + '45'
        # words now sit on the original timeline, next to their segments
        self.assertAlmostEqual(w['north']['start'], 17.90)
        self.assertAlmostEqual(w['Station']['start'], 1.94)
        self.assertGreaterEqual(w['strike']['start'], out['segments'][1]['start'] - 0.3)
        for x in out['words']:
            self.assertTrue(x['end'] >= x['start'])
            self.assertTrue(x['p'] is None or 0 <= x['p'] <= 1)

    def test_no_mapping_falls_back_to_segment_shift(self):
        out, method = normalize(fixture('station01_verbose.json'), [])
        self.assertEqual(method, 'segment_shifted')
        self.assertAlmostEqual(out['words'][0]['start'], out['segments'][0]['start'])

    def test_word_split_across_segments_rejoined(self):
        # prod 10-09: "grained" came back as segment "... medium gra" + "ined with ..."
        raw = json.dumps({'segments': [
            {'start': 0.86, 'end': 4.5, 'text': ' It is medium gra', 'words': [
                {'word': ' It', 'start': 0.86, 'end': 1.1, 'probability': 0.9},
                {'word': ' is', 'start': 1.1, 'end': 1.3, 'probability': 0.9},
                {'word': ' medium', 'start': 3.78, 'end': 4.1, 'probability': 0.99},
                {'word': ' gra', 'start': 4.27, 'end': 4.5, 'probability': 0.89}]},
            {'start': 4.5, 'end': 6.76, 'text': 'ined with shale.', 'words': [
                {'word': 'ined', 'start': 4.5, 'end': 4.82, 'probability': 0.9998},
                {'word': ' with', 'start': 4.82, 'end': 5.14, 'probability': 0.6},
                {'word': ' shale.', 'start': 6.1, 'end': 6.76, 'probability': 0.99}]},
            {'start': 7.52, 'end': 9.0, 'text': ' Bedding.', 'words': [
                {'word': ' Bedding.', 'start': 7.52, 'end': 9.0, 'probability': 0.96}]}]})
        out, _ = normalize(raw, [])
        self.assertEqual(out['text'], 'It is medium grained with shale. Bedding.')
        self.assertEqual([x['w'] for x in out['words']],
                         ['It', 'is', 'medium', 'grained', 'with', 'shale.', 'Bedding.'])
        g = out['words'][3]
        self.assertEqual((g['start'], g['end'], g['p']), (4.27, 4.82, 0.89))
        self.assertEqual(len(out['segments']), 3)

    def test_silence(self):
        out, method = normalize(json.dumps({'text': '', 'segments': []}), [])
        self.assertEqual((out, method), ({'text': '', 'segments': [], 'words': []}, 'none'))


if __name__ == '__main__':
    unittest.main()
