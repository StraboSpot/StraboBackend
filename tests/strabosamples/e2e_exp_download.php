<?php
/**
 * File: e2e_exp_download.php
 * Description: Curl-level END-TO-END coverage of the StraboExperimental
 *              download endpoints, proving each one applies the
 *              strabosamples.* spine overlay at download time *through the
 *              live session-gated HTTP endpoint* (not just the library
 *              helper). Closes the Phase I gap: smoke_test_exp_export_-
 *              readthrough.php exercises experimental_sample_overlay_apply()
 *              directly on decoded JSON (0 HTTP), so nothing proved the four
 *              endpoints actually decode the experiment.json, call the
 *              overlay with the right owner pkey, and emit the result.
 *
 *              All four download endpoints are session-gated
 *              (session_start() + $_SESSION['loggedin']=="yes" + userpkey),
 *              so this uses the forged-session harness from Phase C's
 *              smoke_test_auth_matrix.php (write a PHP session file, pass the
 *              matching PHPSESSID cookie to curl).
 *
 *                A. GET /experimental/api/download_experiment.php?id={exp}
 *                   (JSON) — owner sees spine overlay on the embedded sample
 *                B. download_experiment.php anonymous, private project → 404
 *                   (anonymous PUBLIC reads allowed since 2026-08-05 —
 *                   see tests/experimental/smoke_test_exp_public_anonymous.php)
 *                C. download_experiment.php as stranger, PRIVATE project → 404
 *                D. GET /experimental/api/download_project.php?id={proj}
 *                   (JSON) — batch overlay across experiments; a second
 *                   experiment with NO spine row stays at its uploaded values
 *                E. download_experiment.php as stranger, PUBLIC project → 200
 *                   + overlay still applied (public-read path)
 *                F. GET /experimental/api/download_pdf.php?id={exp} → 200 +
 *                   valid %PDF (overlay runs before render; render succeeds)
 *                G. download_pdf.php anonymous, private project → 404
 *                H. GET /experimental/api/download_project_pdf.php?id={proj}
 *                   → 200 + valid %PDF
 *                I. download_project_pdf.php as stranger, PRIVATE → 404
 *
 *              The JSON endpoints assert the exact overlaid values; the PDF
 *              endpoints share the identical overlay call on the same data,
 *              so they assert a valid PDF is produced (overlay didn't break
 *              the render + the session gate works). Deeper PDF text-grep is
 *              blocked until poppler-utils/pdftotext lands in the container
 *              (parking lot).
 *
 *              Hermetic: all fixtures under an 'e2eexp-' prefix; cleans up at
 *              start + in finally{}. Uses the test users from
 *              setup_test_data.php. Exits non-zero on any failure.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/e2e_exp_download.php
 *
 * @package    StraboExperimental / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

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
    if (!$pkey) {
        echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n";
        exit(1);
    }
    $users[$who] = $pkey;
}
$ownerPkey    = $users['owner'];
$strangerPkey = $users['outsider'];
echo "owner=$ownerPkey stranger=$strangerPkey\n\n";

// -------------------------------------------------------------------------
// Forged PHP sessions (same harness as smoke_test_auth_matrix.php)
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
// HTTP GET — optional PHPSESSID cookie (null = anonymous)
// -------------------------------------------------------------------------

function httpGet($path, $sid) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2eexp_body_');
    $args  = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    if ($sid !== null) $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body   = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

function isPdf($body) { return is_string($body) && strncmp($body, '%PDF', 4) === 0; }

// -------------------------------------------------------------------------
// Fixtures — 'e2eexp-' prefix
// -------------------------------------------------------------------------

$stamp        = time();
$sidA         = "e2eexp-sampleA-$stamp";    // spine-edited, in private project
$sidB         = "e2eexp-sampleB-$stamp";    // spine-edited, in public project
$sidNoSpine   = "e2eexp-nospine-$stamp";    // NO spine row — batch no-op control
$projPrivUuid = "e2eexp-projpriv-$stamp";
$projPubUuid  = "e2eexp-projpub-$stamp";
$expPriv1Uuid = "e2eexp-exppriv1-$stamp";
$expPriv2Uuid = "e2eexp-exppriv2-$stamp";
$expPubUuid   = "e2eexp-exppub-$stamp";

// experiment.json with an embedded sample block carrying ORIGINAL (pre-edit)
// values, so a successful overlay is observable as a change.
function expJson($straboId, $expName) {
    return json_encode(array(
        'experiment' => array('name' => $expName, 'title' => $expName, 'description' => 'e2e experiment'),
        'facility'   => array('name' => 'E2E Facility'),
        'sample'     => array(
            'strabo_id'   => $straboId,
            'id'          => 'human-' . $straboId,
            'name'        => 'OriginalUploadName',
            'igsn'        => 'IGSN-ORIGINAL',
            'description' => 'original upload description',
            'material'    => array(
                'material'   => array('type' => 'igneous', 'name' => 'granite'),
                'provenance' => array('location' => array('latitude' => '40.0', 'longitude' => '-100.0')),
            ),
        ),
    ));
}

function seedSpine($db, $id, $userpkey, $name, $igsn, $desc, $lat, $lng, $type) {
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, igsn, description, latitude, longitude,
             display_sample_type, created_by, modified_by, created_at, modified_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$2,$2,NOW(),NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET
            name=$3, igsn=$4, description=$5, latitude=$6, longitude=$7,
            display_sample_type=$8, modified_at=NOW()",
        array($id, (int)$userpkey, $name, $igsn, $desc, $lat, $lng, $type));
}

function expCleanup($db) {
    $db->query("DELETE FROM strabosamples.samples WHERE id LIKE 'e2eexp-%'");
    $db->query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'e2eexp-%'");
    $db->query("DELETE FROM straboexp.project WHERE uuid LIKE 'e2eexp-%'");
}

expCleanup($db);

try {
    // ---------------------------------------------------------------------
    // Seed: two projects (private + public, both owner), three experiments,
    // two spine edits (A, B). expPriv2's sample (sidNoSpine) has NO spine row.
    // ---------------------------------------------------------------------
    $db->prepare_query("INSERT INTO straboexp.project (userpkey, uuid, name, ispublic) VALUES ($1,$2,'E2E Exp Private',FALSE)", array($ownerPkey, $projPrivUuid));
    $db->prepare_query("INSERT INTO straboexp.project (userpkey, uuid, name, ispublic) VALUES ($1,$2,'E2E Exp Public', TRUE)",  array($ownerPkey, $projPubUuid));
    $projPrivPkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.project WHERE uuid=$1", array($projPrivUuid));
    $projPubPkey  = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.project WHERE uuid=$1", array($projPubUuid));

    $db->prepare_query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid, json) VALUES ($1,$2,'e2e-exp-priv-1',$3,$4)",
        array($projPrivPkey, $ownerPkey, $expPriv1Uuid, expJson($sidA, 'E2E Priv Exp 1')));
    $db->prepare_query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid, json) VALUES ($1,$2,'e2e-exp-priv-2',$3,$4)",
        array($projPrivPkey, $ownerPkey, $expPriv2Uuid, expJson($sidNoSpine, 'E2E Priv Exp 2')));
    $db->prepare_query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid, json) VALUES ($1,$2,'e2e-exp-pub',$3,$4)",
        array($projPubPkey, $ownerPkey, $expPubUuid, expJson($sidB, 'E2E Pub Exp')));
    $expPriv1Pkey = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.experiment WHERE uuid=$1", array($expPriv1Uuid));
    $expPubPkey   = (int)$db->get_var_prepared("SELECT pkey FROM straboexp.experiment WHERE uuid=$1", array($expPubUuid));

    // Spine edits (sampleNoSpine deliberately omitted).
    seedSpine($db, $sidA, $ownerPkey, 'EditedNameA', 'IGSN-NEW-A', 'edited via Samples app A', 45.5, -120.5, 'tephra');
    seedSpine($db, $sidB, $ownerPkey, 'EditedNameB', 'IGSN-NEW-B', 'edited via Samples app B', 33.3,  -90.0, 'sediment');

    $sidOwner    = forgeSession($ownerPkey);
    $sidStranger = forgeSession($strangerPkey);

    $epExp  = "/experimental/api/download_experiment.php?id=$expPriv1Pkey";
    $epProj = "/experimental/api/download_project.php?id=$projPrivPkey";

    // =====================================================================
    // A — download_experiment.php (JSON) as owner → spine overlay applied
    // =====================================================================
    echo "=== A: download_experiment.php (JSON) — owner sees spine overlay ===\n";
    $r = httpGet($epExp, $sidOwner);
    note("HTTP {$r['status']}");
    $s = is_array($r['json']) && isset($r['json']['sample']) ? $r['json']['sample'] : null;
    check("A: 200 + JSON sample block present",          $r['status'] === 200 && $s !== null);
    check("A: sample.name overlaid ('EditedNameA')",     $s && $s['name'] === 'EditedNameA');
    check("A: sample.igsn overlaid ('IGSN-NEW-A')",      $s && $s['igsn'] === 'IGSN-NEW-A');
    check("A: sample.description overlaid",              $s && $s['description'] === 'edited via Samples app A');
    check("A: material.material.type overlaid ('tephra')", $s && isset($s['material']['material']['type']) && $s['material']['material']['type'] === 'tephra');
    check("A: material.material.name UNCHANGED ('granite')", $s && isset($s['material']['material']['name']) && $s['material']['material']['name'] === 'granite');
    check("A: provenance.location.latitude overlaid",    $s && (string)$s['material']['provenance']['location']['latitude'] === '45.5');
    check("A: provenance.location.longitude overlaid",   $s && (string)$s['material']['provenance']['location']['longitude'] === '-120.5');

    // =====================================================================
    // B — anonymous on a PRIVATE project → 404 (existence hidden; public
    // reads are anonymous-allowed since 2026-08-05)
    // =====================================================================
    echo "\n=== B: download_experiment.php anonymous, private → 404 ===\n";
    $r = httpGet($epExp, null);
    note("HTTP {$r['status']}");
    check("B: anonymous denied on private experiment (404)", $r['status'] === 404);

    // =====================================================================
    // C — stranger on a PRIVATE project → 404 (existence hidden)
    // =====================================================================
    echo "\n=== C: download_experiment.php stranger, private project → 404 ===\n";
    $r = httpGet($epExp, $sidStranger);
    note("HTTP {$r['status']}");
    check("C: stranger denied on private experiment (404)", $r['status'] === 404);

    // =====================================================================
    // D — download_project.php (JSON) as owner → batch overlay; the
    //     no-spine experiment keeps its uploaded values (conservative no-op)
    // =====================================================================
    echo "\n=== D: download_project.php (JSON) — batch overlay + no-spine no-op ===\n";
    $r = httpGet($epProj, $sidOwner);
    note("HTTP {$r['status']}");
    $exps = (is_array($r['json']) && isset($r['json']['project']['experiments'])) ? $r['json']['project']['experiments'] : null;
    check("D: 200 + project.experiments array", $r['status'] === 200 && is_array($exps) && count($exps) === 2);
    $byId = array();
    if (is_array($exps)) foreach ($exps as $e) { if (isset($e['sample']['strabo_id'])) $byId[$e['sample']['strabo_id']] = $e['sample']; }
    check("D: edited experiment's sample overlaid ('EditedNameA')",  isset($byId[$sidA]) && $byId[$sidA]['name'] === 'EditedNameA');
    check("D: edited experiment material.type overlaid ('tephra')",  isset($byId[$sidA]['material']['material']['type']) && $byId[$sidA]['material']['material']['type'] === 'tephra');
    check("D: no-spine experiment keeps uploaded name",              isset($byId[$sidNoSpine]) && $byId[$sidNoSpine]['name'] === 'OriginalUploadName');
    check("D: no-spine experiment keeps uploaded type ('igneous')",  isset($byId[$sidNoSpine]['material']['material']['type']) && $byId[$sidNoSpine]['material']['material']['type'] === 'igneous');

    // =====================================================================
    // E — PUBLIC project read by a stranger → 200 + overlay still applied
    // =====================================================================
    echo "\n=== E: download_experiment.php stranger, PUBLIC project → 200 + overlay ===\n";
    $r = httpGet("/experimental/api/download_experiment.php?id=$expPubPkey", $sidStranger);
    note("HTTP {$r['status']}");
    $s = is_array($r['json']) && isset($r['json']['sample']) ? $r['json']['sample'] : null;
    check("E: public experiment readable by stranger (200)", $r['status'] === 200 && $s !== null);
    check("E: overlay applied on public read ('EditedNameB')", $s && $s['name'] === 'EditedNameB');
    check("E: public-read igsn overlaid ('IGSN-NEW-B')",       $s && $s['igsn'] === 'IGSN-NEW-B');

    // =====================================================================
    // F — download_pdf.php as owner → 200 + valid %PDF
    // =====================================================================
    echo "\n=== F: download_pdf.php — owner → valid PDF ===\n";
    $r = httpGet("/experimental/api/download_pdf.php?id=$expPriv1Pkey", $sidOwner);
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes, magic=" . substr($r['body'], 0, 4));
    check("F: 200",                       $r['status'] === 200);
    check("F: body is a valid PDF (%PDF)", isPdf($r['body']));
    check("F: PDF is non-trivial (>1KB)",  strlen($r['body']) > 1024);

    // =====================================================================
    // G — download_pdf.php anonymous on a PRIVATE project → 404
    // =====================================================================
    echo "\n=== G: download_pdf.php anonymous, private → 404 ===\n";
    $r = httpGet("/experimental/api/download_pdf.php?id=$expPriv1Pkey", null);
    note("HTTP {$r['status']}");
    check("G: anonymous PDF denied on private experiment (404)", $r['status'] === 404);

    // =====================================================================
    // H — download_project_pdf.php as owner → 200 + valid %PDF
    // =====================================================================
    echo "\n=== H: download_project_pdf.php — owner → valid PDF ===\n";
    $r = httpGet("/experimental/api/download_project_pdf.php?id=$projPrivPkey", $sidOwner);
    note("HTTP {$r['status']}, " . strlen($r['body']) . " bytes, magic=" . substr($r['body'], 0, 4));
    check("H: 200",                       $r['status'] === 200);
    check("H: body is a valid PDF (%PDF)", isPdf($r['body']));
    check("H: PDF is non-trivial (>1KB)",  strlen($r['body']) > 1024);

    // =====================================================================
    // I — download_project_pdf.php stranger, PRIVATE → 404
    // =====================================================================
    echo "\n=== I: download_project_pdf.php stranger, private → 404 ===\n";
    $r = httpGet("/experimental/api/download_project_pdf.php?id=$projPrivPkey", $sidStranger);
    note("HTTP {$r['status']}");
    check("I: stranger denied on private project PDF (404)", $r['status'] === 404);

} finally {
    expCleanup($db);
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
