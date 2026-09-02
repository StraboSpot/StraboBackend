-- =============================================================================
-- File: sql/export_jobs.sql
-- Description: Async export job queue for the StraboField Export Builder
--              (docs/ExportBuilder_Design.md §5). One row per submitted
--              build. The command-line worker (exportjobs/worker.php) claims
--              rows with FOR UPDATE SKIP LOCKED, writes progress into the row
--              as it runs, and stores the finished zip outside Apache's reach
--              (exportjobs/results/, .htaccess-denied; streamed only by
--              exportjobs/download.php after a session + owner check).
--
--              Rows are never physically deleted by the system: an expired
--              result flips status to 'expired' (file removed, recipe kept so
--              the user can re-run); a user "clear" sets deleted_at.
--
-- Apply (dev or prod), idempotent / safe to re-run:
--   cat sql/export_jobs.sql | docker exec -i strabo-postgres \
--       psql -U postgres -d strabospot
--
-- DDL needs the superuser role on prod (-U postgres), matching the project's
-- established convention for schema changes. Apply BEFORE pulling the code
-- that uses it.
-- =============================================================================

CREATE TABLE IF NOT EXISTS export_jobs (
    pkey            BIGSERIAL PRIMARY KEY,
    uuid            UUID        NOT NULL UNIQUE,            -- the only id that appears in URLs
    userpkey        INTEGER     NOT NULL,                   -- submitter (from the session, never a URL)
    status          VARCHAR(16) NOT NULL DEFAULT 'queued',  -- queued|running|done|failed|expired|cancelled
    recipe          JSONB       NOT NULL,                   -- design §5.2; stored verbatim, re-runnable
    recipe_summary  TEXT,                                   -- one-line human label for lists/emails
    origin          VARCHAR(16) NOT NULL DEFAULT 'builder', -- builder|search|rerun|test
    rerun_of        BIGINT      REFERENCES export_jobs(pkey),
    email_on_done   BOOLEAN     NOT NULL DEFAULT FALSE,
    -- progress (worker-written)
    phase           VARCHAR(32),                            -- resolve|children|gather|format:<fmt>|package|zip
    progress_done   INTEGER,
    progress_total  INTEGER,
    progress_note   TEXT,                                   -- "copying 312 of 980 images"
    heartbeat_at    TIMESTAMP WITH TIME ZONE,               -- stale-run detection (sweeper)
    worker_pid      INTEGER,
    attempt         SMALLINT    NOT NULL DEFAULT 0,
    -- outcome
    item_count      INTEGER,
    child_count     INTEGER,                                -- design D12: nested children pulled in
    result_path     TEXT,                                   -- relative to the results root
    result_bytes    BIGINT,
    result_sha256   CHAR(64),
    error_text      TEXT,
    index_synced_at TIMESTAMP WITH TIME ZONE,               -- strabosearch.sync_state(field) at FIND time
    -- lifecycle
    created_at      TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    started_at      TIMESTAMP WITH TIME ZONE,
    finished_at     TIMESTAMP WITH TIME ZONE,
    expires_at      TIMESTAMP WITH TIME ZONE,               -- finished_at + retention
    expired_at      TIMESTAMP WITH TIME ZONE,
    deleted_at      TIMESTAMP WITH TIME ZONE                -- user "clear": hidden from My Exports, kept for audit
);

CREATE INDEX IF NOT EXISTS export_jobs_user_idx
    ON export_jobs (userpkey, created_at DESC);
CREATE INDEX IF NOT EXISTS export_jobs_active_idx
    ON export_jobs (status, created_at) WHERE status IN ('queued', 'running');
CREATE INDEX IF NOT EXISTS export_jobs_expiry_idx
    ON export_jobs (expires_at) WHERE status = 'done';

-- ---------------------------------------------------------------------------
-- Privileges: created by the superuser, used by the web/worker role.
-- (GRANT is idempotent, so this is safe to re-run.)
-- ---------------------------------------------------------------------------
GRANT ALL PRIVILEGES ON TABLE export_jobs TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE export_jobs_pkey_seq TO strabodbuser;
