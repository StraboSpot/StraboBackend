-- =============================================================================
-- File: sql/email_change_requests.sql
-- Description: Pending self-service email-change requests for the StraboSpot
--              website. A row is created when a logged-in user requests an email
--              change; the change is only applied once the user clicks the
--              expiring, single-use confirmation link sent to the NEW address.
--
-- Apply (dev or prod), idempotent / safe to re-run:
--   cat sql/email_change_requests.sql | docker exec -i strabo-postgres \
--       psql -U postgres -d strabospot
--
-- DDL needs the superuser role on prod (-U postgres), matching the project's
-- established convention for schema changes.
-- =============================================================================

CREATE TABLE IF NOT EXISTS email_change_requests (
    id           SERIAL PRIMARY KEY,
    userpkey     INTEGER                  NOT NULL,
    new_email    VARCHAR(255)             NOT NULL,
    token        VARCHAR(64)              NOT NULL UNIQUE,
    requested_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    expires_at   TIMESTAMP WITH TIME ZONE NOT NULL,
    used_at      TIMESTAMP WITH TIME ZONE          DEFAULT NULL
);

-- Fast token lookup on confirmation (also covered by the UNIQUE constraint, but
-- declared explicitly for clarity / parity with the userpkey index).
CREATE INDEX IF NOT EXISTS email_change_requests_token_idx
    ON email_change_requests (token);

-- Used to supersede a user's prior outstanding requests when they start a new one.
CREATE INDEX IF NOT EXISTS email_change_requests_userpkey_idx
    ON email_change_requests (userpkey);

-- ---------------------------------------------------------------------------
-- Privileges. The table is created by the superuser (postgres) but the web app
-- connects as strabodbuser, with a readonly role for reporting - mirror the
-- grant convention used by the existing users/apptokens/jwts tables.
-- (GRANT is idempotent, so this is safe to re-run.)
-- ---------------------------------------------------------------------------
GRANT ALL PRIVILEGES ON TABLE email_change_requests TO strabodbuser;
GRANT USAGE, SELECT, UPDATE ON SEQUENCE email_change_requests_id_seq TO strabodbuser;
GRANT SELECT ON TABLE email_change_requests TO readonly;
