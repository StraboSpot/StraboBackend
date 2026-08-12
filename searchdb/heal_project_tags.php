<?php
/**
 * File: heal_project_tags.php
 * Description: Detect and repair stale tag-derived columns in the
 *              StraboSearch index (item_hit.rock_types / met_facies /
 *              tag_names / tag_types, plus the image_hit mirrors).
 *
 *              Why they go stale: tags live ONLY in Project.json_tags
 *              (json_tags amendment), and the index joins them to spots at
 *              extraction time. A PROJECT-ONLY upload that changes
 *              json_tags with no spot/dataset write following (the web
 *              version of the StraboField app saves surgically like this)
 *              historically refreshed nothing but project name/ispublic,
 *              so tag edits never reached the index. insertProject now
 *              propagates them (touchFieldProjectTags, 2026-08-12); this
 *              script heals rows that went stale BEFORE that fix, and
 *              doubles as an ad hoc tag-parity checker.
 *
 *              Per indexed Field project: fetch the authoritative
 *              json_tags via the anchored (:User)-[:HAS_PROJECT]-> walk,
 *              resolve every spot's expected facets with the shared
 *              extractor code, and compare set-wise against the index.
 *              Classification:
 *                MISMATCH       stale spots found; healable via --apply
 *                               (StraboSearchSync::refreshFieldProjectTagColumns,
 *                               which re-extracts small sets per spot and
 *                               bulk-updates large ones).
 *                UNREACHABLE    no anchored walk reaches the project node
 *                               (ownerless; heal_ownerless_projects.php
 *                               territory). Reported, never touched.
 *                DUP-DIVERGENT  multiple anchored Project nodes carry
 *                               DIFFERENT json_tags (parked duplicate-node
 *                               population). Reported, never touched:
 *                               healing would pick a side blindly.
 *
 *              image_hit rows are healed alongside their parent spots; an
 *              image-only drift with a consistent item row is not detected
 *              (both are written by the same paths, so it has no known
 *              producer).
 *
 *              Detect-only by default; --apply performs the refresh.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/searchdb/heal_project_tags.php \
 *                  [--apply] [--userpkey=N] [--project=ID] [--verbose]
 *
 *              Exit codes: 0 = clean (or all mismatches healed);
 *                          1 = drift found (detect mode) or unhealed rows;
 *                          2 = execution failure.
 *
 * @package    StraboSpot Web Site (StraboSearch)
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit("CLI only\n");
}

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../includes/config.inc.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../neodb.php';
require_once __DIR__ . '/extractors/_row_builders.php';
require_once __DIR__ . '/sync/StraboSearchSync.php';

$APPLY = in_array('--apply', $argv, true);
$VERBOSE = in_array('--verbose', $argv, true);
$SCOPE_UPK = null;
$SCOPE_PROJECT = null;
foreach ($argv as $a) {
	if (strpos($a, '--userpkey=') === 0) $SCOPE_UPK = (int)substr($a, 11);
	if (strpos($a, '--project=') === 0)  $SCOPE_PROJECT = substr($a, 10);
	if ($a === '--help' || $a === '-h') {
		echo "Usage: php heal_project_tags.php [--apply] [--userpkey=N] [--project=ID] [--verbose]\n";
		echo "  --apply       Refresh stale tag columns (default: detect only).\n";
		echo "  --userpkey=N  Restrict to one project owner.\n";
		echo "  --project=ID  Restrict to one Strabo project id.\n";
		echo "  --verbose     List every stale spot, not just the first 3 per project.\n";
		exit(0);
	}
}

/** Sorted-copy compare so element order never counts as drift. */
function tagSetEquals($a, $b) {
	sort($a); sort($b);
	return $a === $b;
}

$where = "project_subsystem = 'field' AND item_type = 'spot'";
if ($SCOPE_UPK !== null)     $where .= " AND project_userpkey = $SCOPE_UPK";
if ($SCOPE_PROJECT !== null) $where .= " AND project_id = '" . pg_escape_string($SCOPE_PROJECT) . "'";

$slices = $db->get_results("SELECT project_id, project_userpkey, count(*) AS nspots
	FROM strabosearch.item_hit WHERE $where
	GROUP BY project_id, project_userpkey
	ORDER BY project_userpkey, project_id");
$slices = (array)$slices;
echo "field project slices to check: " . count($slices) . "\n\n";

$checked = 0;
$mismatchProjects = array();
$unreachable = 0;
$dupDivergent = 0;
$healedProjects = 0;
$healFailures = 0;

foreach ($slices as $slice) {
	$pid = $slice->project_id;
	$upk = (int)$slice->project_userpkey;
	$checked++;
	if ($checked % 250 === 0) {
		echo "  ... $checked / " . count($slices) . " checked\n";
	}

	// Authoritative blob via the anchored walk. A failed Cypher poisons the
	// Bolt connection; reconnect before moving on.
	try {
		$rows = $neodb->get_results(
			"MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project {id: " . neoIdLiteral($pid) . "})
			 RETURN substring(toString(p.json_tags), 0, 1000000) AS pjt");
	} catch (\Throwable $e) {
		try { if (method_exists($neodb, 'reconnect')) $neodb->reconnect(); } catch (\Throwable $e2) {}
		echo "CYPHER-FAIL upk=$upk id=$pid: " . $e->getMessage() . "\n";
		$unreachable++;
		continue;
	}
	$rows = is_array($rows) ? $rows : array();
	if (!$rows) {
		$unreachable++;
		echo "UNREACHABLE upk=$upk id=$pid (no anchored walk; see heal_ownerless_projects.php)\n";
		continue;
	}
	$blobs = array();
	foreach ($rows as $r) {
		$blobs[(string)$r->get('pjt')] = true;
	}
	if (count($blobs) > 1) {
		$dupDivergent++;
		echo "DUP-DIVERGENT upk=$upk id=$pid (" . count($rows) . " anchored nodes, "
			. count($blobs) . " distinct json_tags; parked dup cleanup)\n";
		continue;
	}
	$pjt = (string)array_keys($blobs)[0];
	$tagMap = fieldTagMapFromJsonTags($pjt);

	// Fast path: no source tags. Consistent iff no index row carries any.
	$sliceWhere = "project_subsystem = 'field' AND item_type = 'spot'
		AND project_id = '" . pg_escape_string((string)$pid) . "' AND project_userpkey = $upk";
	if (!$tagMap) {
		$staleIds = $db->get_results("SELECT item_id FROM strabosearch.item_hit
			WHERE $sliceWhere AND (tag_names IS NOT NULL OR tag_types IS NOT NULL
			   OR rock_types IS NOT NULL OR met_facies IS NOT NULL)");
		$stale = array();
		foreach ((array)$staleIds as $r) {
			$stale[(string)$r->item_id] = array('have tags', 'expect none');
		}
	} else {
		$idxRows = $db->get_results("SELECT item_id, rock_types, met_facies, tag_names, tag_types
			FROM strabosearch.item_hit WHERE $sliceWhere");
		$stale = array();
		$vocabIgnored = array();
		foreach ((array)$idxRows as $r) {
			$sid = (string)$r->item_id;
			list($expRock, $expFacies, $expNames, $expTypes)
				= fieldTagsForSpot($tagMap, $sid, $vocabIgnored);
			if (!tagSetEquals(pgParseTextArray($r->tag_names), $expNames)
				|| !tagSetEquals(pgParseTextArray($r->tag_types), $expTypes)
				|| !tagSetEquals(pgParseTextArray($r->rock_types), $expRock)
				|| !tagSetEquals(pgParseTextArray($r->met_facies), $expFacies)) {
				$stale[$sid] = array(
					'have [' . implode(',', pgParseTextArray($r->tag_names)) . ']',
					'expect [' . implode(',', $expNames) . ']');
			}
		}
	}

	if (!$stale) continue;

	$staleIds = array_keys($stale);
	$mismatchProjects[] = array('pid' => $pid, 'upk' => $upk, 'ids' => $staleIds, 'pjt' => $pjt);
	echo "MISMATCH    upk=$upk id=$pid: " . count($staleIds) . " of {$slice->nspots} spot(s) stale\n";
	$shown = 0;
	foreach ($stale as $sid => $d) {
		if (!$VERBOSE && $shown >= 3) {
			echo "              ... " . (count($stale) - $shown) . " more (use --verbose)\n";
			break;
		}
		echo "              spot $sid: {$d[0]} {$d[1]}\n";
		$shown++;
	}

	if ($APPLY) {
		$ok = StraboSearchSync::refreshFieldProjectTagColumns(
			$db, $neodb, $pid, $upk, $pjt, $staleIds);
		if ($ok === true) {
			$healedProjects++;
			echo "HEALED      upk=$upk id=$pid (" . count($staleIds) . " spot(s) refreshed)\n";
		} else {
			$healFailures++;
			echo "HEAL-FAIL   upk=$upk id=$pid (see [strabosearch-sync] in the error log)\n";
		}
	}
}

echo "\nchecked $checked slice(s): " . count($mismatchProjects) . " mismatch, "
	. "$unreachable unreachable, $dupDivergent dup-divergent"
	. ($APPLY ? ", $healedProjects healed, $healFailures heal-failure(s)" : '') . "\n";

if (!$mismatchProjects && !$unreachable && !$dupDivergent) {
	echo "clean.\n";
	exit(0);
}
if ($APPLY) {
	exit($healFailures > 0 ? 1 : 0);
}
echo "detect-only. Re-run with --apply to refresh the stale columns.\n";
exit(1);
