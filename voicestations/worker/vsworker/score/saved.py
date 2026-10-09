"""What a confirmed recording SAVED, rebuilt from its confirm record + proposal.

The confirm record lists every value with its proposed and final value and the
action (unchanged / edited / removed / added); the proposal says what each ref
is (orientation plane or line, sample, note, photo) and which lines sit on
which plane (`associated`). Hand-added items (refs h1, h2...) are top level and
their kind follows from their fields.
"""

PLANE_NUMS = ('strike', 'dip', 'dip_direction')
LINE_NUMS = ('trend', 'plunge')
NUMBER_FIELDS = ('strike', 'dip', 'dip_direction', 'trend', 'plunge', 'quality')
LABEL_FIELDS = ('sample_id_name',)
NOTE_FIELDS = ('notes', 'text', 'sample_description')

# Where a saved value came from (scoring page point 3b).
ORIGIN = {'unchanged': 'proposal', 'edited': 'edited', 'added': 'typed'}


def field_kind(field):
    """numbers / labels / notes / words: the bar #2 breakdown (point 4)."""
    if field in NUMBER_FIELDS:
        return 'numbers'
    if field in LABEL_FIELDS:
        return 'labels'
    if field in NOTE_FIELDS:
        return 'notes'
    return 'words'


def _proposal_items(proposal):
    """ref -> (item, parent ref) for every proposal item, nested lines included."""
    out = {}

    def walk(items, parent):
        for it in items or []:
            out[it.get('ref')] = (it, parent)
            walk(it.get('associated'), it.get('ref'))
    walk((proposal or {}).get('items'), None)
    return out


def _kind(item, fields):
    if item is not None:
        k = item.get('kind')
        if k == 'orientation':
            t = (item.get('spot') or {}).get('type')
            if t == 'linear_orientation':
                return 'line'
            if t == 'planar_orientation':
                return 'plane'
        elif k in ('sample', 'note', 'photo'):
            return k
    # hand-added, or an orientation the proposal did not type: by its fields
    if any(f in fields for f in LINE_NUMS):
        return 'line'
    if any(f in fields for f in PLANE_NUMS):
        return 'plane'
    if 'sample_id_name' in fields or 'sample_description' in fields:
        return 'sample'
    return 'note'


def _quotes(item):
    """The transcript words this item and its values were read from."""
    if item is None:
        return []
    qs = [item.get('quote')] + [v.get('quote') for v in item.get('values') or []]
    return [q for q in qs if q]


def saved_items(record, proposal):
    """[{ref, kind, parent, card, values: {field: {final, action, origin, proposed}},
    quotes, order}] for every item that still has a saved value."""
    props = _proposal_items(proposal)
    by_ref = {}
    order = []
    for v in record.get('values') or []:
        ref = v.get('ref')
        if ref not in by_ref:
            by_ref[ref] = {}
            order.append(ref)
        by_ref[ref][v.get('field')] = v
    out = []
    for i, ref in enumerate(order):
        vals = by_ref[ref]
        item, parent = props.get(ref, (None, None))
        kept = {}
        for f, v in vals.items():
            if v.get('action') == 'removed':
                continue
            kept[f] = {'final': v.get('final'), 'action': v.get('action'),
                       'origin': ORIGIN.get(v.get('action'), v.get('action')), 'proposed': v.get('proposed')}
        if not kept:
            continue
        cards = {v.get('card') for v in vals.values()}
        out.append({'ref': ref, 'kind': _kind(item, set(vals)), 'parent': parent,
                    'card': sorted(c for c in cards if c)[0] if any(cards) else None,
                    'values': kept, 'quotes': _quotes(item), 'order': i})
    return out


def num(v):
    """A saved or key value as a number, or None."""
    if v is None or v == '':
        return None
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        return int(v) if float(v).is_integer() else float(v)
    try:
        f = float(str(v).strip())
    except ValueError:
        return None
    return int(f) if f.is_integer() else f


def final(item, field):
    v = item['values'].get(field)
    return None if v is None else v['final']
