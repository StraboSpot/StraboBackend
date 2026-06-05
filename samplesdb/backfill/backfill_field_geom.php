<?php
/**
 * File: samplesdb/backfill/backfill_field_geom.php
 * Description: One-shot operational backfill for the strabosamples spine
 *              columns latitude/longitude on Field-linked rows.
 *
 *              The 2026-06-05 click-test of the Edit Metadata modal
 *              surfaced that 0 of 49,747 Field-linked samples on dev
 *              had lat/lng populated, even though both rich and legacy
 *              go through a sync helper that intends to inherit
 *              geometry from the holding Spot. Root cause: the helpers
 *              in db/lib/sample_sync.php and samplesdb/migration/
 *              extract_field.php read a `geometry` JSON property that
 *              doesn't exist on Neo4j Spot nodes — spots flatten
 *              geometry to a `wkt` string. Both helpers now delegate
 *              to samplesdb/lib/field_geom.php::
 *              samples_field_extract_geom_from_spot() which parses the
 *              wkt POINT and applies a defensive geographic-range guard.
 *
 *              This backfill walks every Field-linked sample row, refetches
 *              the holding Spot's wkt from Neo4j, runs the same canonical
 *              helper, and UPDATEs spine lat/lng when both come back non-
 *              null. Idempotent — re-running after --apply makes zero
 *              additional updates because the WHERE clause skips already-
 *              populated rows.
 *
 *              Dev-only. Prod cutover does NOT need this — the fixed
 *              extract_field.php writes correct lat/lng on the first pass
 *              of the cutover migration.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/samplesdb/backfill/backfill_field_geom.php
 *                docker exec strabo-php php /srv/app/www/samplesdb/backfill/backfill_field_geom.php --apply
 *
 * @package    StraboSpot Web Site / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/../../includes/config.inc.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../neodb.php';
require_once __DIR__ . '/../lib/field_geom.php';

$apply = in_array('--apply', $argv ?? array(), true);

function fmt($n) { return number_format($n); }

echo "===== StraboSamples Field-link lat/lng backfill =====\n";
echo "Mode: " . ($apply ? 'APPLY (rows will be UPDATEd)' : 'DRY-RUN (no writes)') . "\n\n";

// ----- Pre-flight: count candidates -----
//
// Candidate set = Field-linked sample rows whose spine lat/lng is currently
// null. (Rows with lat/lng already set are skipped on the apply, but we
// count both buckets here for visibility into the population shape.)

$totalLinks = (int)$db->get_var(
    "SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE subsystem='field'"
);
$candidates = $db->get_results(
    "SELECT s.id, s.userpkey, l.reference_id::bigint AS spot_id, l.reference_userpkey AS spot_userpkey
       FROM strabosamples.samples s
       JOIN strabosamples.sample_subsystem_links l
         ON l.sample_id = s.id
        AND l.sample_userpkey = s.userpkey
        AND l.subsystem = 'field'
      WHERE s.latitude IS NULL
        AND s.longitude IS NULL"
);
$alreadyPopulated = $totalLinks - count($candidates);

echo "Total Field-linked sample rows:        " . fmt($totalLinks) . "\n";
echo "Already populated (lat AND lng):       " . fmt($alreadyPopulated) . "\n";
echo "Candidate rows (lat IS NULL):          " . fmt(count($candidates)) . "\n";

if (count($candidates) === 0) {
    echo "\nNothing to backfill. Exiting.\n";
    exit(0);
}

// ----- Classify each candidate by what wkt extraction yields -----
//
// We bucket each candidate by the helper's verdict so the DRY-RUN report
// matches the empirical audit numbers from PROJECT_STATUS, and so the
// user can spot anomalies (e.g. an unexpected jump in pixel-range rows
// would indicate a new client version is uploading bad wkt).

$bucketWouldFill   = 0;
$bucketNoPoint     = 0;  // non-Point geometry
$bucketNoWkt       = 0;  // spot exists but has empty/no wkt
$bucketOutOfRange  = 0;  // Point but lat/lng outside geographic bounds
$bucketNoSpot      = 0;  // Neo4j has no matching Spot at all

$pending = array();  // rows to actually UPDATE on --apply
$total   = count($candidates);
$ix      = 0;

echo "\nReading wkt from Neo4j for " . fmt($total) . " candidate spots…\n";

foreach ($candidates as $r) {
    $ix++;
    $spotId  = (int)$r->spot_id;
    $spotUpk = (int)$r->spot_userpkey;
    if ($spotId <= 0 || $spotUpk <= 0) {
        $bucketNoSpot++;
        continue;
    }
    $rec = $neodb->getRecord("MATCH (s:Spot {id:$spotId, userpkey:$spotUpk}) RETURN s LIMIT 1");
    if (!$rec) {
        $bucketNoSpot++;
        continue;
    }
    $props = $rec->get('s')->values();
    $wktRaw = is_array($props) && isset($props['wkt']) ? (string)$props['wkt'] : '';

    list($lat, $lng) = samples_field_extract_geom_from_spot($props);
    if ($lat !== null && $lng !== null) {
        $bucketWouldFill++;
        $pending[] = array(
            'id' => (string)$r->id,
            'userpkey' => (int)$r->userpkey,
            'lat' => $lat,
            'lng' => $lng,
        );
        continue;
    }
    // Classify the null outcome to make the dry-run informative.
    if ($wktRaw === '') {
        $bucketNoWkt++;
    } elseif (!preg_match('/^POINT/i', $wktRaw)) {
        $bucketNoPoint++;
    } else {
        $bucketOutOfRange++;
    }

    // Periodic progress so the user sees the script is alive on big runs.
    if ($ix % 5000 === 0) {
        echo "  …processed " . fmt($ix) . " / " . fmt($total) . "\n";
    }
}

echo "\n--- Dry-run classification ---\n";
echo "  Would populate (real-world Point):    " . fmt($bucketWouldFill)  . "\n";
echo "  Non-Point geometry:                   " . fmt($bucketNoPoint)    . "\n";
echo "  Point but out of geographic bounds:   " . fmt($bucketOutOfRange) . "\n";
echo "  No wkt on the holding Spot:           " . fmt($bucketNoWkt)      . "\n";
echo "  No matching Spot in Neo4j:            " . fmt($bucketNoSpot)     . "\n";

if (!$apply) {
    echo "\nDRY-RUN complete. Re-run with --apply to perform the UPDATEs.\n";
    exit(0);
}

if ($bucketWouldFill === 0) {
    echo "\nNo rows to update.\n";
    exit(0);
}

// ----- Apply -----
//
// Single transaction. Per-row UPDATE keyed on (id, userpkey) so we don't
// accidentally touch a same-id row owned by a different user (the
// strabosamples uniqueness is (id, userpkey)). Bumps modified_at but
// preserves modified_by — we don't want this background backfill to
// look like a user edit in the audit log. (It also intentionally does
// NOT write a changelog row — this is mechanical data repair, not a
// semantic edit; cluttering the audit log with 33k+ rows for every
// sample would just make the changelog viewer slower for no benefit.)

echo "\n--- Applying " . fmt($bucketWouldFill) . " UPDATEs ---\n";
$db->query("BEGIN");

$written = 0;
foreach ($pending as $p) {
    $db->prepare_query(
        "UPDATE strabosamples.samples
            SET latitude = $1, longitude = $2, modified_at = now()
          WHERE id = $3 AND userpkey = $4",
        array($p['lat'], $p['lng'], $p['id'], $p['userpkey'])
    );
    $written++;
    if ($written % 5000 === 0) {
        echo "  …wrote " . fmt($written) . " / " . fmt($bucketWouldFill) . "\n";
    }
}

$db->query("COMMIT");

// ----- Verify -----

$nowPopulated = (int)$db->get_var(
    "SELECT count(*)
       FROM strabosamples.samples s
       JOIN strabosamples.sample_subsystem_links l
         ON l.sample_id = s.id AND l.sample_userpkey = s.userpkey AND l.subsystem='field'
      WHERE s.latitude IS NOT NULL AND s.longitude IS NOT NULL"
);

echo "\nDone.\n";
echo "Rows written:                          " . fmt($written)       . "\n";
echo "Total Field-linked rows w/ lat+lng:    " . fmt($nowPopulated)  . " / " . fmt($totalLinks) . "\n";
