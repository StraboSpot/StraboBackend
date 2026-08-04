<?php
/**
 * File: heal_ownerless_projects.php
 * Description: Detect and repair Field projects missing their
 *              (:User)-[:HAS_PROJECT]->(:Project) ownership edge.
 *
 *              The edge is the authoritative ownership marker: the
 *              StraboSearch field extractor (and every anchored walk)
 *              reaches projects ONLY through it. insertProject stopped
 *              creating it on 2025-12-06 (commit 6035f73, collab work) —
 *              restored 2026-08-04 — so every app-created project in that
 *              window is invisible to search while remaining fully
 *              functional in the app and dashboards (those match on the
 *              p.userpkey property instead).
 *
 *              Classification (per ownerless project):
 *                HEALABLE   p.userpkey matches an existing :User AND that
 *                           user has no OTHER owned Project node with the
 *                           same strabo id → CREATE UNIQUE the edge.
 *                DUP-SHADOW the same user already owns another node with
 *                           this id — the parked prod duplicate-node
 *                           population; healing it would index duplicate
 *                           content. Reported, never touched. (Same id
 *                           under a DIFFERENT user is legitimate — Strabo
 *                           ids are not globally unique — and healable.)
 *                NO-USER    no :User carries p.userpkey. Reported only.
 *
 *              Detect-only by default; --apply creates the edges. After
 *              an --apply run, re-extract field + refresh vocab so the
 *              healed projects' spots enter the index:
 *                php extractors/field.php --apply
 *                php extractors/refresh_vocab.php
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/searchdb/heal_ownerless_projects.php [--apply] [--userpkey=N]
 *
 *              Exit codes: 0 = clean (or all healable healed);
 *                          1 = healable drift found (detect mode);
 *                          2 = execution failure.
 *
 * @package    StraboSpot Web Site — StraboSearch
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit("CLI only\n");
}

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../includes/config.inc.php';
require_once __DIR__ . '/../neodb.php';

$APPLY = in_array('--apply', $argv, true);
$SCOPE_UPK = null;
foreach ($argv as $a) {
	if (strpos($a, '--userpkey=') === 0) $SCOPE_UPK = (int)substr($a, 11);
	if ($a === '--help' || $a === '-h') {
		echo "Usage: php heal_ownerless_projects.php [--apply] [--userpkey=N]\n";
		echo "  --apply       Create the missing HAS_PROJECT edges (default: detect only).\n";
		echo "  --userpkey=N  Restrict to one owner (hermetic test scope).\n";
		exit(0);
	}
}

$scopeCy = ($SCOPE_UPK !== null) ? " AND toInt(p.userpkey) = $SCOPE_UPK" : '';

// One pass: every ownerless project + whether its owner exists and
// whether an owned same-id sibling exists (dup shadow). userpkey/id are
// stored as Long OR String depending on write path era — toInt() for the
// owner compare, and NO Cypher ORDER BY (mixed types make Neo4j 3.0
// throw "Don't know how to compare"); sorted in PHP below.
$rows = $neodb->get_results(
	"MATCH (p:Project) " .
	"WHERE NOT (:User)-[:HAS_PROJECT]->(p) AND exists(p.userpkey)$scopeCy " .
	"OPTIONAL MATCH (u:User) WHERE u.userpkey = toInt(p.userpkey) " .
	"OPTIONAL MATCH (q:Project) " .
	"  WHERE toString(q.id) = toString(p.id) AND id(q) <> id(p) " .
	"    AND toInt(q.userpkey) = toInt(p.userpkey) " .
	"    AND (:User)-[:HAS_PROJECT]->(q) " .
	"WITH p, count(DISTINCT u) AS nusers, count(DISTINCT q) AS nsiblings " .
	"RETURN id(p) AS pid, p.id AS strabo_id, p.userpkey AS upk, " .
	"       p.desc_project_name AS name, nusers, nsiblings");

$healable = array();
$dupShadow = 0;
$noUser = 0;

foreach ($rows as $r) {
	$pid = $r->get('pid');
	$upk = $r->get('upk');
	$sid = $r->get('strabo_id');
	$name = $r->get('name');
	if ((int)$r->get('nsiblings') > 0) {
		$dupShadow++;
		echo "DUP-SHADOW  upk=$upk  id=$sid  \"$name\" (owned sibling exists — parked dup cleanup)\n";
	} elseif ((int)$r->get('nusers') === 0) {
		$noUser++;
		echo "NO-USER     upk=$upk  id=$sid  \"$name\"\n";
	} else {
		$healable[] = array('pid' => $pid, 'upk' => $upk, 'sid' => $sid, 'name' => $name);
	}
}

usort($healable, function ($a, $b) {
	return array((int)$a['upk'], (string)$a['sid']) <=> array((int)$b['upk'], (string)$b['sid']);
});

echo "\nownerless (with userpkey" . ($SCOPE_UPK !== null ? ", upk=$SCOPE_UPK" : '') . "): "
	. count($rows) . "  =  healable " . count($healable)
	. " + dup-shadow $dupShadow + no-user $noUser\n";

if (!$healable) {
	echo $APPLY ? "nothing to heal.\n" : "no healable drift.\n";
	exit(0);
}

if (!$APPLY) {
	foreach ($healable as $h) {
		echo "HEALABLE    upk={$h['upk']}  id={$h['sid']}  \"{$h['name']}\"\n";
	}
	echo "\ndetect-only. Re-run with --apply to create the edges, then:\n";
	echo "  php " . __DIR__ . "/extractors/field.php --apply\n";
	echo "  php " . __DIR__ . "/extractors/refresh_vocab.php\n";
	exit(1);
}

$created = 0;
$failed = 0;
foreach ($healable as $h) {
	// First :User with that userpkey — same resolution insertProject uses.
	$uid = $neodb->get_var("MATCH (u:User) WHERE u.userpkey = {$h['upk']} RETURN id(u)");
	if ($uid === null || $uid === '') { $failed++; continue; }
	$neodb->query("MATCH (u:User), (p:Project) WHERE id(u) = $uid AND id(p) = {$h['pid']} " .
		"CREATE UNIQUE (u)-[:HAS_PROJECT]->(p)");
	$created++;
	echo "HEALED      upk={$h['upk']}  id={$h['sid']}  \"{$h['name']}\"\n";
}

echo "\ncreated $created edge(s)" . ($failed ? ", $failed FAILED user lookups" : '') . ".\n";
echo "NEXT: re-extract so the healed projects enter the index:\n";
echo "  php " . __DIR__ . "/extractors/field.php --apply\n";
echo "  php " . __DIR__ . "/extractors/refresh_vocab.php\n";
exit($failed ? 2 : 0);
