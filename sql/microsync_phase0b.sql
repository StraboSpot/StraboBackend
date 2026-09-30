-- microsync_phase0b: point count sessions become sync entities (2026-09-30).
--
-- StraboMicro2 keeps point count sessions outside project.json, as
-- point-counts/<sessionId>.json in the project folder and in the .smz. They
-- are user data with an id and a parent micrograph, so they sync as entity
-- type 'point_count' (parent: micrograph; body: the session JSON). Design:
-- StraboMicro2 repo, docs/specs/collaboration-phase0-design.md §4.7.
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres.
-- Idempotent (drops and re-creates the check):
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/microsync_phase0b.sql
-- (Applied to dev 2026-09-30.)

BEGIN;

ALTER TABLE strabomicro.micro_entities
  DROP CONSTRAINT IF EXISTS micro_entities_entity_type_check;
ALTER TABLE strabomicro.micro_entities
  ADD CONSTRAINT micro_entities_entity_type_check CHECK (entity_type IN
    ('project','dataset','sample','micrograph','spot','tag','group','preset','point_count'));

COMMIT;
