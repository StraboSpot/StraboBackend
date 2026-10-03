-- microsync_phase2_delete: deleted synced projects (2026-10-03).
--
-- One row per synced StraboMicro project its owner deleted. While purged_at
-- is NULL the project row and its sync store are still there (restorable for
-- 30 days, hidden everywhere); the daily purge then deletes them and sets
-- purged_at. The row itself is never removed: a copy that comes back months
-- later is still told the project was deleted (410 project_deleted) instead
-- of getting a plain 404. members: who belonged to it when it was deleted
-- (only they are told; anyone else gets 404). No foreign keys: the row
-- outlives the project and may outlive its users.
--
-- Design: StraboMicro2 repo, docs/specs/collaboration-workflow-spec-v3.md
-- §12b 17p, 17ac, 17ad.
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres.
-- Idempotent:
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/microsync_phase2_delete.sql

BEGIN;

CREATE TABLE IF NOT EXISTS strabomicro.micro_deleted_projects (
  project_id   integer PRIMARY KEY,       -- micro_projectmetadata.id it had
  strabo_id    varchar NOT NULL,
  owner_pkey   integer NOT NULL,
  name         varchar,
  members      integer[] NOT NULL,        -- active members (owner included) when deleted
  deleted_by   integer NOT NULL,
  deleted_at   timestamptz NOT NULL DEFAULT now(),
  purged_at    timestamptz                -- set by the purge, 30 days after deleted_at
);
CREATE INDEX IF NOT EXISTS micro_deleted_projects_due
  ON strabomicro.micro_deleted_projects (deleted_at) WHERE purged_at IS NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON strabomicro.micro_deleted_projects TO strabodbuser;
GRANT SELECT ON strabomicro.micro_deleted_projects TO readonly;

COMMIT;
