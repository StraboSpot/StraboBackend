-- File: samplesdb/migration/micro_pdf_dirty.sql
-- Description: Adds the pdf_dirty boolean to micro_projectmetadata.
--              Set to TRUE whenever a Samples-app spine edit affects a
--              sample linked to the Micro project; cleared when the
--              Micro download paths lazily regenerate the server-rendered
--              PDF (microdb/lib/MicroProjectPDF.php).
--
--              Default FALSE so existing projects continue to serve the
--              desktop client's uploaded project.pdf unchanged until a
--              Samples-app spine edit actually mutates the linked sample.
--              Backfill is intentionally NOT applied — the only time we
--              need a fresh server PDF is when the underlying spine has
--              actually diverged from the upload, and the dirty flag
--              flips precisely on that event.
--
-- Rollback:
--    ALTER TABLE micro_projectmetadata DROP COLUMN pdf_dirty;
--
-- Usage (dev — strabodbuser does NOT own micro_projectmetadata, so the
-- ALTER must be run as the postgres superuser; matches the gotcha
-- recorded for straboexp.sample.strabo_id in
-- project_exp_ingestion_strabo_id_pending):
--    cat samplesdb/migration/micro_pdf_dirty.sql \
--      | docker exec -i strabo-postgres psql -U postgres -d strabospot
--
-- Usage (prod, per the Phase 1 runbook): apply during the cutover window,
-- also as -U postgres.

BEGIN;

ALTER TABLE micro_projectmetadata
    ADD COLUMN IF NOT EXISTS pdf_dirty BOOLEAN NOT NULL DEFAULT FALSE;

-- Optional index — pdf_dirty is read on every Micro download to gate
-- regeneration. Most rows will be FALSE so a partial index keeps the
-- lookup cheap when a regen is genuinely needed.
CREATE INDEX IF NOT EXISTS micro_projectmetadata_pdf_dirty_true
    ON micro_projectmetadata (pdf_dirty)
    WHERE pdf_dirty = TRUE;

COMMIT;
