<?php
/**
 * File: e2e_field_download.php
 * Description: Curl-level END-TO-END coverage of the StraboField download /
 *              export endpoints, proving that a Samples-app spine edit —
 *              which writes back into the Neo4j Spot's json_samples — surfaces
 *              in the actual download served by the live session-gated HTTP
 *              endpoints.
 *
 *              Field is the one HYBRID-storage subsystem: the Neo4j Spot stays
 *              authoritative, so Field downloads reflect spine edits via
 *              WRITEBACK (StraboSamplesService::updateSample → Neo4j
 *              json_samples), NOT a read-time overlay (that's the Exp/Micro
 *              model). smoke_test_field_export_verify.php proves the writeback
 *              reaches the export *library* methods (geoJSONOut / doiDataOut
 *              via output buffering / direct return — 0 HTTP). This suite
 *              closes the remaining gap: that the real HTTP endpoints
 *              (searchdownload.php, build_field_download.php), session-gated
 *              and wired through prepare_connections.php, actually serve that
 *              writeback-updated Neo4j data.
 *
 *                A. GET /searchdownload.php?type=geojson&dsids={ds}
 *                   → FeatureCollection reflects the edited samples[]
 *                B. GET /searchdownload.php?type=doidata&projectid={proj}
 *                   → spotsDb reflects the edited samples[] (the doiDataOut
 *                     path that build_field_download.php's ZIP also uses)
 *                C. GET /searchdownload.php?type=sample_list&dsids={ds}
 *                   → valid XLSX (PK magic) carrying the edited sample name
 *                     (verified via sharedStrings.xml when unzip is present)
 *                D. GET /build_field_download.php?p={proj}
 *                   → builds the offline ZIP; data.json inside reflects edits
 *                E. auth gates: anonymous → 302 /login.php; logged-in stranger
 *                   sees none of the owner's data (userpkey isolation)
 *
 *              The spine edit itself is performed via the service layer (the
 *              same call the /samplesdb API + samples_edit.php proxy use) as
 *              the precondition; what this suite asserts E2E is the DOWNLOAD
 *              side over HTTP.
 *
 *              Hermetic: numeric fixture ids under a '96667' prefix (disjoint
 *              from the upload suite's 96666); cleans up DB + the fieldZips/
 *              build artifact + forged session files. Uses the test users from
 *              setup_test_data.php. Exits non-zero on any failure.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_field_download.php
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
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

// -------------------------------------------------------------------------
// Users
// -------------------------------------------------------------------------

$users = array();
foreach (array('owner', 'outsider') as $who) {
    $email = "$who@test.strabospot.org";
    $pkey  = (int)$db->get_var_prepared(
        "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($email));
    if (!$pkey) { echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n"; exit(1); }
    $users[$who] = $pkey;
}
$ownerPkey    = $users['owner'];
$strangerPkey = $users['outsider'];
echo "owner=$ownerPkey stranger=$strangerPkey\n";

// -------------------------------------------------------------------------
// Forged sessions
// -------------------------------------------------------------------------

$sessionDir   = '/var/lib/php/sessions';
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
// HTTP GET — captures status, body, and Location header. sid null = anon.
// -------------------------------------------------------------------------

function httpGet($path, $sid) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2efd_body_');
    $hdrFile  = tempnam(sys_get_temp_dir(), 'e2efd_hdr_');
    $args  = "-s -o " . escapeshellarg($bodyFile) . " -D " . escapeshellarg($hdrFile) . " -w '%{http_code}' ";
    if ($sid !== null) $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body   = file_get_contents($bodyFile);
    $location = '';
    foreach (explode("\n", (string)file_get_contents($hdrFile)) as $line) {
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    @unlink($bodyFile); @unlink($hdrFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'location' => $location);
}

// Find a feature by spot id in a GeoJSON-ish features array (assoc).
function findFeatureProps($features, $spotId) {
    if (!is_array($features)) return null;
    foreach ($features as $f) {
        if (isset($f['properties']['id']) && (string)$f['properties']['id'] === (string)$spotId) return $f['properties'];
    }
    return null;
}
// Find a sample entry by id within a properties.samples[] (assoc).
function findSample($props, $sampleId) {
    if (!is_array($props) || empty($props['samples'])) return null;
    foreach ($props['samples'] as $s) {
        if (isset($s['id']) && (string)$s['id'] === (string)$sampleId) return $s;
    }
    return null;
}

// -------------------------------------------------------------------------
// Fixtures — numeric '96667' prefix
// -------------------------------------------------------------------------

$stamp     = time();
$base      = 96667 * 10000000 + ($stamp % 10000000);
$projectId = $base + 1;
$datasetId = $base + 2;
$spotRich  = $base + 10;
$spotLeg   = $base + 11;
$legSample = $base + 60;
$richSid   = (string)$spotRich;
$legSid    = (string)$legSample;
echo "project=$projectId dataset=$datasetId richSpot=$spotRich legacySpot=$spotLeg\n\n";

function fdCleanup($db, $neodb) {
    $db->query("DELETE FROM strabosamples.sample_changelog      WHERE sample_id LIKE '96667%'");
    $db->query("DELETE FROM strabosamples.sample_collaborators  WHERE sample_id LIKE '96667%'");
    $db->query("DELETE FROM strabosamples.sample_subsystem_links WHERE sample_id LIKE '96667%'");
    $db->query("DELETE FROM strabosamples.samples               WHERE id LIKE '96667%'");
    $neodb->query("MATCH (s:Spot)    WHERE s.id >= 966670000000 AND s.id <= 966679999999 DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id >= 966670000000 AND d.id <= 966679999999 DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id >= 966670000000 AND p.id <= 966679999999 DETACH DELETE p");
}

fdCleanup($db, $neodb);
$buildUuid = null;

try {
    // ---------------------------------------------------------------------
    // Seed Neo4j: Project + Dataset + rich + legacy sample-spots.
    // ---------------------------------------------------------------------
    $neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, name:'E2E Field DL', desc_project_name:'E2E Field DL'})");
    $neodb->query("CREATE (d:Dataset {id:$datasetId, userpkey:$ownerPkey, name:'E2E DL DS'})");
    $neodb->query("MATCH (p:Project {id:$projectId,userpkey:$ownerPkey}),(d:Dataset {id:$datasetId,userpkey:$ownerPkey}) CREATE (p)-[:HAS_DATASET]->(d)");

    $richObj = array('id'=>$richSid,'sample_id_name'=>'BaselineRich','Sample_IGSN'=>'IGSN-base-rich',
                     'sample_description'=>'baseline rich desc','material_type'=>'intact_rock','main_sampling_purpose'=>'petrology');
    $neodb->query("CREATE (s:Spot {id:$spotRich, userpkey:$ownerPkey, isSample:1, name:'BaselineRich', modified_timestamp:1000,
                   json_samples:'" . addslashes(json_encode(array($richObj))) . "', wkt:'POINT(-120.5 45.5)'})");
    $neodb->query("MATCH (d:Dataset {id:$datasetId,userpkey:$ownerPkey}),(s:Spot {id:$spotRich,userpkey:$ownerPkey}) CREATE (d)-[:HAS_SPOT]->(s)");

    $legObj = array('id'=>$legSid,'sample_id_name'=>'BaselineLegacy','sample_description'=>'baseline legacy desc','material_type'=>'sediment');
    $neodb->query("CREATE (s:Spot {id:$spotLeg, userpkey:$ownerPkey, name:'ParentHolder', modified_timestamp:1000,
                   json_samples:'" . addslashes(json_encode(array($legObj))) . "', wkt:'POINT(-100.0 40.0)'})");
    $neodb->query("MATCH (d:Dataset {id:$datasetId,userpkey:$ownerPkey}),(s:Spot {id:$spotLeg,userpkey:$ownerPkey}) CREATE (d)-[:HAS_SPOT]->(s)");

    // Materialize strabosamples rows + Field links.
    field_sample_sync_spot($db, $neodb, (object)array('id'=>$spotRich,'samples'=>array((object)$richObj),'isSample'=>1),
        $spotRich, $ownerPkey, (string)$projectId, (string)$datasetId);
    field_sample_sync_spot($db, $neodb, (object)array('id'=>$spotLeg,'samples'=>array((object)$legObj)),
        $spotLeg, $ownerPkey, (string)$projectId, (string)$datasetId);

    // Spine edits (writeback into Neo4j json_samples) — precondition.
    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey($ownerPkey);
    $svc->updateSample($richSid, $ownerPkey, array(
        'name'=>'EditedRich','igsn'=>'IGSN-edited-rich','description'=>'edited rich desc',
        'display_sample_type'=>'tephra','display_sample_purpose'=>'geochronology'));
    $svc->updateSample($legSid, $ownerPkey, array('name'=>'EditedLegacy','description'=>'edited legacy desc'));

    // Sanity: writeback reached Neo4j json_samples.
    $richJs = $neodb->get_var("MATCH (s:Spot {id:$spotRich,userpkey:$ownerPkey}) RETURN s.json_samples");
    $rj = json_decode($richJs, true);
    check("precondition: writeback set rich samples[0].sample_id_name='EditedRich'",
          is_array($rj) && isset($rj[0]['sample_id_name']) && $rj[0]['sample_id_name'] === 'EditedRich');

    $sidOwner    = forgeSession($ownerPkey);
    $sidStranger = forgeSession($strangerPkey);

    // =====================================================================
    // A — searchdownload.php?type=geojson
    // =====================================================================
    echo "\n=== A: searchdownload.php?type=geojson — owner ===\n";
    $r = httpGet("/searchdownload.php?type=geojson&dsids=$datasetId", $sidOwner);
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes");
    $feats = (is_array($r['json']) && isset($r['json']['features'])) ? $r['json']['features'] : null;
    check("A: 200 + FeatureCollection with 2 features",
          $r['status'] === 200 && isset($r['json']['type']) && $r['json']['type'] === 'FeatureCollection' && is_array($feats) && count($feats) === 2);
    $rp = findFeatureProps($feats, $spotRich); $re = $rp ? findSample($rp, $richSid) : null;
    check("A: rich samples[].sample_id_name='EditedRich'", $re && $re['sample_id_name'] === 'EditedRich');
    check("A: rich samples[].material_type='tephra'",      $re && $re['material_type'] === 'tephra');
    check("A: rich spot.name reflects rich sync ('EditedRich')", $rp && isset($rp['name']) && $rp['name'] === 'EditedRich');
    $lp = findFeatureProps($feats, $spotLeg); $le = $lp ? findSample($lp, $legSid) : null;
    check("A: legacy samples[].sample_id_name='EditedLegacy'", $le && $le['sample_id_name'] === 'EditedLegacy');

    // =====================================================================
    // B — searchdownload.php?type=doidata
    // =====================================================================
    echo "\n=== B: searchdownload.php?type=doidata — owner ===\n";
    $r = httpGet("/searchdownload.php?type=doidata&projectid=$projectId", $sidOwner);
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes");
    $spotsDb = (is_array($r['json']) && isset($r['json']['spotsDb'])) ? $r['json']['spotsDb'] : null;
    check("B: 200 + spotsDb present", $r['status'] === 200 && is_array($spotsDb) && count($spotsDb) >= 2);
    $rprops = isset($spotsDb[$richSid]['properties']) ? $spotsDb[$richSid]['properties'] : null;
    $re = $rprops ? findSample($rprops, $richSid) : null;
    check("B: spotsDb rich samples[].sample_id_name='EditedRich'", $re && $re['sample_id_name'] === 'EditedRich');
    check("B: spotsDb rich samples[].material_type='tephra'",      $re && $re['material_type'] === 'tephra');
    // spotsDb is keyed by SPOT id; the legacy holder spot's id differs from
    // its inline sample id.
    $lprops = isset($spotsDb[(string)$spotLeg]['properties']) ? $spotsDb[(string)$spotLeg]['properties'] : null;
    $le = $lprops ? findSample($lprops, $legSid) : null;
    check("B: spotsDb legacy samples[].sample_id_name='EditedLegacy'", $le && $le['sample_id_name'] === 'EditedLegacy');

    // =====================================================================
    // C — searchdownload.php?type=sample_list (XLSX)
    // =====================================================================
    echo "\n=== C: searchdownload.php?type=sample_list — owner (XLSX) ===\n";
    $r = httpGet("/searchdownload.php?type=sample_list&dsids=$datasetId", $sidOwner);
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes, magic=" . bin2hex(substr($r['body'], 0, 2)));
    $isXlsx = strncmp($r['body'], "PK\x03\x04", 4) === 0;
    check("C: 200", $r['status'] === 200);
    check("C: body is a valid XLSX (PK zip magic)", $isXlsx);
    check("C: XLSX non-trivial (>2KB)", strlen($r['body']) > 2048);
    // Content check via sharedStrings.xml when unzip is available.
    if ($isXlsx) {
        $tmp = tempnam(sys_get_temp_dir(), 'e2efd_xlsx_') . '.xlsx';
        file_put_contents($tmp, $r['body']);
        $shared = shell_exec("unzip -p " . escapeshellarg($tmp) . " xl/sharedStrings.xml 2>/dev/null");
        @unlink($tmp);
        if (is_string($shared) && $shared !== '') {
            check("C: XLSX sharedStrings contains edited rich sample name", strpos($shared, 'EditedRich') !== false);
            check("C: XLSX sharedStrings contains edited legacy sample name", strpos($shared, 'EditedLegacy') !== false);
        } else {
            note("unzip unavailable or no sharedStrings.xml — skipping XLSX content grep (magic+size asserted)");
        }
    }

    // =====================================================================
    // D — build_field_download.php (offline ZIP build)
    // =====================================================================
    echo "\n=== D: build_field_download.php?p={proj} — owner (ZIP build) ===\n";
    $r = httpGet("/build_field_download.php?p=$projectId", $sidOwner);
    note("HTTP {$r['status']}, body=" . substr($r['body'], 0, 120));
    check("D: 200 + Success + uuid", $r['status'] === 200 && is_array($r['json']) && isset($r['json']['Message']) && $r['json']['Message'] === 'Success!' && !empty($r['json']['uuid']));
    if (is_array($r['json']) && !empty($r['json']['uuid'])) {
        $buildUuid = $r['json']['uuid'];
        $zipPath = "/srv/app/www/fieldZips/$buildUuid/field_app_project.zip";
        check("D: ZIP artifact exists on disk", is_file($zipPath));
        $dataJson = null;
        if (is_file($zipPath) && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (substr($name, -strlen('/data.json')) === '/data.json' || $name === 'data.json') {
                        $dataJson = json_decode($zip->getFromIndex($i), true);
                        break;
                    }
                }
                $zip->close();
            }
        }
        check("D: data.json present inside the ZIP", is_array($dataJson));
        $sdb = (is_array($dataJson) && isset($dataJson['spotsDb'])) ? $dataJson['spotsDb'] : null;
        $rprops = (is_array($sdb) && isset($sdb[$richSid]['properties'])) ? $sdb[$richSid]['properties'] : null;
        $re = $rprops ? findSample($rprops, $richSid) : null;
        check("D: ZIP data.json rich samples[].sample_id_name='EditedRich'", $re && $re['sample_id_name'] === 'EditedRich');
        check("D: ZIP data.json rich samples[].material_type='tephra'",      $re && $re['material_type'] === 'tephra');
        $lprops = (is_array($sdb) && isset($sdb[(string)$spotLeg]['properties'])) ? $sdb[(string)$spotLeg]['properties'] : null;
        $le = $lprops ? findSample($lprops, $legSid) : null;
        check("D: ZIP data.json legacy samples[].sample_id_name='EditedLegacy'", $le && $le['sample_id_name'] === 'EditedLegacy');
    }

    // =====================================================================
    // E — auth gates
    // =====================================================================
    echo "\n=== E: auth gates ===\n";
    $r = httpGet("/searchdownload.php?type=geojson&dsids=$datasetId", null);
    note("anon HTTP {$r['status']} → {$r['location']}");
    check("E: anonymous searchdownload → 302 redirect to /login.php",
          $r['status'] === 302 && strpos($r['location'], 'login.php') !== false);

    $r = httpGet("/build_field_download.php?p=$projectId", null);
    check("E: anonymous build_field_download → 302 redirect to /login.php",
          $r['status'] === 302 && strpos($r['location'], 'login.php') !== false);

    // Logged-in stranger: getDatasetSpotsSearch filters by the session
    // userpkey, so the owner's dataset yields zero features.
    $r = httpGet("/searchdownload.php?type=geojson&dsids=$datasetId", $sidStranger);
    $feats = (is_array($r['json']) && isset($r['json']['features'])) ? $r['json']['features'] : array();
    note("stranger HTTP {$r['status']}, features=" . (is_array($feats) ? count($feats) : 'n/a'));
    check("E: stranger geojson sees none of the owner's spots (isolation)",
          $r['status'] === 200 && is_array($feats) && count($feats) === 0);

    // Stranger build: project ownership check fails → "Project not found", no uuid.
    $r = httpGet("/build_field_download.php?p=$projectId", $sidStranger);
    note("stranger build body=" . substr($r['body'], 0, 80));
    check("E: stranger build_field_download → not found, no Success/uuid",
          stripos($r['body'], 'not found') !== false && strpos($r['body'], 'Success') === false);

} finally {
    fdCleanup($db, $neodb);
    if ($buildUuid !== null && preg_match('/^[a-f0-9\-]{8,}$/i', $buildUuid)) {
        shell_exec("rm -rf " . escapeshellarg("/srv/app/www/fieldZips/$buildUuid"));
    }
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
