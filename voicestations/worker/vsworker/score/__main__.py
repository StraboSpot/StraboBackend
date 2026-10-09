"""Strabo Voice trial scoring, from the command line (step 6).

  VS_SCORE_URL   https://strabospot.org/voiceworker/v1 (default) or a dev URL
  VS_SCORER_TOKEN the plain scorer token (its sha256 = VOICESTATIONS_SCORER)

  python -m vsworker.score export  --out export.json
  python -m vsworker.score sheets  --export export.json --out SHEETS_DIR
  python -m vsworker.score convert --export export.json --sheets SHEETS_DIR
  python -m vsworker.score score   --export export.json --sheets SHEETS_DIR
                                   [--conversions-checked] [--post] [--out results.json]
  python -m vsworker.score selftest

Scoring refuses while the converter's problem list is not empty, and while
quadrant conversions wait for Jason's check (--conversions-checked).
"""

import argparse
import json
import os
import sys
import urllib.request

from . import convert as C
from . import scorer
from . import sheets


def api(method, path, body=None):
    base = os.environ.get('VS_SCORE_URL', 'https://strabospot.org/voiceworker/v1').rstrip('/')
    token = os.environ.get('VS_SCORER_TOKEN')
    if not token:
        sys.exit('VS_SCORER_TOKEN is not set.')
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(f'{base}/{path}', data=data, method=method,
                                 headers={'Authorization': f'Bearer {token}', 'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.loads(r.read().decode())


def load(p):
    with open(p) as f:
        return json.load(f)


def main(argv=None):
    ap = argparse.ArgumentParser(prog='python -m vsworker.score')
    sub = ap.add_subparsers(dest='cmd', required=True)
    e = sub.add_parser('export')
    e.add_argument('--out', required=True)
    s = sub.add_parser('sheets')
    s.add_argument('--export', required=True)
    s.add_argument('--out', required=True)
    for name in ('convert', 'score'):
        p = sub.add_parser(name)
        p.add_argument('--export', required=True)
        p.add_argument('--sheets', required=True)
        if name == 'score':
            p.add_argument('--conversions-checked', action='store_true')
            p.add_argument('--post', action='store_true')
            p.add_argument('--out')
    sub.add_parser('selftest')
    a = ap.parse_args(argv)

    if a.cmd == 'export':
        x = api('GET', 'score/export')
        with open(a.out, 'w') as f:
            json.dump(x, f)
        print(f'{len(x["stations"])} recordings, {len(x["testers"])} testers -> {a.out}')
        return 0
    if a.cmd == 'sheets':
        for p in sheets.write_all(load(a.export), a.out):
            print(p)
        return 0
    if a.cmd == 'selftest':
        from .selftest import run
        return run()

    export = load(a.export)
    key, problems, sha = C.convert(a.sheets, export)
    for c in key['conversions']:
        print(f'CHECK conversion {c["sheet"]} Spot {c["spot"]} #{c["n"]}: "{c["as_written"]}" -> '
              f'strike {c["strike"]}, dip {c["dip"]}, dip direction {c["dd"]}')
    for p in problems:
        print(f'PROBLEM {p}')
    print(f'{len(key["recordings"])} recordings, {len(key["baseline"])} stopwatch rows, '
          f'{len(key["corrections"])} corrections; key {sha[:12]}')
    if a.cmd == 'convert':
        return 1 if problems else 0
    if problems:
        print('Not scored: fix the problems above first.')
        return 1
    if key['conversions'] and not a.conversions_checked:
        print('Not scored: check the conversions above, then rerun with --conversions-checked.')
        return 1
    res = scorer.score(export, key, sha)
    o = res['overall']
    print(f'bar 1 wrong numbers saved: {o["bar1"]["wrong"]} (as spoken {o["bar1"]["as_spoken"]}), '
          f'missing {o["bar1"]["missing"]}, extra {o["bar1"]["extra"]}')
    print(f'bar 2 unchanged: {o["bar2"]["share"]}  bar 3 voice vs stopwatch per measurement: '
          f'{(o["bar3"]["voice"]["per_measurement"] or {}).get("median")} s vs '
          f'{(o["bar3"]["baseline"]["per_measurement"] or {}).get("median")} s')
    if a.out:
        with open(a.out, 'w') as f:
            json.dump(res, f, indent=1)
    if a.post:
        r = api('POST', 'score/results', {'scorer_version': scorer.SCORER_VERSION, 'key_sha256': sha, 'results': res})
        print(f'stored as scorer run {r["id"]}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
