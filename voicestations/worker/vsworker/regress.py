"""Regression gate (step 3 point 6): the LIVE extraction code (prompt, provider,
P7 checks, conversions) on stored extract jobs, scored against a blind answer key.

  python -m vsworker.regress --inputs DIR --key KEY.json --out DIR [--provider anthropic]
         [--model M] [--effort high] [--slots 4] [--score-only]

  inputs/*.json  extract jobs exactly as the server sends them (captured from
                 the real pipeline: transcript + words, station, earlier
                 stations, form choices), one per station, named by station
  KEY.json       the blind answer key (written 09-26, before any model ran)
  out/           per station: raw reply, proposal, audit, meta; score.txt

PASS (Jason, 10-07): 0 wrong numbers that survive the checks, every expected
measurement found with the right feature type and every expected number
(none missing), every expected flag present.
The scorer proves itself first: a copy of the run with one number changed and
one measurement removed must score WRONG and MISSING.
"""

import argparse
import concurrent.futures as cf
import copy
import glob
import json
import os
import sys
import time

from .extract import adapters
from .extract.run import extract_once

PL, LN = 'planar_orientation', 'linear_orientation'
FLAG_FOR = {'doubt': 'doubt', 'corrected': 'corrected'}


def orientations(proposal):
    """Every orientation item, nested lines included, with its parent ref."""
    out = []
    for it in proposal.get('items', []):
        if it.get('kind') != 'orientation':
            continue
        out.append((it, None))
        for a in it.get('associated', []):
            out.append((a, it['ref']))
    return out


def score_station(name, key, proposal):
    rows, t = [], {'expected': 0, 'found': 0, 'ft_ok': 0, 'wrong': 0, 'missing': 0, 'extra': 0,
                   'flags_expected': 0, 'flags_ok': 0, 'extras_expected': 0, 'extras_ok': 0}
    ors = orientations(proposal)
    used, match = set(), {}
    for e in key['m']:
        t['expected'] += 1
        want_type = PL if e['kind'] == 'planar' else LN
        best, bs = None, 0
        for i, (it, _) in enumerate(ors):
            sp = it['spot']
            if i in used or sp.get('type') != want_type:
                continue
            if want_type == PL:
                sc = (sp.get('strike') == e['strike']) * 2 + (sp.get('dip') == e.get('dip')) + (sp.get('dip_direction') == e['dd'])
            else:
                sc = (sp.get('trend') == e['trend']) * 2 + (sp.get('plunge') == e['plunge'])
            if sc > bs:
                best, bs = i, sc
        if best is None:
            rows.append(f'  {name} MISSING: {e["id"]}')
            continue
        used.add(best)
        it, parent = ors[best]
        match[e['id']] = (it, parent)
        sp = it['spot']
        t['found'] += 1
        if sp.get('feature_type') in e['ft']:
            t['ft_ok'] += 1
        else:
            rows.append(f'  {name} {e["id"]} feature_type {sp.get("feature_type")!r} (want {"/".join(e["ft"])})')
        slots = [('strike', 'strike'), ('dip', 'dip'), ('dip_direction', 'dd')] if want_type == PL else [('trend', 'trend'), ('plunge', 'plunge')]
        for sf, ef in slots:
            got, want = sp.get(sf), e.get(ef)
            if got is None:
                if not (ef == 'dip' and e.get('dip_may_be_null')):
                    t['missing'] += 1
                    rows.append(f'  {name} {e["id"]} {ef} missing')
            elif got != want:
                t['wrong'] += 1
                rows.append(f'  {name} {e["id"]} WRONG {ef}: got {got}, want {want}')
        q = sp.get('quality')
        if e.get('quality') is not None:
            t['extras_expected'] += 1
            if q == str(e['quality']):
                t['extras_ok'] += 1
            else:
                rows.append(f'  {name} {e["id"]} quality {q} (want {e["quality"]})')
        elif q is not None:
            t['wrong'] += 1
            rows.append(f'  {name} {e["id"]} WRONG quality {q} (none spoken)')
        codes = {f['code'] for f in it.get('flags', [])}
        for k in ('doubt', 'corrected'):
            if e.get(k):
                t['flags_expected'] += 1
                if FLAG_FOR[k] in codes:
                    t['flags_ok'] += 1
                else:
                    rows.append(f'  {name} {e["id"]} {k} flag MISSING')
        if e.get('movement'):
            t['extras_expected'] += 1
            mv = (sp.get('other_movement') or sp.get('movement') or '').lower()
            if e['movement'] in mv:
                t['extras_ok'] += 1
            else:
                rows.append(f'  {name} {e["id"]} movement {mv!r}')
    for e in key['m']:
        if e.get('lies_on'):
            t['extras_expected'] += 1
            got = match.get(e['id'])
            plane = match.get(e['lies_on'])
            if got and plane and got[1] == plane[0]['ref']:
                t['extras_ok'] += 1
            else:
                rows.append(f'  {name} {e["id"]} not nested on {e["lies_on"]}')
    for i, (it, _) in enumerate(ors):
        if i not in used:
            nums = {k: it['spot'].get(k) for k in ('strike', 'dip', 'dip_direction', 'trend', 'plunge') if it['spot'].get(k) is not None}
            if nums:
                t['extra'] += 1
                rows.append(f'  {name} EXTRA measurement {it["spot"].get("feature_type")} {nums}')
    items = proposal.get('items', [])
    if key.get('facing_upright'):
        t['extras_expected'] += 1
        if any(it['spot'].get('facing') == 'upright' for it, _ in ors):
            t['extras_ok'] += 1
        else:
            rows.append(f'  {name} facing upright missing')
    if key.get('sample'):
        t['extras_expected'] += 1
        labels = [(it.get('spot', {}).get('sample_id_name') or '').upper().replace('-', '').replace(' ', '')
                  for it in items if it.get('kind') == 'sample']
        if any(key['sample'] in lab for lab in labels):
            t['extras_ok'] += 1
        else:
            rows.append(f'  {name} sample {key["sample"]} missing (got {labels})')
    if key.get('photos'):
        t['extras_expected'] += 1
        n = sum(1 for it in items if it.get('kind') == 'photo')
        if n == key['photos']:
            t['extras_ok'] += 1
        else:
            rows.append(f'  {name} photos {n} (want {key["photos"]})')
    return t, rows


def score(key, proposals):
    tot, rows = {}, []
    for name in sorted(key):
        t, r = score_station(name, key[name], proposals.get(name) or {'items': []})
        for k, v in t.items():
            tot[k] = tot.get(k, 0) + v
        rows += r
    return tot, rows


def passed(t):
    return (t['wrong'] == 0 and t['missing'] == 0 and t['found'] == t['expected'] and t['ft_ok'] == t['expected']
            and t['flags_ok'] == t['flags_expected'])


def self_test(key, proposals):
    """The scorer must catch a changed number and a missing measurement."""
    p = copy.deepcopy(proposals)

    def has_num(it):
        return it.get('kind') == 'orientation' and ('strike' in it['spot'] or 'trend' in it['spot'])
    name = next((n for n in sorted(key) if any(has_num(it) for it in p.get(n, {}).get('items', []))), None)
    if name is None:
        return False
    first = next(it for it in p[name]['items'] if has_num(it))
    f = 'strike' if 'strike' in first['spot'] else 'trend'
    first['spot'][f] = (first['spot'][f] + 7) % 360
    t1, _ = score(key, p)
    p2 = copy.deepcopy(proposals)
    p2[name]['items'] = [it for it in p2[name]['items'] if it.get('kind') != 'orientation']
    t2, _ = score(key, p2)
    t0, _ = score(key, proposals)
    return t1['wrong'] > t0['wrong'] and t2['found'] < t0['found']


def line(t, label):
    return (f'{label:28s} found {t["found"]}/{t["expected"]}  type {t["ft_ok"]}/{t["expected"]}  '
            f'WRONG {t["wrong"]}  missing {t["missing"]}  extra {t["extra"]}  flags {t["flags_ok"]}/{t["flags_expected"]}  '
            f'extras {t["extras_ok"]}/{t["extras_expected"]}')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--inputs', required=True)
    ap.add_argument('--key', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--provider', default='anthropic')
    ap.add_argument('--model')
    ap.add_argument('--effort', default='high')
    ap.add_argument('--slots', type=int, default=4)
    ap.add_argument('--ollama-url', default=os.environ.get('VS_OLLAMA_URL', 'http://host.docker.internal:11434'))
    ap.add_argument('--score-only', action='store_true')
    a = ap.parse_args()

    key = json.load(open(a.key))
    for st in key.values():
        for m in st['m']:
            m['ft'] = set(m['ft'])
    jobs = {os.path.basename(p)[:-5]: json.load(open(p)) for p in sorted(glob.glob(os.path.join(a.inputs, '*.json')))}
    os.makedirs(a.out, exist_ok=True)

    if not a.score_only:
        if a.provider == 'anthropic':
            ad = adapters.AnthropicAdapter(a.model or 'claude-opus-5-5', a.effort)
        else:
            ad = adapters.OllamaAdapter(a.model or 'qwen3:14b', a.ollama_url)
            a.slots = 1

        def one(name):
            t0 = time.time()
            try:
                reply, proposal, audit = extract_once(jobs[name], ad)
                rec = {'raw': reply.raw_text, 'data': reply.data, 'proposal': proposal, 'audit': audit,
                       'meta': reply.meta, 'wall_s': round(time.time() - t0, 2)}
            except adapters.ProviderError as e:
                rec = {'error': str(e), 'proposal': None, 'wall_s': round(time.time() - t0, 2)}
            json.dump(rec, open(os.path.join(a.out, name + '.json'), 'w'), indent=1)
            return name, rec

        with cf.ThreadPoolExecutor(max_workers=a.slots) as ex:
            for name, rec in ex.map(one, sorted(jobs)):
                m = rec.get('meta') or {}
                print(f'{name}: {"ERROR " + rec["error"] if rec.get("error") else "ok"} '
                      f'{rec["wall_s"]} s, {m.get("output_tokens")} out, {m.get("cache_read_tokens")} cached', flush=True)

    proposals = {}
    meta = []
    for name in jobs:
        p = os.path.join(a.out, name + '.json')
        if os.path.exists(p):
            rec = json.load(open(p))
            proposals[name] = rec.get('proposal')
            meta.append(rec.get('meta') or {})
    missing_key = [n for n in key if n not in jobs]
    if missing_key:
        sys.exit(f'inputs missing for: {missing_key}')
    st = self_test(key, {n: proposals.get(n) or {'items': []} for n in key})
    tot, rows = score(key, proposals)
    cost_in = sum((m.get('input_tokens') or 0) for m in meta)
    cost_out = sum((m.get('output_tokens') or 0) for m in meta)
    cached = sum((m.get('cache_read_tokens') or 0) for m in meta)
    written = sum((m.get('cache_write_tokens') or 0) for m in meta)
    secs = sum((m.get('seconds') or 0) for m in meta)
    label = f'{a.provider} {a.model or ""} {a.effort if a.provider == "anthropic" else "think"}'.strip()
    report = [line(tot, label),
              f'scorer self-test: {"OK" if st else "FAILED"}',
              f'tokens: input {cost_in} (+{cached} cache read, {written} cache write), output {cost_out}; provider time {round(secs)} s',
              f'GATE: {"PASS" if passed(tot) and st else "FAIL"}', ''] + (rows or ['  (no problems)'])
    print('\n'.join(report))
    open(os.path.join(a.out, 'score.txt'), 'w').write('\n'.join(report) + '\n')
    sys.exit(0 if passed(tot) and st else 1)


if __name__ == '__main__':
    main()
