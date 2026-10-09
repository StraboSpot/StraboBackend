"""Extraction providers behind one contract (P4: switching must be easy).

  adapter.extract(fixed, station) -> Reply(raw_text, data, meta)
    fixed    the cached instructions + form choices (prompt.fixed_part)
    station  earlier stations + the current transcript (prompt.station_part)
    data     the parsed JSON (schema.SCHEMA), checked by the caller
    meta     provider, model, served_by, effort, tokens, request id, seconds

Errors become ProviderError(message for people, retry?).

Phase 1 (step 3 point 1): Claude is the only LIVE extractor; Ollama is the
manual switch for offline use and the regression comparison.
"""

import json
import time

from .schema import SCHEMA


class ProviderError(Exception):
    def __init__(self, message, retry, detail=''):
        super().__init__(f'{message} ({detail})' if detail else message)
        self.message = message
        self.retry = retry
        self.detail = detail


class Reply:
    def __init__(self, raw_text, data, meta):
        self.raw_text, self.data, self.meta = raw_text, data, meta


UNAVAILABLE = 'The proposal service is unavailable right now; it will be tried again.'


class AnthropicAdapter:
    """Claude via the official SDK: structured output against our schema,
    the fixed prompt cached, effort set explicitly (Opus 5.5 always thinks;
    its default effort is medium), refusal fallback on (server picks a
    model by refusal category; the model that answered is recorded)."""

    name = 'anthropic'

    def __init__(self, model, effort, max_tokens=32000):
        import anthropic
        self.anthropic = anthropic
        self.client = anthropic.Anthropic(max_retries=3, timeout=600)
        self.model, self.effort, self.max_tokens = model, effort, max_tokens

    def extract(self, fixed, station):
        a = self.anthropic
        t0 = time.time()
        try:
            with self.client.beta.messages.stream(
                model=self.model,
                max_tokens=self.max_tokens,
                betas=['server-side-fallback-2026-07-01'],
                system=[{'type': 'text', 'text': fixed, 'cache_control': {'type': 'ephemeral'}}],
                messages=[{'role': 'user', 'content': station}],
                output_config={'effort': self.effort, 'format': {'type': 'json_schema', 'schema': SCHEMA}},
                extra_body={'fallbacks': 'default'},
            ) as stream:
                msg = stream.get_final_message()
        except (a.RateLimitError, a.InternalServerError, a.APIConnectionError, a.APITimeoutError) as e:
            raise ProviderError(UNAVAILABLE, True, f'{type(e).__name__}: {e}')
        except a.APIStatusError as e:
            if e.status_code >= 500 or e.status_code in (408, 409, 429, 529):
                raise ProviderError(UNAVAILABLE, True, f'HTTP {e.status_code}: {e.message}')
            # 400/401/403/404: our request or our key is wrong; retrying will not help
            raise ProviderError('The proposal service refused the request; an administrator has to look at it.',
                                False, f'HTTP {e.status_code}: {e.message}')
        secs = time.time() - t0
        if msg.stop_reason == 'refusal':
            cat = getattr(getattr(msg, 'stop_details', None), 'category', None)
            raise ProviderError('The proposal service declined this recording; enter it by hand.', False, f'refusal {cat}')
        if msg.stop_reason == 'max_tokens':
            raise ProviderError(UNAVAILABLE, True, 'hit max_tokens')
        raw = ''.join(b.text for b in msg.content if b.type == 'text')
        try:
            data = json.loads(raw)
        except ValueError as e:
            raise ProviderError(UNAVAILABLE, True, f'not JSON: {e}')
        u = msg.usage
        meta = {
            'provider': self.name, 'model': self.model, 'served_by': msg.model, 'effort': self.effort,
            'request_id': getattr(msg, '_request_id', None), 'stop_reason': msg.stop_reason,
            'input_tokens': u.input_tokens, 'output_tokens': u.output_tokens,
            'cache_read_tokens': getattr(u, 'cache_read_input_tokens', None) or 0,
            'cache_write_tokens': getattr(u, 'cache_creation_input_tokens', None) or 0,
            'seconds': round(secs, 2),
        }
        return Reply(raw, data, meta)


class OllamaAdapter:
    """Local model through Ollama's /api/chat with the same schema as its
    output format (temperature 0, fixed seed). Manual switch only."""

    name = 'ollama'

    def __init__(self, model, url, think=True):
        import requests
        self.requests = requests
        self.model, self.url, self.think = model, url.rstrip('/'), think

    def extract(self, fixed, station):
        t0 = time.time()
        body = {'model': self.model, 'stream': False, 'format': SCHEMA, 'think': self.think,
                'options': {'temperature': 0, 'seed': 1, 'num_ctx': 16384},
                'messages': [{'role': 'system', 'content': fixed}, {'role': 'user', 'content': station}]}
        try:
            r = self.requests.post(self.url + '/api/chat', json=body, timeout=900)
        except self.requests.RequestException as e:
            raise ProviderError(UNAVAILABLE, True, f'ollama: {e}')
        if r.status_code != 200:
            raise ProviderError(UNAVAILABLE, True, f'ollama HTTP {r.status_code}: {r.text[:200]}')
        j = r.json()
        raw = (j.get('message') or {}).get('content') or ''
        try:
            data = json.loads(raw)
        except ValueError as e:
            raise ProviderError(UNAVAILABLE, True, f'not JSON: {e}')
        meta = {'provider': self.name, 'model': self.model, 'served_by': self.model, 'effort': 'think' if self.think else 'no-think',
                'request_id': None, 'stop_reason': j.get('done_reason'),
                'input_tokens': j.get('prompt_eval_count'), 'output_tokens': j.get('eval_count'),
                'cache_read_tokens': 0, 'cache_write_tokens': 0, 'seconds': round(time.time() - t0, 2)}
        return Reply(raw, data, meta)


def check_shape(data):
    """P7 check 5 for providers without enforced structured output: the
    top-level lists and each measurement's keys (flat schema v2)."""
    from .schema import MEASUREMENT
    if not isinstance(data, dict):
        return 'not an object'
    for k in SCHEMA['required']:
        if not isinstance(data.get(k), list):
            return f'{k} missing or not a list'
    for i, m in enumerate(data['measurements']):
        if not isinstance(m, dict):
            return f'measurement {i} not an object'
        miss = [k for k in MEASUREMENT['required'] if k not in m]
        if miss:
            return f'measurement {i} lacks {", ".join(miss)}'
        if m.get('kind') not in ('planar', 'linear'):
            return f'measurement {i} kind {m.get("kind")!r}'
        if not all(isinstance(n, dict) and isinstance(n.get('value'), (int, float)) for n in m.get('numbers') or []):
            return f'measurement {i} has a non-number value'
    return None


def make(cfg):
    if cfg.extract_provider == 'anthropic':
        return AnthropicAdapter(cfg.extract_model, cfg.extract_effort)
    if cfg.extract_provider == 'ollama':
        return OllamaAdapter(cfg.extract_model, cfg.ollama_url)
    raise SystemExit(f'VS_EXTRACT_PROVIDER {cfg.extract_provider!r} unknown')
