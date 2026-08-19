<?php
/**
 * File: smoke_test_access_matrix.php
 * Description: Phase 5.C — the full access matrix, mechanically: EVERY
 *              criterion id in the §4.2 catalog × five identities
 *              (anonymous / stranger / disabled-collaborator / owner /
 *              accepted-collaborator) against a PRIVATE project whose
 *              fixture rows carry a matching value for every criterion
 *              column at once. Each criterion is searched composed with
 *              a fixture-unique keyword so real dev data can never
 *              satisfy the query — any non-zero total for a blocked
 *              identity is leakage through that criterion's predicate
 *              path (incl. the image-EXISTS routing and the
 *              parent-sample spine routing).
 *
 *              Also pins the Phase 5.C vocab fixes: the pre-aggregated
 *              anonymous facet feed (vocab_facet_counts) and the F7 tree
 *              (vocab_rock_type) are built from PUBLIC rows only.
 *
 *              Hermetic: upks 94580-94583, prefix 'uniqacm'. Runs
 *              refresh_vocab twice (fixtures present / after teardown) —
 *              ≈0.5s per run. Zero residue.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_access_matrix.php
 *
 * @package    StraboSearch Phase 5
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/searchdb/services/StraboSearchService.php';

$OWNER    = 94580;
$COLLAB   = 94581;
$DISABLED = 94582;
$STRANGER = 94583;
$PFX      = 'uniqacm';
$PROJ     = $PFX . '_proj';
$KEY      = 'UNIQACM';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

// ---------------------------------------------------------------------------
section('0. Fixtures');

function acm_cleanup($db, $PFX, $upks) {
	$db->prepare_query("DELETE FROM strabosearch.item_hit  WHERE project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM collaborators WHERE strabo_project_id LIKE $1", array($PFX . '_%'));
	$db->prepare_query("DELETE FROM users WHERE pkey IN ($1,$2,$3,$4)", $upks);
}
acm_cleanup($db, $PFX, array($OWNER, $COLLAB, $DISABLED, $STRANGER));

foreach (array(
	array($OWNER,    'Ann', 'Acmowner'),
	array($COLLAB,   'Bea', 'Acmcollab'),
	array($DISABLED, 'Cal', 'Acmdisabled'),
	array($STRANGER, 'Dee', 'Acmstranger'),
) as $u) {
	$db->prepare_query(
		"INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted)
		 VALUES ($1, $2, $3, $4, 'x', 'x', TRUE, FALSE)",
		array($u[0], $u[1], $u[2], strtolower($PFX . $u[0] . '@example.com')));
}

$db->prepare_query(
	"INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
	                            collaboration_level, accepted, disabled, uuid)
	 VALUES ($1, $2, $3, 'readonly', TRUE, FALSE, $4)",
	array($PROJ, $OWNER, $COLLAB, $PFX . '-collab'));
$db->prepare_query(
	"INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
	                            collaboration_level, accepted, disabled, uuid)
	 VALUES ($1, $2, $3, 'readonly', TRUE, TRUE, $4)",
	array($PROJ, $OWNER, $DISABLED, $PFX . '-disabled'));

// F10 needs a real province: any valid shapegeology polygon + an interior
// point (the fixture rows' location).
$prov = $db->get_row("SELECT gid,
		ST_X(ST_PointOnSurface(the_geom)) AS x, ST_Y(ST_PointOnSurface(the_geom)) AS y
	FROM shapegeology WHERE name IS NOT NULL AND ST_IsValid(the_geom)
	ORDER BY gid LIMIT 1");
check('province fixture resolved', $prov && $prov->gid !== null,
	$prov ? "gid={$prov->gid} @ {$prov->x},{$prov->y}" : 'none');
$GID = (int)$prov->gid;
$PX  = (float)$prov->x;
$PY  = (float)$prov->y;

// One PRIVATE field project; three rows carrying every criterion column.
// spot row — every item-side facet:
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
	 project_name, project_ispublic, searchtext_tsv, location, date_value,
	 has_orientation, has_samples, has_images, has_microstructure, has_strat,
	 orientation_strike, orientation_dip, orientation_trend, orientation_plunge,
	 orientation_features, orientation_planar, rock_types, met_facies, trace_types,
	 tag_names, tag_types, tag_text_tsv, minerals, mineral_methods,
	 instrument_type, detector_type, apparatus_type, daq_sensor_type, measurement_type,
	 source_modified)
	VALUES ('spot', '{$PFX}_spot', $OWNER, '$PROJ', $OWNER, 'field',
	 'acm private project', FALSE, to_tsvector('english', '$KEY secret'),
	 ST_SetSRID(ST_MakePoint($PX, $PY), 4326), '2031-07-15',
	 TRUE, TRUE, TRUE, TRUE, TRUE,
	 '{123.4}', '{45.6}', '{234.5}', '{12.3}',
	 '{uniqacm_feat}', '{t}', '{uniqacm_rock}', '{uniqacm_fac}', '{uniqacm_trace}',
	 '{uniqacmtag}', '{uniqacm_ttype}', to_tsvector('english', 'uniqacmtag'),
	 '{uniqacm_min}', '{uniqacm_mm}',
	 'uniqacm_inst', 'uniqacm_det', 'uniqacm_app', 'uniqacm_daq', 'uniqacm_meas',
	 now())");

// sample spine row — U5/U6/U7 item-side + the image sample routing target:
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
	 project_name, project_ispublic, searchtext_tsv,
	 sample_id, sample_name, igsn, display_sample_type, display_sample_purpose,
	 source_modified)
	VALUES ('sample', '{$PFX}_samprow', $OWNER, '$PROJ', $OWNER, 'field',
	 'acm private project', FALSE, to_tsvector('english', '$KEY sampletext'),
	 'UNIQACM-SAMP', 'uniqacm sample', 'UNIQACM-IGSN', 'uniqacm_stype', 'uniqacm_spurp',
	 now())");

// image row — image-side criteria + parent chains:
$db->query("INSERT INTO strabosearch.image_hit
	(image_id, image_subsystem, image_userpkey, image_type, annotated, title, caption,
	 imagetext_tsv, filename, parent_spot_id, parent_sample_id,
	 project_id, project_userpkey, project_subsystem, project_ispublic,
	 location, date_value,
	 orientation_strike, orientation_dip, orientation_trend, orientation_plunge,
	 orientation_features, orientation_planar, rock_types, met_facies, trace_types,
	 tag_names, tag_types, tag_text_tsv, minerals, mineral_methods,
	 instrument_type, detector_type, source_modified)
	VALUES ('{$PFX}_img', 'field', $OWNER, 'uniqacm_itype', TRUE, 'acm image', 'acm caption',
	 to_tsvector('english', 'uniqacmimgsecret'), '{$PFX}_nofile', '{$PFX}_spot', 'UNIQACM-SAMP',
	 '$PROJ', $OWNER, 'field', FALSE,
	 ST_SetSRID(ST_MakePoint($PX, $PY), 4326), '2031-07-15',
	 '{123.4}', '{45.6}', '{234.5}', '{12.3}',
	 '{uniqacm_feat}', '{t}', '{uniqacm_rock}', '{uniqacm_fac}', '{uniqacm_trace}',
	 '{uniqacmtag}', '{uniqacm_ttype}', to_tsvector('english', 'uniqacmtag'),
	 '{uniqacm_min}', '{uniqacm_mm}', 'uniqacm_inst', 'uniqacm_det', now())");

check('fixtures seeded', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit WHERE project_id = '$PROJ'") === 2);

// ---------------------------------------------------------------------------
// The criterion → DSL-value catalog (§4.2, all ids the builder accepts).
// Each is searched as [U1 $KEY, X] so only fixture rows can ever match.
// ---------------------------------------------------------------------------
$CRITERIA = array(
	'U1'  => $KEY,
	'U2'  => array('bbox' => array($PX - 0.2, $PY - 0.2, $PX + 0.2, $PY + 0.2)),
	'U3'  => array('min' => '2031-07-01', 'max' => '2031-07-31'),
	'U4'  => $OWNER,
	'U5'  => 'UNIQACM-SAMP',
	'U6'  => 'UNIQACM-IGSN',
	'U7'  => array('sample_type' => array('uniqacm_stype')),
	'U9'  => array('orientation', 'images'),
	'U10' => 'uniqacmtag',
	'F1'  => array('min' => 120, 'max' => 130),
	'F2'  => array('min' => 40,  'max' => 50),
	'F3'  => array('min' => 230, 'max' => 240),
	'F4'  => array('min' => 10,  'max' => 15),
	'F5'  => array('uniqacm_feat'),
	'F6'  => array('planar'),
	'F7'  => array('uniqacm_rock'),
	'F8'  => array('uniqacm_fac'),
	'F9'  => array('uniqacm_trace'),
	'F10' => null,   // set below (live gid)
	'F11' => array('uniqacm_ttype'),
	'M1'  => array('uniqacm_min'),
	'M2'  => array('uniqacm_mm'),
	'M3'  => array('uniqacm_inst'),
	'M4'  => array('uniqacm_det'),
	'E1'  => array('uniqacm_app'),
	'E2'  => array('uniqacm_daq'),
	'E3'  => array('uniqacm_meas'),
	'I1'  => array('uniqacm_itype'),
	'I2'  => true,
	'I3'  => 'uniqacmimgsecret',
);
$CRITERIA['F10'] = $GID;

$IDENTITIES = array(
	'anonymous'      => array(0,         0),
	'stranger'       => array($STRANGER, 0),
	'disabled-collab' => array($DISABLED, 0),
	'owner'          => array($OWNER,    1),
	'collaborator'   => array($COLLAB,   1),
);

function runAs($db, $upk, $dsl) {
	$svc = new StraboSearchService($db, $upk);
	return $svc->runSearch($dsl);
}

// ---------------------------------------------------------------------------
section('1. Projects pathway — every criterion × every identity');

foreach ($CRITERIA as $cid => $value) {
	$criteria = array(array('id' => 'U1', 'value' => $KEY));
	if ($cid !== 'U1') $criteria[] = array('id' => $cid, 'value' => $value);
	$dsl = array('pathway' => 'projects', 'criteria' => $criteria);

	$row = array();
	$ok = true;
	foreach ($GLOBALS['IDENTITIES'] as $name => $spec) {
		list($upk, $expect) = $spec;
		try {
			$r = runAs($db, $upk, $dsl);
			$got = $r['total'];
		} catch (Exception $e) {
			$got = 'ERR:' . $e->getMessage();
		}
		$row[] = "$name=$got";
		if ($got !== $expect) $ok = false;
	}
	check("$cid matrix", $ok, implode(' ', $row));
}

// ---------------------------------------------------------------------------
section('2. Images pathway — the distinct routing paths × every identity');

// U1 (parent-item EXISTS), U5/U6/U7 (spine routing), U9 (parent flags),
// I1/I2/I3 (native image columns).
foreach (array('U1', 'U5', 'U6', 'U7', 'U9', 'I1', 'I2', 'I3') as $cid) {
	$criteria = array(array('id' => 'U1', 'value' => $KEY . ' OR uniqacmimgsecret'));
	if ($cid !== 'U1') $criteria[] = array('id' => $cid, 'value' => $CRITERIA[$cid]);
	$dsl = array('pathway' => 'images', 'criteria' => $criteria);

	$row = array();
	$ok = true;
	foreach ($IDENTITIES as $name => $spec) {
		list($upk, $expect) = $spec;
		try {
			$r = runAs($db, $upk, $dsl);
			$got = $r['total'];
		} catch (Exception $e) {
			$got = 'ERR:' . $e->getMessage();
		}
		$row[] = "$name=$got";
		if ($got !== $expect) $ok = false;
	}
	check("$cid images matrix", $ok, implode(' ', $row));
}

// ---------------------------------------------------------------------------
section('3. Counterpart + summary carry no blocked-identity counts');

$dsl = array('pathway' => 'projects', 'criteria' => array(array('id' => 'U1', 'value' => $KEY)));
$r = runAs($db, 0, $dsl);
check('anon counterpart_total = 0', $r['counterpart_total'] === 0, 'got ' . $r['counterpart_total']);
check('anon subsystem_summary empty', $r['subsystem_summary'] === array(),
	json_encode($r['subsystem_summary']));
$r = runAs($db, $COLLAB, $dsl);
check('collab counterpart_total = 1 image', $r['counterpart_total'] === 1, 'got ' . $r['counterpart_total']);
check('collab summary sees the field project', isset($r['subsystem_summary']['field']['project'])
	&& $r['subsystem_summary']['field']['project'] === 1);

// ---------------------------------------------------------------------------
section('4. Anonymous vocab feeds are public-only (Phase 5.C fix)');

// Rebuild with the private fixtures present: their values must NOT land.
exec('php ' . escapeshellarg('/srv/app/www/searchdb/extractors/refresh_vocab.php') . ' 2>&1', $o, $rc);
check('refresh_vocab ran', $rc === 0, "exit $rc");
check('private mineral NOT in facet counts', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.vocab_facet_counts WHERE value = 'uniqacm_min'") === 0);
check('private rock type NOT in F7 tree', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.vocab_rock_type WHERE path = 'uniqacm_rock'") === 0);

// Control: a PUBLIC row's value must land (proves the filter is the reason).
$db->query("INSERT INTO strabosearch.item_hit
	(item_type, item_id, item_userpkey, project_id, project_userpkey, project_subsystem,
	 project_name, project_ispublic, searchtext_tsv, minerals, rock_types, source_modified)
	VALUES ('spot', '{$PFX}_pubspot', $OWNER, '{$PFX}_pubproj', $OWNER, 'field',
	 'acm public project', TRUE, to_tsvector('english', '$KEY pub'),
	 '{uniqacmpub_min}', '{uniqacmpub_rock}', now())");
exec('php ' . escapeshellarg('/srv/app/www/searchdb/extractors/refresh_vocab.php') . ' 2>&1', $o, $rc);
check('public mineral IS in facet counts', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.vocab_facet_counts WHERE value = 'uniqacmpub_min'") === 1);
check('public rock type IS in F7 tree', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.vocab_rock_type WHERE path = 'uniqacmpub_rock'") === 1);

// ---------------------------------------------------------------------------
section('5. Teardown + zero residue (vocab re-purged)');

acm_cleanup($db, $PFX, array($OWNER, $COLLAB, $DISABLED, $STRANGER));
exec('php ' . escapeshellarg('/srv/app/www/searchdb/extractors/refresh_vocab.php') . ' 2>&1', $o, $rc);
check('vocab purged of fixture values', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.vocab_facet_counts WHERE value LIKE 'uniqacm%'") === 0
	&& (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.vocab_rock_type WHERE path LIKE 'uniqacm%'") === 0);
check('zero item_hit residue', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit WHERE project_id LIKE '{$PFX}_%'") === 0);
check('zero image_hit residue', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit WHERE project_id LIKE '{$PFX}_%'") === 0);
check('zero collaborator residue', (int)$db->get_var(
	"SELECT count(*) FROM collaborators WHERE strabo_project_id LIKE '{$PFX}_%'") === 0);

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('=', 70) . PHP_EOL;
if ($failures) {
	echo count($failures) . " FAILURE(S):" . PHP_EOL;
	foreach ($failures as $f) echo "  - $f" . PHP_EOL;
	exit(1);
}
echo "ALL CHECKS PASSED." . PHP_EOL;
exit(0);
