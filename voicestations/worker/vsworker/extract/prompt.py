"""Prompt v2. The fixed part (instructions + the app's form choices) is the
cached prefix; it changes only when the prompt version or the forms change.
The per-station part carries the earlier stations (read-only) and the
station being extracted.
"""

from .schema import PROMPT_VERSION

INSTRUCTIONS = """You extract geology field measurements from the transcript of ONE spoken field station.
A geologist pressed record, measured with a compass, and talked. The transcript came from
speech recognition, so numbers may be written as digits, words, or split digits
("0, 4, 8" or "zero four eight" means 048).

Your job is to POINT, not to calculate. Report each value exactly as spoken and quote the
words it came from. Our code does every conversion afterwards (dip direction to strike,
quadrant bearings to azimuths, right-hand rule). Never compute, convert or guess a number.

Every quote must be copied character for character from the CURRENT station's transcript
(the only exception is carried_from_quote, rule 13).

Rules:

1. One entry in "measurements" per measured feature. A second reading of the same kind of
   feature is its own entry.
2. kind = "planar" for planes (bedding, foliation, fault, joint, contact, vein, dike, ...),
   "linear" for lines (trend and plunge: slickenlines, mineral lineation, fold hinge, ...).
3. feature_type = one choice NAME from the lists below (the name, not the label).
4. source_quote = the transcript words that contain this measurement's strike / dip /
   dip direction or trend / plunge numbers.
5. "numbers": one entry per number that was SPOKEN for this measurement, as spoken:
   {field, value, quote}. field is strike, dip, dip_direction, trend, plunge or quality.
   - "strike 45 dip 32" -> strike 45, dip 32.
   - "dipping 70 toward 250" -> dip 70, dip_direction 250 (no strike entry).
   - Lines: trend and plunge.
   - quality: a 1-5 number only if spoken for this measurement, quoted with its own words
     (often a separate phrase, e.g. "I'd call it a 4").
   Leave out what was not said. Never fill a gap, never copy a number into a field it was
   not spoken for, and never turn a word like "vertical" into a number.
6. "words": compass words and spoken terms, as spoken, each with its quote:
   - Quadrant bearings ("north 60 west, 75 southwest") -> strike_quadrant "N60W" and
     dip_quadrant "SW", with dip 75 in "numbers" (no strike number).
   - A dip direction said as a compass word with an azimuth strike ("strike 160, dip 70
     west") -> strike 160 and dip 70 in "numbers", dip_quadrant "W" in "words".
   - movement: fault motion words as spoken ("normal", "reverse", "right-lateral").
   - facing: "upright" or "overturned" only if spoken.
7. Self-corrections ("30, no wait, 38"): use the corrected value and add the abandoned one to
   "corrected" with the field name and the quote containing it.
8. Two different values said for the same field with NO correction cue: put the value you
   think is meant in "numbers" and add {field, values, quote} to "unresolved".
9. Doubts and hedges ("roughly", "around", "rough guess", "might be off", "near my hammer"):
   add each to "doubts" with its exact quote, on the measurement it is about.
10. A line measured on a plane ("slickenlines on it", "lineation on the foliation"): set
    lies_on to the index (0-based) of that plane in "measurements"; otherwise -1.
11. A feature that is named but not measured (no numbers) is NOT a measurement: put it in
    "notes" instead.
12. rock_descriptions, samples, photos, notes: each item with its exact quote. Photo caption =
    what was said about the photo, nothing more. Sample label = the sample name as spoken.
13. Earlier stations of this batch are shown for reference only. Never take a measurement or
    a number from them. If the CURRENT station refers back to one ("same sandstone as
    before"), add the rock description with its own quote from the current station and set
    carried_from_station to that earlier station's number and carried_from_quote to the words
    quoted from ITS transcript. Otherwise carried_from_station is 0 and carried_from_quote "".
14. Ignore speech-recognition artifacts that were clearly not part of the observation
    (e.g. a trailing "Thank you.")."""


def _choices(vocab, form, field):
    ch = vocab['forms'].get(form, {}).get(field, {})
    return ', '.join(f'{name} (= {label})' if name != label else name for name, label in ch.items())


def fixed_part(vocab):
    """Instructions + the app's choice lists. Stable for a given prompt + vocab version."""
    planar = 'measurement.planar_orientation'
    linear = 'measurement.linear_orientation'
    return (f'[prompt {PROMPT_VERSION}, forms {vocab["version"]}]\n\n' + INSTRUCTIONS + '\n\n'
            'Planar feature_type names: ' + _choices(vocab, planar, 'feature_type') + '.\n\n'
            'Linear feature_type names: ' + _choices(vocab, linear, 'feature_type') + '.')


def station_part(job):
    """Earlier stations (read-only) + the station to extract."""
    parts = []
    earlier = job.get('earlier_stations') or []
    if earlier:
        parts.append('EARLIER STATIONS OF THIS BATCH (reference only, rule 13):')
        for i, e in enumerate(earlier, 1):
            parts.append(f'[Earlier station {i}] {e.get("transcript") or ""}')
        parts.append('')
    parts.append('CURRENT STATION TRANSCRIPT:')
    parts.append(job['transcript'].get('text') or '')
    return '\n'.join(parts)
