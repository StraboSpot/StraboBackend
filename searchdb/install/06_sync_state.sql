-- StraboSearch Phase 2 — sync_state table.
--
-- Per §5.1.4 + §5.6.3 of DESIGN_PROPOSAL.md.
--
-- One row per source subsystem; tracks the bootstrap timestamps and the
-- most recent incremental sync results. §5.6.3's sync-lag query reads
-- straight off this table.

CREATE TABLE IF NOT EXISTS strabosearch.sync_state (
    source                  varchar     PRIMARY KEY,    -- 'field' | 'micro' | 'exp' | 'samples'
    last_full_backfill      timestamptz,
    last_incremental_sync   timestamptz,
    last_sync_rows_added    integer,
    last_sync_rows_updated  integer,
    last_sync_rows_removed  integer
);
ALTER TABLE strabosearch.sync_state OWNER TO strabodbuser;

-- Seed the four source rows so the §5.6.3 lag query always has all four
-- subsystems in its result set (NULL timestamps until each extractor runs).
INSERT INTO strabosearch.sync_state (source) VALUES
    ('field'), ('micro'), ('exp'), ('samples')
ON CONFLICT (source) DO NOTHING;
