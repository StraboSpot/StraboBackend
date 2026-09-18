-- Template Wizard: exact-file duplicate-upload guard (2026-09-18).
-- Adds the uploaded file's sha256 + client name to the import journal so the
-- review step can say "this exact file was already imported into this dataset
-- on <date> (run #N)". Idempotent. Apply as postgres (table owner) BEFORE
-- pulling the code that writes the columns:
--   cat TemplateWizard/schema/templatewizard_2026-09-18_file_hash.sql | docker exec -i strabo-postgres psql -U postgres -d strabospot
ALTER TABLE field_tabular_runs ADD COLUMN IF NOT EXISTS file_sha256 char(64);
ALTER TABLE field_tabular_runs ADD COLUMN IF NOT EXISTS file_name   varchar(255);
CREATE INDEX IF NOT EXISTS field_tabular_runs_file_idx
    ON field_tabular_runs (userpkey, file_sha256);
