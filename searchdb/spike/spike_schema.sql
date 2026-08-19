-- StraboSearch Phase 0.3 engine-evaluation spike — SCRATCH schema.
--
-- A throwaway denormalized search index over Field data, shaped like the
-- likely Phase 2 design, used only to measure PG-native query latency on
-- prod-scale data (1.67M spots / ~740k orientation elements / 180k rock
-- type rows). NOT production DDL. Remove with spike_teardown.sql.
--
-- Apply as superuser (dev convention per the samples runbook):
--   docker exec -i strabo-postgres psql -U postgres -d strabospot < searchdb/spike/spike_schema.sql
--
-- Source caveat: the spike loads from the PG mirror for build speed. The
-- census disqualified the mirror as the REAL index source (193k-spot drift
-- vs Neo4j) — fine here, since the spike measures query shapes, not data
-- correctness. The real Phase 2 backfill extracts from Neo4j.

DROP SCHEMA IF EXISTS strabosearch_spike CASCADE;
CREATE SCHEMA strabosearch_spike;

CREATE TABLE strabosearch_spike.spots (
	spot_pkey      integer PRIMARY KEY,   -- mirror pkey kept for traceability
	strabo_spot_id varchar(20),
	userpkey       integer NOT NULL,
	project_pkey   integer,
	ispublic       boolean NOT NULL DEFAULT false,
	name           text,
	date_created   date,
	location       geometry,
	keywords       tsvector,
	has_orientation boolean NOT NULL DEFAULT false,
	has_samples     boolean NOT NULL DEFAULT false,
	has_images      boolean NOT NULL DEFAULT false
);

-- One row per orientation measurement element. Access-control columns are
-- denormalized so single-table range scans need no join.
CREATE TABLE strabosearch_spike.orientations (
	pkey         serial PRIMARY KEY,
	spot_pkey    integer NOT NULL,
	userpkey     integer NOT NULL,
	ispublic     boolean NOT NULL DEFAULT false,
	otype        text,            -- planar_orientation / linear_orientation / tabular_orientation
	feature_type text,
	strike       double precision,
	dip          double precision,
	trend        double precision,
	plunge       double precision,
	quality      text
);

CREATE TABLE strabosearch_spike.rock_types (
	pkey               serial PRIMARY KEY,
	spot_pkey          integer NOT NULL,
	userpkey           integer NOT NULL,
	ispublic           boolean NOT NULL DEFAULT false,
	rock_type          text,
	metamorphic_facies text
);

GRANT USAGE ON SCHEMA strabosearch_spike TO strabodbuser;
GRANT ALL ON ALL TABLES IN SCHEMA strabosearch_spike TO strabodbuser;
GRANT ALL ON ALL SEQUENCES IN SCHEMA strabosearch_spike TO strabodbuser;

-- Ownership to the app user so build_spike.php can CREATE INDEX post-load.
-- Table GRANTs alone are not enough: CREATE INDEX needs table ownership AND
-- CREATE on the schema (an index is a new schema object).
ALTER SCHEMA strabosearch_spike OWNER TO strabodbuser;
ALTER TABLE strabosearch_spike.spots OWNER TO strabodbuser;
ALTER TABLE strabosearch_spike.orientations OWNER TO strabodbuser;
ALTER TABLE strabosearch_spike.rock_types OWNER TO strabodbuser;

-- Indexes are created AFTER load by build_spike.php (faster bulk insert).
