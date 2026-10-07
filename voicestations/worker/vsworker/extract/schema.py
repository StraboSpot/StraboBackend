"""What the LLM returns (prompt v2, the "pointing" format): values as spoken,
each with the exact transcript words it came from. Our code does every
conversion afterwards (checks.py).

Kept FLAT for structured outputs: no nullable fields (a schema with many
anyOf-null fields compiled to a grammar the API rejects as too large). Each
measurement lists what was spoken as {field, value, quote} entries; "none" is
-1 for lies_on, 0 for carried_from_station, "" for a quote. to_internal() turns
this into the per-field dict checks.py works on.
"""

PROMPT_VERSION = 'v2'

NUMBER_FIELDS = ['strike', 'dip', 'dip_direction', 'trend', 'plunge', 'quality']
WORD_FIELDS = ['strike_quadrant', 'dip_quadrant', 'movement', 'facing']


def _obj(props):
    return {'type': 'object', 'properties': props, 'required': list(props), 'additionalProperties': False}


STR = {'type': 'string'}
NUM = {'type': 'number'}
INT = {'type': 'integer'}


def _list(item):
    return {'type': 'array', 'items': item}


MEASUREMENT = _obj({
    'kind': {'type': 'string', 'enum': ['planar', 'linear']},
    'feature_type': STR,
    'source_quote': STR,
    'numbers': _list(_obj({'field': {'type': 'string', 'enum': NUMBER_FIELDS}, 'value': NUM, 'quote': STR})),
    'words': _list(_obj({'field': {'type': 'string', 'enum': WORD_FIELDS}, 'value': STR, 'quote': STR})),
    'lies_on': INT,
    'corrected': _list(_obj({'field': STR, 'abandoned_value': NUM, 'quote': STR})),
    'unresolved': _list(_obj({'field': STR, 'values': _list(NUM), 'quote': STR})),
    'doubts': _list(_obj({'quote': STR})),
})

SCHEMA = _obj({
    'measurements': _list(MEASUREMENT),
    'rock_descriptions': _list(_obj({'text': STR, 'quote': STR, 'carried_from_station': INT, 'carried_from_quote': STR})),
    'samples': _list(_obj({'label': STR, 'description': STR, 'quote': STR})),
    'photos': _list(_obj({'caption': STR, 'quote': STR})),
    'notes': _list(_obj({'text': STR, 'quote': STR})),
})


def to_internal(data):
    """Flat LLM output -> the per-field shape checks.py reads:
    strike..plunge = number or None (from source_quote), quality / movement /
    facing = {value|spoken, quote} or None, quadrants = text or None,
    lies_on / carried_from = None when absent."""
    out = dict(data)
    ms = []
    for m in data.get('measurements') or []:
        x = {'kind': m.get('kind'), 'feature_type': m.get('feature_type'), 'source_quote': m.get('source_quote') or '',
             'corrected': m.get('corrected') or [], 'unresolved': m.get('unresolved') or [], 'doubts': m.get('doubts') or [],
             'lies_on': m.get('lies_on') if isinstance(m.get('lies_on'), int) and m.get('lies_on') >= 0 else None}
        for f in NUMBER_FIELDS + WORD_FIELDS:
            x[f] = None
        for n in m.get('numbers') or []:
            f = n.get('field')
            if f == 'quality':
                x['quality'] = {'value': int(n['value']) if float(n.get('value', 0)).is_integer() else n.get('value'),
                                'quote': n.get('quote') or ''}
            elif f in NUMBER_FIELDS and x[f] is None:
                x[f] = n.get('value')
        for w in m.get('words') or []:
            f = w.get('field')
            if f in ('movement', 'facing'):
                x[f] = {'spoken': w.get('value') or '', 'quote': w.get('quote') or ''}
            elif f in WORD_FIELDS and x[f] is None:
                x[f] = w.get('value')
        ms.append(x)
    out['measurements'] = ms
    rds = []
    for r in data.get('rock_descriptions') or []:
        st = r.get('carried_from_station')
        q = r.get('carried_from_quote') or ''
        rds.append({'text': r.get('text'), 'quote': r.get('quote'),
                    'carried_from': {'station': st, 'quote': q} if isinstance(st, int) and st >= 1 and q else None})
    out['rock_descriptions'] = rds
    return out
