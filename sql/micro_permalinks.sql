-- micro_permalinks: upload-stable public landing-page slugs for StraboMicro
-- projects (2026-08-20).
--
-- Every StraboMicro upload DELETES the old micro_projectmetadata row and
-- inserts a fresh one with a new serial id, so /microview/?p=<id> links die
-- on re-upload. This table maps a short random slug to the project's stable
-- identity (strabo_id, userpkey); resolution to the CURRENT metadata row
-- happens at request time, so permalinks survive re-uploads (and even a
-- delete followed by a later re-upload of the same project).
--
-- Rows are minted lazily (get-or-create) by microdb/lib/permalink.php when
-- a landing link is rendered or visited. No backfill required.
--
-- Deploy: apply BEFORE pulling the code that references it. Must run as
-- -U postgres (strabodbuser cannot create tables in the strabomicro
-- schema), and ownership must land on strabodbuser afterwards:
--   docker exec -i strabo-postgres psql -U postgres -d strabospot < sql/micro_permalinks.sql
-- (Applied to dev 2026-08-20.)

CREATE TABLE strabomicro.micro_permalinks (
    permakey  varchar(20)       NOT NULL PRIMARY KEY,
    strabo_id character varying NOT NULL,
    userpkey  integer           NOT NULL,
    created   timestamp         NOT NULL DEFAULT now(),
    CONSTRAINT micro_permalinks_identity UNIQUE (strabo_id, userpkey)
);

ALTER TABLE strabomicro.micro_permalinks OWNER TO strabodbuser;
