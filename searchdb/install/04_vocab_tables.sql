-- StraboSearch Phase 2 — vocab + facet-count tables.
--
-- Per §5.1.4 of DESIGN_PROPOSAL.md.
--
-- Three derived tables, populated by the extractor sub-branches:
--   vocab_image_type   — unified image-type vocabulary (§4.5)
--   vocab_rock_type    — materialized rock-type hierarchy (§4.5, F7 source)
--   vocab_facet_counts — pre-aggregated facet counts for the empty-search
--                        initial state (§4.3 dropdown count hints)
--
-- All three are safe to TRUNCATE + rebuild — no row in these tables is the
-- source of truth for any user data.

CREATE TABLE IF NOT EXISTS strabosearch.vocab_image_type (
    unified_value   varchar    NOT NULL,             -- the §4.5 mapped value
    normalized_from varchar    NOT NULL,             -- raw source value (key for upsert)
    subsystem       varchar    NOT NULL,             -- 'field' | 'micro'
    PRIMARY KEY (subsystem, normalized_from)
);
ALTER TABLE strabosearch.vocab_image_type OWNER TO strabodbuser;

CREATE TABLE IF NOT EXISTS strabosearch.vocab_rock_type (
    path        varchar    PRIMARY KEY,              -- colon-delimited (e.g. 'igneous:plutonic:granite')
    parent_path varchar,
    depth       smallint   NOT NULL
);
ALTER TABLE strabosearch.vocab_rock_type OWNER TO strabodbuser;

-- One row per (criterion_id, value) tuple. Refreshed by the extractor and
-- by the §5.4.3 query-time facet recount; this materialized copy serves the
-- empty-search initial-state counts without scanning item_hit.
CREATE TABLE IF NOT EXISTS strabosearch.vocab_facet_counts (
    criterion_id varchar    NOT NULL,
    value        varchar    NOT NULL,
    count        integer    NOT NULL,
    PRIMARY KEY (criterion_id, value)
);
ALTER TABLE strabosearch.vocab_facet_counts OWNER TO strabodbuser;
