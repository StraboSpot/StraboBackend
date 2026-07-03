<?php
/**
 * File: smoke_test_tabular_io.php
 * Description: Service-layer smoke test for samples/tabular-io —
 *              SampleTabularService parse / plan / commit / export.
 *
 *              Coverage:
 *                1.  Template + export workbook build, XLSX re-parse
 *                2.  Unmodified export round-trip == all-noop clean plan
 *                3.  Creates via CSV incl. custom-column resolution + changelog
 *                4.  Updates via internal id: diff, blank-cell clear, custom merge
 *                5.  Vocab: label normalization, unknown → soft → resolution,
 *                    free-text keep, unchanged-free-text pass-through
 *                6.  Hard errors: unknown/foreign/duplicate id, bad coords,
 *                    blank names
 *                7.  Field-linked lat/lng guard (changed = error, same = noop)
 *                8.  All-or-nothing rollback on mid-commit failure
 *                9.  Micro dirty-flags flip on tabular update
 *                10. Export is owner-only
 *                11. CSV parse: BOM + comma decimals + blank rows
 *                12. State save/load/expiry/foreign-user
 *                13. Custom key cap
 *
 *              Hermetic: sentinel ids prefixed smoketab-<stamp>; cleanup in
 *              a finally block (samples CASCADE; micro_projectmetadata row).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_tabular_io.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/samplesdb/services/SampleTabularService.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) { $failures[] = $label; }
}

/** Write a CSV string to a temp file and return its path. */
function csvFile($csv) {
    $path = tempnam(sys_get_temp_dir(), 'tabsmoke_') . '.csv';
    file_put_contents($path, $csv);
    return $path;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 2", array()
);
if (!is_array($users) || count($users) < 2) { echo "Need 2 test users.\n"; exit(1); }
$owner    = (int)$users[0]->pkey;
$stranger = (int)$users[1]->pkey;
echo "owner=$owner  stranger=$stranger\n\n";

$stamp   = time();
$pfx     = "smoketab-$stamp";
$ids     = array();   // array($id, $userpkey) for cleanup
$microId = 96660001;  // sentinel micro_projectmetadata pkey (cleaned up)
$tmpFiles = array();

$svc = new SampleTabularService($db, $neodb);
$svc->setUserpkey($owner);

$svcStranger = new SampleTabularService($db, $neodb);
$svcStranger->setUserpkey($stranger);

try {

    // ------------------------------------------------------------------
    echo "=== 1. template + export workbook build ===\n";
    // ------------------------------------------------------------------
    $wb = $svc->buildWorkbook(array(), array(), true);
    check("workbook has Data sheet",         $wb->getSheetByName('Data') !== null);
    check("workbook has Vocabulary sheet",   $wb->getSheetByName('Vocabulary') !== null);
    check("workbook has Instructions sheet", $wb->getSheetByName('Instructions') !== null);

    if (!class_exists('PHPExcel_Writer_Excel2007')) {
        require_once '/srv/app/www/PHPExcel/Writer/Excel2007.php';
    }
    $tplPath = tempnam(sys_get_temp_dir(), 'tabsmoke_') . '.xlsx';
    $tmpFiles[] = $tplPath;
    $writer = new PHPExcel_Writer_Excel2007($wb);
    $writer->save($tplPath);
    check("template xlsx written", filesize($tplPath) > 5000);

    $parsedTpl = $svc->parseUpload($tplPath, 'template.xlsx');
    check("template re-parses ok",           !empty($parsedTpl['ok']));
    check("template has 0 data rows",        count($parsedTpl['rows']) === 0);
    check("all 9 standard fields recognized", count($parsedTpl['fields_present']) === 9);

    // ------------------------------------------------------------------
    echo "\n=== 2. seed samples + unmodified round-trip == all noop ===\n";
    // ------------------------------------------------------------------
    // Rich sample: vocab values + custom_data. Free-text sample: non-vocab type.
    $sRich = "$pfx-rich";  $sFree = "$pfx-free";
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, igsn, description, notes, latitude, longitude,
             display_sample_type, display_sample_purpose, custom_data, created_by, modified_by)
         VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$2,$2)",
        array($sRich, $owner, 'RICH-01', 'IGSN0001', 'rich desc', 'rich notes',
              34.2, -118.5, 'intact_rock', 'petrology',
              json_encode(array('collector' => 'J. Ash', 'depth_m' => '12.5'))));
    $ids[] = array($sRich, $owner);
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, display_sample_type, created_by, modified_by)
         VALUES ($1,$2,$3,$4,$2,$2)",
        array($sFree, $owner, 'FREE-01', 'Weird Custom Rock'));
    $ids[] = array($sFree, $owner);

    $export = $svc->exportRows();
    $mine = array();
    foreach ($export['rows'] as $r) {
        if (strpos((string)$r['strabo_internal_id'], $pfx) === 0) { $mine[] = $r; }
    }
    check("export contains both seeded samples", count($mine) === 2);
    check("export custom_keys has collector+depth_m",
          in_array('collector', $export['custom_keys']) && in_array('depth_m', $export['custom_keys']));
    $richRow = null;
    foreach ($mine as $r) { if ($r['strabo_internal_id'] === $sRich) { $richRow = $r; } }
    check("vocab exported as display label",  $richRow && $richRow['material_type'] === 'Intact Rock');
    check("custom values ride along",         $richRow && $richRow['_custom']['collector'] === 'J. Ash');

    // Round-trip ONLY the seeded rows through a real XLSX.
    $wb2 = $svc->buildWorkbook($mine, $export['custom_keys'], false);
    $rtPath = tempnam(sys_get_temp_dir(), 'tabsmoke_') . '.xlsx';
    $tmpFiles[] = $rtPath;
    $writer2 = new PHPExcel_Writer_Excel2007($wb2);
    $writer2->save($rtPath);
    $parsedRt = $svc->parseUpload($rtPath, 'roundtrip.xlsx');
    check("round-trip parses",  !empty($parsedRt['ok']));
    $planRt = $svc->plan($parsedRt, array(
        // custom columns need a decision; import (the values are unchanged)
        'custom_columns' => array('collector' => 'import', 'depth_m' => 'import'),
    ));
    check("round-trip plan is clean",         $planRt['clean'] === true);
    check("round-trip plan is all noop",
          $planRt['counts']['noop'] === 2 && $planRt['counts']['create'] === 0 && $planRt['counts']['update'] === 0);
    check("free-text vocab passes through without a soft issue", empty($planRt['soft_vocab']));

    // ------------------------------------------------------------------
    echo "\n=== 3. creates via CSV + custom resolution + changelog ===\n";
    // ------------------------------------------------------------------
    $csv = "sample_id,igsn,material_type,sampling_purpose,description,latitude,longitude,collector\n"
         . "$pfx-NEW-A,IGSNA,Intact Rock,Petrology,made by smoke,34.1,-117.9,K. Lee\n"
         . "$pfx-NEW-B,,Sediment,,second row,,,\n";
    $p = $svc->parseUpload(csvFile($csv), 'creates.csv');
    check("creates csv parses", !empty($p['ok']));
    $plan = $svc->plan($p, array('custom_columns' => array('collector' => 'import')));
    check("creates plan clean", $plan['clean'] === true);
    check("creates counted", $plan['counts']['create'] === 2);
    $res = $svc->commit($plan);
    check("commit ok", !empty($res['ok']));
    check("2 created", $res['created'] === 2 && $res['updated'] === 0);
    check("minted ids returned per row", count($res['minted']) === 2);
    $newIdA = null;
    foreach ($res['minted'] as $m) { if ($m['row'] === 2) { $newIdA = $m['id']; } }
    check("minted id is uuid-shaped", $newIdA && preg_match('/^[0-9a-f-]{36}$/', $newIdA));
    if ($newIdA) { $ids[] = array($newIdA, $owner); }
    foreach ($res['minted'] as $m) { if ($m['row'] === 3) { $ids[] = array($m['id'], $owner); } }

    $rowA = $db->get_row_prepared(
        "SELECT name, igsn, display_sample_type, display_sample_purpose, latitude, longitude,
                custom_data::text AS custom_data
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($newIdA, $owner));
    check("create persisted name",       $rowA && $rowA->name === "$pfx-NEW-A");
    check("vocab label normalized to storage value", $rowA && $rowA->display_sample_type === 'intact_rock');
    check("purpose normalized",          $rowA && $rowA->display_sample_purpose === 'petrology');
    check("coords persisted",            $rowA && abs((float)$rowA->latitude - 34.1) < 1e-9);
    $cdA = $rowA ? json_decode($rowA->custom_data, true) : null;
    check("custom field persisted",      is_array($cdA) && $cdA['collector'] === 'K. Lee');

    $clog = $db->get_row_prepared(
        "SELECT change_type, source_subsystem FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 ORDER BY pkey DESC LIMIT 1",
        array($newIdA, $owner));
    check("changelog 'create' row written", $clog && $clog->change_type === 'create');

    // ------------------------------------------------------------------
    echo "\n=== 4. updates: diff, blank-cell clear, custom merge ===\n";
    // ------------------------------------------------------------------
    // Rename RICH-01, clear its notes (blank cell, column present), blank
    // out custom 'depth_m' (key removal), leave 'collector' column absent
    // (key untouched).
    $csv = "strabo_internal_id,sample_id,notes,depth_m\n"
         . "$sRich,RICH-01-renamed,,\n";
    $p = $svc->parseUpload(csvFile($csv), 'updates.csv');
    $plan = $svc->plan($p, array('custom_columns' => array('depth_m' => 'import')));
    check("update plan clean", $plan['clean'] === true);
    check("1 update", $plan['counts']['update'] === 1);
    $res = $svc->commit($plan);
    check("update commit ok", !empty($res['ok']) && $res['updated'] === 1);

    $rowR = $db->get_row_prepared(
        "SELECT name, notes, description, custom_data::text AS custom_data
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sRich, $owner));
    check("name updated",                 $rowR && $rowR->name === 'RICH-01-renamed');
    check("blank cell cleared notes",     $rowR && $rowR->notes === null);
    check("absent column left description untouched", $rowR && $rowR->description === 'rich desc');
    $cdR = $rowR ? json_decode($rowR->custom_data, true) : null;
    check("blank custom cell removed depth_m",     is_array($cdR) && !isset($cdR['depth_m']));
    check("absent custom column kept collector",   is_array($cdR) && $cdR['collector'] === 'J. Ash');

    $clog = $db->get_row_prepared(
        "SELECT change_type, changes FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 ORDER BY pkey DESC LIMIT 1",
        array($sRich, $owner));
    check("changelog 'update' row written", $clog && $clog->change_type === 'update');
    $chg = $clog ? json_decode($clog->changes, true) : null;
    check("changelog diff carries old name", is_array($chg) && isset($chg['name']) && $chg['name']['old'] === 'RICH-01');

    // ------------------------------------------------------------------
    echo "\n=== 5. vocab: unknown → soft → resolution paths ===\n";
    // ------------------------------------------------------------------
    $csv = "sample_id,material_type\n"
         . "$pfx-V1,Meta-Sediment\n"
         . "$pfx-V2,Meta-Sediment\n";
    $p = $svc->parseUpload(csvFile($csv), 'vocab.csv');
    $plan = $svc->plan($p);
    check("unknown vocab is a soft issue, not hard", empty($plan['hard_errors']));
    check("plan not clean while vocab unresolved",   $plan['clean'] === false);
    check("soft issue groups both rows", isset($plan['soft_vocab']['material_type']['Meta-Sediment'])
          && $plan['soft_vocab']['material_type']['Meta-Sediment']['count'] === 2);

    $planMap = $svc->plan($p, array('vocab' => array(
        'display_sample_type' => array('Meta-Sediment' => 'sediment'))));
    check("mapped resolution cleans the plan", $planMap['clean'] === true);
    $res = $svc->commit($planMap);
    check("vocab-mapped commit ok", !empty($res['ok']) && $res['created'] === 2);
    $vIds = array();
    foreach ($res['minted'] as $m) { $vIds[] = $m['id']; $ids[] = array($m['id'], $owner); }
    $v1 = $db->get_var_prepared(
        "SELECT display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($vIds[0], $owner));
    check("mapped value stored canonically", $v1 === 'sediment');

    $csv = "sample_id,material_type\n$pfx-V3,Totally Novel Stuff\n";
    $p = $svc->parseUpload(csvFile($csv), 'vocab2.csv');
    $planFt = $svc->plan($p, array('vocab' => array(
        'display_sample_type' => array('Totally Novel Stuff' => '__freetext__'))));
    check("free-text resolution cleans the plan", $planFt['clean'] === true);
    $res = $svc->commit($planFt);
    check("free-text commit ok", !empty($res['ok']));
    foreach ($res['minted'] as $m) { $ids[] = array($m['id'], $owner); $v3id = $m['id']; }
    $v3 = $db->get_var_prepared(
        "SELECT display_sample_type FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($v3id, $owner));
    check("free text stored verbatim", $v3 === 'Totally Novel Stuff');

    // ------------------------------------------------------------------
    echo "\n=== 6. hard errors ===\n";
    // ------------------------------------------------------------------
    $foreign = "$pfx-foreign";
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
         VALUES ($1,$2,$3,$2,$2)", array($foreign, $stranger, 'STRANGER-01'));
    $ids[] = array($foreign, $stranger);

    $csv = "strabo_internal_id,sample_id,latitude,longitude\n"
         . "no-such-id-$stamp,X1,,\n"          // unknown id
         . "$foreign,X2,,\n"                    // stranger's sample = unknown under owner
         . "$sFree,FREE-dup,,\n"
         . "$sFree,FREE-dup2,,\n"               // duplicate id in file
         . ",X3,95,10\n"                        // lat out of range
         . ",X4,45,\n"                          // one-sided pair
         . ",,,\n";                             // fully blank row → skipped silently
    $p = $svc->parseUpload(csvFile($csv), 'errors.csv');
    $plan = $svc->plan($p);
    $codes = array();
    foreach ($plan['hard_errors'] as $e) { $codes[$e['code']] = true; }
    check("unknown id detected",      isset($codes['unknown_id']));
    check("foreign id reads as unknown (owner-only)", true); // same code path — both rows hit unknown_id
    $unknownCount = 0;
    foreach ($plan['hard_errors'] as $e) { if ($e['code'] === 'unknown_id') { $unknownCount++; } }
    check("both bad ids flagged",     $unknownCount === 2);
    check("duplicate id detected",    isset($codes['duplicate_id']));
    check("out-of-range detected",    isset($codes['out_of_range']));
    check("coord pair detected",      isset($codes['coord_pair']));
    check("plan not clean",           $plan['clean'] === false);
    check("commit refuses unclean plan", !$svc->commit($plan)['ok']);

    $csv = "strabo_internal_id,sample_id\n$sFree,\n";   // blank name on update
    $p = $svc->parseUpload(csvFile($csv), 'blankname.csv');
    $plan = $svc->plan($p);
    $hasNameErr = false;
    foreach ($plan['hard_errors'] as $e) { if ($e['code'] === 'name_required') { $hasNameErr = true; } }
    check("blank name on update rejected", $hasNameErr);

    // ------------------------------------------------------------------
    echo "\n=== 7. field-linked lat/lng guard ===\n";
    // ------------------------------------------------------------------
    $sField = "$pfx-fieldlinked";
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, latitude, longitude, created_by, modified_by)
         VALUES ($1,$2,$3,$4,$5,$2,$2)",
        array($sField, $owner, 'FIELD-01', 40.0, -100.0));
    $ids[] = array($sField, $owner);
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
            (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
         VALUES ($1,$2,'field',$3,$2,'{}')",
        array($sField, $owner, "spot-$stamp"));

    $csv = "strabo_internal_id,sample_id,latitude,longitude\n$sField,FIELD-01,41.0,-100.0\n";
    $p = $svc->parseUpload(csvFile($csv), 'fieldlat.csv');
    $plan = $svc->plan($p);
    $hasGuard = false;
    foreach ($plan['hard_errors'] as $e) { if ($e['code'] === 'field_link_read_only') { $hasGuard = true; } }
    check("changed lat on field-linked sample = hard error", $hasGuard);

    $csv = "strabo_internal_id,sample_id,latitude,longitude\n$sField,FIELD-01,40.0,-100.0\n";
    $p = $svc->parseUpload(csvFile($csv), 'fieldsame.csv');
    $plan = $svc->plan($p);
    check("unchanged lat/lng on field-linked sample = clean noop",
          $plan['clean'] === true && $plan['counts']['noop'] === 1);

    // ------------------------------------------------------------------
    echo "\n=== 8. all-or-nothing rollback ===\n";
    // ------------------------------------------------------------------
    // Hand-tamper a plan so the 2nd row fails inside commit: the create
    // succeeds, then an update on a nonexistent id returns not_found →
    // ROLLBACK must erase the create.
    $tamperName = "$pfx-rollback-victim";
    $tampered = array(
        'clean' => true,
        'rows'  => array(
            array('n' => 2, 'action' => 'create', 'input' => array('name' => $tamperName)),
            array('n' => 3, 'action' => 'update', 'id' => "no-such-$stamp", 'input' => array('notes' => 'x')),
        ),
    );
    $res = $svc->commit($tampered);
    check("tampered commit fails", empty($res['ok']));
    check("failure reports the row", isset($res['row']) && $res['row'] === 3);
    $leak = $db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples WHERE userpkey=$1 AND name=$2",
        array($owner, $tamperName));
    check("rolled back — created row did not persist", (int)$leak === 0);

    // ------------------------------------------------------------------
    echo "\n=== 9. micro dirty-flags flip on tabular update ===\n";
    // ------------------------------------------------------------------
    $sMicro = "$pfx-microlinked";
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
         VALUES ($1,$2,$3,$2,$2)", array($sMicro, $owner, 'MICRO-01'));
    $ids[] = array($sMicro, $owner);
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, projectjson, files_dirty, pdf_dirty)
         VALUES ($1,$2,$3,'{}',FALSE,FALSE)",
        array($microId, $owner, "microproj-$stamp"));
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
            (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
         VALUES ($1,$2,'micro',$3,$2,'{}')",
        array($sMicro, $owner, (string)$microId));

    $csv = "strabo_internal_id,sample_id,description\n$sMicro,MICRO-01,described via tabular\n";
    $p = $svc->parseUpload(csvFile($csv), 'micro.csv');
    $plan = $svc->plan($p);
    check("micro update plan clean", $plan['clean'] === true && $plan['counts']['update'] === 1);
    $res = $svc->commit($plan);
    check("micro update commit ok", !empty($res['ok']));
    $flags = $db->get_row_prepared(
        "SELECT files_dirty, pdf_dirty FROM micro_projectmetadata WHERE id=$1", array($microId));
    check("files_dirty flipped", $flags && ($flags->files_dirty === 't' || $flags->files_dirty === true));
    check("pdf_dirty flipped",   $flags && ($flags->pdf_dirty === 't'   || $flags->pdf_dirty === true));

    // ------------------------------------------------------------------
    echo "\n=== 10. export is owner-only ===\n";
    // ------------------------------------------------------------------
    $exportS = $svcStranger->exportRows();
    $sawOwners = false; $sawOwnStranger = false;
    foreach ($exportS['rows'] as $r) {
        if (strpos((string)$r['strabo_internal_id'], $pfx) === 0) {
            if ($r['strabo_internal_id'] === $foreign) { $sawOwnStranger = true; }
            else { $sawOwners = true; }
        }
    }
    check("stranger's export excludes owner's samples", !$sawOwners);
    check("stranger's export includes their own",        $sawOwnStranger);

    // ------------------------------------------------------------------
    echo "\n=== 11. CSV robustness: BOM, comma decimal, blank rows ===\n";
    // ------------------------------------------------------------------
    $csv = "\xEF\xBB\xBF" . "sample_id,latitude,longitude\n\n$pfx-C1,\"34,5\",-100.25\n\n";
    $p = $svc->parseUpload(csvFile($csv), 'bom.csv');
    check("BOM csv parses", !empty($p['ok']));
    check("blank lines skipped", count($p['rows']) === 1);
    $plan = $svc->plan($p);
    check("comma decimal accepted", $plan['clean'] === true);
    $lat = null;
    foreach ($plan['rows'] as $r) { if ($r['action'] === 'create') { $lat = $r['input']['latitude']; } }
    check("comma decimal parsed to 34.5", abs($lat - 34.5) < 1e-9);

    // ------------------------------------------------------------------
    echo "\n=== 12. state save/load semantics ===\n";
    // ------------------------------------------------------------------
    $token = $svc->saveState($p, 'bom.csv');
    check("token is 32 hex chars", preg_match('/^[0-9a-f]{32}$/', $token) === 1);
    $st = $svc->loadState($token);
    check("state loads for the same user", is_array($st) && $st['filename'] === 'bom.csv');
    check("state refuses another user", $svcStranger->loadState($token) === null);
    $svc->discardState($token);
    check("discarded state gone", $svc->loadState($token) === null);
    check("garbage token rejected", $svc->loadState('zzzz') === null);

    // ------------------------------------------------------------------
    echo "\n=== 13. custom key cap ===\n";
    // ------------------------------------------------------------------
    $hdr = array('sample_id'); $cells = array("$pfx-CAP");
    for ($i = 1; $i <= 51; $i++) { $hdr[] = "custom_$i"; $cells[] = "v$i"; }
    $csv = implode(',', $hdr) . "\n" . implode(',', $cells) . "\n";
    $p = $svc->parseUpload(csvFile($csv), 'cap.csv');
    $resCustom = array();
    foreach ($p['custom_headers'] as $h) { $resCustom[$h] = 'import'; }
    $plan = $svc->plan($p, array('custom_columns' => $resCustom));
    $hasCap = false;
    foreach ($plan['hard_errors'] as $e) { if ($e['code'] === 'too_many_custom') { $hasCap = true; } }
    check("51 custom keys on one row rejected", $hasCap);

} finally {
    // ---- cleanup ----
    foreach ($ids as $pair) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                           array($pair[0], $pair[1]));
    }
    // Any leftover creates from this run (defensive sweep by name prefix).
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE name LIKE $1", array("$pfx-%"));
    $db->prepare_query("DELETE FROM micro_projectmetadata WHERE id=$1", array($microId));
    foreach ($tmpFiles as $f) { @unlink($f); }
    foreach (glob(sys_get_temp_dir() . '/tabsmoke_*') as $f) { @unlink($f); }
}

echo "\n==========================================\n";
if (empty($failures)) {
    echo "ALL CHECKS PASSED\n";
    exit(0);
}
echo count($failures) . " FAILURE(S):\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
