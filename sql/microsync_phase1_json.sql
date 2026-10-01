-- microsync_phase1_json: entity bodies keep the key order the app wrote (2026-10-01).
--
-- jsonb sorts object keys (shorter first), so project.json files built from
-- the entity store, and the PDFs rendered from them, listed fields in a
-- different order than the app. Plain json stores the text as sent. Only
-- ->> lookups read these columns and nothing compares them in SQL, so json
-- (which has no equality operator) is enough. micro_parked_pushes.review is
-- written by the server only and stays jsonb. Design: StraboMicro2 repo,
-- docs/specs/collaboration-phase0-design.md §4.9 (P1-2).
--
-- Existing rows keep their already sorted text; the one-off tool
-- microsync/tools/reorder_keys.php restores the order of converted projects.
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres
-- (either order works: Postgres casts json <-> jsonb on assignment).
-- Idempotent (ALTER ... TYPE json on a json column is a no-op rewrite):
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/microsync_phase1_json.sql

BEGIN;

ALTER TABLE strabomicro.micro_entities
  ALTER COLUMN body        TYPE json USING body::json,
  ALTER COLUMN child_order TYPE json USING child_order::json;

ALTER TABLE strabomicro.micro_changes
  ALTER COLUMN before TYPE json USING before::json,
  ALTER COLUMN after  TYPE json USING after::json;

ALTER TABLE strabomicro.micro_pushes
  ALTER COLUMN result TYPE json USING result::json;

ALTER TABLE strabomicro.micro_parked_pushes
  ALTER COLUMN payload TYPE json USING payload::json;

COMMIT;
