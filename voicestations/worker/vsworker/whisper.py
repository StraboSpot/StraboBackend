"""The resident whisper-server (whisper.cpp) inside the worker container.

The worker starts it on localhost with the model loaded once (P5.4), checks
it before each job and restarts it if it died. Settings as tested 09-28:
large-v3-turbo + Silero VAD with 200 ms speech padding.

whisper.cpp v1.9.4 / v1.9.5 map SEGMENT times back to the original audio
after VAD, but leave WORD times on the shortened (speech only) timeline. The
mapping it used is printed to its log for every request
("vad_segment_info: orig_start .. vad_end .."), so we keep the log and map
the word times ourselves (vadmap.py).
"""

import collections
import os
import subprocess
import threading
import time

import requests


class WhisperError(Exception):
    pass


class WhisperServer:
    def __init__(self, cfg, log):
        self.cfg = cfg
        self.log = log
        self.proc = None
        self.lines = collections.deque(maxlen=20000)
        self.line_no = 0
        self.lock = threading.Lock()
        self.url = f'http://127.0.0.1:{cfg.whisper_port}'

    def command(self):
        c = self.cfg
        return [
            'whisper-server',
            '-m', os.path.join(c.model_dir, c.whisper_model),
            '-l', c.language,
            '-t', str(c.threads),
            '--vad', '-vm', os.path.join(c.model_dir, c.vad_model),
            '-vp', str(c.vad_pad_ms),
            '--host', '127.0.0.1', '--port', str(c.whisper_port),
        ]

    def _reader(self, stream):
        for raw in stream:
            line = raw.decode('utf-8', 'replace').rstrip('\n')
            with self.lock:
                self.lines.append((self.line_no, line))
                self.line_no += 1

    def alive(self):
        return self.proc is not None and self.proc.poll() is None

    def start(self, wait=300):
        self.stop()
        for f in (self.cfg.whisper_model, self.cfg.vad_model):
            p = os.path.join(self.cfg.model_dir, f)
            if not os.path.isfile(p):
                raise WhisperError(f'model file missing: {p}')
        self.log(f'starting whisper-server ({self.cfg.whisper_model}, {self.cfg.flavor}, {self.cfg.threads} threads)')
        self.proc = subprocess.Popen(self.command(), stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        threading.Thread(target=self._reader, args=(self.proc.stdout,), daemon=True).start()
        t0 = time.time()
        while time.time() - t0 < wait:
            if not self.alive():
                raise WhisperError('whisper-server exited during start: ' + self.tail())
            try:
                requests.get(self.url + '/', timeout=2)
                self.log(f'whisper-server ready in {time.time() - t0:.1f} s')
                return
            except requests.RequestException:
                time.sleep(0.5)
        raise WhisperError('whisper-server did not start in time')

    def stop(self):
        if self.alive():
            self.proc.terminate()
            try:
                self.proc.wait(10)
            except subprocess.TimeoutExpired:
                self.proc.kill()
        self.proc = None

    def ensure(self):
        if not self.alive():
            self.start()

    def tail(self, n=15):
        with self.lock:
            return ' | '.join(l for _, l in list(self.lines)[-n:])

    def transcribe(self, wav_path):
        """POST the wav; returns (response text, log lines written meanwhile)."""
        self.ensure()
        with self.lock:
            start_no = self.line_no
        try:
            with open(wav_path, 'rb') as f:
                r = requests.post(self.url + '/inference',
                                  files={'file': ('audio.wav', f, 'audio/wav')},
                                  data={'response_format': 'verbose_json', 'temperature': '0'},
                                  timeout=self.cfg.whisper_timeout)
        except requests.RequestException as e:
            died = not self.alive()
            raise WhisperError(('whisper-server died: ' + self.tail()) if died else f'whisper-server request failed: {e}')
        if r.status_code != 200:
            raise WhisperError(f'whisper-server answered {r.status_code}: {r.text[:300]}')
        # The VAD mapping is logged (unbuffered) before the reply is sent, ending
        # with "Reduced audio from"; give the reader thread a moment to catch up.
        deadline = time.time() + 1.0
        while True:
            with self.lock:
                lines = [l for no, l in self.lines if no >= start_no]
            if any('Reduced audio from' in l for l in lines) or time.time() > deadline:
                return r.text, lines
            time.sleep(0.02)
