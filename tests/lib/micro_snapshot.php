<?php
/**
 * File: micro_snapshot.php
 * Description: Database and file snapshots for comparing two ways of
 *              producing the same StraboMicro project (strabomicro tables,
 *              the user's strabosamples and strabosearch rows, files).
 *              Shared by tests/microinplace/equivalence_test.php and
 *              tests/microsync/worker_test.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

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

/**
 * Rows of every snapshot table, normalized for comparison (surrogate ids,
 * timestamps, sharekey dropped; project ids masked in strings).
 * $opts (all optional, used by tests/microsync/worker_test.php):
 *   skipTables    table names ("schema.table") to leave out
 *   skipColumns   "schema.table.column" names to drop
 *   canonicalJson decode string values holding JSON objects/arrays and
 *                 compare them with sorted keys and nested ids dropped
 */
function snapshot_rows($db, $user, $maskPids, $opts = array()) {
	$skipTables = isset($opts['skipTables']) ? $opts['skipTables'] : array();
	$skipColumns = isset($opts['skipColumns']) ? $opts['skipColumns'] : array();
	$canonicalJson = !empty($opts['canonicalJson']);
	$out = array();
	foreach (snapshot_tables($db) as $table => $cols) {
		if (in_array($table, $skipTables, true)) continue;
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
				if (isset($cols[$c]) && is_volatile($c, $cols[$c])) { unset($row[$c]); continue; }
				if (in_array("$table.$c", $skipColumns, true)) { unset($row[$c]); continue; }
				if ($canonicalJson && is_string($v) && $v !== '' && ($v[0] === '{' || $v[0] === '[')) {
					$d = json_decode($v, true);
					if (is_array($d)) { drop_nested_ids($d); ksort_recursive($d); $row[$c] = $d; }
				}
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
