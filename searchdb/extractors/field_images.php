<?php
/**
 * StraboSearch Phase 2 — Field image extractor (image_hit, image_subsystem='field').
 *
 * Follow-on to searchdb/extractors/field.php on the same Field source.
 * Implements §5.2.1 of DESIGN_PROPOSAL.md for the Field row of the image_hit
 * column:
 *
 *   Source: Neo4j :Image nodes reachable via (Spot)-[:HAS_IMAGE]->.
 *           Per the 0.4 audit: 563,202 :Image nodes, ≈520k servable
 *           (reachable, with filename). 43k orphans / 9k missing-filename
 *           are not emitted to image_hit (filename is the §5.5 servability
 *           gate).
 *   Writes: strabosearch.image_hit, one row per servable :Image tagged
 *           image_subsystem='field'.
 *
 * ## Algorithm
 *
 *   1. Walk the spot→image graph ANCHORED THROUGH (:User)-[:HAS_PROJECT]->
 *      (:Project), same anchor as field.php — Strabo ids are not unique
 *      across the graph and the HAS_PROJECT edge is the authoritative
 *      ownership relationship. See project_neo4j_user_anchored_walks.
 *
 *      For each user:
 *      (User)-[:HAS_PROJECT]->(Project)-[:HAS_DATASET]->(Dataset)
 *            -[:HAS_SPOT]->(Spot)-[:HAS_IMAGE]->(Image)
 *      OPTIONAL MATCH (Spot)-[:IS_TAGGED]->(:Tag {type:'geologic_unit'})
 *      so the Field-extension columns inherited from the parent spot are
 *      filled in one shot.
 *
 *   2. Per (Spot, Image) pair, emit one image_hit row carrying:
 *        - identity: image.id + image.userpkey + image_subsystem='field'
 *        - image-specific facets:
 *            image_type   normalized via the §4.5 vocab mapping
 *                         (photo / sketch / thin_section / outcrop / sample
 *                         / other catchall — vocab_image_type table is
 *                         upserted with every (raw → unified) tuple seen)
 *            annotated    coerced from the stored string "1"/""/null
 *            title        verbatim
 *            caption      verbatim
 *            filename     verbatim  (filename is the §5.5 servability gate;
 *                         images with no filename are skipped entirely —
 *                         they are unservable, per the 0.4 audit)
 *            imagetext_tsv  to_tsvector(title + ' ' + caption)
 *        - parent context (denormalized for §6 Q4a + Q4b inheritance):
 *            parent_spot_id      spot.id
 *            parent_sample_id    NULL  (filled later by samples extractor
 *                                       fan-out)
 *            project_id / userpkey / subsystem / ispublic
 *                                — ispublic comes from the pre-fetched PG
 *                                project map (Neo4j project.ispublic is
 *                                NULL everywhere — same exception as
 *                                field.php)
 *        - inherited universal-core (§6 Q4a):
 *            location     spot.wkt centroid (POLYGON/LINESTRING/POINT all
 *                         collapse to a single Point — image_hit.location
 *                         is geometry(Point, 4326))
 *            date_value   parsed from spot.date (validated ISO-8601 prefix)
 *                         fallback to spot.modified_timestamp epoch.
 *                         NOTE: the image node carries its own modified_
 *                         timestamp, but date_value tracks WHEN THE OBSERVA-
 *                         TION HAPPENED — that's the spot's date, not the
 *                         image record's. source_modified separately tracks
 *                         the image record's mtime for sync purposes.
 *        - inherited Field-extension columns (§6 Q4b):
 *            orientation_strike/dip/trend/plunge/features/planar
 *            rock_types / met_facies / trace_types
 *                         Parsed from the parent spot's blobs + tags using
 *                         the same logic as field.php. Allows queries like
 *                         "thin-section images of dip>60 outcrops" without
 *                         a query-time join.
 *        - Micro-native columns (minerals, mineral_methods, instrument_type,
 *          detector_type) stay NULL — these are populated by the Micro
 *          extractor for image_subsystem='micro' rows.
 *        - source_modified: image.modified_timestamp (the image record's
 *          last-write), fallback to spot.modified_timestamp.
 *
 *   3. Stage to strabosearch.image_hit_staging_field, then atomic-swap the
 *      Field slice into the live table per §5.2.3
 *      (DELETE WHERE image_subsystem='field' + INSERT SELECT + TRUNCATE
 *      staging, in one transaction). Conflict-soak on the identity unique
 *      constraint mirrors field.php (same image id reachable through more
 *      than one of a single user's projects/datasets — rare but seen).
 *
 *   4. Upsert vocab_image_type rows for every raw (normalized_from, unified)
 *      pair seen on the Field side.
 *
 *   5. Update strabosearch.sync_state.last_full_backfill for 'field' (same
 *      row that field.php writes — last_full_backfill marks the most recent
 *      full pass over the Field source, regardless of which target table
 *      ran).
 *
 * ## Memory + batching
 *
 * Same dynamics as field.php: Bolt receive buffer can balloon on outlier
 * datasets (multi-thousand-vertex polygon WKTs + truncated blobs). 8G heap
 * ceiling is the safe value observed against the ~06-10 prod restore.
 * BATCH_SIZE is the per-pull cap on spots-with-images; the actual row
 * cardinality is BATCH_SIZE × avg-images-per-spot (~1.4 on prod per the
 * 0.4 audit's 554k servable / 400k image-bearing spots).
 *
 * ## CLI
 *
 *   docker exec strabo-php php /srv/app/www/searchdb/extractors/field_images.php
 *
 *   Flags:
 *     --apply              Required for writes. Without it, runs dry.
 *     --resume-from=<upk>  Skip userpkeys < upk. Recovery after a crash.
 *     --source-userpkey=N  Limit to a single user — test isolation.
 *     --batch-size=N       Spots-with-images per Cypher pull (default 50 —
 *                          matches field.php's empirical sweet spot).
 *     --no-swap            Build staging only; skip the atomic swap (debug).
 *
 * @package StraboSearch Phase 2 extractors
 */

chdir(__DIR__ . '/../../');
ini_set('memory_limit', '8G');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
require_once(__DIR__ . '/_extractor_lib.php');
require_once(__DIR__ . '/_row_builders.php');

$APPLY        = in_array('--apply', $argv, true);
$NO_SWAP      = in_array('--no-swap', $argv, true);
$BATCH_SIZE   = 50;
$RESUME_FROM  = 0;
$SINGLE_USER  = null;
foreach ($argv as $arg) {
	if (preg_match('/^--batch-size=(\d+)$/', $arg, $m))       $BATCH_SIZE  = (int)$m[1];
	if (preg_match('/^--resume-from=(\d+)$/', $arg, $m))      $RESUME_FROM = (int)$m[1];
	if (preg_match('/^--source-userpkey=(\d+)$/', $arg, $m))  $SINGLE_USER = (int)$m[1];
}

$t0 = microtime(true);
line('FIELD IMAGE EXTRACTOR — ' . date('Y-m-d H:i:s'));
line('  mode:        ' . ($APPLY ? 'APPLY' : 'DRY RUN (no writes)'));
line('  batch_size:  ' . $BATCH_SIZE);
if ($RESUME_FROM)  line('  resume_from: userpkey >= ' . $RESUME_FROM);
if ($SINGLE_USER !== null) line('  single user: userpkey = ' . $SINGLE_USER);
if ($NO_SWAP)      line('  --no-swap:   staging will not be swapped into live');

// ---------------------------------------------------------------------------
section('1. Staging table prep');

if ($RESUME_FROM > 0) {
	$db->query("CREATE TABLE IF NOT EXISTS strabosearch.image_hit_staging_field
		(LIKE strabosearch.image_hit INCLUDING DEFAULTS)");
	$db->query("ALTER TABLE strabosearch.image_hit_staging_field DROP COLUMN IF EXISTS image_hit_pkey");
	line('  staging table preserved (--resume-from set)');
} else {
	$db->query("DROP TABLE IF EXISTS strabosearch.image_hit_staging_field");
	$db->query("CREATE TABLE strabosearch.image_hit_staging_field
		(LIKE strabosearch.image_hit INCLUDING DEFAULTS)");
	$db->query("ALTER TABLE strabosearch.image_hit_staging_field DROP COLUMN image_hit_pkey");
	line('  staging table created (fresh): strabosearch.image_hit_staging_field');
}

// Column list excludes image_hit_pkey (PK) and last_synced (defaulted).
// Single source of truth shared with the sync path — see _row_builders.php.
// Micro-native cols (minerals/mineral_methods/instrument_type/detector_type)
// are NOT in this list — they stay NULL on Field rows and the Micro
// extractor's swap will project them separately.
$INSERT_COLS = fieldImageCols();
$buf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.image_hit_staging_field (" . implode(', ', $INSERT_COLS) . ") VALUES ",
	500);

// ---------------------------------------------------------------------------
section('2. Pre-fetch PG project metadata');

// Keyed by (strabo_project_id, user_pkey): Strabo project ids are NOT
// unique across users — see the matching fix in field.php.
$pgPubMap = array();
$rows = $db->get_results("SELECT strabo_project_id, user_pkey, ispublic FROM project");
foreach ((array)$rows as $r) {
	$pgPubMap[(string)$r->strabo_project_id . '|' . (int)$r->user_pkey] =
		($r->ispublic === 't' || $r->ispublic === true);
}
line('  pg project rows loaded: ' . number_format(count($pgPubMap)));

// ---------------------------------------------------------------------------
section('3. Walk Neo4j by userpkey');

if ($SINGLE_USER !== null) {
	$activeUsers = array($SINGLE_USER);
} else {
	// Same anchor as field.php — users with HAS_PROJECT edges.
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

$totalImages       = 0;
$totalSpotsWalked  = 0;
$totalUsers        = 0;
$totalSkippedNoFn  = 0;   // images with no filename (per 0.4: unservable)
$totalSkippedNoId  = 0;
$parseFail         = 0;
$vocabSeen         = array();   // raw image_type → unified, deduped for end-of-run upsert
$tStart            = microtime(true);

foreach ($activeUsers as $upk) {
	// Per-user (project, dataset) inventory — same shape as field.php.
	$pdRows = $neodb->query(
		"MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset) " .
		"RETURN p.id AS pid, coalesce(p.desc_project_name, p.projectname) AS pname, " .
		"       substring(toString(p.json_tags), 0, 1000000) AS pjt, " .
		"       d.id AS did, d.name AS dname"
	);
	if (!$pdRows) continue;

	$nUserImages = 0;

	foreach ($pdRows as $pd) {
		$pid    = $pd->get('pid');
		$puk    = $upk;       // HAS_PROJECT edge ⇒ project is owned by $upk
		$pname  = $pd->get('pname');
		$did    = $pd->get('did');
		$dname  = $pd->get('dname');

		if ($pid === null || $did === null) continue;

		// json_tags amendment: per-project spot_id → tags map (see field.php).
		$tagMap = fieldTagMapFromJsonTags($pd->get('pjt'));

		$pidLit = neoIdLiteral($pid);
		$didLit = neoIdLiteral($did);

		$cursor = null;
		while (true) {
			$cursorClause = '';
			if ($cursor !== null) {
				$cursorClause = "AND s.id > " . neoIdLiteral($cursor) . " ";
			}

			// Query rationale documented on fieldImagesBatchCypher in
			// _row_builders.php (shared with the sync touch path).
			$rows = $neodb->query(
				fieldImagesBatchCypher($upk, $pidLit, $didLit, $cursorClause, $BATCH_SIZE));
			if (!$rows) break;

			foreach ($rows as $r) {
				$spotId = $r->get('sid');
				if ($spotId === null) continue;
				$cursor = $spotId;

				$pispubBool = !empty($pgPubMap[(string)$pid . '|' . (int)$upk]);

				$totalSpotsWalked++;

				// Spot-context parse + per-image emission shared with the
				// sync touch path — see fieldImageTuples in _row_builders.php.
				$stats = array('no_filename' => 0, 'no_id' => 0);
				$tuples = fieldImageTuples($r, $spotId, $pid, $puk, $pispubBool,
					$tagMap, $vocabSeen, $stats);
				$totalSkippedNoFn += $stats['no_filename'];
				$totalSkippedNoId += $stats['no_id'];

				foreach ($tuples as $values) {
					if ($APPLY) {
						$ret = $buf->add($values);
						if ($ret === false || $ret < 0) {
							line('  batch dropped (' . abs((int)$ret) . ' rows) — ' . $buf->lastError);
							$parseFail += abs((int)$ret);
						}
					}
					$nUserImages++;
					$totalImages++;
				}
			}

			if (count($rows) < $BATCH_SIZE) break;
		}
	}

	if ($nUserImages > 0) {
		$totalUsers++;
		if ($totalUsers % 25 === 0 || $SINGLE_USER !== null) {
			progress(sprintf('users=%d, images=%s, spots=%s, elapsed=%.0fs',
				$totalUsers, number_format($totalImages),
				number_format($totalSpotsWalked), microtime(true) - $tStart));
		}
	}
}

if ($APPLY) $buf->flush();

// ---------------------------------------------------------------------------
section('4. Staging summary');

$stagingCount = (int)$db->get_var("SELECT count(*) FROM strabosearch.image_hit_staging_field");
line(sprintf('  images walked:       %s', number_format($totalImages)));
line(sprintf('  spots walked:        %s', number_format($totalSpotsWalked)));
line(sprintf('  users walked:        %d', $totalUsers));
line(sprintf('  skipped (no fname):  %s (unservable per §5.5)', number_format($totalSkippedNoFn)));
line(sprintf('  skipped (no id):     %d', $totalSkippedNoId));
line(sprintf('  parse failures:      %d', $parseFail));
line(sprintf('  staging rows:        %s', number_format($stagingCount)));
line(sprintf('  vocab raw values:    %d', count($vocabSeen)));
line(sprintf('  pull elapsed:        %.1fs', microtime(true) - $tStart));

// ---------------------------------------------------------------------------
section('5. Atomic swap (§5.2.3)');

if (!$APPLY) {
	line('  (skipped — --apply not passed)');
} elseif ($NO_SWAP) {
	line('  (skipped — --no-swap passed; staging retained)');
} else {
	if ($SINGLE_USER !== null) {
		$slice = "image_subsystem = 'field' AND project_userpkey = " . (int)$SINGLE_USER;
		line('  (slice scoped to project_userpkey = ' . (int)$SINGLE_USER . ')');
	} else {
		$slice = "image_subsystem = 'field'";
	}
	$ins = swapStagingInto($db, 'strabosearch.image_hit', 'strabosearch.image_hit_staging_field',
		$slice, $INSERT_COLS,
		array('image_subsystem', 'image_id', 'image_userpkey'));
	if ($ins === null) {
		line('  SWAP FAILED: ' . $db->last_error);
		exit(1);
	}
	line(sprintf('  swapped:  %s rows into image_hit', number_format($ins)));

	// Upsert vocab_image_type with every (raw → unified) pair seen.
	// Field-only — Micro extractor will upsert its own rows under subsystem='micro'.
	if ($vocabSeen) {
		$inserted = upsertVocabImageTypes($db, $vocabSeen, 'field');
		line(sprintf('  vocab_image_type rows upserted: %d', $inserted));
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

// Former local helpers (normalizeFieldImageType, pgBoolFromAnnotated)
// moved to _row_builders.php — shared with the sync touch path.
?>
