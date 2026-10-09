"""Self-test: a made-up trial with known answers, end to end (sheet -> converter
-> scorer). It must find exactly the planted errors (answer-key point 4,
scoring page points 2-5). `python -m vsworker.score selftest`
"""

import copy
import os
import tempfile

from . import convert as C
from . import scorer
from . import sheets as S

VOCAB = {'version': 't', 'forms': {
    'measurement.planar_orientation': {'feature_type': {'bedding': 'bedding', 'option_13': 'joint', 'fault': 'fault'}},
    'measurement.linear_orientation': {'feature_type': {'slickenlines': 'slickenlines', 'fold_hinge': 'fold hinge'}}}}


def val(ref, field, final, action='unchanged', proposed=None, card='A'):
    v = {'ref': ref, 'field': field, 'action': action, 'card': card}
    if action != 'added':
        v['proposed'] = final if proposed is None else proposed
    if action != 'removed':
        v['final'] = final
    return v


def counts(record):
    n = {'proposed': 0, 'unchanged': 0, 'edited': 0, 'removed': 0, 'added': 0, 'flags': len(record.get('flags', []))}
    for v in record['values']:
        if v['action'] != 'added':
            n['proposed'] += 1
        n[v['action']] += 1
    return n


def plane(ref, strike, dip, dd, quote, ft='bedding', q=None, assoc=()):
    spot = {'type': 'planar_orientation', 'strike': strike, 'dip': dip, 'dip_direction': dd, 'feature_type': ft}
    if q is not None:
        spot['quality'] = q
    return {'ref': ref, 'kind': 'orientation', 'spot': spot, 'quote': quote, 'values': [], 'associated': list(assoc)}


def line(ref, trend, plunge, quote, ft='slickenlines'):
    return {'ref': ref, 'kind': 'orientation', 'quote': quote, 'values': [], 'associated': [],
            'spot': {'type': 'linear_orientation', 'trend': trend, 'plunge': plunge, 'feature_type': ft}}


def station(uuid, upk, name, start, items, values, spots, recorded=30.0, review=50.0):
    record = {'format': 1, 'spots': [{'card': 'A', 'spot_id': f'9{uuid[-3:]}', 'name': {'final': name}}],
              'values': values, 'flags': []}
    return {'station_uuid': uuid, 'userpkey': upk, 'spot_name': name, 'started_at': start,
            'tz_offset_minutes': -300, 'outcome': 'confirmed', 'recorded_seconds': recorded, 'review_seconds': review,
            'counts': counts(record), 'record': record, 'proposal': {'items': items},
            'spots': {f'9{uuid[-3:]}': spots}}


def ospot(od, samples=None):
    return {'type': 'Feature', 'properties': {'orientation_data': od, 'samples': samples}}


def fixture():
    r1_items = [plane('m1', 45, 32, 135, 'bedding strike zero four five dip thirty two quality four', q=4,
                      assoc=[line('l1', 120, 15, 'slickenlines trend one twenty plunge fifteen')]),
                {'ref': 's1', 'kind': 'sample', 'quote': 'sample J A twelve', 'values': [], 'spot': {}},
                line('l2', 300, 20, 'and a fold hinge three hundred twenty', ft='fold_hinge'),
                line('l3', 10, 10, 'scratch that ten ten')]
    r1_vals = [val('m1', 'strike', 45), val('m1', 'dip', 33, 'edited', 32), val('m1', 'dip_direction', 135),
               val('m1', 'quality', 4), val('m1', 'feature_type', 'bedding'),
               val('l1', 'trend', 120), val('l1', 'plunge', 15), val('l1', 'feature_type', 'slickenlines'),
               val('s1', 'sample_id_name', 'ja 12'),
               val('l2', 'trend', 300), val('l2', 'plunge', 20), val('l2', 'feature_type', 'fold_hinge'),
               val('l3', 'trend', None, 'removed', 10), val('l3', 'plunge', None, 'removed', 10),
               val('h1', 'strike', 200, 'added'), val('h1', 'dip', 50, 'added'), val('h1', 'dip_direction', 290, 'added')]
    r1_spot = ospot([{'type': 'planar_orientation', 'strike': 45, 'dip': 33, 'dip_direction': 135, 'quality': 4,
                      'feature_type': 'bedding', 'associated_orientation': [
                          {'type': 'linear_orientation', 'trend': 120, 'plunge': 14, 'feature_type': 'slickenlines'}]},
                     {'type': 'linear_orientation', 'trend': 300, 'plunge': 20, 'feature_type': 'fold_hinge'},
                     {'type': 'planar_orientation', 'strike': 200, 'dip': 50, 'dip_direction': 290}],
                    [{'sample_id_name': 'ja 12'}])
    r2_vals = [val('m1', 'strike', 10), val('m1', 'dip', 40), val('m1', 'dip_direction', 280),
               val('m1', 'feature_type', 'option_13'), val('l9', 'trend', 200), val('l9', 'plunge', 60)]
    r3_vals = [val('m1', 'strike', 90), val('m1', 'dip', 20), val('m1', 'dip_direction', 180),
               val('m1', 'quality', 4), val('m1', 'feature_type', 'fault')]
    sts = [
        station('00000000-0000-4000-8000-000000000001', 1, 'V-01', '2026-10-12T15:00:00.000Z', r1_items, r1_vals,
                r1_spot),
        station('00000000-0000-4000-8000-000000000002', 1, 'V-02', '2026-10-12T15:30:00.000Z',
                [plane('m1', 10, 40, 280, 'joint ten forty two eighty', ft='option_13'),
                 line('l9', 200, 60, 'lineation two hundred sixty')], r2_vals, None,
                recorded=40.0, review=20.0),
        station('00000000-0000-4000-8000-000000000003', 2, 'D-01', '2026-10-12T16:00:00.000Z',
                [plane('m1', 90, 20, 180, 'fault ninety twenty', ft='fault', q=4)], r3_vals,
                ospot([{'type': 'planar_orientation', 'strike': 90, 'dip': 20, 'dip_direction': 180, 'quality': 4,
                        'feature_type': 'fault'}])),
        station('00000000-0000-4000-8000-000000000004', 2, 'D-02', '2026-10-12T16:30:00.000Z',
                [plane('m1', 1, 2, 91, 'one two')], [val('m1', 'strike', 1)], None),
    ]
    export = {'generated_at': '2026-10-12T23:00:00Z', 'vocab': VOCAB,
              'testers': [{'userpkey': 1, 'email': 'claire@example.org', 'name': 'Claire'},
                          {'userpkey': 2, 'email': 'doug@example.org', 'name': 'Doug'}],
              'stations': sts + [dict(copy.deepcopy(sts[3]), station_uuid='00000000-0000-4000-8000-000000000005',
                                      outcome='discarded')],
              'stopwatch': [{'userpkey': 1, 'project_id': '7', 'dataset_id': '8',
                             'spots': [{'id': '1', 'name': 'SW-1', 'n_measurements': 2}]},
                            {'userpkey': 2, 'project_id': '7', 'dataset_id': '9',
                             'spots': [{'id': '2', 'name': 'SW-9', 'n_measurements': 1}]}]}
    return export


def fill(ws, spot, rows, rename=None):
    """Write measurement rows under the Spot's header row (inserting as needed)."""
    col = S.COL
    for r in range(S.FIRST_ROW + 1, ws.max_row + 1):
        if ws.cell(row=r, column=col['Spot']).value == spot and ws.cell(row=r, column=col['Recording id']).value:
            if rename:
                ws.cell(row=r, column=col['Spot'], value=rename)
            ws.insert_rows(r + 1, amount=len(rows))
            for i, values in enumerate(rows):
                for k, v in values.items():
                    ws.cell(row=r + 1 + i, column=col[k], value=v)
            return
    raise AssertionError(f'no Spot {spot}')


def write_sheets(export, d):
    out = S.outings(export)
    wb1 = S.build(export, 1, '2026-10-12', out[(1, '2026-10-12')])
    ws = wb1[S.VOICE]
    fill(ws, 'V-01', [
        {'#': 1, 'Kind': 'Plane', 'Feature type': 'bedding', 'Strike': 45, 'Dip': 32, 'Quality': 4},
        {'#': 2, 'Kind': 'Line', 'Feature type': 'slickenlines', 'Trend': 120, 'Plunge': 10, 'Lies on #': 1},
        {'#': 3, 'Kind': 'Sample', 'Sample label': 'JA-12'},
        {'#': 4, 'Kind': 'Plane', 'Strike': 200, 'Dip': 55, 'Dip direction': 290},
        {'#': 5, 'Kind': 'Plane', 'Feature type': 'bedding', 'Dip': 5, 'Dip direction': 10},
    ])
    fill(ws, 'V-02', [{'#': 1, 'Kind': 'Plane', 'Feature type': 'joint', 'As written': 'N10E 40W'},
                      {'#': 2, 'Kind': 'Line', 'Trend': 20, 'Plunge': 6}], rename='V-02 renamed')
    sw = wb1[S.STOPWATCH]
    sw.append(['2026-10-12', 'SW-1', '2:00', None])
    wb1.save(os.path.join(d, 'claire.xlsx'))
    wb2 = S.build(export, 2, '2026-10-12', [s for s in out[(2, '2026-10-12')] if s['spot_name'] == 'D-01'])
    # the notebook writes the other end of the strike line (270 for 90): the same plane
    fill(wb2[S.VOICE], 'D-01', [{'#': 1, 'Kind': 'Plane', 'Feature type': 'fault', 'Strike': 270, 'Dip': 20,
                                 'Dip direction': 180, 'Quality': 3}])
    wb2[S.STOPWATCH].append(['2026-10-12', 'SW-9', '1:30', None])
    wb2.save(os.path.join(d, 'doug.xlsx'))
    with open(os.path.join(d, 'corrections.csv'), 'w') as f:
        f.write('date,tester,spot,n,field,old,new,reason,decided_by\n'
                '2026-10-13,doug@example.org,D-01,1,quality,3,4,notebook smudge; audio says four,Jason\n')


def run():
    failures = []

    def check(label, cond, detail=''):
        print(('  PASS  ' if cond else '  FAIL  ') + label + ('' if cond else f'\n        {detail}'))
        if not cond:
            failures.append(label)

    export = fixture()
    export['stations'][1]['recorded_on'] = 'watch'   # V-02 made on the watch; the rest send nothing (= phone)
    with tempfile.TemporaryDirectory() as d:
        write_sheets(export, d)
        key, problems, sha = C.convert(d, export)
        check('converter: no problems', problems == [], problems)
        r2 = key['recordings']['00000000-0000-4000-8000-000000000002']
        check('renamed Spot still matched by its hidden recording id', r2['spot_name'] == 'V-02 renamed', r2)
        check('quadrant "N10E 40W" -> strike 10, dip 40, dd 280, listed to check',
              r2['measurements'][0]['dd'] == 280 and r2['measurements'][0]['strike'] == 10
              and len(key['conversions']) == 1, key['conversions'])
        m1 = key['recordings']['00000000-0000-4000-8000-000000000001']['measurements'][0]
        check('right-hand rule sheet: strike 45 -> dd 135', m1['dd'] == 135, m1)
        d1 = key['recordings']['00000000-0000-4000-8000-000000000003']['measurements'][0]
        check('correction applied and marked', d1['quality'] == 4 and d1['corrected'] == ['quality'], d1)
        check('stopwatch: 2:00 = 120 s, 2 measurements from the server',
              key['baseline'][0]['seconds'] == 120 and key['baseline'][0]['n_measurements'] == 2, key['baseline'])
        sha2 = C.convert(d, export)[2]
        check('key hash is stable', sha == sha2)
        with open(os.path.join(d, 'corrections.csv'), 'a') as f:
            f.write('2026-10-13,doug@example.org,D-01,1,dip,99,21,typo,Jason\n')
        _k, probs, sha3 = C.convert(d, export)
        check('a correction whose "old" does not match -> problem, and the hash changes',
              any('not "99"' in p for p in probs) and sha3 != sha, probs)

        res = scorer.score(export, key, sha)
        lists = res['lists']
        wrong = {(w['station'][-1], w['ref'], w['field']): w for w in lists['wrong']}
        check('bar 1: exactly the planted wrong numbers',
              set(wrong) == {('1', 'm1', 'dip'), ('1', 'l1', 'plunge'), ('1', 'h1', 'dip'),
                             ('2', 'l9', 'trend'), ('2', 'l9', 'plunge')}, sorted(wrong))
        check('a line with EVERY number wrong is still paired and scored wrong, not missing + extra',
              not any(x['station'][-1] == '2' for x in lists['missing'] + lists['extra']))
        check('origins: edited / proposal / typed',
              [wrong[k]['origin'] for k in (('1', 'm1', 'dip'), ('1', 'l1', 'plunge'), ('1', 'h1', 'dip'))]
              == ['edited', 'proposal', 'typed'])
        check('"as spoken": plunge fifteen was said; the edited 33 and the typed 50 were not',
              wrong[('1', 'l1', 'plunge')]['as_spoken'] and not wrong[('1', 'm1', 'dip')]['as_spoken']
              and not wrong[('1', 'h1', 'dip')]['as_spoken'])
        check('sample label JA-12 = "ja 12"', not any(w['field'] == 'sample_id_name' for w in lists['wrong']))
        check('missing: the plane the notebook has but nothing saved (dip + dd)',
              sorted((m['key_n'], m['field']) for m in lists['missing']) == [(5, 'dip'), (5, 'dip_direction')],
              lists['missing'])
        check('extra: the fold hinge said but not in the notebook',
              [(x['ref'], x['kind']) for x in lists['extra']] == [('l2', 'line')], lists['extra'])
        check('convention mismatch: strike 10 with dip direction 280 (RHR wants 100)',
              [(c['station'][-1], c['strike'], c['dip_direction']) for c in lists['convention']] == [('2', 10, 280)],
              lists['convention'])
        ho = {(h['station'][-1], h['problem']) for h in lists['handoff']}
        check('hand-off: V-01 plunge 15 confirmed but 14 on the server; V-02 Spot not on the server; D-02 too',
              ho == {('1', 'differs'), ('2', 'not_on_server'), ('4', 'not_on_server')}, lists['handoff'])
        check('D-01 quality 4: right after the correction, marked corrected',
              res['stations']['00000000-0000-4000-8000-000000000003']['rows'][0]['fields']['quality']
              == {'key': 4, 'saved': 4, 'origin': 'proposal', 'corrected': True, 'status': 'ok'})
        check('not in the key: D-02; discarded counted apart',
              res['not_in_key'] == ['00000000-0000-4000-8000-000000000004'] and res['discarded'] == 1)
        o, t1, t2 = res['overall'], res['testers']['1'], res['testers']['2']
        check('bar 1 by tester: Claire 5 wrong (fails), Doug 0 (passes)',
              t1['bar1']['wrong'] == 5 and t1['bar1']['pass'] is False and t2['bar1']['pass'] is True)
        check('bar 2: unchanged / (proposed + added) = 23 / (26 + 3), a removed value counts as changed', o['bar2']['share'] == round(23 / 29, 3), o['bar2'])
        check('bar 2: zero-change Spots 3 of 4', (o['bar2']['zero_change_spots'], o['bar2']['spots']) == (3, 4))
        check('bar 2 by kind: numbers 17 of 23 values unchanged',
              o['bar2']['by_kind']['numbers'] == {'unchanged': 17, 'of': 23}, o['bar2']['by_kind'])
        check('a removed line is not saved: not extra, not a measurement',
              res['stations']['00000000-0000-4000-8000-000000000001']['timing']['n_measurements'] == 4
              and not any(x['ref'] == 'l3' for x in lists['extra']))
        check('strike 270 in the notebook = strike 90 saved (either end of the line)',
              res['stations']['00000000-0000-4000-8000-000000000003']['rows'][0]['fields']['strike']['status'] == 'ok')
        check('bar 3 Claire: voice per measurement 80/4 = 20 s and 60/2 = 30 s, median 25 s, vs stopwatch 120/2 = 60 s',
              t1['bar3']['voice']['per_measurement']['median'] == 25.0
              and t1['bar3']['baseline']['per_measurement']['median'] == 60.0 and t1['bar3']['pass'] is True,
              t1['bar3'])
        check('bar 3 Doug: voice 80 s per measurement vs stopwatch 90 s',
              t2['bar3']['voice']['per_measurement']['median'] == 80.0
              and t2['bar3']['baseline']['per_measurement']['median'] == 90.0, t2['bar3'])
        check('words: feature types + line on its plane', o['words']['of'] >= 4 and o['words']['right'] >= 4, o['words'])
        p1, w1 = t1['by_device']['phone'], t1['by_device']['watch']
        check('by device, Claire: phone V-01 3 wrong, watch V-02 2 wrong (the line l9)',
              (p1['bar1']['wrong'], w1['bar1']['wrong']) == (3, 2) and w1['bar1']['recordings_scored'] == 1,
              (p1['bar1'], w1['bar1']))
        check('by device, Claire bar 3: phone 20 s, watch 30 s per measurement, same stopwatch 60 s',
              p1['bar3']['voice']['per_measurement']['median'] == 20.0
              and w1['bar3']['voice']['per_measurement']['median'] == 30.0
              and w1['bar3']['baseline']['per_measurement']['median'] == 60.0, (p1['bar3'], w1['bar3']))
        w2 = t2['by_device']['watch']
        check('by device, Doug: no watch recordings -> nothing scored, no verdict',
              w2['bar1']['recordings_scored'] == 0 and w2['bar1']['pass'] is None and w2['bar2']['spots'] == 0
              and w2['bar2']['share'] is None, w2)
        op, ow = o['by_device']['phone'], o['by_device']['watch']
        check('by device, overall: phone + watch add up to all (wrong, Spots, values checked, words)',
              op['bar1']['wrong'] + ow['bar1']['wrong'] == o['bar1']['wrong']
              and op['bar2']['spots'] + ow['bar2']['spots'] == o['bar2']['spots']
              and op['bar1']['values_checked'] + ow['bar1']['values_checked'] == o['bar1']['values_checked']
              and op['words']['of'] + ow['words']['of'] == o['words']['of'], (op['bar1'], ow['bar1']))
        check('station entry says where it was recorded',
              res['stations']['00000000-0000-4000-8000-000000000002']['recorded_on'] == 'watch'
              and res['stations']['00000000-0000-4000-8000-000000000001']['recorded_on'] == 'phone')
    # broken rows never get guessed: each lands on the problem list
    export = fixture()
    with tempfile.TemporaryDirectory() as d:
        out = S.outings(export)
        wb = S.build(export, 1, '2026-10-12', out[(1, '2026-10-12')])
        ws = wb[S.VOICE]
        ws[S.CONVENTION_CELL] = 'Dip direction always written'
        fill(ws, 'V-01', [{'#': 1, 'Kind': 'Plane', 'Feature type': 'bedrock', 'Strike': 45, 'Dip': 32},
                          {'#': 2, 'Strike': 'forty', 'Kind': 'Plane', 'Dip': 3, 'Dip direction': 9},
                          {'#': 3, 'Trend': 120},
                          {'#': 4, 'Kind': 'Line', 'Trend': 1, 'Plunge': 2, 'Lies on #': 9}])
        wb[S.STOPWATCH].append(['2026-10-12', 'SW-404', 'soon', None])
        wb.save(os.path.join(d, 'broken.xlsx'))
        _k, probs, _s = C.convert(d, export)
        want = ['unknown feature type "bedrock"', 'a plane without a dip direction', 'strike "forty" is not a number',
                'Kind "None"', 'Lies on #9 is not a plane', 'time "soon" is not mm:ss', '"SW-404": not found']
        for w in want:
            check(f'problem listed: {w}', any(w in p for p in probs), probs)
    print('\n' + ('SELFTEST PASSED' if not failures else f'SELFTEST {len(failures)} FAILED: ' + '; '.join(failures)))
    return 0 if not failures else 1
