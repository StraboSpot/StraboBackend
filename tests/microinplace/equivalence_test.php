<?php
/**
 * File: equivalence_test.php
 * Description: Proves that the in-place re-upload path in jwtmicrodb
 *              (StraboMicro::replaceProjectInPlace, MICRO_INPLACE_REBUILD)
 *              produces the same database rows and files as the old
 *              delete-and-recreate path, apart from keeping the project id.
 *
 *              For each fixture, the same sequence runs through BOTH paths
 *              (each operation in its own process, see upload_once.php):
 *                1. upload        2. re-upload (same file)
 *                3. re-upload a modified file (a micrograph removed, notes changed)
 *                4. delete (always the old deleteProject)
 *              After steps 2 and 3 it snapshots every strabomicro table (diffed
 *              against a baseline), the test user's strabosamples and
 *              strabosearch rows, and the project's files, and requires the two
 *              paths to match. Surrogate integer ids, timestamps, and the
 *              random sharekey are ignored; the project id is masked inside
 *              strings. Step 4 must restore the baseline exactly.
 *
 *              Extra checks on the first fixture:
 *                - the new path keeps the project id and the sharekey;
 *                - an invalid re-upload leaves the project intact (new path);
 *                - when a statement fails mid-upload (forced by a trigger), the new
 *                  path ends in exactly the state the old path produces.
 *
 *              Fixtures: the real .smz uploads on dev (straboMicroFiles/<id>/project.zip)
 *              plus project.json-only uploads built from micro_projectmetadata.projectjson.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/microinplace/equivalence_test.php [--json=N|--json=all] [--no-real]
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

$jsonCount = 25;
$useReal = true;
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('/^--json=(all|\d+)$/', $arg, $m)) { $jsonCount = ($m[1] === 'all') ? PHP_INT_MAX : (int)$m[1]; }
	elseif ($arg === '--no-real') { $useReal = false; }
}

$W = '/tmp/microinplace';
@mkdir($W, 0777, true);
$failures = array();

function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        $detail" : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}

// ---------------------------------------------------------------------------
// Child process runner
// ---------------------------------------------------------------------------
function run_child($mode, $user, $zip, $sid) {
	$cmd = 'php ' . escapeshellarg(__DIR__ . '/upload_once.php') . ' ' . escapeshellarg($mode) . ' '
		. (int)$user . ' ' . escapeshellarg($zip) . ' ' . escapeshellarg($sid) . ' 2>/dev/null';
	$out = array();
	exec($cmd, $out, $rc);
	$last = count($out) ? $out[count($out) - 1] : '';
	$j = json_decode($last, true);
	if (!is_array($j)) {
		return array('result' => array('harness_error' => "exit $rc: " . implode(' | ', array_slice($out, -3))), 'pid' => null);
	}
	return $j;
}

// ---------------------------------------------------------------------------
// Snapshots
// ---------------------------------------------------------------------------
function snapshot_tables($db) {
	static $tables = null;
	if ($tables !== null) return $tables;
	$rows = $db->get_results_prepared(
		"SELECT table_schema, table_name, column_name, data_type
		   FROM information_schema.columns
		  WHERE table_schema IN ('strabomicro','strabosamples','strabosearch')
		  ORDER BY table_schema, table_name, ordinal_position", array());
	$tables = array();
	foreach ($rows as $r) {
		$key = $r->table_schema . '.' . $r->table_name;
		$tables[$key][$r->column_name] = $r->data_type;
	}
	// strabosamples / strabosearch: only tables scoped by a user column (the
	// test user's rows); strabomicro: whole tables, diffed against a baseline.
	foreach ($tables as $key => $cols) {
		if (strpos($key, 'strabomicro.') === 0) continue;
		$userCols = array_values(array_filter(array_keys($cols), function ($c) { return preg_match('/userpkey$/', $c); }));
		if (count($userCols) === 0) { unset($tables[$key]); continue; }
	}
	return $tables;
}

function is_volatile($col, $type) {
	$n = strtolower($col);
	if (in_array($type, array('timestamp with time zone', 'timestamp without time zone', 'date'), true)) return true;
	if ($n === 'sharekey') return true;
	if (strpos($n, 'user') !== false) return false;
	if (in_array($type, array('integer', 'bigint', 'smallint'), true)
		&& ($n === 'id' || $n === 'pkey' || preg_match('/(_id|pkey)$/', $n))) return true;
	return false;
}

/** Drop integer surrogate ids nested inside JSON values (e.g. micro_data.dataset_id). */
function drop_nested_ids(&$a) {
	if (!is_array($a)) return;
	foreach ($a as $k => &$v) {
		if (is_string($k) && is_int($v) && strpos(strtolower($k), 'user') === false
			&& ($k === 'id' || $k === 'pkey' || preg_match('/(_id|pkey)$/i', $k))) {
			unset($a[$k]);
			continue;
		}
		drop_nested_ids($v);
	}
}

function ksort_recursive(&$a) {
	if (!is_array($a)) return;
	ksort($a);
	foreach ($a as &$v) ksort_recursive($v);
}

function snapshot_rows($db, $user, $maskPids) {
	$out = array();
	foreach (snapshot_tables($db) as $table => $cols) {
		$where = '';
		if (strpos($table, 'strabomicro.') !== 0) {
			$userCols = array_values(array_filter(array_keys($cols), function ($c) { return preg_match('/userpkey$/', $c); }));
			$where = ' WHERE ' . implode(' OR ', array_map(function ($c) use ($user) { return "t.\"$c\" = " . (int)$user; }, $userCols));
		}
		$res = pg_query($db->dbh, "SELECT row_to_json(t) AS j FROM $table t$where");
		$lines = array();
		while ($r = pg_fetch_assoc($res)) {
			$row = json_decode($r['j'], true);
			foreach ($row as $c => $v) {
				if (isset($cols[$c]) && is_volatile($c, $cols[$c])) unset($row[$c]);
			}
			drop_nested_ids($row);
			ksort_recursive($row);
			$line = json_encode($row);
			foreach ($maskPids as $pid) {
				// Only where the id is a path or query component (URLs such as
				// /straboMicroFiles/<id>/ or ?p=<id>), never inside numbers.
				// Also a string that IS the id (sample_subsystem_links.reference_id).
				if ($pid) {
					$line = preg_replace('#(?<=[/=])' . (int)$pid . '(?=[/"&?\\\\]|$)#', '<PID>', $line);
					$line = str_replace('"' . (int)$pid . '"', '"<PID>"', $line);
				}
			}
			$lines[] = $line;
		}
		sort($lines);
		$out[$table] = $lines;
	}
	return $out;
}

/** Multiset difference per table: rows in $after not in $base. */
function rows_minus($after, $base) {
	$diff = array();
	foreach ($after as $table => $lines) {
		$counts = array();
		foreach (isset($base[$table]) ? $base[$table] : array() as $l) { $counts[$l] = (isset($counts[$l]) ? $counts[$l] : 0) + 1; }
		$extra = array();
		foreach ($lines as $l) {
			if (!empty($counts[$l])) { $counts[$l]--; } else { $extra[] = $l; }
		}
		if (count($extra)) $diff[$table] = $extra;
	}
	return $diff;
}

function snapshot_files($pid) {
	$dir = "/srv/app/www/straboMicroFiles/$pid";
	if (!$pid || !is_dir($dir)) return array();
	$files = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		if ($f->isFile()) $files[substr($f->getPathname(), strlen($dir) + 1)] = md5_file($f->getPathname());
	}
	ksort($files);
	return $files;
}

function first_difference($a, $b) {
	foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
		$va = isset($a[$k]) ? $a[$k] : null;
		$vb = isset($b[$k]) ? $b[$k] : null;
		if ($va !== $vb) {
			$sa = is_array($va) ? json_encode(array_values(array_diff($va, (array)$vb))) : json_encode($va);
			$sb = is_array($vb) ? json_encode(array_values(array_diff($vb, (array)$va))) : json_encode($vb);
			return "$k: old=" . substr($sa, 0, 300) . " new=" . substr($sb, 0, 300);
		}
	}
	return '';
}

function sharekey($db, $user, $sid) {
	return $db->get_var_prepared("SELECT sharekey FROM micro_projectmetadata WHERE userpkey=$1 AND strabo_id=$2", array($user, $sid));
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
function zip_json_path($zipPath, $sid) {
	$za = new ZipArchive();
	$za->open($zipPath);
	for ($i = 0; $i < $za->numFiles; $i++) {
		$n = $za->getNameIndex($i);
		if ($n === "$sid/project.json" || $n === 'project.json') { $za->close(); return $n; }
	}
	$za->close();
	return null;
}

function modify_project($json) {
	$j = json_decode($json);
	$j->notes = (isset($j->notes) ? $j->notes : '') . ' [in-place variant]';
	foreach ((isset($j->datasets) ? $j->datasets : array()) as $d) {
		foreach ((isset($d->samples) ? $d->samples : array()) as $s) {
			if (isset($s->micrographs) && is_array($s->micrographs) && count($s->micrographs) > 0) {
				array_pop($s->micrographs);
				return json_encode($j);
			}
		}
	}
	return json_encode($j);
}

function make_variant($srcZip, $sid, $dstZip, $transform) {
	copy($srcZip, $dstZip);
	$entry = zip_json_path($dstZip, $sid);
	$za = new ZipArchive();
	$za->open($dstZip);
	$za->addFromString($entry, $transform($za->getFromName($entry)));
	$za->close();
}

function make_json_zip($json, $sid, $dstZip) {
	@unlink($dstZip);
	$za = new ZipArchive();
	$za->open($dstZip, ZipArchive::CREATE);
	$za->addFromString("$sid/project.json", $json);
	$za->close();
}

$fixtures = array();
if ($useReal) {
	foreach (array(787, 775, 776, 773) as $id) {
		$zip = "/srv/app/www/straboMicroFiles/$id/project.zip";
		$sid = $db->get_var_prepared("SELECT strabo_id FROM micro_projectmetadata WHERE id=$1", array($id));
		if (!file_exists($zip) || !$sid || !zip_json_path($zip, $sid)) continue;
		$base = "$W/real_$id.zip";
		copy($zip, $base);
		make_variant($base, $sid, "$W/real_{$id}_v.zip", 'modify_project');
		$fixtures[] = array('name' => "real .smz #$id", 'sid' => $sid, 'zip' => $base, 'variant' => "$W/real_{$id}_v.zip");
	}
}
if ($jsonCount > 0) {
	$rows = $db->get_results_prepared(
		"SELECT id, strabo_id, length(projectjson) AS len FROM micro_projectmetadata
		  WHERE projectjson IS NOT NULL AND strabo_id IS NOT NULL AND strabo_id <> ''
		  ORDER BY length(projectjson), id", array());
	$pick = array();
	$n = count($rows);
	if ($jsonCount >= $n) { $pick = range(0, $n - 1); }
	else { for ($i = 0; $i < $jsonCount; $i++) $pick[] = (int)round($i * ($n - 1) / max(1, $jsonCount - 1)); }
	// Always include the projects with duplicate micrograph ids.
	foreach ($rows as $i => $r) { if (in_array((int)$r->id, array(454, 471), true)) $pick[] = $i; }
	$seen = array();
	foreach (array_unique($pick) as $i) {
		$r = $rows[$i];
		if (isset($seen[$r->strabo_id])) continue;
		$seen[$r->strabo_id] = true;
		$json = $db->get_var_prepared("SELECT projectjson FROM micro_projectmetadata WHERE id=$1", array($r->id));
		$base = "$W/json_{$r->id}.zip";
		make_json_zip($json, $r->strabo_id, $base);
		make_json_zip(modify_project($json), $r->strabo_id, "$W/json_{$r->id}_v.zip");
		$fixtures[] = array('name' => "project.json #{$r->id} ({$r->len} bytes)", 'sid' => $r->strabo_id,
			'zip' => $base, 'variant' => "$W/json_{$r->id}_v.zip");
	}
}

// ---------------------------------------------------------------------------
// Sequence
// ---------------------------------------------------------------------------
function run_sequence($db, $mode, $user, $fx, $baseline) {
	$s = array();
	$r1 = run_child($mode, $user, $fx['zip'], $fx['sid']);
	$k1 = sharekey($db, $user, $fx['sid']);
	$r2 = run_child($mode, $user, $fx['zip'], $fx['sid']);
	$s['k2'] = sharekey($db, $user, $fx['sid']);
	$s['k1'] = $k1;
	$s['rowsA']  = rows_minus(snapshot_rows($db, $user, array($r2['pid'])), $baseline);
	$s['filesA'] = snapshot_files($r2['pid']);
	$r3 = run_child($mode, $user, $fx['variant'], $fx['sid']);
	$s['rowsB']  = rows_minus(snapshot_rows($db, $user, array($r3['pid'])), $baseline);
	$s['filesB'] = snapshot_files($r3['pid']);
	run_child('delete', $user, '-', $fx['sid']);
	$s['leftover'] = rows_minus(snapshot_rows($db, $user, array()), $baseline);
	$s['results'] = array($r1['result'], $r2['result'], $r3['result']);
	$s['pids'] = array($r1['pid'], $r2['pid'], $r3['pid']);
	return $s;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
$email = 'microinplace-' . time() . '@test.strabospot.org';
$res = pg_query_params($db->dbh,
	"INSERT INTO users (firstname, lastname, email, password, hash, active) VALUES ('Inplace', 'Harness', $1, 'x', 'x', true) RETURNING pkey",
	array($email));
$user = (int)pg_fetch_result($res, 0, 0);
echo "Test user $user ($email); " . count($fixtures) . " fixtures\n";

$triggerSql = "
	CREATE OR REPLACE FUNCTION strabomicro.microinplace_fail() RETURNS trigger AS \$\$
	BEGIN
		IF NEW.name = '__FAIL_INPLACE__' THEN RAISE EXCEPTION 'microinplace forced failure'; END IF;
		RETURN NEW;
	END \$\$ LANGUAGE plpgsql;
	DROP TRIGGER IF EXISTS microinplace_fail ON strabomicro.micro_datasetmetadata;
	CREATE TRIGGER microinplace_fail BEFORE INSERT ON strabomicro.micro_datasetmetadata
		FOR EACH ROW EXECUTE PROCEDURE strabomicro.microinplace_fail();";
$dropTriggerSql = "
	DROP TRIGGER IF EXISTS microinplace_fail ON strabomicro.micro_datasetmetadata;
	DROP FUNCTION IF EXISTS strabomicro.microinplace_fail();";

try {
	$baseline = snapshot_rows($db, $user, array());

	foreach ($fixtures as $idx => $fx) {
		echo "\n=== {$fx['name']} ===\n";
		$old = run_sequence($db, 'old', $user, $fx, $baseline);
		$new = run_sequence($db, 'new', $user, $fx, $baseline);

		check('responses identical (upload, re-upload, modified re-upload)',
			json_encode($old['results']) === json_encode($new['results']),
			json_encode($old['results']) . ' vs ' . json_encode($new['results']));
		check('rows identical after re-upload', $old['rowsA'] == $new['rowsA'], first_difference($old['rowsA'], $new['rowsA']));
		check('rows identical after modified re-upload', $old['rowsB'] == $new['rowsB'], first_difference($old['rowsB'], $new['rowsB']));
		check('files identical after re-upload', $old['filesA'] == $new['filesA'], first_difference($old['filesA'], $new['filesA']));
		check('files identical after modified re-upload', $old['filesB'] == $new['filesB'], first_difference($old['filesB'], $new['filesB']));
		check('new path keeps the project id', $new['pids'][0] !== null && $new['pids'][0] === $new['pids'][1] && $new['pids'][1] === $new['pids'][2],
			json_encode($new['pids']));
		check('sharekey kept on re-upload (both paths)', $old['k1'] === $old['k2'] && $new['k1'] === $new['k2']);
		check('delete restores the baseline (both paths)', count($old['leftover']) === 0 && count($new['leftover']) === 0,
			json_encode(array_keys($old['leftover'] + $new['leftover'])));
		echo "        (old path ids " . json_encode($old['pids']) . ", new path ids " . json_encode($new['pids']) . ")\n";

		if ($idx !== 0) continue;

		// Invalid re-upload: the new path must leave the project intact.
		echo "--- invalid re-upload ({$fx['name']}) ---\n";
		$bad = "$W/invalid.zip";
		make_json_zip('{"not":"a project"}', $fx['sid'], $bad);
		$u = run_child('new', $user, $fx['zip'], $fx['sid']);
		$before = rows_minus(snapshot_rows($db, $user, array($u['pid'])), $baseline);
		$filesBefore = snapshot_files($u['pid']);
		$r = run_child('new', $user, $bad, $fx['sid']);
		$after = rows_minus(snapshot_rows($db, $user, array($r['pid'])), $baseline);
		check('invalid file returns the old error message', isset($r['result']['Error']) && $r['result']['Error'] === 'Invalid file detectedd.', json_encode($r['result']));
		check('invalid file leaves rows untouched', $before == $after && $u['pid'] === $r['pid'], first_difference($before, $after));
		check('invalid file leaves files untouched', $filesBefore == snapshot_files($r['pid']));
		run_child('delete', $user, '-', $fx['sid']);

		// A statement failing mid-upload: the new path must end exactly like the old path.
		echo "--- failing statement mid-upload ({$fx['name']}) ---\n";
		$failZip = "$W/fail.zip";
		make_variant($fx['zip'], $fx['sid'], $failZip, function ($json) {
			$j = json_decode($json);
			if (isset($j->datasets[0])) $j->datasets[0]->name = '__FAIL_INPLACE__';
			return json_encode($j);
		});
		pg_query($db->dbh, $triggerSql);
		$states = array();
		foreach (array('old', 'new') as $mode) {
			run_child($mode, $user, $fx['zip'], $fx['sid']);
			$r = run_child($mode, $user, $failZip, $fx['sid']);
			$states[$mode] = array(
				'result' => $r['result'],
				'rows'   => rows_minus(snapshot_rows($db, $user, array($r['pid'])), $baseline),
				'files'  => snapshot_files($r['pid']),
			);
			run_child('delete', $user, '-', $fx['sid']);
		}
		pg_query($db->dbh, $dropTriggerSql);
		check('failing statement: same response as old path', json_encode($states['old']['result']) === json_encode($states['new']['result']));
		check('failing statement: same rows as old path', $states['old']['rows'] == $states['new']['rows'], first_difference($states['old']['rows'], $states['new']['rows']));
		check('failing statement: same files as old path', $states['old']['files'] == $states['new']['files'], first_difference($states['old']['files'], $states['new']['files']));
	}
} finally {
	pg_query($db->dbh, $dropTriggerSql);
	foreach ($fixtures as $fx) { run_child('delete', $user, '-', $fx['sid']); }
	pg_query_params($db->dbh, "DELETE FROM strabosamples.sample_changelog WHERE sample_userpkey = $1", array($user));
	pg_query_params($db->dbh, "DELETE FROM users WHERE pkey = $1", array($user));
	foreach (glob('/srv/app/www/straboMicroFiles/_staging_*') ?: array() as $d) exec('rm -rf ' . escapeshellarg($d));
	foreach (glob('/srv/app/www/straboMicroFiles/_trash_*') ?: array() as $d) exec('rm -rf ' . escapeshellarg($d));
	exec('rm -rf ' . escapeshellarg($W));
}

echo "\n" . (count($failures) === 0 ? "ALL PASS" : count($failures) . " FAILURE(S)") . "\n";
exit(count($failures) === 0 ? 0 : 1);
