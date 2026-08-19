-- StraboTools server-side storage — PostgreSQL objects.
--
-- StraboTools analyses live in their own id space, fully separate from
-- StraboField (:Image / image_seq / dbimages). Metadata lives in the
-- strabotools_analyses table below (analyses are flat per-user records
-- with no relationships, so they need no graph storage); image files
-- live under /srv/app/www/straboToolsImages/ (gitignored; the directory
-- must exist and be writable by Apache), named by strabotools_image_seq.
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

-- One row per analysis, keyed by the client record UUID per user
-- (the same UUID uploaded by two users is two independent records).
-- id is text, NOT uuid: the uuid type would normalize to lowercase,
-- but iOS UUID().uuidString is uppercase and the client was built
-- against verbatim echo + byte-exact lookup (the Neo4j behavior).
-- Queryable scalars are projected out of the client payload; the full
-- payload (including arrays like attitudeRowMajor) is kept losslessly
-- in the analysis jsonb column. Timestamps are ms-since-epoch to match
-- the client contract.
CREATE TABLE IF NOT EXISTS strabotools_analyses (
	id                      text NOT NULL,
	userpkey                integer NOT NULL,
	created_by              integer NOT NULL,
	filename                text NOT NULL,
	origfilename            text,
	imagesha1               text,
	created_timestamp       bigint NOT NULL,
	modified_timestamp      bigint NOT NULL,
	spot_name               text,
	notes                   text,
	capture_date            text,
	saved_date              text,
	app_version             text,
	device_model            text,
	schema_version          double precision,
	azimuth_offset_degrees  double precision,
	attitude_gap_seconds    double precision,
	latitude                double precision,
	longitude               double precision,
	horizontal_error_meters double precision,
	vertical_error_meters   double precision,
	elevation_meters        double precision,
	fabric_azimuth_degrees  double precision,
	fabric_rake_degrees     double precision,
	axial_ratio             double precision,
	trend_degrees           double precision,
	plunge_degrees          double precision,
	color_index_percent     double precision,
	mode_phase_count        double precision,
	color_index_adaptive    boolean,
	analysis                jsonb NOT NULL,
	PRIMARY KEY (id, userpkey)
);

CREATE INDEX IF NOT EXISTS strabotools_analyses_user_modified_idx
	ON strabotools_analyses (userpkey, modified_timestamp DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON strabotools_analyses TO strabodbuser;
