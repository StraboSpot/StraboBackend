<?php
/**
 * File: smoke_test_samples_extractor.php
 * Description: Permanent smoke suite for the Samples extractor — verifies
 *              that searchdb/extractors/samples.php fans out
 *              strabosearch.item_hit rows tagged item_type='sample' from
 *              the strabosamples spine (samples + sample_subsystem_links).
 *
 *              Hermetic: seeds spine fixtures under an isolated userpkey
 *              (94520), plus the three per-subsystem resolution anchors
 *              the extractor joins against:
 *                - field:  fixture SPOT rows inserted directly into
 *                          strabosearch.item_hit (the extractor resolves
 *                          field links via the indexed Field slice — no
 *                          Neo4j involved, which is the design point of
 *                          running Samples LAST per §5.2.2)
 *                - micro:  strabomicro.micro_projectmetadata rows
 *                - exp:    straboexp.project rows
 *              Runs the extractor with --source-userpkey=94520 --apply,
 *              asserts, tears down.
 *
 *              Scenarios covered:
 *                A. Sample with own coords + field link → one row carrying
 *                   the host Field project tuple, the sample's OWN point,
 *                   all U5/U6/U7 facet columns, has_samples=TRUE, and
 *                   searchtext hits for name / igsn / description /
 *                   custom_data values.
 *                B. Sample without coords + TWO links to spots in the SAME
 *                   project → ONE row (DISTINCT + conflict soak), location
 *                   inherited from a host spot.
 *                C. Cross-subsystem fan-out: one sample linked to field +
 *                   micro + exp → three rows; 'experimental' link value
 *                   translated to project_subsystem='exp'; micro resolution
 *                   disambiguates by (strabo_id, userpkey) — a DECOY micro
 *                   project with the SAME strabo_id under another user must
 *                   not bleed its name/ispublic in.
 *                D. Orphan sample (zero links) → zero rows (v1 rule).
 *                E. Field link pointing at a spot that is NOT in the index
 *                   → no row; extractor reports it as unresolved.
 *                F. Decoy spine sample with the SAME id under another
 *                   userpkey → excluded by --source-userpkey; its unique
 *                   token appears in no TEST row.
 *                G. Atomic swap idempotency: re-running gives the same
 *                   row set.
 *                H. sync_state NOT updated on --source-userpkey partial run.
 *
 *              Self-contained: cleans up before and after. Exits non-zero
 *              on any failure so it can gate CI.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_samples_extractor.php
 *
 * @package    StraboSearch Phase 2 extractors
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';

$TEST_UPK    = 94520;
$DECOY_UPK   = 94521;
$PREFIX      = 'spsxs94520';   // strabosearch-eXtractor-Samples + isolation upk

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	$mark = $cond ? '  PASS' : '  FAIL';
	echo $mark . '  ' . $label . ($detail !== '' ? '  -- ' . $detail : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . '== ' . $t . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

// ===========================================================================
section('0. Cleanup any leftover fixtures');

function samples_drop_fixtures($db, $upks) {
	$in = implode(',', $upks);
	// links cascade from samples; changelog cascades too.
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabomicro.micro_projectmetadata WHERE userpkey IN ($in)");
	$db->query("DELETE FROM straboexp.project WHERE userpkey IN ($in)");
	// Fixture spot rows + extracted sample rows.
	$db->query("DELETE FROM strabosearch.item_hit
		WHERE (item_userpkey IN ($in) OR project_userpkey IN ($in))");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
}
samples_drop_fixtures($db, array($TEST_UPK, $DECOY_UPK));
$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_samples");

$db->query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active)
	VALUES ($TEST_UPK, 'spsxs', 'fixture',
	        'spsxs-fixture-$TEST_UPK@test.strabospot.org', 'x', 'x', false),
	       ($DECOY_UPK, 'spsxs', 'decoy',
	        'spsxs-decoy-$DECOY_UPK@test.strabospot.org', 'x', 'x', false)");

echo '  cleared fixture spine + resolution anchors + item_hit + users for upk '
	. $TEST_UPK . ' and decoy ' . $DECOY_UPK . PHP_EOL;

// ===========================================================================
section('1. Seed resolution anchors (field slice / micro project / exp project)');

// --- Field: two fixture spot rows in the SAME Field project, pre-indexed.
$FIELD_PROJ = $PREFIX . '_fieldproj';
foreach (array(
	array('spsxs_spot1', 'POINT(-100 40)'),
	array('spsxs_spot2', 'POINT(-101 41)'),
) as $spot) {
	$db->query("INSERT INTO strabosearch.item_hit
		(item_type, item_id, item_userpkey,
		 project_id, project_userpkey, project_subsystem, project_name, project_ispublic,
		 location)
		VALUES ('spot', '{$spot[0]}', $TEST_UPK,
		        '$FIELD_PROJ', $TEST_UPK, 'field',
		        'spsxs Field Project UNIQKEY_fieldprojname', TRUE,
		        ST_SetSRID(ST_GeomFromText('{$spot[1]}'), 4326))");
}

// --- Micro: private project under TEST + PUBLIC decoy with the SAME
// strabo_id under DECOY (exercises the (strabo_id, userpkey) join).
$MICRO_SID = $PREFIX . '_microproj';
$db->query("INSERT INTO strabomicro.micro_projectmetadata (strabo_id, userpkey, name, ispublic)
	VALUES ('$MICRO_SID', $TEST_UPK, 'spsxs Micro Project', FALSE)");
$db->query("INSERT INTO strabomicro.micro_projectmetadata (strabo_id, userpkey, name, ispublic)
	VALUES ('$MICRO_SID', $DECOY_UPK, 'spsxs DECOY Micro Project UNIQKEY_decoymicroname', TRUE)");

// --- Exp: public project under TEST.
$EXP_UUID = $PREFIX . '-exp-uuid-1';
$db->query("INSERT INTO straboexp.project (userpkey, uuid, name, notes, ispublic)
	VALUES ($TEST_UPK, '$EXP_UUID', 'spsxs Exp Project', '', TRUE)");

echo "  seeded 2 field spot index rows + micro project (+decoy) + exp project\n";

// ===========================================================================
section('2. Seed spine samples + links');

function seed_sample($db, $id, $upk, $name, $igsn, $desc, $lat, $lng, $custom) {
	$customLit = $custom === null ? 'NULL' : "'" . pg_escape_string($custom) . "'::jsonb";
	$latLit = $lat === null ? 'NULL' : (string)(float)$lat;
	$lngLit = $lng === null ? 'NULL' : (string)(float)$lng;
	$db->query("INSERT INTO strabosamples.samples
		(id, userpkey, name, igsn, description, notes, latitude, longitude,
		 display_sample_type, display_sample_purpose, created_by, modified_by, custom_data)
		VALUES ('" . pg_escape_string($id) . "', $upk,
		        '" . pg_escape_string($name) . "',
		        " . ($igsn === null ? 'NULL' : "'" . pg_escape_string($igsn) . "'") . ",
		        '" . pg_escape_string($desc) . "', 'fixture notes',
		        $latLit, $lngLit,
		        'Rock', 'Geochemistry', $upk, $upk, $customLit)");
}
function seed_link($db, $sampleId, $upk, $subsystem, $refId, $refUpk, $metaJson) {
	$metaLit = $metaJson === null ? 'NULL' : "'" . pg_escape_string($metaJson) . "'::jsonb";
	$db->query("INSERT INTO strabosamples.sample_subsystem_links
		(sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
		VALUES ('" . pg_escape_string($sampleId) . "', $upk, '$subsystem',
		        '" . pg_escape_string($refId) . "', $refUpk, $metaLit)");
}

// A — own coords + field link.
seed_sample($db, 'spsxs_s1', $TEST_UPK, 'spsxs Alpha Sample', 'SPSXS0001',
	'UNIQKEY_s1desc alpha description', 48.8, 2.3,
	'{"drill_core_run": "UNIQKEY_customtok", "depth_m": 12.5}');
seed_link($db, 'spsxs_s1', $TEST_UPK, 'field', 'spsxs_spot1', $TEST_UPK, '{"rich": false}');

// B — no coords, two links to spots in the SAME field project.
seed_sample($db, 'spsxs_s2', $TEST_UPK, 'spsxs Beta Sample', null,
	'', null, null, null);
seed_link($db, 'spsxs_s2', $TEST_UPK, 'field', 'spsxs_spot1', $TEST_UPK, null);
seed_link($db, 'spsxs_s2', $TEST_UPK, 'field', 'spsxs_spot2', $TEST_UPK, null);

// C — cross-subsystem fan-out: field + micro + exp.
seed_sample($db, 'spsxs_s3', $TEST_UPK, 'spsxs Gamma Sample', null,
	'', null, null, null);
seed_link($db, 'spsxs_s3', $TEST_UPK, 'field', 'spsxs_spot1', $TEST_UPK, null);
seed_link($db, 'spsxs_s3', $TEST_UPK, 'micro', '999901', $TEST_UPK,
	'{"dataset_id": 999901, "project_strabo_id": "' . $MICRO_SID . '"}');
seed_link($db, 'spsxs_s3', $TEST_UPK, 'experimental', '999902', $TEST_UPK,
	'{"project_pkey": 999902, "project_uuid": "' . $EXP_UUID . '"}');

// D — orphan: no links.
seed_sample($db, 'spsxs_s4', $TEST_UPK, 'spsxs Orphan Sample', null,
	'UNIQKEY_orphandesc', 10.0, 10.0, null);

// E — field link to a spot that is NOT in the index.
seed_sample($db, 'spsxs_s6', $TEST_UPK, 'spsxs Unresolved Sample', null,
	'', null, null, null);
seed_link($db, 'spsxs_s6', $TEST_UPK, 'field', 'spsxs_spot_missing', $TEST_UPK, null);

// F — decoy spine sample, SAME id as A under DECOY_UPK, linked to the decoy
// micro project. Must be entirely absent from a --source-userpkey=TEST run.
seed_sample($db, 'spsxs_s1', $DECOY_UPK, 'spsxs DECOY Sample UNIQKEY_decoysamplename', null,
	'', null, null, null);
seed_link($db, 'spsxs_s1', $DECOY_UPK, 'micro', '999903', $DECOY_UPK,
	'{"project_strabo_id": "' . $MICRO_SID . '"}');

echo "  seeded 5 TEST samples (A/B/C/D/E) + 1 decoy (same id as A)\n";

// ===========================================================================
section('3. Run extractor');

$syncBefore = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='samples'");

$cmd = "php /srv/app/www/searchdb/extractors/samples.php --source-userpkey=$TEST_UPK --apply 2>&1";
$out = shell_exec($cmd);
$ok = (strpos($out, 'SWAP FAILED') === false && strpos($out, 'DROPPED batches') === false);
check('extractor exits clean', $ok, $ok ? '' : substr($out, -400));
check('extractor reports 1 unresolved field link',
	strpos($out, 'field links unresolved: 1') !== false,
	'output should count spsxs_s6\'s dangling link');

// ===========================================================================
section('4. Row presence + fan-out shape');

$total = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_userpkey = $TEST_UPK"
);
check('5 sample rows total (A:1 + B:1 + C:3)', $total === 5, "got $total");

$bySub = array();
$rows = $db->get_results(
	"SELECT project_subsystem, count(*) AS n FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_userpkey = $TEST_UPK
	 GROUP BY project_subsystem"
);
foreach ((array)$rows as $r) $bySub[$r->project_subsystem] = (int)$r->n;
check('3 field-hosted rows', isset($bySub['field']) && $bySub['field'] === 3,
	'got ' . json_encode($bySub));
check('1 micro-hosted row', isset($bySub['micro']) && $bySub['micro'] === 1);
check("1 exp-hosted row ('experimental' link translated to 'exp')",
	isset($bySub['exp']) && $bySub['exp'] === 1);
check("no rows with project_subsystem='experimental' (untranslated)",
	!isset($bySub['experimental']));

// ===========================================================================
section('5. Scenario A — facets, own location, searchtext');

$rowA = $db->get_row(
	"SELECT project_id, project_userpkey, project_name, project_ispublic,
	        sample_id, sample_name, igsn, display_sample_type, display_sample_purpose,
	        has_samples, has_orientation,
	        ST_AsText(location) AS loc, date_value::text AS dv,
	        (date_value = CURRENT_DATE) AS dv_today
	 FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_id = 'spsxs_s1' AND item_userpkey = $TEST_UPK"
);
check('A: row found', $rowA !== null);
if ($rowA) {
	check('A: project tuple = host Field project',
		$rowA->project_id === $FIELD_PROJ && (int)$rowA->project_userpkey === $TEST_UPK,
		"got ({$rowA->project_id}, {$rowA->project_userpkey})");
	check('A: project_name carried from Field slice',
		strpos((string)$rowA->project_name, 'spsxs Field Project') === 0);
	check('A: project_ispublic = TRUE', $rowA->project_ispublic === 't');
	check("A: sample_id = item_id = 'spsxs_s1'", $rowA->sample_id === 'spsxs_s1');
	check("A: sample_name = 'spsxs Alpha Sample'", $rowA->sample_name === 'spsxs Alpha Sample');
	check("A: igsn = 'SPSXS0001'", $rowA->igsn === 'SPSXS0001');
	check("A: display_sample_type = 'Rock'", $rowA->display_sample_type === 'Rock');
	check("A: display_sample_purpose = 'Geochemistry'", $rowA->display_sample_purpose === 'Geochemistry');
	check('A: has_samples = TRUE', $rowA->has_samples === 't');
	check('A: has_orientation = FALSE', $rowA->has_orientation === 'f');
	check('A: location = sample\'s OWN point (2.3, 48.8), not the spot\'s',
		strpos((string)$rowA->loc, 'POINT(2.3 48.8)') !== false, "got '{$rowA->loc}'");
	check('A: date_value = today (modified_at::date)', $rowA->dv_today === 't',
		"got {$rowA->dv}");
}

$tsvChecks = array(
	array('uniqkey_s1desc',   'description token'),
	array('spsxs0001',        'igsn token'),
	array('uniqkey_customtok','custom_data value token'),
	array('alpha',            'sample-name token'),
);
foreach ($tsvChecks as $tc) {
	$n = (int)$db->get_var(
		"SELECT count(*) FROM strabosearch.item_hit
		 WHERE item_type = 'sample' AND item_id = 'spsxs_s1' AND item_userpkey = $TEST_UPK
		   AND searchtext_tsv @@ to_tsquery('{$tc[0]}')"
	);
	check("A: searchtext finds {$tc[1]} ('{$tc[0]}')", $n === 1, "got $n");
}

$projNameHits = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_userpkey = $TEST_UPK
	   AND searchtext_tsv @@ to_tsquery('uniqkey_fieldprojname')"
);
check('host project-name token on all 3 field-hosted rows', $projNameHits === 3,
	"got $projNameHits");

// ===========================================================================
section('6. Scenario B — duplicate links collapse; location inherited from host spot');

$rowsB = $db->get_results(
	"SELECT ST_AsText(location) AS loc FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_id = 'spsxs_s2' AND item_userpkey = $TEST_UPK"
);
$rowsB = (array)$rowsB;
check('B: exactly ONE row despite two links into the same project',
	count($rowsB) === 1, 'got ' . count($rowsB));
if (count($rowsB) === 1) {
	$locB = (string)$rowsB[0]->loc;
	check('B: location inherited from a host spot (non-NULL)',
		strpos($locB, 'POINT(-100 40)') !== false || strpos($locB, 'POINT(-101 41)') !== false,
		"got '$locB'");
}

// ===========================================================================
section('7. Scenario C — cross-subsystem fan-out + micro disambiguation');

$rowCmicro = $db->get_row(
	"SELECT project_id, project_userpkey, project_name, project_ispublic,
	        ST_AsText(location) AS loc
	 FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_id = 'spsxs_s3' AND item_userpkey = $TEST_UPK
	   AND project_subsystem = 'micro'"
);
check('C: micro row found', $rowCmicro !== null);
if ($rowCmicro) {
	check('C: micro project resolved to TEST user\'s (strabo_id, userpkey)',
		(int)$rowCmicro->project_userpkey === $TEST_UPK);
	check("C: micro project_name = 'spsxs Micro Project' (NOT the decoy's)",
		$rowCmicro->project_name === 'spsxs Micro Project',
		"got '{$rowCmicro->project_name}'");
	check('C: micro project_ispublic = FALSE (decoy is public — no bleed)',
		$rowCmicro->project_ispublic === 'f');
	check('C: micro row location NULL (no sample coords; no host fallback outside field)',
		$rowCmicro->loc === null, 'got ' . var_export($rowCmicro->loc, true));
}

$rowCexp = $db->get_row(
	"SELECT project_id, project_userpkey, project_name, project_ispublic
	 FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_id = 'spsxs_s3' AND item_userpkey = $TEST_UPK
	   AND project_subsystem = 'exp'"
);
check('C: exp row found', $rowCexp !== null);
if ($rowCexp) {
	check('C: exp project_id = project uuid', $rowCexp->project_id === $EXP_UUID,
		"got '{$rowCexp->project_id}'");
	check('C: exp project_userpkey = link reference_userpkey',
		(int)$rowCexp->project_userpkey === $TEST_UPK);
	check("C: exp project_name = 'spsxs Exp Project'",
		$rowCexp->project_name === 'spsxs Exp Project');
	check('C: exp project_ispublic = TRUE', $rowCexp->project_ispublic === 't');
}

$rowCfield = $db->get_row(
	"SELECT ST_AsText(location) AS loc FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_id = 'spsxs_s3' AND item_userpkey = $TEST_UPK
	   AND project_subsystem = 'field'"
);
check('C: field row found', $rowCfield !== null);
if ($rowCfield) {
	check('C: field row inherits spot1 location',
		strpos((string)$rowCfield->loc, 'POINT(-100 40)') !== false,
		"got '{$rowCfield->loc}'");
}

// ===========================================================================
section('8. Scenarios D + E — orphan and unresolved produce no rows');

$orphanRows = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_id = 'spsxs_s4' AND item_userpkey = $TEST_UPK"
);
check('D: orphan sample → 0 rows (v1 rule)', $orphanRows === 0, "got $orphanRows");

$unresolvedRows = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_id = 'spsxs_s6' AND item_userpkey = $TEST_UPK"
);
check('E: dangling field link → 0 rows', $unresolvedRows === 0, "got $unresolvedRows");

// ===========================================================================
section('9. Scenario F — decoy spine sample must not leak');

$decoyRows = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_userpkey = $DECOY_UPK"
);
check('F: no rows for DECOY_UPK (excluded by --source-userpkey)',
	$decoyRows === 0, "got $decoyRows");

$decoyTok = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_userpkey = $TEST_UPK
	   AND searchtext_tsv @@ to_tsquery('uniqkey_decoysamplename | uniqkey_decoymicroname')"
);
check('F: decoy tokens absent from all TEST rows', $decoyTok === 0, "got $decoyTok");

// ===========================================================================
section('10. Scenario G — idempotent re-run');

shell_exec($cmd);
$total2 = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_userpkey = $TEST_UPK"
);
check('idempotent re-run: still 5 sample rows', $total2 === 5, "got $total2");

$rowA2 = $db->get_row(
	"SELECT igsn FROM strabosearch.item_hit
	 WHERE item_type = 'sample' AND item_id = 'spsxs_s1' AND item_userpkey = $TEST_UPK"
);
check('idempotent: A still carries igsn', $rowA2 && $rowA2->igsn === 'SPSXS0001');

$spotRows = (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit
	 WHERE item_type = 'spot' AND item_userpkey = $TEST_UPK"
);
check('fixture spot rows untouched by the sample-slice swap', $spotRows === 2,
	"got $spotRows");

// ===========================================================================
section('11. Scenario H — sync_state NOT updated on partial run');

$syncAfter = $db->get_var("SELECT last_full_backfill FROM strabosearch.sync_state WHERE source='samples'");
check('sync_state.samples last_full_backfill unchanged after --source-userpkey',
	$syncBefore === $syncAfter,
	'before=' . var_export($syncBefore, true) . ' after=' . var_export($syncAfter, true));

// ===========================================================================
section('12. Cleanup');

samples_drop_fixtures($db, array($TEST_UPK, $DECOY_UPK));
$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_samples");
echo '  cleared fixture spine + anchors + item_hit slices + staging + users' . PHP_EOL;

// ===========================================================================
echo PHP_EOL;
if ($failures) {
	echo count($failures) . ' FAIL(s):' . PHP_EOL;
	foreach ($failures as $f) echo '  - ' . $f . PHP_EOL;
	exit(1);
}
echo 'ALL CHECKS PASSED.' . PHP_EOL;
exit(0);
?>
