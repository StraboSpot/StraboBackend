<?php
/**
 * File: tephra_test.php
 * Description: Service-layer test for the Template Wizard tephra section
 *              (Matt Loewen, AVO, 2026-10-24): one row per layer, derived
 *              Layer Order, layers matched by label on update.
 *
 *              Coverage:
 *                1. Catalog: tephra group (20 fields, synced vocab), headers;
 *                   a tephra column brings the layer label along
 *                2. Creates via CSV: shuffled rows sorted by Layer Order,
 *                   file order kept without it, labels + names + multi
 *                   values resolved, decimals typed, UUID layer ids,
 *                   layer_order never stored
 *                3. Export: one row per layer, Layer Order 1..n, spot-only
 *                   template writes no layer rows; CSV + XLSX round trips
 *                   are all-unchanged
 *                4. Reorder via Layer Order: ids kept, new order stored
 *                5. Narrow template: edit one field, fields outside the
 *                   template and ids survive; a new label = a new layer
 *                6. Hard errors: bad / duplicate / partial Layer Order
 *                7. Blank tephra cells on an update remove the layers (warning)
 *
 *              Hermetic: own Project node 96669004; spots, datasets,
 *              journal rows removed in the finally block; residue check.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/templatewizard/tephra_test.php
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
    if (!$cond) { $failures[] = $label; }
}

function csvFile($csv) {
    global $tmpFiles;
    $path = tempnam(sys_get_temp_dir(), 'wiztephra_') . '.csv';
    file_put_contents($path, $csv);
    $tmpFiles[] = $path;
    return $path;
}

function tephraOf($neodb, $id, $upk) {
    $records = $neodb->get_results("MATCH (s:Spot {id: $id, userpkey: $upk}) RETURN s.tephra AS t");
    if (!is_array($records) || !count($records)) { return null; }
    $t = $records[0]->value('t');
    return is_string($t) ? json_decode($t, true) : null;
}

function codes($plan) {
    $out = array();
    foreach ($plan['hard_errors'] as $e) { $out[] = $e['code']; }
    return $out;
}

/** Export rows -> CSV text with some rows' cells rewritten. */
function editedCsv($svc, $export, $edit) {
    $rows = array();
    foreach ($export['rows'] as $i => $r) {
        $r = $edit($r, $i);
        if ($r !== null) { $rows[] = $r; }
    }
    return $svc->buildCsv(array('headers' => $export['headers'], 'rows' => $rows));
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 1", array());
if (!is_array($users) || count($users) < 1) { echo "Need a test user.\n"; exit(1); }
$owner = (int)$users[0]->pkey;
echo "owner=$owner\n\n";

$PROJECT_ID = 96669004;
$stamp = time();
$tmpFiles = array();
$spotIds = array();
$datasetIds = array();

$strabo = new StraboSpot($neodb, $owner, $db);
$svc = new FieldTabularService($db, $neodb, $strabo);
$svc->setUserpkey($owner);

$UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

try {
    $neodb->createNode(json_encode(array(
        'userpkey' => $owner, 'id' => $PROJECT_ID,
        'desc_project_name' => "tephrawiz project $stamp",
        'modified_timestamp' => (int)round(microtime(true) * 1000),
    )), 'Project');

    // ------------------------------------------------------------------
    echo "=== 1. catalog / headers / spec ===\n";
    // ------------------------------------------------------------------
    $cat = FieldTabularService::catalog();
    check('tephra group with 20 fields, multi, target tephra',
        isset($cat['groups']['tephra']) && count($cat['groups']['tephra']['fields']) === 20
        && $cat['groups']['tephra']['multi'] === true && $cat['groups']['tephra']['target'] === 'tephra');
    $lt = FieldTabularService::fieldDef('tephra', 'layer_type');
    $ltVals = array(); $ltLabel = array();
    foreach ($lt['vocab'] as $e) { $ltVals[] = $e['value']; $ltLabel[$e['value']] = $e['label']; }
    check('layer_type vocab from the synced app form (tephra_fall, package)',
        in_array('tephra_fall', $ltVals) && in_array('package', $ltVals));
    $ga = FieldTabularService::fieldDef('tephra', 'grain_angularity');
    check('grain_angularity is select_multiple', $ga['type'] === 'select_multiple');
    check('bottom_contact vocab comes from the additional form',
        count(FieldTabularService::fieldDef('tephra', 'bottom_contact')['vocab']) > 0);
    check('layer_order is a derived integer', FieldTabularService::fieldDef('tephra', 'layer_order')['type'] === 'integer'
        && !empty(FieldTabularService::fieldDef('tephra', 'layer_order')['derived']));
    check('headers: tephra_label (label taken), bare layer_order + layer_type',
        FieldTabularService::displayHeader('tephra', 'label') === 'tephra_label'
        && FieldTabularService::displayHeader('tephra', 'layer_order') === 'layer_order'
        && FieldTabularService::displayHeader('tephra', 'layer_type') === 'layer_type');
    check('existing headers unchanged (other_features_label, sample_label)',
        FieldTabularService::displayHeader('other_features', 'label') === 'other_features_label'
        && FieldTabularService::displayHeader('sample', 'label') === 'sample_label');
    $idx = FieldTabularService::headerIndex();
    check("Matt's download headers resolve ('Tephra Layer Type', 'Tephra Tephra Name')",
        isset($idx['tephra_layer_type']) && $idx['tephra_layer_type'] === array('tephra', 'layer_type')
        && isset($idx['tephra_tephra_name']) && $idx['tephra_tephra_name'] === array('tephra', 'tephra_name'));

    $v = $svc->validateSpec(array('columns' => array(
        array('kind' => 'field', 'group' => 'spot', 'name' => 'name'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'layer_order'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'layer_color'),
    )));
    $sigs = array();
    foreach ($v['spec']['columns'] as $c) { $sigs[] = $c['kind'] === 'field' ? $c['group'] . '.' . $c['name'] : $c['key']; }
    check('a tephra column brings tephra.label, placed before the first tephra column',
        !empty($v['ok']) && $sigs === array('strabo_internal_id', 'spot.name', 'tephra.label', 'tephra.layer_order', 'tephra.layer_color'));

    $SPEC = $svc->validateSpec(array('columns' => array(
        array('kind' => 'field', 'group' => 'spot', 'name' => 'name'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'latitude'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'longitude'),
        array('kind' => 'field', 'group' => 'sample', 'name' => 'sample_id_name'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'layer_order'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'label'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'layer_type'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'layer_color'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'thickness_typical'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'grain_angularity'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'tephra_name'),
    )));
    check('full tephra spec validates', !empty($SPEC['ok']));
    $SPEC = $SPEC['spec'];

    // ------------------------------------------------------------------
    echo "\n=== 2. creates via CSV ===\n";
    // ------------------------------------------------------------------
    // TS-1: rows shuffled, Layer Order says 1..3. TS-2: no Layer Order
    // (file order), a sample row between its layers, a label typed for
    // layer_type and a two-value grain_angularity.
    $fallLabel = $ltLabel['tephra_fall'];
    $csv = "spot_name,latitude,longitude,sample_id_name,layer_order,tephra_label,layer_type,layer_color,thickness_typical,grain_angularity,tephra_name\n"
         . "TS-1,52.0315,-176.1902,,3,TS-1-3,tephra_fall,gray,1.5,,Main\n"
         . "TS-1,52.0315,-176.1902,,1,TS-1-1,package,tan orange,20,,\n"
         . "TS-1,52.0315,-176.1902,,2,TS-1-2,tephra_fall,dark brown,3,,\n"
         . "TS-2,52.0400,-176.2000,,,TS-2-a,$fallLabel,white,0.5,subround; angular,\n"
         . "TS-2,52.0400,-176.2000,TEPH-S-$stamp,,,,,,,\n"
         . "TS-2,52.0400,-176.2000,,,TS-2-b,package,black,12,,\n";
    $parsed = $svc->parseUpload(csvFile($csv), 'tephra.csv', $SPEC);
    check('CSV parses', !empty($parsed['ok']) && count($parsed['rows']) === 6);
    $target = array('project_id' => $PROJECT_ID, 'dataset_id' => null, 'dataset_name' => "tephrawiz DS1 $stamp");
    $plan = $svc->plan($parsed, $target);
    check('plan clean: 2 creates, 5 tephra layers, 1 sample',
        !empty($plan['clean']) && $plan['counts']['create'] === 2 && $plan['counts']['tephra'] === 5 && $plan['counts']['samples'] === 1);
    $commit = $svc->commit($plan);
    check('commit ok', !empty($commit['ok']) && $commit['created'] === 2);
    $DS1 = (int)$commit['dataset_id'];
    $datasetIds[] = $DS1;
    foreach ($commit['minted'] as $mid) { $spotIds[] = (int)$mid; }
    $byName = array();
    foreach ($neodb->get_results("MATCH (d:Dataset {id: $DS1, userpkey: $owner})-[:HAS_SPOT]->(s:Spot) RETURN s.id AS id, s.name AS name") as $r) {
        $byName[$r->value('name')] = (int)$r->value('id');
    }
    $T1 = $byName['TS-1']; $T2 = $byName['TS-2'];

    $t1 = tephraOf($neodb, $T1, $owner);
    check('TS-1 layers stored in Layer Order (TS-1-1, TS-1-2, TS-1-3)',
        is_array($t1) && array_column($t1, 'label') === array('TS-1-1', 'TS-1-2', 'TS-1-3'));
    check('layer_order is not stored on the layers', is_array($t1) && !array_key_exists('layer_order', $t1[0]));
    // (20.0 reads back from the node's JSON as int 20: the same number)
    check('thickness typed as a number', is_array($t1) && is_numeric($t1[0]['thickness_typical']) && !is_string($t1[0]['thickness_typical'])
        && $t1[0]['thickness_typical'] == 20 && $t1[2]['thickness_typical'] === 1.5);
    check('every layer has a UUID id', is_array($t1) && preg_match($UUID_RE, $t1[0]['id']) && preg_match($UUID_RE, $t1[2]['id']));
    $t2 = tephraOf($neodb, $T2, $owner);
    check('TS-2 keeps file order without Layer Order', is_array($t2) && array_column($t2, 'label') === array('TS-2-a', 'TS-2-b'));
    check("layer_type label '$fallLabel' stored as tephra_fall", is_array($t2) && $t2[0]['layer_type'] === 'tephra_fall');
    check('grain_angularity stored as a list', is_array($t2) && $t2[0]['grain_angularity'] === array('subround', 'angular'));
    $sm = json_decode($neodb->get_var("MATCH (s:Spot {id: $T2, userpkey: $owner}) RETURN s.json_samples"), true);
    check('the sample row on TS-2 landed as a sample, not a layer', is_array($sm) && count($sm) === 1 && count($t2) === 2);

    // ------------------------------------------------------------------
    echo "\n=== 3. export + round trips ===\n";
    // ------------------------------------------------------------------
    $export = $svc->exportLong($DS1, $SPEC);
    check('export ok', !empty($export['ok']));
    $layerRows = array();
    foreach ($export['rows'] as $r) {
        if ($r['tephra_label'] !== '') { $layerRows[$r['name']][] = array($r['layer_order'], $r['tephra_label']); }
    }
    check('export: one row per layer with Layer Order 1..n',
        $layerRows['TS-1'] === array(array('1', 'TS-1-1'), array('2', 'TS-1-2'), array('3', 'TS-1-3'))
        && $layerRows['TS-2'] === array(array('1', 'TS-2-a'), array('2', 'TS-2-b')));
    check('export: 6 rows (3 + 1 sample + 2)', count($export['rows']) === 6);
    $ex2 = null;
    foreach ($export['rows'] as $r) { if ($r['tephra_label'] === 'TS-2-a') { $ex2 = $r; } }
    check('export writes form labels for layer_type and joins grain_angularity',
        $ex2 !== null && $ex2['layer_type'] === $fallLabel && strpos($ex2['grain_angularity'], '; ') !== false);

    $spotOnly = $svc->validateSpec(array('columns' => array(
        array('kind' => 'field', 'group' => 'spot', 'name' => 'name'))))['spec'];
    $exSpot = $svc->exportLong($DS1, $spotOnly);
    check('spot-only template writes no layer rows (1 row per spot)', count($exSpot['rows']) === 2);

    $rt = $svc->plan($svc->parseUpload(csvFile($svc->buildCsv($export)), 'rt.csv', $SPEC),
                     array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''));
    check('CSV round trip is all-unchanged', !empty($rt['clean']) && $rt['counts']['noop'] === 2 && $rt['counts']['update'] === 0);

    if (!class_exists('PHPExcel')) { require_once '/srv/app/www/PHPExcel.php'; }
    $wb = $svc->buildWorkbook($export, false);
    $xlsx = tempnam(sys_get_temp_dir(), 'wiztephra_') . '.xlsx';
    $tmpFiles[] = $xlsx;
    (new PHPExcel_Writer_Excel2007($wb))->save($xlsx);
    $px = $svc->parseUpload($xlsx, 'rt.xlsx');
    $rtx = $svc->plan($px, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''));
    check('XLSX round trip (embedded template) is all-unchanged',
        !empty($px['embedded_spec']) && !empty($rtx['clean']) && $rtx['counts']['noop'] === 2);

    // ------------------------------------------------------------------
    echo "\n=== 4. reorder via Layer Order ===\n";
    // ------------------------------------------------------------------
    $idsBefore = array();
    foreach ($t1 as $el) { $idsBefore[$el['label']] = $el['id']; }
    $csv = editedCsv($svc, $export, function ($r) {
        if ($r['tephra_label'] === 'TS-1-1') { $r['layer_order'] = '3'; }
        elseif ($r['tephra_label'] === 'TS-1-3') { $r['layer_order'] = '1'; }
        return $r;
    });
    $plan = $svc->plan($svc->parseUpload(csvFile($csv), 're.csv', $SPEC),
                       array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''));
    check('reorder plans as 1 update, 1 unchanged', !empty($plan['clean']) && $plan['counts']['update'] === 1 && $plan['counts']['noop'] === 1);
    $c = $svc->commit($plan);
    $t1 = tephraOf($neodb, $T1, $owner);
    check('reorder committed: TS-1-3, TS-1-2, TS-1-1', !empty($c['ok']) && array_column($t1, 'label') === array('TS-1-3', 'TS-1-2', 'TS-1-1'));
    $kept = true;
    foreach ($t1 as $el) { if ($el['id'] !== $idsBefore[$el['label']]) { $kept = false; } }
    check('layer ids kept through the reorder', $kept);

    // ------------------------------------------------------------------
    echo "\n=== 5. narrow template: uncovered fields survive ===\n";
    // ------------------------------------------------------------------
    $NARROW = $svc->validateSpec(array('columns' => array(
        array('kind' => 'field', 'group' => 'spot', 'name' => 'name'),
        array('kind' => 'field', 'group' => 'tephra', 'name' => 'layer_color'),
    )))['spec'];
    $exN = $svc->exportLong($DS1, $NARROW);
    check('narrow export carries tephra_label (auto) + layer_color', in_array('tephra_label', $exN['headers']) && in_array('layer_color', $exN['headers']));
    $csv = editedCsv($svc, $exN, function ($r) {
        if ($r['tephra_label'] === 'TS-1-3') { $r['layer_color'] = 'pale gray'; }
        return $r;
    });
    $plan = $svc->plan($svc->parseUpload(csvFile($csv), 'narrow.csv', $NARROW),
                       array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''));
    $c = $svc->commit($plan);
    $t1 = tephraOf($neodb, $T1, $owner);
    check('narrow edit committed (1 update)', !empty($c['ok']) && $plan['counts']['update'] === 1);
    check('edited field changed, uncovered fields kept (tephra_name, thickness, type)',
        $t1[0]['label'] === 'TS-1-3' && $t1[0]['layer_color'] === 'pale gray' && $t1[0]['tephra_name'] === 'Main'
        && $t1[0]['thickness_typical'] == 1.5 && $t1[0]['layer_type'] === 'tephra_fall');
    check('ids kept on a narrow edit', $t1[0]['id'] === $idsBefore['TS-1-3'] && $t1[2]['id'] === $idsBefore['TS-1-1']);

    // a new label is a new layer (appended where its row sits)
    $csv = editedCsv($svc, $exN, function ($r) { return $r; });
    $csv .= "$T1,TS-1,TS-1-4,yellow\n";
    $hdr = implode(',', $exN['headers']);
    check('narrow headers are id,name,tephra_label,layer_color', $hdr === 'strabo_internal_id,name,tephra_label,layer_color');
    $plan = $svc->plan($svc->parseUpload(csvFile($csv), 'add.csv', $NARROW),
                       array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''));
    $c = $svc->commit($plan);
    $t1 = tephraOf($neodb, $T1, $owner);
    check('a new label adds a 4th layer with a fresh UUID, others untouched',
        !empty($c['ok']) && count($t1) === 4 && $t1[3]['label'] === 'TS-1-4' && preg_match($UUID_RE, $t1[3]['id'])
        && $t1[0]['tephra_name'] === 'Main');

    // ------------------------------------------------------------------
    echo "\n=== 6. Layer Order errors ===\n";
    // ------------------------------------------------------------------
    $base = "spot_name,latitude,longitude,layer_order,tephra_label\n";
    $bad = function ($rows) use ($svc, $SPEC, $PROJECT_ID, $stamp) {
        return $svc->plan($svc->parseUpload(csvFile($GLOBALS['base'] . $rows), 'bad.csv', $SPEC),
                          array('project_id' => $PROJECT_ID, 'dataset_id' => null, 'dataset_name' => "tephrawiz bad $stamp"));
    };
    foreach (array('0', '1.5', 'x') as $junk) {
        $p = $bad("TE-1,52,-176,$junk,A\n");
        check("Layer Order '$junk' is a hard error", in_array('bad_layer_order', codes($p)) && empty($p['clean']));
    }
    $p = $bad("TE-1,52,-176,1,A\nTE-1,52,-176,1,B\n");
    check('duplicate Layer Order in one spot is a hard error', in_array('duplicate_layer_order', codes($p)));
    $p = $bad("TE-1,52,-176,1,A\nTE-1,52,-176,,B\n");
    check('some layers numbered, some not: hard error', in_array('partial_layer_order', codes($p)));
    $p = $bad("TE-1,52,-176,1,A\nTE-2,52,-176,1,B\n");
    check('the same Layer Order in two different spots is fine', !empty($p['clean']) && $p['counts']['tephra'] === 2);

    // ------------------------------------------------------------------
    echo "\n=== 7. blank tephra cells remove the layers ===\n";
    // ------------------------------------------------------------------
    $export = $svc->exportLong($DS1, $SPEC);
    $csv = editedCsv($svc, $export, function ($r) {
        if ($r['name'] !== 'TS-2') { return $r; }
        if ($r['sample_id_name'] === '' && $r['tephra_label'] !== '' && $r['tephra_label'] !== 'TS-2-a') { return null; }
        foreach (array('layer_order', 'tephra_label', 'layer_type', 'layer_color', 'thickness_typical', 'grain_angularity', 'tephra_name') as $h) { $r[$h] = ''; }
        return $r;
    });
    $plan = $svc->plan($svc->parseUpload(csvFile($csv), 'clear.csv', $SPEC),
                       array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''));
    $warned = false;
    foreach ($plan['warnings'] as $w) { if (strpos($w['message'], 'Removes all 2 tephra layers') !== false) { $warned = true; } }
    check('clearing warns "Removes all 2 tephra layers"', $warned && $plan['counts']['update'] === 1);
    $c = $svc->commit($plan);
    $t2 = tephraOf($neodb, $T2, $owner);
    $sm = json_decode($neodb->get_var("MATCH (s:Spot {id: $T2, userpkey: $owner}) RETURN s.json_samples"), true);
    check('TS-2 layers removed, its sample kept', !empty($c['ok']) && empty($t2) && is_array($sm) && count($sm) === 1);

} finally {
    echo "\n=== cleanup ===\n";
    foreach (array_unique($spotIds) as $sid) {
        try { $strabo->deleteSingleSpot((int)$sid); } catch (Exception $e) { /* already gone */ }
    }
    foreach (array_unique($datasetIds) as $did) {
        try { $neodb->query("MATCH (d:Dataset {id: " . (int)$did . ", userpkey: $owner}) DETACH DELETE d"); } catch (Exception $e) {}
        $db->get_var_prepared("DELETE FROM dataset WHERE user_pkey = $1 AND strabo_dataset_id = $2 RETURNING strabo_dataset_id",
            array($owner, (string)$did));
    }
    try { $neodb->query("MATCH (p:Project {id: $PROJECT_ID, userpkey: $owner}) DETACH DELETE p"); } catch (Exception $e) {}
    $db->query("DELETE FROM project WHERE strabo_project_id = '$PROJECT_ID' AND user_pkey = $owner");
    $db->query("DELETE FROM field_tabular_runs WHERE project_id = '$PROJECT_ID'");
    $db->get_var_prepared("DELETE FROM strabosamples.samples WHERE userpkey = $1 AND name = $2 RETURNING id",
        array($owner, "TEPH-S-$stamp"));
    foreach ($tmpFiles as $f) { @unlink($f); }

    $r1 = (int)$neodb->get_var("MATCH (s:Spot {userpkey: $owner}) WHERE s.name IN ['TS-1','TS-2','TE-1','TE-2'] RETURN count(s)");
    $r2 = (int)$neodb->get_var("MATCH (d:Dataset {userpkey: $owner}) WHERE d.name =~ 'tephrawiz.*' RETURN count(d)");
    $r3 = (int)$db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $1 AND name = $2", array($owner, "TEPH-S-$stamp"));
    echo "residue: spots=$r1 datasets=$r2 spine=$r3\n";
}

echo "\n==============================\n";
if (empty($failures)) {
    echo "ALL CHECKS PASSED\n";
    exit(0);
}
echo count($failures) . " FAILURES:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
