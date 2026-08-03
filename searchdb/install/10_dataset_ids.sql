-- StraboSearch Phase 3 — dataset_ids amendment.
--
-- The §5.4.1 response contract requires match_counts.dataset ("Field
-- datasets the spot matches fall into") and the §6.5.2 triptych band shows
-- a per-subsystem dataset rollup for Field — but item_hit carried no
-- dataset context (the §5.1.3 indicative DDL omitted it and Phase 2
-- shipped without it). Query-time derivation is impossible: dataset
-- membership lives only in Neo4j.
--
--   dataset_ids  TEXT[]  Field spot rows only: ids of the Dataset nodes
--                        that reach this spot within the host project.
--                        Normally one element; duplicate-edge / dup-node
--                        cases union. NULL on sample / experiment /
--                        micrograph rows (their subsystems have no dataset
--                        rollup in the §6.5.2 band).
--
-- No index: the column is only aggregated (COUNT(DISTINCT unnest)) inside
-- an already-filtered result set, never used as a filter predicate.
--
-- Idempotent — safe to re-run in the 0?_*.sql install pipe.

ALTER TABLE strabosearch.item_hit
    ADD COLUMN IF NOT EXISTS dataset_ids text[];
