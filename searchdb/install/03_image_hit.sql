-- StraboSearch Phase 2 — `strabosearch.image_hit` table.
--
-- One row per servable, reachable image. Per the 0.4 image-results audit:
--   Field: `:Image` nodes reachable via (Spot)-[:HAS_IMAGE]-> (≈520k)
--   Micro: composite micrographs already on disk (4,894)
--   Exp:   excluded (72.8% have no path to a public flag)
--
-- Inherits universal-core columns (location, date_value) and immediate-parent
-- subsystem-extension columns at extraction time, per the §6 Q4 hybrid
-- ("yes to BOTH universal-core AND subsystem-extension inheritance for the
-- Image pathway"). This is what allows "thin section AND dip > 60" image
-- queries.
--
-- Indexes deferred to the extractor sub-branches per §5.1.5.

CREATE TABLE IF NOT EXISTS strabosearch.image_hit (
    image_hit_pkey     bigserial PRIMARY KEY,

    -- Identity ---------------------------------------------------------------
    image_id           varchar    NOT NULL,            -- subsystem-native id
    image_subsystem    varchar    NOT NULL,            -- 'field' | 'micro'
    image_userpkey     integer    NOT NULL,

    -- Image-specific facets --------------------------------------------------
    image_type         varchar,                        -- normalized via vocab_image_type
    annotated          boolean,
    title              text,
    caption            text,
    imagetext_tsv      tsvector,                       -- title + caption for I3
    filename           varchar,

    -- Parent context (denormalized at extraction for §6 Q4a + Q4b inheritance)
    parent_spot_id     varchar,
    parent_sample_id   varchar,
    project_id         varchar    NOT NULL,
    project_userpkey   integer    NOT NULL,
    project_subsystem  varchar    NOT NULL,
    project_ispublic   boolean    NOT NULL,

    -- Inherited universal-core (§6 Q4a) --------------------------------------
    location           geometry(Point, 4326),
    date_value         date,

    -- Inherited Field-parent extensions (NULL for Micro images) --------------
    orientation_strike   numeric[],
    orientation_dip      numeric[],
    orientation_trend    numeric[],
    orientation_plunge   numeric[],
    orientation_features text[],
    orientation_planar   boolean[],
    rock_types           text[],
    met_facies           text[],
    trace_types          text[],

    -- Native Micro micrograph columns (NULL for Field images) ----------------
    -- For Micro the micrograph IS the image — these are the micrograph's own
    -- columns, not inherited.
    minerals             text[],
    mineral_methods      text[],
    instrument_type      varchar,
    detector_type        varchar,

    -- Sync state -------------------------------------------------------------
    source_modified      timestamptz,
    last_synced          timestamptz NOT NULL DEFAULT now(),

    -- Image identity is unique per (subsystem, id, owner). Drives the
    -- §5.3.2 ON CONFLICT upsert in incremental sync.
    CONSTRAINT image_hit_identity_uq UNIQUE
        (image_subsystem, image_id, image_userpkey)
);

ALTER TABLE strabosearch.image_hit OWNER TO strabodbuser;
