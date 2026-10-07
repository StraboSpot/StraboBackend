-- voice_stations: Voice Stations Phase 1 testbed, staging tables (2026-10-07).
--
-- A geologist presses Record at each station; the phone uploads GPS fixes +
-- audio per station, a worker transcribes and an LLM proposes Spot values,
-- and the user confirms them IN THE APP. The server proposes, the app writes:
-- nothing here is ever written to Neo4j. Unreviewed values live only in this
-- schema (no leaks into search, exports, versions, fieldbook or the app).
--
-- Design: docs/AlternateStraboFieldIdea/Phase1_Plan.md (P2, P3, P5, P6, P7,
-- P10) and the 10-07 DDL decisions:
--   1. own schema, so the experiment is one DROP SCHEMA away;
--   2. the work queue lives on the stations row (stage, lease, attempts);
--   3. every station upload carries its batch's full station UUID list, and
--      the batch is complete once every listed station has arrived;
--   4. a run keeps the raw engine output, our normalized output and the
--      validation result;
--   5. one confirm row per station; the server computes its counts from
--      the record, never trusting the phone's;
--   6. UUIDs are globally unique, userpkey comes from the login only, Field
--      ids are TEXT, no FK to public.users (an account deletion must not
--      block on, or silently cascade away, research records).
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres.
-- Idempotent:
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/voice_stations.sql
-- Remove the whole experiment: DROP SCHEMA voicestations CASCADE;

BEGIN;

CREATE SCHEMA IF NOT EXISTS voicestations;

-- 1. Batches: one upload sitting, phone-made UUID (P6.6)
CREATE TABLE IF NOT EXISTS voicestations.batches (
  id             bigserial PRIMARY KEY,
  batch_uuid     uuid        NOT NULL UNIQUE,
  userpkey       integer     NOT NULL,              -- from the login, never the body
  project_id     text        NOT NULL,              -- Field ids as given (checked once at upload)
  dataset_id     text        NOT NULL,              -- the project's "Voice Stations" dataset
  station_uuids  uuid[]      NOT NULL DEFAULT '{}', -- latest list the phone sent; latest wins
  complete_at    timestamptz,                       -- every listed station has arrived
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS batches_user_idx
  ON voicestations.batches (userpkey, created_at DESC);

-- 2. Stations: one Record press, phone-made UUID (P3, P6.2, P6.3); carries
-- the work queue (P5.1). Stage moves uploaded -> transcribing -> transcribed
-- -> extracting -> ready; failed after the retry limit. attempts counts
-- tries at the CURRENT stage and resets when the stage advances. The prod
-- fallback worker claims only when stage_since is older than its threshold
-- (P5.7). Extraction is claimed only once the batch is complete and all its
-- stations are transcribed or failed (P6.6): a claim-query rule, no column.
CREATE TABLE IF NOT EXISTS voicestations.stations (
  id                 bigserial PRIMARY KEY,
  station_uuid       uuid        NOT NULL UNIQUE,
  batch_id           bigint      NOT NULL REFERENCES voicestations.batches(id) ON DELETE CASCADE,
  userpkey           integer     NOT NULL,
  -- what the station adds to: a new Spot (uses the station GPS) or an
  -- existing Spot (geometry never used or changed; whole Spot JSON kept as
  -- read-only context, P3 aside)
  target_kind        varchar     NOT NULL CHECK (target_kind IN ('new', 'existing')),
  target_spot_id     text,
  target_spot        jsonb,
  -- recording
  started_at         timestamptz NOT NULL,
  ended_at           timestamptz NOT NULL,
  tz_offset_minutes  smallint,                       -- phone time zone at recording
  gps_fixes          jsonb       NOT NULL DEFAULT '[]', -- EVERY fix: lat, lon, alt, accuracy, time
  best_lat           double precision,               -- most accurate fix (server picks, P3)
  best_lon           double precision,
  best_alt           double precision,
  best_accuracy      real,                           -- metres; > 20 = flag 15; NULL = no location
  best_fix_at        timestamptz,
  photos             jsonb       NOT NULL DEFAULT '[]', -- id + timestamp only, never the image
  strike_convention  varchar,                        -- the user's setting (DV5)
  app_version        text,
  device_model       text,
  details            jsonb       NOT NULL,           -- the upload's details verbatim (audit)
  -- audio (original kept, P6.4 / P10.2); path relative to the audio data folder
  audio_path         text,
  audio_mime         varchar,
  audio_bytes        bigint,
  audio_sha256       char(64),
  audio_seconds      real,
  audio_deleted_at   timestamptz,                    -- discard grace or deletion request (P10.5)
  -- work queue (P5.1)
  stage              varchar     NOT NULL DEFAULT 'uploaded' CHECK (stage IN
                       ('uploaded', 'transcribing', 'transcribed', 'extracting', 'ready', 'failed')),
  stage_since        timestamptz NOT NULL DEFAULT now(),
  lease_until        timestamptz,
  leased_by          varchar,                        -- worker name, e.g. 'gpubox', 'prod'
  attempts           smallint    NOT NULL DEFAULT 0,
  error_text         text,
  current_transcript_run bigint,                     -- FKs added below (runs is created after)
  current_proposal_run   bigint,
  -- lifecycle
  discarded_at       timestamptz,
  confirmed_at       timestamptz,
  created_at         timestamptz NOT NULL DEFAULT now(),
  updated_at         timestamptz NOT NULL DEFAULT now(),
  CHECK ((target_kind = 'new' AND target_spot_id IS NULL)
      OR (target_kind = 'existing' AND target_spot_id IS NOT NULL)),
  CHECK (ended_at >= started_at)
);
CREATE INDEX IF NOT EXISTS stations_batch_idx
  ON voicestations.stations (batch_id, started_at);
CREATE INDEX IF NOT EXISTS stations_user_idx
  ON voicestations.stations (userpkey, created_at DESC);
CREATE INDEX IF NOT EXISTS stations_queue_idx
  ON voicestations.stations (stage, stage_since)
  WHERE stage IN ('uploaded', 'transcribing', 'transcribed', 'extracting');

-- 3. Runs: one transcription or extraction attempt (P4, P5.3, P7). Kept
-- forever with the station; a re-run adds a row and moves the station's
-- current_*_run pointer.
CREATE TABLE IF NOT EXISTS voicestations.runs (
  id                 bigserial PRIMARY KEY,
  station_id         bigint      NOT NULL REFERENCES voicestations.stations(id) ON DELETE CASCADE,
  kind               varchar     NOT NULL CHECK (kind IN ('transcribe', 'extract')),
  origin             varchar     NOT NULL DEFAULT 'pipeline' CHECK (origin IN ('pipeline', 'rerun')),
  transcript_run_id  bigint      REFERENCES voicestations.runs(id), -- extract: the transcript it read
  status             varchar     NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'done', 'failed')),
  worker             varchar,                        -- who ran it ('gpubox', 'prod')
  engine             varchar     NOT NULL,           -- 'whisper.cpp', 'anthropic', 'ollama', ...
  model              varchar     NOT NULL,
  prompt_version     varchar,                        -- extract runs
  settings           jsonb       NOT NULL DEFAULT '{}',
  raw_output         text,                           -- engine output verbatim, before our code
  output             jsonb,                          -- our contract: transcript + word timings, or proposal
  validation         jsonb,                          -- per value kept / dropped (reason) / flagged; misfits
  input_tokens       integer,
  output_tokens      integer,
  error_text         text,
  started_at         timestamptz NOT NULL DEFAULT now(),
  finished_at        timestamptz,
  CHECK (kind = 'extract' OR transcript_run_id IS NULL)
);
CREATE INDEX IF NOT EXISTS runs_station_idx
  ON voicestations.runs (station_id, kind, started_at DESC);

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'stations_current_transcript_run_fkey') THEN
    ALTER TABLE voicestations.stations ADD CONSTRAINT stations_current_transcript_run_fkey
      FOREIGN KEY (current_transcript_run) REFERENCES voicestations.runs(id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'stations_current_proposal_run_fkey') THEN
    ALTER TABLE voicestations.stations ADD CONSTRAINT stations_current_proposal_run_fkey
      FOREIGN KEY (current_proposal_run) REFERENCES voicestations.runs(id) ON DELETE SET NULL;
  END IF;
END $$;

-- 4. Confirms: one per station, confirmed or discarded (P6.6, P8, P10.2).
-- record = per value accepted unchanged / edited from->to / removed / added
-- by hand, plus the action on each flag. The n_* counts are computed by the
-- server from record. A resend returns the existing row (UNIQUE station_id).
CREATE TABLE IF NOT EXISTS voicestations.confirms (
  id                 bigserial PRIMARY KEY,
  station_id         bigint      NOT NULL UNIQUE REFERENCES voicestations.stations(id) ON DELETE CASCADE,
  userpkey           integer     NOT NULL,
  proposal_run_id    bigint      REFERENCES voicestations.runs(id) ON DELETE SET NULL, -- NULL: entered by hand
  outcome            varchar     NOT NULL CHECK (outcome IN ('confirmed', 'discarded')),
  record             jsonb       NOT NULL,
  n_proposed         integer     NOT NULL DEFAULT 0,
  n_unchanged        integer     NOT NULL DEFAULT 0,
  n_edited           integer     NOT NULL DEFAULT 0,
  n_removed          integer     NOT NULL DEFAULT 0,
  n_added            integer     NOT NULL DEFAULT 0,
  n_flags            integer     NOT NULL DEFAULT 0,
  review_seconds     real,
  spot_ids           text[]      NOT NULL DEFAULT '{}', -- app-made Spot ids (a split makes more than one)
  created_at         timestamptz NOT NULL DEFAULT now(),
  CHECK (outcome = 'confirmed' OR cardinality(spot_ids) = 0)
);
CREATE INDEX IF NOT EXISTS confirms_user_idx
  ON voicestations.confirms (userpkey, created_at DESC);

-- Privileges: the web role reads and writes; the readonly role gets nothing
-- (testers' recordings and transcripts, consent-bound, P10.6).
GRANT USAGE ON SCHEMA voicestations TO strabodbuser;
GRANT SELECT, INSERT, UPDATE, DELETE ON
  voicestations.batches, voicestations.stations, voicestations.runs, voicestations.confirms
  TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE
  voicestations.batches_id_seq, voicestations.stations_id_seq,
  voicestations.runs_id_seq, voicestations.confirms_id_seq
  TO strabodbuser;

COMMIT;
