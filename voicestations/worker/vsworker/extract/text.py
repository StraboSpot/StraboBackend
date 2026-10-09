"""Transcript text: tolerant matching and the numbers a quote can mean.

Speech recognition writes numbers many ways: "045", "zero four five", "0-4-5",
"forty five", "one fifty". Quotes are matched and numbers read on a CANONICAL
token stream where each of those becomes the same tokens, and every token
remembers which transcript word(s) it came from (for highlighting and audio).

Canonical rules:
  - lower case, punctuation dropped, hyphens split ("0-4-5" -> 0 4 5)
  - number words -> digits ("four" -> 4, "forty" -> 40); "forty five" -> 45
  - a multi-digit token with a leading zero splits into digits ("045" -> 0 4 5),
    so it equals "zero four five"

Numbers a quote supports (P7 checks 2 and 16):
  - every numeric token
  - a run of 2+ single digits supports ONLY the joined number: "0, 5, 2" -> 52,
    never 0, 5 or 2 alone (qwen3 / gemma read it as 0 in the 10-06 test)
  - spoken hundreds shorthand "one fifty" -> 150 only (the same trap)
"""

import re

ONES = ('zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen '
        'fifteen sixteen seventeen eighteen nineteen').split()
TENS = {'twenty': 20, 'thirty': 30, 'forty': 40, 'fifty': 50, 'sixty': 60, 'seventy': 70,
        'eighty': 80, 'ninety': 90}
EXTRA_WORDS = {'oh': 0}  # "zero four five" is often heard as "oh four five"


def raw_tokens(s):
    s = (s or '').lower().replace('-', ' ').replace('‐', ' ').replace('–', ' ')
    s = re.sub(r'[^a-z0-9 ]+', ' ', s)
    return s.split()


class Tok:
    """One canonical token: text, the source word span [w0, w1], and origin."""
    __slots__ = ('t', 'w0', 'w1', 'word')

    def __init__(self, t, w0, w1, word):
        self.t, self.w0, self.w1, self.word = t, w0, w1, word

    def __repr__(self):
        return f'{self.t}@{self.w0}-{self.w1}'


def canonical(pairs):
    """[(raw token, word index)] -> [Tok]."""
    out = []
    for tok, wi in pairs:
        if tok in ONES:
            out.append(Tok(str(ONES.index(tok)), wi, wi, True))
        elif tok in TENS:
            out.append(Tok(str(TENS[tok]), wi, wi, True))
        elif tok in EXTRA_WORDS and out and out[-1].t.isdigit():
            out.append(Tok(str(EXTRA_WORDS[tok]), wi, wi, True))
        else:
            out.append(Tok(tok, wi, wi, False))
    # forty five -> 45
    merged = []
    for t in out:
        if (merged and t.word and merged[-1].word and t.t.isdigit() and merged[-1].t.isdigit()
                and int(merged[-1].t) in TENS.values() and 1 <= int(t.t) <= 9):
            p = merged[-1]
            merged[-1] = Tok(str(int(p.t) + int(t.t)), p.w0, t.w1, True)
        else:
            merged.append(t)
    # 045 -> 0 4 5
    final = []
    for t in merged:
        if t.t.isdigit() and len(t.t) > 1 and t.t[0] == '0':
            final.extend(Tok(c, t.w0, t.w1, t.word) for c in t.t)
        else:
            final.append(t)
    return final


def canon_text(s):
    return canonical([(t, i) for i, t in enumerate(raw_tokens(s))])


def canon_words(words):
    """Transcript words [{"w": ...}] -> [Tok] with word indices."""
    pairs = []
    for i, w in enumerate(words):
        for t in raw_tokens(w.get('w', '')):
            pairs.append((t, i))
    return canonical(pairs)


def find(needle, hay):
    """Index of the first contiguous match of Tok list needle in hay, or -1."""
    n = [t.t for t in needle]
    h = [t.t for t in hay]
    if not n:
        return -1
    for i in range(len(h) - len(n) + 1):
        if h[i:i + len(n)] == n:
            return i
    return -1


def numbers(toks):
    """Numbers a canonical token list supports -> {value: (first tok, last tok)}."""
    vals = {}
    n = len(toks)
    used = [False] * n
    single = [t.t.isdigit() and len(t.t) == 1 for t in toks]
    i = 0
    while i < n:
        if single[i]:
            j = i
            while j < n and single[j]:
                j += 1
            if j - i >= 2:
                vals.setdefault(int(''.join(toks[k].t for k in range(i, j))), (i, j - 1))
                for k in range(i, j):
                    used[k] = True
            i = j
        else:
            i += 1
    for i in range(n - 1):
        a, b = toks[i], toks[i + 1]
        if (a.word and b.word and a.t.isdigit() and b.t.isdigit() and not used[i] and not used[i + 1]
                and 1 <= int(a.t) <= 3 and 10 <= int(b.t) <= 99):
            vals.setdefault(int(a.t) * 100 + int(b.t), (i, i + 1))
            used[i] = used[i + 1] = True
    for i, t in enumerate(toks):
        if not used[i] and t.t.isdigit():
            vals.setdefault(int(t.t), (i, i))
    return vals


def split_run_digit(toks, value):
    """The joined number when value is one digit of a split-digit run, else None (check 16)."""
    if not (0 <= value <= 9):
        return None
    single = [t.t.isdigit() and len(t.t) == 1 for t in toks]
    i, n = 0, len(toks)
    while i < n:
        if single[i]:
            j = i
            while j < n and single[j]:
                j += 1
            if j - i >= 2 and any(int(toks[k].t) == value for k in range(i, j)):
                return int(''.join(toks[k].t for k in range(i, j)))
            i = j
        else:
            i += 1
    return None


ARTIFACTS = {'thank you', 'thanks for watching', 'thank you for watching', 'blank audio', 'music', 'you'}


def is_artifact(quote):
    """Speech-recognition junk never counts as a source (P7 check 6)."""
    q = ' '.join(raw_tokens(quote))
    return q in ARTIFACTS or 'blank audio' in q
