-- StraboSearch Phase 0 follow-up: rebuild shapegeology.the_geom from the
-- surviving `poly` coordinate text.
--
-- Background: this PostgreSQL instance originally ran without PostGIS, so
-- the geometry column of the imported USGS WEP province shapefile was lost
-- — the_geom survives only as an all-NULL varchar stub. The raw outlines
-- survive in `poly` (text): single closed lon/lat rings, verified clean on
-- all 980 non-null rows (no multi-part separators; 1 self-intersection,
-- repaired below via ST_MakeValid). 43 rows have no poly text and stay
-- NULL — those provinces cannot match a tectonicProvince search until the
-- source shapefile is re-imported.
--
-- SRID NOTE: spot.location carries SRID 0 (not 4326), and the fullsearch
-- tectonicProvince constraint runs ST_Contains(the_geom, spot.location),
-- which errors on mixed SRIDs. the_geom is therefore built with SRID 0 to
-- match the incumbent convention. The coordinates are lon/lat (WGS84)
-- either way; the StraboSearch Phase 2 schema should adopt proper SRID
-- 4326 columns in its own tables.
--
-- Run as the postgres superuser (the table is postgres-owned):
--   dev:  docker exec -i strabo-postgres psql -U postgres -d strabospot \
--           < searchdb/shapegeology/repair_the_geom.sql
--   prod: psql -U postgres -d strabospot -f repair_the_geom.sql
--
-- Idempotent: re-running drops and rebuilds the column from poly.
-- Verify afterwards with searchdb/shapegeology/verify_the_geom.php.

\set ON_ERROR_STOP on

BEGIN;

-- Precondition: the poly source data must be present.
DO $$
DECLARE n integer;
BEGIN
	SELECT count(poly) INTO n FROM shapegeology;
	IF n < 900 THEN
		RAISE EXCEPTION 'shapegeology.poly looks wrong (only % non-null rows; expected ~980) - aborting', n;
	END IF;
END $$;

ALTER TABLE shapegeology DROP COLUMN IF EXISTS the_geom;
ALTER TABLE shapegeology ADD COLUMN the_geom geometry(MultiPolygon, 0);

-- ST_MakeValid repairs the one self-intersecting ring; CollectionExtract(3)
-- keeps only polygonal parts of whatever MakeValid returns; ST_Multi
-- normalizes everything to MultiPolygon for the typed column.
UPDATE shapegeology
   SET the_geom = ST_Multi(ST_CollectionExtract(
                    ST_MakeValid(ST_GeomFromText('POLYGON((' || poly || '))', 0)), 3))
 WHERE poly IS NOT NULL;

CREATE INDEX IF NOT EXISTS shapegeology_the_geom_gist
    ON shapegeology USING gist (the_geom);

COMMIT;

ANALYZE shapegeology;

-- Inline report (expected: total 1023 | with_geom 980 | invalid 0)
SELECT count(*)        AS total,
       count(the_geom) AS with_geom,
       count(*) FILTER (WHERE the_geom IS NOT NULL AND NOT ST_IsValid(the_geom)) AS invalid
  FROM shapegeology;
