<?php
/**
 * File: samplesdb/lib/field_geom.php
 * Description: Canonical extractor for a Field-linked sample's lat/lng
 *              from the holding Neo4j Spot's flattened wkt property.
 *
 *              Three callers MUST share this function so the live sync,
 *              the migration tooling, and the backfill all produce
 *              identical output for identical input:
 *
 *                1. db/lib/sample_sync.php                    (live sync)
 *                2. samplesdb/migration/extract_field.php     (cutover migration)
 *                3. samplesdb/backfill/backfill_field_geom.php (one-shot dev backfill)
 *
 *              History (2026-06-05): both copies of the helper had
 *              originally looked for a `geometry` JSON property that
 *              doesn't exist on Spot nodes — Neo4j stores geometry as a
 *              WKT string at `wkt`. That bug left 0/49,747 Field-linked
 *              samples with lat/lng on dev. Pulled here to one place so
 *              the regex + range guard never drift again.
 *
 *              Rule:
 *                - Read `wkt` from spot properties (object or array form).
 *                - Match POINT (lng lat); reject anything else (non-Point
 *                  geometry, malformed, unparsable, etc.).
 *                - Reject coordinates outside geographic bounds
 *                  (lat ∉ [-90,90] or lng ∉ [-180,180]) — defends against
 *                  pixel coords from image-basemap spots whose
 *                  fixIncomingBasemaps conversion was lost, including
 *                  the ~169 dev spots that lack the image_basemap tag
 *                  but still carry pixel-range wkt.
 *                - On any rejection, return (null, null) — same as today's
 *                  silent-fail behavior, just much higher hit rate.
 *
 *              WKT order is `POINT (lng lat)`. Returned tuple is
 *              `array(lat, lng)` to match the strabosamples.samples
 *              column order (latitude, longitude).
 *
 * @package    StraboSpot Web Site / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (!function_exists('samples_field_extract_geom_from_spot')) {

    /**
     * @param object|array $spotProps  Neo4j Spot's flattened property map
     *                                  (object from `$rec->get('s')->values()`
     *                                  or array form).
     * @return array{0:?float,1:?float} (lat, lng) — both null when the spot
     *                                  has no usable real-world Point.
     */
    function samples_field_extract_geom_from_spot($spotProps)
    {
        $wkt = null;
        if (is_object($spotProps) && isset($spotProps->wkt)) {
            $wkt = $spotProps->wkt;
        } elseif (is_array($spotProps) && isset($spotProps['wkt'])) {
            $wkt = $spotProps['wkt'];
        }
        if (!is_string($wkt) || $wkt === '') {
            return array(null, null);
        }
        if (!preg_match('/^POINT\s*\(\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\)\s*$/i', $wkt, $m)) {
            return array(null, null);  // non-Point, malformed, or unparsable
        }
        $lng = (float)$m[1];
        $lat = (float)$m[2];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return array(null, null);  // pixel coords or otherwise out of geographic bounds
        }
        return array($lat, $lng);
    }

}
