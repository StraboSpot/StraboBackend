"""Offline tests for the P7 checks + conversions (no provider calls).

python3 -m unittest discover -s tests -t .   (from voicestations/worker)
"""

import json
import os
import unittest

from vsworker.extract import checks, text as T
from vsworker.vadmap import normalize

HERE = os.path.dirname(__file__)
VOCAB = {'version': 'test-1', 'forms': {
    'measurement.planar_orientation': {
        'feature_type': {'bedding': 'bedding', 'foliation': 'foliation', 'fault': 'fault', 'fracture': 'fracture',
                         'option_13': 'joint', 'contact': 'contact', 'other': 'other'},
        'movement': {'n_side_up': 'N side up', 'ne_side_up': 'NE side up', 'sw_side_up': 'SW side up',
                     'right_lateral': 'right-lateral', 'left_lateral': 'left-lateral', 'other': 'other'},
        'facing': {'upright': 'upright', 'overturned': 'overturned', 'other': 'other'},
        'quality': {'5': '5 - excellent', '4': '4', '3': '3', '2': '2', '1': '1 - poor'}},
    'measurement.linear_orientation': {
        'feature_type': {'slickenlines': 'slickenlines', 'mineral_align': 'mineral alignment', 'fold_hinge': 'fold hinge'},
        'quality': {'5': '5 - excellent', '4': '4', '3': '3', '2': '2', '1': '1 - poor'}}}}


def words_of(s):
    return [{'w': w, 'start': i * 0.5, 'end': i * 0.5 + 0.4, 'p': 0.9} for i, w in enumerate(s.split())]


def job(transcript_text, words=True, earlier=(), fix={'accuracy': 5.0}, conv='rhr'):
    return {'transcript': {'text': transcript_text, 'segments': [], 'words': words_of(transcript_text) if words else []},
            'station': {'best_fix': fix, 'strike_convention': conv}, 'earlier_stations': list(earlier), 'vocab': VOCAB}


def M(**kw):
    m = {'kind': 'planar', 'feature_type': 'bedding', 'source_quote': '', 'strike': None, 'dip': None,
         'dip_direction': None, 'strike_quadrant': None, 'dip_quadrant': None, 'trend': None, 'plunge': None,
         'quality': None, 'movement': None, 'facing': None, 'lies_on': None, 'corrected': [], 'unresolved': [], 'doubts': []}
    m.update(kw)
    return m


def raw(ms=(), **kw):
    r = {'measurements': list(ms), 'rock_descriptions': [], 'samples': [], 'photos': [], 'notes': []}
    r.update(kw)
    return r


def first(p):
    return next(i for i in p['items'] if i['kind'] == 'orientation')


class Flat(unittest.TestCase):
    def test_to_internal(self):
        from vsworker.extract.schema import to_internal
        d = to_internal({'measurements': [{'kind': 'planar', 'feature_type': 'fault', 'source_quote': 'q',
            'numbers': [{'field': 'dip', 'value': 75, 'quote': 'q'}, {'field': 'quality', 'value': 3, 'quote': 'a 3'}],
            'words': [{'field': 'strike_quadrant', 'value': 'N60W', 'quote': 'q'}, {'field': 'movement', 'value': 'normal', 'quote': 'normal'}],
            'lies_on': -1, 'corrected': [], 'unresolved': [], 'doubts': []}],
            'rock_descriptions': [{'text': 't', 'quote': 'q', 'carried_from_station': 0, 'carried_from_quote': ''},
                                  {'text': 't', 'quote': 'q', 'carried_from_station': 2, 'carried_from_quote': 'x'}],
            'samples': [], 'photos': [], 'notes': []})
        m = d['measurements'][0]
        self.assertEqual((m['dip'], m['strike'], m['strike_quadrant'], m['lies_on']), (75, None, 'N60W', None))
        self.assertEqual(m['quality'], {'value': 3, 'quote': 'a 3'})
        self.assertEqual(m['movement'], {'spoken': 'normal', 'quote': 'normal'})
        self.assertEqual([r['carried_from'] for r in d['rock_descriptions']], [None, {'station': 2, 'quote': 'x'}])


class Numbers(unittest.TestCase):
    def n(self, s):
        return set(T.numbers(T.canon_text(s)))

    def test_forms(self):
        self.assertIn(45, self.n('strike 045'))
        self.assertIn(45, self.n('strike zero four five'))
        self.assertIn(45, self.n('strike 0-4-5'))
        self.assertIn(45, self.n('forty five'))
        self.assertEqual(self.n('one fifty'), {150})
        self.assertEqual(self.n('trend two zero five, plunge 74'), {205, 74})

    def test_split_digits_join_only(self):  # check 16
        self.assertEqual(self.n('Strike 0, 5, 2, dip 30'), {52, 30})
        self.assertEqual(T.split_run_digit(T.canon_text('Strike 0, 5, 2'), 0), 52)
        self.assertIsNone(T.split_run_digit(T.canon_text('dip 5'), 5))

    def test_quote_matching_tolerant(self):
        hay = T.canon_text('Strike zero four five, dip 32.')
        self.assertGreaterEqual(T.find(T.canon_text('strike 045'), hay), 0)

    def test_artifacts(self):
        self.assertTrue(T.is_artifact('Thank you.'))
        self.assertTrue(T.is_artifact('[BLANK_AUDIO]'))
        self.assertFalse(T.is_artifact('strike 45'))


class RealStation(unittest.TestCase):
    def setUp(self):
        out, _ = normalize(open(os.path.join(HERE, 'fixtures', 'station01_verbose.json')).read(),
                           open(os.path.join(HERE, 'fixtures', 'station01_server.log')).read().splitlines())
        self.job = {'transcript': out, 'station': {'best_fix': {'accuracy': 6.0}, 'strike_convention': 'rhr'},
                    'earlier_stations': [], 'vocab': VOCAB}
        self.text = out['text']

    def test_station01(self):
        r = raw([M(source_quote='strike 045, dip 32, right hand rule', strike=45, dip=32,
                   quality={'value': 4, 'quote': "I'd call it a four"})],
                photos=[{'caption': 'looking north', 'quote': 'taking a photo looking north'}],
                rock_descriptions=[{'text': 'gray sandstone, medium grained', 'quote': 'bedding in a gray sandstone, medium grained', 'carried_from': None}])
        p, audit = checks.process(r, self.job)
        it = first(p)
        self.assertEqual(it['spot'], {'type': 'planar_orientation', 'strike': 45, 'dip': 32, 'dip_direction': 135,
                                      'feature_type': 'bedding', 'quality': '4'})
        dd = next(v for v in it['values'] if v['field'] == 'dip_direction')
        self.assertEqual(dd['origin'], 'computed')  # "right hand rule" was said
        s = next(v for v in it['values'] if v['field'] == 'strike')
        self.assertEqual(self.text.split()[0], 'Station')
        self.assertIsNotNone(s['audio'])
        self.assertEqual(it['flags'], [])
        self.assertEqual([i['kind'] for i in p['items']], ['orientation', 'note', 'photo'])
        self.assertEqual(p['dropped'], [])
        photo = p['items'][2]
        self.assertGreater(photo['said_at'], 15)  # on the ORIGINAL timeline


class Checks(unittest.TestCase):
    def test_quote_not_found_drops_whole_measurement(self):
        p, _ = checks.process(raw([M(source_quote='strike 99 dip 9', strike=99, dip=9)]), job('Strike 45, dip 32.'))
        self.assertEqual(p['items'], [])
        self.assertEqual(p['dropped'][0]['field'], None)

    def test_value_not_in_quote_drops_only_value(self):
        p, _ = checks.process(raw([M(source_quote='Strike 45, dip 32', strike=45, dip=33)]), job('Strike 45, dip 32. Right hand rule.'))
        it = first(p)
        self.assertEqual(it['spot'].get('strike'), 45)
        self.assertNotIn('dip', it['spot'])
        self.assertEqual(p['dropped'][0]['field'], 'dip')
        self.assertIn('incomplete', [f['code'] for f in it['flags']])

    def test_split_digit_value_dropped_with_reason(self):
        t = 'Strike 0, 5, 2, dip 30, no wait, 38.'
        p, _ = checks.process(raw([M(source_quote='Strike 0, 5, 2, dip 30, no wait, 38', strike=0, dip=38,
                                     corrected=[{'field': 'dip', 'abandoned_value': 30, 'quote': 'dip 30, no wait, 38'}])]), job(t))
        d = p['dropped'][0]
        self.assertEqual((d['field'], d['value']), ('strike', 0))
        self.assertIn('one digit of "52"', d['reason'])
        it = first(p)
        self.assertIn('corrected', [f['code'] for f in it['flags']])
        self.assertIn('Corrected dip', it['spot']['notes'])

    def test_joined_split_digits_kept(self):
        p, _ = checks.process(raw([M(source_quote='Strike 0, 5, 2, dip 38', strike=52, dip=38)]), job('Strike 0, 5, 2, dip 38.'))
        self.assertEqual(first(p)['spot']['strike'], 52)

    def test_assumed_rhr_flagged(self):
        p, _ = checks.process(raw([M(source_quote='Strike 45, dip 32', strike=45, dip=32)]), job('Strike 45, dip 32.'))
        it = first(p)
        self.assertEqual(it['spot']['dip_direction'], 135)
        self.assertEqual(next(v for v in it['values'] if v['field'] == 'dip_direction')['origin'], 'assumed')
        self.assertIn('assumed_convention', [f['code'] for f in it['flags']])

    def test_quadrant_fault_normal_side_up(self):
        t = "There's a fault here. Fault plane is north 60 west, 75 southwest. It's a normal fault."
        p, _ = checks.process(raw([M(feature_type='fault', source_quote='north 60 west, 75 southwest', dip=75,
                                     strike_quadrant='N60W', dip_quadrant='SW',
                                     movement={'spoken': 'normal', 'quote': "It's a normal fault"})]), job(t))
        it = first(p)
        self.assertEqual((it['spot']['strike'], it['spot']['dip_direction'], it['spot']['dip']), (120, 210, 75))
        self.assertEqual((it['spot']['movement'], it['spot']['other_movement']), ('other', 'normal'))
        self.assertEqual(it['suggestions'][0]['value'], 'ne_side_up')
        self.assertEqual(p['dropped'], [])

    def test_computed_dd_never_trusted_from_llm(self):
        t = 'Fault plane is north 60 west, 75 southwest.'
        p, _ = checks.process(raw([M(feature_type='fault', source_quote='north 60 west, 75 southwest', dip=75, dip_direction=210,
                                     strike_quadrant='N60W', dip_quadrant='SW')]), job(t))
        self.assertEqual(p['dropped'][0]['field'], 'dip_direction')  # 210 was computed by the model
        self.assertEqual(first(p)['spot']['dip_direction'], 210)     # code computed it instead

    def test_strike_dd_mismatch(self):  # check 17
        t = 'Strike 45, dip 32, dipping toward 200.'
        p, _ = checks.process(raw([M(source_quote='Strike 45, dip 32, dipping toward 200', strike=45, dip=32, dip_direction=200)]), job(t))
        it = first(p)
        self.assertNotIn('dip_direction', it['spot'])
        self.assertIn('strike_dd_mismatch', [f['code'] for f in it['flags']])

    def test_left_hand_strike_turned_rhr(self):
        # dipping west: 160 + 90 = 250 is west, already right-hand rule (station 3)
        t = 'Contact, strike 160, dip 70 west.'
        p, _ = checks.process(raw([M(feature_type='contact', source_quote='strike 160, dip 70 west', strike=160, dip=70, dip_quadrant='W')]), job(t))
        it = first(p)
        self.assertEqual((it['spot']['strike'], it['spot']['dip_direction']), (160, 250))
        self.assertNotIn('assumed_convention', [f['code'] for f in it['flags']])
        # dipping east: the dip is on the left of 160, so right-hand rule strike is 340
        t = 'Contact, strike 160, dip 70 east.'
        p, _ = checks.process(raw([M(feature_type='contact', source_quote='strike 160, dip 70 east', strike=160, dip=70, dip_quadrant='E')]), job(t))
        it = first(p)
        self.assertEqual((it['spot']['strike'], it['spot']['dip_direction']), (340, 70))

    def test_vocab_fix_and_unknown(self):
        p, audit = checks.process(raw([M(feature_type='joint', source_quote='Strike 60, dip 40', strike=60, dip=40),
                                       M(feature_type='wiggle', source_quote='strike 150 dip 80', strike=150, dip=80)]),
                                  job('Joints. Strike 60, dip 40. Another set strike 150 dip 80.'))
        a, b = [i for i in p['items'] if i['kind'] == 'orientation']
        self.assertEqual(a['spot']['feature_type'], 'option_13')
        self.assertNotIn('feature_type', b['spot'])
        self.assertTrue(any(d['field'] == 'feature_type' and d['value'] == 'wiggle' for d in p['dropped']))

    def test_line_on_plane_nested_and_misfit(self):  # check 7
        t = 'Fault strike 120 dip 75. Slickenlines on it, trend 205, plunge 74. Another line trend 205 plunge 20.'
        ms = [M(feature_type='fault', source_quote='Fault strike 120 dip 75', strike=120, dip=75),
              M(kind='linear', feature_type='slickenlines', source_quote='trend 205, plunge 74', trend=205, plunge=74, lies_on=0),
              M(kind='linear', feature_type='slickenlines', source_quote='trend 205 plunge 20', trend=205, plunge=20, lies_on=0)]
        p, _ = checks.process(raw(ms), job(t))
        plane = first(p)
        self.assertEqual(len(plane['associated']), 2)
        good, bad = plane['associated']
        self.assertNotIn('line_off_plane', [f['code'] for f in good['flags']])
        self.assertIn('line_off_plane', [f['code'] for f in bad['flags']])

    def test_doubt_to_notes(self):
        t = 'Hinge trend 310, plunge 12, but that is a rough guess.'
        p, _ = checks.process(raw([M(kind='linear', feature_type='fold_hinge', source_quote='trend 310, plunge 12', trend=310, plunge=12,
                                     doubts=[{'quote': 'that is a rough guess'}])]), job(t))
        it = first(p)
        self.assertIn('doubt', [f['code'] for f in it['flags']])
        self.assertIn('rough guess', it['spot']['notes'])

    def test_wrong_kind_fields_dropped(self):
        p, _ = checks.process(raw([M(source_quote='strike 45 dip 32 trend 10', strike=45, dip=32, trend=10)]), job('strike 45 dip 32 trend 10, right hand rule'))
        self.assertTrue(any(d['field'] == 'trend' for d in p['dropped']))

    def test_ranges(self):
        p, _ = checks.process(raw([M(source_quote='strike 45 dip 95', strike=45, dip=95)]), job('strike 45 dip 95'))
        self.assertTrue(any(d['field'] == 'dip' and 'must be 0-90' in d['reason'] for d in p['dropped']))

    def test_artifact_not_a_source(self):
        p, _ = checks.process(raw(notes=[{'text': 'thanks', 'quote': 'Thank you.'}]), job('Strike 45, dip 32. Thank you.'))
        self.assertEqual(p['items'], [])

    def test_carried_from(self):
        earlier = [{'station_uuid': 'u1', 'transcript': 'Bedding in a gray sandstone, medium grained.'}]
        t = 'Same sandstone as before, strike 50 dip 30.'
        ok, _ = checks.process(raw(rock_descriptions=[{'text': 'same sandstone', 'quote': 'Same sandstone as before',
                                                        'carried_from': {'station': 1, 'quote': 'gray sandstone, medium grained'}}]), job(t, earlier=earlier))
        bad, _ = checks.process(raw(rock_descriptions=[{'text': 'same sandstone', 'quote': 'Same sandstone as before',
                                                         'carried_from': {'station': 1, 'quote': 'red shale'}}]), job(t, earlier=earlier))
        self.assertEqual(ok['items'][0]['carried']['station_uuid'], 'u1')
        self.assertIn('carried', [f['code'] for f in ok['items'][0]['flags']])
        self.assertNotIn('carried', bad['items'][0])

    def test_sample_label_must_be_spoken(self):
        t = 'Taking sample JA-01 from the contact.'
        p, _ = checks.process(raw(samples=[{'label': 'JA01', 'description': 'contact', 'quote': 'Taking sample JA-01 from the contact'},
                                           {'label': 'XY99', 'description': 'contact', 'quote': 'Taking sample JA-01 from the contact'}]), job(t))
        a, b = p['items']
        self.assertEqual(a['spot']['sample_id_name'], 'JA01')
        self.assertNotIn('sample_id_name', b['spot'])

    def test_gps_flags(self):
        p, _ = checks.process(raw(), job('x', fix={'accuracy': 34.0}))
        self.assertEqual(p['station_flags'][0]['code'], 'gps_poor')
        p, _ = checks.process(raw(), job('x', fix=None))
        self.assertEqual(p['station_flags'][0]['code'], 'no_location')

    def test_text_only_fallback(self):
        p, _ = checks.process(raw([M(source_quote='Strike 45, dip 32', strike=45, dip=32)]), job('Strike 45, dip 32. Right hand rule.', words=False))
        it = first(p)
        self.assertEqual(it['spot']['strike'], 45)
        self.assertIsNone(it['words'])

    def test_duplicate_notes_merged(self):
        t = 'Contact between dark shale and hard rock. Strike 45, dip 32.'
        p, a = checks.process(raw(rock_descriptions=[{'text': 'x', 'quote': 'Contact between dark shale and hard rock.', 'carried_from': None}],
                                  notes=[{'text': 'y', 'quote': 'contact between dark shale and hard rock'}]), job(t))
        self.assertEqual(len(p['items']), 1)
        self.assertTrue(any(e['check'] == 'duplicate' for e in a['events']))

    def test_proposal_is_json(self):
        p, a = checks.process(raw([M(source_quote='Strike 45, dip 32', strike=45, dip=32)]), job('Strike 45, dip 32.'))
        json.dumps(p), json.dumps(a)


if __name__ == '__main__':
    unittest.main()
