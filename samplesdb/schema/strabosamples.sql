-- StraboSamples PostgreSQL DDL
-- Phase 1 artifact. See docs/StraboSamples/DESIGN_PROPOSAL.md §5 for the canonical spec.
-- All design decisions (identity, storage shape, collaboration model, sub-arrays,
-- cross-system links, migration log) are documented there; this file is the runnable
-- realization of §5.1–§5.6.
--
-- Apply to a fresh dev clone:
--   docker exec strabo-postgres psql -U strabodbuser -d strabospot -f /path/strabosamples.sql
--
-- Roll back the whole feature for any reason: `DROP SCHEMA strabosamples CASCADE;`
-- (the migration is non-destructive against subsystem sources per §10).

BEGIN;

CREATE SCHEMA IF NOT EXISTS strabosamples;

-- Used by gen_random_uuid() for new sample ids minted via the API (Phase 2)
-- and for samples_migration_log.run_id values.
CREATE EXTENSION IF NOT EXISTS pgcrypto;


-- ============================================================================
-- 5.1  samples (the spine)
-- ============================================================================
-- Identity: composite (id, userpkey). `id` is TEXT so legacy Field/Micro
-- timestamp-derived ids grandfather in alongside UUIDs minted for new samples
-- and for every Experimental row (§3.4, §9.3).
-- Ownership == userpkey (mirrors the projects model). No separate owner_pkey.

CREATE TABLE strabosamples.samples (
  id                       TEXT NOT NULL,
  userpkey                 INTEGER NOT NULL REFERENCES users(pkey),

  -- common spine (indexable, searchable)
  name                     TEXT,
  igsn                     TEXT,
  description              TEXT,
  notes                    TEXT,
  latitude                 DOUBLE PRECISION,
  longitude                DOUBLE PRECISION,

  -- display normalization for cross-system filters (§11.1).
  -- Derived on upsert from per-subsystem source columns; original values are
  -- preserved verbatim in *_data JSONB.
  display_sample_type      TEXT,
  display_sample_purpose   TEXT,

  -- parent/child (single-parent tree). Brand-new capability — NOT back-filled
  -- from any subsystem at migration; tree starts empty and grows via API (§4.3).
  parent_sample_id         TEXT,
  parent_userpkey          INTEGER,

  -- audit. Owner is userpkey (part of the PK) — no separate owner column.
  created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_by               INTEGER NOT NULL REFERENCES users(pkey),
  modified_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
  modified_by              INTEGER NOT NULL REFERENCES users(pkey),

  -- per-subsystem extension data (flat fields).
  -- Cross-system references (Field spot, Micro/Experimental projects) live in
  -- sample_subsystem_links (§5.5), not on this row, because the relationship
  -- is many-to-many.
  field_data               JSONB,
  micro_data               JSONB,
  experimental_data        JSONB,

  -- user-defined key/value fields from the tabular import path (flat
  -- {"header": "value"} object; keys round-trip verbatim as columns on
  -- tabular export). Owned by the samples system itself — no subsystem
  -- reads or writes this column.
  custom_data              JSONB,

  PRIMARY KEY (id, userpkey),

  -- Self-FK for the parent link. DEFERRABLE so cycles during a single migration
  -- transaction (or a future re-parent operation) don't trip the check mid-tx.
  -- ON DELETE SET NULL so deleting a parent orphans children rather than
  -- cascading; clients surface the orphan state in the UI (§7.5).
  FOREIGN KEY (parent_sample_id, parent_userpkey)
    REFERENCES strabosamples.samples (id, userpkey)
    ON DELETE SET NULL
    DEFERRABLE INITIALLY DEFERRED,

  -- Both parent columns are NULL or both are populated; never mixed.
  CONSTRAINT samples_parent_pair_chk
    CHECK ((parent_sample_id IS NULL) = (parent_userpkey IS NULL))
);

CREATE INDEX idx_samples_userpkey            ON strabosamples.samples (userpkey);
CREATE INDEX idx_samples_created_by          ON strabosamples.samples (created_by);
CREATE INDEX idx_samples_igsn                ON strabosamples.samples (igsn) WHERE igsn IS NOT NULL;
CREATE INDEX idx_samples_name                ON strabosamples.samples (lower(name));
CREATE INDEX idx_samples_display_type        ON strabosamples.samples (display_sample_type);
CREATE INDEX idx_samples_display_purpose     ON strabosamples.samples (display_sample_purpose);
CREATE INDEX idx_samples_modified            ON strabosamples.samples (modified_at DESC);
CREATE INDEX idx_samples_parent              ON strabosamples.samples (parent_sample_id, parent_userpkey)
                                               WHERE parent_sample_id IS NOT NULL;
-- Selective GIN indexes on *_data JSONB intentionally deferred — added in Phase 2
-- once the real query shapes are known.


-- ============================================================================
-- 5.2  sample_collaborators
-- ============================================================================
-- Invite/accept workflow, mirrors public.collaborators (project-level).
-- Pending invites confer NO access — the auth gate is `accepted = TRUE`.
-- Auto-seeded project-collaborators come in pre-accepted (§7.3.1).

CREATE TABLE strabosamples.sample_collaborators (
  pkey                SERIAL PRIMARY KEY,
  sample_id           TEXT NOT NULL,
  sample_userpkey     INTEGER NOT NULL,
  collaborator_pkey   INTEGER NOT NULL REFERENCES users(pkey),
  permission_level    TEXT NOT NULL CHECK (permission_level IN ('edit', 'readonly')),

  -- per-invite token used by the accept link (mirrors public.collaborators.uuid)
  uuid                TEXT NOT NULL,
  accepted            BOOLEAN NOT NULL DEFAULT FALSE,
  accepted_at         TIMESTAMPTZ,

  added_by            INTEGER NOT NULL REFERENCES users(pkey),
  added_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Soft delete — plays the role of public.collaborators.disabled.
  -- Re-inviting a soft-removed collaborator clears removed_at and updates level.
  removed_at          TIMESTAMPTZ,

  FOREIGN KEY (sample_id, sample_userpkey)
    REFERENCES strabosamples.samples (id, userpkey)
    ON DELETE CASCADE
);

-- One active grant per (sample, collaborator). MUST be a partial INDEX —
-- PostgreSQL does not permit a WHERE clause on an inline UNIQUE table
-- constraint (v4 doc correction).
CREATE UNIQUE INDEX idx_sample_collab_active_unique
  ON strabosamples.sample_collaborators (sample_id, sample_userpkey, collaborator_pkey)
  WHERE removed_at IS NULL;

CREATE INDEX idx_sample_collab_sample        ON strabosamples.sample_collaborators (sample_id, sample_userpkey);
CREATE INDEX idx_sample_collab_collaborator  ON strabosamples.sample_collaborators (collaborator_pkey)
                                               WHERE removed_at IS NULL;
CREATE INDEX idx_sample_collab_uuid          ON strabosamples.sample_collaborators (uuid);


-- ============================================================================
-- 5.3  sample_changelog
-- ============================================================================
-- Append-only audit. Every meaningful change appends one row. Soft-delete vs
-- hard-delete semantics for samples themselves are still open (§16 item 5);
-- for now `ON DELETE CASCADE` cascades changelog with the sample. If we move
-- to soft delete in Phase 2 the cascade is harmless (no actual deletes occur).

CREATE TABLE strabosamples.sample_changelog (
  pkey              BIGSERIAL PRIMARY KEY,
  sample_id         TEXT NOT NULL,
  sample_userpkey   INTEGER NOT NULL,
  changed_by        INTEGER NOT NULL REFERENCES users(pkey),
  changed_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- One of: 'create', 'update', 'delete', 'collab_add', 'collab_remove',
  -- 'parent_set', 'parent_clear', 'composition_change',
  -- 'parameters_change', 'documents_change'
  change_type       TEXT NOT NULL,

  -- One of: 'field', 'micro', 'experimental', 'samples_api', 'migration'
  source_subsystem  TEXT,

  -- { "field_name": { "old": ..., "new": ... }, ... }
  changes           JSONB,

  FOREIGN KEY (sample_id, sample_userpkey)
    REFERENCES strabosamples.samples (id, userpkey)
    ON DELETE CASCADE
);

CREATE INDEX idx_changelog_sample            ON strabosamples.sample_changelog (sample_id, sample_userpkey, changed_at DESC);
CREATE INDEX idx_changelog_actor             ON strabosamples.sample_changelog (changed_by, changed_at DESC);


-- ============================================================================
-- 5.4  Sub-array child tables
-- ============================================================================
-- Superset of Experimental's source tables: `other_mineral`, `other_control`,
-- and `ordering` do not exist in straboexp.sample_composition /
-- straboexp.sample_parameter (verified 2026-05-29). They migrate as NULL/0;
-- the unmodified source rows are preserved in experimental_data JSONB.
-- sample_documents adds `original_filename` from straboexp.document so the
-- human-friendly filename survives migration (physical files at
-- experimental/expimages/{uuid} are untouched).

CREATE TABLE strabosamples.sample_composition (
  pkey              BIGSERIAL PRIMARY KEY,
  sample_id         TEXT NOT NULL,
  sample_userpkey   INTEGER NOT NULL,
  mineral           TEXT NOT NULL,
  other_mineral     TEXT,
  -- Preserved as TEXT per source schema; Experimental UI enforces numeric input
  -- client-side but storage is varchar.
  fraction          TEXT,
  -- Enum-ish (Vol%, Mol%, Wt%, MPa) but stored as TEXT to accommodate legacy
  -- rows and forward-compat. No CHECK constraint.
  unit              TEXT,
  grainsize         TEXT,
  ordering          INTEGER NOT NULL DEFAULT 0,

  FOREIGN KEY (sample_id, sample_userpkey)
    REFERENCES strabosamples.samples (id, userpkey)
    ON DELETE CASCADE
);

CREATE TABLE strabosamples.sample_parameters (
  pkey              BIGSERIAL PRIMARY KEY,
  sample_id         TEXT NOT NULL,
  sample_userpkey   INTEGER NOT NULL,
  control           TEXT NOT NULL,
  other_control     TEXT,
  value             TEXT,
  unit              TEXT,
  prefix            TEXT,
  note              TEXT,
  ordering          INTEGER NOT NULL DEFAULT 0,

  FOREIGN KEY (sample_id, sample_userpkey)
    REFERENCES strabosamples.samples (id, userpkey)
    ON DELETE CASCADE
);

CREATE TABLE strabosamples.sample_documents (
  pkey              BIGSERIAL PRIMARY KEY,
  sample_id         TEXT NOT NULL,
  sample_userpkey   INTEGER NOT NULL,
  uuid              TEXT NOT NULL,
  type              TEXT,
  other_type        TEXT,
  format            TEXT,
  other_format      TEXT,
  path              TEXT,
  -- Original straboexp.document.id (human/client document id, not its int pkey)
  document_id       TEXT,
  -- Human filename at upload time (preserved from straboexp.document.original_filename)
  original_filename TEXT,
  description       TEXT,
  ordering          INTEGER NOT NULL DEFAULT 0,

  FOREIGN KEY (sample_id, sample_userpkey)
    REFERENCES strabosamples.samples (id, userpkey)
    ON DELETE CASCADE
);

CREATE INDEX idx_composition_sample          ON strabosamples.sample_composition (sample_id, sample_userpkey);
CREATE INDEX idx_parameters_sample           ON strabosamples.sample_parameters (sample_id, sample_userpkey);
CREATE INDEX idx_documents_sample            ON strabosamples.sample_documents (sample_id, sample_userpkey);


-- ============================================================================
-- 5.5  sample_subsystem_links  (many-to-many)
-- ============================================================================
-- One row per (sample, subsystem-instance). A sample referenced by 3 Micro
-- projects = 3 rows with subsystem='micro'. A sample in 1 Field spot = 1 row
-- with subsystem='field', reference_id = spot_id (sample-spot id for rich
-- samples, holding-spot id for legacy — see §9.1).

CREATE TABLE strabosamples.sample_subsystem_links (
  pkey                 BIGSERIAL PRIMARY KEY,
  sample_id            TEXT NOT NULL,
  sample_userpkey      INTEGER NOT NULL,
  subsystem            TEXT NOT NULL CHECK (subsystem IN ('field', 'micro', 'experimental')),

  -- field: spot_id  |  micro: project_id  |  experimental: project_id
  reference_id         TEXT NOT NULL,

  -- Owner of the containing record. May differ from sample_userpkey when the
  -- sample is hosted inside a collaborator's project — see §4.1 "host" identity.
  reference_userpkey   INTEGER NOT NULL,

  -- Subsystem-specific extras without DDL churn:
  --   field:        {"dataset_id": "..."}
  --   micro:        {"dataset_id": "...", "micrograph_count": N}
  --   experimental: {"experiment_count": N}
  reference_metadata   JSONB,

  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  modified_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

  FOREIGN KEY (sample_id, sample_userpkey)
    REFERENCES strabosamples.samples (id, userpkey)
    ON DELETE CASCADE,

  -- One link per (sample, subsystem, reference instance).
  UNIQUE (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey)
);

CREATE INDEX idx_subsystem_links_sample
  ON strabosamples.sample_subsystem_links (sample_id, sample_userpkey);
CREATE INDEX idx_subsystem_links_reference
  ON strabosamples.sample_subsystem_links (subsystem, reference_id, reference_userpkey);
CREATE INDEX idx_subsystem_links_subsystem
  ON strabosamples.sample_subsystem_links (subsystem);


-- ============================================================================
-- 5.6  samples_migration_log
-- ============================================================================
-- Records every action the migration script takes. Lives in this schema for
-- cohesion; consulted post-migration for debugging. No FK to samples — the
-- log must survive a `DROP SCHEMA strabosamples CASCADE` only insofar as the
-- migration itself is reversible (the source tables remain intact per §10).

CREATE TABLE strabosamples.samples_migration_log (
  pkey              BIGSERIAL PRIMARY KEY,
  -- Per-invocation grouping key; one UUID per migration run.
  run_id            UUID NOT NULL,
  -- One of: 'field', 'micro', 'experimental'
  source_subsystem  TEXT NOT NULL,
  source_id         TEXT NOT NULL,
  source_userpkey   INTEGER NOT NULL,

  -- Resulting StraboSamples row (NULL on skipped/conflict actions).
  result_sample_id  TEXT,
  result_userpkey   INTEGER,

  -- One of: 'created', 'merged', 'skipped_orphan', 'skipped_duplicate', 'conflict_logged'
  action            TEXT NOT NULL,

  -- Per-column conflict details when action = 'conflict_logged' or 'merged'.
  conflicts         JSONB,
  notes             TEXT,
  logged_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_migration_log_run     ON strabosamples.samples_migration_log (run_id, action);
CREATE INDEX idx_migration_log_source  ON strabosamples.samples_migration_log (source_subsystem, source_id);


COMMIT;
