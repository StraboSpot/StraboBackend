<?php
/**
 * File: reorder_keys.php
 * Description: One-off repair for projects converted while entity bodies were
 *              stored as jsonb (which sorts object keys). Puts the keys of
 *              every entity body and change-log state back in the order the
 *              app wrote them, taken from the project's archived pre-conversion
 *              project.zip (project.json + point-counts/). Content never
 *              changes, only key order; every rewritten value is checked equal
 *              to the old one by Postgres (jsonb comparison) before it is kept.
 *              Design: StraboMicro2 repo, docs/specs/collaboration-phase0-design.md
 *              §4.9 (P1-2). Needs sql/microsync_phase1_json.sql applied first.
 *
 *              Default is a DRY RUN (report only).
 *
 *              Usage (as www-data inside strabo-php):
 *                php microsync/tools/reorder_keys.php --only=869,786
 *                php microsync/tools/reorder_keys.php --only=869,786 --apply
 *                php microsync/tools/reorder_keys.php --revert=<run folder name> [--only=...]
 *
 *              --apply first writes, per project, a full backup of the
 *              columns it may touch (backup-<id>.json: every entity body and
 *              child_order, every change-log before/after, as stored text)
 *              and the planned rewrites (changed-<id>.json: old and new text),
 *              re-reads both, and only then writes, in one transaction per
 *              project under the project's push lock. Files go to
 *              microsync_data/reorder_keys/<UTC time>/ (small).
 *              --revert puts the old text back from changed-<id>.json, all or
 *              nothing per project, and only for rows still exactly as the
 *              tool left them.
 *              Entities that are not in the archive (created after the
 *              conversion) follow the key order of their type in the archive,
 *              or, when the archive has no entity of that type, the order of
 *              that type in the archives of the other --only projects (all
 *              written by the same app); keys no archive has keep their
 *              current order, at the end.
 *              Both --apply and --revert mark the project's views dirty, so the
 *              worker rebuilds project.json and the PDF.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit();
}
set_time_limit(0);
chdir(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);

include_once "../../includes/config.inc.php";
include_once "../../db.php";
include_once "../../jwtmicrodb/strabomicroclass.php";
include_once "../lib/MsConvert.php";

$apply = false;
$revert = null;
$only = null;
foreach (array_slice($argv, 1) as $a) {
	if ($a === '--apply') {
		$apply = true;
	} elseif (preg_match('/^--only=([0-9,]+)$/', $a, $m)) {
		$only = array_map('intval', array_filter(explode(',', $m[1]), 'strlen'));
	} elseif (preg_match('/^--revert=([0-9TZ]+(-[0-9]+)?)$/', $a, $m)) {
		$revert = $m[1];
	} else {
		fwrite(STDERR, "usage: php reorder_keys.php --only=12,34 [--apply] | --revert=<run folder> [--only=12,34]\n");
		exit(2);
	}
}
if ($revert !== null && $apply) {
	fwrite(STDERR, "--revert and --apply cannot be combined\n");
	exit(2);
}
if ($revert === null && ($only === null || count($only) === 0)) {
	fwrite(STDERR, "--only=<ids> is required\n");
	exit(2);
}

$ms = new MsDb($db);

// The rewritten text only survives in json columns; jsonb would sort it again.
$types = array();
foreach ($ms->rows(
	"SELECT table_name || '.' || column_name AS c, data_type FROM information_schema.columns
	  WHERE table_schema = 'strabomicro'
	    AND ((table_name = 'micro_entities' AND column_name = 'body')
	      OR (table_name = 'micro_changes' AND column_name IN ('before', 'after')))") as $r) {
	$types[$r['c']] = $r['data_type'];
}
foreach (array('micro_entities.body', 'micro_changes.before', 'micro_changes.after') as $c) {
	if (!isset($types[$c]) || $types[$c] !== 'json') {
		fwrite(STDERR, "strabomicro.$c is not json yet: apply sql/microsync_phase1_json.sql first\n");
		exit(1);
	}
}

if ($ms->val("SELECT pg_try_advisory_lock(hashtext('microsync-convert'), 0)") !== 't') {
	fwrite(STDERR, "a conversion or another reorder run is in progress\n");
	exit(1);
}

$runsRoot = MsWorker::dataDir() . '/reorder_keys';
$fail = 0;

// ---------------------------------------------------------------------------
// Key-order templates
// ---------------------------------------------------------------------------

/** Ordered union of two templates: keys of $a, then keys only $b has; lists element by element. */
function rk_merge($a, $b) {
	if ($a instanceof stdClass && $b instanceof stdClass) {
		$out = new stdClass();
		foreach ($a as $k => $v) {
			$out->{$k} = property_exists($b, $k) ? rk_merge($v, $b->{$k}) : $v;
		}
		foreach ($b as $k => $v) {
			if (!property_exists($out, $k)) {
				$out->{$k} = $v;
			}
		}
		return $out;
	}
	if (is_array($a) && is_array($b)) {
		$out = array();
		$n = max(count($a), count($b));
		for ($i = 0; $i < $n; $i++) {
			if (array_key_exists($i, $a) && array_key_exists($i, $b)) {
				$out[] = rk_merge($a[$i], $b[$i]);
			} else {
				$out[] = array_key_exists($i, $a) ? $a[$i] : $b[$i];
			}
		}
		return $out;
	}
	return $a !== null ? $a : $b;
}

/** $v with object keys in template order (keys the template lacks follow, in their current order). */
function rk_reorder($v, $t) {
	if ($v instanceof stdClass) {
		$out = new stdClass();
		if ($t instanceof stdClass) {
			foreach ($t as $k => $sub) {
				if (property_exists($v, $k)) {
					$out->{$k} = rk_reorder($v->{$k}, $sub);
				}
			}
		}
		foreach ($v as $k => $x) {
			if (!property_exists($out, $k)) {
				$out->{$k} = rk_reorder($x, null);
			}
		}
		return $out;
	}
	if (is_array($v)) {
		$all = null;
		if (is_array($t)) {
			foreach ($t as $e) {
				$all = $all === null ? $e : rk_merge($all, $e);
			}
		}
		$out = array();
		foreach ($v as $i => $x) {
			$out[] = rk_reorder($x, is_array($t) && array_key_exists($i, $t) ? $t[$i] : $all);
		}
		return $out;
	}
	return $v;
}

/**
 * Templates from the archived zip: array('entities' => "type:id" => template,
 * 'types' => type => merged template). Returns a string when unusable.
 */
function rk_templates($pid) {
	$zipPath = MsConvert::archiveDir($pid) . '/project.zip';
	if (!is_file($zipPath)) {
		return "no archived project.zip ($zipPath)";
	}
	$z = new ZipArchive();
	if ($z->open($zipPath) !== true) {
		return 'the archived project.zip cannot be opened';
	}
	$project = null;
	$pointCounts = array();
	for ($i = 0; $i < $z->numFiles; $i++) {
		$n = $z->getNameIndex($i);
		if (substr_count($n, '/') === 1 && substr($n, -13) === '/project.json') {
			$project = json_decode((string)$z->getFromIndex($i));
		} elseif (preg_match('#^[^/]+/point-counts/[^/]+\.json$#', $n)) {
			$pc = json_decode((string)$z->getFromIndex($i));
			if ($pc instanceof stdClass && isset($pc->id) && is_string($pc->id)) {
				$pointCounts[] = $pc;
			}
		}
	}
	$z->close();
	if (!($project instanceof stdClass) || !isset($project->id)) {
		return 'the archived zip has no usable project.json';
	}

	$out = array('entities' => array(), 'types' => array());
	$add = function ($type, $obj) use (&$out) {
		if (!isset($obj->id) || !is_string($obj->id)) {
			return;
		}
		$key = MsModel::key($type, $obj->id);
		if (!isset($out['entities'][$key])) {
			$out['entities'][$key] = $obj;
		}
		$out['types'][$type] = isset($out['types'][$type]) ? rk_merge($out['types'][$type], $obj) : $obj;
	};
	$walk = function ($type, $obj) use (&$walk, $add) {
		if (!($obj instanceof stdClass)) {
			return;
		}
		$tmpl = clone $obj;
		foreach (MsModel::$CHILD_KEYS[$type] as $childKey => $childType) {
			if (property_exists($tmpl, $childKey)) {
				if (is_array($tmpl->$childKey)) {
					foreach ($tmpl->$childKey as $child) {
						$walk($childType, $child);
					}
				}
				unset($tmpl->$childKey);
			}
		}
		$add($type, $tmpl);
	};
	$walk('project', $project);
	foreach ($pointCounts as $pc) {
		$add('point_count', $pc);
	}
	return $out;
}

/**
 * Template for one entity and where it came from: 'own' (the entity is in its
 * project's archive), 'type' (its type is), 'shared' (only another project of
 * this run has the type), 'none'.
 */
function rk_entity_template($tmpls, $type, $id, $shared) {
	$own = isset($tmpls['entities'][MsModel::key($type, $id)]) ? $tmpls['entities'][MsModel::key($type, $id)] : null;
	$common = isset($tmpls['types'][$type]) ? $tmpls['types'][$type] : null;
	if ($own !== null) {
		return array($common !== null ? rk_merge($own, $common) : $own, 'own');
	}
	if ($common !== null) {
		return array($common, 'type');
	}
	if (isset($shared[$type])) {
		return array($shared[$type], 'shared');
	}
	return array(null, 'none');
}

/** Change-log state: the order MsStore::state writes, with the body in entity order. */
function rk_state_template($bodyTemplate) {
	return (object)array('parentType' => null, 'parentId' => null, 'body' => $bodyTemplate,
		'childOrder' => null, 'refs' => null);
}

function rk_encode($v) {
	$out = json_encode($v, MsHttp::JSON_OUT);
	if ($out === false) {
		throw new Exception('cannot encode: ' . json_last_error_msg());
	}
	return $out;
}

/** New text for one stored JSON text, or null when already in order. */
function rk_rewrite($old, $template) {
	$v = json_decode($old);
	if (json_last_error() !== JSON_ERROR_NONE) {
		throw new Exception('stored text is not JSON');
	}
	$new = rk_encode(rk_reorder($v, $template));
	return $new === $old ? null : $new;
}

// ---------------------------------------------------------------------------
// Plan, back up, apply
// ---------------------------------------------------------------------------

/**
 * Every stored text this tool may touch, and the planned rewrites.
 * Rewrites: list of array(table, keyFields, column, old, new).
 */
function rk_plan($ms, $pid, $tmpls, $shared) {
	$entities = $ms->rows(
		"SELECT entity_type, entity_id, body::text AS body, child_order::text AS child_order
		   FROM strabomicro.micro_entities WHERE project_id = $1 ORDER BY entity_type, entity_id",
		array($pid));
	$changes = $ms->rows(
		"SELECT seq, entity_type, entity_id, before::text AS before, after::text AS after
		   FROM strabomicro.micro_changes WHERE project_id = $1 ORDER BY seq",
		array($pid));
	$rewrites = array();
	$stats = array('entities' => count($entities), 'entityRewrites' => 0,
		'own' => 0, 'type' => 0, 'shared' => 0, 'none' => 0,
		'changes' => count($changes), 'changeRewrites' => 0);
	foreach ($entities as $e) {
		list($t, $from) = rk_entity_template($tmpls, $e['entity_type'], $e['entity_id'], $shared);
		$stats[$from]++;
		$new = rk_rewrite($e['body'], $t);
		if ($new !== null) {
			$rewrites[] = array('table' => 'micro_entities', 'type' => $e['entity_type'], 'id' => $e['entity_id'],
				'column' => 'body', 'old' => $e['body'], 'new' => $new);
			$stats['entityRewrites']++;
		}
	}
	foreach ($changes as $c) {
		$st = rk_state_template(rk_entity_template($tmpls, $c['entity_type'], $c['entity_id'], $shared)[0]);
		foreach (array('before', 'after') as $col) {
			if ($c[$col] === null) {
				continue;
			}
			$new = rk_rewrite($c[$col], $st);
			if ($new !== null) {
				$rewrites[] = array('table' => 'micro_changes', 'seq' => (int)$c['seq'],
					'column' => $col, 'old' => $c[$col], 'new' => $new);
				$stats['changeRewrites']++;
			}
		}
	}
	return array($entities, $changes, $rewrites, $stats);
}

/** Postgres agrees every rewrite has the same content (jsonb equality ignores key order only). */
function rk_same_content($ms, $rewrites) {
	foreach ($rewrites as $i => $w) {
		if ($ms->val("SELECT ($1::jsonb = $2::jsonb)::text", array($w['old'], $w['new'])) !== 'true') {
			return $i;
		}
	}
	return null;
}

/** Write a file atomically and read it back; returns null or the problem. */
function rk_write_verified($path, $data) {
	$text = json_encode($data, MsHttp::JSON_OUT | JSON_PRETTY_PRINT);
	if ($text === false) {
		return 'cannot encode ' . basename($path);
	}
	if (@file_put_contents("$path.tmp", $text) === false || !@rename("$path.tmp", $path)) {
		return "cannot write $path";
	}
	clearstatcache();
	if (@file_get_contents($path) !== $text || json_decode($text, true) === null) {
		return "$path does not read back";
	}
	return null;
}

/** UPDATE one stored text from $from to $to; true when exactly that row changed. */
function rk_swap($ms, $pid, $w, $from, $to) {
	if ($w['table'] === 'micro_entities') {
		$res = $ms->q(
			"UPDATE strabomicro.micro_entities SET body = $4::json
			  WHERE project_id = $1 AND entity_type = $2 AND entity_id = $3 AND body::text = $5",
			array($pid, $w['type'], $w['id'], $to, $from));
	} else {
		$col = $w['column'] === 'before' ? 'before' : 'after';
		$res = $ms->q(
			"UPDATE strabomicro.micro_changes SET $col = $3::json
			  WHERE project_id = $1 AND seq = $2 AND $col::text = $4",
			array($pid, $w['seq'], $to, $from));
	}
	return pg_affected_rows($res) === 1;
}

function rk_mark_dirty($ms, $pid) {
	$ms->q("UPDATE strabomicro.micro_projectmetadata SET views_dirty_since = COALESCE(views_dirty_since, now()) WHERE id = $1",
		array($pid));
}

// ---------------------------------------------------------------------------
// Revert
// ---------------------------------------------------------------------------

if ($revert !== null) {
	$dir = "$runsRoot/$revert";
	if (!is_dir($dir)) {
		fwrite(STDERR, "no run folder $dir\n");
		$ms->q("SELECT pg_advisory_unlock(hashtext('microsync-convert'), 0)");
		exit(1);
	}
	$files = glob("$dir/changed-*.json");
	sort($files);
	foreach ($files as $f) {
		$pid = (int)preg_replace('/^changed-([0-9]+)\.json$/', '$1', basename($f));
		if ($only !== null && !in_array($pid, $only, true)) {
			continue;
		}
		$j = json_decode((string)file_get_contents($f), true);
		if (!is_array($j) || !isset($j['rewrites'])) {
			echo "#$pid NOT reverted: $f is unreadable\n";
			$fail++;
			continue;
		}
		try {
			$ms->begin();
			MsStore::lockProject($ms, $pid);
			$bad = 0;
			foreach ($j['rewrites'] as $w) {
				if (!rk_swap($ms, $pid, $w, $w['new'], $w['old'])) {
					$bad++;
				}
			}
			if ($bad > 0) {
				$ms->rollback();
				echo "#$pid NOT reverted: $bad of " . count($j['rewrites']) . " rows changed since the run (nothing written)\n";
				$fail++;
				continue;
			}
			if (count($j['rewrites']) > 0) {
				rk_mark_dirty($ms, $pid);
			}
			$ms->commit();
			echo "#$pid reverted: " . count($j['rewrites']) . " rows back to their stored text before $revert\n";
		} catch (Throwable $e) {
			$ms->rollback();
			echo "#$pid NOT reverted: " . $e->getMessage() . "\n";
			$fail++;
		}
	}
	$ms->q("SELECT pg_advisory_unlock(hashtext('microsync-convert'), 0)");
	exit($fail ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Dry run / apply
// ---------------------------------------------------------------------------

// A run folder is never reused: its changed-*.json files are the only way back.
$run = gmdate('Ymd\THis\Z');
for ($n = 2; file_exists("$runsRoot/$run"); $n++) {
	$run = gmdate('Ymd\THis\Z') . "-$n";
}
$dir = "$runsRoot/$run";
echo ($apply ? 'APPLY' : 'DRY RUN') . ' --only=' . implode(',', $only) . ($apply ? "  (files: $dir)" : '') . "\n";

// Type orders from every project of this run, for types a project's own
// archive lacks (e.g. its first spots were added after the conversion).
$shared = array();
foreach ($only as $pid) {
	$t = rk_templates($pid);
	if (is_array($t)) {
		foreach ($t['types'] as $type => $tmpl) {
			$shared[$type] = isset($shared[$type]) ? rk_merge($shared[$type], $tmpl) : $tmpl;
		}
	}
}

foreach ($only as $pid) {
	try {
		$p = $ms->row("SELECT strabo_id, sync_format, sync_state FROM strabomicro.micro_projectmetadata WHERE id = $1", array($pid));
		if ($p === null) {
			echo "#$pid SKIPPED: no such project\n";
			$fail++;
			continue;
		}
		if ($p['sync_format'] !== 'entity') {
			echo "#$pid SKIPPED: not a converted project (sync_format {$p['sync_format']})\n";
			$fail++;
			continue;
		}
		$tmpls = rk_templates($pid);
		if (is_string($tmpls)) {
			echo "#$pid SKIPPED: $tmpls\n";
			$fail++;
			continue;
		}

		list($entities, $changes, $rewrites, $s) = rk_plan($ms, $pid, $tmpls, $shared);
		$bad = rk_same_content($ms, $rewrites);
		if ($bad !== null) {
			echo "#$pid STOPPED: a rewrite would change content (" . json_encode(array_diff_key($rewrites[$bad], array('old' => 1, 'new' => 1))) . "); nothing written\n";
			$fail++;
			continue;
		}
		$line = sprintf('#%d entities %d (order from: own archive entry %d, own archive type %d, other project %d, none %d): %d to reorder; change-log states: %d to reorder in %d rows',
			$pid, $s['entities'], $s['own'], $s['type'], $s['shared'], $s['none'], $s['entityRewrites'], $s['changeRewrites'], $s['changes']);
		if (!$apply) {
			echo "$line\n";
			continue;
		}

		if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
			echo "#$pid NOT applied: cannot create $dir\n";
			$fail++;
			continue;
		}
		$problem = rk_write_verified("$dir/backup-$pid.json", array(
			'project' => $pid, 'straboId' => $p['strabo_id'], 'run' => $run,
			'entities' => $entities, 'changes' => $changes));
		if ($problem === null) {
			$problem = rk_write_verified("$dir/changed-$pid.json", array(
				'project' => $pid, 'straboId' => $p['strabo_id'], 'run' => $run, 'rewrites' => $rewrites));
		}
		if ($problem !== null) {
			echo "#$pid NOT applied: $problem (nothing written to the database)\n";
			$fail++;
			continue;
		}

		$ms->begin();
		MsStore::lockProject($ms, $pid);
		$stale = 0;
		foreach ($rewrites as $w) {
			if (!rk_swap($ms, $pid, $w, $w['old'], $w['new'])) {
				$stale++;
			}
		}
		if ($stale > 0) {
			$ms->rollback();
			echo "#$pid NOT applied: $stale rows changed while planning (nothing written); run again\n";
			$fail++;
			continue;
		}
		if (count($rewrites) > 0) {
			rk_mark_dirty($ms, $pid);
		}
		$ms->commit();
		echo "$line\n#$pid APPLIED: " . count($rewrites) . " rows rewritten; backup + journal in $dir\n";
	} catch (Throwable $e) {
		$ms->rollback();
		echo "#$pid FAILED: " . $e->getMessage() . " (nothing written for this project)\n";
		$fail++;
	}
}

$ms->q("SELECT pg_advisory_unlock(hashtext('microsync-convert'), 0)");
exit($fail ? 1 : 0);
