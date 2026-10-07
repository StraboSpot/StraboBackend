"""Voice Stations worker: python -m vsworker

Slots (step 3 point 5): one transcription at a time (whisper-server is
serial and fast) and VS_EXTRACT_SLOTS extractions at once (default 4; mostly
waiting on the API). The loop claims only the kinds it has a free slot for,
each job runs in its own thread with its own lease + heartbeat. Waits
VS_IDLE_SECONDS when there is nothing to do. On SIGTERM it stops claiming and
finishes the jobs in hand (compose gives it a grace period); a job cut off
anyway returns to the queue when its lease ends.

Ollama mode (manual switch, step 3 point 1): extraction and transcription
take turns on the GPU; whisper's model is unloaded before each extraction and
reloaded by the next transcription.
"""

import signal
import sys
import threading
import time
import traceback

from . import config, transcribe
from .api import Api, ApiError
from .extract import adapters, run as extract_run
from .extract.schema import PROMPT_VERSION
from .whisper import WhisperServer

_log_lock = threading.Lock()


def log(msg):
    with _log_lock:
        print(time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()) + ' ' + msg, flush=True)


def main():
    cfg = config.load()
    api = Api(cfg.api_url, cfg.token)
    stopping = threading.Event()

    def on_term(*_):
        stopping.set()
        log('stop requested: finishing the jobs in hand')
    signal.signal(signal.SIGTERM, on_term)
    signal.signal(signal.SIGINT, on_term)

    whisper = None
    if 'transcribe' in cfg.kinds:
        whisper = WhisperServer(cfg, log)
        whisper.start()
    adapter = adapters.make(cfg) if 'extract' in cfg.kinds else None
    gpu_lock = threading.Lock() if (adapter and cfg.extract_provider == 'ollama' and cfg.flavor == 'cuda') else None

    engines = {
        'transcribe': {'engine': 'whisper.cpp', 'model': cfg.whisper_model.replace('ggml-', '').replace('.bin', '')},
        'extract': {'engine': cfg.extract_provider, 'model': cfg.extract_model, 'prompt_version': PROMPT_VERSION},
    }
    log(f'worker up: {cfg.api_url}, kinds {",".join(cfg.kinds)}'
        + (f', extraction {cfg.extract_provider} {cfg.extract_model} x{cfg.extract_slots}' if adapter else ''))

    busy = {'transcribe': 0, 'extract': 0}
    limit = {'transcribe': 1, 'extract': cfg.extract_slots}
    lock = threading.Lock()
    threads = []

    def work(job):
        kind = job['kind']
        japi = Api(cfg.api_url, cfg.token)  # one HTTP session per thread
        try:
            if kind == 'transcribe':
                if gpu_lock:
                    with gpu_lock:
                        transcribe.run(job, japi, whisper, cfg, log)
                else:
                    transcribe.run(job, japi, whisper, cfg, log)
            else:
                if gpu_lock:
                    with gpu_lock:
                        if whisper:
                            whisper.stop()
                        extract_run.run(job, japi, adapter, cfg, log)
                else:
                    extract_run.run(job, japi, adapter, cfg, log)
        except Exception:
            # the lease runs out and the server retries the station
            log('job crashed: ' + traceback.format_exc().replace('\n', ' | '))
        finally:
            with lock:
                busy[kind] -= 1

    backoff = 0
    while not stopping.is_set():
        with lock:
            free = [k for k in cfg.kinds if busy[k] < limit[k]]
        if not free:
            time.sleep(0.2)
            continue
        try:
            job = api.claim(free, {k: engines[k] for k in free})
            backoff = 0
        except ApiError as e:
            if e.status == 401:
                log('the server does not accept this worker token; stopping')
                sys.exit(2)
            backoff = min(60, max(2, backoff * 2))
            log(f'claim failed: {e}; next try in {backoff} s')
            stopping.wait(backoff)
            continue
        except Exception as e:  # network down, server restarting
            backoff = min(60, max(2, backoff * 2))
            log(f'claim failed: {e}; next try in {backoff} s')
            stopping.wait(backoff)
            continue
        if job is None:
            stopping.wait(cfg.idle_seconds)
            continue
        log(f"{job['station_uuid']}: claimed {job['kind']} (run {job['run_id']}, attempt {job['attempt']})")
        with lock:
            busy[job['kind']] += 1
        t = threading.Thread(target=work, args=(job,), daemon=True)
        t.start()
        threads = [x for x in threads if x.is_alive()] + [t]

    for t in threads:
        t.join()
    if whisper:
        whisper.stop()
    log('worker stopped')


if __name__ == '__main__':
    main()
