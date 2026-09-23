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
    // built-in Basic layout resolves for anyone with no row; saved by pkey; junk = null
    $basic = $svcStranger->resolveTemplate('basic');
    $basicHeaders = array();
    foreach ($svcStranger->columnDefs($basic['spec']) as $d) { $basicHeaders[] = $d['header']; }
    check('resolveTemplate(basic): builtin, pkey 0, Basic name',
        $basic !== null && $basic['builtin'] === true && $basic['pkey'] === 0 && $basic['name'] === 'Basic');
    check('resolveTemplate(basic): validated spec with id + strike + orientation_type columns',
        in_array('strabo_internal_id', $basicHeaders) && in_array('strike', $basicHeaders) && in_array('orientation_type', $basicHeaders));
    check('resolveTemplate(blank) = basic', $svc->resolveTemplate('')['builtin'] === true);
    $own = $svc->resolveTemplate((string)$tplPkey);
    check('resolveTemplate(pkey): saved template, not builtin', $own !== null && $own['builtin'] === false && $own['pkey'] === $tplPkey);
    check('resolveTemplate(foreign pkey) = null', $svcStranger->resolveTemplate((string)$tplPkey) === null);
    check('resolveTemplate(junk) = null', $svc->resolveTemplate('12abc') === null && $svc->resolveTemplate('-1') === null);
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
    $dataSheet = $wb->getSheetByName('Data');
    check('band row 1: StraboSpot over id, Spot over name',
        $dataSheet->getCell('A1')->getValue() === 'StraboSpot'
        && $dataSheet->getCell('B1')->getValue() === 'Spot');
    check('headers on row 2', $dataSheet->getCell('A2')->getValue() === 'strabo_internal_id'
        && $dataSheet->getCell('B2')->getValue() === 'name');
    check('band sections merged', count($dataSheet->getMergeCells()) >= 2);
    check('freeze pane below headers', $dataSheet->getFreezePane() === 'A3');
    $tplPath = tempnam(sys_get_temp_dir(), 'wizsmoke_') . '.xlsx';
    $tmpFiles[] = $tplPath;
    $writer = new PHPExcel_Writer_Excel2007($wb);
    $writer->save($tplPath);
    $parsedTpl = $svc->parseUpload($tplPath, 'template.xlsx');
    check('template re-parses with embedded spec',
        !empty($parsedTpl['ok']) && !empty($parsedTpl['embedded_spec']));
    check('template has 0 data rows', count($parsedTpl['rows']) === 0);

    // Protection regression (2026-07-04): blank templates used to lock
    // NOTHING below the header (the lock loop only ran when data rows
    // existed) — a hand-typed id is the wrong-spot-update / duplicate-create
    // bug class. The id column must lock all the way down; ordinary field
    // cells stay editable.
    $lockWb = PHPExcel_IOFactory::load($tplPath);
    $lockSheet = $lockWb->getSheetByName('Data');
    check('blank template: Data sheet protection enabled',
        $lockSheet->getProtection()->getSheet() === true);
    check('blank template: id cells locked at row 3 and far below the data area',
        $lockSheet->getStyle('A3')->getProtection()->getLocked() === PHPExcel_Style_Protection::PROTECTION_PROTECTED
        && $lockSheet->getStyle('A400')->getProtection()->getLocked() === PHPExcel_Style_Protection::PROTECTION_PROTECTED);
    check('blank template: ordinary field cells stay editable',
        $lockSheet->getStyle('B3')->getProtection()->getLocked() === PHPExcel_Style_Protection::PROTECTION_UNPROTECTED
        && $lockSheet->getStyle('B400')->getProtection()->getLocked() === PHPExcel_Style_Protection::PROTECTION_UNPROTECTED);

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

    // header-row detection: junk title line above the headers must lose to
    // the real header row (same scan that skips the XLSX section band)
    $titled = "My field notes June 2024,,,\n" . $csv;
    $parsedTitled = $svc->parseUpload(csvFile($titled), 'titled.csv');
    check('title line above headers skipped by detection',
        !empty($parsedTitled['ok']) && count($parsedTitled['rows']) === count($parsed['rows'])
        && $parsedTitled['custom_headers'] === array('Field Book Page'));

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

    // My Field Data "Last Uploaded" reads Project.uploaddate — a wizard
    // import must stamp it (regression: it bypassed insertProject).
    $projUpload = (int)$neodb->get_var("MATCH (p:Project {id: $PROJECT_ID, userpkey: $owner}) RETURN p.uploaddate");
    check('project uploaddate stamped by create commit', $projUpload >= $stamp);

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

    // the same id-less file planned again into the dataset it just filled:
    // both creates collide by name -> Heads up warnings, plan still clean
    $again = $svc->plan($parsed, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''),
                        array('custom_columns' => array('Field Book Page' => 'import')));
    $nameHits = array();
    foreach ($again['warnings'] as $w) { if ($w['code'] === 'name_exists') { $nameHits[] = $w; } }
    check('re-planning the id-less file into its dataset warns per colliding create (SP-A, SP-B)',
        count($nameHits) === 2 && $nameHits[0]['row'] === 2 && strpos($nameHits[0]['message'], '"SP-A"') !== false);
    $summaryW = null; foreach ($again['warnings'] as $w) { if ($w['code'] === 'name_exists_summary') { $summaryW = $w; } }
    check('collision summary warning present (after the exact-file line) and the plan stays clean (warning, not a block)',
        $summaryW !== null && strpos($summaryW['message'], '2 new spots') !== false
        && !empty($again['clean']) && $again['counts']['create'] === 2);
    // exact-file guard: the committed run journaled the sha256; the same bytes
    // planned again (same dataset, or a new dataset in the project) are named
    $runRow = $db->get_row_prepared("SELECT file_sha256, file_name FROM field_tabular_runs WHERE pkey = $1", array($commit['run_id']));
    check('journal row carries the file sha256 + client name',
        $runRow && $runRow->file_sha256 === $parsed['file_sha256'] && strlen($runRow->file_sha256) === 64 && $runRow->file_name === 'import.csv');
    $sameFile = array(); foreach ($again['warnings'] as $w) { if ($w['code'] === 'same_file_imported') { $sameFile[] = $w; } }
    check('same file into the same dataset: "This exact file was already imported into this dataset" leads the warnings',
        count($sameFile) === 1 && $again['warnings'][0]['code'] === 'same_file_imported'
        && strpos($sameFile[0]['message'], 'into this dataset on') !== false && strpos($sameFile[0]['message'], 'run #' . $commit['run_id']) !== false
        && strpos($sameFile[0]['message'], '2 spots created') !== false);
    $fresh = $svc->plan($parsed, array('project_id' => $PROJECT_ID, 'dataset_id' => null, 'dataset_name' => "smokewiz-fresh-$stamp"),
                        array('custom_columns' => array('Field Book Page' => 'import')));
    $freshHits = 0;
    foreach ($fresh['warnings'] as $w) { if (strpos($w['code'], 'name_exists') === 0) { $freshHits++; } }
    check('no collision warnings when the target is a new dataset', $freshHits === 0);
    $freshFile = null; foreach ($fresh['warnings'] as $w) { if ($w['code'] === 'same_file_imported') { $freshFile = $w; } }
    check('same file into a NEW dataset of the same project still names the earlier dataset',
        $freshFile !== null && strpos($freshFile['message'], 'of this project') !== false);
    $otherBytes = $svc->parseUpload(csvFile($csv . ",SP-C,34.3,-118.6,,,,,,,,,,,,,,\n"), 'import2.csv');
    $otherPlan = $svc->plan($otherBytes, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''),
                            array('custom_columns' => array('Field Book Page' => 'import')));
    $otherFile = 0; foreach ($otherPlan['warnings'] as $w) { if ($w['code'] === 'same_file_imported') { $otherFile++; } }
    check('a file with different bytes (one row added) is not called a repeat, only the name collisions are', $otherFile === 0);
    check('runExportContext: committed run resolves to its dataset + spec; foreign / unknown run = null',
        ($rc = $svc->runExportContext($commit['run_id'])) !== null && $rc['dataset_id'] === $DS1 && isset($rc['spec']['columns'])
        && $svcStranger->runExportContext($commit['run_id']) === null && $svc->runExportContext(0) === null);

    // integer-looking text values must stay strings end to end (PHP array
    // keys turned "2" into int 2 before 2026-09-18; "02" was never affected)
    $numCsv = "strabo_internal_id,spot_name,latitude,longitude,notes\n,2,34.21,-118.51,42\n,02,34.22,-118.52,seven\n";
    $numParsed = $svc->parseUpload(csvFile($numCsv), 'num.csv');
    $numPlan = $svc->plan($numParsed, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''));
    $numNames = array(); foreach ($numPlan['rows'] as $pr) { $numNames[] = $pr['name']; }
    check('plan keeps integer-looking names and notes as strings ("2", "02", notes "42")',
        $numNames === array('2', '02') && $numPlan['rows'][0]['set']['notes'] === '42' && is_string($numPlan['rows'][0]['name']));
    $numCommit = $svc->commit($numPlan);
    check('numeric-name commit ok', !empty($numCommit['ok']) && $numCommit['created'] === 2);
    foreach ($numCommit['minted'] as $mid) { $spotIds[] = (int)$mid; }
    $numRows = $neodb->get_results("MATCH (d:Dataset {id: $DS1, userpkey: $owner})-[:HAS_SPOT]->(s:Spot) WHERE s.id IN [" . implode(',', $numCommit['minted']) . "] RETURN s.name AS name, s.notes AS notes ORDER BY s.id");
    $storedOk = true;
    foreach ((array)$numRows as $r) { if (!is_string($r->value('name'))) { $storedOk = false; } }
    check('Neo4j stores the names as strings, not integers', $storedOk && count((array)$numRows) === 2
        && in_array('2', array_map(function ($r) { return $r->value('name'); }, (array)$numRows), true));
    $numAgain = $svc->plan($numParsed, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => ''));
    $numHits = 0; foreach ($numAgain['warnings'] as $w) { if ($w['code'] === 'name_exists') { $numHits++; } }
    check('re-planning the numeric-name file warns for both "2" and "02"', $numHits === 2);
    // leave DS1 as section 5 expects it (SP-A + SP-B only)
    foreach ($numCommit['minted'] as $mid) { try { $strabo->deleteSingleSpot((int)$mid); } catch (Exception $e) {} }
    check('numeric-name probe spots removed', (int)$neodb->get_var("MATCH (d:Dataset {id: $DS1, userpkey: $owner})-[:HAS_SPOT]->(s:Spot) RETURN count(s)") === 2);

    // ------------------------------------------------------------------
    echo "\n=== 5. export -> re-import round trip == all-noop ===\n";
    // ------------------------------------------------------------------
    $export = $svc->exportLong($DS1, $SPEC);
    check('export ok', !empty($export['ok']));
    check('export emits 5 long rows (3 orient + sample on A rows, 1 B row)', count($export['rows']) === 5);
    check('all-point export omits geometry_type', !in_array('geometry_type', $export['headers']));
    // Coordinates on EVERY row, not just a spot's first (Joe, JCU 2026-09-23):
    // each measurement row is plottable on its own; the round trip below
    // proves the upload side takes identical repeats as one value.
    $coordRows = 0;
    foreach ($export['rows'] as $r0) {
        $exp = ($r0['name'] === 'SP-A') ? array(34.2001, -118.5010) : array(34.2010, -118.5032);
        if ($r0['latitude'] !== '' && $r0['longitude'] !== ''
            && abs((float)$r0['latitude'] - $exp[0]) < 0.00001 && abs((float)$r0['longitude'] - $exp[1]) < 0.00001) {
            $coordRows++;
        }
    }
    check('export repeats the spot latitude/longitude on every row (5/5)', $coordRows === 5);

    // Role column materializes (dataset has an associated orientation) and
    // primary rows say "primary" explicitly, never blank (Jason 2026-08-21:
    // blank-on-export read as data loss; import keeps accepting blank).
    $roleTally = array();
    foreach ($export['rows'] as $r0) {
        $rv = isset($r0['orientation_role']) ? $r0['orientation_role'] : '(no col)';
        $roleTally[$rv] = (isset($roleTally[$rv]) ? $roleTally[$rv] : 0) + 1;
    }
    check('export writes explicit primary roles (3 primary / 1 associated / 1 blank sample row)',
        in_array('orientation_role', $export['headers'])
        && isset($roleTally['primary']) && $roleTally['primary'] === 3
        && isset($roleTally['associated']) && $roleTally['associated'] === 1
        && isset($roleTally['']) && $roleTally[''] === 1);

    $wb2 = $svc->buildWorkbook($export, false, "smokewiz-tpl-$stamp");
    $exPath = tempnam(sys_get_temp_dir(), 'wizsmoke_') . '.xlsx';
    $tmpFiles[] = $exPath;
    $writer2 = new PHPExcel_Writer_Excel2007($wb2);
    $writer2->save($exPath);

    // Same protection regression on a FILLED export: id cells must stay
    // locked below the last data row (where users add new rows / paste).
    $exLockWb = PHPExcel_IOFactory::load($exPath);
    $exLockSheet = $exLockWb->getSheetByName('Data');
    check('filled export: id cells locked on data rows AND below them',
        $exLockSheet->getProtection()->getSheet() === true
        && $exLockSheet->getStyle('A3')->getProtection()->getLocked() === PHPExcel_Style_Protection::PROTECTION_PROTECTED
        && $exLockSheet->getStyle('A50')->getProtection()->getLocked() === PHPExcel_Style_Protection::PROTECTION_PROTECTED);

    $reparsed = $svc->parseUpload($exPath, 'export.xlsx');
    check('export re-parses with embedded spec', !empty($reparsed['ok']) && !empty($reparsed['embedded_spec']));
    $rtTarget = array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => '');
    $rtPlan = $svc->plan($reparsed, $rtTarget);
    check('round-trip plan is clean', !empty($rtPlan['clean']));
    check('round-trip == all-noop', $rtPlan['counts']['noop'] === 2
        && $rtPlan['counts']['create'] === 0 && $rtPlan['counts']['update'] === 0);

    // Confirming an all-noop plan writes nothing, so it must not pretend an
    // upload happened: both display stamps stay untouched.
    $neodb->query("MATCH (p:Project {id: $PROJECT_ID, userpkey: $owner}) SET p.uploaddate = 1000");
    $neodb->query("MATCH (d:Dataset {id: $DS1, userpkey: $owner}) SET d.modified_timestamp = 2000");
    $rtCommit = $svc->commit($rtPlan);
    check('all-noop commit ok with zero writes',
        !empty($rtCommit['ok']) && $rtCommit['created'] === 0 && $rtCommit['updated'] === 0);
    check('all-noop commit leaves project uploaddate alone',
        (int)$neodb->get_var("MATCH (p:Project {id: $PROJECT_ID, userpkey: $owner}) RETURN p.uploaddate") === 1000);
    check('all-noop commit leaves dataset modified_timestamp alone',
        (int)$neodb->get_var("MATCH (d:Dataset {id: $DS1, userpkey: $owner}) RETURN d.modified_timestamp") === 2000);

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
    // Stale both display stamps first: a commit with real writes into an
    // EXISTING dataset must refresh Project.uploaddate ("Last Uploaded")
    // and Dataset.modified_timestamp ("Modified" column).
    $neodb->query("MATCH (p:Project {id: $PROJECT_ID, userpkey: $owner}) SET p.uploaddate = 1000");
    $neodb->query("MATCH (d:Dataset {id: $DS1, userpkey: $owner}) SET d.modified_timestamp = 2000");
    $upCommit = $svc->commit($upPlan);
    check('update commit ok', !empty($upCommit['ok']) && $upCommit['updated'] === 1);
    check('project uploaddate refreshed by update commit',
        (int)$neodb->get_var("MATCH (p:Project {id: $PROJECT_ID, userpkey: $owner}) RETURN p.uploaddate") >= $stamp);
    check('existing dataset modified_timestamp refreshed by update commit',
        (float)$neodb->get_var("MATCH (d:Dataset {id: $DS1, userpkey: $owner}) RETURN d.modified_timestamp") >= $stamp * 1000);

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
    echo "\n=== 9b. import as new spots (ids only group rows) ===\n";
    // ------------------------------------------------------------------
    // The DS1 export (ids of A + B, embedded spec) copied into DS2: without
    // the flag every spot is a wrong_dataset error; with it, two creates
    // carrying all instances, and the created spots are new ids in DS2.
    $anParsed = $svc->parseUpload($exPath, 'student_export.xlsx');
    $anOff = $svc->plan($anParsed, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS2, 'dataset_name' => ''));
    $anOffCodes = array();
    foreach ($anOff['hard_errors'] as $e) { $anOffCodes[] = $e['code']; }
    check('flag off: export of DS1 into DS2 = wrong_dataset per spot',
        array_count_values($anOffCodes) === array('wrong_dataset' => 2));
    check('wrong_dataset message points at the option',
        strpos($anOff['hard_errors'][0]['message'], 'Import as new spots') !== false);
    $anOn = $svc->plan($anParsed, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS2, 'dataset_name' => '', 'as_new' => true));
    check('flag on: clean plan, 2 creates, 0 updates/noops',
        !empty($anOn['clean']) && $anOn['counts']['create'] === 2
        && $anOn['counts']['update'] === 0 && $anOn['counts']['noop'] === 0);
    check('flag on: instances carried (3 orientations, 1 sample)',
        $anOn['counts']['orientations'] === 3 && $anOn['counts']['samples'] === 1);
    check('flag on: plan echoes as_new in its target', !empty($anOn['target']['as_new']));
    $notesA0 = spotProps($neodb, $A, $owner)['notes'];
    $anCommit = $svc->commit($anOn);
    check('flag on: commit creates 2 spots', !empty($anCommit['ok']) && $anCommit['created'] === 2);
    foreach ($anCommit['minted'] as $mid) { $spotIds[] = (int)$mid; }
    check('flag on: created ids are new (not A / B)',
        !in_array($A, array_map('intval', $anCommit['minted'])) && !in_array($B, array_map('intval', $anCommit['minted'])));
    $inDs2 = (int)$neodb->get_var("MATCH (d:Dataset {id: $DS2, userpkey: $owner})-[:HAS_SPOT]->(s:Spot) WHERE s.name IN ['SP-A','SP-B'] RETURN count(s)");
    check('flag on: copies live in DS2', $inDs2 === 2);
    $pa2 = spotProps($neodb, (int)$anCommit['minted'][0], $owner);
    check('flag on: copy carries the orientations', strpos((string)$pa2['json_orientation_data'], 'slickenlines') !== false);
    check('originals in DS1 untouched (A keeps its notes, DS1 still 2 + line spots only)',
        spotProps($neodb, $A, $owner)['notes'] === $notesA0
        && (int)$neodb->get_var("MATCH (d:Dataset {id: $DS1, userpkey: $owner})-[:HAS_SPOT]->(s:Spot) RETURN count(s)") === 2);

    // Ids the account has never seen (a student's file): create, never unknown_id.
    $stuCsv = $H . "88880000000001,01,34.71,-118.71,planar,,bedding,100,20,,\n"
                  . "88880000000001,01,,,linear,,intersection,,,40,10\n";
    $stuOff = $codes($stuCsv, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS2, 'dataset_name' => ''));
    check('flag off: foreign id = unknown_id', in_array('unknown_id', $stuOff));
    $stuOn = $svc->plan($svc->parseUpload(csvFile($stuCsv), 'student.csv'),
                        array('project_id' => $PROJECT_ID, 'dataset_id' => $DS2, 'dataset_name' => '', 'as_new' => true));
    check('flag on: foreign id groups 2 rows into 1 create with 2 orientations',
        !empty($stuOn['clean']) && $stuOn['counts']['create'] === 1 && $stuOn['counts']['orientations'] === 2);

    // Twenty students paste into one file: same spot NAME, different ids ->
    // separate spots (grouping stays on the id), not one merged spot.
    $mergedCsv = $H . "88880000000001,01,34.71,-118.71,planar,,bedding,100,20,,\n"
                     . "88880000000002,01,34.72,-118.72,planar,,bedding,110,25,,\n";
    $mgOn = $svc->plan($svc->parseUpload(csvFile($mergedCsv), 'merged.csv'),
                       array('project_id' => $PROJECT_ID, 'dataset_id' => null, 'dataset_name' => 'new one', 'as_new' => true));
    check('flag on: same name + different ids = 2 creates (no contradiction), new-dataset target ok',
        !empty($mgOn['clean']) && $mgOn['counts']['create'] === 2);
    // Flag on but no ids at all: unchanged behavior (name grouping, creates).
    $noIdOn = $svc->plan($svc->parseUpload(csvFile($H . ",SP-N,34.1,-118.1,,,,,,,\n"), 'noid.csv'),
                         array('project_id' => $PROJECT_ID, 'dataset_id' => $DS2, 'dataset_name' => '', 'as_new' => true));
    check('flag on: id-less rows still create', !empty($noIdOn['clean']) && $noIdOn['counts']['create'] === 1);
    // Flag on into the dataset the file came from: creates + the name Heads up.
    $selfOn = $svc->plan($anParsed, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS1, 'dataset_name' => '', 'as_new' => true));
    $selfCodes = array();
    foreach ($selfOn['warnings'] as $w) { $selfCodes[] = $w['code']; }
    check('flag on into the source dataset: 2 creates + name_exists Heads up',
        $selfOn['counts']['create'] === 2 && in_array('name_exists_summary', $selfCodes));
    // Flag on cannot smuggle a bad id past validation, and owner checks still hold.
    check('flag on: malformed id still bad_id',
        in_array('bad_id', (function () use ($svc, $H, $PROJECT_ID, $DS2) {
            $p = $svc->parseUpload(csvFile($H . "abc,SP-Z,34.1,-118.1,,,,,,,\n"), 'bad.csv');
            $pl = $svc->plan($p, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS2, 'dataset_name' => '', 'as_new' => true));
            $out = array();
            foreach ($pl['hard_errors'] as $e) { $out[] = $e['code']; }
            return $out;
        })()));
    check("flag on: stranger still cannot target the owner's project",
        in_array('bad_project', (function () use ($svcStranger, $A, $PROJECT_ID, $DS2) {
            $p = $svcStranger->parseUpload(csvFile("strabo_internal_id,spot_name,latitude,longitude\n$A,SP-A,34.1,-118.1\n"), 'x.csv');
            $pl = $svcStranger->plan($p, array('project_id' => $PROJECT_ID, 'dataset_id' => $DS2, 'dataset_name' => '', 'as_new' => true));
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
