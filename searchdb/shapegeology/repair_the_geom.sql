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

-- Pass 2 — antimeridian (±180°) crossers. A ring whose vertices jump from
-- ~+179° to ~−179° is read by the planar build above as wrapping the LONG
-- way around the globe: gid 985 (Aleutian Arc) came out as a 50–62°N band
-- circling the whole planet, so a tectonic-province search for it matched
-- spots in Germany (found 2026-08-04 during StraboSearch soft launch).
-- A true crosser becomes compact (< 180° lon span) once shifted into the
-- 0–360 frame; that is the detection criterion. Polar caps (gid 1013,
-- Polar Province) stay world-spanning in either frame and are correctly
-- left as the planar band build.
-- Rebuild: shift → make valid in the continuous frame → split at the 180°
-- meridian → translate the far-side parts back into −180..180.
WITH cand AS (
    SELECT gid,
           ST_MakeValid(ST_ShiftLongitude(
               ST_GeomFromText('POLYGON((' || poly || '))', 0))) AS shifted
      FROM shapegeology
     WHERE poly IS NOT NULL
       AND the_geom IS NOT NULL
       AND ST_XMax(the_geom) - ST_XMin(the_geom) > 180
),
fixable AS (
    SELECT gid, shifted
      FROM cand
     WHERE ST_XMax(shifted) - ST_XMin(shifted) < 180
),
parts AS (
    SELECT gid,
           (ST_Dump(ST_Split(shifted,
                ST_SetSRID(ST_MakeLine(ST_MakePoint(180, -90),
                                       ST_MakePoint(180,  90)), 0)))).geom AS part
      FROM fixable
),
rebuilt AS (
    SELECT gid,
           ST_Multi(ST_CollectionExtract(ST_Collect(
               CASE WHEN ST_X(ST_Centroid(part)) > 180
                    THEN ST_Translate(part, -360, 0)
                    ELSE part END), 3)) AS geom
      FROM parts
     GROUP BY gid
)
UPDATE shapegeology sg
   SET the_geom = r.geom
  FROM rebuilt r
 WHERE sg.gid = r.gid;

CREATE INDEX IF NOT EXISTS shapegeology_the_geom_gist
    ON shapegeology USING gist (the_geom);

COMMIT;

ANALYZE shapegeology;

-- Inline report (expected: total 1023 | with_geom 980 | invalid 0 |
-- wide_parts 1). wide_parts counts provinces with a SINGLE polygon part
-- spanning > 180° of longitude — the per-part measure matters because a
-- correctly split antimeridian crosser still has a −180..180 bounding
-- box. The one legitimate wide part is the Polar Province cap; anything
-- more is an unrepaired crosser whose interior wraps the globe.
SELECT count(*)        AS total,
       count(the_geom) AS with_geom,
       count(*) FILTER (WHERE the_geom IS NOT NULL AND NOT ST_IsValid(the_geom)) AS invalid,
       (SELECT count(DISTINCT gid)
          FROM (SELECT gid, (ST_Dump(the_geom)).geom AS g
                  FROM shapegeology WHERE the_geom IS NOT NULL) d
         WHERE ST_XMax(g) - ST_XMin(g) > 180) AS wide_parts
  FROM shapegeology;
