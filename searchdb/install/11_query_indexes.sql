-- StraboSearch Phase 3 — §5.1.5 query-side indexes.
--
-- Phase 2 deferred the criteria-catalog indexes "post-backfill" and shipped
-- only the identity uniques + tag GINs (08) + ops slice indexes (09). This
-- file is the §5.1.5 set as-built, applied with the /searchdb/ API branch —
-- without it every criterion predicate is a 1.7M-row sequential scan.
--
-- ── ONE-TIME PREREQUISITE (superuser) ───────────────────────────────────
-- The U5/U6 prefix path needs pg_trgm. strabodbuser is NOT superuser, so
-- on a fresh box run FIRST, as postgres (prod: sudo docker exec, -U postgres):
--
--     CREATE EXTENSION IF NOT EXISTS pg_trgm;
--
-- The statement is repeated here guarded by a to_regprocedure probe so this
-- file stays runnable as strabodbuser once the extension exists.
-- ─────────────────────────────────────────────────────────────────────────
--
-- Deliberate non-indexes (documented so nobody hunts for them):
--   * F1–F4 orientation numeric ranges: the predicate is per-element range
--     over numeric[] (EXISTS unnest ... BETWEEN), which no GIN opclass
--     serves. Phase 0.3 spike measured pure range scans at 15–520ms warm —
--     acceptable; orientation queries in practice combine with other
--     (indexed) criteria that narrow first. Revisit = materialized junction
--     table, only if Phase 5.D benchmarks demand it.
--   * F6 planar/linear (boolean[] overlap): ~half the rows match either
--     value — an index would never be chosen.
--   * F7 rock-type hierarchical prefix: served by the plain rock_types GIN
--     below via §5.1.5 "strategy (c)": the API expands the user's prefix
--     selection against vocab_rock_type (WHERE path LIKE 'sedimentary%')
--     into the full path list server-side, turning the predicate into an
--     indexable rock_types && ARRAY[...]. No junction table, no trigram
--     column — the vocab table already materializes every observed path.
--   * dataset_ids: aggregated in rollups only, never filtered on (10).
--
-- All additive CREATE INDEX IF NOT EXISTS — idempotent, no table rewrites.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm') THEN
        RAISE EXCEPTION 'pg_trgm extension missing — run CREATE EXTENSION pg_trgm as postgres first (see header)';
    END IF;
END $$;

-- ═══════════════════════════════════════════════════════════════════════
-- item_hit — Projects pathway
-- ═══════════════════════════════════════════════════════════════════════

-- U1 keyword
CREATE INDEX IF NOT EXISTS item_hit_searchtext_gin
    ON strabosearch.item_hit USING gin (searchtext_tsv);

-- U2 location / F10 tectonic province
CREATE INDEX IF NOT EXISTS item_hit_location_gist
    ON strabosearch.item_hit USING gist (location);

-- U3 date range
CREATE INDEX IF NOT EXISTS item_hit_date_idx
    ON strabosearch.item_hit (date_value);

-- §5.5 ACL clause + U4 owner
CREATE INDEX IF NOT EXISTS item_hit_acl_idx
    ON strabosearch.item_hit (project_ispublic, project_userpkey);
CREATE INDEX IF NOT EXISTS item_hit_owner_idx
    ON strabosearch.item_hit (project_userpkey);

-- U8 subsystem filter (+ the U8="Samples" item_type predicate)
CREATE INDEX IF NOT EXISTS item_hit_subsystem_idx
    ON strabosearch.item_hit (project_subsystem);
CREATE INDEX IF NOT EXISTS item_hit_item_type_idx
    ON strabosearch.item_hit (item_type);

-- U5/U6 sample identity + IGSN — trigram for prefix, btree for exact toggle
CREATE INDEX IF NOT EXISTS item_hit_sample_id_trgm
    ON strabosearch.item_hit USING gin (sample_id gin_trgm_ops);
CREATE INDEX IF NOT EXISTS item_hit_sample_name_trgm
    ON strabosearch.item_hit USING gin (sample_name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS item_hit_igsn_trgm
    ON strabosearch.item_hit USING gin (igsn gin_trgm_ops);
CREATE INDEX IF NOT EXISTS item_hit_sample_id_idx
    ON strabosearch.item_hit (sample_id);
CREATE INDEX IF NOT EXISTS item_hit_sample_name_idx
    ON strabosearch.item_hit (sample_name);
CREATE INDEX IF NOT EXISTS item_hit_igsn_idx
    ON strabosearch.item_hit (igsn);

-- U7 unified sample vocab
CREATE INDEX IF NOT EXISTS item_hit_sample_type_idx
    ON strabosearch.item_hit (display_sample_type);
CREATE INDEX IF NOT EXISTS item_hit_sample_purpose_idx
    ON strabosearch.item_hit (display_sample_purpose);

-- U9 has-data flags (partial — only TRUE rows are ever asked for)
CREATE INDEX IF NOT EXISTS item_hit_has_orientation_idx
    ON strabosearch.item_hit (item_hit_pkey) WHERE has_orientation;
CREATE INDEX IF NOT EXISTS item_hit_has_samples_idx
    ON strabosearch.item_hit (item_hit_pkey) WHERE has_samples;
CREATE INDEX IF NOT EXISTS item_hit_has_images_idx
    ON strabosearch.item_hit (item_hit_pkey) WHERE has_images;
CREATE INDEX IF NOT EXISTS item_hit_has_microstructure_idx
    ON strabosearch.item_hit (item_hit_pkey) WHERE has_microstructure;
CREATE INDEX IF NOT EXISTS item_hit_has_strat_idx
    ON strabosearch.item_hit (item_hit_pkey) WHERE has_strat;

-- F5 orientation feature type / F7 rock type (strategy c) / F8 facies /
-- F9 trace types — array-overlap GINs
CREATE INDEX IF NOT EXISTS item_hit_orientation_features_gin
    ON strabosearch.item_hit USING gin (orientation_features);
CREATE INDEX IF NOT EXISTS item_hit_rock_types_gin
    ON strabosearch.item_hit USING gin (rock_types);
CREATE INDEX IF NOT EXISTS item_hit_met_facies_gin
    ON strabosearch.item_hit USING gin (met_facies);
CREATE INDEX IF NOT EXISTS item_hit_trace_types_gin
    ON strabosearch.item_hit USING gin (trace_types);

-- M1 minerals / M2 methods (array GINs), M3/M4 scalars
CREATE INDEX IF NOT EXISTS item_hit_minerals_gin
    ON strabosearch.item_hit USING gin (minerals);
CREATE INDEX IF NOT EXISTS item_hit_mineral_methods_gin
    ON strabosearch.item_hit USING gin (mineral_methods);
CREATE INDEX IF NOT EXISTS item_hit_instrument_type_idx
    ON strabosearch.item_hit (instrument_type);
CREATE INDEX IF NOT EXISTS item_hit_detector_type_idx
    ON strabosearch.item_hit (detector_type);

-- E1–E3 scalars
CREATE INDEX IF NOT EXISTS item_hit_apparatus_type_idx
    ON strabosearch.item_hit (apparatus_type);
CREATE INDEX IF NOT EXISTS item_hit_daq_sensor_type_idx
    ON strabosearch.item_hit (daq_sensor_type);
CREATE INDEX IF NOT EXISTS item_hit_measurement_type_idx
    ON strabosearch.item_hit (measurement_type);

-- ═══════════════════════════════════════════════════════════════════════
-- image_hit — Images pathway
-- ═══════════════════════════════════════════════════════════════════════

-- I3 image text keyword
CREATE INDEX IF NOT EXISTS image_hit_imagetext_gin
    ON strabosearch.image_hit USING gin (imagetext_tsv);

-- Inherited U2 / U3
CREATE INDEX IF NOT EXISTS image_hit_location_gist
    ON strabosearch.image_hit USING gist (location);
CREATE INDEX IF NOT EXISTS image_hit_date_idx
    ON strabosearch.image_hit (date_value);

-- §5.5 ACL clause + U4 owner + U8
CREATE INDEX IF NOT EXISTS image_hit_acl_idx
    ON strabosearch.image_hit (project_ispublic, project_userpkey);
CREATE INDEX IF NOT EXISTS image_hit_owner_idx
    ON strabosearch.image_hit (project_userpkey);
CREATE INDEX IF NOT EXISTS image_hit_subsystem_idx
    ON strabosearch.image_hit (project_subsystem);

-- I1 image type / I2 annotated (partial)
CREATE INDEX IF NOT EXISTS image_hit_image_type_idx
    ON strabosearch.image_hit (image_type);
CREATE INDEX IF NOT EXISTS image_hit_annotated_idx
    ON strabosearch.image_hit (image_hit_pkey) WHERE annotated;

-- U5/U6/U7 route through the parent sample on the Images pathway
CREATE INDEX IF NOT EXISTS image_hit_parent_sample_idx
    ON strabosearch.image_hit (parent_sample_id);

-- Inherited Field-parent + native Micro facet arrays / scalars
CREATE INDEX IF NOT EXISTS image_hit_orientation_features_gin
    ON strabosearch.image_hit USING gin (orientation_features);
CREATE INDEX IF NOT EXISTS image_hit_rock_types_gin
    ON strabosearch.image_hit USING gin (rock_types);
CREATE INDEX IF NOT EXISTS image_hit_met_facies_gin
    ON strabosearch.image_hit USING gin (met_facies);
CREATE INDEX IF NOT EXISTS image_hit_trace_types_gin
    ON strabosearch.image_hit USING gin (trace_types);
CREATE INDEX IF NOT EXISTS image_hit_minerals_gin
    ON strabosearch.image_hit USING gin (minerals);
CREATE INDEX IF NOT EXISTS image_hit_mineral_methods_gin
    ON strabosearch.image_hit USING gin (mineral_methods);
CREATE INDEX IF NOT EXISTS image_hit_instrument_type_idx
    ON strabosearch.image_hit (instrument_type);
CREATE INDEX IF NOT EXISTS image_hit_detector_type_idx
    ON strabosearch.image_hit (detector_type);

ANALYZE strabosearch.item_hit;
ANALYZE strabosearch.image_hit;
