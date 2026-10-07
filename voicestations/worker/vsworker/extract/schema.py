"""What the LLM returns (prompt v2, the "pointing" format): values as spoken,
each with the exact transcript words it came from. Our code does every
conversion afterwards (checks.py). Every object closes its properties
(additionalProperties false, all keys required), as structured outputs need.
"""

PROMPT_VERSION = 'v2'


def _obj(props):
    return {'type': 'object', 'properties': props, 'required': list(props), 'additionalProperties': False}


def _null(schema):
    return {'anyOf': [schema, {'type': 'null'}]}


NUM = _null({'type': 'number'})
STR = {'type': 'string'}
QUOTED_TEXT = _obj({'text': STR, 'quote': STR})

MEASUREMENT = _obj({
    'kind': {'type': 'string', 'enum': ['planar', 'linear']},
    'feature_type': STR,
    'source_quote': STR,
    'strike': NUM,
    'dip': NUM,
    'dip_direction': NUM,
    'strike_quadrant': _null(STR),
    'dip_quadrant': _null(STR),
    'trend': NUM,
    'plunge': NUM,
    'quality': _null(_obj({'value': {'type': 'integer'}, 'quote': STR})),
    'movement': _null(_obj({'spoken': STR, 'quote': STR})),
    'facing': _null(_obj({'spoken': STR, 'quote': STR})),
    'lies_on': _null({'type': 'integer'}),
    'corrected': {'type': 'array', 'items': _obj({'field': STR, 'abandoned_value': {'type': 'number'}, 'quote': STR})},
    'unresolved': {'type': 'array', 'items': _obj({'field': STR, 'values': {'type': 'array', 'items': {'type': 'number'}}, 'quote': STR})},
    'doubts': {'type': 'array', 'items': _obj({'quote': STR})},
})

SCHEMA = _obj({
    'measurements': {'type': 'array', 'items': MEASUREMENT},
    'rock_descriptions': {'type': 'array', 'items': _obj({
        'text': STR, 'quote': STR,
        'carried_from': _null(_obj({'station': {'type': 'integer'}, 'quote': STR})),
    })},
    'samples': {'type': 'array', 'items': _obj({'label': STR, 'description': STR, 'quote': STR})},
    'photos': {'type': 'array', 'items': _obj({'caption': STR, 'quote': STR})},
    'notes': {'type': 'array', 'items': QUOTED_TEXT},
})
