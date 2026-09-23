<?php
/**
 * File: smoke_test_field_linking_id.php
 * Description: Field linking id (samples[].strabosamples_id, 2026-09-21).
 *              A Field sample object keeps its own local id forever; an
 *              optional strabosamples_id names the StraboSamples sample it
 *              IS. Rule + rationale: samplesdb/lib/field_identity.php.
 *
 *              Covers, at the service layer against seeded Neo4j spots:
 *                1. new rich sample born with the key (row under B only,
 *                   stub still skipped)
 *                2. ESTABLISHED sample gains the key: old row folds into B
 *                   (children + collaborators carried, changelog entry);
 *                   variant where the old row has Micro data survives
 *                3. key removed: old identity comes back, B keeps Micro
 *                4. legacy inline sample with the key + writeback into the
 *                   right samples[i]; inline sample deleted in the app;
 *                   last sample removed
 *                5. unknown / cross-owner key falls back to the local id
 *                6. a stub carrying the key is still a stub
 *                7. remove mirror on a linked rich spot
 *                8. migration extractor resolves the same identity
 *                9. drift audit is clean for every sample touched
 *               10. Template Wizard mergeSamples keeps the key on import
 *
 *              Hermetic: seeds Neo4j Project/Dataset/Spots + spine rows,
 *              tears down in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_field_linking_id.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/db/lib/sample_sync.php';
require_once '/srv/app/www/samplesdb/migration/extract_field.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 3", array()
);
if (!is_array($users) || count($users) < 3) { echo "Need 3 users\n"; exit(1); }
$owner  = (int)$users[0]->pkey;
$friend = (int)$users[1]->pkey;
$other  = (int)$users[2]->pkey;
$uuidGen = new UUID();

$stamp   = time();
$project = (string)($stamp * 100 + 11);
$dataset = (string)($stamp * 100 + 12);
$spotNew     = (int)($stamp * 100 + 21);   // rich, born linked
$spotParent  = (int)($stamp * 100 + 22);   // parent holding the stub of spotNew
$spotEst     = (int)($stamp * 100 + 23);   // rich, established then linked
$spotEstMic  = (int)($stamp * 100 + 24);   // rich, established, old row also has Micro data
$spotLegacy  = (int)($stamp * 100 + 25);   // legacy holder
$legacyA     = (string)($stamp * 100 + 31);
$legacyB     = (string)($stamp * 100 + 32);
$spotBogus   = (int)($stamp * 100 + 26);   // rich, key names nothing
$spotCross   = (int)($stamp * 100 + 27);   // rich, key names someone else's sample

$B_new   = $uuidGen->v4();
$B_est   = $uuidGen->v4();
$B_mic   = $uuidGen->v4();
$B_leg   = $uuidGen->v4();
$B_bogus = $uuidGen->v4();
$B_cross = $uuidGen->v4();
$childId = $uuidGen->v4();
$B_web0  = $uuidGen->v4();               // made on the web, no subsystem slice (picker keeps it)
$spotOnly = (int)($stamp * 100 + 29);    // rich, never linked: a field-only row the picker must hide

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($owner);

/** A sample that already lives in StraboSamples through Micro. */
function seed_micro_sample($svc, $id, $upk, $name) {
    $svc->setUserpkey($upk);
    return $svc->upsertSample('micro', $id, $upk,
        array('name' => $name, 'description' => 'from micro'),
        array('id' => $id, 'label' => $name),
        array('reference_id' => '990' . substr((string)crc32($id), 0, 6), 'reference_userpkey' => $upk,
              'reference_metadata' => array('micrograph_count' => 1))
    );
}

function seed_spot($neodb, $spotId, $upk, $dataset, array $samples, $isRich) {
    $js  = addslashes(json_encode($samples));
    $now = (int)round(microtime(true) * 1000);
    $rich = $isRich ? "isSample: 1," : "";
    $neodb->query("CREATE (s:Spot { id: $spotId, userpkey: $upk, $rich
        json_samples: '$js', wkt: 'POINT (-95.2 38.9)', modified_timestamp: $now })");
    $neodb->query("MATCH (d:Dataset {id:$dataset, userpkey:$upk}), (s:Spot {id:$spotId, userpkey:$upk})
                   CREATE (d)-[:HAS_SPOT]->(s)");
}

function restamp_spot($neodb, $spotId, $upk, array $samples) {
    $js = addslashes(json_encode($samples));
    $neodb->query("MATCH (s:Spot {id:$spotId, userpkey:$upk}) SET s.json_samples = '$js'");
}

function sync($db, $neodb, $spotId, $upk, array $samples, $isRich, $project, $dataset) {
    $props = (object)array('id' => $spotId, 'wkt' => 'POINT (-95.2 38.9)',
        'samples' => json_decode(json_encode($samples)));
    if ($isRich) $props->isSample = 1;
    return field_sample_sync_spot($db, $neodb, $props, $spotId, $upk, $project, $dataset);
}

function spine($db, $id, $upk) {
    return $db->get_row_prepared(
        "SELECT name, field_data::text AS fd, micro_data::text AS md, parent_sample_id
           FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array((string)$id, (int)$upk));
}

function field_links($db, $id, $upk) {
    $rows = $db->get_results_prepared(
        "SELECT reference_id FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field' ORDER BY reference_id",
        array((string)$id, (int)$upk));
    $out = array();
    foreach ((array)$rows as $r) $out[] = (string)$r->reference_id;
    return $out;
}

function micro_link_count($db, $id, $upk) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='micro'", array((string)$id, (int)$upk));
}

function sample_obj($localId, $name, $linkId = null) {
    $o = array('id' => $localId, 'sample_id_name' => $name, 'sample_description' => "desc $name",
               'material_type' => 'intact_rock', 'main_sampling_purpose' => 'petrology');
    if ($linkId !== null) $o['strabosamples_id'] = $linkId;
    return $o;
}

$neodb->query("CREATE (p:Project {id:$project, userpkey:$owner, name:'linking id $stamp'})");
$neodb->query("CREATE (d:Dataset {id:$dataset, userpkey:$owner, name:'ds $stamp'})");
$neodb->query("MATCH (p:Project {id:$project, userpkey:$owner}), (d:Dataset {id:$dataset, userpkey:$owner})
               CREATE (p)-[:HAS_DATASET]->(d)");

echo "owner=$owner project=$project dataset=$dataset\n\n";

try {
    foreach (array($B_new => 'Micro NEW', $B_est => 'Micro EST', $B_mic => 'Micro MIC', $B_leg => 'Micro LEG') as $id => $nm) {
        seed_micro_sample($svc, $id, $owner, $nm);
    }
    seed_micro_sample($svc, $B_cross, $other, 'Someone elses');
    $svc->setUserpkey($owner);

    // -------------------------------------------------------------------
    echo "=== 1. new rich sample born with the key ===\n";
    $objNew = sample_obj($spotNew, 'Field NEW', $B_new);
    seed_spot($neodb, $spotNew, $owner, $dataset, array($objNew), true);
    $m = sync($db, $neodb, $spotNew, $owner, array($objNew), true, $project, $dataset);
    check("mirrored identity is the linking id", $m === array($B_new));
    check("no row under the local id", spine($db, $spotNew, $owner) === null);
    $r = spine($db, $B_new, $owner);
    check("linked row carries field_data", $r !== null && $r->fd !== null);
    check("field_data keeps the local id + the key",
        $r !== null && strpos((string)$r->fd, (string)$spotNew) !== false && strpos((string)$r->fd, $B_new) !== false);
    check("Field outranks Micro on the spine name", $r !== null && $r->name === 'Field NEW');
    check("Field link points at the spot", field_links($db, $B_new, $owner) === array((string)$spotNew));
    check("Micro link untouched", micro_link_count($db, $B_new, $owner) === 1);

    $stub = array(array('id' => $spotNew));
    seed_spot($neodb, $spotParent, $owner, $dataset, $stub, false);
    $m = sync($db, $neodb, $spotParent, $owner, $stub, false, $project, $dataset);
    check("parent stub {local id} still skipped", $m === array());
    check("stub minted nothing under the local id", spine($db, $spotNew, $owner) === null);

    echo "\n=== 6. a stub that carries the key is still a stub ===\n";
    $stubK = array(array('id' => $spotNew, 'strabosamples_id' => $B_new));
    $m = sync($db, $neodb, $spotParent, $owner, $stubK, false, $project, $dataset);
    check("keyed stub skipped", $m === array());
    check("linked row still links only the rich spot", field_links($db, $B_new, $owner) === array((string)$spotNew));
    $r = spine($db, $B_new, $owner);
    check("keyed stub did not blank the name", $r !== null && $r->name === 'Field NEW');

    // -------------------------------------------------------------------
    echo "\n=== 2. established sample gains the key ===\n";
    $objEst = sample_obj($spotEst, 'Field EST');
    seed_spot($neodb, $spotEst, $owner, $dataset, array($objEst), true);
    sync($db, $neodb, $spotEst, $owner, array($objEst), true, $project, $dataset);
    check("established row exists under the local id", spine($db, $spotEst, $owner) !== null);

    // spine-only attachments on the old row: a child sample + a collaborator
    $svc->createSample(array('id' => $childId, 'name' => 'child of EST'));
    $svc->setParent($childId, $owner, (string)$spotEst, $owner);
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level, uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
        array((string)$spotEst, $owner, $friend, $uuidGen->v4()));

    $objEstL = sample_obj($spotEst, 'Field EST', $B_est);
    restamp_spot($neodb, $spotEst, $owner, array($objEstL));
    $m = sync($db, $neodb, $spotEst, $owner, array($objEstL), true, $project, $dataset);
    check("mirrored identity is the linking id", $m === array($B_est));
    check("old Field-only row is gone", spine($db, $spotEst, $owner) === null);
    check("linked row holds the Field link", field_links($db, $B_est, $owner) === array((string)$spotEst));
    $c = spine($db, $childId, $owner);
    check("child re-pointed to the linked row", $c !== null && $c->parent_sample_id === $B_est);
    $cc = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_collaborators
          WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3 AND removed_at IS NULL AND accepted",
        array($B_est, $owner, $friend));
    check("collaborator carried to the linked row", $cc === 1);
    $cl = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type='field_link_adopted'
            AND changes->>'from_sample_id' = $3", array($B_est, $owner, (string)$spotEst));
    check("changelog names the old id", $cl === 1);

    $m2 = sync($db, $neodb, $spotEst, $owner, array($objEstL), true, $project, $dataset);
    $cl2 = (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type='field_link_adopted'", array($B_est, $owner));
    check("re-upload is idempotent (no second adoption)", $m2 === array($B_est) && $cl2 === 1);

    echo "\n--- variant: old row also has Micro data ---\n";
    $objMic = sample_obj($spotEstMic, 'Field MIC');
    seed_spot($neodb, $spotEstMic, $owner, $dataset, array($objMic), true);
    sync($db, $neodb, $spotEstMic, $owner, array($objMic), true, $project, $dataset);
    seed_micro_sample($svc, (string)$spotEstMic, $owner, 'Field MIC in micro');
    $svc->setUserpkey($owner);
    $objMicL = sample_obj($spotEstMic, 'Field MIC', $B_mic);
    restamp_spot($neodb, $spotEstMic, $owner, array($objMicL));
    sync($db, $neodb, $spotEstMic, $owner, array($objMicL), true, $project, $dataset);
    $old = spine($db, $spotEstMic, $owner);
    check("old row survives (Micro still owns it)", $old !== null && $old->md !== null);
    check("old row lost its Field side", $old !== null && $old->fd === null && field_links($db, $spotEstMic, $owner) === array());
    check("linked row holds the Field link", field_links($db, $B_mic, $owner) === array((string)$spotEstMic));

    // -------------------------------------------------------------------
    echo "\n=== 2b. picker list: omit=field (Field app's /samplesdb/mysamples) ===\n";
    $objOnly = sample_obj($spotOnly, 'Field ONLY');
    seed_spot($neodb, $spotOnly, $owner, $dataset, array($objOnly), true);
    sync($db, $neodb, $spotOnly, $owner, array($objOnly), true, $project, $dataset);
    $svc->setUserpkey($owner);
    $svc->createSample(array('id' => $B_web0, 'name' => 'made on the web, unlinked'));
    $idsOf = function ($rows) { return array_map(function ($r) { return (string)$r['id']; }, $rows); };
    $all  = $svc->listMySamples();
    $pick = $svc->listMySamples(array('omit' => 'field', 'include_subsystem_flags' => true));
    $byId = array();
    foreach ($pick as $r) $byId[(string)$r['id']] = $r;
    check("full list holds the field-only row", in_array((string)$spotOnly, $idsOf($all), true));
    check("omit=field drops the field-only row", !isset($byId[(string)$spotOnly]));
    check("omit=field keeps the web-made sample, unflagged",
        isset($byId[$B_web0]) && $byId[$B_web0]['has_field_data'] === false && $byId[$B_web0]['has_micro_data'] === false);
    check("omit=field keeps the Micro sample a spot already links, flagged has_field_data",
        isset($byId[$B_mic]) && $byId[$B_mic]['has_field_data'] === true && $byId[$B_mic]['has_micro_data'] === true);
    check("omit=field keeps the Micro-only leftover row, unflagged",
        isset($byId[(string)$spotEstMic]) && $byId[(string)$spotEstMic]['has_field_data'] === false);
    $leak = 0;
    foreach ($pick as $r) {
        $fo = $db->get_var_prepared(
            "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2
               AND field_data IS NOT NULL AND micro_data IS NULL AND experimental_data IS NULL",
            array((string)$r['id'], (int)$r['userpkey']));
        if ($fo) $leak++;
    }
    check("no field-only row leaks through omit=field (" . count($pick) . " rows)", $leak === 0);
    $pick2 = $idsOf($svc->listMySamples(array('omit' => array('field', 'micro'))));
    check("omit=field,micro also drops the Micro rows but keeps the web-made one",
        !in_array($B_mic, $pick2, true) && !in_array((string)$spotEstMic, $pick2, true) && in_array($B_web0, $pick2, true));
    check("normalizeOmit: case/space tolerant, unknown value = null",
        StraboSamplesService::normalizeOmit(' Field,MICRO, ') === array('field', 'micro')
        && StraboSamplesService::normalizeOmit('field,bogus') === null
        && StraboSamplesService::normalizeOmit('') === array());

    // -------------------------------------------------------------------
    echo "\n=== 3. key removed (unlink) ===\n";
    restamp_spot($neodb, $spotEst, $owner, array($objEst));
    $m = sync($db, $neodb, $spotEst, $owner, array($objEst), true, $project, $dataset);
    check("identity falls back to the local id", $m === array((string)$spotEst));
    check("local row is back with the Field link", field_links($db, $spotEst, $owner) === array((string)$spotEst));
    $b = spine($db, $B_est, $owner);
    check("linked row survives through Micro", $b !== null && $b->md !== null);
    check("linked row lost only its Field side", $b !== null && $b->fd === null
        && field_links($db, $B_est, $owner) === array() && micro_link_count($db, $B_est, $owner) === 1);

    // -------------------------------------------------------------------
    echo "\n=== 4. legacy inline sample with the key ===\n";
    $leg = array(sample_obj($legacyA, 'Legacy A', $B_leg), sample_obj($legacyB, 'Legacy B'));
    seed_spot($neodb, $spotLegacy, $owner, $dataset, $leg, false);
    $m = sync($db, $neodb, $spotLegacy, $owner, $leg, false, $project, $dataset);
    check("mirrored = [linking id, local id]", $m === array($B_leg, $legacyB));
    check("no row under the linked entry's local id", spine($db, $legacyA, $owner) === null);

    $res = $svc->updateSample($B_leg, $owner, array('name' => 'Renamed on the web'));
    check("samples-side edit accepted", is_array($res) && !isset($res['error']));
    $rec = $neodb->getRecord("MATCH (s:Spot {id:$spotLegacy, userpkey:$owner}) RETURN s LIMIT 1");
    $vals = $rec ? $rec->get('s')->values() : array();
    $after = isset($vals['json_samples']) ? json_decode($vals['json_samples'], true) : array();
    check("writeback hit the linked entry", isset($after[0]['sample_id_name']) && $after[0]['sample_id_name'] === 'Renamed on the web');
    check("writeback kept local id + key", isset($after[0]['id'], $after[0]['strabosamples_id'])
        && (string)$after[0]['id'] === $legacyA && $after[0]['strabosamples_id'] === $B_leg);
    check("writeback left the other entry alone", isset($after[1]['sample_id_name']) && $after[1]['sample_id_name'] === 'Legacy B');

    echo "\n--- inline sample deleted in the app ---\n";
    $leg1 = array($after[0]);
    restamp_spot($neodb, $spotLegacy, $owner, $leg1);
    sync($db, $neodb, $spotLegacy, $owner, $leg1, false, $project, $dataset);
    check("deleted inline sample's row is retired", spine($db, $legacyB, $owner) === null);
    check("linked entry unaffected", field_links($db, $B_leg, $owner) === array((string)$spotLegacy));

    echo "\n--- last sample removed ---\n";
    restamp_spot($neodb, $spotLegacy, $owner, array());
    sync($db, $neodb, $spotLegacy, $owner, array(), false, $project, $dataset);
    $b = spine($db, $B_leg, $owner);
    check("linked row keeps Micro, loses Field", $b !== null && $b->md !== null && $b->fd === null
        && field_links($db, $B_leg, $owner) === array());

    // -------------------------------------------------------------------
    echo "\n=== 5. a key that names nothing (or someone else's sample) is not honored ===\n";
    $objBogus = sample_obj($spotBogus, 'Bogus', $B_bogus);
    seed_spot($neodb, $spotBogus, $owner, $dataset, array($objBogus), true);
    $m = sync($db, $neodb, $spotBogus, $owner, array($objBogus), true, $project, $dataset);
    check("unknown key: identity = local id", $m === array((string)$spotBogus));
    check("unknown key minted no row", spine($db, $B_bogus, $owner) === null);

    $objCross = sample_obj($spotCross, 'Cross', $B_cross);
    seed_spot($neodb, $spotCross, $owner, $dataset, array($objCross), true);
    $m = sync($db, $neodb, $spotCross, $owner, array($objCross), true, $project, $dataset);
    check("cross-owner key: identity = local id", $m === array((string)$spotCross));
    check("cross-owner key minted no row for this owner", spine($db, $B_cross, $owner) === null);
    check("the other user's sample is untouched", field_links($db, $B_cross, $other) === array());

    // -------------------------------------------------------------------
    echo "\n=== 8. migration extractor resolves the same identity ===\n";
    _migration_field_identity_db($db);
    $row = _migration_field_build_row($objNew, array('id' => $spotNew, 'userpkey' => $owner, 'wkt' => 'POINT (-95.2 38.9)'), $dataset, $owner, true);
    check("honored key -> linking id", is_array($row) && $row['sample_id'] === $B_new);
    $row = _migration_field_build_row($objBogus, array('id' => $spotBogus, 'userpkey' => $owner, 'wkt' => 'POINT (-95.2 38.9)'), $dataset, $owner, true);
    check("unknown key -> local id", is_array($row) && $row['sample_id'] === (string)$spotBogus);
    _migration_field_identity_db(false);

    // -------------------------------------------------------------------
    echo "\n=== 10. Template Wizard import keeps the key ===\n";
    require_once '/srv/app/www/TemplateWizard/services/FieldTabularService.php';
    $rc  = new ReflectionClass('FieldTabularService');
    $fts = $rc->newInstanceWithoutConstructor();
    $mm  = $rc->getMethod('mergeSamples');
    $mm->setAccessible(true);
    $merged = $mm->invoke($fts,
        array(array('sample_id_name' => 'Field NEW', 'sample_description' => 'edited in a spreadsheet')),
        array($objNew),
        array('sample_id_name', 'sample_description'));
    check("wizard merge keeps local id + strabosamples_id", isset($merged[0]['id'], $merged[0]['strabosamples_id'])
        && (string)$merged[0]['id'] === (string)$spotNew && $merged[0]['strabosamples_id'] === $B_new);
    check("wizard merge applied the edited column", isset($merged[0]['sample_description'])
        && $merged[0]['sample_description'] === 'edited in a spreadsheet');

    // -------------------------------------------------------------------
    echo "\n=== 9. drift audit is clean for every sample touched ===\n";
    // Control: a linked entry the sync never saw. If the sweep reads this
    // window at all it must flag it, under its IDENTITY (the linking id).
    $spotCtl = (int)($stamp * 100 + 28);
    $objCtl  = sample_obj($spotCtl, 'Control', $B_est);
    seed_spot($neodb, $spotCtl, $owner, $dataset, array($objCtl), true);

    $out = shell_exec("php /srv/app/www/samplesdb/audit/audit_cross_system_drift.php"
                    . " --user $owner --sample 0 --since 1 --json 2>&1");
    $parts  = explode("---JSON---", (string)$out);
    $report = count($parts) === 2 ? json_decode(trim($parts[1]), true) : null;
    check("auditor emitted parseable JSON", is_array($report) && isset($report['findings']));
    $mine = array_map('strval', array($B_new, $B_est, $B_mic, $B_leg, $B_bogus, $B_cross, $spotNew, $spotEst,
        $spotEstMic, $spotBogus, $spotCross, $legacyA, $legacyB));
    $hits = array();
    foreach ((is_array($report) ? $report['findings'] : array()) as $f) {
        if ($f['kind'] === 'missing_field_link' && (string)$f['sample_id'] === $B_est) continue;   // the control
        if ($f['class'] === 'DRIFT' && strpos((string)$f['section'], 'field') === 0
            && in_array((string)$f['sample_id'], $mine, true)) {
            $hits[] = $f['kind'] . ':' . $f['sample_id'];
        }
    }
    $ctl = false;
    foreach ((is_array($report) ? $report['findings'] : array()) as $f) {
        if ($f['kind'] === 'missing_field_link' && (string)$f['sample_id'] === $B_est) $ctl = true;
    }
    check("control: unsynced linked entry IS flagged, under the linking id", $ctl);
    check("no Field DRIFT on linked samples" . ($hits ? ' (' . implode(', ', $hits) . ')' : ''), count($hits) === 0);

    // -------------------------------------------------------------------
    echo "\n=== 7. remove mirror on a linked rich spot ===\n";
    field_sample_sync_remove_spot($db, $neodb, $spotNew, $owner);
    $b = spine($db, $B_new, $owner);
    check("linked row survives the spot delete through Micro", $b !== null && $b->md !== null);
    check("its Field side is gone", $b !== null && $b->fd === null && field_links($db, $B_new, $owner) === array());

    echo "\n--- delete then re-insert (version restore shape) keeps the link ---\n";
    $m = sync($db, $neodb, $spotNew, $owner, array($objNew), true, $project, $dataset);
    check("re-inserted spot lands on the linking id again", $m === array($B_new)
        && field_links($db, $B_new, $owner) === array((string)$spotNew) && spine($db, $spotNew, $owner) === null);

    echo "\n--- a linked sample nothing else holds (made on the web) outlives the spot ---\n";
    $B_web = $uuidGen->v4();
    $svc->createSample(array('id' => $B_web, 'name' => 'made on the web'));
    $objWeb = sample_obj($spotBogus, 'Bogus', $B_web);
    sync($db, $neodb, $spotBogus, $owner, array($objWeb), true, $project, $dataset);
    check("web-made sample linked", field_links($db, $B_web, $owner) === array((string)$spotBogus));
    restamp_spot($neodb, $spotBogus, $owner, array($objWeb));
    field_sample_sync_remove_spot($db, $neodb, $spotBogus, $owner);
    $w = spine($db, $B_web, $owner);
    check("web-made sample survives the spot delete", $w !== null && $w->fd === null && field_links($db, $B_web, $owner) === array());
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($B_web, $owner));

} finally {
    echo "\n--- teardown ---\n";
    $ids = array($B_new, $B_est, $B_mic, $B_leg, $B_bogus, $childId, $B_web0, (string)$spotNew, (string)$spotEst,
        (string)$spotEstMic, (string)$spotBogus, (string)$spotCross, (string)$spotOnly, $legacyA, $legacyB);
    foreach ($ids as $id) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($id, $owner));
    }
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($B_cross, $other));
    foreach (array($spotNew, $spotParent, $spotEst, $spotEstMic, $spotLegacy, $spotBogus, $spotCross, $spotOnly, $stamp * 100 + 28) as $sid) {
        $neodb->query("MATCH (s:Spot {id:$sid, userpkey:$owner}) DETACH DELETE s");
    }
    $neodb->query("MATCH (d:Dataset {id:$dataset, userpkey:$owner}) DETACH DELETE d");
    $neodb->query("MATCH (p:Project {id:$project, userpkey:$owner}) DETACH DELETE p");
}

echo "\n";
if ($failures) {
    echo count($failures) . " FAILURE(S):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL CHECKS PASSED\n";
