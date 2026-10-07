"""One transcription job: fetch the audio, check it, convert it, transcribe,
post the result. The audio only ever sits in /tmp (a tmpfs in the container)
and is deleted when the job ends, whatever happens (P5.7: the box keeps no
audio; the server keeps the original).
"""

import hashlib
import os
import shutil
import subprocess
import tempfile
import threading
import time

from .api import ApiError, Dropped
from .vadmap import normalize
from .whisper import WhisperError

MAX_RAW = 1048576


class JobFailed(Exception):
    """error = text the app shows, so written for people; retry = could another try help."""

    def __init__(self, error, retry):
        super().__init__(error)
        self.error = error
        self.retry = retry


class Heartbeat:
    """Keeps the lease alive while a job runs; notices a dropped job early."""

    def __init__(self, api, job, every, log):
        self.api, self.job, self.every, self.log = api, job, every, log
        self.stop_ev = threading.Event()
        self.dropped = None
        self.t = threading.Thread(target=self.run, daemon=True)

    def run(self):
        while not self.stop_ev.wait(self.every):
            try:
                self.api.heartbeat(self.job['station_uuid'], self.job['run_id'])
            except Dropped as e:
                self.dropped = e
                self.log(f"heartbeat: job dropped ({e.code})")
                return
            except Exception as e:  # network trouble: keep trying until the lease runs out
                self.log(f'heartbeat failed: {e}')

    def __enter__(self):
        self.t.start()
        return self

    def __exit__(self, *a):
        self.stop_ev.set()


def sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def probe_seconds(path):
    r = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
                        '-of', 'default=noprint_wrappers=1:nokey=1', path],
                       capture_output=True, text=True, timeout=60)
    try:
        return float(r.stdout.strip())
    except ValueError:
        raise JobFailed('The recording could not be read (it may be damaged).', False)


def to_wav(src, dst):
    r = subprocess.run(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-i', src,
                        '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le', dst],
                       capture_output=True, text=True, timeout=300)
    if r.returncode != 0 or not os.path.isfile(dst):
        raise JobFailed('The recording could not be converted for transcription (it may be damaged).', False)


def run(job, api, whisper, cfg, log):
    uuid, run_id = job['station_uuid'], job['run_id']
    work = tempfile.mkdtemp(prefix='vs-', dir='/tmp' if os.path.isdir('/tmp') else None)
    t0 = time.time()
    try:
        with Heartbeat(api, job, cfg.heartbeat_seconds, log) as hb:
            try:
                m4a, wav = os.path.join(work, 'a.m4a'), os.path.join(work, 'a.wav')
                n = api.download_audio(job, m4a)
                if n != job['audio']['bytes'] or sha256(m4a) != job['audio']['sha256']:
                    raise JobFailed('The recording did not download completely.', True)
                seconds = probe_seconds(m4a)
                to_wav(m4a, wav)
                t1 = time.time()
                try:
                    raw, lines = whisper.transcribe(wav)
                except WhisperError as e:
                    log(f'{uuid}: {e}')
                    raise JobFailed('The transcription engine had a problem.', True)
                t2 = time.time()
                output, word_times = normalize(raw, lines)
                if len(raw.encode('utf-8')) > MAX_RAW:
                    raw = raw.encode('utf-8')[:MAX_RAW - 64].decode('utf-8', 'ignore')
                if hb.dropped:
                    raise hb.dropped
                body = {
                    'run_id': run_id,
                    'engine': 'whisper.cpp',
                    'model': cfg.whisper_model.replace('ggml-', '').replace('.bin', ''),
                    'settings': {
                        'build': cfg.flavor, 'whisper_version': cfg.whisper_version,
                        'language': cfg.language, 'threads': cfg.threads, 'temperature': 0,
                        'vad': True, 'vad_model': cfg.vad_model.replace('ggml-', '').replace('.bin', ''),
                        'vad_pad_ms': cfg.vad_pad_ms, 'word_times': word_times,
                        'seconds_transcribe': round(t2 - t1, 3), 'seconds_total': round(time.time() - t0, 3),
                    },
                    'audio_seconds': round(seconds, 3),
                    'raw_output': raw,
                    'output': output,
                }
                try:
                    api.result(uuid, body)
                except ApiError as e:  # our result did not pass the server's checks: a worker bug
                    log(f'{uuid}: result refused: {e}')
                    raise JobFailed('The processing server could not store the transcript.', True)
                log(f'{uuid}: transcribed {seconds:.1f} s of audio in {t2 - t1:.2f} s '
                    f'({len(output["words"])} words, {word_times})')
            except JobFailed as e:
                if hb.dropped:
                    raise hb.dropped
                r = api.fail(uuid, run_id, e.error, e.retry)
                log(f"{uuid}: failed ({e.error}); station now {r.get('stage')}")
    except Dropped as e:
        log(f'{uuid}: dropped by the server ({e.code})')
    finally:
        shutil.rmtree(work, ignore_errors=True)
