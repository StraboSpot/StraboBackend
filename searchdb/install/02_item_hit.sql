-- StraboSearch Phase 2 — `strabosearch.item_hit` table.
--
-- One row per (item, host-project) pair. Items: Field spots, Micro
-- micrographs, Exp experiments, Samples spine rows. The Samples fan-out
-- (one sample × N linked subsystem projects = N rows) happens at extraction
-- time, expressed by the soft uniqueness constraint at the bottom.
--
-- Per §5.1.3 of DESIGN_PROPOSAL.md (Phase 1, SIGNED OFF v0.7 2026-06-16).
--
-- Indexes are deliberately NOT created here — per §5.1.5 they are applied
-- post-backfill in a single REINDEX pass to avoid per-row write amplification
-- during the bulk load. They ship with the extractor sub-branches.
--
-- Geometry: SRID 4326 (WGS84 lon/lat) — Phase 2 declares it properly,
-- correcting the incumbent SRID-0 convention noted in the shapegeology
-- repair (extractor wraps incoming coordinates with ST_SetSRID).

CREATE TABLE IF NOT EXISTS strabosearch.item_hit (
    item_hit_pkey      bigserial PRIMARY KEY,

    -- Identity ---------------------------------------------------------------
    item_type          varchar    NOT NULL,            -- 'spot' | 'sample' | 'experiment' | 'micrograph'
    item_id            varchar    NOT NULL,            -- subsystem-native id
    item_userpkey      integer    NOT NULL,            -- subsystem-native owner; may differ from project owner for samples

    -- Host project context (denormalized for index-only ACL + facet filters)-
    project_id         varchar    NOT NULL,
    project_userpkey   integer    NOT NULL,
    project_subsystem  varchar    NOT NULL,            -- 'field' | 'micro' | 'exp' | 'samples'
    project_name       text,
    project_ispublic   boolean    NOT NULL,

    -- Universal-core facets (apply to all rows) ------------------------------
    location           geometry(Point, 4326),
    date_value         date,
    searchtext_tsv     tsvector,                       -- combined item + project text for U1

    -- Sample-spine facets (NULL on item_type='spot' for non-sample spots) ----
    sample_id              varchar,
    sample_name            text,
    igsn                   varchar,
    display_sample_type    varchar,
    display_sample_purpose varchar,

    -- Has-data flags (item-level booleans for §6 Q4b Image-pathway inheritance)
    has_orientation    boolean    NOT NULL DEFAULT false,
    has_samples        boolean    NOT NULL DEFAULT false,
    has_images         boolean    NOT NULL DEFAULT false,
    has_microstructure boolean    NOT NULL DEFAULT false,
    has_strat          boolean    NOT NULL DEFAULT false,

    -- Field extensions (NULL for non-Field rows) -----------------------------
    -- Arrays: a single spot can carry many measurements.
    orientation_strike   numeric[],
    orientation_dip      numeric[],
    orientation_trend    numeric[],
    orientation_plunge   numeric[],
    orientation_features text[],                       -- feature_type values
    orientation_planar   boolean[],                    -- planar(t) / linear(f) per measurement
    rock_types           text[],                       -- colon-delimited paths
    met_facies           text[],
    trace_types          text[],

    -- Micro extensions (NULL for non-Micro rows) -----------------------------
    minerals             text[],
    mineral_methods      text[],
    instrument_type      varchar,
    detector_type        varchar,

    -- Exp extensions (NULL for non-Exp rows) ---------------------------------
    apparatus_type       varchar,
    daq_sensor_type      varchar,
    measurement_type     varchar,

    -- Sync state -------------------------------------------------------------
    source_modified      timestamptz,
    last_synced          timestamptz NOT NULL DEFAULT now(),

    -- Soft uniqueness across the fan-out: a sample linked to two projects
    -- produces two rows (one per linked project). Drives the §5.3.2
    -- ON CONFLICT upsert in incremental sync.
    CONSTRAINT item_hit_fanout_uq UNIQUE
        (item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem)
);

ALTER TABLE strabosearch.item_hit OWNER TO strabodbuser;
