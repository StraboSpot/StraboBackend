# Voice Stations worker: runbook

The worker turns uploaded station audio into transcripts, then transcripts into
proposals (Claude), checked by our own code before the app sees them. It pulls jobs from the server's `/voiceworker/v1/` API over HTTP(S). It
holds no database credentials and no user login, only its own worker token. Design:
`docs/AlternateStraboFieldIdea/Phase1_Plan.md`, build step 2.

- **gpubox** (RTX 3060) is the main worker. Its claim delay is 0.
- **prod CPU** is the fallback. It only claims stations that have waited 120 s.
- Both run this same folder: `compose.gpubox.yml` (CUDA build) or `compose.cpu.yml`.

## 1. Register the worker on the server

Each host gets its own token. The plain token goes only in the worker's `.env`. The
server stores only its sha256, in `includes/config.inc.php`:

```php
define('VOICESTATIONS_WORKERS', array(
	'gpubox' => array('sha256' => '<sha256 of the token>', 'delay' => 0),
	'prod'   => array('sha256' => '<sha256 of the token>', 'delay' => 120),
));
```

Make a token and its hash:

```bash
openssl rand -hex 32
printf '%s' '<token>' | sha256sum
```

To remove a worker, delete its line. Its next claim gets 401 and the worker stops.

## 2. Configure the worker host

```bash
cp env.example .env          # then fill it in; chmod 600 .env
```

| Setting | Value |
|---|---|
| `VS_API_URL` | `https://strabospot.org/voiceworker/v1` (dev from gpubox: `http://<Mac LAN IP>/voiceworker/v1`; Mac Docker: `http://host.docker.internal/voiceworker/v1`) |
| `VS_TOKEN` | the plain token |
| `VS_KINDS` | `transcribe,extract` (or one of them) |
| `ANTHROPIC_API_KEY` | the Voice Stations workspace key for this machine (only with `extract`) |
| `VS_EXTRACT_EFFORT` | `high` (default; `medium` also passed the regression 10-07) |
| `VS_EXTRACT_SLOTS` | `4` extractions at once (default) |
| `VS_EXTRACT_PROVIDER` | `anthropic` (default). `ollama` = the MANUAL offline switch: one extraction at a time, whisper's model unloaded around it on the GPU, `VS_EXTRACT_MODEL=qwen3:14b`. It FAILED the gate 10-07 (7 values dropped as split digits, none wrong), so use it only when Claude cannot be |
| `VS_THREADS` | 8 on gpubox, 16 on prod (benchmarked 09-28) |
| `VS_CPUS` | CPU worker only: container CPU cap, 16 on prod (of 32) so the web keeps its share; default 4 |
| `VS_MODELS_HOST_DIR` | folder with `ggml-large-v3-turbo.bin` + `ggml-silero-v5.1.2.bin` |

## 3. Run it

```bash
docker compose -f compose.gpubox.yml up -d --build      # gpubox
docker compose -f compose.cpu.yml up -d --build         # prod / Mac
docker logs -f strabo-voiceworker                       # one line per job
docker compose -f compose.gpubox.yml stop               # finishes the job in hand first
```

A healthy log shows `worker up`, and then one `claimed` line and one `transcribed`
line per station. It is silent when idle.

**gpubox setup (done 10-07):** nvidia-container-toolkit is installed from NVIDIA's apt
repo, and `nvidia-ctk runtime configure --runtime=docker` was run. Check the GPU from
Docker with `docker run --rm --gpus all nvidia/cuda:12.6.3-runtime-ubuntu24.04 nvidia-smi`.

**GPU memory:** whisper uses about 2 GB of the card's 12 GB. Ollama's `qwen3:14b`
uses 10 GB, so the two do not fit together. Ollama now unloads models after 5 idle
minutes (`OLLAMA_KEEP_ALIVE=5m`) and listens only on the Docker bridge (172.17.0.1).
If the worker restarts in a loop with `failed to allocate CUDA0 buffer` (e.g. right
after an Ollama regression run), run `OLLAMA_HOST=172.17.0.1 ollama stop qwen3:14b`.

## 4. What happens to a job

| Event | What happens |
|---|---|
| Claim | Moves the station to the next stage. Leases it for 2 min. Counts one attempt (3 per stage). Makes a run row. |
| During the job | The worker sends a heartbeat every 30 s. A 410 answer means the user discarded the station, so the worker drops the job. |
| Worker dies | The lease runs out. The next claim, or the next batch status read, hands the station back to the queue. After the 3rd attempt the station is marked failed with a readable error. The user can press Retry. |
| Audio | Lives only in the container's tmpfs `/tmp` and is deleted when the job ends. The server keeps the original. |
| Extraction | Waits until the whole batch is transcribed. Each job carries the transcript, the earlier stations' transcripts (20 at most, read only) and the app's form choices. Claude points at what was said; `vsworker/extract/checks.py` checks every value and does the arithmetic, and the proposal + audit go back to the server. API trouble = retried up to 3 times, then the station fails with "The proposal service is unavailable" and the user can Retry or enter it by hand. |
| Word timings | whisper.cpp leaves word times on the VAD-shortened timeline. The worker maps them back using the server's VAD log. `settings.word_times` says `vad_remapped` (normal), `segment_shifted` (no mapping found) or `none`. |

## 5. Tests

| What | Command |
|---|---|
| Unit (VAD mapping, on real whisper output) | `docker run --rm strabo-voiceworker:cpu python -m unittest discover -s tests -t .` |
| Server API (stop real dev workers first) | `docker exec strabo-php php /srv/app/www/tests/voicestations/worker_test.php` |
| Unit (checks, numbers, conversions) | same command: `tests/test_extract.py` runs with it |
| End to end (worker running, against dev) | `docker exec strabo-php php /srv/app/www/tests/voicestations/worker_e2e.php` (`--extract` when the worker also extracts) |
| Capture regression inputs (worker on transcribe only) | `... worker_e2e.php --capture /srv/app/www/docs/AlternateStraboFieldIdea/Regression/inputs` |

## 6. Regression gate (before ANY prompt, model, check or form change goes live)

Inputs (gitignored, docs/AlternateStraboFieldIdea/Regression/): `inputs/` = the 8 Phase 0
extraction jobs captured from the real pipeline, `answer_key.json` = the blind key written
09-26. From this folder on the Mac:

```bash
R=$PWD/../../docs/AlternateStraboFieldIdea/Regression
docker run --rm --env-file .env -v $R:/reg strabo-voiceworker:cpu python -m vsworker.regress \
  --inputs /reg/inputs --key /reg/answer_key.json --out /reg/out/<name> --effort high
```

PASS = 0 wrong numbers surviving the checks, nothing missing, 13/13 measurements with the
right feature type, 5/5 flags (and the scorer's self-test OK). About $0.20 per run.
`--score-only` re-scores a saved run without calling the model. On Linux add
`--user $(id -u):$(id -g) -e HOME=/tmp` so the container can write the output folder.

10-07 results: Opus 5.5 high PASS (13/13, 0 wrong, 0 missing, flags 5/5, extras 10/10,
~$0.20); medium PASS (same scores, ~17% fewer output tokens); qwen3:14b (think) FAIL
(0 wrong but 7 missing: it read "045" as 0 and the split-digit check dropped it).
