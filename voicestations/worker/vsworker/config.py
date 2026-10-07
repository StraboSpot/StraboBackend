"""Worker settings, all from the environment (the compose file's .env).

Required: VS_API_URL (e.g. https://strabospot.org/voiceworker/v1), VS_TOKEN.
The worker's name and claim delay live on the server (VOICESTATIONS_WORKERS),
keyed by the token, so they cannot drift from what the server enforces.
"""

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Config:
    api_url: str
    token: str
    kinds: tuple
    threads: int
    model_dir: str
    whisper_model: str
    vad_model: str
    vad_pad_ms: int
    language: str
    flavor: str
    whisper_version: str
    idle_seconds: float
    heartbeat_seconds: float
    whisper_port: int
    whisper_timeout: float


def load(env=os.environ):
    def need(name):
        v = env.get(name, '').strip()
        if not v:
            raise SystemExit(f'{name} is not set')
        return v

    kinds = tuple(k.strip() for k in env.get('VS_KINDS', 'transcribe').split(',') if k.strip())
    for k in kinds:
        if k != 'transcribe':
            # extraction arrives in build step 3
            raise SystemExit(f'VS_KINDS: {k!r} is not supported by this worker yet')
    return Config(
        api_url=need('VS_API_URL').rstrip('/'),
        token=need('VS_TOKEN'),
        kinds=kinds,
        threads=int(env.get('VS_THREADS', '8')),
        model_dir=env.get('VS_MODEL_DIR', '/models'),
        whisper_model=env.get('VS_WHISPER_MODEL', 'ggml-large-v3-turbo.bin'),
        vad_model=env.get('VS_VAD_MODEL', 'ggml-silero-v5.1.2.bin'),
        vad_pad_ms=int(env.get('VS_VAD_PAD_MS', '200')),
        language=env.get('VS_LANGUAGE', 'en'),
        flavor=env.get('VS_FLAVOR', 'cpu'),
        whisper_version=env.get('VS_WHISPER_VERSION', 'unknown'),
        idle_seconds=float(env.get('VS_IDLE_SECONDS', '2')),
        heartbeat_seconds=float(env.get('VS_HEARTBEAT_SECONDS', '30')),
        whisper_port=int(env.get('VS_WHISPER_PORT', '8178')),
        whisper_timeout=float(env.get('VS_WHISPER_TIMEOUT', '900')),
    )
