-- File: samplesdb/migration/micro_files_dirty.sql
-- Description: Adds the files_dirty boolean to micro_projectmetadata.
--              Set to TRUE whenever a Samples-app spine edit affects a
--              sample linked to the Micro project; cleared when a static
--              file reader lazily regenerates the on-disk
--              straboMicroFiles/<id>/project.json (+ the project.json entry
--              inside project.zip, + the straboMicroView/smzFiles/<id>/
--              project.json mirror) with the spine overlay applied
--              (micro_regenerate_files_if_dirty in microdb/lib/sample_overlay.php).
--
--              Sibling to pdf_dirty: pdf_dirty gates the server-PDF regen,
--              files_dirty gates the static project.json/zip regen. Separate
--              flags so the two lazy-regen lifecycles don't race to clear a
--              shared flag (a PDF download must not clear the JSON's dirty
--              state, and vice versa).
--
--              The static readers that depend on this (jwtmicrodb
--              getProjectURL / getSharedURL return a static
--              /straboMicroFiles/<id>/project.zip URL; straboMicroView/view.php
--              serves a static ./smzFiles/<id>/project.json) have no per-file
--              PHP serving hook, so the overlay can't be applied at read time
--              the way the streamed/rendered surfaces do it — it has to be
--              baked into the on-disk file when the edit happens (lazily, on
--              the next access after the flag flips).
--
--              Default FALSE; no backfill — the flag flips precisely when a
--              spine edit diverges the linked sample from the upload.
--
-- Rollback:
--    ALTER TABLE micro_projectmetadata DROP COLUMN files_dirty;
--
-- Usage (dev — strabodbuser does NOT own micro_projectmetadata, so the
-- ALTER must be run as the postgres superuser; matches micro_pdf_dirty.sql):
--    cat samplesdb/migration/micro_files_dirty.sql \
--      | docker exec -i strabo-postgres psql -U postgres -d strabospot
--
-- Usage (prod, per the Phase 1 runbook): apply during the cutover window,
-- also as -U postgres, alongside micro_pdf_dirty.sql.

BEGIN;

ALTER TABLE micro_projectmetadata
    ADD COLUMN IF NOT EXISTS files_dirty BOOLEAN NOT NULL DEFAULT FALSE;

-- Partial index — files_dirty is read on every static-file access to gate
-- regeneration. Most rows are FALSE so a partial index keeps the gate cheap.
CREATE INDEX IF NOT EXISTS micro_projectmetadata_files_dirty_true
    ON micro_projectmetadata (files_dirty)
    WHERE files_dirty = TRUE;

COMMIT;
