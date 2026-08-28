-- =============================================================================
-- File: sql/project_merge_prefs.sql
-- Description: Per-project merge preferences for StraboField project uploads.
--              A row with union_tags = true forces UNION tag-merge semantics
--              for a project even when it has no collaborator rows. This is
--              the escape hatch for multi-device shared-credential groups
--              (several iPads uploading under one account): they look "solo"
--              to the server, and the solo REPLACE semantics (2026-08-16 tag
--              fix) would let each device's upload wipe geologic units the
--              other devices created. Rows are managed by the admin page
--              admin_merge_prefs.php (userpkey 3 only).
--
-- Apply (dev or prod), idempotent / safe to re-run:
--   cat sql/project_merge_prefs.sql | docker exec -i strabo-postgres \
--       psql -U postgres -d strabospot
--
-- DDL needs the superuser role on prod (-U postgres), matching the project's
-- established convention for schema changes.
-- =============================================================================

CREATE TABLE IF NOT EXISTS project_merge_prefs (
    pkey                    SERIAL PRIMARY KEY,
    strabo_project_id       VARCHAR                  NOT NULL,  -- matches collaborators.strabo_project_id type
    project_owner_user_pkey INTEGER                  NOT NULL,
    union_tags              BOOLEAN                  NOT NULL DEFAULT TRUE,
    note                    TEXT,                               -- why this project is flagged (audit/context)
    created_date            TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    UNIQUE (strabo_project_id, project_owner_user_pkey)
);

-- The upload-path lookup is by (strabo_project_id, project_owner_user_pkey);
-- covered by the UNIQUE constraint's index.

-- ---------------------------------------------------------------------------
-- Privileges. The table is created by the superuser (postgres) but the web app
-- connects as strabodbuser, with a readonly role for reporting - mirror the
-- grant convention used by the existing users/apptokens/jwts tables.
-- (GRANT is idempotent, so this is safe to re-run.)
-- ---------------------------------------------------------------------------
GRANT ALL PRIVILEGES ON TABLE project_merge_prefs TO strabodbuser;
GRANT USAGE, SELECT, UPDATE ON SEQUENCE project_merge_prefs_pkey_seq TO strabodbuser;
GRANT SELECT ON TABLE project_merge_prefs TO readonly;
