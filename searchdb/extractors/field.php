<?php
/**
 * StraboSearch Phase 2 — Field extractor (item_hit.item_type='spot').
 *
 * First sub-branch of Phase 2's extractor unit per MASTER_PLAN.md.
 * Implements §5.2.1 of DESIGN_PROPOSAL.md for the Field row of the table:
 *
 *   Source: Neo4j (the canonical store — per Phase 0.2's mirror disqualification).
 *   Writes: strabosearch.item_hit, one row per Spot tagged item_type='spot'.
 *   Defers: image_hit population — Field's :Image extraction ships in a
 *           follow-on commit on this same branch.
 *
 * ## Algorithm
 *
 *   1. Walk the spot graph ANCHORED THROUGH (:User)-[:HAS_PROJECT]->(:Project).
 *      Strabo ids (Project/Dataset/Spot.id) are NOT unique across the graph
 *      — the same id can exist on multiple nodes owned by different users
 *      (shapefile imports, "starter project" workflows, class scenarios).
 *      The (:User)-[:HAS_PROJECT]->(:Project) edge IS the authoritative
 *      ownership relationship. Matching Project/Dataset by id alone would
 *      cross-product across all same-id nodes; chaining through User scopes
 *      every node correctly. See project_neo4j_user_anchored_walks.
 *
 *      Iterate every User with at least one HAS_PROJECT edge, then for
 *      each user traverse
 *      (User)-[:HAS_PROJECT]->(Project)-[:HAS_DATASET]->(Dataset)-[:HAS_SPOT]->(Spot)
 *      with OPTIONAL MATCH on (Spot)-[:IS_TAGGED]->(:Tag {type:'geologic_unit'}).
 *
 *   2. Per Spot, parse:
 *        - identity:       spot.id + spot.userpkey
 *        - project ctx:    project.id, .userpkey, .ispublic, .name
 *        - universal core: location (centroid of spot.wkt), date_value
 *                          (from properties.date or modified_timestamp),
 *                          searchtext_tsv (recursive flatten via
 *                          flattenKeywords + project name + dataset name +
 *                          spot notes, matching the legacy getKeywords source)
 *        - field exts:     orientation_strike/dip/trend/plunge/features/planar
 *                          from json_orientation_data (and bare orientation_data
 *                          per the Phase 0.2 dual-conventions finding);
 *                          rock_types + met_facies from the geologic_unit tags
 *                          (hierarchy assembled per the legacy assembler at
 *                          strabospotclass.php:5965);
 *                          trace_types from json_trace
 *        - has_* flags:    from blob presence + the IS_TAGGED presence test
 *        - sample-spine:   left NULL (Samples extractor fills item_type='sample' rows)
 *
 *   3. Stage to strabosearch.item_hit_staging_field (created on demand —
 *      same column shape as item_hit), then atomic-swap the Field slice
 *      into the live table per §5.2.3 (DELETE WHERE project_subsystem='field'
 *      AND item_type='spot' + INSERT SELECT + TRUNCATE staging, in one txn).
 *
 *   4. Update strabosearch.sync_state.last_full_backfill for 'field'.
 *
 * ## CLI
 *
 *   docker exec strabo-php php /srv/app/www/searchdb/extractors/field.php
 *
 *   Flags:
 *     --apply              Required for writes. Without it, runs dry.
 *     --resume-from=<upk>  Skip userpkeys < upk. Recovery after a crash.
 *     --source-userpkey=N  Limit to a single user — test isolation.
 *     --batch-size=N       Spots per Cypher pull (default 1000 — full-node
 *                          pulls must stay small per the Phase 0.3 probe).
 *     --no-swap            Build staging only; skip the atomic swap (debug).
 *
 * @package StraboSearch Phase 2 extractors
 */

chdir(__DIR__ . '/../../');
// Bolt client allocates the full receive buffer for each Cypher response.
// Field's biggest spots (polygon hand-drawn boundaries with thousands of
// vertices + 100KB-truncated blobs) can cumulatively allocate multi-GB
// per batch when a few outlier datasets show up. 8G is the safety ceiling
// observed on the ~06-10 prod restore. PHP CLI default of 1G OOMs on the
// outlier datasets — bump unconditionally.
ini_set('memory_limit', '8G');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
require_once(__DIR__ . '/_extractor_lib.php');
require_once(__DIR__ . '/_row_builders.php');

$APPLY        = in_array('--apply', $argv, true);
$NO_SWAP      = in_array('--no-swap', $argv, true);
// Spots-per-dataset cap on the per-dataset pull. Bolt OOMs on big result
// sets — most datasets are small but a handful carry multi-MB blobs per
// spot (multi-thousand-vertex polygon WKTs + truncated orientation blobs).
// 50 + 8G heap is empirically the sweet spot: completes the full
// 1.67M-spot prod restore in ~14 min. Drop to --batch-size=25 if the
// outlier datasets fire OOM mid-run (non-deterministic — depends on
// Neo4j cache state); use --resume-from to continue past the OOM point.
$BATCH_SIZE   = 50;
$RESUME_FROM  = 0;
$SINGLE_USER  = null;
foreach ($argv as $arg) {
	if (preg_match('/^--batch-size=(\d+)$/', $arg, $m))       $BATCH_SIZE  = (int)$m[1];
	if (preg_match('/^--resume-from=(\d+)$/', $arg, $m))      $RESUME_FROM = (int)$m[1];
	if (preg_match('/^--source-userpkey=(\d+)$/', $arg, $m))  $SINGLE_USER = (int)$m[1];
}

$t0 = microtime(true);
line('FIELD EXTRACTOR — ' . date('Y-m-d H:i:s'));
line('  mode:        ' . ($APPLY ? 'APPLY' : 'DRY RUN (no writes)'));
line('  batch_size:  ' . $BATCH_SIZE);
if ($RESUME_FROM)  line('  resume_from: userpkey >= ' . $RESUME_FROM);
if ($SINGLE_USER !== null) line('  single user: userpkey = ' . $SINGLE_USER);
if ($NO_SWAP)      line('  --no-swap:   staging will not be swapped into live');

// ---------------------------------------------------------------------------
section('1. Staging table prep');

// Same shape as item_hit. CREATE TABLE LIKE keeps the column list in lockstep
// with whatever the install DDL declares. Defaults + identities are NOT
// inherited (which is what we want — staging has no PK).
//
// --resume-from preserves the existing staging table so a crash-and-resume
// run accumulates into it rather than starting over from empty.
if ($RESUME_FROM > 0) {
	$db->query("CREATE TABLE IF NOT EXISTS strabosearch.item_hit_staging_field
		(LIKE strabosearch.item_hit INCLUDING DEFAULTS)");
	// Older runs may have an item_hit_pkey column — drop if so for shape match.
	$db->query("ALTER TABLE strabosearch.item_hit_staging_field DROP COLUMN IF EXISTS item_hit_pkey");
	line('  staging table preserved (--resume-from set)');
} else {
	$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_field");
	$db->query("CREATE TABLE strabosearch.item_hit_staging_field
		(LIKE strabosearch.item_hit INCLUDING DEFAULTS)");
	$db->query("ALTER TABLE strabosearch.item_hit_staging_field DROP COLUMN item_hit_pkey");
	line('  staging table created (fresh): strabosearch.item_hit_staging_field');
}

// INSERT column list excludes the PK + last_synced (defaulted). Single
// source of truth shared with the sync path — see _row_builders.php.
$INSERT_COLS = fieldItemCols();
$buf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.item_hit_staging_field (" . implode(', ', $INSERT_COLS) . ") VALUES ",
	500);

// ---------------------------------------------------------------------------
section('2. Pre-fetch PG project metadata');

// Project.ispublic in Neo4j is NULL everywhere. The source of truth is the
// PG `project` table (~8299 rows on prod), which the upload/create API
// path keeps current. Build an in-memory map for O(1) per-spot lookup.
// Keyed by (strabo_project_id, user_pkey): Strabo project ids are NOT
// unique across users, and id-only keying let another user's flag win
// (found by the 2026-08-01 ACL denorm audit — 14 projects drifted).
$pgPubMap = array();
$rows = $db->get_results("SELECT strabo_project_id, user_pkey, ispublic FROM project");
foreach ((array)$rows as $r) {
	$pgPubMap[(string)$r->strabo_project_id . '|' . (int)$r->user_pkey] =
		($r->ispublic === 't' || $r->ispublic === true);
}
line('  pg project rows loaded: ' . number_format(count($pgPubMap)));
$pubCount = 0;
foreach ($pgPubMap as $v) if ($v) $pubCount++;
line('  public projects:         ' . number_format($pubCount));

// ---------------------------------------------------------------------------
section('3. Walk Neo4j by userpkey');

// Anchor every traversal through (:User {userpkey: $upk})-[:HAS_PROJECT]->(:Project).
// Per project_neo4j_user_anchored_walks: Strabo ids (Project/Dataset/Spot.id)
// are NOT unique across the graph — the same id can exist on multiple nodes
// owned by different users (shapefile imports, "starter project" workflows,
// class scenarios). Matching by id alone returns ALL same-id nodes; the
// (:User)-[:HAS_PROJECT]->(:Project) edge IS the authoritative ownership
// relationship and is what scopes the walk correctly. Property filters on
// Project.userpkey are wrong (the property is unreliable and ignores the
// graph's actual ownership semantics).
//
// Per-dataset queries stay bounded since most datasets carry <1000 spots;
// the inner loop keyset-paginates by s.id when a dataset is unexpectedly
// large.

if ($SINGLE_USER !== null) {
	$activeUsers = array($SINGLE_USER);
} else {
	$rows = $neodb->query(
		"MATCH (u:User)-[:HAS_PROJECT]->(:Project) " .
		"RETURN distinct toInt(u.userpkey) AS upk ORDER BY upk"
	);
	$activeUsers = array();
	foreach ($rows as $r) {
		$u = (int)$r->get('upk');
		if ($u >= $RESUME_FROM) $activeUsers[] = $u;
	}
	line('  users with projects: ' . count($activeUsers));
}

$totalSpots   = 0;
$totalUsers   = 0;
$totalSkipped = 0;
$parseFail    = 0;
$tagTypeVocabSeen = array();   // observed Tag.type values → vocab_tag_type (F11)
$tStart       = microtime(true);

foreach ($activeUsers as $upk) {
	// Get this user's (project, dataset) pairs via the User node's
	// HAS_PROJECT + HAS_DATASET edges. Per project_neo4j_user_anchored_walks:
	// scope by the edge, not by Project.userpkey property.
	//
	// Project name lives in `desc_project_name` (canonical, 99.9% populated)
	// with a legacy `projectname` on older nodes (~9% of projects). Project
	// nodes do NOT carry a `name` property — use coalesce.
	$pdRows = $neodb->query(
		"MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset) " .
		"RETURN p.id AS pid, coalesce(p.desc_project_name, p.projectname) AS pname, " .
		"       substring(toString(p.json_tags), 0, 1000000) AS pjt, " .
		"       d.id AS did, d.name AS dname"
	);
	if (!$pdRows) continue;

	$nUserSpots = 0;

	foreach ($pdRows as $pd) {
		$pid    = $pd->get('pid');
		// Project owner = the User we're iterating (HAS_PROJECT is owner-only).
		$puk    = $upk;
		$pname  = $pd->get('pname');
		$did    = $pd->get('did');
		$dname  = $pd->get('dname');

		if ($pid === null || $did === null) continue;

		// json_tags amendment: tags join to spots in PHP via the project's
		// spot_id → tags map, decoded once per (project, dataset) pair.
		$tagMap = fieldTagMapFromJsonTags($pd->get('pjt'));

		// Keyset-paginate by s.id within a dataset (handles the rare
		// pathologically large dataset without re-scan penalty).
		$pidLit = neoIdLiteral($pid);
		$didLit = neoIdLiteral($did);
		$cursor = null;
		while (true) {
			$cursorClause = '';
			if ($cursor !== null) {
				$cursorClause = "AND s.id > " . neoIdLiteral($cursor) . " ";
			}
			// Query + payload rationale documented on fieldSpotBatchCypher
			// in _row_builders.php (shared with the sync touch path).
			$rows = $neodb->query(
				fieldSpotBatchCypher($upk, $pidLit, $didLit, $cursorClause, $BATCH_SIZE));
			if (!$rows) break;

			foreach ($rows as $r) {
				$spotId = $r->get('sid');
				if ($spotId === null) { $totalSkipped++; continue; }
				$cursor = $spotId;  // preserve original type for keyset comparison

				// ispublic comes from the pre-fetched PG project map (Neo4j
				// Project.ispublic is NULL everywhere on prod). Unknown projects
				// (not yet in PG mirror) default to private.
				$pispubBool = !empty($pgPubMap[(string)$pid . '|' . (int)$puk]);

				// Row → tuple mapping is shared with the sync touch path —
				// see fieldSpotTuple in _row_builders.php. dataset_ids =
				// this outer dataset; the swap's conflict action unions
				// multi-dataset paths (dataset_ids amendment).
				$values = fieldSpotTuple($r, $pid, $puk, $pname, $dname,
					$pispubBool, $tagMap, $tagTypeVocabSeen, array($did));

				if ($APPLY) {
					$ret = $buf->add($values);
					// add() drains poison batches and returns < 0 — log once per batch.
					if ($ret === false || $ret < 0) {
						line('  batch dropped (' . abs((int)$ret) . ' rows) — ' . $buf->lastError);
						$parseFail += abs((int)$ret);
					}
				}
				$nUserSpots++;
				$totalSpots++;
			}

			// Keyset-paginate: stop when the last batch was short (no more spots).
			if (count($rows) < $BATCH_SIZE) break;
		}
	}

	if ($nUserSpots > 0) {
		$totalUsers++;
		if ($totalUsers % 25 === 0 || $SINGLE_USER !== null) {
			progress(sprintf('users=%d, spots=%s, elapsed=%.0fs',
				$totalUsers, number_format($totalSpots), microtime(true) - $tStart));
		}
	}
}

if ($APPLY) $buf->flush();

// ---------------------------------------------------------------------------
section('4. Staging summary');

$stagingCount = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit_staging_field");
line(sprintf('  spots walked:        %s', number_format($totalSpots)));
line(sprintf('  users walked:        %d', $totalUsers));
line(sprintf('  spots skipped:       %d (missing id)', $totalSkipped));
line(sprintf('  parse failures:      %d', $parseFail));
line(sprintf('  staging rows:        %s', number_format($stagingCount)));
line(sprintf('  pull elapsed:        %.1fs', microtime(true) - $tStart));

// ---------------------------------------------------------------------------
section('5. Atomic swap (§5.2.3)');

if (!$APPLY) {
	line('  (skipped — --apply not passed)');
} elseif ($NO_SWAP) {
	line('  (skipped — --no-swap passed; staging retained)');
} else {
	// When --source-userpkey limits the run to one user, narrow the slice so
	// other users' Field rows are NOT deleted. Without that, a partial run
	// would nuke everything else under project_subsystem='field'.
	if ($SINGLE_USER !== null) {
		$slice = "project_subsystem = 'field' AND item_type = 'spot' AND project_userpkey = " . (int)$SINGLE_USER;
		line('  (slice scoped to project_userpkey = ' . (int)$SINGLE_USER . ')');
	} else {
		$slice = "project_subsystem = 'field' AND item_type = 'spot'";
	}
	// dataset_ids amendment: duplicate staging rows (same spot reached via
	// two Datasets) union their dataset_ids instead of dropping the second
	// path — the slice was deleted first in the swap txn, so the union only
	// ever merges fresh same-run rows.
	$ins = swapStagingInto($db, 'strabosearch.item_hit', 'strabosearch.item_hit_staging_field',
		$slice, $INSERT_COLS,
		array('item_type', 'item_id', 'item_userpkey', 'project_id', 'project_userpkey', 'project_subsystem'),
		"DO UPDATE SET dataset_ids = (
			SELECT array_agg(DISTINCT x) FROM unnest(
				coalesce(live.dataset_ids, '{}'::text[]) || coalesce(EXCLUDED.dataset_ids, '{}'::text[])
			) AS x)");
	if ($ins === null) {
		line('  SWAP FAILED: ' . $db->last_error);
		exit(1);
	}
	line(sprintf('  swapped:  %s rows into item_hit', number_format($ins)));

	// vocab_tag_type refresh under subsystem='field' (F11 live vocab —
	// deny-list of NULL/empty applied at collection time).
	if ($tagTypeVocabSeen) {
		$n = upsertVocabTagTypes($db, $tagTypeVocabSeen, 'field');
		line(sprintf('  vocab_tag_type rows upserted: %d', $n));
	}

	if ($SINGLE_USER === null) {
		updateSyncState($db, 'field', $ins);
		line('  sync_state.field updated (last_full_backfill = now)');
	} else {
		line('  sync_state NOT updated (partial run via --source-userpkey)');
	}
}

// ---------------------------------------------------------------------------
line();
line(sprintf('Done in %.1fs.', microtime(true) - $t0));
?>
