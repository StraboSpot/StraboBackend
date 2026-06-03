-- StraboExperimental: introduce strabo_id (UUID) on straboexp.sample
-- Phase 1 scope: schema column + one-time backfill ONLY. The ingestion-mint
-- half (server-side minting on new sample upload + idempotent reuse on
-- project re-upload) is Phase 3 work (§9.3 + §16 item 13).
--
-- Why: Experimental's existing straboexp.sample.id is free human text and is
-- non-unique across rows (prod: 71 rows → 41 distinct ids). The (id, userpkey)
-- composite key cannot disambiguate Experimental rows the way it does for
-- Field/Micro. A minted per-row UUID gives every Experimental sample a stable
-- cross-system identity. See DESIGN_PROPOSAL.md §3.4 and §9.3.
--
-- Apply against a dev clone:
--   docker cp samplesdb/schema/exp_strabo_id.sql strabo-postgres:/tmp/
--   docker exec strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 -f /tmp/exp_strabo_id.sql
--
-- The script is fully idempotent — re-running on a database where the column
-- already exists is a no-op. The backfill UPDATE only touches rows with NULL
-- strabo_id, and the NOT NULL + UNIQUE add steps are guarded.
--
-- Roll back (only valid before the ingestion-mint change ships in Phase 3):
--   BEGIN;
--     ALTER TABLE straboexp.sample DROP CONSTRAINT IF EXISTS sample_strabo_id_unique;
--     ALTER TABLE straboexp.sample DROP COLUMN IF EXISTS strabo_id;
--   COMMIT;
-- After Phase 3 ships, dropping strabo_id orphans every related StraboSamples
-- row and is not a safe rollback path.

BEGIN;

-- Required for gen_random_uuid(). The main strabosamples.sql also creates it;
-- declaring here too keeps this script self-contained.
CREATE EXTENSION IF NOT EXISTS pgcrypto;


-- 1) Add the column nullable so the ALTER can succeed against existing rows.
ALTER TABLE straboexp.sample
  ADD COLUMN IF NOT EXISTS strabo_id UUID;


-- 2) One-time backfill: mint a fresh UUID for every existing row that does
--    not yet have one. Per-row, never grouped — same-named Experimental rows
--    are not provably the same physical specimen (§3.4 v6 decision), so each
--    row becomes a distinct StraboSamples sample.
UPDATE straboexp.sample
SET    strabo_id = gen_random_uuid()
WHERE  strabo_id IS NULL;


-- 3) Enforce NOT NULL now that every row has a value. SET NOT NULL is
--    idempotent (no-op if already NOT NULL).
ALTER TABLE straboexp.sample
  ALTER COLUMN strabo_id SET NOT NULL;


-- 4) Enforce uniqueness. PostgreSQL 11 does not support
--    `ADD CONSTRAINT IF NOT EXISTS`, so check pg_constraint first.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM   pg_constraint
    WHERE  conname = 'sample_strabo_id_unique'
      AND  conrelid = 'straboexp.sample'::regclass
  ) THEN
    ALTER TABLE straboexp.sample
      ADD CONSTRAINT sample_strabo_id_unique UNIQUE (strabo_id);
  END IF;
END$$;


COMMIT;


-- Post-apply verification (run separately, outside the transaction):
--
--   SELECT count(*) AS total,
--          count(strabo_id) AS with_uuid,
--          count(DISTINCT strabo_id) AS distinct_uuids
--   FROM   straboexp.sample;
--
-- Expect total == with_uuid == distinct_uuids. Any divergence is a bug.
