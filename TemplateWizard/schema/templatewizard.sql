-- Template Wizard schema (StraboField tabular import/export)
-- Apply as superuser:
--   cat TemplateWizard/schema/templatewizard.sql | docker exec -i strabo-postgres psql -U postgres -d strabospot
-- NOTE: rides a post-Samples-cutover develop migration, NOT the Phase 1 samples runbook.

CREATE TABLE IF NOT EXISTS field_templates (
    pkey        serial PRIMARY KEY,
    userpkey    integer NOT NULL,
    name        varchar(255) NOT NULL,
    spec        jsonb NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    modified_at timestamptz NOT NULL DEFAULT now(),
    deleted     boolean NOT NULL DEFAULT false
);

CREATE UNIQUE INDEX IF NOT EXISTS field_templates_user_name_uq
    ON field_templates (userpkey, lower(name)) WHERE NOT deleted;

CREATE INDEX IF NOT EXISTS field_templates_user_idx
    ON field_templates (userpkey) WHERE NOT deleted;

-- Import-run journal: written BEFORE Neo4j writes begin; enables compensating
-- rollback (delete created spots / restore prior JSON of updated spots) and
-- post-hoc forensics (rawcache philosophy — rows are never deleted).
CREATE TABLE IF NOT EXISTS field_tabular_runs (
    pkey         serial PRIMARY KEY,
    userpkey     integer NOT NULL,
    project_id   varchar(64) NOT NULL,
    dataset_id   varchar(64) NOT NULL,
    dataset_new  boolean NOT NULL DEFAULT false,
    template     jsonb,
    plan_counts  jsonb,
    -- [{action:'create'|'update', spot_id, prior_json (updates only)}]
    rows         jsonb NOT NULL,
    status       varchar(24) NOT NULL DEFAULT 'started',
      -- started | committed | rolled_back | rollback_failed
    error        text,
    started_at   timestamptz NOT NULL DEFAULT now(),
    finished_at  timestamptz
);

CREATE INDEX IF NOT EXISTS field_tabular_runs_user_idx
    ON field_tabular_runs (userpkey, started_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON field_templates, field_tabular_runs TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE field_templates_pkey_seq, field_tabular_runs_pkey_seq TO strabodbuser;
