-- StraboSearch Phase 2 — schema bootstrap.
--
-- Creates the `strabosearch` schema and grants on it. Per §5.1 of
-- DESIGN_PROPOSAL.md (Phase 1 design, SIGNED OFF v0.7 2026-06-16).
--
-- install.php applies the spike teardown (searchdb/spike/spike_teardown.sql,
-- §5.7 Q4) before this file, so `strabosearch_spike` is already gone by
-- the time these statements run.
--
-- Idempotent. The connection user owns the schema after first install (so
-- index DDL from the extractor sub-branches can be issued from PHP via the
-- standard app credentials, matching the Phase 0.3 spike pattern).

CREATE SCHEMA IF NOT EXISTS strabosearch;

-- Ownership: hand the schema to strabodbuser so subsequent ALTER TABLE and
-- CREATE INDEX from the extractor sub-branches can run as the app user.
-- Mirrors the Phase 0.3 spike's ALTER SCHEMA OWNER pattern. If the schema
-- was created by postgres (superuser pipe), this assignment transfers
-- control. If created by strabodbuser, this is a no-op.
ALTER SCHEMA strabosearch OWNER TO strabodbuser;

GRANT USAGE  ON SCHEMA strabosearch TO strabodbuser;
GRANT CREATE ON SCHEMA strabosearch TO strabodbuser;
