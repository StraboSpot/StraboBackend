-- =============================================================================
-- File: sql/project_transfers.sql
-- Description: StraboField project ownership transfers
--              (docs/ProjectTransfer_Design.md). One row per transfer request.
--              A row is the request (pending), its outcome (accepted /
--              declined / cancelled / expired / refused / failed), the audit
--              record of what moved (summary JSONB), and, once `applied` is
--              set, the TOMBSTONE that makes the old owner's upload path
--              refuse to recreate the project from a stale device copy
--              (CollaborationAuth::canUploadProjectAsOwner).
--
--              A reversal performed from admin_transfers.php inserts a NEW row
--              with kind = 'reversal' and the parties swapped, and stamps
--              reversed_date on the original, so the tombstone then protects
--              the reversed direction.
--
-- Apply (dev or prod), idempotent / safe to re-run:
--   cat sql/project_transfers.sql | docker exec -i strabo-postgres \
--       psql -U postgres -d strabospot
--
-- DDL needs the superuser role on prod (-U postgres), matching the project's
-- established convention for schema changes. Apply BEFORE pulling the code
-- that uses it.
-- =============================================================================

CREATE TABLE IF NOT EXISTS project_transfers (
    pkey                 SERIAL PRIMARY KEY,
    uuid                 VARCHAR(64) NOT NULL UNIQUE,            -- the token in the mails / URLs
    kind                 VARCHAR(16) NOT NULL DEFAULT 'transfer' -- transfer | reversal
                         CHECK (kind IN ('transfer','reversal')),
    strabo_project_id    VARCHAR     NOT NULL,                   -- matches collaborators.strabo_project_id
    from_user_pkey       INTEGER     NOT NULL REFERENCES users(pkey),
    to_user_pkey         INTEGER     NOT NULL REFERENCES users(pkey),
    status               VARCHAR(16) NOT NULL
                         CHECK (status IN ('pending','accepted','declined',
                                           'cancelled','expired','refused','failed')),
    keep_as_collaborator BOOLEAN     NOT NULL DEFAULT TRUE,      -- D1: old owner stays on as admin
    applied              BOOLEAN     NOT NULL DEFAULT FALSE,     -- data rewrite has started: tombstone
    step                 SMALLINT    NOT NULL DEFAULT 0,         -- last completed service step (§6)
    project_name         VARCHAR,                                -- snapshot at request time
    created_date         TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_date         TIMESTAMPTZ NOT NULL,
    decided_date         TIMESTAMPTZ,                            -- accept / decline / cancel / expire
    completed_date       TIMESTAMPTZ,                            -- every step done
    reversed_date        TIMESTAMPTZ,
    reversed_by_pkey     INTEGER,
    requested_by_pkey    INTEGER,                                -- initiator (owner, or admin for reversals)
    decided_by_pkey      INTEGER,                                -- who accepted / declined / cancelled
    summary              JSONB                                   -- before/after counts, step log, error, reason
);

-- One live request per (project, owner).
CREATE UNIQUE INDEX IF NOT EXISTS project_transfers_one_pending
    ON project_transfers (strabo_project_id, from_user_pkey)
    WHERE status = 'pending';

-- Tombstone lookup on the upload path.
CREATE INDEX IF NOT EXISTS project_transfers_tombstone
    ON project_transfers (strabo_project_id, from_user_pkey)
    WHERE applied AND reversed_date IS NULL;

-- My Field Data listings.
CREATE INDEX IF NOT EXISTS project_transfers_to_user
    ON project_transfers (to_user_pkey, status);
CREATE INDEX IF NOT EXISTS project_transfers_from_user
    ON project_transfers (from_user_pkey, status);
