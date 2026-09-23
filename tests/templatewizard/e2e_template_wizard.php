<?php
/**
 * File: e2e_template_wizard.php
 * Description: Curl-level E2E for the Template Wizard — drives the real
 *              session-gated pages over HTTP (forged-session harness, the
 *              Phase C technique) and asserts results at the DB layer.
 *
 *              Coverage:
 *                1. Anonymous gates: wizard pages 302 -> /login.php
 *                2. Template CRUD over ajax.php + stranger isolation
 *                3. Template file downloads (XLSX magic, CSV BOM+headers)
 *                4. Full import: upload CSV -> target -> plan -> resolutions
 *                   -> confirm -> spots/dataset/journal asserted in DB
 *                5. Dirty-plan confirm refused (nothing committed)
 *                6. Vocab __other__ resolution over HTTP
 *                7. Designer page config (GET saved / POST new sections),
 *                   stranger isolation, stage door removed
 *                8. Export -> re-upload -> "embedded template recognized"
 *                   -> all-unchanged plan (round trip over HTTP)
 *               8b. Exported workbook carries lat/lng on every row; "Import
 *                   as new spots" checkbox copies an export into a new
 *                   dataset (3 creates, originals untouched); off = refused
 *               8c. Polygon spot: export materializes geometry_type +
 *                   geometry_wkt, round trip all-unchanged, as-new copy
 *                   lands as a Polygon under a new id
 *                9. Stranger isolation: foreign spot id, foreign dataset
 *                   export, foreign ajax datasets
 *               10. Cancel kills the review token
 *               11. Designer-shaped template -> blank XLSX -> rows typed with
 *                   PHPExcel -> upload -> Neo4j + spine asserts -> export == noop
 *               12. Exported XLSX edited (cell edits + new row) -> updates + create
 *               13. How-to example workbook imports as its page documents
 *               (11 also: same id-less file twice -> name-collision Heads up;
 *                success page's download-with-ids re-imports as unchanged)
 *
 *              Hermetic: sentinel project 96669002, template prefix
 *              e2ewiz-<stamp>; cleanup in finally; residue checks.
 *
 *              Usage (inside the container — writes forged session files):
 *                docker exec strabo-php php /srv/app/www/tests/templatewizard/e2e_template_wizard.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/geophp/geoPHP.inc';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/strabospotclass.php';
require_once '/srv/app/www/TemplateWizard/services/FieldTabularService.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

// -------------------------------------------------------------------------
// Users (from tests/collaboration/setup_test_data.php)
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
// Forged sessions + HTTP helpers
// -------------------------------------------------------------------------
$sessionDir   = '/var/lib/php/sessions';
$sessionFiles = array();
$tmpFiles     = array();

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

function curlRun($args) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'e2ewiz_body_');
    $hdrFile  = tempnam(sys_get_temp_dir(), 'e2ewiz_hdr_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -D " . escapeshellarg($hdrFile)
         . " -w '%{http_code}' $args";
    $status = (int)shell_exec($cmd);
    $body   = file_get_contents($bodyFile);
    $location = '';
    foreach (explode("\n", (string)file_get_contents($hdrFile)) as $line) {
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    @unlink($bodyFile); @unlink($hdrFile);
    return array('status' => $status, 'body' => $body, 'location' => $location);
}

function httpGet($path, $sid) {
    $args = ($sid !== null) ? ("-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ") : '';
    return curlRun($args . escapeshellarg("http://localhost$path"));
}

function httpPostFile($path, $sid, $fields, $fileField, $file, $uploadName) {
    $args = "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    foreach ($fields as $k => $v) {
        $args .= "-F " . escapeshellarg("$k=$v") . " ";
    }
    $args .= "-F " . escapeshellarg("$fileField=@$file;filename=$uploadName") . " ";
    return curlRun($args . escapeshellarg("http://localhost$path"));
}

function httpPostForm($path, $sid, $data) {
    $body = http_build_query($data);
    $args = "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " "
          . "-H 'Content-Type: application/x-www-form-urlencoded' "
          . "--data-binary " . escapeshellarg($body) . " ";
    return curlRun($args . escapeshellarg("http://localhost$path"));
}

function extractToken($html) {
    if (preg_match('/name="token" value="([0-9a-f]{32})"/', $html, $m)) { return $m[1]; }
    return null;
}

function b64url($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function csvFile($csv) {
    global $tmpFiles;
    $path = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.csv';
    file_put_contents($path, $csv);
    $tmpFiles[] = $path;
    return $path;
}

function spotProps($neodb, $id, $upk) {
    $records = $neodb->get_results("MATCH (s:Spot {id: $id, userpkey: $upk}) RETURN s");
    if (!is_array($records) || !count($records)) { return null; }
    return $records[0]->get('s')->values();
}

// -------------------------------------------------------------------------
// Fixtures
// -------------------------------------------------------------------------
$PROJECT_ID = 96669002;
$stamp = time();
$tplName = "e2ewiz-$stamp";
$spotIds = array();
$datasetIds = array();

$strabo = new StraboSpot($neodb, $ownerPkey, $db);

$sidOwner    = forgeSession($ownerPkey);
$sidStranger = forgeSession($strangerPkey);

$SPEC = FieldTabularService::defaultSpec();

try {
    $neodb->createNode(json_encode(array(
        'userpkey' => $ownerPkey, 'id' => $PROJECT_ID,
        'desc_project_name' => "e2ewiz project $stamp",
        'modified_timestamp' => (int)round(microtime(true) * 1000),
    )), 'Project');

    // ------------------------------------------------------------------
    echo "=== 1. anonymous gates ===\n";
    // ------------------------------------------------------------------
    foreach (array('/TemplateWizard/', '/TemplateWizard/review.php', '/TemplateWizard/export.php',
                   '/TemplateWizard/design_template.php', '/TemplateWizard/ajax.php?action=templates') as $p) {
        $r = httpGet($p, null);
        check("anon $p -> login redirect",
            $r['status'] === 302 && strpos($r['location'], '/login.php') !== false);
    }

    // ------------------------------------------------------------------
    echo "\n=== 2. template CRUD over ajax ===\n";
    // ------------------------------------------------------------------
    $r = httpPostForm('/TemplateWizard/ajax.php', $sidOwner, array(
        'action' => 'save_template', 'name' => $tplName, 'spec_json' => json_encode($SPEC)));
    $res = json_decode($r['body'], true);
    check('template saves over HTTP', $r['status'] === 200 && !empty($res['ok']) && (int)$res['pkey'] > 0);
    $TPL = (int)$res['pkey'];

    $r = httpGet('/TemplateWizard/ajax.php?action=templates', $sidOwner);
    check('owner sees template in list', strpos($r['body'], $tplName) !== false);
    $r = httpGet('/TemplateWizard/ajax.php?action=templates', $sidStranger);
    check('stranger does not see it', strpos($r['body'], $tplName) === false);

    // ------------------------------------------------------------------
    echo "\n=== 3. template file downloads ===\n";
    // ------------------------------------------------------------------
    $r = httpGet("/TemplateWizard/export.php?what=template&template_id=$TPL&format=xlsx", $sidOwner);
    check('template xlsx: 200 + PK zip magic + >4KB',
        $r['status'] === 200 && substr($r['body'], 0, 4) === "PK\x03\x04" && strlen($r['body']) > 4096);
    $r = httpGet("/TemplateWizard/export.php?what=template&template_id=$TPL&format=csv", $sidOwner);
    check('template csv: BOM + headers',
        $r['status'] === 200 && substr($r['body'], 0, 3) === "\xEF\xBB\xBF"
        && strpos($r['body'], 'strabo_internal_id') !== false && strpos($r['body'], 'strike') !== false);
    // built-in Basic layout: no field_templates row, any user, blank + export
    $r = httpGet("/TemplateWizard/export.php?what=template&template_id=basic&format=csv", $sidStranger);
    check('basic blank csv: headers, no template row needed',
        $r['status'] === 200 && strpos($r['body'], 'strabo_internal_id') !== false
        && strpos($r['body'], 'strike') !== false && strpos($r['body'], 'orientation_type') !== false);
    $r = httpGet("/TemplateWizard/export.php?what=template&template_id=abc&format=csv", $sidOwner);
    check('non-numeric template id other than basic: Template not found',
        strpos($r['body'], 'Template not found') !== false);
    $r = httpGet("/TemplateWizard/export.php?what=template&template_id=$TPL&format=xlsx", $sidStranger);
    check("stranger cannot download owner's template",
        strpos($r['body'], 'Template not found') !== false || $r['status'] !== 200 || substr($r['body'], 0, 2) !== 'PK');

    // ------------------------------------------------------------------
    echo "\n=== 4. full import over HTTP (upload -> plan -> confirm) ===\n";
    // ------------------------------------------------------------------
    $csv = "strabo_internal_id,spot_name,latitude,longitude,notes,orientation_type,orientation_role,feature_type,strike,dip\n"
         . ",WZ-A,34.11,-118.11,first station,planar,,bedding,245,32\n"
         . ",WZ-A,,,,planar,,bedding,250,30\n"
         . ",WZ-B,34.12,-118.12,second station,,,,,\n";
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', csvFile($csv), 'import.csv');
    $token = extractToken($r['body']);
    check('upload renders target screen + token',
        $r['status'] === 200 && $token !== null && strpos($r['body'], '3 data rows') !== false);

    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array(
        'action' => 'plan', 'token' => $token,
        'project_id' => $PROJECT_ID, 'dataset_choice' => 'new', 'dataset_name' => "e2ewiz DS $stamp"));
    check('plan renders review: 2 new spots, clean',
        strpos($r['body'], '2 new spots') !== false && strpos($r['body'], 'Confirm &amp; Import') !== false);

    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array(
        'action' => 'confirm', 'token' => $token,
        'project_id' => $PROJECT_ID, 'dataset_choice' => 'new', 'dataset_name' => "e2ewiz DS $stamp"));
    check('confirm reports Import complete', strpos($r['body'], 'Import complete') !== false);
    preg_match('/New dataset created \(id (\d+)\)/', $r['body'], $m);
    $DS = isset($m[1]) ? (int)$m[1] : 0;
    check('success page reveals new dataset id', $DS > 0);
    $datasetIds[] = $DS;

    $recs = $neodb->get_results("MATCH (d:Dataset {id: $DS, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN s.id AS id, s.name AS name");
    $byName = array();
    foreach ((array)$recs as $rec) {
        $byName[$rec->value('name')] = (int)$rec->value('id');
        $spotIds[] = (int)$rec->value('id');
    }
    check('2 spots landed in the new dataset', count($byName) === 2 && isset($byName['WZ-A'], $byName['WZ-B']));
    $A = $byName['WZ-A'];
    $pa = spotProps($neodb, $A, $ownerPkey);
    $od = json_decode($pa['json_orientation_data'], true);
    check('WZ-A carries 2 orientations over HTTP path', is_array($od) && count($od) === 2 && $od[0]['strike'] === 245);
    $run = $db->get_row_prepared(
        "SELECT status FROM field_tabular_runs WHERE userpkey = $1 AND project_id = $2 ORDER BY pkey DESC LIMIT 1",
        array($ownerPkey, (string)$PROJECT_ID));
    check('journal row committed', $run && $run->status === 'committed');
    check('review token consumed after commit', extractToken(httpPostForm('/TemplateWizard/review.php', $sidOwner,
        array('action' => 'plan', 'token' => $token, 'project_id' => $PROJECT_ID,
              'dataset_choice' => 'existing', 'dataset_id' => $DS))['body']) === null);

    // ------------------------------------------------------------------
    echo "\n=== 5. dirty-plan confirm refused ===\n";
    // ------------------------------------------------------------------
    $csv = "strabo_internal_id,spot_name,latitude,longitude,orientation_type,feature_type,strike,dip\n"
         . ",WZ-DIRTY,34.5,-118.5,planar,wibbly wobbly,100,45\n";
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', csvFile($csv), 'dirty.csv');
    $dirtyToken = extractToken($r['body']);
    $target = array('project_id' => $PROJECT_ID, 'dataset_choice' => 'existing', 'dataset_id' => $DS);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner,
        array_merge(array('action' => 'confirm', 'token' => $dirtyToken), $target));
    check('confirm without resolutions refused',
        strpos($r['body'], 'unresolved issues') !== false && strpos($r['body'], 'Import complete') === false);
    $cnt = (int)$neodb->get_var("MATCH (s:Spot {userpkey: $ownerPkey}) WHERE s.name = 'WZ-DIRTY' RETURN count(s)");
    check('nothing committed by refused confirm', $cnt === 0);

    // ------------------------------------------------------------------
    echo "\n=== 6. vocab __other__ resolution over HTTP ===\n";
    // ------------------------------------------------------------------
    $gfB64 = b64url('orientation.feature_type');
    $rawB64 = b64url('wibbly wobbly');
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array(
        'action' => 'confirm', 'token' => $dirtyToken,
        'vocab_res' => array($gfB64 => array($rawB64 => '__other__')),
    ), $target));
    check('resolved confirm imports', strpos($r['body'], 'Import complete') !== false);
    $recs = $neodb->get_results("MATCH (s:Spot {userpkey: $ownerPkey}) WHERE s.name = 'WZ-DIRTY' RETURN s");
    check('WZ-DIRTY exists', is_array($recs) && count($recs) === 1);
    if (is_array($recs) && count($recs)) {
        $props = $recs[0]->get('s')->values();
        $spotIds[] = (int)$props['id'];
        $od = json_decode($props['json_orientation_data'], true);
        check('feature_type=other + literal companion stored',
            is_array($od) && $od[0]['feature_type'] === 'other' && $od[0]['other_feature'] === 'wibbly wobbly');
    } else {
        check('feature_type=other + literal companion stored', false);
    }

    // ------------------------------------------------------------------
    echo "
=== 7. designer page (column list builder) + stage door gone ===
";
    // ------------------------------------------------------------------
    $r = httpGet("/TemplateWizard/design_template.php?template_id=$TPL", $sidOwner);
    check('designer GET template_id: 200 + name + spec columns in page config',
        $r['status'] === 200 && strpos($r['body'], 'window.twDesigner') !== false
        && strpos($r['body'], json_encode($tplName)) !== false
        && strpos($r['body'], '"header":"strabo_internal_id"') !== false
        && strpos($r['body'], '{"kind":"field","group":"spot","name":"name"') !== false);
    check('designer page has no grid library', strpos($r['body'], 'handsontable') === false && strpos($r['body'], 'xlsx.full') === false);
    $r = httpPostForm('/TemplateWizard/design_template.php', $sidOwner, array(
        'template_method' => 'new', 'selected_sections' => array('spot', 'sample')));
    // the page config also embeds the whole catalog, so judge the columns segment only
    $colsSeg = preg_match('/"columns":(\[.*?\]),"catalog"/s', $r['body'], $m) ? $m[1] : '';
    check('designer POST new + sections: sample columns seeded, no orientation',
        $r['status'] === 200 && strpos($colsSeg, '"header":"sample_type"') !== false
        && strpos($colsSeg, '"header":"strike"') === false && strpos($colsSeg, '"header":"orientation_type"') === false);
    $r = httpGet("/TemplateWizard/design_template.php?template_id=$TPL", $sidStranger);
    check("stranger opening owner's template gets a fresh new template, not its columns",
        $r['status'] === 200 && strpos($r['body'], json_encode($tplName)) === false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array(
        'action' => 'stage', 'grid_json' => json_encode(array(array('strabo_internal_id', 'spot_name'), array((string)$A, 'WZ-A'))),
        'spec_json' => json_encode($SPEC), 'template_name' => $tplName, 'project_id' => $PROJECT_ID));
    check('review.php action=stage no longer stages anything',
        extractToken($r['body']) === null && strpos($r['body'], 'designer grid') === false);

    // ------------------------------------------------------------------
    echo "\n=== 8. export -> re-upload round trip over HTTP ===\n";
    // ------------------------------------------------------------------
    $r = httpGet("/TemplateWizard/export.php?what=export&dataset_id=$DS&template_id=$TPL&format=xlsx", $sidOwner);
    check('export xlsx: 200 + zip magic', $r['status'] === 200 && substr($r['body'], 0, 4) === "PK\x03\x04");
    $exPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $exPath;
    file_put_contents($exPath, $r['body']);

    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $exPath, 'export_roundtrip.xlsx');
    $rtToken = extractToken($r['body']);
    check('re-upload recognizes embedded template',
        $rtToken !== null && strpos($r['body'], 'embedded template recognized') !== false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner,
        array_merge(array('action' => 'plan', 'token' => $rtToken), $target));
    check('round trip plans clean over HTTP',
        strpos($r['body'], '0 new spots') !== false && strpos($r['body'], '0 updated') !== false
        && strpos($r['body'], '3 unchanged') !== false);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $rtToken));

    // same round trip through the built-in Basic layout (the export page default)
    $r = httpGet("/TemplateWizard/export.php?what=export&dataset_id=$DS&template_id=basic&format=xlsx", $sidOwner);
    check('basic export xlsx: 200 + zip magic', $r['status'] === 200 && substr($r['body'], 0, 4) === "PK\x03\x04");
    $bxPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $bxPath;
    file_put_contents($bxPath, $r['body']);
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $bxPath, 'basic_roundtrip.xlsx');
    $bxToken = extractToken($r['body']);
    check('basic re-upload recognizes embedded template',
        $bxToken !== null && strpos($r['body'], 'embedded template recognized') !== false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner,
        array_merge(array('action' => 'plan', 'token' => $bxToken), $target));
    check('basic round trip plans clean over HTTP',
        strpos($r['body'], '0 new spots') !== false && strpos($r['body'], '0 updated') !== false
        && strpos($r['body'], '3 unchanged') !== false);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $bxToken));
    // explicit Basic choice on upload (matched by header, no embedded spec)
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload', 'template_pkey' => 'basic'),
                      'tabfile', csvFile("strabo_internal_id,spot_name\n$A,WZ-A\n"), 'basic_choice.csv');
    $bcToken = extractToken($r['body']);
    check('upload with template_pkey=basic reaches the target step', $bcToken !== null);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $bcToken));

    // ------------------------------------------------------------------
    echo "\n=== 8b. coordinates on every export row + Import as new spots over HTTP ===\n";
    // ------------------------------------------------------------------
    // The Basic export of DS (4 rows: WZ-A x2, WZ-B, WZ-DIRTY from section 6): latitude/longitude
    // filled on every data row, not just a spot's first (Joe, JCU 2026-09-23).
    if (!class_exists('PHPExcel')) { require_once '/srv/app/www/PHPExcel.php'; }
    $wbX = PHPExcel_IOFactory::load($bxPath);
    $shX = $wbX->getSheetByName('Data');
    $hdrX = array();
    for ($c = 0; $c < 60; $c++) {
        $h = (string)$shX->getCellByColumnAndRow($c, 2)->getValue();
        if ($h === '') { break; }
        $hdrX[$h] = $c;
    }
    $xRows = 0; $xCoordRows = 0;
    for ($rw = 3; $rw < 60; $rw++) {
        $nm = (string)$shX->getCellByColumnAndRow($hdrX['name'], $rw)->getValue();
        if ($nm === '') { break; }
        $xRows++;
        $la = (string)$shX->getCellByColumnAndRow($hdrX['latitude'], $rw)->getValue();
        $lo = (string)$shX->getCellByColumnAndRow($hdrX['longitude'], $rw)->getValue();
        $expAll = array('WZ-A' => array(34.11, -118.11), 'WZ-B' => array(34.12, -118.12), 'WZ-DIRTY' => array(34.5, -118.5));
        $exp = isset($expAll[$nm]) ? $expAll[$nm] : array(0, 0);
        if ($la !== '' && $lo !== '' && abs((float)$la - $exp[0]) < 0.0001 && abs((float)$lo - $exp[1]) < 0.0001) { $xCoordRows++; }
    }
    check('exported workbook carries the coordinates on every data row (4/4)', $xRows === 4 && $xCoordRows === 4);

    // Same export, ids and all, pointed at a NEW dataset with the checkbox:
    // 3 fresh spots there, the originals untouched.
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $bxPath, 'student_export.xlsx');
    $anToken = extractToken($r['body']);
    check('target screen offers the Import as new spots checkbox',
        $anToken !== null && strpos($r['body'], 'name="as_new"') !== false && strpos($r['body'], 'Import as new spots') !== false);
    $anTarget = array('project_id' => $PROJECT_ID, 'dataset_choice' => 'new', 'dataset_name' => "e2ewiz MASTER $stamp", 'as_new' => '1');
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $anToken), $anTarget));
    check('as-new plan: 3 new spots, 0 updated, note shown, checkbox stays ticked',
        strpos($r['body'], '3 new spots') !== false && strpos($r['body'], '0 updated') !== false
        && strpos($r['body'], 'is on: every spot in this file will be created') !== false
        && preg_match('/name="as_new"[^>]*checked/', $r['body']) === 1
        && strpos($r['body'], 'Confirm &amp; Import') !== false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'confirm', 'token' => $anToken), $anTarget));
    check('as-new confirm: Import complete, 3 created', strpos($r['body'], 'Import complete') !== false
        && preg_match('/<strong>3<\/strong> spots created/', $r['body']) === 1);
    preg_match('/New dataset created \(id (\d+)\)/', $r['body'], $m);
    $DSM = isset($m[1]) ? (int)$m[1] : 0;
    check('as-new: master dataset created', $DSM > 0);
    $datasetIds[] = $DSM;
    $recsM = $neodb->get_results("MATCH (d:Dataset {id: $DSM, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN s.id AS id, s.name AS name, s.json_orientation_data AS od");
    $mNames = array(); $mIds = array(); $mOrient = 0;
    foreach ((array)$recsM as $rec) {
        $mNames[] = $rec->value('name'); $mIds[] = (int)$rec->value('id'); $spotIds[] = (int)$rec->value('id');
        $od = json_decode((string)$rec->value('od'), true);
        if (is_array($od)) { $mOrient += count($od); }
    }
    sort($mNames);
    check('as-new: copies WZ-A + WZ-B + WZ-DIRTY live in the master dataset with new ids and their 3 orientations',
        $mNames === array('WZ-A', 'WZ-B', 'WZ-DIRTY') && !in_array($A, $mIds) && $mOrient === 3);
    check('as-new: source dataset still holds exactly its own spots',
        (int)$neodb->get_var("MATCH (d:Dataset {id: $DS, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN count(s)") === 3);
    // Checkbox off: the same file into the master dataset is refused per spot,
    // and the error points at the checkbox.
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $bxPath, 'student_export.xlsx');
    $offToken = extractToken($r['body']);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'plan', 'token' => $offToken,
        'project_id' => $PROJECT_ID, 'dataset_choice' => 'existing', 'dataset_id' => $DSM));
    check('checkbox off: ids from another dataset are refused and the error names the option',
        strpos($r['body'], 'is not in the target dataset') !== false
        && strpos($r['body'], 'Import as new spots') !== false
        && strpos($r['body'], 'Confirm &amp; Import') === false);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $offToken));

    // ------------------------------------------------------------------
    echo "\n=== 8c. polygon spot survives export -> Import as new spots over HTTP (geometry_wkt) ===\n";
    // ------------------------------------------------------------------
    // Jason 2026-09-23: a polygon exported by the wizard and re-imported as
    // new spots came back as a Point. The export now carries geometry_wkt.
    $POLY_ID = 96669778;
    $spotIds[] = $POLY_ID;
    $polyFeature = json_encode(array(
        'type' => 'Feature',
        'geometry' => array('type' => 'Polygon', 'coordinates' => array(array(
            array(-118.6, 34.1), array(-118.5, 34.1), array(-118.5, 34.2), array(-118.6, 34.2), array(-118.6, 34.1)))),
        'properties' => array('id' => $POLY_ID, 'name' => 'WZ-POLY', 'modified_timestamp' => (int)round(microtime(true) * 1000)),
    ));
    $strabo->insertSpot($polyFeature);
    $strabo->addSpotToDataset($DS, $POLY_ID);
    check('polygon fixture spot stored as Polygon', spotProps($neodb, $POLY_ID, $ownerPkey)['geometrytype'] === 'Polygon');

    $r = httpGet("/TemplateWizard/export.php?what=export&dataset_id=$DS&template_id=basic&format=xlsx", $sidOwner);
    $pxPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $pxPath;
    file_put_contents($pxPath, $r['body']);
    $wbP = PHPExcel_IOFactory::load($pxPath);
    $shP = $wbP->getSheetByName('Data');
    $hdrP = array();
    for ($c = 0; $c < 60; $c++) {
        $h = (string)$shP->getCellByColumnAndRow($c, 2)->getValue();
        if ($h === '') { break; }
        $hdrP[$h] = $c;
    }
    $polyCell = null; $pointCell = null;
    for ($rw = 3; $rw < 60; $rw++) {
        $nm = (string)$shP->getCellByColumnAndRow($hdrP['name'], $rw)->getValue();
        if ($nm === '') { break; }
        if ($nm === 'WZ-POLY') { $polyCell = (string)$shP->getCellByColumnAndRow($hdrP['geometry_wkt'], $rw)->getValue(); }
        if ($nm === 'WZ-B')    { $pointCell = (string)$shP->getCellByColumnAndRow($hdrP['geometry_wkt'], $rw)->getValue(); }
    }
    check('export workbook materializes geometry_type + geometry_wkt after the id',
        isset($hdrP['geometry_type'], $hdrP['geometry_wkt'])
        && $hdrP['geometry_type'] === $hdrP['strabo_internal_id'] + 1 && $hdrP['geometry_wkt'] === $hdrP['geometry_type'] + 1);
    check('polygon row carries its POLYGON wkt, point row a POINT wkt',
        $polyCell !== null && stripos($polyCell, 'POLYGON') === 0 && strpos($polyCell, '-118.6 34.1') !== false
        && $pointCell !== null && stripos($pointCell, 'POINT') === 0);

    // round trip into the source dataset: all unchanged, no geometry warning
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $pxPath, 'poly_roundtrip.xlsx');
    $prtToken = extractToken($r['body']);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $prtToken), $target));
    check('wkt-bearing export round-trips as all unchanged with no geometry warning',
        strpos($r['body'], '0 new spots') !== false && strpos($r['body'], '4 unchanged') !== false
        && strpos($r['body'], 'geometry cannot be edited') === false);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $prtToken));

    // Import as new spots into a fresh dataset: the polygon stays a polygon
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $pxPath, 'poly_copy.xlsx');
    $pcToken = extractToken($r['body']);
    $pcTarget = array('project_id' => $PROJECT_ID, 'dataset_choice' => 'new', 'dataset_name' => "e2ewiz POLYCOPY $stamp", 'as_new' => '1');
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $pcToken), $pcTarget));
    check('as-new plan of the wkt-bearing export: 4 new spots, no centroid Heads up',
        strpos($r['body'], '4 new spots') !== false && strpos($r['body'], 'created as a Point at the centroid') === false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'confirm', 'token' => $pcToken), $pcTarget));
    check('as-new confirm of the wkt-bearing export: Import complete', strpos($r['body'], 'Import complete') !== false);
    preg_match('/New dataset created \(id (\d+)\)/', $r['body'], $m);
    $DSP = isset($m[1]) ? (int)$m[1] : 0;
    $datasetIds[] = $DSP;
    $recsP = $neodb->get_results("MATCH (d:Dataset {id: $DSP, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN s.id AS id, s.name AS name, s.geometrytype AS gt, s.wkt AS wkt");
    $copyPoly = null; $copyPointGt = null;
    foreach ((array)$recsP as $rec) {
        $spotIds[] = (int)$rec->value('id');
        if ($rec->value('name') === 'WZ-POLY') { $copyPoly = array('gt' => $rec->value('gt'), 'wkt' => (string)$rec->value('wkt'), 'id' => (int)$rec->value('id')); }
        if ($rec->value('name') === 'WZ-B')    { $copyPointGt = $rec->value('gt'); }
    }
    check('copied polygon is a Polygon with the same ring, under a new id',
        $copyPoly !== null && $copyPoly['gt'] === 'Polygon' && stripos($copyPoly['wkt'], 'POLYGON') === 0
        && strpos($copyPoly['wkt'], '-118.5 34.2') !== false && $copyPoly['id'] !== $POLY_ID);
    check('copied point spot is still a Point', $copyPointGt === 'Point');

    // ------------------------------------------------------------------
    echo "\n=== 9. stranger isolation ===\n";
    // ------------------------------------------------------------------
    $csv = "strabo_internal_id,spot_name\n$A,WZ-A\n";
    $r = httpPostFile('/TemplateWizard/review.php', $sidStranger, array('action' => 'upload'),
                      'tabfile', csvFile($csv), 'hijack.csv');
    $sToken = extractToken($r['body']);
    // stranger has no projects — plan must fail on target, and even with a
    // fake project id the owner's spot id must be invisible
    $r = httpPostForm('/TemplateWizard/review.php', $sidStranger, array(
        'action' => 'plan', 'token' => $sToken,
        'project_id' => $PROJECT_ID, 'dataset_choice' => 'existing', 'dataset_id' => $DS));
    check("stranger's plan rejects owner's project/spot",
        strpos($r['body'], 'Target project not found') !== false
        || strpos($r['body'], 'does not match any of your spots') !== false);
    check("stranger cannot plan into owner's dataset (no import happened)",
        strpos($r['body'], 'Import complete') === false);

    $r = httpGet("/TemplateWizard/export.php?what=export&dataset_id=$DS&template_id=$TPL", $sidStranger);
    check("stranger cannot export owner's dataset",
        strpos($r['body'], 'Template not found') !== false || strpos($r['body'], 'Dataset not found') !== false);

    $r = httpGet("/TemplateWizard/ajax.php?action=datasets&project_id=$PROJECT_ID", $sidStranger);
    $res = json_decode($r['body'], true);
    check("stranger's dataset lookup returns empty", is_array($res) && empty($res['datasets']));

    // owner token is invisible to the stranger
    $r = httpPostForm('/TemplateWizard/review.php', $sidStranger,
        array_merge(array('action' => 'plan', 'token' => $gridToken), $target));
    check("stranger cannot reuse owner's token", strpos($r['body'], 'expired') !== false);

    // ------------------------------------------------------------------
    echo "\n=== 10. cancel kills token ===\n";
    // ------------------------------------------------------------------
    $csv = "strabo_internal_id,spot_name,latitude,longitude\n,WZ-CANCEL,34.9,-118.9\n";
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', csvFile($csv), 'cancel.csv');
    $cToken = extractToken($r['body']);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $cToken));
    check('cancel redirects to wizard', $r['status'] === 302 && strpos($r['location'], '/TemplateWizard/') !== false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner,
        array_merge(array('action' => 'plan', 'token' => $cToken), $target));
    check('cancelled token is dead', strpos($r['body'], 'expired') !== false);

    // ajax delete
    $r = httpPostForm('/TemplateWizard/ajax.php', $sidOwner, array('action' => 'delete_template', 'pkey' => $TPL));
    check('template deletes over HTTP', strpos($r['body'], '"ok":true') !== false);
    $r = httpGet('/TemplateWizard/ajax.php?action=templates', $sidOwner);
    check('deleted template gone from list', strpos($r['body'], $tplName) === false);

    // ------------------------------------------------------------------
    echo "\n=== 11. designer template -> blank workbook -> fill in Excel -> upload -> verify -> export == noop ===\n";
    // ------------------------------------------------------------------
    // The researcher flow end to end at the file level: a template shaped
    // the way the column builder saves it (custom column, orientation_role,
    // samples), its blank workbook downloaded, rows typed into the real
    // Data sheet with PHPExcel (what Excel/Sheets would write), uploaded.
    if (!class_exists('PHPExcel')) { require_once '/srv/app/www/PHPExcel.php'; }
    $FILL_SPEC = array('spec_version' => 1, 'layout' => 'long', 'columns' => array(
        array('kind' => 'system', 'key' => 'strabo_internal_id'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'name'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'latitude'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'longitude'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'date'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'notes'),
        array('kind' => 'system', 'key' => 'orientation_type'),
        array('kind' => 'system', 'key' => 'orientation_role'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'feature_type'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'strike'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'dip'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'trend'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'plunge'),
        array('kind' => 'field', 'group' => 'sample', 'name' => 'sample_id_name'),
        array('kind' => 'field', 'group' => 'sample', 'name' => 'sample_type'),
        array('kind' => 'custom', 'header' => 'Field Book Page'),
    ));
    $r = httpPostForm('/TemplateWizard/ajax.php', $sidOwner, array(
        'action' => 'save_template', 'name' => "$tplName-fill", 'spec_json' => json_encode($FILL_SPEC)));
    $res = json_decode($r['body'], true);
    check('designer-shaped template (custom column + role + samples) saves', !empty($res['ok']) && (int)$res['pkey'] > 0);
    $TPL2 = (int)$res['pkey'];

    $r = httpGet("/TemplateWizard/export.php?what=template&template_id=$TPL2&format=xlsx", $sidOwner);
    check('blank workbook downloads', $r['status'] === 200 && substr($r['body'], 0, 4) === "PK\x03\x04");
    $blankPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $blankPath;
    file_put_contents($blankPath, $r['body']);

    $wb = PHPExcel_IOFactory::load($blankPath);
    $sheet = $wb->getSheetByName('Data');
    $hdrCol = array();   // header => 0-based column
    for ($c = 0; $c < 60; $c++) {
        $h = (string)$sheet->getCellByColumnAndRow($c, 2)->getValue();
        if ($h === '') { break; }
        $hdrCol[$h] = $c;
    }
    check('blank workbook headers include the custom column and orientation_role',
        isset($hdrCol['Field Book Page'], $hdrCol['orientation_role'], $hdrCol['sample_id_name'], $hdrCol['strike']));
    $fillRows = array(
        array('name' => 'WZ-FILL-1', 'latitude' => 34.21, 'longitude' => -118.21, 'date' => '2026-09-18', 'notes' => 'typed in Excel',
              'orientation_type' => 'planar', 'orientation_role' => 'primary', 'feature_type' => 'bedding', 'strike' => 100, 'dip' => 20,
              'sample_id_name' => "FS-FILL-$stamp", 'sample_type' => 'core', 'Field Book Page' => 'p. 12'),
        array('name' => 'WZ-FILL-1', 'orientation_type' => 'linear', 'orientation_role' => 'associated', 'feature_type' => 'stretching',
              'trend' => 150, 'plunge' => 30),
        array('name' => 'WZ-FILL-2', 'latitude' => 34.22, 'longitude' => -118.22, 'date' => '2026-09-18', 'notes' => 'second station'),
    );
    $rowN = 3;
    foreach ($fillRows as $vals) {
        foreach ($vals as $h => $v) {
            if (isset($hdrCol[$h])) { $sheet->setCellValueByColumnAndRow($hdrCol[$h], $rowN, $v); }
        }
        $rowN++;
    }
    $filledPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $filledPath;
    $writer = new PHPExcel_Writer_Excel2007($wb);
    $writer->save($filledPath);

    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'),
                      'tabfile', $filledPath, 'filled_template.xlsx');
    $fillToken = extractToken($r['body']);
    check('filled workbook uploads: embedded template recognized, 3 data rows',
        $fillToken !== null && strpos($r['body'], 'embedded template recognized') !== false && strpos($r['body'], '3 data rows') !== false);
    $fillTargetNew = array('project_id' => $PROJECT_ID, 'dataset_choice' => 'new', 'dataset_name' => "e2ewiz FILL $stamp");
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $fillToken), $fillTargetNew));
    check('filled workbook plans clean: 2 new spots (template custom column needs no decision)',
        strpos($r['body'], '2 new spots') !== false && strpos($r['body'], 'Confirm &amp; Import') !== false
        && strpos($r['body'], 'Unknown columns') === false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'confirm', 'token' => $fillToken), $fillTargetNew));
    check('filled workbook imports', strpos($r['body'], 'Import complete') !== false);
    preg_match('/New dataset created \(id (\d+)\)/', $r['body'], $m);
    $DS2 = isset($m[1]) ? (int)$m[1] : 0;
    $datasetIds[] = $DS2;
    $recs = $neodb->get_results("MATCH (d:Dataset {id: $DS2, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN s.id AS id, s.name AS name");
    $fillByName = array();
    foreach ((array)$recs as $rec) { $fillByName[$rec->value('name')] = (int)$rec->value('id'); $spotIds[] = (int)$rec->value('id'); }
    check('2 spots landed from the filled workbook', count($fillByName) === 2 && isset($fillByName['WZ-FILL-1'], $fillByName['WZ-FILL-2']));
    $F1 = isset($fillByName['WZ-FILL-1']) ? $fillByName['WZ-FILL-1'] : 0;
    $p1 = $F1 ? spotProps($neodb, $F1, $ownerPkey) : null;
    $od1 = $p1 ? json_decode($p1['json_orientation_data'], true) : null;
    check('WZ-FILL-1: one primary bedding 100/20 with the associated stretching lineation 150/30 riding it',
        is_array($od1) && count($od1) === 1 && $od1[0]['strike'] === 100 && $od1[0]['dip'] === 20
        && $od1[0]['feature_type'] === 'bedding'
        && isset($od1[0]['associated_orientation'][0]) && $od1[0]['associated_orientation'][0]['trend'] === 150
        && $od1[0]['associated_orientation'][0]['plunge'] === 30);
    $sm1 = $p1 ? json_decode($p1['json_samples'], true) : null;
    check('WZ-FILL-1: sample element landed with type core', is_array($sm1) && $sm1[0]['sample_id_name'] === "FS-FILL-$stamp" && $sm1[0]['sample_type'] === 'core');
    $cf1 = $p1 ? json_decode($p1['custom_fields'], true) : null;
    check('WZ-FILL-1: custom column value stored as a custom field', is_array($cf1) && $cf1['Field Book Page'] === 'p. 12');
    check('WZ-FILL-1: notes and date typed in Excel landed', $p1 && $p1['notes'] === 'typed in Excel' && (string)$p1['date'] !== '');
    $spine = $db->get_row_prepared("SELECT id FROM strabosamples.samples WHERE userpkey = $1 AND name = $2", array($ownerPkey, "FS-FILL-$stamp"));
    check('sample mirrored into the strabosamples spine over the HTTP path', $spine !== null && $spine !== false);

    // Jason's mistake (2026-09-18): the same id-less file uploaded a second
    // time. The plan must warn by name, and the success page must have
    // offered the dataset back WITH ids through the run that just committed.
    $successBody = $r['body'];
    preg_match('/Import run #(\d+)/', $successBody, $m);
    $RUN2 = isset($m[1]) ? (int)$m[1] : 0;
    check('success page offers "Download this dataset with ids" for the run',
        $RUN2 > 0 && strpos($successBody, 'id="tw-download-ids"') !== false
        && strpos($successBody, "run_id=$RUN2") !== false && strpos($successBody, 'out of date') !== false);
    $r = httpGet("/TemplateWizard/export.php?what=export&run_id=$RUN2&format=xlsx", $sidOwner);
    check('download with ids: xlsx through the run\'s dataset + spec', $r['status'] === 200 && substr($r['body'], 0, 4) === "PK\x03\x04");
    $idsPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $idsPath;
    file_put_contents($idsPath, $r['body']);
    $r = httpGet("/TemplateWizard/export.php?what=export&run_id=$RUN2&format=xlsx", $sidStranger);
    check("stranger cannot download the owner's run", strpos($r['body'], 'Import run not found') !== false || substr($r['body'], 0, 2) !== 'PK');
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'), 'tabfile', $idsPath, 'with_ids.xlsx');
    $idsToken = extractToken($r['body']);
    $fillTarget = array('project_id' => $PROJECT_ID, 'dataset_choice' => 'existing', 'dataset_id' => $DS2);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $idsToken), $fillTarget));
    check('the with-ids copy is a different file: no exact-file message', strpos($r['body'], 'This exact file') === false);
    check('the with-ids copy re-imports as all-unchanged (no Heads up)',
        strpos($r['body'], '0 new spots') !== false && strpos($r['body'], '2 unchanged') !== false && strpos($r['body'], 'Heads up') === false);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $idsToken));
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'), 'tabfile', $filledPath, 'filled_template_again.xlsx');
    $dupToken = extractToken($r['body']);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $dupToken), $fillTarget));
    check('the id-less file uploaded again: plan says 2 new spots but warns they already exist by name',
        strpos($r['body'], '2 new spots') !== false && strpos($r['body'], 'Heads up') !== false
        && strpos($r['body'], '2 new spots in this file share a name') !== false
        && strpos($r['body'], 'A spot named &quot;WZ-FILL-1&quot; already exists') !== false
        && strpos($r['body'], 'Confirm &amp; Import') !== false);
    check('the id-less file uploaded again: "This exact file was already imported into this dataset" with the run number',
        strpos($r['body'], 'This exact file was already imported into this dataset on') !== false
        && strpos($r['body'], "run #$RUN2") !== false && strpos($r['body'], '2 spots created') !== false);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $dupToken));
    check('cancelled duplicate upload created nothing',
        (int)$neodb->get_var("MATCH (d:Dataset {id: $DS2, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN count(s)") === 2);

    // export the new dataset through the same template: re-import must be all-noop
    $r = httpGet("/TemplateWizard/export.php?what=export&dataset_id=$DS2&template_id=$TPL2&format=xlsx", $sidOwner);
    check('filled dataset exports through its template', $r['status'] === 200 && substr($r['body'], 0, 4) === "PK\x03\x04");
    $ex2Path = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $ex2Path;
    file_put_contents($ex2Path, $r['body']);
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'), 'tabfile', $ex2Path, 'fill_export.xlsx');
    $ex2Token = extractToken($r['body']);
    $fillTarget = array('project_id' => $PROJECT_ID, 'dataset_choice' => 'existing', 'dataset_id' => $DS2);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $ex2Token), $fillTarget));
    check('export of the filled dataset re-imports as all-unchanged (custom field + sample + associated survive the round trip)',
        strpos($r['body'], '0 new spots') !== false && strpos($r['body'], '0 updated') !== false && strpos($r['body'], '2 unchanged') !== false);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $ex2Token));

    // Excel turns a typed "02" into the NUMBER 2. The name must still land as
    // the string "2" and the second upload must still warn (Jason, 2026-09-18).
    $wbN = PHPExcel_IOFactory::load($blankPath);
    $shN = $wbN->getSheetByName('Data');
    foreach (array('name' => 7, 'latitude' => 34.3, 'longitude' => -118.3, 'notes' => 42) as $h => $v) {
        $shN->setCellValueByColumnAndRow($hdrCol[$h], 3, $v);   // numeric cells, not strings
    }
    $numPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $numPath;
    $writerN = new PHPExcel_Writer_Excel2007($wbN);
    $writerN->save($numPath);
    $numTargetNew = array('project_id' => $PROJECT_ID, 'dataset_choice' => 'new', 'dataset_name' => "e2ewiz NUM $stamp");
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'), 'tabfile', $numPath, 'numeric_cells.xlsx');
    $numToken = extractToken($r['body']);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'confirm', 'token' => $numToken), $numTargetNew));
    check('numeric-cell workbook imports', strpos($r['body'], 'Import complete') !== false);
    preg_match('/New dataset created \(id (\d+)\)/', $r['body'], $m);
    $DSN = isset($m[1]) ? (int)$m[1] : 0;
    $datasetIds[] = $DSN;
    $numRec = $neodb->get_results("MATCH (d:Dataset {id: $DSN, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN s.id AS id, s.name AS name, s.notes AS notes");
    $numRec = (array)$numRec;
    if (count($numRec)) { $spotIds[] = (int)$numRec[0]->value('id'); }
    check('spot typed as the number 7 in Excel is stored with the STRING name "7" and notes "42"',
        count($numRec) === 1 && $numRec[0]->value('name') === '7' && $numRec[0]->value('notes') === '42');
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'), 'tabfile', $numPath, 'numeric_cells_again.xlsx');
    $numToken2 = extractToken($r['body']);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $numToken2),
        array('project_id' => $PROJECT_ID, 'dataset_choice' => 'existing', 'dataset_id' => $DSN)));
    check('uploading the numeric-name file again warns: a spot named "7" already exists',
        strpos($r['body'], 'A spot named &quot;7&quot; already exists') !== false);
    httpPostForm('/TemplateWizard/review.php', $sidOwner, array('action' => 'cancel', 'token' => $numToken2));

    // ------------------------------------------------------------------
    echo "\n=== 12. edit the exported workbook in Excel -> updates + a new spot over HTTP ===\n";
    // ------------------------------------------------------------------
    $wb = PHPExcel_IOFactory::load($ex2Path);
    $sheet = $wb->getSheetByName('Data');
    $hdrCol = array();
    for ($c = 0; $c < 60; $c++) {
        $h = (string)$sheet->getCellByColumnAndRow($c, 2)->getValue();
        if ($h === '') { break; }
        $hdrCol[$h] = $c;
    }
    $lastRow = 2; $editedNotes = false; $editedStrike = false;
    for ($rw = 3; $rw < 60; $rw++) {
        $nm = (string)$sheet->getCellByColumnAndRow($hdrCol['name'], $rw)->getValue();
        if ($nm === '') { break; }
        $lastRow = $rw;
        if ($nm === 'WZ-FILL-2' && !$editedNotes) { $sheet->setCellValueByColumnAndRow($hdrCol['notes'], $rw, 'edited in Excel'); $editedNotes = true; }
        if ($nm === 'WZ-FILL-1' && (string)$sheet->getCellByColumnAndRow($hdrCol['strike'], $rw)->getValue() === '100') {
            $sheet->setCellValueByColumnAndRow($hdrCol['strike'], $rw, 110); $editedStrike = true;
        }
    }
    check('export rows located for editing (ids present, notes + strike cells found)',
        $editedNotes && $editedStrike && (string)$sheet->getCellByColumnAndRow($hdrCol['strabo_internal_id'], 3)->getValue() !== '');
    $newRow = $lastRow + 1;
    foreach (array('name' => 'WZ-FILL-3', 'latitude' => 34.23, 'longitude' => -118.23, 'notes' => 'added in Excel') as $h => $v) {
        $sheet->setCellValueByColumnAndRow($hdrCol[$h], $newRow, $v);
    }
    $editPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $editPath;
    $writer = new PHPExcel_Writer_Excel2007($wb);
    $writer->save($editPath);

    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'), 'tabfile', $editPath, 'fill_export_edited.xlsx');
    $edToken = extractToken($r['body']);
    check('edited export uploads with its embedded template', $edToken !== null && strpos($r['body'], 'embedded template recognized') !== false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $edToken), $fillTarget));
    check('plan: 1 new spot, 2 updated, 0 unchanged',
        strpos($r['body'], '1 new spot<') !== false && strpos($r['body'], '2 updated') !== false && strpos($r['body'], '0 unchanged') !== false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'confirm', 'token' => $edToken), $fillTarget));
    check('edited export imports', strpos($r['body'], 'Import complete') !== false);
    $recs = $neodb->get_results("MATCH (d:Dataset {id: $DS2, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN s.id AS id, s.name AS name");
    $fillByName = array();
    foreach ((array)$recs as $rec) { $fillByName[$rec->value('name')] = (int)$rec->value('id'); $spotIds[] = (int)$rec->value('id'); }
    check('WZ-FILL-3 created in the existing dataset', isset($fillByName['WZ-FILL-3']) && count($fillByName) === 3);
    $p2 = isset($fillByName['WZ-FILL-2']) ? spotProps($neodb, $fillByName['WZ-FILL-2'], $ownerPkey) : null;
    check('WZ-FILL-2 notes updated from the edited cell', $p2 && $p2['notes'] === 'edited in Excel');
    $p1 = $F1 ? spotProps($neodb, $F1, $ownerPkey) : null;
    $od1 = $p1 ? json_decode($p1['json_orientation_data'], true) : null;
    check('WZ-FILL-1 strike 100 -> 110 with the associated lineation, sample and custom field preserved',
        is_array($od1) && count($od1) === 1 && $od1[0]['strike'] === 110
        && isset($od1[0]['associated_orientation'][0]) && $od1[0]['associated_orientation'][0]['trend'] === 150
        && json_decode($p1['json_samples'], true)[0]['sample_id_name'] === "FS-FILL-$stamp"
        && json_decode($p1['custom_fields'], true)['Field Book Page'] === 'p. 12');

    // ------------------------------------------------------------------
    echo "\n=== 13. the how-to example file imports as documented (5 spots, 6 orientations, 2 samples) ===\n";
    // ------------------------------------------------------------------
    $r = httpGet('/TemplateWizard/howto.php?demo=xlsx', $sidOwner);
    check('how-to example workbook downloads', $r['status'] === 200 && substr($r['body'], 0, 4) === "PK\x03\x04");
    $demoPath = tempnam(sys_get_temp_dir(), 'e2ewiz_') . '.xlsx';
    $tmpFiles[] = $demoPath;
    file_put_contents($demoPath, $r['body']);
    $r = httpPostFile('/TemplateWizard/review.php', $sidOwner, array('action' => 'upload'), 'tabfile', $demoPath, 'StraboSpot_HowTo_Example.xlsx');
    $demoToken = extractToken($r['body']);
    check('example uploads: embedded template recognized, 9 data rows',
        $demoToken !== null && strpos($r['body'], 'embedded template recognized') !== false && strpos($r['body'], '9 data rows') !== false);
    $demoTargetNew = array('project_id' => $PROJECT_ID, 'dataset_choice' => 'new', 'dataset_name' => "e2ewiz HOWTO $stamp");
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'plan', 'token' => $demoToken), $demoTargetNew));
    check('example plans clean with no vocabulary decisions: 5 new spots',
        strpos($r['body'], '5 new spots') !== false && strpos($r['body'], 'Confirm &amp; Import') !== false
        && strpos($r['body'], 'Unrecognized vocabulary') === false);
    $r = httpPostForm('/TemplateWizard/review.php', $sidOwner, array_merge(array('action' => 'confirm', 'token' => $demoToken), $demoTargetNew));
    check('example imports', strpos($r['body'], 'Import complete') !== false);
    preg_match('/New dataset created \(id (\d+)\)/', $r['body'], $m);
    $DS3 = isset($m[1]) ? (int)$m[1] : 0;
    $datasetIds[] = $DS3;
    $recs = $neodb->get_results("MATCH (d:Dataset {id: $DS3, userpkey: $ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN s.id AS id, s.name AS name, s.json_orientation_data AS od, s.json_samples AS sm");
    $nSpots = 0; $nOrient = 0; $nAssoc = 0; $nSamples = 0;
    foreach ((array)$recs as $rec) {
        $nSpots++; $spotIds[] = (int)$rec->value('id');
        $od = json_decode((string)$rec->value('od'), true);
        if (is_array($od)) {
            $nOrient += count($od);
            foreach ($od as $o) { if (!empty($o['associated_orientation'])) { $nAssoc += count($o['associated_orientation']); } }
        }
        $sm = json_decode((string)$rec->value('sm'), true);
        if (is_array($sm)) { $nSamples += count($sm); }
    }
    check("example landed as documented: 5 spots / 6 orientations / 1 associated / 2 samples (got $nSpots / $nOrient / $nAssoc / $nSamples)",
        $nSpots === 5 && $nOrient === 6 && $nAssoc === 1 && $nSamples === 2);

} finally {
    echo "\n=== cleanup ===\n";
    foreach (array_unique($spotIds) as $sid2) {
        try { $strabo->deleteSingleSpot((int)$sid2); } catch (Exception $e) {}
    }
    // catch strays by name
    $recs = $neodb->get_results("MATCH (s:Spot {userpkey: $ownerPkey}) WHERE s.name IN ['WZ-A','WZ-B','WZ-DIRTY','WZ-CANCEL','WZ-FILL-1','WZ-FILL-2','WZ-FILL-3','WZ-POLY'] RETURN s.id AS id");
    foreach ((array)$recs as $rec) {
        try { $strabo->deleteSingleSpot((int)$rec->value('id')); } catch (Exception $e) {}
    }
    foreach (array_unique($datasetIds) as $did) {
        try { $neodb->query("MATCH (d:Dataset {id: " . (int)$did . ", userpkey: $ownerPkey}) DETACH DELETE d"); } catch (Exception $e) {}
        $db->get_var_prepared("DELETE FROM dataset WHERE user_pkey = $1 AND strabo_dataset_id = $2 RETURNING strabo_dataset_id",
            array($ownerPkey, (string)$did));
    }
    try { $neodb->query("MATCH (p:Project {id: $PROJECT_ID, userpkey: $ownerPkey}) DETACH DELETE p"); } catch (Exception $e) {}
    $db->query("DELETE FROM project WHERE strabo_project_id = '$PROJECT_ID' AND user_pkey = $ownerPkey");
    $db->query("DELETE FROM field_templates WHERE userpkey = $ownerPkey AND name ILIKE 'e2ewiz%'");
    $db->query("DELETE FROM field_tabular_runs WHERE project_id = '$PROJECT_ID'");
    foreach (array("FS-FILL-$stamp", 'KU-26-001', 'KU-26-002') as $sn) {
        $db->get_var_prepared("DELETE FROM strabosamples.samples WHERE userpkey = $1 AND name = $2 RETURNING id", array($ownerPkey, $sn));
    }
    foreach ($sessionFiles as $f) { @unlink($f); }
    foreach ($tmpFiles as $f) { @unlink($f); }

    $r1 = (int)$neodb->get_var("MATCH (s:Spot {userpkey: $ownerPkey}) WHERE s.name IN ['WZ-A','WZ-B','WZ-DIRTY','WZ-CANCEL','WZ-FILL-1','WZ-FILL-2','WZ-FILL-3','WZ-POLY'] RETURN count(s)");
    $r2 = (int)$neodb->get_var("MATCH (d:Dataset {userpkey: $ownerPkey}) WHERE d.name =~ 'e2ewiz.*' RETURN count(d)");
    $r3 = (int)$db->get_var_prepared("SELECT count(*) FROM field_templates WHERE userpkey = $1 AND name ILIKE 'e2ewiz%'", array($ownerPkey));
    $r4 = (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $1 AND name IN ($2, 'KU-26-001', 'KU-26-002')", array($ownerPkey, "FS-FILL-$stamp"));
    echo "residue: spots=$r1 datasets=$r2 templates=$r3 samples=$r4\n";
}

echo "\n==============================\n";
if (empty($failures)) {
    echo "ALL CHECKS PASSED\n";
    exit(0);
}
echo count($failures) . " FAILURES:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
