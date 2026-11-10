-- =============================================================================
-- File: sql/fieldcamp.sql
-- Description: Field Camp Wizard, stages 1 to 4 (camp records, the instructor
--              list, the dedicated camp accounts, the rock units, the custom
--              maps and the project package). An instructor (an account
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
--              fieldcamp_copy         student projects copied to the camp owner
--              fieldcamp_unit         stage 3: the rock units Macrostrat maps
--                                     show in the study area; the chosen ones
--                                     become geologic unit tags of the project
--              fieldcamp_unit_source  the source maps those units come from
--                                     (name and citation, for attribution)
--              fieldcamp_map          stage 4: the owner's My Maps that go into
--                                     the project as custom maps
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

-- One row per student project copied into the camp owner's account
-- (includes/fieldcamp/FieldCampCopy.php). The copy gets new project, dataset
-- and tag ids, because every student of a shared-package camp holds the same
-- ids and an account can hold only one project per id.
CREATE TABLE IF NOT EXISTS fieldcamp_copy (
    pkey               SERIAL PRIMARY KEY,
    fieldcamp_pkey     INTEGER                  NOT NULL REFERENCES fieldcamp(pkey),
    source_user_pkey   INTEGER                  NOT NULL,
    source_project_id  VARCHAR                  NOT NULL,
    target_user_pkey   INTEGER                  NOT NULL,
    target_project_id  VARCHAR                  NOT NULL,
    project_name       VARCHAR(255),
    dataset_count      INTEGER                  NOT NULL DEFAULT 0,
    spot_count         INTEGER                  NOT NULL DEFAULT 0,
    image_count        INTEGER                  NOT NULL DEFAULT 0,
    created_date       TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    UNIQUE (source_user_pkey, source_project_id, target_user_pkey)
);

CREATE INDEX IF NOT EXISTS fieldcamp_copy_camp_idx ON fieldcamp_copy (fieldcamp_pkey);

-- ---------------------------------------------------------------------------
-- Stage 3: rock units (includes/fieldcamp/FieldCampUnits.php).
-- ---------------------------------------------------------------------------

-- When and how the units were last looked up. units_bbox is the study area
-- (west,south,east,north) the lookup used, so the page can tell when the area
-- has changed since.
ALTER TABLE fieldcamp ADD COLUMN IF NOT EXISTS units_fetched_date TIMESTAMP WITH TIME ZONE;
ALTER TABLE fieldcamp ADD COLUMN IF NOT EXISTS units_zoom INTEGER;
ALTER TABLE fieldcamp ADD COLUMN IF NOT EXISTS units_bbox VARCHAR(120);

-- One row per Macrostrat legend entry found in the study area. The Macrostrat
-- columns are replaced by every new lookup; chosen, tag_name, unit_label and
-- tag_id are the instructor's and survive a new lookup. tag_id is the id the
-- unit's tag carries in the project (a UUID, as the app gives its tags), fixed
-- once given so a rebuilt package does not duplicate tags.
CREATE TABLE IF NOT EXISTS fieldcamp_unit (
    pkey            SERIAL PRIMARY KEY,
    fieldcamp_pkey  INTEGER                  NOT NULL REFERENCES fieldcamp(pkey),
    source_id       INTEGER                  NOT NULL,
    legend_id       INTEGER                  NOT NULL,
    map_unit_name   VARCHAR(500)             NOT NULL,
    strat_name      TEXT,
    age             TEXT,
    lith            TEXT,
    descrip         TEXT,
    comments        TEXT,
    t_age           DOUBLE PRECISION,                   -- top (youngest), Ma
    b_age           DOUBLE PRECISION,                   -- bottom (oldest), Ma
    lith_classes    TEXT,                               -- comma separated
    lith_types      TEXT,                               -- comma separated
    color           VARCHAR(7),                         -- #rrggbb or NULL
    sort_order      INTEGER                  NOT NULL DEFAULT 0,
    chosen          BOOLEAN                  NOT NULL DEFAULT TRUE,
    tag_name        VARCHAR(255)             NOT NULL,
    unit_label      VARCHAR(40)              NOT NULL,
    tag_id          VARCHAR(36)              NOT NULL UNIQUE,
    created_date    TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    UNIQUE (fieldcamp_pkey, legend_id)
);

CREATE TABLE IF NOT EXISTS fieldcamp_unit_source (
    pkey            SERIAL PRIMARY KEY,
    fieldcamp_pkey  INTEGER                  NOT NULL REFERENCES fieldcamp(pkey),
    source_id       INTEGER                  NOT NULL,
    name            TEXT,
    ref_title       TEXT,
    url             TEXT,
    scale           VARCHAR(40),
    UNIQUE (fieldcamp_pkey, source_id)
);

-- ---------------------------------------------------------------------------
-- Stage 4: the project package (includes/fieldcamp/FieldCampPackage.php).
-- ---------------------------------------------------------------------------

-- The ids the camp's project and its Default dataset carry in the package, in
-- the app's id format. Given at the first build and kept, so a rebuilt package
-- is the same project. (Shared-package camps: every student gets these ids.)
ALTER TABLE fieldcamp ADD COLUMN IF NOT EXISTS project_id BIGINT;
ALTER TABLE fieldcamp ADD COLUMN IF NOT EXISTS dataset_id BIGINT;

-- The owner's My Maps (geotiffs.hash) that go into the project as custom maps.
CREATE TABLE IF NOT EXISTS fieldcamp_map (
    pkey            SERIAL PRIMARY KEY,
    fieldcamp_pkey  INTEGER                  NOT NULL REFERENCES fieldcamp(pkey),
    geotiff_hash    VARCHAR(50)              NOT NULL,
    title           VARCHAR(255)             NOT NULL,
    created_date    TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
    UNIQUE (fieldcamp_pkey, geotiff_hash)
);

-- ---------------------------------------------------------------------------
-- Privileges. Tables are created by the superuser (postgres) but the web app
-- connects as strabodbuser, with a readonly role for reporting.
-- (GRANT is idempotent, so this is safe to re-run.)
-- ---------------------------------------------------------------------------
GRANT ALL PRIVILEGES ON TABLE fieldcamp_instructors, fieldcamp, fieldcamp_account, fieldcamp_viewer, fieldcamp_copy,
    fieldcamp_unit, fieldcamp_unit_source, fieldcamp_map TO strabodbuser;
GRANT USAGE, SELECT, UPDATE ON SEQUENCE fieldcamp_instructors_pkey_seq, fieldcamp_pkey_seq,
    fieldcamp_account_pkey_seq, fieldcamp_viewer_pkey_seq, fieldcamp_copy_pkey_seq,
    fieldcamp_unit_pkey_seq, fieldcamp_unit_source_pkey_seq, fieldcamp_map_pkey_seq TO strabodbuser;
GRANT SELECT ON TABLE fieldcamp_instructors, fieldcamp, fieldcamp_account, fieldcamp_viewer, fieldcamp_copy,
    fieldcamp_unit, fieldcamp_unit_source, fieldcamp_map TO readonly;
