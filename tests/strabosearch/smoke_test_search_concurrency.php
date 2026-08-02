<?php
/**
 * File: smoke_test_search_concurrency.php
 * Description: Phase 5.E — search during sync. Spawns writer workers that
 *              storm the REAL StraboSearchSync primitives (touchExperiment
 *              / removeExperiment, syncMicroProject / removeMicroProject —
 *              the pure-SQL primitives, so no Neo4j fixture is needed)
 *              against shared fixture entities, while the parent process
 *              hammers service-level searches. Asserts what the §5.3
 *              design promises under concurrency:
 *
 *                - a search NEVER errors mid-sync,
 *                - identity uniques + advisory locks mean a result total
 *                  is only ever 0 or 1 — duplicated rows never appear,
 *                - after the storm the index converges to exactly one row
 *                  per entity and verify_extended reports clean.
 *
 *              Worker mode: this file re-invokes itself with --worker.
 *
 *              Hermetic: upk 94590, prefix 'spconc'. Zero residue.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/strabosearch/smoke_test_search_concurrency.php
 *
 * @package    StraboSearch Phase 5
 */

chdir('/srv/app/www');
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/searchdb/sync/StraboSearchSync.php';

$UPK  = 94590;
$PFX  = 'spconc';
$ITER = 150;
$WORKERS = 3;
$MARK = '/tmp/' . $PFX . '_done_';

// ---------------------------------------------------------------------------
// Worker mode — storm the primitives, then drop a done marker.
// ---------------------------------------------------------------------------
$isWorker = in_array('--worker', $argv, true);
$wid = 0;
foreach ($argv as $a) if (preg_match('/^--wid=(\d+)$/', $a, $m)) $wid = (int)$m[1];

if ($isWorker) {
	$epk  = (int)$db->get_var("SELECT pkey FROM straboexp.experiment WHERE userpkey = $UPK");
	$mpid = (int)$db->get_var("SELECT id FROM strabomicro.micro_projectmetadata WHERE userpkey = $UPK");
	$MSTRABO = $PFX . '_mp';
	for ($i = 0; $i < $ITER; $i++) {
		// exp: bump the source row, re-touch; every 5th cycle delete +
		// re-add (the remove/touch race).
		$db->query("UPDATE straboexp.experiment SET modified_timestamp = now() WHERE pkey = $epk");
		if ($i % 5 === $wid % 5) {
			StraboSearchSync::removeExperiment($db, 'UNIQCONC experiment', $UPK);
		}
		StraboSearchSync::touchExperiment($db, $epk);

		// micro: full project re-sync; every 7th cycle remove + re-sync.
		// touchSample after: removeMicroProject sweeps the spine fan-out
		// row too, and the real hook flow re-touches linked samples.
		if ($i % 7 === $wid % 7) {
			StraboSearchSync::removeMicroProject($db, $MSTRABO, $UPK);
		}
		StraboSearchSync::syncMicroProject($db, $mpid, $MSTRABO, $UPK);
		StraboSearchSync::touchSample($db, $MSTRABO . '_samp', $UPK);
	}
	file_put_contents($GLOBALS['MARK'] . $wid, 'done');
	exit(0);
}

// ---------------------------------------------------------------------------
// Parent mode.
// ---------------------------------------------------------------------------
require_once '/srv/app/www/searchdb/services/StraboSearchService.php';

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}
function section($t) { echo PHP_EOL . str_repeat('=', 70) . PHP_EOL . "== $t" . PHP_EOL . str_repeat('=', 70) . PHP_EOL; }

section('0. Fixtures');

function conc_cleanup($db, $UPK, $PFX, $MARK, $WORKERS) {
	$db->prepare_query("DELETE FROM strabosearch.item_hit  WHERE item_userpkey = $1 OR project_userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM strabosearch.image_hit WHERE image_userpkey = $1 OR project_userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM strabomicro.micro_micrographmetadata WHERE sample_id IN
		(SELECT s.id FROM strabomicro.micro_samplemetadata s
		   JOIN strabomicro.micro_datasetmetadata d ON s.dataset_id = d.id
		   JOIN strabomicro.micro_projectmetadata p ON d.project_id = p.id
		  WHERE p.userpkey = $1)", array($UPK));
	$db->prepare_query("DELETE FROM strabomicro.micro_samplemetadata WHERE dataset_id IN
		(SELECT d.id FROM strabomicro.micro_datasetmetadata d
		   JOIN strabomicro.micro_projectmetadata p ON d.project_id = p.id
		  WHERE p.userpkey = $1)", array($UPK));
	$db->prepare_query("DELETE FROM strabomicro.micro_datasetmetadata WHERE project_id IN
		(SELECT id FROM strabomicro.micro_projectmetadata WHERE userpkey = $1)", array($UPK));
	$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM straboexp.experiment WHERE userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM straboexp.project WHERE userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM strabosamples.sample_subsystem_links WHERE sample_userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($UPK));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($UPK));
	for ($i = 0; $i < $WORKERS; $i++) @unlink($MARK . $i);
}
conc_cleanup($db, $UPK, $PFX, $MARK, $WORKERS);

$db->prepare_query(
	"INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted)
	 VALUES ($1, 'Con', 'Currency', $2, 'x', 'x', TRUE, FALSE)",
	array($UPK, $PFX . '@example.com'));

// PUBLIC exp project + one experiment (name carries the search key).
$db->query("INSERT INTO straboexp.project (userpkey, uuid, name, notes, ispublic)
	VALUES ($UPK, '{$PFX}-exp-uuid', 'UNIQCONC exp project', '', TRUE)");
$eppk = (int)$db->insert_id;
$db->query("INSERT INTO straboexp.experiment (project_pkey, userpkey, id, json, modified_timestamp)
	VALUES ($eppk, $UPK, 'UNIQCONC experiment', '{}', now())");
$epk = (int)$db->insert_id;

// PUBLIC micro chain (project → dataset → sample → micrograph).
$MSTRABO = $PFX . '_mp';
$db->query("INSERT INTO strabomicro.micro_projectmetadata
	(strabo_id, userpkey, name, ispublic, modifiedtimestamp, notes)
	VALUES ('$MSTRABO', $UPK, 'UNIQCONC micro project', TRUE, '1722400005000', '')");
$mpid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_datasetmetadata (project_id, strabo_id, name)
	VALUES ($mpid, '{$MSTRABO}_ds', 'conc ds')");
$mdid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_samplemetadata
	(dataset_id, strabo_id, label, sampleid, longitude, latitude, samplenotes)
	VALUES ($mdid, '{$MSTRABO}_samp', 'conc sample', 'CC-1', -101.5, 41.5, '')");
$msid = (int)$db->insert_id;
$db->query("INSERT INTO strabomicro.micro_micrographmetadata
	(sample_id, strabo_id, name, notes, imagetype, width, height)
	VALUES ($msid, '{$MSTRABO}_mg1', 'UNIQCONC Micrograph', '', 'Backscatter Electron (BSE)', 1024, 768)");

// Post-cutover reality: every micro sample has a spine row whose id IS the
// micro strabo_id — the verifier's [sanity] parent_sample check asserts it.
$db->query("INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
	VALUES ('{$MSTRABO}_samp', $UPK, 'conc micro sample', $UPK, $UPK)");
$db->query("INSERT INTO strabosamples.sample_subsystem_links
	(sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
	VALUES ('{$MSTRABO}_samp', $UPK, 'micro', '{$MSTRABO}_samp', $UPK,
	        '{\"project_strabo_id\": \"$MSTRABO\"}')");

// initial index state
check('initial touchExperiment', StraboSearchSync::touchExperiment($db, $epk) === true);
check('initial syncMicroProject', StraboSearchSync::syncMicroProject($db, $mpid, $MSTRABO, $UPK) === true);
check('initial touchSample', StraboSearchSync::touchSample($db, $MSTRABO . '_samp', $UPK) === true);

// ---------------------------------------------------------------------------
section('1. Storm — ' . $WORKERS . ' writers × ' . $ITER . ' cycles vs continuous search');

$self = '/srv/app/www/tests/strabosearch/smoke_test_search_concurrency.php';
for ($i = 0; $i < $WORKERS; $i++) {
	exec('php ' . escapeshellarg($self) . " --worker --wid=$i > /tmp/{$PFX}_w$i.log 2>&1 &");
}

$svc = new StraboSearchService($db, 0);   // anonymous — fixtures are public
$dsl = array('pathway' => 'projects', 'criteria' => array(
	array('id' => 'U1', 'value' => 'UNIQCONC')));

$reads = 0;
$errors = array();
$badTotals = array();
$seen = array();
$deadline = time() + 90;
while (time() < $deadline) {
	$done = 0;
	for ($i = 0; $i < $WORKERS; $i++) if (is_file($MARK . $i)) $done++;

	try {
		$r = $svc->runSearch($dsl);
		$reads++;
		$t = $r['total'];
		$seen[$t] = isset($seen[$t]) ? $seen[$t] + 1 : 1;
		// two fixture projects (exp + micro) both match UNIQCONC; each
		// group is 0-or-1 — total beyond 2 means duplicated identities.
		if ($t > 2) $badTotals[] = $t;
		foreach ($r['results'] as $g) {
			if ($g['match_counts']['experiment'] > 1) $badTotals[] = 'exp=' . $g['match_counts']['experiment'];
			if ($g['match_counts']['micrograph'] > 1) $badTotals[] = 'mg=' . $g['match_counts']['micrograph'];
		}
	} catch (Exception $e) {
		$errors[] = $e->getMessage();
	}

	if ($done === $WORKERS) break;
	usleep(50000);
}

check('all workers finished inside the window', $done === $WORKERS, "$done/$WORKERS");
check('reader made real progress', $reads >= 10, "$reads reads");
check('no search errored mid-sync', $errors === array(),
	count($errors) . ' errors: ' . implode(' | ', array_slice(array_unique($errors), 0, 3)));
check('no duplicated identities ever visible', $badTotals === array(),
	implode(',', array_slice($badTotals, 0, 5)));
echo '  observed totals during storm: ' . json_encode($seen) . PHP_EOL;

// worker logs must be silent (primitives never warn under contention)
$noisy = array();
for ($i = 0; $i < $WORKERS; $i++) {
	$log = trim((string)@file_get_contents("/tmp/{$PFX}_w$i.log"));
	if ($log !== '') $noisy[] = "w$i: " . substr($log, 0, 120);
}
check('worker logs silent', $noisy === array(), implode(' | ', $noisy));

// ---------------------------------------------------------------------------
section('2. Convergence — exactly one row per entity, verifier clean');

$n = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit
	WHERE item_type = 'experiment' AND item_userpkey = $UPK");
check('exactly 1 experiment row after storm', $n === 1, "got $n");
$n = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit
	WHERE item_type = 'micrograph' AND item_userpkey = $UPK");
check('exactly 1 micrograph row after storm', $n === 1, "got $n");
$n = (int)$db->get_var("SELECT count(*) FROM strabosearch.image_hit WHERE image_userpkey = $UPK");
check('exactly 1 micro image row after storm', $n === 1, "got $n");

$r = $svc->runSearch($dsl);
check('post-storm search: both projects, once each', $r['total'] === 2, 'total=' . $r['total']);

exec('php /srv/app/www/searchdb/verify_extended.php --only=micro,exp,sanity --source-userpkey=' . $UPK . ' 2>&1',
	$vout, $vrc);
check('verify_extended clean post-storm', $vrc === 0, "exit $vrc");

// ---------------------------------------------------------------------------
section('3. Teardown + zero residue');

conc_cleanup($db, $UPK, $PFX, $MARK, $WORKERS);
for ($i = 0; $i < $WORKERS; $i++) @unlink("/tmp/{$PFX}_w$i.log");
check('zero item_hit residue', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.item_hit WHERE item_userpkey = $UPK OR project_userpkey = $UPK") === 0);
check('zero image_hit residue', (int)$db->get_var(
	"SELECT count(*) FROM strabosearch.image_hit WHERE image_userpkey = $UPK OR project_userpkey = $UPK") === 0);
check('zero exp residue', (int)$db->get_var(
	"SELECT count(*) FROM straboexp.project WHERE userpkey = $UPK") === 0);
check('zero micro residue', (int)$db->get_var(
	"SELECT count(*) FROM strabomicro.micro_projectmetadata WHERE userpkey = $UPK") === 0);

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('=', 70) . PHP_EOL;
if ($failures) {
	echo count($failures) . " FAILURE(S):" . PHP_EOL;
	foreach ($failures as $f) echo "  - $f" . PHP_EOL;
	exit(1);
}
echo "ALL CHECKS PASSED." . PHP_EOL;
exit(0);
