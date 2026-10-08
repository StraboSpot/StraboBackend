"""P7 checks and code conversions: the LLM's "pointing" output (schema.py) ->
the PROPOSAL the app reads (Field Spot field names, a source for every value,
dropped values with reasons, flags to tap) + the AUDIT kept for scoring.

Code does the arithmetic, the LLM only points. Order (Phase1_Plan.md step 3,
point 4): 5 schema -> 6 artifacts -> 1 quote in the transcript (else the WHOLE
measurement is dropped) -> 2 + 16 each number in its quote, split digits join
(else only that VALUE is dropped) -> conversions (computed / assumed) -> 3
ranges -> 18 label -> name, then 4 vocabulary -> flags 7-12, 14, 15, 17.

Thresholds live in THRESHOLDS (decided 09-28 from dev data).
"""

import math

from . import text as T

THRESHOLDS = {
    'line_on_plane_deg': 10.0,     # flag 7
    'gps_accuracy_m': 20.0,        # flag 15
    'strike_dd_tolerance_deg': 2.0,  # flag 17
}

PLANAR = 'measurement.planar_orientation'
LINEAR = 'measurement.linear_orientation'
RANGES = {'strike': 360, 'dip_direction': 360, 'trend': 360, 'dip': 90, 'plunge': 90}

# Spoken words the forms do not list under that label (P7 check 18 auto-fix)
SYNONYMS = {
    PLANAR: {'joint': 'option_13', 'joints': 'option_13', 'joint set': 'option_13',
             'beds': 'bedding', 'bed': 'bedding', 'cleavage': 'foliation', 'schistosity': 'foliation',
             'dyke': 'dike', 'shear zone': 'shear_zone', 'axial plane': 'fold_axial_surface',
             'axial surface': 'fold_axial_surface'},
    LINEAR: {'mineral lineation': 'mineral_align', 'mineral_lineation': 'mineral_align',
             'lineation': 'mineral_align', 'slickenline': 'slickenlines', 'slicks': 'slickenlines',
             'striae': 'striations', 'hinge': 'fold_hinge', 'fold axis': 'fold_hinge',
             'stretching lineation': 'stretching'},
}
MOVEMENT_WORDS = {'dextral': 'right_lateral', 'right lateral': 'right_lateral',
                  'sinistral': 'left_lateral', 'left lateral': 'left_lateral'}
COMPASS8 = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw']
DIR_WORDS = {'n': ['n', 'north'], 's': ['s', 'south'], 'e': ['e', 'east'], 'w': ['w', 'west'],
             'ne': ['ne', 'northeast', 'north east'], 'nw': ['nw', 'northwest', 'north west'],
             'se': ['se', 'southeast', 'south east'], 'sw': ['sw', 'southwest', 'south west']}
QUAD_RANGE = {'NE': (0, 90), 'SE': (90, 180), 'SW': (180, 270), 'NW': (270, 360),
              'N': (315, 45), 'E': (45, 135), 'S': (135, 225), 'W': (225, 315)}


def az8(az):
    return COMPASS8[int(((az % 360) + 22.5) // 45) % 8]


def in_quadrant(az, q):
    rng = QUAD_RANGE.get((q or '').upper().strip())
    if not rng:
        return False
    lo, hi = rng
    return lo <= az <= hi if lo < hi else (az >= lo or az <= hi)


def quadrant_az(q):
    """'N60W' -> 300."""
    import re
    m = re.fullmatch(r'\s*([NS])\s*(\d+(?:\.\d+)?)\s*([EW])\s*', (q or '').upper())
    if not m:
        return None, None
    a, v, b = m.group(1), float(m.group(2)), m.group(3)
    if a == 'N':
        az = v if b == 'E' else (360 - v) % 360
    else:
        az = 180 - v if b == 'E' else 180 + v
    return az, v


def num_out(x):
    """Whole numbers as int (JSON 45, not 45.0)."""
    return int(x) if float(x).is_integer() else round(float(x), 2)


class Transcript:
    """The current station's transcript: canonical tokens on the words (for
    word spans + audio) and on the text (fallback when words are missing)."""

    def __init__(self, out):
        self.words = out.get('words') or []
        self.text = out.get('text') or ''
        self.wtoks = T.canon_words(self.words)
        self.ttoks = T.canon_text(self.text)

    def locate(self, quote):
        """Quote -> (canonical quote tokens, [w0, w1] or None, [start, end] or None), or None if absent."""
        q = T.canon_text(quote)
        if not q:
            return None
        i = T.find(q, self.wtoks) if self.wtoks else -1
        if i >= 0:
            hit = self.wtoks[i:i + len(q)]
            span = [hit[0].w0, hit[-1].w1]
            return hit, span, self.audio(span)
        if T.find(q, self.ttoks) >= 0:
            return q, None, None
        return None

    def audio(self, span):
        if not span or not self.words:
            return None
        a, b = self.words[span[0]], self.words[span[1]]
        return [a.get('start'), b.get('end')]


class Builder:
    def __init__(self, job):
        self.job = job
        self.tr = Transcript(job['transcript'])
        self.vocab = job['vocab']['forms']
        self.audit = []
        self.dropped = []

    # -- bookkeeping
    def log(self, ref, check, field, value, outcome, reason=''):
        self.audit.append({'ref': ref, 'check': check, 'field': field, 'value': value,
                           'outcome': outcome, 'reason': reason})

    def drop(self, ref, check, field, value, quote, reason):
        self.dropped.append({'ref': ref, 'field': field, 'value': value, 'quote': quote, 'reason': reason})
        self.log(ref, check, field, value, 'dropped', reason)

    def where(self, hit):
        """Quote location -> {"words", "audio"}."""
        _, span, audio = hit
        return {'words': span, 'audio': audio}

    def value_span(self, hit, value):
        """Word span + audio of one number inside a located quote."""
        qtoks = hit[0]
        if hit[1] is None:  # located on the text only: no word indices
            return None, None
        nums = T.numbers(qtoks)
        pos = nums.get(int(value)) if float(value).is_integer() else None
        if pos is None:
            return None, None
        span = [qtoks[pos[0]].w0, qtoks[pos[1]].w1]
        return span, self.tr.audio(span)

    # -- vocabulary (check 18 then 4)
    def choice(self, form, field, spoken, ref):
        ch = self.vocab.get(form, {}).get(field, {})
        s = (spoken or '').strip()
        if s in ch:
            return s
        low = s.lower().replace('_', ' ').strip()
        for name, label in ch.items():
            if low == str(label).lower() or low == name.lower().replace('_', ' '):
                self.log(ref, 18, field, s, 'fixed', f'label -> choice name {name}')
                return name
        syn = SYNONYMS.get(form, {}).get(low)
        if field == 'feature_type' and syn and syn in ch:
            self.log(ref, 18, field, s, 'fixed', f'spoken term -> choice name {syn}')
            return syn
        return None

    # -- measurements
    def measurement(self, i, m):
        ref = f'm{i + 1}'
        kind = m.get('kind')
        form = PLANAR if kind == 'planar' else LINEAR
        q = m.get('source_quote') or ''
        if T.is_artifact(q):
            self.drop(ref, 6, None, None, q, 'A speech-recognition artifact is not a measurement.')
            return None
        hit = self.tr.locate(q)
        if hit is None:
            self.drop(ref, 1, None, None, q, 'These words are not in what you said, so the whole measurement was set aside.')
            return None
        qtoks = hit[0]
        nums = T.numbers(qtoks)
        item = {'ref': ref, 'kind': 'orientation',
                'spot': {'type': 'planar_orientation' if kind == 'planar' else 'linear_orientation'},
                'values': [], 'flags': [], 'suggestions': [], 'associated': [], 'quote': q}
        item.update(self.where(hit))
        notes = []
        spoken = {}

        allowed = ('strike', 'dip', 'dip_direction') if kind == 'planar' else ('trend', 'plunge')
        for f in ('strike', 'dip', 'dip_direction', 'trend', 'plunge'):
            v = m.get(f)
            if v is None:
                continue
            if f not in allowed:
                self.drop(ref, 5, f, v, q, f'A {kind} measurement has no {f.replace("_", " ")}.')
                continue
            if not isinstance(v, (int, float)) or not math.isfinite(v):
                self.drop(ref, 5, f, v, q, 'Not a number.')
                continue
            if not (float(v).is_integer() and int(v) in nums) and v not in nums:
                joined = T.split_run_digit(qtoks, int(v)) if float(v).is_integer() else None
                why = (f'{num_out(v)} is one digit of "{joined}"; only the whole number counts.' if joined is not None
                       else f'{num_out(v)} is not in what you said here.')
                self.drop(ref, 16 if joined is not None else 2, f, num_out(v), q, why)
                continue
            if not (0 <= v <= RANGES[f]):
                self.drop(ref, 3, f, num_out(v), q, f'{f.replace("_", " ")} must be 0-{RANGES[f]}.')
                continue
            spoken[f] = float(v)
            span, audio = self.value_span(hit, v)
            item['values'].append({'field': f, 'value': num_out(v), 'origin': 'spoken', 'quote': q,
                                   'words': span or item['words'], 'audio': audio or item['audio']})
            self.log(ref, 2, f, num_out(v), 'kept')

        if kind == 'planar':
            self.planar(ref, m, q, qtoks, nums, spoken, item, notes)
        else:
            for f in ('trend', 'plunge'):
                if f in spoken:
                    item['spot'][f] = num_out(spoken[f])
            missing = [f for f in ('trend', 'plunge') if f not in spoken]
            if missing and spoken:
                self.flag(item, 'incomplete', f'Incomplete: no {" or ".join(missing)} was said. Nothing was filled in.')
            elif not spoken:
                self.flag(item, 'incomplete', 'No usable numbers were kept for this line.')

        # feature type (18 then 4)
        ft = m.get('feature_type')
        name = self.choice(form, 'feature_type', ft, ref)
        if name:
            item['spot']['feature_type'] = name
            self.log(ref, 4, 'feature_type', ft, 'kept' if name == ft else 'fixed')
        else:
            self.drop(ref, 4, 'feature_type', ft, q, f'"{ft}" is not a {kind} feature type in the form.')

        self.extras(ref, m, form, kind, item, notes)

        for c in m.get('corrected') or []:
            ch = self.tr.locate(c.get('quote') or '')
            av = c.get('abandoned_value')
            if ch and isinstance(av, (int, float)) and (int(av) in T.numbers(ch[0]) if float(av).is_integer() else False):
                self.flag(item, 'corrected', f'Corrected: {c.get("field", "").replace("_", " ")} {num_out(av)} was replaced; the earlier value went to notes.',
                          quote=c.get('quote'), field=c.get('field'), loc=self.where(ch))
                notes.append(f'Corrected {c.get("field", "").replace("_", " ")}: "{c.get("quote")}"')
            else:
                self.log(ref, 9, c.get('field'), av, 'ignored', 'correction quote or value not found')
        for u in m.get('unresolved') or []:
            uh = self.tr.locate(u.get('quote') or '')
            nums_u = [num_out(x) for x in (u.get('values') or []) if isinstance(x, (int, float))]
            vals = ', '.join(str(x) for x in nums_u)
            self.flag(item, 'two_values', f'Two values were said for {u.get("field", "").replace("_", " ")} ({vals}) with no correction. Check which is right.',
                      quote=u.get('quote'), field=u.get('field'), loc=self.where(uh) if uh else None)
            item['flags'][-1]['values'] = nums_u  # the app offers one "Use N" button per value
        for d in m.get('doubts') or []:
            dh = self.tr.locate(d.get('quote') or '')
            if dh:
                self.flag(item, 'doubt', f'You sounded unsure: "{d.get("quote")}". The words went to notes.',
                          quote=d.get('quote'), loc=self.where(dh))
                notes.append(f'Doubt: "{d.get("quote")}"')
            else:
                self.log(ref, 10, None, d.get('quote'), 'ignored', 'doubt quote not found')
        if notes:
            item['spot']['notes'] = '\n'.join(notes)
        return item

    def planar(self, ref, m, q, qtoks, nums, spoken, item, notes):
        s, dd, dip = spoken.get('strike'), spoken.get('dip_direction'), spoken.get('dip')
        how = None
        sq, dq = m.get('strike_quadrant'), m.get('dip_quadrant')
        dq_ok = None
        if dq:
            words = DIR_WORDS.get(dq.strip().lower())
            qtext = ' '.join(t.t for t in qtoks)
            if words and any(f' {w} ' in f' {qtext} ' for w in words):
                dq_ok = dq.strip().upper()
            else:
                self.drop(ref, 1, 'dip_quadrant', dq, q, f'"{dq}" is not in what you said here.')
        if sq:
            az, v = quadrant_az(sq)
            if az is None or not (float(v).is_integer() and int(v) in nums):
                self.drop(ref, 2, 'strike_quadrant', sq, q, f'"{sq}" does not match what you said here.')
            elif s is None and dd is None:
                cands = [x for x in ((az + 90) % 360, (az - 90) % 360) if dq_ok and in_quadrant(x, dq_ok)]
                if len(cands) == 1:
                    dd = cands[0]
                    s = (dd - 90) % 360
                    how = f'from {sq.upper()} dipping {dq_ok} (right-hand rule)'
                    self.computed(item, 'dip_direction', dd, how)
                    self.computed(item, 'strike', s, how)
                else:
                    self.flag(item, 'incomplete', f'{sq.upper()} needs a dip direction (e.g. "75 southwest") to be used; nothing was filled in.')
        if s is not None and dd is not None and 'strike' in spoken and 'dip_direction' in spoken:
            diff = (dd - s) % 360
            if abs(diff - 90) > THRESHOLDS['strike_dd_tolerance_deg']:
                # check 17: set the dip direction aside, keep the strike
                item['values'] = [v for v in item['values'] if v['field'] != 'dip_direction']
                self.flag(item, 'strike_dd_mismatch',
                          f'Strike {num_out(s)} and dip direction {num_out(dd)} are not 90 degrees apart (right-hand rule); the dip direction was set aside.',
                          field='dip_direction')
                self.log(ref, 17, 'dip_direction', num_out(dd), 'flagged', f'{num_out(diff)} apart')
                dd = None
                spoken.pop('dip_direction')
                spoken['_dd_set_aside'] = True
        if s is None and dd is not None and 'dip_direction' in spoken:
            s = (dd - 90) % 360
            self.computed(item, 'strike', s, 'dip direction - 90 (right-hand rule)')
        elif s is not None and dd is None and 'strike' in spoken and not spoken.get('_dd_set_aside'):
            if dq_ok and in_quadrant((s - 90) % 360, dq_ok) and not in_quadrant((s + 90) % 360, dq_ok):
                # strike given with the dip to its left: convert to right-hand rule
                s2 = (s + 180) % 360
                item['values'] = [v for v in item['values'] if v['field'] != 'strike']
                self.computed(item, 'strike', s2, f'spoken strike {num_out(s)} dipping {dq_ok}, turned to right-hand rule')
                s, dd = s2, (s2 + 90) % 360
                self.computed(item, 'dip_direction', dd, 'strike + 90 (right-hand rule)')
            else:
                dd = (s + 90) % 360
                said = any(' '.join(t.t for t in self.tr.ttoks[i:i + 3]) == 'right hand rule' for i in range(len(self.tr.ttoks)))
                conv = self.job.get('station', {}).get('strike_convention') or 'rhr'
                if said or (dq_ok and in_quadrant(dd, dq_ok)):
                    self.computed(item, 'dip_direction', dd, 'strike + 90 (right-hand rule)')
                else:
                    self.computed(item, 'dip_direction', dd, 'strike + 90 (right-hand rule, your default)', origin='assumed')
                    self.flag(item, 'assumed_convention',
                              'No convention was said; right-hand rule was assumed' + ('' if conv == 'rhr' else ' (your setting is dip direction: check)') + '.',
                              field='dip_direction')
                    if conv == 'rhr':
                        # 5f (Jason 10-08): the user's own setting, so the app shows it as a
                        # note with "Dips the other way", not a flag that waits for a tap
                        item['flags'][-1]['soft'] = True
        if s is not None:
            item['spot']['strike'] = num_out(s)
        if dd is not None:
            item['spot']['dip_direction'] = num_out(dd)
        if dip is not None:
            item['spot']['dip'] = num_out(dip)
        missing = []
        if s is None:
            missing.append('strike or dip direction')
        if dip is None:
            missing.append('dip')
        if missing:
            self.flag(item, 'incomplete', f'Incomplete: no {" or ".join(missing)} was kept. Nothing was filled in.')

    def computed(self, item, field, value, how, origin='computed'):
        item['values'].append({'field': field, 'value': num_out(value), 'origin': origin, 'how': how,
                               'quote': item['quote'], 'words': item['words'], 'audio': item['audio']})
        self.log(item['ref'], 'convert', field, num_out(value), origin, how)

    def flag(self, item, code, message, quote=None, field=None, loc=None):
        f = {'code': code, 'message': message}
        if field:
            f['field'] = field
        if quote:
            f['quote'] = quote
            if loc:
                f.update(loc)
        item['flags'].append(f)
        self.log(item.get('ref'), 'flag', field, code, 'flagged', message)

    def extras(self, ref, m, form, kind, item, notes):
        qv = m.get('quality')
        if qv:
            h = self.tr.locate(qv.get('quote') or '')
            v = qv.get('value')
            if h and isinstance(v, int) and 1 <= v <= 5 and v in T.numbers(h[0]):
                name = self.choice(form, 'quality', str(v), ref)
                if name:
                    item['spot']['quality'] = name
                    item['values'].append({'field': 'quality', 'value': v, 'origin': 'spoken', 'quote': qv['quote'], **self.where(h)})
                else:
                    self.drop(ref, 4, 'quality', v, qv.get('quote'), 'Not a quality choice in the form.')
            else:
                self.drop(ref, 2, 'quality', v, qv.get('quote'), 'Quality must be 1-5 and in what you said.')
        mv = m.get('movement') if kind == 'planar' else None
        if mv:
            h = self.tr.locate(mv.get('quote') or '')
            sp = (mv.get('spoken') or '').strip()
            if h and sp and T.find(T.canon_text(sp), h[0]) >= 0:
                name = self.choice(form, 'movement', sp, ref) or MOVEMENT_WORDS.get(sp.lower())
                if name:
                    item['spot']['movement'] = name
                    item['values'].append({'field': 'movement', 'value': name, 'origin': 'spoken', 'quote': mv['quote'], **self.where(h)})
                else:
                    # Q7: no form choice for the spoken motion -> other + the words
                    item['spot']['movement'] = 'other'
                    item['spot']['other_movement'] = sp
                    item['values'].append({'field': 'other_movement', 'value': sp, 'origin': 'spoken', 'quote': mv['quote'], **self.where(h)})
                    self.side_up(item, sp)
            else:
                self.drop(ref, 1, 'movement', sp, mv.get('quote'), 'Those words are not in what you said here.')
        fc = m.get('facing') if kind == 'planar' else None
        if fc:
            h = self.tr.locate(fc.get('quote') or '')
            sp = (fc.get('spoken') or '').strip()
            name = self.choice(form, 'facing', sp, ref) if h and T.find(T.canon_text(sp), h[0]) >= 0 else None
            if name:
                item['spot']['facing'] = name
                item['values'].append({'field': 'facing', 'value': name, 'origin': 'spoken', 'quote': fc['quote'], **self.where(h)})
            else:
                self.drop(ref, 4, 'facing', sp, fc.get('quote'), 'Not a facing choice in the form, or not in what you said.')

    def side_up(self, item, spoken):
        """Q7: a code-computed 'X side up' the reviewer may tap (never pre-accepted)."""
        dd = item['spot'].get('dip_direction')
        w = spoken.lower()
        if dd is None:
            return
        if 'normal' in w:
            side, why = az8((dd + 180) % 360), 'normal fault: the hanging wall (dip side) moved down'
        elif 'reverse' in w or 'thrust' in w:
            side, why = az8(dd), 'reverse fault: the hanging wall (dip side) moved up'
        else:
            return
        name = f'{side}_side_up'
        if name in self.vocab.get(PLANAR, {}).get('movement', {}):
            item['suggestions'].append({'field': 'movement', 'value': name,
                                        'how': f'{why}; dips toward {num_out(dd)}'})

    # -- other items
    def quoted_items(self, kind, rows, prefix, make):
        out = []
        for i, r in enumerate(rows or []):
            ref = f'{prefix}{len(out) + 1}'
            q = r.get('quote') or ''
            if T.is_artifact(q):
                self.drop(ref, 6, kind, q, q, 'A speech-recognition artifact.')
                continue
            h = self.tr.locate(q)
            if h is None:
                self.drop(ref, 1, kind, r.get('text') or r.get('caption') or r.get('label'), q, 'These words are not in what you said.')
                continue
            it = make(ref, r, q, h)
            if it:
                out.append(it)
        return out

    def note(self, ref, r, q, h):
        it = {'ref': ref, 'kind': 'note', 'text': q, 'quote': q, 'flags': [], **self.where(h)}
        cf = r.get('carried_from')
        if cf:
            earlier = self.job.get('earlier_stations') or []
            n = cf.get('station')
            ok = isinstance(n, int) and 1 <= n <= len(earlier)
            et = T.canon_text(earlier[n - 1].get('transcript') or '') if ok else []
            cq = T.canon_text(cf.get('quote') or '')
            if ok and cq and T.find(cq, et) >= 0:
                it['carried'] = {'station_uuid': earlier[n - 1].get('station_uuid'), 'text': cf['quote']}
                self.flag(it, 'carried', f'Carried from an earlier recording: "{cf["quote"]}". Keep it only if it applies here.',
                          quote=cf['quote'])
            else:
                self.log(ref, 14, 'carried_from', cf.get('quote'), 'dropped', 'not found in that earlier transcript')
        return it

    def sample(self, ref, r, q, h):
        it = {'ref': ref, 'kind': 'sample', 'spot': {'sample_description': q}, 'quote': q, 'flags': [], **self.where(h)}
        label = (r.get('label') or '').strip()
        qa = ''.join(t.t for t in h[0])
        la = ''.join(t.t for t in T.canon_text(label))
        if label and la and la in qa:
            it['spot']['sample_id_name'] = label
        else:
            self.flag(it, 'incomplete', 'No sample name was found in what you said; add one.')
        return it

    def photo(self, ref, r, q, h):
        start = (h[2] or [None])[0]
        return {'ref': ref, 'kind': 'photo', 'caption': q, 'quote': q, 'said_at': start, 'flags': [], **self.where(h)}

    # -- whole proposal
    def build(self, raw):
        items = []
        ms = raw.get('measurements') or []
        built = [self.measurement(i, m) for i, m in enumerate(ms)]
        # lines on planes (flag 7) and nesting
        nested = set()
        for i, (m, it) in enumerate(zip(ms, built)):
            j = m.get('lies_on')
            if it is None or it['spot']['type'] != 'linear_orientation' or not isinstance(j, int):
                continue
            if 0 <= j < len(built) and j != i and built[j] is not None and built[j]['spot']['type'] == 'planar_orientation':
                self.misfit(built[j], it)
                built[j]['associated'].append(it)
                nested.add(i)
            else:
                self.log(it['ref'], 7, 'lies_on', j, 'ignored', 'no kept plane at that index')
        items += [it for i, it in enumerate(built) if it is not None and i not in nested]
        notes = self.quoted_items('rock description', raw.get('rock_descriptions'), 'r', self.note)
        notes += self.quoted_items('note', raw.get('notes'), 'n', self.note)
        seen = set()
        for it in notes:  # the same words listed twice (as a rock description and a note) show once
            k = ' '.join(t.t for t in T.canon_text(it['quote']))
            if k in seen:
                self.log(it['ref'], 'duplicate', 'note', it['quote'], 'merged', 'same words as an earlier note')
                continue
            seen.add(k)
            items.append(it)
        items += self.quoted_items('sample', raw.get('samples'), 's', self.sample)
        items += self.quoted_items('photo', raw.get('photos'), 'p', self.photo)

        station_flags = []
        fix = (self.job.get('station') or {}).get('best_fix')
        if not fix:
            station_flags.append({'code': 'no_location', 'message': 'No GPS location was recorded for this recording.'})
        elif fix.get('accuracy') is not None and fix['accuracy'] > THRESHOLDS['gps_accuracy_m']:
            station_flags.append({'code': 'gps_poor', 'message': f'Best GPS fix was +-{num_out(fix["accuracy"])} m.'})
        return {'format': 1, 'items': items, 'dropped': self.dropped, 'station_flags': station_flags}

    def misfit(self, plane, line):
        s, d = plane['spot'].get('strike'), plane['spot'].get('dip')
        t, p = line['spot'].get('trend'), line['spot'].get('plunge')
        if None in (s, d, t, p):
            return
        dd = math.radians((s + 90) % 360)
        di = math.radians(d)
        n = (math.sin(di) * math.sin(dd), math.sin(di) * math.cos(dd), math.cos(di))  # pole (E, N, Up)
        tr, pl = math.radians(t), math.radians(p)
        v = (math.cos(pl) * math.sin(tr), math.cos(pl) * math.cos(tr), -math.sin(pl))
        ang = math.degrees(math.asin(min(1.0, abs(sum(a * b for a, b in zip(n, v))))))
        want = math.degrees(math.atan(math.tan(di) * abs(math.sin(math.radians(t - s)))))
        self.log(line['ref'], 7, 'misfit_deg', round(ang, 1), 'measured', f'plunge on the plane at that trend {round(want, 1)}')
        if ang > THRESHOLDS['line_on_plane_deg']:
            self.flag(line, 'line_off_plane',
                      f'This line is {round(ang)} degrees off its plane; a line trending {num_out(t)} on that plane would plunge about {round(want)}.')


def process(raw, job):
    """LLM output (dict) + the extract job -> (proposal, audit)."""
    b = Builder(job)
    proposal = b.build(raw)
    audit = {'thresholds': THRESHOLDS, 'vocab_version': job['vocab']['version'], 'events': b.audit,
             'counts': {'items': len(proposal['items']), 'dropped': len(proposal['dropped'])}}
    return proposal, audit
