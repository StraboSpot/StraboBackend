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
    Words are whole spoken words (whisper's tokens joined), punctuation attached:
    "bedding,", "045,". p is the lowest token probability in the word.
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
        for k, w in enumerate(words):
            if spans:
                s, e = to_original(float(w['start']), spans), to_original(float(w['end']), spans)
            else:
                s, e = float(w['start']) + shift, float(w['end']) + shift
            p = w.get('probability')
            p = None if p is None else round(float(p), 4)
            piece = w.get('word', '')
            # whisper "words" are tokens: a new word starts with a space; anything
            # else ("ding" after "bed", "45" after "0", ",") continues the last one,
            # also across a segment break ("gra" | "ined" = "grained")
            if out_words and not piece.startswith(' '):
                last = out_words[-1]
                last['w'] += piece
                last['end'] = r2(max(last['end'], e))
                if p is not None:
                    last['p'] = p if last['p'] is None else min(last['p'], p)
                continue
            out_words.append({'w': piece.strip(), 'start': r2(s), 'end': r2(max(s, e)), 'p': p})
    if not out_words:
        method = 'none'
    # Segments are joined with a space, except one that starts without a space:
    # whisper split a word across the break, so it continues the last word
    text = ''
    for g, s in zip(segs, out_segs):
        if not s['text']:
            continue
        joined = text and not g.get('text', '')[:1].isspace()
        text += s['text'] if joined or not text else ' ' + s['text']
    return {'text': text, 'segments': out_segs, 'words': out_words}, method
