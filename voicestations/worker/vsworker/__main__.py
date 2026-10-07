"""Voice Stations worker: python -m vsworker

Claims one station at a time from /voiceworker/v1/, does the work, posts the
result, repeats; waits VS_IDLE_SECONDS when there is nothing to do. On
SIGTERM it stops claiming and finishes the job in hand (compose gives it a
grace period); a job cut off anyway returns to the queue when its lease ends.
"""

import signal
import sys
import time
import traceback

from . import config, transcribe
from .api import Api, ApiError
from .whisper import WhisperServer


def log(msg):
    print(time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()) + ' ' + msg, flush=True)


def main():
    cfg = config.load()
    api = Api(cfg.api_url, cfg.token)
    stopping = {'now': False}

    def on_term(*_):
        stopping['now'] = True
        log('stop requested: finishing the current job')
    signal.signal(signal.SIGTERM, on_term)
    signal.signal(signal.SIGINT, on_term)

    whisper = WhisperServer(cfg, log)
    whisper.start()
    engines = {'transcribe': {'engine': 'whisper.cpp',
                              'model': cfg.whisper_model.replace('ggml-', '').replace('.bin', '')}}
    log(f'worker up: {cfg.api_url}, kinds {",".join(cfg.kinds)}')

    backoff = 0
    while not stopping['now']:
        try:
            job = api.claim(cfg.kinds, {k: engines[k] for k in cfg.kinds})
            backoff = 0
        except ApiError as e:
            if e.status == 401:
                log('the server does not accept this worker token; stopping')
                sys.exit(2)
            backoff = min(60, max(2, backoff * 2))
            log(f'claim failed: {e}; next try in {backoff} s')
            time.sleep(backoff)
            continue
        except Exception as e:  # network down, server restarting
            backoff = min(60, max(2, backoff * 2))
            log(f'claim failed: {e}; next try in {backoff} s')
            time.sleep(backoff)
            continue
        if job is None:
            time.sleep(cfg.idle_seconds)
            continue
        log(f"{job['station_uuid']}: claimed {job['kind']} (run {job['run_id']}, attempt {job['attempt']})")
        try:
            transcribe.run(job, api, whisper, cfg, log)
        except Exception:
            # the lease runs out and the server retries the station
            log('job crashed: ' + traceback.format_exc().replace('\n', ' | '))
    whisper.stop()
    log('worker stopped')


if __name__ == '__main__':
    main()
