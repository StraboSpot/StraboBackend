-- microsync_phase2_access: who removed a member, when a role last changed (2026-10-03).
--
-- removed_by: the owner who removed the member, or the member themselves
-- (leaving). A removed member's app is told which it was (403
-- access_removed), so it can say "<owner> removed you" or stay quiet after
-- leaving. NULL for removals made before this column existed.
--
-- role_changed_at: set when the owner changes a member's role. Changes the
-- new role refuses are parked for the owner's review (instead of only being
-- turned down) once a member's role has changed (v3 §3.2, 17k).
--
-- Design: StraboMicro2 repo, docs/specs/collaboration-workflow-spec-v3.md
-- §12b 17j, 17k.
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres.
-- Idempotent:
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/microsync_phase2_access.sql

BEGIN;

ALTER TABLE strabomicro.micro_members
  ADD COLUMN IF NOT EXISTS removed_by      integer REFERENCES public.users(pkey),
  ADD COLUMN IF NOT EXISTS role_changed_at timestamptz;

COMMIT;
