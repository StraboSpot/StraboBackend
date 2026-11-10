-- =============================================================================
-- File: sql/fieldcamp.sql
-- Description: Field Camp Wizard, stages 1 and 2 (camp records, the instructor
--              list and the dedicated camp accounts). An instructor (an account
--              our team puts on fieldcamp_instructors) sets up a camp on the
--              website and creates the student accounts for it; later stages
--              add the maps, rock units and the project package.
--
--              fieldcamp_instructors  who may use the wizard (managed by the
--                                     admin page admin_field_camp_instructors.php,
--                                     userpkey 3 only)
--              fieldcamp              one row per camp
--              fieldcamp_account      the dedicated accounts created for a camp;
--                                     a row here is what makes a users row a
--                                     "camp account" (no password change, no
--                                     email change, no self delete)
--              fieldcamp_viewer       existing accounts (instructors, TAs) that
--                                     get access to the camp's student work
--
-- Apply (dev or prod), idempotent / safe to re-run:
--   cat sql/fieldcamp.sql | docker exec -i strabo-postgres \
--       psql -U postgres -d strabospot
--
-- DDL needs the superuser role on prod (-U postgres), matching the project's
-- established convention for schema changes.
-- =============================================================================

CREATE TABLE IF NOT EXISTS fieldcamp_instructors (
    pkey          SERIAL PRIMARY KEY,
    user_pkey     INTEGER                  NOT NULL UNIQUE REFERENCES users(pkey),
    note          TEXT,                               -- who / which school (audit/context)
    added_by      INTEGER,
    created_date  TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS fieldcamp (
    pkey               SERIAL PRIMARY KEY,
    owner_user_pkey    INTEGER                  NOT NULL REFERENCES users(pkey),
    camp_name          VARCHAR(255)             NOT NULL,
    camp_year          INTEGER                  NOT NULL,
    start_date         DATE,
    end_date           DATE,
    project_name       VARCHAR(255)             NOT NULL,
    -- Study area, WGS84 degrees. NULL until the Area step is saved.
    bbox_west          DOUBLE PRECISION,
    bbox_south         DOUBLE PRECISION,
    bbox_east          DOUBLE PRECISION,
    bbox_north         DOUBLE PRECISION,
    -- 'shared' = one common-id package, airdropped as in past camps (mode A).
    -- 'per_student' = unique project per student, served at login (mode C, later).
    delivery           VARCHAR(20)              NOT NULL DEFAULT 'shared',
    -- draft | building | ready | failed | archived
    status             VARCHAR(20)              NOT NULL DEFAULT 'draft',
    username_template  VARCHAR(80),
    created_date       TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    modified_date      TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS fieldcamp_owner_idx ON fieldcamp (owner_user_pkey);

CREATE TABLE IF NOT EXISTS fieldcamp_account (
    pkey              SERIAL PRIMARY KEY,
    fieldcamp_pkey    INTEGER                  NOT NULL REFERENCES fieldcamp(pkey),
    user_pkey         INTEGER                  NOT NULL UNIQUE REFERENCES users(pkey),
    username          VARCHAR(255)             NOT NULL,
    seq               INTEGER                  NOT NULL,  -- the {n} of the template
    created_date      TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    deactivated_date  TIMESTAMP WITH TIME ZONE,           -- cannot sign in; reversible
    removed_date      TIMESTAMP WITH TIME ZONE            -- account soft-deleted
);

CREATE INDEX IF NOT EXISTS fieldcamp_account_camp_idx ON fieldcamp_account (fieldcamp_pkey);

CREATE TABLE IF NOT EXISTS fieldcamp_viewer (
    pkey            SERIAL PRIMARY KEY,
    fieldcamp_pkey  INTEGER                  NOT NULL REFERENCES fieldcamp(pkey),
    user_pkey       INTEGER                  NOT NULL REFERENCES users(pkey),
    created_date    TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    UNIQUE (fieldcamp_pkey, user_pkey)
);

-- ---------------------------------------------------------------------------
-- Privileges. Tables are created by the superuser (postgres) but the web app
-- connects as strabodbuser, with a readonly role for reporting.
-- (GRANT is idempotent, so this is safe to re-run.)
-- ---------------------------------------------------------------------------
GRANT ALL PRIVILEGES ON TABLE fieldcamp_instructors, fieldcamp, fieldcamp_account, fieldcamp_viewer TO strabodbuser;
GRANT USAGE, SELECT, UPDATE ON SEQUENCE fieldcamp_instructors_pkey_seq, fieldcamp_pkey_seq,
    fieldcamp_account_pkey_seq, fieldcamp_viewer_pkey_seq TO strabodbuser;
GRANT SELECT ON TABLE fieldcamp_instructors, fieldcamp, fieldcamp_account, fieldcamp_viewer TO readonly;
