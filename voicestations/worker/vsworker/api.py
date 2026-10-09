"""Client for the server's /voiceworker/v1/ API (voicestations/lib/VsWorker.php).

Answers the worker must act on:
  204 from claim        nothing to do
  409 lease_lost        another worker owns the station now: drop the job
  409 stale_transcript  the transcript changed: drop the result
  410 gone              the user discarded or confirmed the station: drop the job
"""

import requests


class Dropped(Exception):
    """The server no longer wants this job's result (409 / 410)."""

    def __init__(self, code, message):
        super().__init__(f'{code}: {message}')
        self.code = code


class ApiError(Exception):
    """Any other refusal; status and the server's error code are kept."""

    def __init__(self, status, code, message):
        super().__init__(f'HTTP {status} {code}: {message}')
        self.status = status
        self.code = code


class Api:
    def __init__(self, base_url, token, timeout=60):
        self.base = base_url.rstrip('/')
        self.timeout = timeout
        self.s = requests.Session()
        self.s.headers['Authorization'] = f'Bearer {token}'
        self.s.headers['User-Agent'] = 'strabo-voiceworker/1'

    def _check(self, r):
        if r.status_code < 300:
            return
        try:
            body = r.json()
            code, msg = body.get('code', ''), body.get('Error', '')
        except ValueError:
            code, msg = '', r.text[:200]
        if r.status_code in (409, 410) and code in ('lease_lost', 'stale_transcript', 'gone'):
            raise Dropped(code, msg)
        raise ApiError(r.status_code, code, msg)

    def _post(self, path, body):
        r = self.s.post(f'{self.base}/{path}', json=body, timeout=self.timeout)
        self._check(r)
        return r

    def ping(self):
        r = self.s.get(f'{self.base}/ping', timeout=self.timeout)
        self._check(r)
        return r.json()

    def claim(self, kinds, engines):
        body = {'kinds': list(kinds)}
        body.update(engines)
        r = self._post('claim', body)
        return None if r.status_code == 204 else r.json()['job']

    def download_audio(self, job, dest):
        """Stream the job's audio to dest; returns the byte count."""
        n = 0
        with self.s.get(f"{self.base}/{job['audio']['path']}", stream=True, timeout=self.timeout) as r:
            self._check(r)
            with open(dest, 'wb') as f:
                for chunk in r.iter_content(65536):
                    f.write(chunk)
                    n += len(chunk)
        return n

    def heartbeat(self, uuid, run_id):
        return self._post(f'jobs/{uuid}/heartbeat', {'run_id': run_id}).json()

    def result(self, uuid, body):
        return self._post(f'jobs/{uuid}/result', body).json()

    def fail(self, uuid, run_id, error, retry):
        return self._post(f'jobs/{uuid}/fail', {'run_id': run_id, 'error': error, 'retry': retry}).json()
