-- StraboExperimental: allow multiple experiments to link one StraboSamples
-- sample (multi-link, decision D1 in
-- docs/StraboSamples/Exp_StraboSamples_Linking.md).
--
-- The spine link table (strabosamples.sample_subsystem_links) is many-to-many
-- by design; the ONLY schema-level 1:1 was this UNIQUE constraint on
-- straboexp.sample.strabo_id (one straboexp.sample row per experiment, so a
-- shared spine sample means duplicate strabo_id values across rows). Replace
-- the constraint with a plain index: lookups by strabo_id remain in the
-- FK-ordering heal path, the reference-scoped removal path, and the audits.
--
-- Apply against a dev clone:
--   docker cp samplesdb/schema/exp_strabo_id_multilink.sql strabo-postgres:/tmp/
--   docker exec strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 -f /tmp/exp_strabo_id_multilink.sql
--
-- Idempotent: re-running is a no-op.
--
-- Rollback caveat: re-adding the UNIQUE constraint is only possible while no
-- two rows actually share a strabo_id. Once users create multi-links, the
-- honest rollback is code-level (disable the picker), not DDL.

BEGIN;

ALTER TABLE straboexp.sample
  DROP CONSTRAINT IF EXISTS sample_strabo_id_unique;

CREATE INDEX IF NOT EXISTS idx_sample_strabo_id
  ON straboexp.sample (strabo_id);

COMMIT;

-- Post-apply verification (run separately):
--
--   SELECT conname FROM pg_constraint
--    WHERE conrelid = 'straboexp.sample'::regclass;
--   -- expect: no sample_strabo_id_unique row
--
--   SELECT indexname FROM pg_indexes
--    WHERE schemaname='straboexp' AND tablename='sample';
--   -- expect: idx_sample_strabo_id present
