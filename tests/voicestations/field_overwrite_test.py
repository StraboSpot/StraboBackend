"""Voice Spot guard test (10-09): replays StraboField (v2.31.5 code) and
Strabo Voice server requests against the dev server and checks that a
StraboField upload can neither delete voice Spots nor replace them with an
older copy, while ordinary StraboField Spots still delete and replace.

Run from the Mac (dev server on http://localhost, demo account maya.chen):
  python3 -I tests/voicestations/field_overwrite_test.py [--keep]
Makes one scratch project per scenario and deletes them unless --keep.
Without the guard (db/controllers/DatasetSpotsController.php) B, C and F fail.

StraboField download: GET /db/projectDatasets/{p} (dataset props; local ts =
server ts or Date.now() if missing), GET /db/datasetSpots/{d}.
StraboField upload, per dataset: POST /db/dataset (local props incl.
modified_timestamp); ONLY if the reply has modified_on_server true, POST
/db/datasetspots/{d} with the local Spots (or DELETE if none).
Strabo Voice upload: GET /db/datasetSpots/{d}, add its Spots (server copy
wins), POST /db/datasetSpots/{d}.
"""
import base64, json, sys, time, urllib.request, urllib.error

BASE = 'http://localhost/db'
AUTH = 'Basic ' + base64.b64encode(b'maya.chen@test.strabospot.org:demopass123').decode()
results = []


def req(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(BASE + path, data=data, method=method,
                               headers={'Authorization': AUTH, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(r, timeout=120) as resp:
            txt = resp.read().decode()
            return resp.status, (json.loads(txt) if txt.strip() else None)
    except urllib.error.HTTPError as e:
        txt = e.read().decode()
        try:
            return e.code, json.loads(txt)
        except Exception:
            return e.code, txt


_n = [0]
def new_id():
    _n[0] += 1
    return int(time.time() * 1000) * 100 + _n[0]


def spot(name, sid=None, voice=False):
    now = int(time.time() * 1000)
    s = {'type': 'Feature', 'geometry': {'type': 'Point', 'coordinates': [-95.25, 38.95]},
         'properties': {'id': sid or new_id(), 'name': name, 'date': '2026-10-09T18:00:00.000Z',
                        'time': '2026-10-09T18:00:00.000Z', 'modified_timestamp': now}}
    if voice:
        s['properties']['voice_station_id'] = f'test-{name}-{now}'
    return s


def vspot(name):
    return spot(name, voice=True)


def server_prop(d, name, key):
    st, body = req('GET', f'/datasetSpots/{d}')
    for f in (body or {}).get('features') or []:
        if f['properties']['name'] == name:
            return f['properties'].get(key)
    return None


def server_names(d):
    st, body = req('GET', f'/datasetSpots/{d}')
    feats = (body or {}).get('features') or [] if isinstance(body, dict) else []
    return sorted(f['properties']['name'] for f in feats)


def check(label, got, want):
    ok = got == want
    results.append(ok)
    print(f"  {'PASS' if ok else 'FAIL'}  {label}: got {got}, expected {want}")


# ---- the two apps -------------------------------------------------------

def voice_create_dataset(p):
    d = new_id()
    req('POST', '/dataset', {'id': d, 'name': 'Voice Spots', 'modified_timestamp': int(time.time() * 1000)})
    req('POST', f'/projectDatasets/{p}', {'id': d})
    return d


def voice_upload(d, new_spots):
    st, body = req('GET', f'/datasetSpots/{d}')
    have = (body or {}).get('features') or [] if isinstance(body, dict) else []
    ids = {f['properties']['id'] for f in have}
    coll = {'type': 'FeatureCollection', 'features': have + [s for s in new_spots if s['properties']['id'] not in ids]}
    st, _ = req('POST', f'/datasetSpots/{d}', coll)
    assert st in (200, 201), st


class StraboField:
    """Local copy as StraboField keeps it after a download."""
    def download(self, p):
        st, body = req('GET', f'/projectDatasets/{p}')
        self.datasets = {}
        for ds in body['datasets']:
            ds = dict(ds)
            ds['modified_timestamp'] = ds.get('modified_timestamp') or int(time.time() * 1000)
            st, sb = req('GET', f"/datasetSpots/{ds['id']}")
            ds['spots'] = (sb or {}).get('features') or [] if isinstance(sb, dict) else []
            self.datasets[ds['id']] = ds
        return {d['name']: d['modified_timestamp'] for d in self.datasets.values()}

    def edit_spot(self, d, name, field='notes', value='edited in StraboField'):
        ds = self.datasets[d]
        now = int(time.time() * 1000)
        for f in ds['spots']:
            if f['properties']['name'] == name:
                f['properties'][field] = value
                f['properties']['modified_timestamp'] = now
        ds['modified_timestamp'] = now           # projects.slice.js bumps the dataset

    def add_spot(self, d, s):
        self.datasets[d]['spots'].append(s)
        self.datasets[d]['modified_timestamp'] = int(time.time() * 1000)

    def upload(self):
        out = {}
        for d, ds in self.datasets.items():
            props = {k: v for k, v in ds.items() if k not in ('spots',)}
            st, body = req('POST', '/dataset', props)
            accepted = isinstance(body, dict) and body.get('modified_on_server') is True
            out[ds['name']] = 'accepted' if accepted else 'refused (server newer)'
            if accepted:
                req('POST', f'/projectDatasets/{self.project}', {'id': d})
                if ds['spots']:
                    req('POST', f'/datasetspots/{d}', {'type': 'FeatureCollection', 'features': ds['spots']})
                else:
                    req('DELETE', f'/datasetSpots/{d}')
        return out


def new_project():
    p = new_id()
    st, body = req('POST', '/project', {'id': p, 'description': {'project_name': f'SF overwrite test {p}'},
                                         'modified_timestamp': int(time.time() * 1000)})
    assert st in (200, 201), (st, body)
    sw = new_id()
    req('POST', '/dataset', {'id': sw, 'name': 'Stopwatch Spots', 'modified_timestamp': int(time.time() * 1000)})
    req('POST', f'/projectDatasets/{p}', {'id': sw})
    return p, sw


def scenario(title):
    print(f'\n== {title}')


projects = []
try:
    # A: StraboField downloads after Voice Spots exists, never touches it, uploads.
    scenario('A. StraboField downloads, voice Spots added later, StraboField uploads without touching Voice Spots')
    p, sw = new_project(); projects.append(p)
    vd = voice_create_dataset(p)
    voice_upload(vd, [vspot('V1')])
    sf = StraboField(); sf.project = p
    print('  StraboField local timestamps after download:', sf.download(p))
    time.sleep(1.1)
    voice_upload(vd, [vspot('V2'), vspot('V3')])
    sf.add_spot(sw, spot('SW1'))
    print('  upload:', sf.upload())
    check('Voice Spots on server', server_names(vd), ['V1', 'V2', 'V3'])
    check('Stopwatch Spots on server', server_names(sw), ['SW1'])

    # B: same, but the tester edits a downloaded voice Spot in StraboField.
    scenario('B. StraboField edits a voice Spot it downloaded, then uploads')
    p, sw = new_project(); projects.append(p)
    vd = voice_create_dataset(p)
    voice_upload(vd, [vspot('V1')])
    sf = StraboField(); sf.project = p
    sf.download(p)
    time.sleep(1.1)
    voice_upload(vd, [vspot('V2'), vspot('V3')])
    sf.edit_spot(vd, 'V1')
    print('  upload:', sf.upload())
    check('Voice Spots on server (V2, V3 confirmed after the download)', server_names(vd), ['V1', 'V2', 'V3'])

    # C: StraboField's target dataset is Voice Spots, so a stopwatch Spot lands there.
    scenario('C. A StraboField Spot made into Voice Spots by mistake, then upload')
    p, sw = new_project(); projects.append(p)
    vd = voice_create_dataset(p)
    sf = StraboField(); sf.project = p
    sf.download(p)                     # Voice Spots still empty here
    time.sleep(1.1)
    voice_upload(vd, [vspot('V1'), vspot('V2')])
    sf.add_spot(vd, spot('SW-mistake'))
    print('  upload:', sf.upload())
    check('Voice Spots on server', server_names(vd), ['SW-mistake', 'V1', 'V2'])

    # D: Voice Spots created AFTER StraboField's download (StraboField has no copy).
    scenario('D. Voice Spots made after the StraboField download, StraboField uploads')
    p, sw = new_project(); projects.append(p)
    sf = StraboField(); sf.project = p
    sf.download(p)
    vd = voice_create_dataset(p)
    voice_upload(vd, [vspot('V1'), vspot('V2')])
    sf.add_spot(sw, spot('SW1'))
    print('  upload:', sf.upload())
    check('Voice Spots on server', server_names(vd), ['V1', 'V2'])
    st, body = req('GET', f'/projectDatasets/{p}')
    check('Voice Spots still in the project', sorted(x['name'] for x in body['datasets']), ['Stopwatch Spots', 'Voice Spots'])

    # E: B again, but StraboField downloads again before editing.
    scenario('E. Download again in StraboField, then edit a voice Spot and upload')
    p, sw = new_project(); projects.append(p)
    vd = voice_create_dataset(p)
    voice_upload(vd, [vspot('V1')])
    sf = StraboField(); sf.project = p
    sf.download(p)
    time.sleep(1.1)
    voice_upload(vd, [vspot('V2'), vspot('V3')])
    sf.download(p)                     # fresh copy
    sf.edit_spot(vd, 'V1')
    print('  upload:', sf.upload())
    check('Voice Spots on server', server_names(vd), ['V1', 'V2', 'V3'])
    check('the StraboField edit of V1 stuck (newer copy)', server_prop(vd, 'V1', 'notes'), 'edited in StraboField')

    # F: Strabo Voice changes V1 after the StraboField download (a late photo
    # bumps its timestamp); StraboField uploads its older copy of V1.
    scenario('F. StraboField uploads an older copy of a voice Spot')
    p, sw = new_project(); projects.append(p)
    vd = voice_create_dataset(p)
    voice_upload(vd, [vspot('V1'), vspot('V2')])
    sf = StraboField(); sf.project = p
    sf.download(p)
    time.sleep(1.1)
    st, body = req('GET', f'/datasetSpots/{vd}')     # Strabo Voice: newer V1 (server copy + a change)
    feats = body['features']
    for f in feats:
        if f['properties']['name'] == 'V1':
            f['properties']['notes'] = 'newer in Strabo Voice'
            f['properties']['modified_timestamp'] = int(time.time() * 1000)
    req('POST', f'/datasetSpots/{vd}', {'type': 'FeatureCollection', 'features': feats})
    time.sleep(1.1)
    sf.edit_spot(vd, 'V2')                             # makes StraboField's whole copy "newer"
    print('  upload:', sf.upload())
    check('Voice Spots on server', server_names(vd), ['V1', 'V2'])
    check('V1 keeps the newer Strabo Voice copy', server_prop(vd, 'V1', 'notes'), 'newer in Strabo Voice')
    check('V2 takes the StraboField edit', server_prop(vd, 'V2', 'notes'), 'edited in StraboField')

    # G: ordinary StraboField Spots behave exactly as before.
    scenario('G. Ordinary StraboField Spots: delete and replace still work')
    p, sw = new_project(); projects.append(p)
    sf = StraboField(); sf.project = p
    sf.download(p)
    sf.add_spot(sw, spot('SW1')); sf.add_spot(sw, spot('SW2'))
    print('  upload 1:', sf.upload())
    time.sleep(1.1)
    sf.datasets[sw]['spots'] = [f for f in sf.datasets[sw]['spots'] if f['properties']['name'] != 'SW2']
    sf.edit_spot(sw, 'SW1')
    print('  upload 2:', sf.upload())
    check('SW2 deleted on the server', server_names(sw), ['SW1'])
    check('SW1 replaced by the edit', server_prop(sw, 'SW1', 'notes'), 'edited in StraboField')
finally:
    if '--keep' not in sys.argv:
        for p in projects:
            req('DELETE', f'/project/{p}')
    print('\nprojects:', projects, '(deleted)' if '--keep' not in sys.argv else '(kept)')

print(f"\n{sum(results)}/{len(results)} checks as hoped")
