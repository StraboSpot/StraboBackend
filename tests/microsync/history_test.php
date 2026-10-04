<?php
/**
 * File: history_test.php
 * Description: Tests for a synced project's history (v3 §12b 17ae;
 *              microsync/lib/MsHistory.php): the project as of any change,
 *              rebuilt from the change log, the .smz "as of" copy
 *              (GET projects/{pid}/smz?seq=|at=, new id and name, files of
 *              that time, no StraboSamples overlay), and the history list's
 *              filters (user=, until=, detail=1, historyStart).
 *
 *              Part 1 (read only): for every ready synced project on this
 *              server, the project as of its last change, rebuilt from the
 *              log, equals the project assembled from the entity table.
 *              Part 2 (hermetic, mstest-history- straboIds): a project
 *              edited step by step (renames, fields, image replaced,
 *              attachment, delete, move, restore, a member's edit); the
 *              rebuild at each step equals what the store held then.
 *
 *              Usage (MICROSYNC_ENABLED must be true in the dev config):
 *                docker exec strabo-php timeout 900 php /srv/app/www/tests/microsync/history_test.php
 *
 *              Fixture users from tests/collaboration/setup_test_data.php.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
$BASE = 'http://localhost/microsync/v1';
require_once '/srv/app/www/tests/lib/microsync_client.php';
require_once '/srv/app/www/microsync/lib/MsHttp.php';
require_once '/srv/app/www/microsync/lib/MsDb.php';
require_once '/srv/app/www/microsync/lib/MsStore.php';
require_once '/srv/app/www/microsync/lib/MsHistory.php';
require_once '/srv/app/www/microsync/lib/MsWorker.php';

$PREFIX = 'mstest-history-';
$TMP = '/tmp/history_test';
$failures = array();
function check($label, $cond, $detail = null) {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== null ? "  -> $detail" : '') . "\n";
	if (!$cond) {
		$failures[] = $label;
	}
}
function section($name) {
	echo "\n== $name\n";
}
function cleanup() {
	global $db, $PREFIX;
	foreach ((array)$db->get_results_prepared(
		"SELECT strabo_id, userpkey FROM strabomicro.micro_projectmetadata WHERE strabo_id LIKE $1", array($PREFIX . '%')) as $r) {
		delete_synced($db, (int)$r->userpkey, $r->strabo_id);
	}
}
/** Push changes in one push; returns status strings ("accepted", "invalid/schema", ...). */
function push($pid, $tok, $changes) {
	$r = req('POST', "/projects/$pid/push", $tok, array('pushId' => uuid(), 'clientId' => 'history-test', 'changes' => $changes));
	if (!isset($r['body']['results'])) {
		return array('http_' . $r['code'] . (isset($r['body']['error']) ? '/' . $r['body']['error'] : ''));
	}
	return array_map(function ($x) { return $x['status'] . (isset($x['reason']) ? '/' . $x['reason'] : ''); }, $r['body']['results']);
}
function allAccepted($statuses) {
	return count($statuses) > 0 && count(array_filter($statuses, function ($s) { return $s !== 'accepted'; })) === 0;
}
function version($pid, $type, $id) {
	global $MS;
	return (int)$MS->val("SELECT version FROM strabomicro.micro_entities WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3",
		array($pid, $type, $id));
}
function upd($pid, $type, $id, $extra) {
	return array_merge(array('op' => 'update', 'type' => $type, 'id' => $id, 'baseVersion' => version($pid, $type, $id)), $extra);
}
/** What a rebuild is compared on. */
function picture($a) {
	if ($a === null) {
		return null;
	}
	$refs = $a['refs'];
	ksort($refs);
	foreach ($refs as $k => $v) {
		ksort($v);
		$refs[$k] = $v;
	}
	$pc = $a['pointCounts'];
	ksort($pc);
	return array('json' => $a['json'], 'refs' => $refs, 'pointCounts' => json_encode($pc), 'micrographs' => $a['micrographs']);
}
function lastSeq($pid) {
	global $MS;
	$r = MsHistory::range($MS, $pid);
	return $r === null ? 0 : $r['lastSeq'];
}
/** Open a downloaded .smz: root folders, project.json (decoded), and a reader for entries. */
function openSmz($raw) {
	global $TMP;
	$f = $TMP . '/' . uuid() . '.smz';
	file_put_contents($f, $raw);
	$za = new ZipArchive();
	if ($za->open($f, ZipArchive::CHECKCONS) !== true) {
		return null;
	}
	$roots = array();
	for ($i = 0; $i < $za->numFiles; $i++) {
		$roots[explode('/', $za->getNameIndex($i))[0]] = true;
	}
	$root = count($roots) === 1 ? array_keys($roots)[0] : null;
	return array('za' => $za, 'roots' => array_keys($roots), 'root' => $root,
		'project' => $root === null ? null : json_decode($za->getFromName("$root/project.json")));
}
function spotNames($project) {
	$out = array();
	foreach ($project->datasets as $d) {
		foreach ($d->samples as $s) {
			foreach ($s->micrographs as $m) {
				foreach ($m->spots as $p) {
					$out[$p->id] = $p->name;
				}
			}
		}
	}
	ksort($out);
	return $out;
}

$db->get_var('SELECT 1');
$MS = new MsDb($db);
@mkdir($TMP, 0777, true);
$U = array();
foreach (array('owner' => 'owner@test.strabospot.org', 'editor' => 'editor@test.strabospot.org',
               'outsider' => 'outsider@test.strabospot.org', 'dan' => 'dan.okafor@test.strabospot.org') as $k => $email) {
	$pkey = (int)$db->get_var_prepared("SELECT pkey FROM users WHERE email = $1 AND deleted = false", array($email));
	if ($pkey <= 0) {
		fwrite(STDERR, "Missing fixture user $email (run tests/collaboration/setup_test_data.php)\n");
		exit(2);
	}
	$U[$k] = array('pkey' => $pkey, 'tok' => token($pkey), 'email' => $email);
}
$OWN = $U['owner']['tok'];
$EDT = $U['editor']['tok'];
$OUT = $U['outsider']['tok'];
$DAN = $U['dan']['tok'];

cleanup();

try {
	section('Part 1: every ready synced project, rebuilt from the log as of its last change = the entity table');
	$n = 0;
	$bad = array();
	foreach ($MS->rows(
		"SELECT p.id, p.strabo_id FROM strabomicro.micro_projectmetadata p
		  WHERE p.sync_format = 'entity' AND p.sync_state = 'ready' AND p.strabo_id NOT LIKE 'mstest-%'
		  ORDER BY p.id") as $p) {
		$pid = (int)$p['id'];
		$last = lastSeq($pid);
		if ($last === 0) {
			continue;
		}
		$n++;
		$now = picture(MsWorker::assemble($MS, $pid, $p['strabo_id']));
		$log = picture(MsWorker::assemble($MS, $pid, $p['strabo_id'], $last));
		if ($now !== $log) {
			$bad[] = $pid;
		}
	}
	check("$n projects rebuilt exactly", $n > 0 && empty($bad), 'differ: ' . implode(', ', $bad));

	section('Part 2 setup: a shared project with an image, a spot, an attachment target and a point count');
	$SID = $PREFIX . substr(uuid(), 0, 8);
	$r = req('POST', '/projects', $OWN, array('straboId' => $SID, 'name' => 'History test'));
	$PID = (int)$r['body']['pid'];
	$imgA = "image A " . str_repeat('a', 2000);
	$imgB = "image B " . str_repeat('b', 3000);
	$s = push($PID, $OWN, array(
		array('op' => 'create', 'type' => 'project', 'id' => $SID, 'body' => array('name' => 'History test')),
		array('op' => 'create', 'type' => 'dataset', 'id' => 'D1', 'parentType' => 'project', 'parentId' => $SID, 'body' => array('name' => 'D1')),
		array('op' => 'create', 'type' => 'sample', 'id' => 'S1', 'parentType' => 'dataset', 'parentId' => 'D1', 'body' => array('name' => 'S1', 'sampleID' => 'S1')),
		array('op' => 'create', 'type' => 'sample', 'id' => 'S2', 'parentType' => 'dataset', 'parentId' => 'D1', 'body' => array('name' => 'S2', 'sampleID' => 'S2')),
		array('op' => 'create', 'type' => 'micrograph', 'id' => 'M1', 'parentType' => 'sample', 'parentId' => 'S1',
			'body' => array('name' => 'M1', 'scale' => 1.0, 'mineralogy' => array('notes' => 'n0', 'minerals' => array()))),
		array('op' => 'create', 'type' => 'spot', 'id' => 'P1', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('name' => 'P1')),
		array('op' => 'create', 'type' => 'point_count', 'id' => 'PC1', 'parentType' => 'micrograph', 'parentId' => 'M1',
			'body' => array('micrographId' => 'M1', 'points' => array(array('x' => 1, 'y' => 2)))),
	));
	check('creates accepted', allAccepted($s), implode(',', $s));
	$shaA = upload_blob($PID, $OWN, $imgA, 'image');
	$shaB = upload_blob($PID, $OWN, $imgB, 'image');
	$shaT = upload_blob($PID, $OWN, 'attachment text', 'associated_file');
	check('image A ref', set_ref($PID, $OWN, 'micrograph', 'M1', 'image', $shaA)['code'] === 200);
	check('ready', req('POST', "/projects/$PID/ready", $OWN)['code'] === 200);
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['editor']['email'], 'role' => 'editor'));
	check('editor joins', req('POST', "/invites/$PID/accept", $EDT)['code'] === 200);
	req('POST', "/projects/$PID/members", $OWN, array('email' => $U['dan']['email'], 'role' => 'viewer'));
	check('viewer joins', req('POST', "/invites/$PID/accept", $DAN)['code'] === 200);

	$steps = array(); // name => [seq, picture of the store then, unix time]
	$mark = function ($name) use (&$steps, $PID, $SID, $MS) {
		$steps[$name] = array('seq' => lastSeq($PID), 'pic' => picture(MsWorker::assemble($MS, $PID, $SID)), 'time' => time());
	};
	$mark('start');
	sleep(2);

	section('Part 2 steps');
	check('1: rename spot + mineralogy note', allAccepted(push($PID, $OWN, array(
		upd($PID, 'spot', 'P1', array('fields' => array('name' => 'P1 renamed'))),
		upd($PID, 'micrograph', 'M1', array('fields' => array('mineralogy.notes' => 'n1')))))));
	$mark('renamed');
	sleep(2);
	check('2: image replaced by B', set_ref($PID, $OWN, 'micrograph', 'M1', 'image', $shaB)['code'] === 200);
	check('2: attachment on the spot', set_ref($PID, $OWN, 'spot', 'P1', 'associated_file:a.txt', $shaT)['code'] === 200);
	$mark('files');
	sleep(2);
	check('3: spot deleted', allAccepted(push($PID, $OWN, array(
		array('op' => 'delete', 'type' => 'spot', 'id' => 'P1', 'baseVersion' => version($PID, 'spot', 'P1'))))));
	$mark('deleted');
	sleep(2);
	check('4: new spot + micrograph moved to S2', allAccepted(push($PID, $OWN, array(
		array('op' => 'create', 'type' => 'spot', 'id' => 'P2', 'parentType' => 'micrograph', 'parentId' => 'M1', 'body' => array('name' => 'P2')),
		upd($PID, 'micrograph', 'M1', array('parentType' => 'sample', 'parentId' => 'S2'))))));
	$mark('moved');
	sleep(2);
	check('5: spot restored', allAccepted(push($PID, $OWN, array(array('op' => 'restore', 'type' => 'spot', 'id' => 'P1')))));
	$mark('restored');
	sleep(2);
	check('6: the editor renames a sample', allAccepted(push($PID, $EDT, array(
		upd($PID, 'sample', 'S1', array('fields' => array('name' => 'S1 by editor')))))));
	$mark('editor');

	section('Rebuilt at each step = the store then');
	foreach ($steps as $name => $st) {
		check("as of '$name' (seq {$st['seq']})", picture(MsWorker::assemble($MS, $PID, $SID, $st['seq'])) === $st['pic']);
	}
	check('steps really differ', count(array_unique(array_map(function ($s) { return json_encode($s['pic']); }, $steps))) === count($steps));
	$firstSeq = MsHistory::range($MS, $PID)['firstSeq'];
	check('before the first change: no project', MsWorker::assemble($MS, $PID, $SID, $firstSeq - 1) === null);
	check('a later seq of another project changes nothing', picture(MsWorker::assemble($MS, $PID, $SID, $steps['editor']['seq'] + 1000000)) === $steps['editor']['pic']);
	check('seqAt(time of a step) = that step', MsHistory::seqAt($MS, $PID, $steps['deleted']['time']) === $steps['deleted']['seq']);
	check('timeOf(seq) within the step', abs(MsHistory::timeOf($MS, $PID, $steps['moved']['seq']) - $steps['moved']['time']) <= 1);

	section('Download as of: who');
	$q = "/projects/$PID/smz?seq={$steps['files']['seq']}&tz=America/Chicago";
	$own = req('GET', $q, $OWN);
	check('owner 200', $own['code'] === 200, $own['code']);
	check('editor 200', req('GET', $q, $EDT)['code'] === 200);
	check('viewer 200', req('GET', $q, $DAN)['code'] === 200);
	check('outsider 404', req('GET', $q, $OUT)['code'] === 404);

	section('Download as of: what (after step 2: image B, attachment, renamed spot)');
	$z = openSmz($own['raw']);
	check('valid zip, one root folder', $z !== null && $z['root'] !== null, $z === null ? 'not a zip' : implode(',', $z['roots']));
	check('root = a NEW uuid, not the straboId', $z['root'] !== $SID && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $z['root']) === 1, $z['root']);
	check('project.json id = root', is_object($z['project']) && $z['project']->id === $z['root']);
	$expectName = MsHistory::asOfName('History test', MsHistory::timeOf($MS, $PID, $steps['files']['seq']), 'America/Chicago');
	check('name "History test (as of <Chicago time>)"', $z['project']->name === $expectName
		&& preg_match('/^History test \(as of [A-Z][a-z]{2} \d{1,2}, \d{4} \d{1,2}:\d{2} (AM|PM)\)$/', $z['project']->name) === 1, $z['project']->name);
	check('image = B (the image then)', $z['za']->getFromName($z['root'] . '/images/M1') === $imgB);
	check('attachment present', $z['za']->getFromName($z['root'] . '/associatedFiles/a.txt') === 'attachment text');
	check('spot as renamed', spotNames($z['project']) === array('P1' => 'P1 renamed'), json_encode(spotNames($z['project'])));
	check('point count file', is_object(json_decode((string)$z['za']->getFromName($z['root'] . '/point-counts/PC1.json'))));
	$own2 = openSmz(req('GET', $q, $OWN)['raw']);
	check('each download gets its own id', $own2['root'] !== $z['root']);
	$stamp = MsHistory::asOfStamp(MsHistory::timeOf($MS, $PID, $steps['files']['seq']), 'America/Chicago');
	check('file name: the time in the same zone', strpos($own['headers']['content-disposition'] ?? '', "history_test_as_of_$stamp.smz") !== false
		&& $stamp !== MsHistory::asOfStamp(MsHistory::timeOf($MS, $PID, $steps['files']['seq']), ''),
		$own['headers']['content-disposition'] ?? '');

	section('Download as of: other points');
	$z1 = openSmz(req('GET', "/projects/$PID/smz?seq={$steps['renamed']['seq']}", $OWN)['raw']);
	check('after step 1: image A, no attachment', $z1['za']->getFromName($z1['root'] . '/images/M1') === $imgA
		&& $z1['za']->getFromName($z1['root'] . '/associatedFiles/a.txt') === false);
	check('no tz: name says UTC', substr($z1['project']->name, -5) === ' UTC)', $z1['project']->name);
	$z3 = openSmz(req('GET', "/projects/$PID/smz?seq={$steps['deleted']['seq']}", $OWN)['raw']);
	check('after step 3: the deleted spot is gone', spotNames($z3['project']) === array());
	$z5 = openSmz(req('GET', "/projects/$PID/smz?seq=" . ($steps['restored']['seq'] + 1) . "&tz=Nope/Zone", $OWN)['raw']);
	check('after the restore, bad tz falls back to UTC', spotNames($z5['project']) === array('P1' => 'P1 renamed', 'P2' => 'P2')
		&& substr($z5['project']->name, -5) === ' UTC)');
	$beforeStep3 = $steps['files']['seq'] + 1; // the first change of step 3 is the delete
	$zb = openSmz(req('GET', "/projects/$PID/smz?seq=" . ($beforeStep3 - 1), $OWN)['raw']);
	check('"before this change" (seq - 1) = the step before', spotNames($zb['project']) === array('P1' => 'P1 renamed'));
	$iso = gmdate('Y-m-d\TH:i:s\Z', $steps['deleted']['time']);
	$za = openSmz(req('GET', "/projects/$PID/smz?at=$iso", $OWN)['raw']);
	check('at=ISO = the step at that time', $za !== null && spotNames($za['project']) === array());
	$zu = openSmz(req('GET', "/projects/$PID/smz?at=" . $steps['renamed']['time'], $OWN)['raw']);
	check('at=unix seconds', $zu !== null && $zu['za']->getFromName($zu['root'] . '/images/M1') === $imgA);
	$cur = json_decode(MsWorker::assemble($MS, $PID, $SID)['json']);
	$zn = openSmz(req('GET', "/projects/$PID/smz?at=" . (time() + 5), $OWN)['raw']);
	$cur->id = $zn['project']->id;
	$cur->name = $zn['project']->name;
	check('at=now = the project today (id and name aside)', json_encode($zn['project']) === json_encode($cur));
	$plain = openSmz(req('GET', "/projects/$PID/smz", $OWN)['raw']);
	check('no seq/at: the plain download, root = straboId, own name', $plain['root'] === $SID && $plain['project']->name === 'History test');

	section('Download as of: refused');
	$r = req('GET', "/projects/$PID/smz?seq=" . ($firstSeq - 1), $OWN);
	check('before sync was on -> 409 before_history with historyStart', $r['code'] === 409 && $r['body']['error'] === 'before_history'
		&& is_string($r['body']['historyStart']), $r['raw'] ?? '');
	$r = req('GET', "/projects/$PID/smz?at=2001-01-01T00:00:00Z", $OWN);
	check('at before sync -> 409 before_history', $r['code'] === 409 && $r['body']['error'] === 'before_history');
	check('seq past the last change -> 400', req('GET', "/projects/$PID/smz?seq=999999999", $OWN)['code'] === 400);
	check('bad at -> 400', req('GET', "/projects/$PID/smz?at=yesterday", $OWN)['code'] === 400);
	check('bad seq -> 400', req('GET', "/projects/$PID/smz?seq=abc", $OWN)['code'] === 400);

	section('History list (brief)');
	$h = req('GET', "/projects/$PID/history?brief=1", $OWN);
	check('200 with historyStart', $h['code'] === 200 && is_string($h['body']['historyStart']));
	check('no fields without detail', !array_key_exists('fields', $h['body']['changes'][0]));
	$all = $h['body']['changes'];
	check('newest first', $all[0]['seq'] > $all[count($all) - 1]['seq']);
	$hd = req('GET', "/projects/$PID/history?brief=1&detail=1", $OWN)['body']['changes'];
	$byKey = function ($rows, $type, $id, $op) {
		return array_values(array_filter($rows, function ($c) use ($type, $id, $op) {
			return $c['type'] === $type && $c['id'] === $id && $c['op'] === $op;
		}));
	};
	$ren = array_values(array_filter($byKey($hd, 'spot', 'P1', 'update'), function ($c) { return $c['changedPaths'] === array('name'); }));
	check('rename row: name P1 -> P1 renamed', count($ren) === 1
		&& $ren[0]['fields'] === array(array('path' => 'name', 'before' => 'P1', 'after' => 'P1 renamed')), json_encode($ren));
	$min = array_values(array_filter($byKey($hd, 'micrograph', 'M1', 'update'), function ($c) { return $c['changedPaths'] === array('mineralogy.notes'); }));
	check('nested field: mineralogy.notes n0 -> n1', count($min) === 1
		&& $min[0]['fields'] === array(array('path' => 'mineralogy.notes', 'before' => 'n0', 'after' => 'n1')), json_encode($min));
	$img = array_values(array_filter($byKey($hd, 'micrograph', 'M1', 'update'), function ($c) { return $c['changedPaths'] === array('refs.image'); }));
	check('file rows: image replaced, then first added', count($img) === 2
		&& $img[0]['fields'] === array(array('path' => 'refs.image', 'file' => true, 'change' => 'replaced'))
		&& $img[1]['fields'] === array(array('path' => 'refs.image', 'file' => true, 'change' => 'added')), json_encode($img));
	$mv = array_values(array_filter($byKey($hd, 'micrograph', 'M1', 'update'), function ($c) { return $c['movedFrom'] === 'S1'; }));
	check('move row: parentId S1 -> S2', count($mv) === 1 && in_array(array('path' => 'parentId', 'before' => 'S1', 'after' => 'S2'), $mv[0]['fields'], true), json_encode($mv));
	check('create/delete rows: empty fields', $byKey($hd, 'spot', 'P1', 'delete')[0]['fields'] === array());
	check('a long value is cut to 300 chars + ...', MsHistory::shortText(str_repeat('x', 400)) === str_repeat('x', 300) . '...'
		&& MsHistory::shortText(str_repeat('é', 300)) === str_repeat('é', 300) && MsHistory::shortText(array('a' => 1)) === '{"a":1}'
		&& MsHistory::shortText(false) === 'false' && MsHistory::shortText(2.5) === '2.5');

	$ed = req('GET', "/projects/$PID/history?brief=1&user=" . $U['editor']['pkey'], $OWN)['body']['changes'];
	check('user=editor: only the editor\'s change', count($ed) === 1 && $ed[0]['id'] === 'S1' && $ed[0]['user']['pkey'] === $U['editor']['pkey'], json_encode($ed));
	$ow = req('GET', "/projects/$PID/history?brief=1&user=" . $U['owner']['pkey'], $OWN)['body']['changes'];
	check('user=owner: everything but the editor\'s', count($ow) === count($all) - 1);
	$un = req('GET', "/projects/$PID/history?brief=1&until=" . gmdate('Y-m-d\TH:i:s\Z', $steps['renamed']['time']), $OWN)['body']['changes'];
	check('until=step 1: newest row is step 1\'s', count($un) > 0 && $un[0]['seq'] <= $steps['renamed']['seq'] && $un[0]['seq'] > $steps['start']['seq'], json_encode(array_column($un, 'seq')));
	check('bad until -> 400', req('GET', "/projects/$PID/history?brief=1&until=soon", $OWN)['code'] === 400);
	check('viewer may read the history', req('GET', "/projects/$PID/history?brief=1", $DAN)['code'] === 200);
	check('outsider -> 404', req('GET', "/projects/$PID/history?brief=1", $OUT)['code'] === 404);

	section('Removed member');
	req('DELETE', "/projects/$PID/members/" . $U['dan']['pkey'], $OWN);
	$r = req('GET', $q, $DAN);
	check('removed viewer -> 403 access_removed', $r['code'] === 403 && $r['body']['error'] === 'access_removed', $r['code']);
} catch (Throwable $e) {
	check('no exception', false, $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

cleanup();
exec('rm -rf ' . escapeshellarg($TMP));
echo "\n" . (empty($failures) ? 'ALL PASS' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
