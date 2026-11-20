-- =============================================================================
-- File: sql/field_deletions.sql
-- Description: Explicit deletes of project-level items (memos and tags).
--
--              A project upload merges memos (reports) and tags with the server
--              copy and keeps every item the upload leaves out, because one
--              account on several devices looks like a solo user to the server
--              and a replace let a stale device wipe what the others made
--              (tags, 2026-10-16). So an omission can never mean "deleted".
--              A client that knows it deleted an item says so through
--              POST /db/projectDeletions/{project id}; the item is removed at once
--              and every later upload that still carries it drops it, unless
--              that copy of a memo was edited after the delete.
--
--              field_deletions  one row per deleted item per project:
--                               report    a memo (item_id = memo id)
--                               tag       a tag or geologic unit (item_id = tag id)
--                               tag_spot  a spot taken out of a tag or unit
--                                         (item_id = "<tag id>/<spot id>")
--
-- Apply (dev or prod), idempotent / safe to re-run:
--   cat sql/field_deletions.sql | docker exec -i strabo-postgres psql -U postgres -d strabospot
--
-- DDL needs the superuser role on prod (-U postgres); the GRANT at the end
-- gives the web user what it needs. Apply BEFORE pulling the code that uses it
-- (the code also works without the table: no deletes are known then).
-- =============================================================================

CREATE TABLE IF NOT EXISTS field_deletions (
    strabo_project_id        VARCHAR(64)  NOT NULL,
    project_owner_user_pkey  INTEGER      NOT NULL,
    item_type                VARCHAR(16)  NOT NULL CHECK (item_type IN ('report','tag','tag_spot')),
    item_id                  VARCHAR(64)  NOT NULL,
    deleted_at               BIGINT       NOT NULL,   -- ms, the deleting device's clock
    deleted_by_user_pkey     INTEGER      NOT NULL,
    recorded_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (strabo_project_id, project_owner_user_pkey, item_type, item_id)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON field_deletions TO strabodbuser;
