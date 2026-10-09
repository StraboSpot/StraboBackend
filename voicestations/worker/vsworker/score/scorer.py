"""Score the Strabo Voice trial (step 6 scoring page points 1-5).

ONE copy of the scoring rules (point 1): this module. Input = the server
export (GET /voiceworker/v1/score/export) + the trial answer key (keyfile.py,
built from the testers' sheets by convert.py). Output = one results object the
scoring page only displays.

Bar #1 (point 3) wrong NUMBER saved, scored from the CONFIRM RECORD (point 2):
  dip, dip direction, strike (either end), trend, plunge, quality and sample
  labels; exact match; every saved value counts whatever its origin
  (proposal / edited / typed); missing and extra are reported apart; a wrong
  number that appears in that measurement's transcript quote is marked
  "as spoken". A plane is identified by dip direction + dip; a saved strike
  that does not fit right-hand rule with its own dip direction is listed as
  a convention mismatch (not wrong).
Bar #2 (point 4) unchanged / (proposed + added) per value, plus the share of
  Spots confirmed with no change; by tester and overall.
Bar #3 (point 5) time per measurement, medians with range, voice (recording +
  review) vs the stopwatch baseline, by tester and overall.
Hand-off check (point 2): confirmed values vs the uploaded Spot; reported
  apart, never counted as wrong.
"""

import statistics

from ..extract import text as T
from .saved import LINE_NUMS, NUMBER_FIELDS, field_kind, final, num, saved_items

SCORER_VERSION = 'score-1'
BAR2_TARGET = 0.80


def norm_label(s):
    """Sample labels compare ignoring case, spaces and hyphens ("JA-12" = "ja 12")."""
    return ''.join(ch for ch in str(s or '').upper() if ch.isalnum())


def spoken_numbers(quotes):
    out = set()
    for q in quotes:
        out.update(T.numbers(T.canon_text(q)).keys())
    return out


def _same_dd(a, b):
    return a is not None and b is not None and (a - b) % 360 == 0


def _strike_ok(saved, key):
    """A strike may be written as either end of the line."""
    return saved is not None and key is not None and ((saved - key) % 180 == 0)


def _plane_dd(item):
    dd = num(final(item, 'dip_direction'))
    if dd is None:
        s = num(final(item, 'strike'))
        dd = None if s is None else (s + 90) % 360   # matching only; the saved strike is still checked
    return dd


def _match(keys, saved, kind):
    """Pair key measurements with saved items of one kind: best equal numbers
    first, then leftovers in recording order (so a measurement with every
    number wrong still shows as WRONG, not as missing + extra)."""
    ks = [k for k in keys if k['kind'] == kind]
    ss = [s for s in saved if s['kind'] == kind]
    pairs, used_k, used_s = [], set(), set()

    def score(k, s):
        if kind == 'plane':
            return 2 * _same_dd(_plane_dd(s), k.get('dd')) + (num(final(s, 'dip')) == k.get('dip'))
        if kind == 'line':
            return 2 * (num(final(s, 'trend')) == k.get('trend')) + (num(final(s, 'plunge')) == k.get('plunge'))
        return 3 * (norm_label(final(s, 'sample_id_name')) == norm_label(k.get('sample')) != '')

    cand = sorted(((score(k, s), -i, -j, i, j) for i, k in enumerate(ks) for j, s in enumerate(ss)), reverse=True)
    for sc, _a, _b, i, j in cand:
        if sc > 0 and i not in used_k and j not in used_s:
            pairs.append((ks[i], ss[j]))
            used_k.add(i)
            used_s.add(j)
    rest_k = [k for i, k in enumerate(ks) if i not in used_k]
    rest_s = sorted((s for j, s in enumerate(ss) if j not in used_s), key=lambda s: s['order'])
    for k, s in zip(rest_k, rest_s):
        pairs.append((k, s))
    paired_s = {id(s) for _, s in pairs}
    missing = rest_k[len(rest_s):]
    extra = [s for s in ss if id(s) not in paired_s]
    return pairs, missing, extra


def _fields_for(kind):
    if kind == 'plane':
        return [('dip_direction', 'dd'), ('dip', 'dip'), ('strike', 'strike'), ('quality', 'quality')]
    if kind == 'line':
        return [('trend', 'trend'), ('plunge', 'plunge'), ('quality', 'quality')]
    return [('sample_id_name', 'sample')]


def score_station(st, key):
    """One confirmed recording against its key -> (measurement rows, wrong, missing, extra, words)."""
    saved = saved_items(st['record'], st.get('proposal'))
    keys = key.get('measurements') or []
    rows, wrong, missing, extra = [], [], [], []
    words = {'right': 0, 'of': 0}
    base = {'station': st['station_uuid'], 'tester': st['userpkey'], 'spot_name': st.get('spot_name')}
    matched = {}
    for kind in ('plane', 'line', 'sample'):
        pairs, miss, ext = _match(keys, saved, kind)
        for k, s in pairs:
            matched[k['n']] = s
            fields = {}
            heard = spoken_numbers(s['quotes'])
            for sf, kf in _fields_for(kind):
                kv = k.get(kf)
                if kv is None or kv == '':
                    continue                     # not in the notebook: not scored
                sv = s['values'].get(sf)
                cell = {'key': kv, 'saved': None if sv is None else sv['final'],
                        'origin': None if sv is None else sv['origin'],
                        'corrected': kf in (k.get('corrected') or [])}
                if sv is None or sv['final'] in (None, ''):
                    cell['status'] = 'missing'
                    missing.append(dict(base, ref=s['ref'], key_n=k['n'], field=sf, key=kv,
                                        corrected=cell['corrected']))
                else:
                    if sf == 'sample_id_name':
                        ok = norm_label(sv['final']) == norm_label(kv)
                    elif sf == 'strike':
                        ok = _strike_ok(num(sv['final']), num(kv))
                    elif sf == 'dip_direction':
                        ok = _same_dd(num(sv['final']), num(kv))
                    else:
                        ok = num(sv['final']) == num(kv)
                    cell['status'] = 'ok' if ok else 'wrong'
                    if not ok:
                        n = num(sv['final'])
                        cell['as_spoken'] = sf != 'sample_id_name' and isinstance(n, int) and n in heard
                        wrong.append(dict(base, ref=s['ref'], key_n=k['n'], field=sf, saved=sv['final'], key=kv,
                                          origin=sv['origin'], as_spoken=cell['as_spoken'],
                                          corrected=cell['corrected']))
                fields[sf] = cell
            # words: feature type (+ movement / facing text) reported, not in the bar
            if kind in ('plane', 'line') and k.get('ft'):
                words['of'] += 1
                ft = str(final(s, 'feature_type') or '')
                if ft.lower() == str(k['ft']).lower():
                    words['right'] += 1
                fields['feature_type'] = {'key': k['ft'], 'saved': final(s, 'feature_type') or None,
                                          'status': 'ok' if ft.lower() == str(k['ft']).lower() else 'word_differs'}
            if k.get('words'):
                said = ' '.join(str(final(s, f) or '') for f in ('movement', 'other_movement', 'facing')).lower()
                words['of'] += 1
                words['right'] += int(str(k['words']).lower() in said)
            rows.append({'key_n': k['n'], 'ref': s['ref'], 'kind': kind, 'fields': fields})
        for k in miss:
            for sf, kf in _fields_for(kind):
                if k.get(kf) not in (None, ''):
                    missing.append(dict(base, ref=None, key_n=k['n'], field=sf, key=k[kf],
                                        corrected=kf in (k.get('corrected') or [])))
            rows.append({'key_n': k['n'], 'ref': None, 'kind': kind, 'fields': {}, 'status': 'not_saved'})
        for s in ext:
            nums = {f: final(s, f) for f in NUMBER_FIELDS + ('sample_id_name',) if final(s, f) not in (None, '')}
            if nums:
                extra.append(dict(base, ref=s['ref'], kind=kind, values=nums))
    # a line the notebook puts on a plane must sit on that plane's saved item
    for k in keys:
        if k.get('lies_on') and k['n'] in matched:
            plane = matched.get(k['lies_on'])
            words['of'] += 1
            words['right'] += int(plane is not None and matched[k['n']]['parent'] == plane['ref'])
    return saved, rows, wrong, missing, extra, words


def convention_mismatches(st, saved):
    out = []
    for s in saved:
        if s['kind'] != 'plane':
            continue
        strike, dd = num(final(s, 'strike')), num(final(s, 'dip_direction'))
        if strike is not None and dd is not None and (strike + 90 - dd) % 360 != 0:
            out.append({'station': st['station_uuid'], 'tester': st['userpkey'], 'spot_name': st.get('spot_name'),
                        'ref': s['ref'], 'strike': strike, 'dip_direction': dd})
    return out


def _spot_tuples(spot):
    """Orientation + sample tuples of an uploaded Spot (nested lines flattened)."""
    p = (spot or {}).get('properties') or {}
    out = []

    def one(o):
        if o.get('type') == 'linear_orientation':
            out.append(('line', num(o.get('trend')), num(o.get('plunge')), num(o.get('quality')), o.get('feature_type')))
        else:
            out.append(('plane', num(o.get('strike')), num(o.get('dip')), num(o.get('dip_direction')),
                        num(o.get('quality')), o.get('feature_type')))
        for a in o.get('associated_orientation') or []:
            one(a)
    for o in p.get('orientation_data') or []:
        one(o)
    for smp in p.get('samples') or []:
        out.append(('sample', norm_label(smp.get('sample_id_name'))))
    return out


def _saved_tuples(saved, card):
    out = []
    for s in saved:
        if s['card'] != card:
            continue
        if s['kind'] == 'line':
            out.append(('line', num(final(s, 'trend')), num(final(s, 'plunge')), num(final(s, 'quality')),
                        final(s, 'feature_type')))
        elif s['kind'] == 'plane':
            out.append(('plane', num(final(s, 'strike')), num(final(s, 'dip')), num(final(s, 'dip_direction')),
                        num(final(s, 'quality')), final(s, 'feature_type')))
        elif s['kind'] == 'sample' and final(s, 'sample_id_name'):
            out.append(('sample', norm_label(final(s, 'sample_id_name'))))
    return out


def handoff(st, saved):
    """Confirmed values that differ from the Spot now on the server (point 2)."""
    out = []
    for c in (st['record'].get('spots') or []):
        sid = str(c.get('spot_id'))
        spot = (st.get('spots') or {}).get(sid)
        base = {'station': st['station_uuid'], 'tester': st['userpkey'], 'spot_id': sid,
                'spot_name': (c.get('name') or {}).get('final')}
        if spot is None:
            out.append(dict(base, problem='not_on_server'))
            continue
        want = sorted(map(repr, _saved_tuples(saved, c.get('card'))))
        have = sorted(map(repr, _spot_tuples(spot)))
        only_confirmed = [t for t in want if t not in have or want.count(t) > have.count(t)]
        only_spot = [t for t in have if t not in want or have.count(t) > want.count(t)]
        if only_confirmed or only_spot:
            out.append(dict(base, problem='differs', confirmed_only=sorted(set(only_confirmed)),
                            spot_only=sorted(set(only_spot))))
    return out


def spread(xs):
    xs = [x for x in xs if x is not None]
    if not xs:
        return None
    return {'median': round(statistics.median(xs), 1), 'min': round(min(xs), 1), 'max': round(max(xs), 1), 'n': len(xs)}


def _bar2(sts):
    t = {'unchanged': 0, 'proposed': 0, 'added': 0, 'by_kind': {}, 'zero_change_spots': 0, 'spots': 0}
    for st in sts:
        c = st['counts']
        t['unchanged'] += c['unchanged']
        t['proposed'] += c['proposed']
        t['added'] += c['added']
        t['spots'] += 1
        t['zero_change_spots'] += int(c['edited'] == 0 and c['removed'] == 0 and c['added'] == 0)
        for v in st['record'].get('values') or []:
            k = t['by_kind'].setdefault(field_kind(v.get('field')), {'unchanged': 0, 'of': 0})
            k['of'] += 1
            k['unchanged'] += int(v.get('action') == 'unchanged')
    denom = t['proposed'] + t['added']
    t['share'] = round(t['unchanged'] / denom, 3) if denom else None
    t['pass'] = None if t['share'] is None else t['share'] >= BAR2_TARGET
    return t


def _bar3(voice_rows, base_rows):
    v_meas = [r['seconds'] / r['n'] for r in voice_rows if r['n']]
    b_meas = [r['seconds'] / r['n'] for r in base_rows if r['n']]
    out = {
        'voice': {'per_measurement': spread(v_meas), 'per_spot': spread([r['seconds'] for r in voice_rows]),
                  'recording': spread([r['recording'] for r in voice_rows]),
                  'review': spread([r['review'] for r in voice_rows])},
        'baseline': {'per_measurement': spread(b_meas), 'per_spot': spread([r['seconds'] for r in base_rows])},
    }
    vm, bm = out['voice']['per_measurement'], out['baseline']['per_measurement']
    out['pass'] = None if not vm or not bm else vm['median'] <= bm['median']
    return out


def score(export, key, key_sha256):
    """The whole trial -> results object (what the scoring page shows)."""
    recs = key.get('recordings') or {}
    confirmed = [st for st in export['stations'] if st.get('outcome') == 'confirmed']
    testers = {t['userpkey']: t for t in export.get('testers') or []}
    lists = {'wrong': [], 'missing': [], 'extra': [], 'convention': [], 'handoff': []}
    stations, words, voice_rows = {}, {}, {}
    for st in confirmed:
        upk = st['userpkey']
        k = recs.get(st['station_uuid'])
        saved = saved_items(st['record'], st.get('proposal'))
        entry = {'tester': upk, 'spot_name': st.get('spot_name'), 'started_at': st.get('started_at'),
                 'in_key': k is not None, 'counts': st['counts'], 'rows': []}
        if k is not None:
            saved, rows, wrong, missing, extra, w = score_station(st, k)
            entry['rows'] = rows
            lists['wrong'] += wrong
            lists['missing'] += missing
            lists['extra'] += extra
            tw = words.setdefault(upk, {'right': 0, 'of': 0})
            tw['right'] += w['right']
            tw['of'] += w['of']
        lists['convention'] += convention_mismatches(st, saved)
        lists['handoff'] += handoff(st, saved)
        n_meas = sum(1 for s in saved if s['kind'] in ('plane', 'line'))
        rec, rev = st.get('recorded_seconds'), st.get('review_seconds')
        entry['timing'] = {'recording': rec, 'review': rev, 'n_measurements': n_meas}
        if rec is not None and rev is not None:
            voice_rows.setdefault(upk, []).append({'seconds': rec + rev, 'recording': rec, 'review': rev, 'n': n_meas})
        stations[st['station_uuid']] = entry

    base_rows = {}
    for b in key.get('baseline') or []:
        base_rows.setdefault(b['tester'], []).append({'seconds': b['seconds'], 'n': b.get('n_measurements') or 0})

    def bars(upks):
        sts = [st for st in confirmed if st['userpkey'] in upks]
        scored = [s for s in sts if s['station_uuid'] in recs]
        wrong = [w for w in lists['wrong'] if w['tester'] in upks]
        missing = [m for m in lists['missing'] if m['tester'] in upks]
        values = sum(len(r['fields']) for s in scored for r in stations[s['station_uuid']]['rows'])
        w = {'right': sum(words.get(u, {}).get('right', 0) for u in upks),
             'of': sum(words.get(u, {}).get('of', 0) for u in upks)}
        return {
            'bar1': {'wrong': len(wrong), 'as_spoken': sum(1 for x in wrong if x['as_spoken']),
                     'by_origin': {o: sum(1 for x in wrong if x['origin'] == o) for o in ('proposal', 'edited', 'typed')},
                     'missing': len(missing),
                     'extra': sum(1 for x in lists['extra'] if x['tester'] in upks),
                     'recordings_scored': len(scored), 'values_checked': values,
                     'pass': None if not scored else len(wrong) == 0},
            'bar2': _bar2(sts),
            'bar3': _bar3([r for u in upks for r in voice_rows.get(u, [])],
                          [r for u in upks for r in base_rows.get(u, [])]),
            'words': w,
        }

    all_upks = sorted({st['userpkey'] for st in confirmed} | set(base_rows))
    by_tester = {str(u): dict(bars({u}), email=(testers.get(u) or {}).get('email'),
                              name=(testers.get(u) or {}).get('name')) for u in all_upks}
    overall = bars(set(all_upks))
    b3 = [by_tester[str(u)]['bar3']['pass'] for u in all_upks]
    overall['bar3']['pass_every_tester'] = None if any(p is None for p in b3) or not b3 else all(b3)
    return {
        'scorer_version': SCORER_VERSION,
        'key_sha256': key_sha256,
        'export_generated_at': export.get('generated_at'),
        'overall': overall,
        'testers': by_tester,
        'lists': lists,
        'corrected': key.get('corrections') or [],
        'stations': stations,
        'discarded': sum(1 for st in export['stations'] if st.get('outcome') == 'discarded'),
        'not_in_key': sorted(u for u, e in stations.items() if not e['in_key']),
        'key_without_recording': sorted(set(recs) - {st['station_uuid'] for st in confirmed}),
    }
