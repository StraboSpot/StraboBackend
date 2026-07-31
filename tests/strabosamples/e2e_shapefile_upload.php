<?php
/**
 * File: e2e_shapefile_upload.php
 * Description: Curl-level END-TO-END coverage of the "Load Shapefile" website
 *              uploader (load_shapefile.php), proving that a sample-bearing
 *              shapefile imported through the real two-POST HTTP flow mirrors
 *              its sample sub-objects into strabosamples.* WITH full project/
 *              dataset context — the Phase I bug #2 fix (`bd3489a`) exercised
 *              over HTTP.
 *
 *              Closes the last Phase I gap: smoke_test_shapefile_sample_sync.php
 *              reproduces the ordering hazard by calling field_sample_sync_spot()
 *              directly (0 HTTP) — it never drives load_shapefile.php itself.
 *              This suite builds a real shapefile, uploads it, walks the actual
 *              column-mapping form, and asserts the imported samples land with
 *              non-NULL project_id/dataset_id in their subsystem link.
 *
 *              The endpoint is a two-step form:
 *                POST 1 (multipart): submitfile + zipfile + projectid
 *                       → unzips, finds the .shp, renders the column-mapping
 *                         form with hidden randnum / shapefilename / epsg.
 *                POST 2 (urlencoded): columnsubmit + those hidden fields +
 *                       fixedcolumns (shapecol:strabocol;…) → runs the import
 *                       (the loop that calls setSampleSyncContext + insertSpot
 *                       + addSpotToDataset).
 *
 *              Mapping a shapefile column to sample_sample_id_name /
 *              sample_sample_description makes straboModelClass::hasSampleData()
 *              fire, so each imported spot carries a sample sub-object.
 *
 *              Requires ogr2ogr (GDAL) + zip in the container — both present.
 *              Session-gated (logincheck.php) → forged-session harness.
 *
 *              Hermetic: a unique '96668'-prefixed project id; imported spot/
 *              dataset/sample ids are server-generated, so cleanup walks the
 *              project (Neo4j) and resolves strabosamples rows via the link's
 *              project_id, then removes temp dirs + the forged session.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_shapefile_upload.php
 *
 * @package    StraboField / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

$docRoot = '/srv/app/www';

// -------------------------------------------------------------------------
// Owner + forged session
// -------------------------------------------------------------------------

$ownerPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE",
    array('owner@test.strabospot.org'));
if (!$ownerPkey) { echo "Test user owner@test.strabospot.org not found. Run setup_test_data.php first.\n"; exit(1); }
echo "owner=$ownerPkey\n";

$sessionDir = '/var/lib/php/sessions';
$sessionFiles = array();
function forgeSession($pkey) {
    global $sessionDir, $sessionFiles;
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = $sessionDir . '/sess_' . $sid;
    file_put_contents($path, 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';LAST_ACTIVITY|i:' . time() . ';');
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    $sessionFiles[] = $path;
    return $sid;
}

// -------------------------------------------------------------------------
// Fixtures
// -------------------------------------------------------------------------

$stamp  = time();
$projId = 96668 * 10000000 + ($stamp % 10000000);   // 966680000000 .. 966689999999
$workDir = "/tmp/e2eshp_$stamp";
$zipPath = "$workDir/e2e_shapefile.zip";
$randnum = null;

function shpCleanup($db, $neodb, $projId, $ownerPkey, $randnum, $docRoot) {
    // strabosamples rows imported under this project (resolve via link).
    $rows = $db->get_results_prepared(
        "SELECT DISTINCT sample_id FROM strabosamples.sample_subsystem_links
          WHERE subsystem='field' AND sample_userpkey=$2
            AND reference_metadata->>'project_id' = $1",
        array((string)$projId, (int)$ownerPkey));
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                array($r->sample_id, (int)$ownerPkey));   // cascade clears links/changelog
        }
    }
    // Walk the project and remove all imported graph nodes (ids are server-generated).
    $neodb->query("MATCH (p:Project {id:$projId, userpkey:$ownerPkey})
                   OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset)
                   OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot)
                   OPTIONAL MATCH (s)-[*1..3]->(c)
                   DETACH DELETE c, s, d, p");
    if ($randnum) {
        shell_exec("rm -rf " . escapeshellarg("$docRoot/ziptemp/$randnum") . " " . escapeshellarg("$docRoot/ogrtemp/$randnum"));
    }
}

shpCleanup($db, $neodb, $projId, $ownerPkey, null, $docRoot);

try {
    // ---------------------------------------------------------------------
    // Build a sample-bearing shapefile: GeoJSON → ogr2ogr → zip.
    // Two point features, each with smpname/smpdesc properties.
    // ---------------------------------------------------------------------
    @mkdir("$workDir/shp", 0777, true);
    $geojson = array('type' => 'FeatureCollection', 'features' => array(
        array('type'=>'Feature','properties'=>array('smpname'=>'ShpSampleA','smpdesc'=>'shp sample A desc'),
              'geometry'=>array('type'=>'Point','coordinates'=>array(-105.10, 39.10))),
        array('type'=>'Feature','properties'=>array('smpname'=>'ShpSampleB','smpdesc'=>'shp sample B desc'),
              'geometry'=>array('type'=>'Point','coordinates'=>array(-105.20, 39.20))),
    ));
    file_put_contents("$workDir/in.geojson", json_encode($geojson));
    shell_exec("ogr2ogr -f 'ESRI Shapefile' -a_srs EPSG:4326 " . escapeshellarg("$workDir/shp/e2eshp.shp") . " " . escapeshellarg("$workDir/in.geojson") . " 2>&1");
    $haveShp = file_exists("$workDir/shp/e2eshp.shp") && file_exists("$workDir/shp/e2eshp.dbf") && file_exists("$workDir/shp/e2eshp.prj");
    check("fixture: ogr2ogr produced .shp/.dbf/.prj", $haveShp);
    if (!$haveShp) throw new Exception("shapefile build failed");
    shell_exec("cd " . escapeshellarg("$workDir/shp") . " && zip -q " . escapeshellarg($zipPath) . " e2eshp.shp e2eshp.shx e2eshp.dbf e2eshp.prj");
    check("fixture: zip archive built", file_exists($zipPath));

    // Seed a Neo4j Project owned by the test user (the import links the new
    // dataset under it).
    $neodb->query("CREATE (p:Project {id:$projId, userpkey:$ownerPkey, desc_project_name:'E2E Shapefile Upload'})");

    $sid = forgeSession($ownerPkey);

    // ---------------------------------------------------------------------
    // POST 1 — multipart upload. One .shp → server renders the column form.
    // ---------------------------------------------------------------------
    echo "\n=== POST 1: multipart submitfile → column-mapping form ===\n";
    $cmd1 = "curl -s -b " . escapeshellarg("PHPSESSID=$sid") . " "
          . "-F " . escapeshellarg("submitfile=Submit") . " "
          . "-F " . escapeshellarg("projectid=$projId") . " "
          . "-F " . escapeshellarg("zipfile=@$zipPath;filename=e2e_shapefile.zip;type=application/zip") . " "
          . escapeshellarg("http://localhost/load_shapefile.php");
    $html1 = shell_exec($cmd1);

    // Pull hidden form fields out of the column-mapping form.
    $grab = function($name) use ($html1) {
        if (preg_match('/name="' . preg_quote($name, '/') . '"\s+value\s*="([^"]*)"/', (string)$html1, $m)) return $m[1];
        return null;
    };
    $randnum       = $grab('randnum');
    $shapefilename = $grab('shapefilename');
    $epsg          = $grab('epsg');
    note("randnum=$randnum shapefilename=$shapefilename epsg=$epsg");
    check("POST1: returned the column-mapping form (has randnum)",       !empty($randnum));
    check("POST1: form carries the unzipped .shp server path",            !empty($shapefilename) && substr($shapefilename, -4) === '.shp');
    check("POST1: projection resolved to EPSG (non-empty)",               !empty($epsg));

    // Discover the actual shapefile column names ogr2ogr produced (the form
    // emits `var columns = ["…","…"];`), then map the sample-bearing ones.
    $cols = array();
    if (preg_match('/var columns = \[(.*?)\];/s', (string)$html1, $m)) {
        foreach (explode(',', $m[1]) as $c) { $c = trim($c, " \t\"'"); if ($c !== '') $cols[] = $c; }
    }
    note("shapefile columns: " . implode(', ', $cols));
    $colName = null; $colDesc = null;
    foreach ($cols as $c) {
        if (strcasecmp($c, 'smpname') === 0) $colName = $c;
        if (strcasecmp($c, 'smpdesc') === 0) $colDesc = $c;
    }
    check("POST1: sample-name + sample-desc columns present in the form",  $colName !== null && $colDesc !== null);

    // ---------------------------------------------------------------------
    // POST 2 — column mapping → import. Map the two columns to sample fields.
    // ---------------------------------------------------------------------
    echo "\n=== POST 2: columnsubmit → import ===\n";
    $fixedcolumns = "$colName:sample_sample_id_name;$colDesc:sample_sample_description";
    note("fixedcolumns=$fixedcolumns");
    $cmd2 = "curl -s -b " . escapeshellarg("PHPSESSID=$sid") . " "
          . "--data-urlencode " . escapeshellarg("columnsubmit=Submit") . " "
          . "--data-urlencode " . escapeshellarg("shapefilename=$shapefilename") . " "
          . "--data-urlencode " . escapeshellarg("randnum=$randnum") . " "
          . "--data-urlencode " . escapeshellarg("projectid=$projId") . " "
          . "--data-urlencode " . escapeshellarg("epsg=$epsg") . " "
          . "--data-urlencode " . escapeshellarg("datasetname=E2E Shp DS") . " "
          . "--data-urlencode " . escapeshellarg("spotnameprefix=shp") . " "
          . "--data-urlencode " . escapeshellarg("featuretype=") . " "
          . "--data-urlencode " . escapeshellarg("filecolumns=") . " "
          . "--data-urlencode " . escapeshellarg("shapefilearray=") . " "
          . "--data-urlencode " . escapeshellarg("fixedcolumns=$fixedcolumns") . " "
          . escapeshellarg("http://localhost/load_shapefile.php");
    $html2 = shell_exec($cmd2);
    check("POST2: response reports 'Shapefile Load Complete'", strpos((string)$html2, 'Shapefile Load Complete') !== false);

    // ---------------------------------------------------------------------
    // Assertions — Neo4j graph + strabosamples mirror with context.
    // ---------------------------------------------------------------------
    echo "\n=== Assertions: graph + strabosamples context ===\n";
    $dsId = $neodb->get_var("MATCH (p:Project {id:$projId, userpkey:$ownerPkey})-[:HAS_DATASET]->(d:Dataset) RETURN d.id LIMIT 1");
    check("dataset node created under the project", !empty($dsId));
    $spotCount = (int)$neodb->get_var(
        "MATCH (p:Project {id:$projId, userpkey:$ownerPkey})-[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(s:Spot) RETURN count(s) AS c");
    check("two spots imported + dataset-linked", $spotCount === 2);

    // strabosamples rows for the imported samples (resolve via the link).
    $rows = $db->get_results_prepared(
        "SELECT s.id, s.name,
                l.reference_metadata->>'project_id' AS pid,
                l.reference_metadata->>'dataset_id' AS did
           FROM strabosamples.sample_subsystem_links l
           JOIN strabosamples.samples s ON s.id=l.sample_id AND s.userpkey=l.sample_userpkey
          WHERE l.subsystem='field' AND l.sample_userpkey=$2
            AND l.reference_metadata->>'project_id' = $1",
        array((string)$projId, (int)$ownerPkey));
    $n = is_array($rows) ? count($rows) : 0;
    note("strabosamples rows linked to project: $n");
    check("two samples mirrored into strabosamples", $n === 2);

    $allPidOk = $n > 0; $allDidOk = $n > 0; $names = array();
    if (is_array($rows)) foreach ($rows as $r) {
        if ($r->pid !== (string)$projId) $allPidOk = false;
        if ($r->did === null || (string)$r->did !== (string)$dsId) $allDidOk = false;
        $names[] = $r->name;
    }
    check("HTTP import propagated project_id context (non-NULL, correct)", $allPidOk);
    check("HTTP import propagated dataset_id context (matches new dataset)", $allDidOk);
    sort($names);
    check("imported sample names mirror the shapefile values",
          $names === array('ShpSampleA', 'ShpSampleB'));

} finally {
    shpCleanup($db, $neodb, $projId, $ownerPkey, $randnum, $docRoot);
    shell_exec("rm -rf " . escapeshellarg($workDir));
    foreach ($sessionFiles as $f) @unlink($f);
}

echo "\n=================================================\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
}
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
