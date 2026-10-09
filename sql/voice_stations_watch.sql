-- voice_stations_watch: where a Strabo Voice recording was made (2026-10-09,
-- watch app design point W4).
--
-- recorded_on: 'phone' or 'watch'. NULL = an upload from a build before the
-- watch app, which can only be a phone recording; read NULL as 'phone'.
-- watch_model: the watch's model and watchOS version, watch recordings only.
-- device_model stays the phone, which ran the app and sent the upload.
--
-- Design: docs/AlternateStraboFieldIdea/Phase1_Plan.md, step 6, W4.
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres.
-- Idempotent:
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/voice_stations_watch.sql

BEGIN;

ALTER TABLE voicestations.stations ADD COLUMN IF NOT EXISTS recorded_on varchar;
ALTER TABLE voicestations.stations ADD COLUMN IF NOT EXISTS watch_model text;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'stations_recorded_on_check') THEN
    ALTER TABLE voicestations.stations ADD CONSTRAINT stations_recorded_on_check
      CHECK (recorded_on IN ('phone', 'watch'));
  END IF;
END $$;

COMMIT;
