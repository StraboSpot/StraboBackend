<?php
/**
 * File: reorder_keys_test.php
 * Description: microsync/tools/reorder_keys.php (P1-2 key order repair).
 *              A throwaway user uploads the dev fixtures 786 and 747 (point
 *              counts) through the legacy path and converts them, then:
 *                - a fresh conversion already keeps the app's key order
 *                  (json columns)
 *                - bodies and change-log states are scrambled the way jsonb
 *                  stored them, plus one spot the archive never had
 *                - dry run writes nothing
 *                - apply: backup + journal files, every body back in archive
 *                  order (the extra spot in its type's order), content equal
 *                  (Postgres jsonb comparison against the backup), a type the
 *                  project's own archive lacks in the order of another
 *                  project of the run, change-log
 *                  states in MsStore::state order, views dirty, head_seq
 *                  unchanged, worker-built project.json in app order
 *                - a second apply finds nothing to do
 *                - revert refuses (and writes nothing) when a row changed
 *                  since, and otherwise restores every stored text byte for byte
 *
 *              Usage: docker exec strabo-php php /srv/app/www/tests/microsync/reorder_keys_test.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
set_time_limit(0);
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microsync/lib/MsConvert.php';
$FILES = '/srv/app/www/straboMicroFiles';
$TOOL = 'php /srv/app/www/microsync/tools/reorder_keys.php';
$RUNS = MsWorker::dataDir() . '/reorder_keys';
$RUN = substr(md5(uniqid('', true)), 0, 6);
$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr($detail, 0, 1500) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function section($name) { echo "\n== $name\n"; }
$ms = new MsDb($db);

/** Object key paths in document order, depth first ("a", "a.b", "list[].c"). */
function paths($v, $pre = '') {
	$out = array();
	if ($v instanceof stdClass) {
		foreach ($v as $k => $x) {
			$out[] = $pre . $k;
			$out = array_merge($out, paths($x, $pre . $k . '.'));
		}
	} elseif (is_array($v)) {
		foreach ($v as $x) {
			$out = array_merge($out, paths($x, rtrim($pre, '.') . '[].'));
		}
	}
	return $out;
}
/** Archive order of $archive restricted to the paths $stored has. */
function inArchiveOrder($stored, $archive) {
	$have = array_flip(paths($stored));
	$want = array_values(array_unique(array_filter(paths($archive), function ($p) use ($have) { return isset($have[$p]); })));
	return array_values(array_unique(paths($stored))) === $want;
}
/** "type:id" => archive object (child collections removed), from the archived zip. */
function archiveEntities($pid) {
	$z = new ZipArchive();
	$z->open(MsConvert::archiveDir($pid) . '/project.zip');
	$out = array();
	$project = null;
	for ($i = 0; $i < $z->numFiles; $i++) {
		$n = $z->getNameIndex($i);
		if (substr_count($n, '/') === 1 && substr($n, -13) === '/project.json') $project = json_decode($z->getFromIndex($i));
		elseif (preg_match('#^[^/]+/point-counts/[^/]+\.json$#', $n)) { $pc = json_decode($z->getFromIndex($i)); $out['point_count:' . $pc->id] = $pc; }
	}
	$z->close();
	$walk = function ($type, $o) use (&$walk, &$out) {
		$t = clone $o;
		foreach (MsModel::$CHILD_KEYS[$type] as $ck => $ct) {
			if (isset($t->$ck) && is_array($t->$ck)) foreach ($t->$ck as $c) $walk($ct, $c);
			unset($t->$ck);
		}
		if (!isset($out["$type:{$t->id}"])) $out["$type:{$t->id}"] = $t;
	};
	$walk('project', $project);
	return $out;
}
/** Every stored text the tool may touch, keyed. */
function texts($pids) {
	global $ms;
	$out = array();
	foreach ($pids as $pid) {
		foreach ($ms->rows("SELECT entity_type, entity_id, body::text AS b, child_order::text AS c FROM strabomicro.micro_entities WHERE project_id = $1", array($pid)) as $r)
			$out["$pid e {$r['entity_type']}:{$r['entity_id']}"] = array($r['b'], $r['c']);
		foreach ($ms->rows("SELECT seq, before::text AS b, after::text AS a FROM strabomicro.micro_changes WHERE project_id = $1", array($pid)) as $r)
			$out["$pid c {$r['seq']}"] = array($r['b'], $r['a']);
	}
	ksort($out);
	return $out;
}
function tool($args, &$out) {
	global $TOOL;
	$lines = array();
	exec("$TOOL $args 2>&1", $lines, $rc);
	$out = implode("\n", $lines);
	return $rc;
}
/** Bodies of every entity of $pid that the archive has, checked against archive order; returns list of misses. */
function orderMisses($pid, $arch) {
	global $ms;
	$miss = array();
	foreach ($ms->rows("SELECT entity_type, entity_id, body::text AS b FROM strabomicro.micro_entities WHERE project_id = $1", array($pid)) as $r) {
		$k = "{$r['entity_type']}:{$r['entity_id']}";
		if (isset($arch[$k]) && !inArchiveOrder(json_decode($r['b']), $arch[$k])) $miss[] = $k;
	}
	return $miss;
}

$db->get_var('SELECT 1');
$email = "microsync-reorder-$RUN@test.strabospot.org";
$db->get_var_prepared("INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Reorder', 'Test', $1, 'x', 'x', true) RETURNING pkey", array($email));
$U = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1", array($email));
if ($U <= 0) { echo "cannot create the test user\n"; exit(1); }
$conv = new MsConvert($db);
$pids = array();
$runDirs = array();
$runsBefore = is_dir($RUNS) ? scandir($RUNS) : array();

try {
	section('Fixtures');
	$fx = array();
	foreach (array(786, 747) as $src) {
		$zip = "$FILES/$src/project.zip";
		if (!check("fixture $src has project.zip", is_file($zip))) throw new Exception('fixtures missing');
		$z = new ZipArchive();
		$z->open($zip);
		$sid = substr($z->getNameIndex(0), 0, strpos($z->getNameIndex(0), '/'));
		$z->close();
		legacy('jwt', 'upload', $U, $zip, $sid);
		$pid = pid_of($db, $U, $sid);
		check("legacy upload of $src", $pid !== null);
		$pids[] = $pid;
		$r = $conv->convert($pid, true);
		check("#$src converted", $r['status'] === 'converted', json_encode($r));
		$fx[$src] = $pid;
	}
	$A = $fx[786];
	$B = $fx[747];
	$archA = archiveEntities($A);
	$archB = archiveEntities($B);
	check('747 archive has point counts', count(array_filter(array_keys($archB), function ($k) { return strpos($k, 'point_count:') === 0; })) > 0);
	check('fresh conversion keeps the app key order (json columns)', orderMisses($A, $archA) === array() && orderMisses($B, $archB) === array(),
		json_encode(array_slice(array_merge(orderMisses($A, $archA), orderMisses($B, $archB)), 0, 5)));

	section('Scramble the way jsonb stored it');
	foreach ($pids as $pid) {
		$ms->q("UPDATE strabomicro.micro_entities SET body = body::jsonb::json WHERE project_id = $1", array($pid));
		$ms->q("UPDATE strabomicro.micro_changes SET before = before::jsonb::json, after = after::jsonb::json WHERE project_id = $1", array($pid));
	}
	// A spot the archive never had (created after the conversion), copied from an existing spot.
	$spot = $ms->row("SELECT parent_type, parent_id, body::text AS b FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'spot' ORDER BY entity_id LIMIT 1", array($A));
	check('786 has a spot to copy', $spot !== null);
	$nb = json_decode($spot['b']);
	$origId = $nb->id;
	$nb->id = 'RKNEW';
	$nb->name = 'Created after conversion';
	$ms->q("INSERT INTO strabomicro.micro_entities (project_id, entity_type, entity_id, parent_type, parent_id, body, created_by, updated_by)
		VALUES ($1, 'spot', 'RKNEW', $2, $3, ($4::jsonb)::json, $5, $5)", array($A, $spot['parent_type'], $spot['parent_id'], json_encode($nb), $U));
	// A point count in 786, whose archive has none: its order must come from 747's archive.
	$pcKeys = array_values(array_filter(array_keys($archB), function ($k) { return strpos($k, 'point_count:') === 0; }));
	check('786 archive has no point counts', count(array_filter(array_keys($archA), function ($k) { return strpos($k, 'point_count:') === 0; })) === 0);
	$pcTmpl = $archB[$pcKeys[0]];
	$pc = clone $pcTmpl;
	$pc->id = 'RKPC';
	$pc->micrographId = $ms->val("SELECT entity_id FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'micrograph' ORDER BY entity_id LIMIT 1", array($A));
	$pcRow = array($A, $pc->micrographId, json_encode($pc), $U);
	$insPc = function () use ($ms, &$pcRow) {
		$ms->q("INSERT INTO strabomicro.micro_entities (project_id, entity_type, entity_id, parent_type, parent_id, body, created_by, updated_by)
			VALUES ($1, 'point_count', 'RKPC', 'micrograph', $2, ($3::jsonb)::json, $4, $4)", $pcRow);
	};
	$insPc();
	$scrambledA = count(orderMisses($A, $archA));
	$scrambledB = count(orderMisses($B, $archB));
	check('scrambled: many bodies out of archive order', $scrambledA > 3 && $scrambledB > 3, "$scrambledA / $scrambledB");
	$headA = $ms->val("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($A));
	$ms->q("UPDATE strabomicro.micro_projectmetadata SET views_dirty_since = NULL WHERE id = ANY($1::int[])", array('{' . implode(',', $pids) . '}'));
	$scrambled = texts($pids);
	$only = '--only=' . implode(',', $pids);

	section('Dry run');
	$rc = tool($only, $out);
	check('dry run exits 0 and reports work', $rc === 0 && preg_match('/to reorder/', $out) && strpos($out, 'APPLIED') === false, $out);
	check('dry run wrote nothing', texts($pids) === $scrambled);
	check('needs --only', tool('', $o2) === 2);

	section('Apply');
	$rc = tool("$only --apply", $out);
	check('apply exits 0', $rc === 0, $out);
	preg_match('#\(files: (\S+)\)#', $out, $m);
	$dir = isset($m[1]) ? $m[1] : '';
	$runDirs[] = $dir;
	check('backup and journal written for both projects', $dir !== '' && is_file("$dir/backup-$A.json") && is_file("$dir/changed-$A.json") && is_file("$dir/backup-$B.json") && is_file("$dir/changed-$B.json"), $out);
	$bk = json_decode(file_get_contents("$dir/backup-$A.json"), true);
	$bkOk = count($bk['entities']) === (int)$ms->val("SELECT count(*) FROM strabomicro.micro_entities WHERE project_id = $1", array($A));
	foreach ($bk['entities'] as $e) $bkOk = $bkOk && $scrambled["$A e {$e['entity_type']}:{$e['entity_id']}"] === array($e['body'], $e['child_order']);
	check('backup holds every stored text exactly as it was', $bkOk);
	check('every archived entity back in app order', orderMisses($A, $archA) === array() && orderMisses($B, $archB) === array(),
		json_encode(array_slice(array_merge(orderMisses($A, $archA), orderMisses($B, $archB)), 0, 5)));
	$new = json_decode($ms->val("SELECT body::text FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'spot' AND entity_id = 'RKNEW'", array($A)));
	check('entity the archive never had follows its type order', $new !== null && inArchiveOrder($new, $archA["spot:$origId"]), json_encode($new));
	$newPc = json_decode($ms->val("SELECT body::text FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'point_count' AND entity_id = 'RKPC'", array($A)));
	check('type missing from its own archive takes the order from another project of the run',
		$newPc !== null && inArchiveOrder($newPc, $pcTmpl) && preg_match("/#$A entities \\d+ \\(order from: own archive entry \\d+, own archive type 1, other project 1, none 0\\)/", $out), json_encode($newPc) . "\n" . $out);
	$same = true;
	foreach (array($A, $B) as $pid) {
		foreach (json_decode(file_get_contents("$dir/changed-$pid.json"), true)['rewrites'] as $w) {
			$same = $same && $ms->val("SELECT ($1::jsonb = $2::jsonb)::text", array($w['old'], $w['new'])) === 'true';
		}
	}
	$after = texts($pids);
	$contentOk = count($after) === count($scrambled);
	$jeq = function ($x, $y) use ($ms) {
		return $ms->val("SELECT (coalesce($1::jsonb, 'null') = coalesce($2::jsonb, 'null'))::text", array($x, $y)) === 'true';
	};
	foreach ($scrambled as $k => $t) {
		$isEntity = strpos($k, ' e ') !== false;
		$contentOk = $contentOk && isset($after[$k]) && $jeq($t[0], $after[$k][0])
			&& ($isEntity ? $t[1] === $after[$k][1] : $jeq($t[1], $after[$k][1]));
	}
	check('content unchanged for every row (Postgres jsonb equality) and child_order untouched', $same && $contentOk);
	$stateOk = true;
	$checked = 0;
	foreach ($ms->rows("SELECT entity_type, entity_id, before::text AS b, after::text AS a FROM strabomicro.micro_changes WHERE project_id = $1", array($A)) as $r) {
		foreach (array($r['b'], $r['a']) as $s) {
			if ($s === null) continue;
			$st = json_decode($s);
			$checked++;
			$stateOk = $stateOk && array_keys(get_object_vars($st)) === array('parentType', 'parentId', 'body', 'childOrder', 'refs');
			$k = "{$r['entity_type']}:{$r['entity_id']}";
			if (isset($archA[$k]) && $st->body instanceof stdClass) $stateOk = $stateOk && inArchiveOrder($st->body, $archA[$k]);
		}
	}
	check("change-log states in MsStore::state order, bodies in app order ($checked states)", $stateOk && $checked > 0);
	check('views marked dirty, head_seq unchanged', $ms->val("SELECT (views_dirty_since IS NOT NULL)::text FROM strabomicro.micro_projectmetadata WHERE id = $1", array($A)) === 'true'
		&& $ms->val("SELECT head_seq FROM strabomicro.micro_projectmetadata WHERE id = $1", array($A)) === $headA);
	$pcApplied = $ms->val("SELECT body::text FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_id = 'RKPC'", array($A));
	$ms->q("DELETE FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_id IN ('RKNEW', 'RKPC')", array($A));
	$built = build_now($A);
	$pj = json_decode((string)@file_get_contents("$FILES/$A/project.json"));
	$m0 = isset($pj->datasets[0]->samples[0]->micrographs[0]) ? clone $pj->datasets[0]->samples[0]->micrographs[0] : null;
	$appOk = $m0 !== null;
	if ($appOk) {
		unset($m0->spots);
		$appOk = inArchiveOrder($m0, $archA["micrograph:{$m0->id}"]);
	}
	check("worker-built project.json lists micrograph fields in app order ($built)", $appOk);
	$ms->q("INSERT INTO strabomicro.micro_entities (project_id, entity_type, entity_id, parent_type, parent_id, body, created_by, updated_by)
		VALUES ($1, 'spot', 'RKNEW', $2, $3, $4::json, $5, $5)", array($A, $spot['parent_type'], $spot['parent_id'], json_encode($new, MsHttp::JSON_OUT), $U));
	$ms->q("INSERT INTO strabomicro.micro_entities (project_id, entity_type, entity_id, parent_type, parent_id, body, created_by, updated_by)
		VALUES ($1, 'point_count', 'RKPC', 'micrograph', $2, $3::json, $4, $4)", array($A, $pc->micrographId, $pcApplied, $U));
	$applied = texts($pids);

	section('Second apply');
	$rc = tool("$only --apply", $out);
	preg_match('#\(files: (\S+)\)#', $out, $m);
	if (isset($m[1])) $runDirs[] = $m[1];
	check('nothing left to reorder', $rc === 0 && substr_count($out, ': 0 to reorder; change-log states: 0 to reorder') === 2 && texts($pids) === $applied, $out);

	section('Revert');
	$run = basename($dir);
	$victim = $ms->row("SELECT entity_type, entity_id FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = 'micrograph' LIMIT 1", array($A));
	$ms->q("UPDATE strabomicro.micro_entities SET body = (body::jsonb || '{\"zz\":1}')::json WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3",
		array($A, $victim['entity_type'], $victim['entity_id']));
	$edited = texts($pids);
	$rc = tool("--revert=$run --only=$A", $out);
	check('revert refused when a row changed since, nothing written', $rc === 1 && strpos($out, 'NOT reverted') !== false && texts($pids) === $edited, $out);
	$ms->q("UPDATE strabomicro.micro_entities SET body = $4::json WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3",
		array($A, $victim['entity_type'], $victim['entity_id'], $applied["$A e {$victim['entity_type']}:{$victim['entity_id']}"][0]));
	$rc = tool("--revert=$run", $out);
	check('revert restores every stored text byte for byte', $rc === 0 && texts($pids) === $scrambled, $out);
} catch (Exception $e) {
	check('no exception', false, $e->getMessage());
} finally {
	foreach ($pids as $pid) {
		if ($pid === null) continue;
		$ms->q("DELETE FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_id IN ('RKNEW', 'RKPC')", array($pid));
		if ($ms->val("SELECT sync_format FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid)) === 'entity') {
			$why = $conv->revert($pid);
			if ($why !== null) echo "  (cleanup: revert of #$pid: $why)\n";
		}
		$sid = $ms->val("SELECT strabo_id FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		delete_synced($db, $U, $sid);
		$db->prepare_query("DELETE FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		if (is_dir("$FILES/$pid")) exec('rm -rf ' . escapeshellarg("$FILES/$pid"));
		if (is_dir(MsConvert::archiveDir($pid))) exec('rm -rf ' . escapeshellarg(MsConvert::archiveDir($pid)));
	}
	$db->prepare_query("DELETE FROM strabosamples.samples WHERE userpkey = $1", array($U));
	$db->prepare_query("DELETE FROM users WHERE pkey = $1", array($U));
	foreach (array_diff(is_dir($RUNS) ? scandir($RUNS) : array(), $runsBefore) as $d) {
		if ($d !== '.' && $d !== '..') exec('rm -rf ' . escapeshellarg("$RUNS/$d"));
	}
}

echo "\n" . ($failures ? count($failures) . ' FAILED' : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
