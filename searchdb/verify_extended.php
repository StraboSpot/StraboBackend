<?php
/**
 * File: verify_extended.php
 * Description: StraboSearch §5.6.1 nightly source↔index reconciliation
 *              checker (+ optional healer). For each subsystem, compares
 *              the SOURCE store against the strabosearch index and reports
 *              drift; with --heal it repairs the drift in place through the
 *              same StraboSearchSync primitives the live write hooks use
 *              (§5.3.2 anti-drift: one row-mapping implementation, one heal
 *              path). This is the Q6 safety net — a sync hook that threw at
 *              write time logged the divergence, and this run picks it up.
 *
 *              Checks (in dependency order — Field heals BEFORE Samples,
 *              because the Samples fan-out resolves through the indexed
 *              Field slice):
 *
 *                [lag]     sync_state freshness report (§5.6.3, informational)
 *                [field]   Neo4j spots vs item_hit spot rows. Stage A: per
 *                          (project, user) count + max-modified aggregates
 *                          via the user-anchored walk; Stage B: id-level
 *                          diff of only the flagged projects.
 *                [images]  Neo4j reachable servable Images vs image_hit
 *                          field rows, per user (image identity is
 *                          per-image, not per-project — a multi-project
 *                          image keeps one row). Same two-stage shape.
 *                [micro]   micrograph chain vs item_hit micrographs AND
 *                          image_hit micro rows — pure-SQL full diff.
 *                [exp]     experiment×project vs item_hit experiments —
 *                          pure-SQL full diff.
 *                [samples] expected spine fan-out (3 resolution joins) vs
 *                          item_hit sample rows — pure-SQL full diff.
 *                [acl]     project_ispublic denorm vs each subsystem's
 *                          source-of-truth flag (folds in the standalone
 *                          census/audit_acl_denorm.php check, grouped per
 *                          project so it is healable via touchProjectMeta).
 *
 *              Heals (only with --heal):
 *                field    touchSpot / syncFieldDataset (large sets) /
 *                         removeSpots / removeFieldProject
 *                images   touchSpot(parent) / removeImage
 *                micro    syncMicroProject / removeMicroProject
 *                exp      touchExperiment / removeExperiment
 *                samples  touchSample (recompute-from-current absorbs all
 *                         three drift kinds, including spine deletion)
 *                acl      touchProjectMeta(ispublic)
 *
 *              Known non-failure classes (reported, never fatal):
 *                - ACL rows whose PG project row is missing (defaulted
 *                  private at extract time — safe; the detached-subtree /
 *                  PG-mirror-gap class).
 *                - Field index rows whose searchtext_tsv predates a project
 *                  rename are NOT detected here (documented touchProjectMeta
 *                  staleness); they heal whenever the spot itself drifts.
 *
 *              Exit codes (cron contract):
 *                0  clean — no drift anywhere
 *                1  drift found and FULLY healed (--heal) — worth a look
 *                   (some write hook failed since the last run)
 *                2  drift present at exit: detect-only drift, unhealed
 *                   remainder (--max-heal), heal failures, or check errors
 *                   (a failed query is NEVER reported as clean)
 *
 *              Usage (inside the container):
 *                docker exec strabo-php php /srv/app/www/searchdb/verify_extended.php
 *                    [--heal] [--max-heal=N] [--source-userpkey=N]
 *                    [--only=field,images,micro,exp,samples,acl] [--help]
 *
 *              Nightly cron (dev + post-Phase-6 prod):
 *                17 3 * * * docker exec strabo-php php /srv/app/www/searchdb/verify_extended.php --heal >> /var/log/strabosearch_verify.log 2>&1
 *
 *              Concurrency: heals serialize against live traffic via the
 *              sync layer's advisory locks. A user actively writing during
 *              the run can surface transient one-item drift; touch heals
 *              are idempotent, so healing it is harmless.
 *
 * @package StraboSearch verify
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit("CLI only.\n");
}

chdir(__DIR__ . '/..');
// Id-only pulls, but whale users still put six figures of tiny rows in the
// Bolt receive buffer — 4G is comfortable (full-node extract needs 8G).
ini_set('memory_limit', '4G');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
require_once(__DIR__ . '/sync/StraboSearchSync.php');  // pulls _row_builders → _extractor_lib → _census_lib

// ---------------------------------------------------------------------------
// Flags
// ---------------------------------------------------------------------------

$HEAL       = in_array('--heal', $argv, true);
$MAX_HEAL   = 5000;
$SCOPE_UPK  = null;   // --source-userpkey — scopes every check to one user (test isolation)
$ONLY       = array('field', 'images', 'micro', 'exp', 'samples', 'acl');

foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--help' || $arg === '-h') {
		echo "Usage: php verify_extended.php [--heal] [--max-heal=N] [--source-userpkey=N] [--only=list]\n\n";
		echo "  Compares every subsystem's source store against the strabosearch index\n";
		echo "  and reports drift per source (DESIGN_PROPOSAL.md §5.6.1).\n\n";
		echo "  --heal              Repair drift in place via the StraboSearchSync touch/remove\n";
		echo "                      primitives. Without it, detect-only.\n";
		echo "  --max-heal=N        Heal-unit budget (default 5000 rows). Drift beyond the budget\n";
		echo "                      is left in place and reported — 'beyond healing reach' means\n";
		echo "                      re-run the extractors instead.\n";
		echo "  --source-userpkey=N Scope every check to one user (hermetic tests / incident triage).\n";
		echo "  --only=a,b,c        Run a subset of: field,images,micro,exp,samples,acl.\n";
		echo "                      NOTE: samples resolves through the indexed Field slice — running\n";
		echo "                      samples without field against a drifted Field slice under-detects.\n\n";
		echo "  Exit 0 = clean; 1 = drift found + fully healed; 2 = drift/errors at exit.\n";
		exit(0);
	}
	if (preg_match('/^--max-heal=(\d+)$/', $arg, $m))        $MAX_HEAL  = (int)$m[1];
	if (preg_match('/^--source-userpkey=(\d+)$/', $arg, $m)) $SCOPE_UPK = (int)$m[1];
	if (preg_match('/^--only=([a-z,]+)$/', $arg, $m)) {
		$ONLY = array_values(array_intersect(
			array('field', 'images', 'micro', 'exp', 'samples', 'acl'),
			explode(',', $m[1])));
	}
}

// ---------------------------------------------------------------------------
// Tallies + shared helpers
// ---------------------------------------------------------------------------

$DRIFT        = 0;      // total drifted rows found
$HEALED       = 0;      // heal units applied successfully
$HEAL_FAILED  = 0;      // heal calls that returned false
$UNHEALED     = 0;      // drift left in place (no --heal, or budget exhausted)
$CHECK_ERRORS = 0;      // failed queries — never reported as clean
$HEAL_BUDGET  = $MAX_HEAL;
$DETAIL_LINES = 20;     // per-check cap on printed drift detail

$CHECK_STATUS = array();   // check → 'ok' | 'drift' | 'error'

/** Reserve $n heal units; false = budget exhausted (drift stays unhealed). */
function healBudgetTake($n) {
	global $HEAL_BUDGET;
	if ($HEAL_BUDGET < $n) return false;
	$HEAL_BUDGET -= $n;
	return true;
}

/** Record a heal call's outcome. */
function healResult($ok, $units, $label) {
	global $HEALED, $HEAL_FAILED;
	if ($ok) {
		$HEALED += $units;
	} else {
		$HEAL_FAILED += $units;
		line('    HEAL FAILED: ' . $label);
	}
	return $ok;
}

/**
 * Guarded get_results: a failed query must read as CHECK ERROR, never as
 * an empty (clean) result. Returns array; sets the per-call $failed flag.
 */
function sqlRows($db, $sql, &$failed) {
	global $CHECK_ERRORS;
	$db->last_error = '';
	$rows = $db->get_results($sql);
	if ($db->last_error !== '' && $db->last_error !== null) {
		line('  SQL FAILED: ' . $db->last_error);
		$CHECK_ERRORS++;
		$failed = true;
		return array();
	}
	return (array)$rows;
}

/** Guarded get_var — null result is only trusted when no error fired. */
function sqlVar($db, $sql, &$failed) {
	global $CHECK_ERRORS;
	$db->last_error = '';
	$v = $db->get_var($sql);
	if ($db->last_error !== '' && $db->last_error !== null) {
		line('  SQL FAILED: ' . $db->last_error);
		$CHECK_ERRORS++;
		$failed = true;
		return null;
	}
	return $v;
}

/**
 * Guarded Neo4j query. Throws → reconnect (a failed Cypher permanently
 * poisons the Bolt connection — the next query would hang forever) and
 * flag the check as errored. Returns null ONLY on failure; empty results
 * come back as empty iterables.
 */
function neoRows($neodb, $cypher, &$failed) {
	global $CHECK_ERRORS;
	try {
		$rows = $neodb->query($cypher);
		return ($rows === null || $rows === false) ? array() : $rows;
	} catch (\Throwable $e) {
		line('  NEO4J FAILED: ' . $e->getMessage());
		$CHECK_ERRORS++;
		$failed = true;
		try {
			if (method_exists($neodb, 'reconnect')) $neodb->reconnect();
		} catch (\Throwable $e2) {
			line('  NEO4J RECONNECT FAILED: ' . $e2->getMessage());
		}
		return null;
	}
}

/**
 * Neo4j modified_timestamp → epoch SECONDS (float) or null. Mirrors
 * pgTimestamp()'s numeric branches (ms-epoch canonical, s-epoch fallback);
 * ISO-ish strings go through strtotime.
 */
function epochFromNeoMt($v) {
	if ($v === null || $v === '' || $v === false) return null;
	if (is_numeric($v)) {
		$n = (float)$v;
		if ($n > 1000000000000) return $n / 1000.0;
		if ($n > 1000000000)    return $n;
		return null;
	}
	$t = strtotime((string)$v);
	return ($t === false) ? null : (float)$t;
}

/** Comparison slack for source↔index timestamps (conversion rounding). */
define('VERIFY_STALE_TOL', 2.0);

/**
 * SET-MEMBERSHIP staleness (PHP side, mirrors the SQL NOT EXISTS rule):
 * the index timestamp is fresh iff it matches at least one source copy's
 * timestamp. Handles duplicate source nodes/rows sharing one id — the
 * index legitimately holds any one copy — and catches drift in BOTH
 * directions (index older OR newer than every copy).
 */
function epMatchesAny($idxEp, $eps) {
	foreach ($eps as $ep) {
		if ($ep === null && $idxEp === null) return true;
		if ($ep !== null && $idxEp !== null && abs($ep - $idxEp) <= VERIFY_STALE_TOL) return true;
	}
	return false;
}

/**
 * SQL expr converting micro_projectmetadata.modifiedtimestamp (varchar,
 * mixed ms-epoch AND ISO-8601 on prod) to timestamptz — the known CASE
 * branch. Unparseable → NULL (stale check skipped, count checks still run).
 */
function microTsSqlExpr($col) {
	return "CASE
		WHEN $col ~ '^[0-9]{10,}\$' THEN
			CASE WHEN $col::numeric > 1000000000000
			     THEN to_timestamp($col::numeric / 1000.0)
			     WHEN $col::numeric > 1000000000
			     THEN to_timestamp($col::numeric)
			     ELSE NULL END
		WHEN $col ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}' THEN $col::timestamptz
		ELSE NULL END";
}

/** Bounded drift-detail printer. */
function detailLine(&$printed, $msg) {
	global $DETAIL_LINES;
	$printed++;
	if ($printed <= $DETAIL_LINES) line('    ' . $msg);
	elseif ($printed === $DETAIL_LINES + 1) line('    ... (further detail suppressed)');
}

function finishCheck($name, $checkDrift, $checkFailed) {
	global $CHECK_STATUS;
	if ($checkFailed) {
		$CHECK_STATUS[$name] = 'error';
		line('  RESULT: CHECK ERROR — treat as drift, do not trust counts above.');
	} elseif ($checkDrift > 0) {
		$CHECK_STATUS[$name] = 'drift';
		line("  RESULT: $checkDrift drifted row(s).");
	} else {
		$CHECK_STATUS[$name] = 'ok';
		line('  RESULT: OK');
	}
}

// ---------------------------------------------------------------------------
section('STRABOSEARCH verify_extended — ' . date('Y-m-d H:i:s'));
line('  mode:            ' . ($HEAL ? "DETECT + HEAL (budget $MAX_HEAL)" : 'DETECT ONLY'));
if ($SCOPE_UPK !== null) line('  scope:           userpkey = ' . $SCOPE_UPK);
line('  checks:          ' . implode(', ', $ONLY));

$failed = false;
if (!sqlVar($db, "SELECT to_regclass('strabosearch.item_hit')", $failed)) {
	line();
	line('strabosearch schema absent — nothing to verify. (Pre-cutover host?)');
	exit(2);
}
if (defined('STRABOSEARCH_SYNC_DISABLED') && STRABOSEARCH_SYNC_DISABLED && $HEAL) {
	line('  WARNING: STRABOSEARCH_SYNC_DISABLED is set — sync primitives no-op, healing is OFF.');
	$HEAL = false;
}

// ---------------------------------------------------------------------------
// [lag] §5.6.3 sync freshness — informational only (sync is write-driven;
// an idle subsystem legitimately shows large lag).
// ---------------------------------------------------------------------------

subsection('[lag] sync_state freshness (informational)');
$failed = false;
foreach (sqlRows($db, "
	SELECT source,
	       to_char(last_full_backfill,    'YYYY-MM-DD HH24:MI') AS backfill,
	       to_char(last_incremental_sync, 'YYYY-MM-DD HH24:MI') AS incr,
	       CASE WHEN last_incremental_sync IS NULL THEN '-'
	            ELSE date_trunc('minute', now() - last_incremental_sync)::text END AS lag
	FROM strabosearch.sync_state ORDER BY source", $failed) as $r) {
	line(sprintf('  %-8s backfill %-17s last sync %-17s lag %s',
		$r->source, ($r->backfill ?: '-'), ($r->incr ?: '-'), $r->lag));
}

// ---------------------------------------------------------------------------
// [field] Neo4j spots vs item_hit spot rows
// ---------------------------------------------------------------------------

if (in_array('field', $ONLY, true)) {
	subsection('[field] Neo4j spots vs item_hit (two-stage)');
	$failed = false;
	$checkDrift = 0;
	$printed = 0;

	// Stage A index side: one aggregate pass over the Field spot slice.
	$scopeSql = ($SCOPE_UPK !== null) ? " AND project_userpkey = $SCOPE_UPK" : '';
	$idxAgg = array();   // upk → pid → {n, maxep}
	foreach (sqlRows($db, "
		SELECT project_userpkey AS upk, project_id AS pid, count(*) AS n,
		       max(extract(epoch from source_modified)) AS maxep
		FROM strabosearch.item_hit
		WHERE item_type = 'spot' AND project_subsystem = 'field'$scopeSql
		GROUP BY 1, 2", $failed) as $r) {
		$idxAgg[(int)$r->upk][(string)$r->pid] = array(
			'n' => (int)$r->n,
			'maxep' => ($r->maxep === null ? null : (float)$r->maxep));
	}

	// Stage A source side: per-user aggregate walk (user-anchored — Strabo
	// ids are NOT unique; HAS_PROJECT is the ownership edge).
	if ($SCOPE_UPK !== null) {
		$activeUsers = array($SCOPE_UPK);
	} else {
		$activeUsers = array();
		$urows = neoRows($neodb, "MATCH (u:User)-[:HAS_PROJECT]->(:Project)
			RETURN distinct toInt(u.userpkey) AS upk ORDER BY upk", $failed);
		if ($urows !== null) {
			foreach ($urows as $r) $activeUsers[] = (int)$r->get('upk');
		}
	}
	line('  users to walk: ' . count($activeUsers) . ', indexed field projects: '
		. array_sum(array_map('count', $idxAgg)));

	$flagged = array();          // list of [upk, pid, reason]
	$srcSeen = array();          // upk → pid → true (for extra-project detection)
	foreach ($activeUsers as $upk) {
		if ($failed) break;      // poisoned walk — don't trust anything further
		$rows = neoRows($neodb, "
			MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project)
			      -[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(s:Spot)
			WHERE s.id IS NOT NULL
			RETURN p.id AS pid, count(DISTINCT s.id) AS n,
			       max(toInt(s.modified_timestamp)) AS maxms", $failed);
		if ($rows === null) break;
		foreach ($rows as $r) {
			$pid = $r->get('pid');
			if ($pid === null) continue;
			$pidK = (string)$pid;
			$srcSeen[$upk][$pidK] = true;
			$srcN  = (int)$r->get('n');
			$srcEp = epochFromNeoMt($r->get('maxms'));
			$idx = isset($idxAgg[$upk][$pidK]) ? $idxAgg[$upk][$pidK] : null;
			if ($idx === null) {
				$flagged[] = array($upk, $pid, "missing project ($srcN spots)");
			} elseif ($idx['n'] !== $srcN) {
				$flagged[] = array($upk, $pid, "count {$idx['n']} != source $srcN");
			} elseif ($srcEp !== null && $idx['maxep'] !== null
					&& abs($srcEp - $idx['maxep']) > VERIFY_STALE_TOL) {
				// Either direction — an index newer than every source copy is
				// drift too (version restore). Stage B's membership rule is
				// the authority; a dup-node project can flag here and come
				// back clean below.
				$flagged[] = array($upk, $pid, 'max-modified mismatch (stale?)');
			}
		}
	}

	// Whole projects present in the index but absent from the source walk.
	$extraProjects = array();
	if (!$failed) {
		foreach ($idxAgg as $upk => $pids) {
			if ($SCOPE_UPK === null && !in_array($upk, $activeUsers, true)) {
				foreach ($pids as $pidK => $agg) $extraProjects[] = array($upk, $pidK, $agg['n']);
				continue;
			}
			foreach ($pids as $pidK => $agg) {
				if (!isset($srcSeen[$upk][$pidK])) $extraProjects[] = array($upk, $pidK, $agg['n']);
			}
		}
	}

	line('  flagged projects: ' . count($flagged) . ', extra index projects: ' . count($extraProjects));

	// Extra projects: every index row is drift; heal = drop the slice.
	foreach ($extraProjects as $ep) {
		list($upk, $pidK, $n) = $ep;
		$checkDrift += $n;
		detailLine($printed, "EXTRA project $pidK/u$upk — $n index row(s), no source project");
		if ($HEAL && healBudgetTake($n)) {
			healResult(StraboSearchSync::removeFieldProject($db, $pidK, $upk), $n,
				"removeFieldProject $pidK/$upk");
		} else {
			$UNHEALED += $n;
		}
	}

	// Stage B: id-level diff per flagged project.
	foreach ($flagged as $f) {
		if ($failed) break;
		list($upk, $pid, $reason) = $f;
		$pidK = (string)$pid;
		$pidLit = neoIdLiteral($pid);

		// Source spot map (id → max epoch), keyset-paginated (tiny rows,
		// but whale projects exist).
		$srcSpots = array();
		$cursor = null;
		while (true) {
			$cur = ($cursor !== null) ? "AND s.id > " . neoIdLiteral($cursor) . " " : '';
			$rows = neoRows($neodb, "
				MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project {id: $pidLit})
				      -[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(s:Spot)
				WHERE s.id IS NOT NULL $cur
				WITH DISTINCT s ORDER BY s.id LIMIT 10000
				RETURN s.id AS sid, s.modified_timestamp AS mt", $failed);
			if ($rows === null) break 2;
			$count = 0;
			foreach ($rows as $r) {
				$sid = $r->get('sid');
				if ($sid === null) continue;
				$count++;
				$cursor = $sid;
				// List of copies (dup nodes sharing one id) — membership rule.
				$srcSpots[(string)$sid][] = epochFromNeoMt($r->get('mt'));
			}
			if ($count < 10000) break;
		}

		// Index spot map for this project (project_slice idx).
		$idxSpots = array();
		$pidEsc = pg_escape_string($pidK);
		foreach (sqlRows($db, "
			SELECT item_id, extract(epoch from source_modified) AS ep
			FROM strabosearch.item_hit
			WHERE item_type = 'spot' AND project_subsystem = 'field'
			  AND project_userpkey = $upk AND project_id = '$pidEsc'", $failed) as $r) {
			$idxSpots[(string)$r->item_id] = ($r->ep === null ? null : (float)$r->ep);
		}

		$missing = array();
		$stale   = array();
		foreach ($srcSpots as $sidK => $eps) {
			if (!array_key_exists($sidK, $idxSpots)) {
				$missing[] = $sidK;
			} elseif (!epMatchesAny($idxSpots[$sidK], $eps)) {
				$stale[] = $sidK;
			}
		}
		$extra = array_values(array_diff(array_keys($idxSpots), array_keys($srcSpots)));

		$n = count($missing) + count($stale) + count($extra);
		if ($n === 0) {
			// Stage A false positive — racing live write, or a dup-copy
			// project where the aggregate max disagrees but membership holds.
			detailLine($printed, "project $pidK/u$upk flagged ($reason) but id-diff clean — not drift");
			continue;
		}
		$checkDrift += $n;
		detailLine($printed, "project $pidK/u$upk ($reason): "
			. count($missing) . ' missing, ' . count($stale) . ' stale, ' . count($extra) . ' extra');

		if (!$HEAL) { $UNHEALED += $n; continue; }

		// Heal removals first (cheap), then touches.
		if ($extra) {
			if (healBudgetTake(count($extra))) {
				healResult(StraboSearchSync::removeSpots($db, $extra, $upk), count($extra),
					"removeSpots x" . count($extra) . " $pidK/$upk");
			} else { $UNHEALED += count($extra); }
		}
		$toTouch = array_merge($missing, $stale);
		if ($toTouch) {
			if (!healBudgetTake(count($toTouch))) {
				$UNHEALED += count($toTouch);
			} elseif (count($toTouch) > 200) {
				// Whole-slice rebuild is cheaper than per-spot touches at this
				// size — syncFieldDataset batches per dataset (and re-syncs
				// the images + linked samples of every touched spot).
				$drows = neoRows($neodb, "
					MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project {id: $pidLit})
					      -[:HAS_DATASET]->(d:Dataset)
					RETURN DISTINCT d.id AS did", $failed);
				if ($drows !== null) {
					$ok = true;
					foreach ($drows as $dr) {
						$did = $dr->get('did');
						if ($did === null) continue;
						$ok = StraboSearchSync::syncFieldDataset($db, $neodb, $did, $upk) && $ok;
					}
					healResult($ok, count($toTouch), "syncFieldDataset sweep $pidK/$upk");
				}
			} else {
				$ok = true;
				foreach ($toTouch as $sidK) {
					$ok = StraboSearchSync::touchSpot($db, $neodb, $sidK, $upk) && $ok;
				}
				healResult($ok, count($toTouch), "touchSpot x" . count($toTouch) . " $pidK/$upk");
			}
		}
	}

	$DRIFT += $checkDrift;
	finishCheck('field', $checkDrift, $failed);
}

// ---------------------------------------------------------------------------
// [images] Neo4j servable Field images vs image_hit
// ---------------------------------------------------------------------------

if (in_array('images', $ONLY, true)) {
	subsection('[images] Neo4j servable Field images vs image_hit (per user)');
	$failed = false;
	$checkDrift = 0;
	$printed = 0;

	// Eligibility mirrors fieldImageTuples: non-empty id AND non-empty
	// trimmed filename (§5.5 servability gate).
	$ELIG = "i.id IS NOT NULL AND toString(i.id) <> ''
	         AND i.filename IS NOT NULL AND trim(toString(i.filename)) <> ''";

	$scopeSql = ($SCOPE_UPK !== null) ? " AND project_userpkey = $SCOPE_UPK" : '';
	$idxAgg = array();   // upk → {n, maxep}
	foreach (sqlRows($db, "
		SELECT project_userpkey AS upk, count(DISTINCT image_id) AS n,
		       max(extract(epoch from source_modified)) AS maxep
		FROM strabosearch.image_hit
		WHERE image_subsystem = 'field'$scopeSql
		GROUP BY 1", $failed) as $r) {
		$idxAgg[(int)$r->upk] = array(
			'n' => (int)$r->n,
			'maxep' => ($r->maxep === null ? null : (float)$r->maxep));
	}

	if ($SCOPE_UPK !== null) {
		$activeUsers = array($SCOPE_UPK);
	} else {
		$activeUsers = array();
		$urows = neoRows($neodb, "MATCH (u:User)-[:HAS_PROJECT]->(:Project)
			RETURN distinct toInt(u.userpkey) AS upk ORDER BY upk", $failed);
		if ($urows !== null) {
			foreach ($urows as $r) $activeUsers[] = (int)$r->get('upk');
		}
	}

	$flaggedUsers = array();
	$srcUserSeen  = array();
	foreach ($activeUsers as $upk) {
		if ($failed) break;
		$rows = neoRows($neodb, "
			MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(:Project)
			      -[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(:Spot)-[:HAS_IMAGE]->(i:Image)
			WHERE $ELIG
			RETURN count(DISTINCT i.id) AS n, max(toInt(i.modified_timestamp)) AS maxms", $failed);
		if ($rows === null) break;
		$srcN = 0; $srcEp = null;
		foreach ($rows as $r) {
			$srcN  = (int)$r->get('n');
			$srcEp = epochFromNeoMt($r->get('maxms'));
		}
		$srcUserSeen[$upk] = true;
		$idx = isset($idxAgg[$upk]) ? $idxAgg[$upk] : array('n' => 0, 'maxep' => null);
		// NOTE index maxep may legitimately EXCEED the source image max —
		// rows fall back to the parent spot's mtime when the image has none.
		if ($idx['n'] !== $srcN) {
			$flaggedUsers[] = array($upk, "count {$idx['n']} != source $srcN");
		} elseif ($srcEp !== null && $idx['maxep'] !== null
				&& $srcEp > $idx['maxep'] + VERIFY_STALE_TOL) {
			$flaggedUsers[] = array($upk, 'source newer than index (stale)');
		}
	}
	if (!$failed && $SCOPE_UPK === null) {
		foreach ($idxAgg as $upk => $agg) {
			if (!isset($srcUserSeen[$upk])) $flaggedUsers[] = array($upk, "no source user ({$agg['n']} rows)");
		}
	}
	line('  users flagged: ' . count($flaggedUsers) . ' of ' . count($activeUsers));

	foreach ($flaggedUsers as $f) {
		if ($failed) break;
		list($upk, $reason) = $f;

		// Source: image id → [max mtime epoch, one parent spot id].
		$srcImgs = array();
		$cursor = null;
		while (true) {
			$cur = ($cursor !== null) ? "AND i.id > " . neoIdLiteral($cursor) . " " : '';
			$rows = neoRows($neodb, "
				MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(:Project)
				      -[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(s:Spot)-[:HAS_IMAGE]->(i:Image)
				WHERE $ELIG $cur
				WITH i, min(s.id) AS sid
				ORDER BY i.id LIMIT 10000
				RETURN i.id AS iid, i.modified_timestamp AS imt, sid", $failed);
			if ($rows === null) break 2;
			$count = 0;
			foreach ($rows as $r) {
				$iid = $r->get('iid');
				if ($iid === null) continue;
				$count++;
				$cursor = $iid;
				$iidK = (string)$iid;
				if (!isset($srcImgs[$iidK])) {
					$srcImgs[$iidK] = array(array(), $r->get('sid'));
				}
				$srcImgs[$iidK][0][] = epochFromNeoMt($r->get('imt'));
			}
			if ($count < 10000) break;
		}

		// Index: image id → [epoch, image_userpkey].
		$idxImgs = array();
		foreach (sqlRows($db, "
			SELECT image_id, image_userpkey, extract(epoch from source_modified) AS ep
			FROM strabosearch.image_hit
			WHERE image_subsystem = 'field' AND project_userpkey = $upk", $failed) as $r) {
			$idxImgs[(string)$r->image_id] = array(
				($r->ep === null ? null : (float)$r->ep), (int)$r->image_userpkey);
		}

		$touchSpots = array();   // parent spots of missing/stale images
		$extras = array();       // [image_id, image_userpkey]
		foreach ($srcImgs as $iidK => $src) {
			list($eps, $sid) = $src;
			// Rows whose every source copy lacks a mtime fell back to the
			// parent SPOT's mtime at extract — skip the stale test for those
			// (count/identity checks still apply).
			$hasOwnMt = false;
			foreach ($eps as $e) if ($e !== null) { $hasOwnMt = true; break; }
			if (!array_key_exists($iidK, $idxImgs)) {
				if ($sid !== null) $touchSpots[(string)$sid] = true;
				$checkDrift++;
			} elseif ($hasOwnMt && !epMatchesAny($idxImgs[$iidK][0], $eps)) {
				if ($sid !== null) $touchSpots[(string)$sid] = true;
				$checkDrift++;
			}
		}
		foreach ($idxImgs as $iidK => $idxv) {
			if (!array_key_exists($iidK, $srcImgs)) {
				$extras[] = array($iidK, $idxv[1]);
				$checkDrift++;
			}
		}
		detailLine($printed, "user $upk ($reason): " . count($touchSpots)
			. ' spot(s) to re-touch, ' . count($extras) . ' extra image row(s)');

		if (!$HEAL) { $UNHEALED += count($touchSpots) + count($extras); continue; }
		if ($extras) {
			if (healBudgetTake(count($extras))) {
				$ok = true;
				foreach ($extras as $ex) {
					$ok = StraboSearchSync::removeImage($db, $ex[0], $ex[1]) && $ok;
				}
				healResult($ok, count($extras), "removeImage x" . count($extras) . " u$upk");
			} else { $UNHEALED += count($extras); }
		}
		if ($touchSpots) {
			if (healBudgetTake(count($touchSpots))) {
				$ok = true;
				foreach (array_keys($touchSpots) as $sidK) {
					// $alsoSamples=false — image drift does not move sample fan-out.
					$ok = StraboSearchSync::touchSpot($db, $neodb, $sidK, $upk, false) && $ok;
				}
				healResult($ok, count($touchSpots), "touchSpot(parents) x" . count($touchSpots) . " u$upk");
			} else { $UNHEALED += count($touchSpots); }
		}
	}

	$DRIFT += $checkDrift;
	finishCheck('images', $checkDrift, $failed);
}

// ---------------------------------------------------------------------------
// [micro] micrograph chain vs item_hit + image_hit — pure SQL
// ---------------------------------------------------------------------------

if (in_array('micro', $ONLY, true)) {
	subsection('[micro] micrograph chain vs item_hit/image_hit');
	$failed = false;
	$checkDrift = 0;
	$printed = 0;

	$scopeSrc = ($SCOPE_UPK !== null) ? " AND pm.userpkey = $SCOPE_UPK" : '';
	$tsExpr = microTsSqlExpr('pm.modifiedtimestamp');
	// One row per micrograph COPY. Micro ids can be duplicated (dup project
	// uploads) — the index legitimately holds any one copy, so staleness is
	// judged by SET MEMBERSHIP: the index timestamp must match at least one
	// source copy, else the row is stale. Converges no matter which copy the
	// backfill (first-in) or a live touch (last-touched) put there, and also
	// catches an index NEWER than every copy (e.g. a version restore).
	$srcAllCte = "
		SELECT mm.strabo_id AS item_id, pm.userpkey AS upk, pm.strabo_id AS project_id,
		       pm.id AS pm_id, $tsExpr AS src_ts
		FROM strabomicro.micro_micrographmetadata mm
		JOIN strabomicro.micro_samplemetadata  sm ON sm.id = mm.sample_id
		JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
		JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
		WHERE pm.userpkey IS NOT NULL
		  AND mm.strabo_id IS NOT NULL AND mm.strabo_id <> ''
		  AND pm.strabo_id IS NOT NULL AND pm.strabo_id <> ''$scopeSrc";
	$tsMatch = "((a.src_ts IS NULL AND idx.source_modified IS NULL)
	             OR abs(extract(epoch from (a.src_ts - idx.source_modified))) <= 2)";

	// item_hit diff joins on the full (item, upk, project) identity;
	// image_hit's identity is per-IMAGE (a micrograph re-hosted across two
	// projects of one user keeps ONE image row), so its diff joins on
	// (item, upk) only — src collapsed accordingly.
	$idxItemScope  = ($SCOPE_UPK !== null) ? " AND project_userpkey = $SCOPE_UPK" : '';
	$diffs = array(
		'item_hit' => array(
			"SELECT DISTINCT ON (item_id, upk, project_id) item_id, upk, project_id, pm_id, src_ts
			 FROM srcall ORDER BY item_id, upk, project_id, src_ts DESC NULLS LAST, pm_id",
			"SELECT item_id, item_userpkey AS upk, project_id, source_modified
			 FROM strabosearch.item_hit
			 WHERE item_type = 'micrograph' AND project_subsystem = 'micro'$idxItemScope",
			"AND idx.project_id = src.project_id",
			"AND a.project_id = idx.project_id"),
		'image_hit' => array(
			"SELECT DISTINCT ON (item_id, upk) item_id, upk, project_id, pm_id, src_ts
			 FROM srcall ORDER BY item_id, upk, src_ts DESC NULLS LAST, pm_id",
			"SELECT image_id AS item_id, image_userpkey AS upk, project_id, source_modified
			 FROM strabosearch.image_hit
			 WHERE image_subsystem = 'micro'$idxItemScope",
			"",
			""),
	);
	$driftProjects = array();   // "pid|upk" → ['pm_id' => int|null, 'rows' => n]
	foreach ($diffs as $table => $d) {
		list($srcSql, $idxSql, $projJoin, $memberJoin) = $d;
		$rows = sqlRows($db, "
			WITH srcall AS ($srcAllCte), src AS ($srcSql), idx AS ($idxSql)
			SELECT coalesce(src.item_id, idx.item_id)       AS item_id,
			       coalesce(src.upk, idx.upk)               AS upk,
			       coalesce(src.project_id, idx.project_id) AS project_id,
			       src.pm_id,
			       CASE WHEN idx.item_id IS NULL THEN 'missing'
			            WHEN src.item_id IS NULL THEN 'extra'
			            ELSE 'stale' END AS kind
			FROM src FULL OUTER JOIN idx
			  ON idx.item_id = src.item_id AND idx.upk = src.upk $projJoin
			WHERE idx.item_id IS NULL OR src.item_id IS NULL
			   OR NOT EXISTS (SELECT 1 FROM srcall a
			                  WHERE a.item_id = idx.item_id AND a.upk = idx.upk $memberJoin
			                    AND $tsMatch)", $failed);
		foreach ($rows as $r) {
			$checkDrift++;
			$k = (string)$r->project_id . '|' . (int)$r->upk;
			if (!isset($driftProjects[$k])) {
				$driftProjects[$k] = array('pm_id' => null, 'rows' => 0);
			}
			if ($r->pm_id !== null) $driftProjects[$k]['pm_id'] = (int)$r->pm_id;
			$driftProjects[$k]['rows']++;
			detailLine($printed, "$table $r->kind: micrograph $r->item_id project $r->project_id/u$r->upk");
		}
	}
	line('  drifted rows: ' . $checkDrift . ' across ' . count($driftProjects) . ' project(s)');

	if ($HEAL) {
		foreach ($driftProjects as $k => $info) {
			list($pidK, $upk) = explode('|', $k);
			$upk = (int)$upk;
			$n = $info['rows'];
			if (!healBudgetTake($n)) { $UNHEALED += $n; continue; }
			$pmId = $info['pm_id'];
			if ($pmId === null) {
				// Extra-only project — confirm it is really gone from source.
				$f2 = false;
				$v = sqlVar($db, "SELECT min(id) FROM strabomicro.micro_projectmetadata
					WHERE strabo_id = '" . pg_escape_string($pidK) . "' AND userpkey = $upk", $f2);
				if ($v !== null && $v !== false) $pmId = (int)$v;
			}
			if ($pmId !== null) {
				healResult(StraboSearchSync::syncMicroProject($db, $pmId, $pidK, $upk), $n,
					"syncMicroProject $pidK/$upk");
			} else {
				healResult(StraboSearchSync::removeMicroProject($db, $pidK, $upk), $n,
					"removeMicroProject $pidK/$upk");
			}
		}
	} else {
		$UNHEALED += $checkDrift;
	}

	$DRIFT += $checkDrift;
	finishCheck('micro', $checkDrift, $failed);
}

// ---------------------------------------------------------------------------
// [exp] experiments vs item_hit — pure SQL
// ---------------------------------------------------------------------------

if (in_array('exp', $ONLY, true)) {
	subsection('[exp] experiments vs item_hit');
	$failed = false;
	$checkDrift = 0;
	$printed = 0;

	$scopeSrc = ($SCOPE_UPK !== null) ? " AND e.userpkey = $SCOPE_UPK" : '';
	$scopeIdx = ($SCOPE_UPK !== null) ? " AND item_userpkey = $SCOPE_UPK" : '';
	// e.id is a human-readable name, NOT unique per (user, project) — the
	// index identity collapses same-id experiments into one row that
	// legitimately holds ANY one copy (backfill keeps the first, a live
	// touch the last-touched). Staleness is therefore SET MEMBERSHIP: the
	// index timestamp must match at least one copy. Heals target the
	// newest copy (most recently edited content wins the shared row).
	$rows = sqlRows($db, "
		WITH srcall AS (
			SELECT e.id AS item_id, e.userpkey AS upk, p.uuid AS project_id,
			       e.pkey AS exp_pkey, e.modified_timestamp AS src_ts
			FROM straboexp.experiment e
			JOIN straboexp.project p ON p.pkey = e.project_pkey
			WHERE e.userpkey IS NOT NULL
			  AND e.id IS NOT NULL AND e.id <> ''
			  AND p.uuid IS NOT NULL AND p.uuid <> ''$scopeSrc
		), src AS (
			SELECT DISTINCT ON (item_id, upk, project_id)
			       item_id, upk, project_id, exp_pkey, src_ts
			FROM srcall
			ORDER BY item_id, upk, project_id, src_ts DESC NULLS LAST, exp_pkey DESC
		), idx AS (
			SELECT item_id, item_userpkey AS upk, project_id, source_modified
			FROM strabosearch.item_hit
			WHERE item_type = 'experiment' AND project_subsystem = 'exp'$scopeIdx
		)
		SELECT coalesce(src.item_id, idx.item_id)       AS item_id,
		       coalesce(src.upk, idx.upk)               AS upk,
		       coalesce(src.project_id, idx.project_id) AS project_id,
		       src.exp_pkey,
		       CASE WHEN idx.item_id IS NULL THEN 'missing'
		            WHEN src.item_id IS NULL THEN 'extra'
		            ELSE 'stale' END AS kind
		FROM src FULL OUTER JOIN idx
		  ON idx.item_id = src.item_id AND idx.upk = src.upk
		 AND idx.project_id = src.project_id
		WHERE idx.item_id IS NULL OR src.item_id IS NULL
		   OR NOT EXISTS (SELECT 1 FROM srcall a
		                  WHERE a.item_id = idx.item_id AND a.upk = idx.upk
		                    AND a.project_id = idx.project_id
		                    AND ((a.src_ts IS NULL AND idx.source_modified IS NULL)
		                         OR abs(extract(epoch from (a.src_ts - idx.source_modified))) <= 2))", $failed);

	foreach ($rows as $r) {
		$checkDrift++;
		detailLine($printed, "$r->kind: experiment $r->item_id project $r->project_id/u$r->upk");
		if (!$HEAL) { $UNHEALED++; continue; }
		if (!healBudgetTake(1)) { $UNHEALED++; continue; }
		if ($r->kind === 'extra') {
			healResult(StraboSearchSync::removeExperiment($db, $r->item_id, (int)$r->upk), 1,
				"removeExperiment $r->item_id/u$r->upk");
		} else {
			healResult(StraboSearchSync::touchExperiment($db, (int)$r->exp_pkey), 1,
				"touchExperiment pkey $r->exp_pkey");
		}
	}

	$DRIFT += $checkDrift;
	finishCheck('exp', $checkDrift, $failed);
}

// ---------------------------------------------------------------------------
// [samples] expected spine fan-out vs item_hit — pure SQL
// ---------------------------------------------------------------------------

if (in_array('samples', $ONLY, true)) {
	subsection('[samples] spine fan-out vs item_hit');
	$failed = false;
	$checkDrift = 0;
	$printed = 0;

	if (!sqlVar($db, "SELECT to_regclass('strabosamples.samples')", $failed)) {
		line('  strabosamples schema absent — skipped.');
		finishCheck('samples', 0, $failed);
	} else {
		$scopeSrc = ($SCOPE_UPK !== null) ? " AND s.userpkey = $SCOPE_UPK" : '';
		$scopeIdx = ($SCOPE_UPK !== null) ? " AND item_userpkey = $SCOPE_UPK" : '';
		// Slim mirrors of samplesFieldSql / samplesMicroSql / samplesExpSql —
		// same joins + DISTINCT identity, none of the payload columns. The
		// field branch resolves through the INDEXED spot slice by design
		// (§5.2.2 ordering) — run [field] first for authoritative results.
		$rows = sqlRows($db, "
			WITH src AS (
				SELECT DISTINCT s.id AS item_id, s.userpkey AS upk,
				       ih.project_id, ih.project_userpkey AS pupk,
				       'field'::text AS subsys, s.modified_at AS src_ts
				FROM strabosamples.samples s
				JOIN strabosamples.sample_subsystem_links l
				  ON l.sample_id = s.id AND l.sample_userpkey = s.userpkey
				 AND l.subsystem = 'field'
				JOIN strabosearch.item_hit ih
				  ON ih.item_type = 'spot' AND ih.project_subsystem = 'field'
				 AND ih.item_id = l.reference_id AND ih.item_userpkey = l.reference_userpkey
				WHERE TRUE$scopeSrc
				UNION
				SELECT DISTINCT s.id, s.userpkey, pm.strabo_id, pm.userpkey,
				       'micro', s.modified_at
				FROM strabosamples.samples s
				JOIN strabosamples.sample_subsystem_links l
				  ON l.sample_id = s.id AND l.sample_userpkey = s.userpkey
				 AND l.subsystem = 'micro'
				JOIN strabomicro.micro_projectmetadata pm
				  ON pm.strabo_id = l.reference_metadata->>'project_strabo_id'
				 AND pm.userpkey = l.reference_userpkey
				WHERE TRUE$scopeSrc
				UNION
				SELECT DISTINCT s.id, s.userpkey, p.uuid, l.reference_userpkey,
				       'exp', s.modified_at
				FROM strabosamples.samples s
				JOIN strabosamples.sample_subsystem_links l
				  ON l.sample_id = s.id AND l.sample_userpkey = s.userpkey
				 AND l.subsystem = 'experimental'
				JOIN straboexp.project p
				  ON p.uuid = l.reference_metadata->>'project_uuid'
				WHERE TRUE$scopeSrc
			), idx AS (
				SELECT item_id, item_userpkey AS upk, project_id,
				       project_userpkey AS pupk, project_subsystem AS subsys,
				       source_modified
				FROM strabosearch.item_hit
				WHERE item_type = 'sample'$scopeIdx
			)
			SELECT coalesce(src.item_id, idx.item_id) AS item_id,
			       coalesce(src.upk, idx.upk)         AS upk,
			       coalesce(src.project_id, idx.project_id) AS project_id,
			       coalesce(src.subsys, idx.subsys)   AS subsys,
			       CASE WHEN idx.item_id IS NULL THEN 'missing'
			            WHEN src.item_id IS NULL THEN 'extra'
			            ELSE 'stale' END AS kind
			FROM src FULL OUTER JOIN idx
			  ON idx.item_id = src.item_id AND idx.upk = src.upk
			 AND idx.project_id = src.project_id AND idx.pupk = src.pupk
			 AND idx.subsys = src.subsys
			WHERE idx.item_id IS NULL OR src.item_id IS NULL
			   OR (src.src_ts IS NOT NULL AND idx.source_modified IS NOT NULL
			       AND src.src_ts > idx.source_modified + interval '2 seconds')", $failed);

		// touchSample recomputes ALL of a sample's fan-out rows from current
		// links — one heal covers every drifted row of that sample (and
		// spine deletion sweeps to zero).
		$driftSamples = array();   // "id|upk" → drifted row count
		foreach ($rows as $r) {
			$checkDrift++;
			$k = (string)$r->item_id . '|' . (int)$r->upk;
			$driftSamples[$k] = isset($driftSamples[$k]) ? $driftSamples[$k] + 1 : 1;
			detailLine($printed, "$r->kind: sample $r->item_id ($r->subsys project $r->project_id)");
		}
		line('  drifted rows: ' . $checkDrift . ' across ' . count($driftSamples) . ' sample(s)');

		if ($HEAL) {
			foreach ($driftSamples as $k => $n) {
				list($sidK, $upk) = explode('|', $k);
				if (!healBudgetTake($n)) { $UNHEALED += $n; continue; }
				healResult(StraboSearchSync::touchSample($db, $sidK, (int)$upk), $n,
					"touchSample $sidK/u$upk");
			}
		} else {
			$UNHEALED += $checkDrift;
		}

		$DRIFT += $checkDrift;
		finishCheck('samples', $checkDrift, $failed);
	}
}

// ---------------------------------------------------------------------------
// [acl] project_ispublic denorm vs source of truth (grouped, healable
// counterpart of census/audit_acl_denorm.php)
// ---------------------------------------------------------------------------

if (in_array('acl', $ONLY, true)) {
	subsection('[acl] project_ispublic denorm vs source flags');
	$failed = false;
	$checkDrift = 0;
	$printed = 0;
	$missingSourceRows = 0;

	// Grouped so a duplicated source project row (the known dup-node /
	// dup-upload class) can never fan a drift group into multiple rows;
	// bool_or = "any copy says public".
	$sources = array(
		'field' => "SELECT strabo_project_id AS k, user_pkey AS u,
		                   bool_or(coalesce(ispublic, false)) AS pub
		            FROM public.project GROUP BY 1, 2",
		'micro' => "SELECT strabo_id AS k, userpkey AS u,
		                   bool_or(coalesce(ispublic, false)) AS pub
		            FROM strabomicro.micro_projectmetadata GROUP BY 1, 2",
		'exp'   => "SELECT uuid AS k, userpkey AS u,
		                   bool_or(coalesce(ispublic, false)) AS pub
		            FROM straboexp.project GROUP BY 1, 2",
	);
	$targets = array(
		array('item_hit',  'project_subsystem', array('field', 'micro', 'exp')),
		array('image_hit', 'image_subsystem',   array('field', 'micro')),
	);

	$heals = array();   // "subsys|pid|upk" → correct flag (dedupe across tables —
	                    // touchProjectMeta fixes item_hit AND image_hit in one call)
	foreach ($targets as $t) {
		list($table, $subsysCol, $subsystems) = $t;
		foreach ($subsystems as $subsys) {
			$scopeSql = ($SCOPE_UPK !== null) ? " AND project_userpkey = $SCOPE_UPK" : '';
			$rows = sqlRows($db, "
				SELECT g.project_id, g.project_userpkey, g.project_ispublic, g.nrows,
				       src.pub AS src_public, (src.k IS NULL) AS missing_src
				FROM (SELECT project_id, project_userpkey, project_ispublic, count(*) AS nrows
				      FROM strabosearch.$table
				      WHERE $subsysCol = '$subsys'$scopeSql
				      GROUP BY 1, 2, 3) g
				LEFT JOIN ({$sources[$subsys]}) src
				  ON src.k = g.project_id AND src.u = g.project_userpkey
				WHERE src.k IS NULL
				   OR g.project_ispublic IS DISTINCT FROM coalesce(src.pub, false)", $failed);
			foreach ($rows as $r) {
				if ($r->missing_src === 't' || $r->missing_src === true) {
					// Known class (detached subtrees / PG-mirror gaps) —
					// extract defaulted these private = safe. Report only.
					$missingSourceRows += (int)$r->nrows;
					continue;
				}
				$n = (int)$r->nrows;
				$checkDrift += $n;
				$srcPub = ($r->src_public === 't' || $r->src_public === true);
				detailLine($printed, "$table/$subsys project $r->project_id/u$r->project_userpkey: "
					. "$n row(s) flagged " . ($r->project_ispublic === 't' || $r->project_ispublic === true ? 'public' : 'private')
					. ', source says ' . ($srcPub ? 'public' : 'private'));
				$k = "$subsys|$r->project_id|$r->project_userpkey";
				if (!isset($heals[$k])) {
					$heals[$k] = array('flag' => $srcPub, 'rows' => 0);
				}
				$heals[$k]['rows'] += $n;
			}
		}
	}
	line("  ACL drift rows: $checkDrift; missing-source rows (defaulted private, informational): $missingSourceRows");

	if ($HEAL) {
		foreach ($heals as $k => $info) {
			list($subsys, $pidK, $upk) = explode('|', $k);
			$n = $info['rows'];
			if (!healBudgetTake($n)) { $UNHEALED += $n; continue; }
			healResult(
				StraboSearchSync::touchProjectMeta($db, $subsys, $pidK, (int)$upk, null, $info['flag']),
				$n, "touchProjectMeta $subsys $pidK/u$upk ispublic=" . ($info['flag'] ? 'true' : 'false'));
		}
	} else {
		$UNHEALED += $checkDrift;
	}

	$DRIFT += $checkDrift;
	finishCheck('acl', $checkDrift, $failed);
}

// ---------------------------------------------------------------------------
// Summary + exit code
// ---------------------------------------------------------------------------

section('SUMMARY');
foreach ($CHECK_STATUS as $name => $status) {
	line(sprintf('  %-8s %s', $name . ':', strtoupper($status)));
}
line();
line("  drift rows found:   $DRIFT");
if ($HEAL || $HEALED || $HEAL_FAILED) {
	line("  healed:             $HEALED");
	line("  heal failures:      $HEAL_FAILED");
}
line("  unhealed:           $UNHEALED" . ($HEAL_BUDGET === 0 ? '  (heal budget exhausted — re-run extractors for bulk drift)' : ''));
line("  check errors:       $CHECK_ERRORS");

if ($CHECK_ERRORS > 0 || $UNHEALED > 0 || $HEAL_FAILED > 0) {
	line();
	line('VERIFY EXTENDED: FAIL (drift or errors present at exit)');
	exit(2);
}
if ($DRIFT > 0) {
	line();
	line('VERIFY EXTENDED: HEALED (drift found and repaired — a write hook failed since the last run; check error_log for [strabosearch-sync] lines)');
	exit(1);
}
line();
line('VERIFY EXTENDED: PASS');
exit(0);
