-- 2026-08-03 (fix/field-string-sample-ids)
-- Field PG search mirror: sample.strabo_sample_id was varchar(20). Sample ids
-- may now be UUIDs (36 chars) minted by the StraboSamples system; the old
-- width made buildPgDataset's INSERT fail ("value too long"), which both
-- dropped the mirror row AND leaked PHP warning HTML ahead of the JSON in
-- /db upload responses (clients parsed that as a failed upload).
--
-- Apply (dev + prod — table is owned by postgres on both):
--   docker exec -i strabo-postgres psql -U postgres -d strabospot < sql/widen_sample_strabo_sample_id.sql
-- (applied on dev 2026-08-03)
--
-- Widening a varchar is metadata-only in PostgreSQL (no table rewrite); safe live.

ALTER TABLE sample ALTER COLUMN strabo_sample_id TYPE character varying(64);
