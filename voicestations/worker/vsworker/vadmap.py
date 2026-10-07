"""Word times back onto the original audio timeline, and our transcript format.

whisper-server's VAD keeps only the speech, transcribes the shortened audio,
and maps segment times back, but not word times (see whisper.py). Its log
gives the mapping, one line per speech span:

  whisper_vad: vad_segment_info: orig_start: 4.02, orig_end: 7.72, vad_start: 1.56, vad_end: 5.26

Inside a span, original = orig_start + (t - vad_start). Between spans
(VAD overlap), interpolate linearly. Before the first / after the last span,
shift by that span's offset.
"""

import json
import re

SPAN = re.compile(r'vad_segment_info: orig_start: ([0-9.]+), orig_end: ([0-9.]+), '
                  r'vad_start: ([0-9.]+), vad_end: ([0-9.]+)')


def parse_spans(lines):
    spans = []
    for line in lines:
        m = SPAN.search(line)
        if m:
            spans.append(tuple(float(x) for x in m.groups()))
    return spans


def to_original(t, spans):
    if not spans:
        return t
    for i, (os_, oe, vs, ve) in enumerate(spans):
        if t < vs:
            if i == 0:
                return max(0.0, os_ + (t - vs))
            pos, poe, pvs, pve = spans[i - 1]
            gap = vs - pve
            frac = (t - pve) / gap if gap > 0 else 0.0
            return poe + frac * (os_ - poe)
        if t <= ve:
            return os_ + (t - vs)
    os_, oe, vs, ve = spans[-1]
    return oe + (t - ve)


def r2(x):
    return round(float(x), 2)


def normalize(raw_text, log_lines):
    """whisper verbose_json text + its log -> (output, word_times method).

    output = {"text", "segments": [{start, end, text}], "words": [{w, start, end, p}]}
    word_times = "vad_remapped" (log mapping), "segment_shifted" (no mapping
    found: each segment's words shifted so the first starts at the segment
    start), or "none" (no words).
    """
    d = json.loads(raw_text)
    segs = d.get('segments') or []
    spans = parse_spans(log_lines)
    method = 'vad_remapped' if spans else 'segment_shifted'
    out_segs, out_words = [], []
    for g in segs:
        out_segs.append({'start': r2(g['start']), 'end': r2(g['end']), 'text': g.get('text', '').strip()})
        words = g.get('words') or []
        shift = 0.0
        if not spans and words:
            shift = float(g['start']) - float(words[0]['start'])
        for w in words:
            if spans:
                s, e = to_original(float(w['start']), spans), to_original(float(w['end']), spans)
            else:
                s, e = float(w['start']) + shift, float(w['end']) + shift
            p = w.get('probability')
            out_words.append({'w': w.get('word', '').strip(), 'start': r2(s), 'end': r2(max(s, e)),
                              'p': None if p is None else round(float(p), 4)})
    if not out_words:
        method = 'none'
    text = ' '.join(s['text'] for s in out_segs if s['text'])
    return {'text': text, 'segments': out_segs, 'words': out_words}, method
