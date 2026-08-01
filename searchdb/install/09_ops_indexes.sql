-- StraboSearch — operational slice indexes (verify_extended + sync sweeps).
--
-- Shipped with the §5.6.1 verifier branch. Before this file, the only
-- btree access paths on the hit tables were the identity uniques
-- (item_hit: leading item_type/item_id; image_hit: leading
-- image_subsystem/image_id) — every PROJECT-slice or per-user query was a
-- full sequential scan:
--
--   * StraboSearchSync::removeFieldProject / removeMicroProject /
--     removeExpProject  (DELETE ... WHERE project_subsystem + project_id
--     + project_userpkey)
--   * StraboSearchSync::touchSpot's image stale-sweep
--     (DELETE ... WHERE image_subsystem='field' AND parent_spot_id + upk)
--   * every verify_extended.php reconciliation pull (per-user and
--     per-project slices of both tables)
--
-- All three are plain additive CREATE INDEX IF NOT EXISTS — idempotent,
-- runnable as strabodbuser via install.php (the schema is ours once 01
-- has run), no table rewrites.

CREATE INDEX IF NOT EXISTS item_hit_project_slice_idx
    ON strabosearch.item_hit (project_subsystem, project_userpkey, project_id, item_type);

CREATE INDEX IF NOT EXISTS image_hit_project_slice_idx
    ON strabosearch.image_hit (image_subsystem, project_userpkey, project_id);

CREATE INDEX IF NOT EXISTS image_hit_parent_spot_idx
    ON strabosearch.image_hit (image_subsystem, parent_spot_id, project_userpkey);

ANALYZE strabosearch.item_hit;
ANALYZE strabosearch.image_hit;
