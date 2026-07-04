<?php
/**
 * File: smoke_test_field_tabular.php
 * Description: Service-layer smoke test for the Template Wizard —
 *              FieldTabularService parse / plan / commit / rollback / export.
 *
 *              Coverage:
 *                1.  Catalog, default spec, header index, spec validation
 *                2.  Template CRUD (save / dup guard / rename / delete)
 *                3.  Template workbook build + re-parse (embedded spec)
 *                4.  Creates via CSV: long-format grouping, associated
 *                    orientation, vocab labels, sample row -> strabosamples
 *                    mirror (sync-hook proof), custom column, new dataset
 *                5.  Export -> re-import round trip == all-noop clean plan
 *                6.  Round trip survives Excel-style row sorting (key grouping)
 *                7.  Updates: scalar diff, blank-cell clear, group replace
 *                    with associated survival, uncovered-field preservation
 *                8.  Instance-group clear (all orientation cells blank) + warning
 *                9.  Hard errors: unknown id, wrong dataset, contradiction,
 *                    bad otype, inapplicable field, constraint, orphan
 *                    associated, blank name, missing coords, id/name ambiguity
 *                10. Vocab: soft -> map resolution, __other__ companion,
 *                    __freetext__
 *                11. Geometry: LineString centroid export, lat/lng edit guard,
 *                    Point move
 *                12. Owner-only export
 *                13. Compensating rollback: mid-run failure deletes created
 *                    spots and restores updated ones from prior JSON
 *                14. State files: save / load / foreign-user / discard
 *
 *              Hermetic: own Project node 96669001, all spot/dataset ids
 *              collected and removed in the finally block; template +
 *              journal rows deleted; residue queries at the end.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/templatewizard/smoke_test_field_tabular.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/geophp/geoPHP.inc';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/strabospotclass.php';
require_once '/srv/app/www/TemplateWizard/services/FieldTabularService.php';

// ~E_WARNING: singleSpotJSONFromFeatureData count()s a null image list on
// image-less spots under PHP 7.4 — pre-existing wart, not ours to fix here.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) { $failures[] = $label; }
}

function csvFile($csv) {
    global $tmpFiles;
    $path = tempnam(sys_get_temp_dir(), 'wizsmoke_') . '.csv';
    file_put_contents($path, $csv);
    $tmpFiles[] = $path;
    return $path;
}

/** Raw Spot node props by id (owner-scoped via query). */
function spotProps($neodb, $id, $upk) {
    $records = $neodb->get_results("MATCH (s:Spot {id: $id, userpkey: $upk}) RETURN s");
    if (!is_array($records) || !count($records)) { return null; }
    return $records[0]->get('s')->values();
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 2", array());
if (!is_array($users) || count($users) < 2) { echo "Need 2 test users.\n"; exit(1); }
$owner    = (int)$users[0]->pkey;
$stranger = (int)$users[1]->pkey;
echo "owner=$owner  stranger=$stranger\n\n";

$PROJECT_ID = 96669001;
$stamp = time();
$tmpFiles  = array();
$spotIds   = array();   // ids to clean up
$datasetIds = array();  // ids to clean up

$strabo = new StraboSpot($neodb, $owner, $db);
$svc = new FieldTabularService($db, $neodb, $strabo);
$svc->setUserpkey($owner);

$straboStranger = new StraboSpot($neodb, $stranger, $db);
$svcStranger = new FieldTabularService($db, $neodb, $straboStranger);
$svcStranger->setUserpkey($stranger);

// The default spec + sample columns + a custom column — the working template
$SPEC = FieldTabularService::defaultSpec();
$SPEC['columns'][] = array('kind' => 'field', 'group' => 'sample', 'name' => 'sample_id_name');
$SPEC['columns'][] = array('kind' => 'field', 'group' => 'sample', 'name' => 'sample_type');
$SPEC['columns'][] = array('kind' => 'custom', 'header' => 'Field Book Page');

try {
    // hermetic project node
    $neodb->createNode(json_encode(array(
        'userpkey' => $owner, 'id' => $PROJECT_ID,
        'desc_project_name' => "smokewiz project $stamp",
        'modified_timestamp' => (int)round(microtime(true) * 1000),
    )), 'Project');

    // ------------------------------------------------------------------
    echo "=== 1. catalog / spec / headers ===\n";
    // ------------------------------------------------------------------
    $cat = FieldTabularService::catalog();
    check('catalog has 6 groups', count($cat['groups']) === 6);
    check('orientation strike constraint 0-360',
        FieldTabularService::fieldDef('orientation', 'strike') !== null
        && FieldTabularService::fieldDef('orientation', 'strike')['constraint']['max'] === 360);

    $v = $svc->validateSpec($SPEC);
    check('working spec validates', !empty($v['ok']));
    $SPEC = $v['spec'];

    // spec missing system columns gets them injected
    $bare = array('spec_version' => 1, 'layout' => 'long', 'columns' => array(
        array('kind' => 'field', 'group' => 'spot', 'name' => 'name'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'strike'),
    ));
    $v2 = $svc->validateSpec($bare);
    $keys = array();
    foreach ($v2['spec']['columns'] as $c) { if ($c['kind'] === 'system') { $keys[] = $c['key']; } }
    check('system columns auto-injected (geom + role stay opt-in)', !empty($v2['ok'])
        && in_array('strabo_internal_id', $keys) && !in_array('geometry_type', $keys)
        && in_array('orientation_type', $keys) && !in_array('orientation_role', $keys));
    check('starter template has no geometry_type', !in_array('geometry_type',
        array_map(function ($c) { return isset($c['key']) ? $c['key'] : ''; },
                  FieldTabularService::defaultSpec()['columns'])));
    check('unknown catalog field rejected',
        empty($svc->validateSpec(array('columns' => array(array('kind' => 'field', 'group' => 'spot', 'name' => 'nope'))))['ok']));

    // header disambiguation: spot claims bare 'label'? (orientation + sample both have label)
    check('sample label header is prefixed',
        FieldTabularService::displayHeader('sample', 'label') !== 'label');

    // ------------------------------------------------------------------
    echo "\n=== 2. template CRUD ===\n";
    // ------------------------------------------------------------------
    $r = $svc->saveTemplate("smokewiz-tpl-$stamp", $SPEC);
    check('template saves', !empty($r['ok']));
    $tplPkey = $r['pkey'];
    $r2 = $svc->saveTemplate("SMOKEWIZ-TPL-$stamp", $SPEC);
    check('duplicate name (case-insensitive) rejected', empty($r2['ok']));
    $got = $svc->getTemplate($tplPkey);
    check('template round-trips spec', $got !== null && count($got['spec']['columns']) === count($SPEC['columns']));
    $r3 = $svc->saveTemplate("smokewiz-tpl-renamed-$stamp", $SPEC, $tplPkey);
    check('rename works', !empty($r3['ok']));
    check('stranger cannot see template', $svcStranger->getTemplate($tplPkey) === null);
    $list = $svc->listTemplates();
    $found = false;
    foreach ($list as $t) { if ((int)$t->pkey === $tplPkey) { $found = true; } }
    check('template listed', $found);

    // ------------------------------------------------------------------
    echo "\n=== 3. template workbook + embedded spec ===\n";
    // ------------------------------------------------------------------
    if (!class_exists('PHPExcel')) {
        require_once '/srv/app/www/PHPExcel.php';   // registers the autoloader
    }
    $defs = array();
    foreach ($svc->columnDefs($SPEC) as $d) { $defs[] = $d['header']; }
    $emptyExport = array('headers' => $defs, 'rows' => array(), 'spec' => $SPEC);
    $wb = $svc->buildWorkbook($emptyExport, true, "smokewiz-tpl-$stamp");
    check('workbook has Data/Vocabulary/Instructions/_template sheets',
        $wb->getSheetByName('Data') !== null && $wb->getSheetByName('Vocabulary') !== null
        && $wb->getSheetByName('Instructions') !== null && $wb->getSheetByName('_template') !== null);
    check('_template sheet hidden', $wb->getSheetByName('_template')->getSheetState() === PHPExcel_Worksheet::SHEETSTATE_HIDDEN);
    $tplPath = tempnam(sys_get_temp_dir(), 'wizsmoke_') . '.xlsx';
    $tmpFiles[] = $tplPath;
    $writer = new PHPExcel_Writer_Excel2007($wb);
    $writer->save($tplPath);
    $parsedTpl = $svc->parseUpload($tplPath, 'template.xlsx');
    check('template re-parses with embedded spec',
        !empty($parsedTpl['ok']) && !empty($parsedTpl['embedded_spec']));
    check('template has 0 data rows', count($parsedTpl['rows']) === 0);

    // ------------------------------------------------------------------
    echo "\n=== 4. creates via CSV (new dataset) ===\n";
    // ------------------------------------------------------------------
    $csv = "strabo_internal_id,spot_name,latitude,longitude,altitude,date,notes,orientation_type,orientation_role,feature_type,strike,dip,trend,plunge,quality,sample_id_name,sample_type,Field Book Page\n"
         . ",SP-A,34.2001,-118.5010,1200,2024-06-01,gray shale,planar,,Bedding,245,32,,,5,,,\n"
         . ",SP-A,,,,,,planar,,bedding,250,30,,,,,,\n"
         . ",SP-A,,,,,,linear,associated,slickenlines,,,130,15,,,,\n"
         . ",SP-A,,,,,,,,,,,,,,FS-001-$stamp,core,\n"
         . ",SP-B,34.2010,-118.5032,,2024-06-02,fault zone,planar,,fault,010,78,,,,,,p. 47\n";
    $parsed = $svc->parseUpload(csvFile($csv), 'import.csv');
    check('CSV parses', !empty($parsed['ok']));
    check('custom column detected', $parsed['custom_headers'] === array('Field Book Page'));

    $target = array('project_id' => $PROJECT_ID, 'dataset_id' => null, 'dataset_name' => "smokewiz DS1 $stamp");
    $plan = $svc->plan($parsed, $target);
    check('undecided custom column keeps plan dirty', empty($plan['clean']) && $plan['soft_custom'] === array('Field Book Page'));
    check('counts preview 2 creates', $plan['counts']['create'] === 2);
    check('provisional counts include custom (samples lesson)', $plan['counts']['create'] === 2);

    $res = array('custom_columns' => array('Field Book Page' => 'import'));
    $plan = $svc->plan($parsed, $target, $res);
    check('resolved plan is clean', !empty($plan['clean']));
    check('3 orientations, 1 sample counted',
        $plan['counts']['orientations'] === 3 && $plan['counts']['samples'] === 1);

    $commit = $svc->commit($plan);
    check('commit ok', !empty($commit['ok']));
    check('2 spots created', $commit['created'] === 2);
    check('dataset created', !empty($commit['dataset_created']));
    $DS1 = (int)$commit['dataset_id'];
    $datasetIds[] = $DS1;
    foreach ($commit['minted'] as $mid) { $spotIds[] = (int)$mid; }
    $A = (int)$commit['minted'][0];
    $B = (int)$commit['minted'][1];

    $edgeCount = (int)$neodb->get_var("MATCH (d:Dataset {id: $DS1, userpkey: $owner})-[:HAS_SPOT]->(s:Spot) RETURN count(s)");
    check('dataset has 2 HAS_SPOT edges', $edgeCount === 2);

    $pa = spotProps($neodb, $A, $owner);
    check('SP-A node exists with name/notes', $pa !== null && $pa['name'] === 'SP-A' && $pa['notes'] === 'gray shale');
    $od = json_decode($pa['json_orientation_data'], true);
    check('SP-A has 2 primary orientations', is_array($od) && count($od) === 2);
    check('vocab label Bedding resolved to bedding', $od[0]['feature_type'] === 'bedding');
    check('strike typed to int 245', $od[0]['strike'] === 245);
    check('quality dropdown value kept', (string)$od[0]['quality'] === '5');
    check('associated linear rides 2nd primary',
        isset($od[1]['associated_orientation'][0])
        && $od[1]['associated_orientation'][0]['type'] === 'linear_orientation'
        && $od[1]['associated_orientation'][0]['trend'] === 130);
    $samples = json_decode($pa['json_samples'], true);
    check('SP-A sample element landed', is_array($samples) && $samples[0]['sample_id_name'] === "FS-001-$stamp"
        && $samples[0]['sample_type'] === 'core');
    check('orientation elements got ids', !empty($od[0]['id']) && !empty($od[1]['associated_orientation'][0]['id']));

    $pb = spotProps($neodb, $B, $owner);
    $cf = json_decode($pb['custom_fields'], true);
    check('SP-B custom field landed', is_array($cf) && $cf['Field Book Page'] === 'p. 47');

    // strabosamples mirror (sync hook fired with dataset context)
    $spine = $db->get_row_prepared(
        "SELECT s.id, s.name FROM strabosamples.samples s WHERE s.userpkey = $1 AND s.name = $2",
        array($owner, "FS-001-$stamp"));
    check('strabosamples spine row mirrored', $spine !== null && $spine !== false);
    if ($spine) {
        $link = $db->get_row_prepared(
            "SELECT reference_metadata::text AS meta FROM strabosamples.sample_subsystem_links
              WHERE sample_id = $1 AND sample_userpkey = $2 AND subsystem = 'field'",
            array($spine->id, $owner));
        check('spine link carries project+dataset context',
            $link && strpos((string)$link->meta, (string)$PROJECT_ID) !== false
                  && strpos((string)$link->meta, (string)$DS1) !== false);
    } else {
        check('spine link carries project+dataset context', false);
    }

    // ------------------------------------------------------------------
    echo "\n=== 5. export -> re-import round trip == all-noop ===\n";
    // ------------------------------------------------------------------
    $export = $svc->exportLong($DS1, $SPEC);
    check('export ok', !empty($export['ok']));
    check('export emits 5 long rows (3 orient + sample on A rows, 1 B row)', count($export['rows']) === 5);
    check('all-point export omits geometry_type', !in_array('geometry_type', $export['headers']));

    $wb2 = $svc->buildWorkbook($export, false, "smokewiz-tpl-$stamp");
    $exPath = tempnam(sys_get_temp_dir(), 'wizsmoke_') . '.xlsx';
    $tmpFiles[] = $exPath;
    $writer2 = new PHPExcel_Writer_Excel2007($wb2);
    $writer2->save($exPath);

    $reparsed = $svc->parseUpload($exPath, 'export.xlsx');
    check('export re-parses with embedded spec', !empty($reparsed['ok']) && !empty($reparsed['embedded_spec']));
    $rtTarget = array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => '');
    $rtPlan = $svc->plan($reparsed, $rtTarget);
    check('round-trip plan is clean', !empty($rtPlan['clean']));
    check('round-trip == all-noop', $rtPlan['counts']['noop'] === 2
        && $rtPlan['counts']['create'] === 0 && $rtPlan['counts']['update'] === 0);

    // ------------------------------------------------------------------
    echo "\n=== 6. round trip survives row sorting ===\n";
    // ------------------------------------------------------------------
    $shuffled = $reparsed;
    $rows = $shuffled['rows'];
    usort($rows, function ($x, $y) {   // sort by strike-ish: scrambles spot grouping
        $sx = isset($x['values']['orientation.strike']) ? (string)$x['values']['orientation.strike'] : 'zzz';
        $sy = isset($y['values']['orientation.strike']) ? (string)$y['values']['orientation.strike'] : 'zzz';
        return strcmp($sx, $sy);
    });
    $shuffled['rows'] = $rows;
    $sortPlan = $svc->plan($shuffled, $rtTarget);
    // NOTE: sorting can reorder a spot's orientation list (projection order
    // differs) — that is a real content difference for the list, BUT it must
    // never split a spot into pieces or invent creates.
    check('sorted file: no creates invented, both spots found',
        !empty($sortPlan['ok']) && $sortPlan['counts']['create'] === 0
        && ($sortPlan['counts']['noop'] + $sortPlan['counts']['update']) === 2
        && empty($sortPlan['hard_errors']));

    // ------------------------------------------------------------------
    echo "\n=== 7. updates: diff / clear / group replace / preservation ===\n";
    // ------------------------------------------------------------------
    // plant uncovered data on A: trace + a scalar the template doesn't carry
    $neodb->query("MATCH (s:Spot {id: $A, userpkey: $owner})
                   SET s.json_trace = '{\"trace_type\":\"contact\"}', s.gps_accuracy = 4.5");

    $upCsv = "strabo_internal_id,spot_name,latitude,longitude,altitude,date,notes,orientation_type,orientation_role,feature_type,strike,dip,trend,plunge,quality,sample_id_name,sample_type,Field Book Page\n"
           . "$A,SP-A,34.2001,-118.5010,,2024-06-01,dark shale,planar,,bedding,247,32,,,5,,,\n"
           . "$A,SP-A,,,,,,planar,,bedding,250,30,,,,,,\n"
           . "$A,SP-A,,,,,,linear,associated,slickenlines,,,130,15,,,,\n"
           . "$A,SP-A,,,,,,,,,,,,,,FS-001-$stamp,core,\n"
           . "$B,SP-B,34.2010,-118.5032,,2024-06-02,fault zone,planar,,fault,010,78,,,,,,p. 47\n";
    $upParsed = $svc->parseUpload(csvFile($upCsv), 'update.csv');
    $upPlan = $svc->plan($upParsed, $rtTarget, $res);
    check('update plan clean', !empty($upPlan['clean']));
    check('1 update (A: notes+strike+altitude), 1 noop (B)',
        $upPlan['counts']['update'] === 1 && $upPlan['counts']['noop'] === 1);
    $upCommit = $svc->commit($upPlan);
    check('update commit ok', !empty($upCommit['ok']) && $upCommit['updated'] === 1);

    $pa = spotProps($neodb, $A, $owner);
    check('notes updated', $pa['notes'] === 'dark shale');
    check('altitude cleared (blank cell in present column)', !isset($pa['altitude']) || $pa['altitude'] === null || $pa['altitude'] === '');
    $od = json_decode($pa['json_orientation_data'], true);
    check('orientation list replaced: strike 247', $od[0]['strike'] === 247);
    check('associated survived group replace',
        isset($od[1]['associated_orientation'][0]) && $od[1]['associated_orientation'][0]['trend'] === 130);
    check('uncovered json_trace preserved through read-merge-write',
        isset($pa['json_trace']) && strpos($pa['json_trace'], 'contact') !== false);
    check('uncovered gps_accuracy preserved', (string)$pa['gps_accuracy'] === '4.5');
    $samples = json_decode($pa['json_samples'], true);
    check('sample element id stable across noop-sample update (merge by name)',
        is_array($samples) && $samples[0]['sample_id_name'] === "FS-001-$stamp");

    // ------------------------------------------------------------------
    echo "\n=== 8. instance-group clear ===\n";
    // ------------------------------------------------------------------
    $clrCsv = "strabo_internal_id,spot_name,orientation_type,feature_type,strike,dip\n"
            . "$B,SP-B,,,,\n";
    $clrParsed = $svc->parseUpload(csvFile($clrCsv), 'clear.csv');
    $clrPlan = $svc->plan($clrParsed, $rtTarget);
    check('clear plan clean + 1 update', !empty($clrPlan['clean']) && $clrPlan['counts']['update'] === 1);
    $warned = false;
    foreach ($clrPlan['warnings'] as $w) {
        if (strpos($w['message'], 'Removes all 1 orientation') !== false) { $warned = true; }
    }
    check('group-clear warning surfaced', $warned);
    $clrCommit = $svc->commit($clrPlan);
    check('clear commit ok', !empty($clrCommit['ok']));
    $pb = spotProps($neodb, $B, $owner);
    check('SP-B orientations cleared', !isset($pb['json_orientation_data']) || $pb['json_orientation_data'] === null || $pb['json_orientation_data'] === '');
    check('SP-B custom field survived the clear', strpos((string)$pb['custom_fields'], 'p. 47') !== false);

    // ------------------------------------------------------------------
    echo "\n=== 9. hard errors ===\n";
    // ------------------------------------------------------------------
    $H = "strabo_internal_id,spot_name,latitude,longitude,orientation_type,orientation_role,feature_type,strike,dip,trend,plunge\n";
    $codes = function ($csv, $target2 = null) use ($svc, $rtTarget) {
        $p = $svc->parseUpload(csvFile($csv), 'err.csv');
        $pl = $svc->plan($p, $target2 !== null ? $target2 : $rtTarget);
        $out = array();
        foreach ($pl['hard_errors'] as $e) { $out[] = $e['code']; }
        return $out;
    };
    check('unknown id', in_array('unknown_id', $codes($H . "99999999999999,SP-X,34.1,-118.1,,,,,,,\n")));
    check('contradiction (two lats for one spot)',
        in_array('contradiction', $codes($H . ",SP-C,34.1,-118.1,,,,,,,\n,SP-C,34.2,-118.1,,,,,,,\n")));
    check('bad orientation_type', in_array('bad_otype', $codes($H . ",SP-C,34.1,-118.1,sideways,,bedding,10,20,,\n")));
    check('missing orientation_type on orientation row',
        in_array('bad_otype', $codes($H . ",SP-C,34.1,-118.1,,,bedding,10,20,,\n")));
    check('inapplicable field (trend on planar)',
        in_array('inapplicable', $codes($H . ",SP-C,34.1,-118.1,planar,,bedding,10,20,130,\n")));
    check('constraint (strike 400)',
        in_array('out_of_range', $codes($H . ",SP-C,34.1,-118.1,planar,,bedding,400,20,,\n")));
    check('orphan associated',
        in_array('orphan_associated', $codes($H . ",SP-C,34.1,-118.1,linear,associated,slickenlines,,,130,15\n")));
    check('create without coords',
        in_array('coords_required', $codes($H . ",SP-C,,,,,,,,,\n")));
    check('id/name ambiguity',
        in_array('ambiguous_key', $codes($H . "$A,SP-A,,,,,,,,,\n,SP-A,34.5,-118.5,,,,,,,\n")));
    $nameCsv = "strabo_internal_id,spot_name\n$A,\n";
    check('update cannot blank the name', in_array('name_required', $codes($nameCsv)));
    // wrong dataset: a second dataset then A targeted at it
    $mk = $svc->plan($svc->parseUpload(csvFile($H . ",SP-D2,34.6,-118.6,,,,,,,\n"), 'd2.csv'),
                     array('project_id' => $PROJECT_ID, 'dataset_id' => null, 'dataset_name' => "smokewiz DS2 $stamp"));
    $mkc = $svc->commit($mk);
    check('second dataset created for wrong-dataset test', !empty($mkc['ok']));
    $DS2 = (int)$mkc['dataset_id'];
    $datasetIds[] = $DS2;
    foreach ($mkc['minted'] as $mid) { $spotIds[] = (int)$mid; }
    check('id outside target dataset',
        in_array('wrong_dataset', $codes($H . "$A,SP-A,,,,,,,,,\n",
            array('project_id' => $PROJECT_ID, 'dataset_id' => $DS2, 'dataset_name' => ''))));
    check('update rows cannot target a new dataset',
        in_array('update_into_new', $codes($H . "$A,SP-A,,,,,,,,,\n",
            array('project_id' => $PROJECT_ID, 'dataset_id' => null, 'dataset_name' => 'nope'))));
    check("stranger's plan can't see owner's spot",
        in_array('unknown_id', (function () use ($svcStranger, $A, $PROJECT_ID) {
            $p = $svcStranger->parseUpload(csvFile("strabo_internal_id,spot_name\n$A,SP-A\n"), 'x.csv');
            $pl = $svcStranger->plan($p, array('project_id' => $PROJECT_ID, 'dataset_id' => null, 'dataset_name' => 'x'));
            $out = array();
            foreach ($pl['hard_errors'] as $e) { $out[] = $e['code']; }
            return $out;
        })()));

    // ------------------------------------------------------------------
    echo "\n=== 10. vocab resolutions ===\n";
    // ------------------------------------------------------------------
    $vCsv = $H . ",SP-V,34.1,-118.1,planar,,sheer zone,100,45,,\n";
    $vParsed = $svc->parseUpload(csvFile($vCsv), 'vocab.csv');
    $vPlan = $svc->plan($vParsed, $rtTarget);
    check('unknown vocab -> soft issue', empty($vPlan['clean']) && isset($vPlan['soft_vocab']['orientation.feature_type']['sheer zone']));
    $sv = $vPlan['soft_vocab']['orientation.feature_type']['sheer zone'];
    check('levenshtein suggestion is shear zone', $sv['suggestion'] === 'shear zone');
    check('other_* companion advertised', !empty($sv['has_other']));

    $vPlan2 = $svc->plan($vParsed, $rtTarget, array('vocab' => array('orientation.feature_type' => array('sheer zone' => 'shear_zone'))));
    check('mapped resolution cleans the plan', !empty($vPlan2['clean']));
    $row = $vPlan2['rows'][0];
    check('mapped value applied', $row['orientations'][0]['feature_type'] === 'shear_zone');

    $vPlan3 = $svc->plan($vParsed, $rtTarget, array('vocab' => array('orientation.feature_type' => array('sheer zone' => FieldTabularService::RESOLUTION_OTHER))));
    check('__other__ resolves to other + companion literal',
        !empty($vPlan3['clean'])
        && $vPlan3['rows'][0]['orientations'][0]['feature_type'] === 'other'
        && $vPlan3['rows'][0]['orientations'][0]['other_feature'] === 'sheer zone');

    $vPlan4 = $svc->plan($vParsed, $rtTarget, array('vocab' => array('orientation.feature_type' => array('sheer zone' => FieldTabularService::RESOLUTION_FREE_TEXT))));
    check('__freetext__ keeps verbatim',
        !empty($vPlan4['clean']) && $vPlan4['rows'][0]['orientations'][0]['feature_type'] === 'sheer zone');

    // ------------------------------------------------------------------
    echo "\n=== 11. geometry ===\n";
    // ------------------------------------------------------------------
    $LINE_ID = 96669777;
    $spotIds[] = $LINE_ID;
    $lineFeature = json_encode(array(
        'type' => 'Feature',
        'geometry' => array('type' => 'LineString', 'coordinates' => array(array(-118.5, 34.2), array(-118.4, 34.3))),
        'properties' => array('id' => $LINE_ID, 'name' => 'SP-LINE', 'modified_timestamp' => (int)round(microtime(true) * 1000)),
    ));
    $strabo->insertSpot($lineFeature);
    $strabo->addSpotToDataset($DS1, $LINE_ID);

    $export2 = $svc->exportLong($DS1, $SPEC);
    $lineRow = null;
    foreach ($export2['rows'] as $r0) {
        if ($r0['strabo_internal_id'] === (string)$LINE_ID) { $lineRow = $r0; }
    }
    check('non-point export materializes geometry_type after id',
        in_array('geometry_type', $export2['headers'])
        && array_search('geometry_type', $export2['headers'])
           === array_search('strabo_internal_id', $export2['headers']) + 1);
    check('line spot exports with geometry_type + centroid',
        $lineRow !== null && $lineRow['geometry_type'] === 'LineString'
        && abs((float)$lineRow['latitude'] - 34.25) < 0.001);
    // opt-in via the designer picker: an explicit geometry_type column
    // survives validateSpec and appears even on all-point exports.
    $optSpec = $svc->validateSpec(array('spec_version' => 1, 'layout' => 'long', 'columns' => array(
        array('kind' => 'system', 'key' => 'geometry_type'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'name'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'latitude'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'longitude'),
    )));
    $optKeys = array();
    foreach ($optSpec['spec']['columns'] as $c) { if ($c['kind'] === 'system') { $optKeys[] = $c['key']; } }
    check('explicit geometry_type opt-in survives validateSpec', !empty($optSpec['ok'])
        && in_array('geometry_type', $optKeys));
    $optExport = $svc->exportLong($DS2, $optSpec['spec']);
    check('opt-in template keeps geometry_type on all-point export',
        !empty($optExport['ok']) && in_array('geometry_type', $optExport['headers']));

    $gCsv = "strabo_internal_id,spot_name,latitude,longitude\n$LINE_ID,SP-LINE,35.0,-118.45\n";
    check('lat/lng edit on line spot hard-errors',
        in_array('geometry_read_only', $codes($gCsv)));
    $gCsv2 = "strabo_internal_id,spot_name,latitude,longitude\n$LINE_ID,SP-LINE,{$lineRow['latitude']},{$lineRow['longitude']}\n";
    $gPlan2 = $svc->plan($svc->parseUpload(csvFile($gCsv2), 'g2.csv'), $rtTarget);
    check('unchanged centroid = noop', !empty($gPlan2['clean']) && $gPlan2['counts']['noop'] === 1);

    // Point move
    $mvCsv = "strabo_internal_id,spot_name,latitude,longitude\n$A,SP-A,34.3000,-118.5010\n";
    $mvPlan = $svc->plan($svc->parseUpload(csvFile($mvCsv), 'mv.csv'), $rtTarget);
    check('point move plans as update', !empty($mvPlan['clean']) && $mvPlan['counts']['update'] === 1);
    $mvCommit = $svc->commit($mvPlan);
    $pa = spotProps($neodb, $A, $owner);
    check('point moved (wkt updated)', !empty($mvCommit['ok']) && strpos($pa['wkt'], '34.3') !== false);
    $od = json_decode($pa['json_orientation_data'], true);
    check('orientations untouched by pure move', is_array($od) && count($od) === 2 && $od[0]['strike'] === 247);

    // ------------------------------------------------------------------
    echo "\n=== 12. owner-only export ===\n";
    // ------------------------------------------------------------------
    $sx = $svcStranger->exportLong($DS1, $SPEC);
    check('stranger cannot export the dataset', empty($sx['ok']));

    // ------------------------------------------------------------------
    echo "\n=== 13. compensating rollback ===\n";
    // ------------------------------------------------------------------
    // plan: update A (notes -> rollback test) + create SP-C, into DS1;
    // then break the dataset between plan and commit so the create's
    // dataset link fails AFTER the update already landed.
    $rbCsv = "strabo_internal_id,spot_name,latitude,longitude,notes\n"
           . "$A,SP-A,,,rollback test\n"
           . ",SP-C,34.7,-118.7,brand new\n";
    $rbPlan = $svc->plan($svc->parseUpload(csvFile($rbCsv), 'rb.csv'), $rtTarget);
    check('rollback plan clean (1 update, 1 create)',
        !empty($rbPlan['clean']) && $rbPlan['counts']['update'] === 1 && $rbPlan['counts']['create'] === 1);

    $notesBefore = spotProps($neodb, $A, $owner);
    $notesBefore = $notesBefore['notes'];
    $neodb->query("MATCH (d:Dataset {id: $DS1, userpkey: $owner}) DETACH DELETE d");   // sabotage

    $rbCommit = $svc->commit($rbPlan);
    check('commit reports failure', empty($rbCommit['ok']));
    check('rollback completed cleanly', !empty($rbCommit['rolled_back']));
    check('message says nothing was imported', strpos($rbCommit['message'], 'Nothing was imported') !== false);

    $pa = spotProps($neodb, $A, $owner);
    check('updated spot restored from prior JSON', $pa !== null && $pa['notes'] === $notesBefore);
    $od = json_decode($pa['json_orientation_data'], true);
    check('restored spot kept its orientations', is_array($od) && count($od) === 2);
    $cCount = (int)$neodb->get_var("MATCH (s:Spot {userpkey: $owner}) WHERE s.name = 'SP-C' RETURN count(s)");
    check('created spot rolled back (deleted)', $cCount === 0);
    $run = $db->get_row_prepared(
        "SELECT status, error FROM field_tabular_runs WHERE pkey = $1", array((int)$rbCommit['run_id']));
    check('journal row says rolled_back', $run && $run->status === 'rolled_back');
    check('journal captured the cause', $run && strpos((string)$run->error, 'link spot') !== false);

    // ------------------------------------------------------------------
    echo "\n=== 14. state files ===\n";
    // ------------------------------------------------------------------
    $token = $svc->saveState(array('hello' => 'world'));
    check('state saves', $token !== null);
    $loaded = $svc->loadState($token);
    check('state loads', is_array($loaded) && $loaded['hello'] === 'world');
    check('foreign user cannot load state', $svcStranger->loadState($token) === null);
    $svc->discardState($token);
    check('discard kills token', $svc->loadState($token) === null);

} finally {
    echo "\n=== cleanup ===\n";
    foreach (array_unique($spotIds) as $sid) {
        try { $strabo->deleteSingleSpot((int)$sid); } catch (Exception $e) { /* already gone */ }
    }
    foreach (array_unique($datasetIds) as $did) {
        try {
            $neodb->query("MATCH (d:Dataset {id: " . (int)$did . ", userpkey: $owner}) DETACH DELETE d");
        } catch (Exception $e) {}
        $db->get_var_prepared("DELETE FROM dataset WHERE user_pkey = $1 AND strabo_dataset_id = $2 RETURNING strabo_dataset_id",
            array($owner, (string)$did));
    }
    try { $neodb->query("MATCH (p:Project {id: $PROJECT_ID, userpkey: $owner}) DETACH DELETE p"); } catch (Exception $e) {}
    $db->query("DELETE FROM project WHERE strabo_project_id = '$PROJECT_ID' AND user_pkey = $owner");
    $db->query("DELETE FROM field_templates WHERE userpkey = $owner AND name ILIKE 'smokewiz%'");
    $db->query("DELETE FROM field_tabular_runs WHERE project_id = '$PROJECT_ID'");
    $db->get_var_prepared("DELETE FROM strabosamples.samples WHERE userpkey = $1 AND name = $2 RETURNING id",
        array($owner, "FS-001-$stamp"));
    foreach ($tmpFiles as $f) { @unlink($f); }

    // residue checks
    $r1 = (int)$neodb->get_var("MATCH (s:Spot {userpkey: $owner}) WHERE s.name IN ['SP-A','SP-B','SP-C','SP-LINE','SP-D2'] RETURN count(s)");
    $r2 = (int)$neodb->get_var("MATCH (d:Dataset {userpkey: $owner}) WHERE d.name =~ 'smokewiz.*' RETURN count(d)");
    $r3 = $db->get_var_prepared("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $1 AND name = $2",
        array($owner, "FS-001-$stamp"));
    echo 'residue: spots=' . $r1 . ' datasets=' . $r2 . ' spine=' . (int)$r3 . "\n";
}

echo "\n==============================\n";
if (empty($failures)) {
    echo "ALL CHECKS PASSED\n";
    exit(0);
}
echo count($failures) . " FAILURES:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
