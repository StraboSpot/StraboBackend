-- StraboTools server-side storage — PostgreSQL objects.
--
-- StraboTools analyses live in their own id space, fully separate from
-- StraboField (:Image / image_seq / dbimages). This sequence names the
-- files stored under /srv/app/www/straboToolsImages/ (gitignored; the
-- directory must exist and be writable by Apache).
--
-- Apply as the postgres superuser (like the samples DDL):
--   docker exec strabo-php psql -h strabo-postgres -U postgres -d strabospot -f /srv/app/www/strabotools/schema/strabotools.sql
--
-- Prod deploy checklist:
--   1. mkdir straboToolsImages/ at the web root, owned/writable by Apache
--   2. apply this file as postgres
--   3. pull the branch

CREATE SEQUENCE IF NOT EXISTS strabotools_image_seq;

GRANT USAGE, SELECT ON SEQUENCE strabotools_image_seq TO strabodbuser;
