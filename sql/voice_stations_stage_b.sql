-- voice_stations_stage_b: Voice Stations step 6, Stage B tables (2026-10-09).
--
-- consents: one row per account per consent version the tester agreed to in
-- the app (step 6 consent point 2). The server refuses station uploads from
-- an account without a row for the current version (403 consent_required).
-- A tester who withdraws has every Voice Stations row removed, this one too.
--
-- Design: docs/AlternateStraboFieldIdea/Phase1_Plan.md, build order step 6.
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres.
-- Idempotent:
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/voice_stations_stage_b.sql

BEGIN;

CREATE TABLE IF NOT EXISTS voicestations.consents (
  id           bigserial   PRIMARY KEY,
  userpkey     integer     NOT NULL,              -- from the login, never the body
  version      integer     NOT NULL,              -- VsConfig::CONSENT_VERSION agreed to
  accepted_at  timestamptz NOT NULL DEFAULT now(),
  app_version  text,
  device_model text,
  UNIQUE (userpkey, version)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON voicestations.consents TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE voicestations.consents_id_seq TO strabodbuser;

COMMIT;
