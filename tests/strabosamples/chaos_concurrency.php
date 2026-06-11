<?php
/**
 * File: chaos_concurrency.php
 * Description: Phase E of the StraboSamples pre-cutover test plan —
 *              concurrency + idempotency under realistic chaos. Drives
 *              REAL HTTP surfaces (and real subprocess migrations) with
 *              genuinely concurrent requests via proc_open, then asserts
 *              the final state is consistent: no duplicate rows, no
 *              duplicate links, no torn writes, no half-committed mess.
 *
 *              E.1 — two parallel Field uploads of the same project from
 *                    different collaborator users (create race + update
 *                    race). Re-proves the 2026-05-29 duplicate-datasets
 *                    fix under true simultaneity AND extends it to the
 *                    strabosamples mirror.
 *              E.2 — modal edit during Field upload of the same sample.
 *                    Deterministic sequence asserts last-writer-wins per
 *                    design §10.4 + changelog ordering; concurrent runs
 *                    assert cross-store consistency and report drift.
 *              E.3 — full migration --apply re-run while a large upload
 *                    is in flight. Documents observed interleaving.
 *              E.4 — backfill_field_geom.php --apply while an upload
 *                    rewrites the same rows. Asserts no NULL flicker and
 *                    no torn lat/lng pairs.
 *
 *              Hermetic: all fixture ids use a '95555' prefix disjoint
 *              from e2e (96666666), collab (9999999900+/8888888800+)
 *              and real Field ids (17-prefix ms epochs). Cleanup runs at
 *              start (catches prior crashed runs) and in finally{}.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/chaos_concurrency.php
 *
 * @package    StraboSpot Web Site / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

// -------------------------------------------------------------------------
// Test framework
// -------------------------------------------------------------------------

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

// -------------------------------------------------------------------------
// Test users — provisioned by tests/collaboration/setup_test_data.php
// -------------------------------------------------------------------------

$ownerEmail  = 'owner@test.strabospot.org';
$editorEmail = 'editor@test.strabospot.org';
$password    = 'testpass123';

$ownerPkey  = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($ownerEmail));
$editorPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($editorEmail));
if (!$ownerPkey || !$editorPkey) {
    echo "Test users not found. Run tests/collaboration/setup_test_data.php first.\n";
    exit(1);
}
echo "owner=$ownerPkey editor=$editorPkey\n\n";

// -------------------------------------------------------------------------
// ID scheme: '95555' prefix + 4 stamp digits + 3-digit counter = 12 digits
// -------------------------------------------------------------------------

$stamp  = time();
$idBase = "95555" . substr((string)$stamp, -4);          // 9 digits
$NEO_ID_MIN = 955550000000;                              // cleanup range
$NEO_ID_MAX = 955559999999;

$projectId  = (int)($idBase . "001");
$datasetId  = (int)($idBase . "002");

// E.1/E.2 spots: two rich sample-spots + one legacy holder.
$richAId    = (int)($idBase . "010");
$richBId    = (int)($idBase . "011");
$legHoldId  = (int)($idBase . "012");
$legSampId  = (string)((int)($idBase . "013"));

// E.3 bulk spots: 120 rich sample-spots, counter 100-219.
$E3_COUNT = 120;
$e3Ids = array();
for ($i = 0; $i < $E3_COUNT; $i++) $e3Ids[] = (int)($idBase . sprintf("%03d", 100 + $i));

// E.4 reuses the first 30 of the E.3 spots.
$E4_COUNT = 30;

// -------------------------------------------------------------------------
// Concurrency primitives — proc_open'd curl + php subprocesses
// -------------------------------------------------------------------------

/**
 * Launch a curl request WITHOUT waiting. Returns a handle for curlWait().
 * $auth: array('basic' => 'email:pass') or array('sid' => $sessionId).
 */
function curlAsync($method, $path, $jsonBody, array $auth) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'chaos_body_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    if (isset($auth['basic'])) $cmd .= "-u " . escapeshellarg($auth['basic']) . " ";
    if (isset($auth['sid']))   $cmd .= "-H " . escapeshellarg('Cookie: PHPSESSID=' . $auth['sid']) . " ";
    $cmd .= "-X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) {
        $cmd .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
        $cmd .= "-d " . escapeshellarg($jsonBody) . " ";
    }
    $cmd .= escapeshellarg("http://localhost" . $path);
    $pipes = array();
    $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    return array('proc' => $proc, 'pipes' => $pipes, 'bodyFile' => $bodyFile);
}

function curlWait(array $h) {
    $status = (int)stream_get_contents($h['pipes'][1]);
    $err    = stream_get_contents($h['pipes'][2]);
    fclose($h['pipes'][1]);
    fclose($h['pipes'][2]);
    proc_close($h['proc']);
    $body = file_get_contents($h['bodyFile']);
    @unlink($h['bodyFile']);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'stderr' => $err);
}

/** Launch a PHP CLI subprocess without waiting. */
function phpAsync($argsString) {
    $pipes = array();
    $proc = proc_open("php $argsString 2>&1", array(1 => array('pipe', 'w')), $pipes);
    return array('proc' => $proc, 'pipes' => $pipes);
}

function phpWait(array $h) {
    $out  = stream_get_contents($h['pipes'][1]);
    fclose($h['pipes'][1]);
    $code = proc_close($h['proc']);
    return array('exit' => $code, 'out' => $out);
}

function forgeSession($pkey) {
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = '/var/lib/php/sessions/sess_' . $sid;
    $body = 'loggedin|s:3:"yes";userpkey|i:' . $pkey . ';LAST_ACTIVITY|i:' . time() . ';';
    file_put_contents($path, $body);
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    return array($sid, $path);
}

// -------------------------------------------------------------------------
// Payload builder — same wire shape as the real Field client's bulk upload
// -------------------------------------------------------------------------

/**
 * $spots: list of arrays with keys
 *   id (int), name (string), coords ([lng,lat]),
 *   rich (bool), sample (assoc — the sample object; id filled from spot for rich)
 */
function buildFieldPayload($projectId, $datasetId, array $spots, $modifiedTs) {
    $features = array();
    foreach ($spots as $s) {
        $props = array(
            'id'                 => $s['id'],
            'name'               => $s['name'],
            'modified_timestamp' => $modifiedTs,
            'samples'            => array($s['sample']),
        );
        if (!empty($s['rich'])) $props['isSample'] = 1;
        $features[] = array(
            'type'       => 'Feature',
            'geometry'   => array('type' => 'Point', 'coordinates' => $s['coords']),
            'properties' => $props,
        );
    }
    return json_encode(array('project' => array(
        'id'                 => $projectId,
        'description'        => array('project_name' => 'Chaos Concurrency Project'),
        'modified_timestamp' => $modifiedTs,
        'datasets'           => array(array(
            'id'                 => $datasetId,
            'name'               => 'Chaos Concurrency Dataset',
            'modified_timestamp' => $modifiedTs,
            'spots'              => array('features' => $features),
        )),
    )));
}

function e12Spots($nameTag) {
    global $richAId, $richBId, $legHoldId, $legSampId;
    return array(
        array('id' => $richAId, 'name' => "ChaosRichA $nameTag", 'coords' => array(-101.1, 35.1), 'rich' => true,
              'sample' => array('id' => (string)$richAId, 'sample_id_name' => "CHAOS-RA-$nameTag",
                                'sample_description' => "rich A desc $nameTag",
                                'material_type' => 'intact_rock', 'main_sampling_purpose' => 'petrology')),
        array('id' => $richBId, 'name' => "ChaosRichB $nameTag", 'coords' => array(-102.2, 36.2), 'rich' => true,
              'sample' => array('id' => (string)$richBId, 'sample_id_name' => "CHAOS-RB-$nameTag",
                                'sample_description' => "rich B desc $nameTag",
                                'material_type' => 'sediment', 'main_sampling_purpose' => 'geochronology')),
        array('id' => $legHoldId, 'name' => "ChaosLegHolder $nameTag", 'coords' => array(-103.3, 37.3), 'rich' => false,
              'sample' => array('id' => $legSampId, 'sample_id_name' => "CHAOS-LEG-$nameTag",
                                'sample_description' => "legacy desc $nameTag",
                                'material_type' => 'sediment', 'main_sampling_purpose' => 'other')),
    );
}

function e3Spots($nameTag, $coordShift = 0.0) {
    global $e3Ids;
    $spots = array();
    foreach ($e3Ids as $ix => $id) {
        $lng = -110.0 + ($ix * 0.01) + $coordShift;
        $lat = 40.0 + ($ix * 0.01) + $coordShift;
        $spots[] = array('id' => $id, 'name' => "ChaosBulk$ix $nameTag",
                         'coords' => array($lng, $lat), 'rich' => true,
                         'sample' => array('id' => (string)$id, 'sample_id_name' => "CHAOS-BULK-$ix-$nameTag",
                                           'sample_description' => "bulk $ix $nameTag",
                                           'material_type' => 'intact_rock',
                                           'main_sampling_purpose' => 'petrology'));
    }
    return $spots;
}

// -------------------------------------------------------------------------
// Assertion helpers
// -------------------------------------------------------------------------

/** Exactly-one invariants for the Neo4j side of the fixture project. */
function neoSingletons($neodb, $projectId, $datasetId, array $spotIds) {
    $bad = array();
    $c = (int)$neodb->get_var("MATCH (p:Project {id:$projectId}) RETURN count(p) AS c");
    if ($c !== 1) $bad[] = "project nodes=$c";
    $c = (int)$neodb->get_var("MATCH (d:Dataset {id:$datasetId}) RETURN count(d) AS c");
    if ($c !== 1) $bad[] = "dataset nodes=$c";
    $c = (int)$neodb->get_var("MATCH (:Project {id:$projectId})-[r:HAS_DATASET]->(:Dataset {id:$datasetId}) RETURN count(r) AS c");
    if ($c !== 1) $bad[] = "HAS_DATASET rels=$c";
    foreach ($spotIds as $sid) {
        $c = (int)$neodb->get_var("MATCH (s:Spot {id:$sid}) RETURN count(s) AS c");
        if ($c !== 1) $bad[] = "spot $sid nodes=$c";
        $c = (int)$neodb->get_var("MATCH (:Dataset {id:$datasetId})-[r:HAS_SPOT]->(:Spot {id:$sid}) RETURN count(r) AS c");
        if ($c !== 1) $bad[] = "spot $sid HAS_SPOT rels=$c";
    }
    return $bad;
}

/** Exactly-one-row + exactly-one-link invariants for strabosamples. */
function pgSingletons($db, $ownerPkey, array $sampleIds) {
    $bad = array();
    foreach ($sampleIds as $sid) {
        $rows = $db->get_results_prepared(
            "SELECT userpkey FROM strabosamples.samples WHERE id=$1", array((string)$sid));
        $n = is_array($rows) ? count($rows) : 0;
        if ($n !== 1) { $bad[] = "sample $sid rows=$n"; continue; }
        if ((int)$rows[0]->userpkey !== $ownerPkey) $bad[] = "sample $sid userpkey={$rows[0]->userpkey}";
        $n = (int)$db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.sample_subsystem_links
              WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
            array((string)$sid, $ownerPkey));
        if ($n !== 1) $bad[] = "sample $sid field links=$n";
    }
    $n = (int)$db->get_var(
        "SELECT count(*) FROM (
            SELECT sample_id FROM strabosamples.sample_subsystem_links
             WHERE sample_id LIKE '95555%'
             GROUP BY sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey
            HAVING count(*) > 1) q");
    if ($n !== 0) $bad[] = "duplicate link tuples=$n";
    return $bad;
}

// -------------------------------------------------------------------------
// Cleanup — start-of-run (stale state) + finally{}
// -------------------------------------------------------------------------

function chaosCleanup($db, $neodb, $ownerPkey, $NEO_ID_MIN, $NEO_ID_MAX) {
    // strabosamples: CASCADE clears links/changelog/children.
    $db->query("DELETE FROM strabosamples.samples WHERE id LIKE '95555%'");
    // Neo4j fixture nodes (any userpkey — a dup under the editor's pkey is
    // exactly the bug class we probe for, so the sweep must catch those too).
    $neodb->query("MATCH (s:Spot) WHERE s.id >= $NEO_ID_MIN AND s.id <= $NEO_ID_MAX DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_ID_MIN AND d.id <= $NEO_ID_MAX DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id >= $NEO_ID_MIN AND p.id <= $NEO_ID_MAX DETACH DELETE p");
    // Collaboration + versioning fixture rows.
    $db->query("DELETE FROM collaborators WHERE strabo_project_id LIKE '95555%'");
    $db->query("DELETE FROM vprojects WHERE projectid::text LIKE '95555%'");
}

chaosCleanup($db, $neodb, $ownerPkey, $NEO_ID_MIN, $NEO_ID_MAX);

$ownerAuth  = array('basic' => "$ownerEmail:$password");
$editorAuth = array('basic' => "$editorEmail:$password");
$sessionFiles = array();

try {

    // ---------------------------------------------------------------------
    // Fixture seed: Project node (bulk controller requires it to exist for
    // canRead — same pre-seed rationale as e2e_workflows B.1) + accepted
    // edit-level collaboration for the editor.
    // ---------------------------------------------------------------------
    $neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, name:'Chaos Concurrency Project'})");
    $db->prepare_query(
        "INSERT INTO collaborators
            (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
             collaboration_level, accepted, uuid, disabled)
         VALUES ($1, $2, $3, 'edit', TRUE, $4, FALSE)",
        array((string)$projectId, $ownerPkey, $editorPkey, bin2hex(random_bytes(16))));

    // =====================================================================
    // E.1a — CREATE race: both users bulk-upload the same project payload
    // simultaneously onto a clean slate. 3 iterations.
    // =====================================================================
    echo "=== E.1a: parallel CREATE race (owner + editor, clean slate) ===\n";

    $e12SampleIds = array((string)$richAId, (string)$richBId, $legSampId);

    for ($iter = 1; $iter <= 3; $iter++) {
        // Clean slate: drop spots + dataset + sample rows; keep project + collab.
        $db->query("DELETE FROM strabosamples.samples WHERE id LIKE '95555%'");
        $neodb->query("MATCH (s:Spot) WHERE s.id >= $NEO_ID_MIN AND s.id <= $NEO_ID_MAX DETACH DELETE s");
        $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_ID_MIN AND d.id <= $NEO_ID_MAX DETACH DELETE d");

        $ts = (int)(microtime(true) * 1000);
        $payload = buildFieldPayload($projectId, $datasetId, e12Spots("e1a$iter"), $ts);

        $hA = curlAsync('POST', '/db/projectdatasetsspots', $payload, $ownerAuth);
        $hB = curlAsync('POST', '/db/projectdatasetsspots', $payload, $editorAuth);
        $rA = curlWait($hA);
        $rB = curlWait($hB);
        note("iter $iter HTTP: owner={$rA['status']} editor={$rB['status']}");

        $ok2xx = ($rA['status'] >= 200 && $rA['status'] < 300) && ($rB['status'] >= 200 && $rB['status'] < 300);
        check("E.1a iter $iter: both uploads returned 2xx", $ok2xx);

        $bad = neoSingletons($neodb, $projectId, $datasetId, array($richAId, $richBId, $legHoldId));
        check("E.1a iter $iter: Neo4j single-instance invariants", empty($bad));
        if (!empty($bad)) note("NEO VIOLATIONS: " . implode('; ', $bad));

        $bad = pgSingletons($db, $ownerPkey, $e12SampleIds);
        check("E.1a iter $iter: strabosamples single-row + single-link invariants", empty($bad));
        if (!empty($bad)) note("PG VIOLATIONS: " . implode('; ', $bad));

        // Changelog: at least one 'create' per sample, none torn mid-create.
        $n = (int)$db->get_var_prepared(
            "SELECT count(DISTINCT sample_id) FROM strabosamples.sample_changelog
              WHERE sample_id = ANY($1) AND change_type='create'",
            array('{' . implode(',', $e12SampleIds) . '}'));
        check("E.1a iter $iter: changelog has a create row for all 3 samples", $n === 3);
    }

    // =====================================================================
    // E.1b — UPDATE race: rows exist; both users re-upload the IDENTICAL
    // payload (same bumped timestamp) simultaneously. Pure idempotency
    // under simultaneity. 3 iterations.
    // =====================================================================
    echo "\n=== E.1b: parallel UPDATE race (identical re-upload) ===\n";

    for ($iter = 1; $iter <= 3; $iter++) {
        $ts = (int)(microtime(true) * 1000);
        $payload = buildFieldPayload($projectId, $datasetId, e12Spots("e1b$iter"), $ts);

        $hA = curlAsync('POST', '/db/projectdatasetsspots', $payload, $ownerAuth);
        $hB = curlAsync('POST', '/db/projectdatasetsspots', $payload, $editorAuth);
        $rA = curlWait($hA);
        $rB = curlWait($hB);
        note("iter $iter HTTP: owner={$rA['status']} editor={$rB['status']}");

        $ok2xx = ($rA['status'] >= 200 && $rA['status'] < 300) && ($rB['status'] >= 200 && $rB['status'] < 300);
        check("E.1b iter $iter: both uploads returned 2xx", $ok2xx);

        $bad = array_merge(
            neoSingletons($neodb, $projectId, $datasetId, array($richAId, $richBId, $legHoldId)),
            pgSingletons($db, $ownerPkey, $e12SampleIds));
        check("E.1b iter $iter: all single-instance invariants hold", empty($bad));
        if (!empty($bad)) note("VIOLATIONS: " . implode('; ', $bad));

        // Content converged: name tag matches this iteration on the rich A row.
        $name = (string)$db->get_var_prepared(
            "SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array((string)$richAId, $ownerPkey));
        check("E.1b iter $iter: spine reflects this round's payload", $name === "CHAOS-RA-e1b$iter");
    }

    // =====================================================================
    // E.2a — deterministic: modal edit lands, then upload re-mirrors.
    // Last-writer-wins per §10.4; changelog carries both rows in order.
    // =====================================================================
    echo "\n=== E.2a: modal edit then upload (deterministic last-writer-wins) ===\n";

    list($ownerSid, $sf) = forgeSession($ownerPkey);
    $sessionFiles[] = $sf;

    $maxPkeyBefore = (int)$db->get_var_prepared(
        "SELECT COALESCE(max(pkey),0) FROM strabosamples.sample_changelog WHERE sample_id=$1",
        array((string)$richAId));

    $rEdit = curlWait(curlAsync('POST', '/samples_edit.php', json_encode(array(
        'sample_id'   => (string)$richAId,
        'owner_pkey'  => $ownerPkey,
        'name'        => 'CHAOS-RA-modal',
        'description' => 'modal-edited description',
    )), array('sid' => $ownerSid)));
    check("E.2a modal edit returned 200", $rEdit['status'] === 200);

    $name = (string)$db->get_var_prepared(
        "SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$richAId, $ownerPkey));
    check("E.2a strabosamples spine has modal value", $name === 'CHAOS-RA-modal');

    $jsRaw = (string)$neodb->get_var("MATCH (s:Spot {id:$richAId, userpkey:$ownerPkey}) RETURN s.json_samples");
    $js = json_decode($jsRaw, true);
    check("E.2a Neo4j writeback carried modal value",
          is_array($js) && isset($js[0]['sample_id_name']) && $js[0]['sample_id_name'] === 'CHAOS-RA-modal');

    // Upload re-mirrors with a newer timestamp → upload wins.
    $ts = (int)(microtime(true) * 1000);
    $rUp = curlWait(curlAsync('POST', '/db/projectdatasetsspots',
        buildFieldPayload($projectId, $datasetId, e12Spots("e2a"), $ts), $ownerAuth));
    check("E.2a re-upload returned 2xx", $rUp['status'] >= 200 && $rUp['status'] < 300);

    $name = (string)$db->get_var_prepared(
        "SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$richAId, $ownerPkey));
    check("E.2a last writer (upload) won the spine", $name === 'CHAOS-RA-e2a');

    $jsRaw = (string)$neodb->get_var("MATCH (s:Spot {id:$richAId, userpkey:$ownerPkey}) RETURN s.json_samples");
    $js = json_decode($jsRaw, true);
    check("E.2a Neo4j agrees with spine after upload",
          is_array($js) && isset($js[0]['sample_id_name']) && $js[0]['sample_id_name'] === 'CHAOS-RA-e2a');

    // Changelog ordering: modal row (samples_api) then upload row (field),
    // both appended after the pre-test high-water mark.
    $rows = $db->get_results_prepared(
        "SELECT pkey, change_type, source_subsystem FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND pkey > $3 ORDER BY pkey",
        array((string)$richAId, $ownerPkey, $maxPkeyBefore));
    $sources = array();
    foreach ((array)$rows as $r) $sources[] = $r->source_subsystem;
    $modalIx = array_search('samples_api', $sources);
    $fieldIx = -1;
    foreach ($sources as $ix => $s) if ($s === 'field') $fieldIx = $ix;  // last field row
    check("E.2a changelog has both rows in order (modal before upload)",
          $modalIx !== false && $fieldIx >= 0 && $modalIx < $fieldIx);
    note("changelog sources since mark: " . implode(',', $sources));

    // =====================================================================
    // E.2b — concurrent chaos: upload + modal edit fired simultaneously.
    // Hard-assert: single rows, both changelog rows, spine value belongs to
    // one writer (no torn cross-field mix). Report cross-store agreement.
    // =====================================================================
    echo "\n=== E.2b: modal edit DURING upload (true race, 3 rounds) ===\n";

    $driftRounds = 0;
    for ($iter = 1; $iter <= 3; $iter++) {
        $maxPkeyBefore = (int)$db->get_var_prepared(
            "SELECT COALESCE(max(pkey),0) FROM strabosamples.sample_changelog WHERE sample_id=$1",
            array((string)$richAId));

        $ts = (int)(microtime(true) * 1000);
        $upName  = "CHAOS-RA-e2b{$iter}up";
        $modName = "CHAOS-RA-e2b{$iter}mod";

        $hUp  = curlAsync('POST', '/db/projectdatasetsspots',
            buildFieldPayload($projectId, $datasetId, array(
                array('id' => $richAId, 'name' => "ChaosRichA e2b$iter", 'coords' => array(-101.1, 35.1), 'rich' => true,
                      'sample' => array('id' => (string)$richAId, 'sample_id_name' => $upName,
                                        'sample_description' => "upload desc e2b$iter",
                                        'material_type' => 'intact_rock', 'main_sampling_purpose' => 'petrology')),
            ), $ts), $ownerAuth);
        $hMod = curlAsync('POST', '/samples_edit.php', json_encode(array(
            'sample_id'   => (string)$richAId,
            'owner_pkey'  => $ownerPkey,
            'name'        => $modName,
            'description' => "modal desc e2b$iter",
        )), array('sid' => $ownerSid));

        $rUp  = curlWait($hUp);
        $rMod = curlWait($hMod);
        note("iter $iter HTTP: upload={$rUp['status']} modal={$rMod['status']}");
        check("E.2b iter $iter: both surfaces returned 2xx",
              $rUp['status'] >= 200 && $rUp['status'] < 300 && $rMod['status'] === 200);

        $bad = pgSingletons($db, $ownerPkey, array((string)$richAId));
        check("E.2b iter $iter: single-row invariants hold", empty($bad));
        if (!empty($bad)) note("VIOLATIONS: " . implode('; ', $bad));

        // No torn write WITHIN the spine: (name, description) must both
        // come from the same writer.
        $row = $db->get_row_prepared(
            "SELECT name, description FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array((string)$richAId, $ownerPkey));
        $fromUpload = $row && $row->name === $upName  && $row->description === "upload desc e2b$iter";
        $fromModal  = $row && $row->name === $modName && $row->description === "modal desc e2b$iter";
        check("E.2b iter $iter: spine is wholly one writer's (no torn mix)", $fromUpload || $fromModal);
        if (!$fromUpload && !$fromModal && $row) note("TORN: name='{$row->name}' desc='{$row->description}'");

        // Both changelog rows landed.
        $n = (int)$db->get_var_prepared(
            "SELECT count(DISTINCT source_subsystem) FROM strabosamples.sample_changelog
              WHERE sample_id=$1 AND sample_userpkey=$2 AND pkey > $3
                AND source_subsystem IN ('field','samples_api')",
            array((string)$richAId, $ownerPkey, $maxPkeyBefore));
        check("E.2b iter $iter: changelog captured both writers", $n === 2);

        // Cross-store agreement — informational (the modal→writeback and
        // upload→sync paths cross in opposite directions; divergence here
        // is the documented §10.4 drift window, healed by the next write).
        $jsRaw = (string)$neodb->get_var("MATCH (s:Spot {id:$richAId, userpkey:$ownerPkey}) RETURN s.json_samples");
        $js = json_decode($jsRaw, true);
        $neoName = is_array($js) && isset($js[0]['sample_id_name']) ? $js[0]['sample_id_name'] : '(unreadable)';
        if ($row && $neoName !== $row->name) {
            $driftRounds++;
            note("iter $iter cross-store DRIFT: pg='{$row->name}' neo4j='$neoName' (self-heals on next write)");
        } else {
            note("iter $iter cross-store agreement: '$neoName'");
        }
    }
    note("cross-store drift observed in $driftRounds/3 rounds (informational)");

    // =====================================================================
    // E.3 — migration --apply re-run while a 120-spot upload is in flight.
    // =====================================================================
    echo "\n=== E.3: migration --apply during a 120-spot upload ===\n";

    // Round 1: migration starts FIRST (it runs ~40-50s; the upload lands
    // mid-extraction).
    $hMig = phpAsync('/srv/app/www/samplesdb/migration/run.php --apply');
    usleep(2000000);  // let the migration get into its Field extraction phase

    $ts = (int)(microtime(true) * 1000);
    $rUp = curlWait(curlAsync('POST', '/db/projectdatasetsspots',
        buildFieldPayload($projectId, $datasetId, e3Spots('e3'), $ts), $ownerAuth));
    note("upload HTTP status: {$rUp['status']}");
    check("E.3 upload during migration returned 2xx", $rUp['status'] >= 200 && $rUp['status'] < 300);

    $mig = phpWait($hMig);
    note("migration exit code: {$mig['exit']}");
    check("E.3 migration exited 0", $mig['exit'] === 0);
    $migTail = implode("\n", array_slice(explode("\n", trim($mig['out'])), -12));
    note("migration tail:\n$migTail");

    // Final-state invariants across every uploaded sample.
    $e3SampleIds = array_map('strval', $e3Ids);
    $bad = pgSingletons($db, $ownerPkey, $e3SampleIds);
    check("E.3 all 120 samples: single-row + single-link invariants", empty($bad));
    if (!empty($bad)) note("VIOLATIONS (first 10): " . implode('; ', array_slice($bad, 0, 10)));

    // Round 2: a SECOND full --apply with the upload already at rest must
    // be a clean no-op for our rows (idempotency after the chaos).
    $mig2 = phpWait(phpAsync('/srv/app/www/samplesdb/migration/run.php --apply'));
    check("E.3 follow-up migration exited 0", $mig2['exit'] === 0);
    $bad = pgSingletons($db, $ownerPkey, $e3SampleIds);
    check("E.3 follow-up migration kept all invariants", empty($bad));

    $n = (int)$db->get_var("SELECT count(*) FROM strabosamples.samples WHERE id LIKE '95555%'");
    check("E.3 total chaos sample rows is exactly " . (3 + $E3_COUNT), $n === 3 + $E3_COUNT);

    // =====================================================================
    // E.4 — backfill_field_geom --apply while an upload rewrites the same
    // rows with NEW coordinates.
    // =====================================================================
    echo "\n=== E.4: geom backfill during a re-upload of the same spots ===\n";

    // Simulate pre-fix rows: NULL out lat/lng on the first 30 E.3 samples.
    $e4Ids = array_slice($e3SampleIds, 0, $E4_COUNT);
    $db->prepare_query(
        "UPDATE strabosamples.samples SET latitude=NULL, longitude=NULL WHERE id = ANY($1)",
        array('{' . implode(',', $e4Ids) . '}'));
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id = ANY($1) AND latitude IS NULL",
        array('{' . implode(',', $e4Ids) . '}'));
    check("E.4 seeded $E4_COUNT NULL-geom candidate rows", $n === $E4_COUNT);

    // Race: backfill scans/applies while the upload rewrites the same rows
    // with shifted coordinates (+0.5 on both axes).
    $hBf = phpAsync('/srv/app/www/samplesdb/backfill/backfill_field_geom.php --apply');
    $ts = (int)(microtime(true) * 1000);
    $hUp = curlAsync('POST', '/db/projectdatasetsspots',
        buildFieldPayload($projectId, $datasetId, e3Spots('e4', 0.5), $ts), $ownerAuth);

    $rUp = curlWait($hUp);
    $bf  = phpWait($hBf);
    note("upload HTTP status: {$rUp['status']}, backfill exit: {$bf['exit']}");
    check("E.4 upload returned 2xx", $rUp['status'] >= 200 && $rUp['status'] < 300);
    check("E.4 backfill exited 0", $bf['exit'] === 0);
    $bfTail = implode("\n", array_slice(explode("\n", trim($bf['out'])), -6));
    note("backfill tail:\n$bfTail");

    // No NULL flicker: every candidate ends populated.
    $n = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples
          WHERE id = ANY($1) AND (latitude IS NULL OR longitude IS NULL)",
        array('{' . implode(',', $e4Ids) . '}'));
    check("E.4 no NULL lat/lng remains on any candidate", $n === 0);

    // No torn pairs: each row's (lat,lng) must be a coherent pair from ONE
    // writer — either the original coords or the +0.5-shifted upload coords.
    $torn = 0; $stale = 0; $fresh = 0;
    foreach ($e4Ids as $ix => $sid) {
        $row = $db->get_row_prepared(
            "SELECT latitude, longitude FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sid, $ownerPkey));
        if (!$row) { $torn++; continue; }
        $lat0 = 40.0 + ($ix * 0.01);          $lng0 = -110.0 + ($ix * 0.01);
        $isOld = abs((float)$row->latitude - $lat0) < 1e-6        && abs((float)$row->longitude - $lng0) < 1e-6;
        $isNew = abs((float)$row->latitude - ($lat0 + 0.5)) < 1e-6 && abs((float)$row->longitude - ($lng0 + 0.5)) < 1e-6;
        if ($isNew)      $fresh++;
        elseif ($isOld)  $stale++;
        else             $torn++;
    }
    check("E.4 every pair is coherent (no torn lat/lng mix)", $torn === 0);
    note("pair outcomes: fresh-upload=$fresh stale-backfill=$stale torn=$torn (stale is the documented scan-window race; informational)");

    // The upload re-mirrors geometry on every spine write, so even a
    // stale-backfill row must converge after one more upload sweep.
    if ($stale > 0) {
        $ts = (int)(microtime(true) * 1000);
        $rUp = curlWait(curlAsync('POST', '/db/projectdatasetsspots',
            buildFieldPayload($projectId, $datasetId, e3Spots('e4heal', 0.5), $ts), $ownerAuth));
        $n = 0;
        foreach ($e4Ids as $ix => $sid) {
            $row = $db->get_row_prepared(
                "SELECT latitude FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                array($sid, $ownerPkey));
            $lat0 = 40.0 + ($ix * 0.01);
            if ($row && abs((float)$row->latitude - ($lat0 + 0.5)) < 1e-6) $n++;
        }
        check("E.4 one more upload sweep heals all stale rows", $n === $E4_COUNT);
    }

} finally {
    chaosCleanup($db, $neodb, $ownerPkey, $NEO_ID_MIN, $NEO_ID_MAX);
    foreach ($sessionFiles as $f) @unlink($f);
}

// -------------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------------

echo "\n=================================================\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
}
echo "RESULT: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
