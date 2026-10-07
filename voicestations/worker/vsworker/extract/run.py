"""One extraction job: build the prompt from the job (form choices travel in
it), ask the provider, run the P7 checks, post the proposal + audit.
"""

import time

from ..api import ApiError, Dropped
from ..transcribe import Heartbeat, JobFailed
from . import checks, prompt
from .adapters import ProviderError, check_shape
from .schema import PROMPT_VERSION, to_internal


def extract_once(job, adapter):
    """Provider call + checks, no server: used live and by the regression."""
    fixed = prompt.fixed_part(job['vocab'])
    station = prompt.station_part(job)
    reply = adapter.extract(fixed, station)
    bad = check_shape(reply.data)
    if bad:
        raise ProviderError('The proposal came back malformed; it will be tried again.', True, bad)
    proposal, audit = checks.process(to_internal(reply.data), job)
    return reply, proposal, audit


def run(job, api, adapter, cfg, log, gpu_lock=None):
    uuid, run_id = job['station_uuid'], job['run_id']
    t0 = time.time()
    try:
        with Heartbeat(api, job, cfg.heartbeat_seconds, log) as hb:
            try:
                if gpu_lock is not None:
                    with gpu_lock:
                        reply, proposal, audit = extract_once(job, adapter)
                else:
                    reply, proposal, audit = extract_once(job, adapter)
            except ProviderError as e:
                log(f'{uuid}: extraction failed: {e}')
                raise JobFailed(e.message, e.retry)
            if hb.dropped:
                raise hb.dropped
            m = reply.meta
            body = {
                'run_id': run_id,
                'engine': m['provider'],
                'model': m['served_by'] or m['model'],
                'prompt_version': PROMPT_VERSION,
                'settings': {
                    'requested_model': m['model'], 'effort': m['effort'], 'vocab_version': job['vocab']['version'],
                    'request_id': m['request_id'], 'stop_reason': m['stop_reason'],
                    'cache_read_tokens': m['cache_read_tokens'], 'cache_write_tokens': m['cache_write_tokens'],
                    'earlier_stations': len(job.get('earlier_stations') or []),
                    'seconds_provider': m['seconds'], 'seconds_total': round(time.time() - t0, 2),
                },
                'raw_output': reply.raw_text,
                'output': proposal,
                'validation': audit,
                'input_tokens': m['input_tokens'],
                'output_tokens': m['output_tokens'],
            }
            try:
                api.result(uuid, body)
            except ApiError as e:
                log(f'{uuid}: proposal refused: {e}')
                raise JobFailed('The processing server could not store the proposal.', True)
            n_flags = sum(len(i.get('flags', [])) for i in proposal['items']) + len(proposal['station_flags'])
            log(f'{uuid}: proposed {len(proposal["items"])} items, {len(proposal["dropped"])} dropped, '
                f'{n_flags} flags ({m["served_by"]}, {m["output_tokens"]} out, {m["cache_read_tokens"]} cached, {m["seconds"]} s)')
    except JobFailed as e:
        try:
            r = api.fail(uuid, run_id, e.error, e.retry)
            log(f"{uuid}: failed ({e.error}); station now {r.get('stage')}")
        except Dropped as d:
            log(f'{uuid}: dropped by the server ({d.code})')
    except Dropped as e:
        log(f'{uuid}: dropped by the server ({e.code})')
