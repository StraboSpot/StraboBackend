<?php
/**
 * StraboSearch Phase 2 — Micro extractor.
 *
 * Phase 2 sub-branch off search/main per MASTER_PLAN.md. Implements §5.2.1
 * Micro row of the index population table:
 *
 *   Source: PG `strabomicro.*` (the canonical store for Micro data).
 *   Writes:
 *     - strabosearch.item_hit, one row per micro_micrographmetadata
 *       tagged item_type='micrograph', with all Micro-extension columns.
 *     - strabosearch.image_hit, the SAME row source surfaced as an
 *       image-pathway view per the 0.4 audit's "every micrograph IS a
 *       servable image".
 *
 * Both targets are filled in one source pass — same micrograph row, two
 * staging tables, two atomic swaps. This is the cheapest way to stay
 * consistent: a micrograph either lands in BOTH targets or NEITHER.
 *
 * ## Algorithm
 *
 *   1. Single PG query joins the chain (no per-row N+1):
 *          micrograph → sample → dataset → project
 *      with array_agg LATERAL sub-selects for the structural facets
 *      that fan out per micrograph (minerals, mineral methods,
 *      instruments, detectors, microstructure presence).
 *
 *   2. Per micrograph row, emit:
 *      - item_hit row (item_type='micrograph')
 *      - image_hit row (image_subsystem='micro')
 *      The two rows share identity / project context / location /
 *      date_value / native Micro columns; only the image-pathway
 *      columns (image_type vocab + title + caption + filename +
 *      imagetext_tsv + parent_sample_id) differ.
 *
 *   3. Source-of-truth choices:
 *      - **item_id / image_id** = micrograph.strabo_id (the cross-system
 *        public id, 100% populated). NOT the PG serial.
 *      - **item_userpkey / image_userpkey** = project.userpkey
 *        (micrographs/samples/datasets carry no userpkey of their own;
 *        ownership is project-level in Micro).
 *      - **project_ispublic** = micro_projectmetadata.ispublic
 *        (lives in PG natively — no PG-vs-Neo4j question for Micro).
 *      - **location** = sample longitude+latitude when present
 *        (29% of Micro samples on dev — only 205 of 714 carry coords);
 *        NULL otherwise. Micro doesn't have spot-level geolocation.
 *      - **date_value** = project.modifiedtimestamp epoch (ms-detect via
 *        pgTimestamp). project.date is freeform varchar and unreliable.
 *      - **searchtext_tsv** = micrograph name + notes + sample
 *        label/sampleid + project name + project notes.
 *      - **source_modified** = project.modifiedtimestamp (the closest
 *        analog to "when this micrograph last changed" — Micro doesn't
 *        track per-row mtimes).
 *
 *   4. image_type normalization via the §4.5 unified vocabulary. Micro
 *      `imagetype` exhibits vocab drift (per 0.4 audit). The four
 *      buckets and their matching rules:
 *        - thin_section: substring "thin section" (case-insensitive)
 *        - micrograph_optical: substring "polarized"/"polarised",
 *          "PPL", "XPL", "reflected light", "no polarizer", "RL "
 *        - micrograph_sem: "BSE", "SEM", "secondary electron",
 *          "backscatter", "EBSD"
 *        - micrograph_other: catchall (CL, phase maps, element maps,
 *          etc.)
 *      vocab_image_type table upserted with every raw → unified pair
 *      seen, under subsystem='micro'.
 *
 *   5. Atomic swap per §5.2.3, once per target table:
 *        - item_hit slice: project_subsystem='micro' AND item_type='micrograph'
 *        - image_hit slice: image_subsystem='micro'
 *      Both swaps execute in their own transactions; staging tables
 *      truncated post-swap.
 *
 *   6. Update strabosearch.sync_state.last_full_backfill for 'micro'.
 *
 * ## CLI
 *
 *   docker exec strabo-php php /srv/app/www/searchdb/extractors/micro.php
 *
 *   Flags:
 *     --apply               Required for writes. Without it, runs dry.
 *     --source-userpkey=N   Limit to a single user — test isolation.
 *     --no-swap             Build staging only; skip the atomic swap (debug).
 *
 *   No --batch-size or --resume-from: at 4,894 micrographs the full pass
 *   runs in seconds and an OOM is non-credible.
 *
 * @package StraboSearch Phase 2 extractors
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/_extractor_lib.php');
require_once(__DIR__ . '/_row_builders.php');

$APPLY        = in_array('--apply', $argv, true);
$NO_SWAP      = in_array('--no-swap', $argv, true);
$SINGLE_USER  = null;
foreach ($argv as $arg) {
	if (preg_match('/^--source-userpkey=(\d+)$/', $arg, $m))  $SINGLE_USER = (int)$m[1];
}

$t0 = microtime(true);
line('MICRO EXTRACTOR — ' . date('Y-m-d H:i:s'));
line('  mode:        ' . ($APPLY ? 'APPLY' : 'DRY RUN (no writes)'));
if ($SINGLE_USER !== null) line('  single user: userpkey = ' . $SINGLE_USER);
if ($NO_SWAP)      line('  --no-swap:   staging will not be swapped into live');

// ---------------------------------------------------------------------------
section('1. Staging tables prep');

$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_micro");
$db->query("CREATE TABLE strabosearch.item_hit_staging_micro
	(LIKE strabosearch.item_hit INCLUDING DEFAULTS)");
$db->query("ALTER TABLE strabosearch.item_hit_staging_micro DROP COLUMN item_hit_pkey");

$db->query("DROP TABLE IF EXISTS strabosearch.image_hit_staging_micro");
$db->query("CREATE TABLE strabosearch.image_hit_staging_micro
	(LIKE strabosearch.image_hit INCLUDING DEFAULTS)");
$db->query("ALTER TABLE strabosearch.image_hit_staging_micro DROP COLUMN image_hit_pkey");

line('  staging tables created (fresh): item_hit_staging_micro + image_hit_staging_micro');

// INSERT column lists — single source of truth shared with the sync path;
// see _row_builders.php (must match microTuples' per-target VALUES order).
$ITEM_COLS  = microItemCols();
$IMAGE_COLS = microImageCols();
$itemBuf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.item_hit_staging_micro (" . implode(', ', $ITEM_COLS) . ") VALUES ",
	500);
$imageBuf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.image_hit_staging_micro (" . implode(', ', $IMAGE_COLS) . ") VALUES ",
	500);

// ---------------------------------------------------------------------------
section('2. Single source pass');

// Single SQL — chain joined + LATERAL sub-selects for the fan-out facets.
// Query rationale documented on microSourceSql in _row_builders.php
// (shared with the sync touch path).
$userFilter = $SINGLE_USER !== null ? "AND pm.userpkey = " . (int)$SINGLE_USER : '';

$rows = $db->get_results(microSourceSql($userFilter));
$rows = (array)$rows;
line('  source rows fetched: ' . number_format(count($rows)));

$totalMicrographs = 0;
$vocabSeen        = array();
$skippedNoProject = 0;

foreach ($rows as $r) {
	// Row → tuple mapping shared with the sync touch path — see
	// microTuples in _row_builders.php (same micrograph feeds BOTH
	// targets: it lands in both or neither).
	$pair = microTuples($r, $vocabSeen);
	if ($pair === null) {
		$skippedNoProject++;
		continue;
	}
	list($itemValues, $imageValues) = $pair;

	if ($APPLY) {
		$itemBuf->add($itemValues);
		$imageBuf->add($imageValues);
	}
	$totalMicrographs++;
}

if ($APPLY) {
	$itemBuf->flush();
	$imageBuf->flush();
}

// ---------------------------------------------------------------------------
section('3. Staging summary');

$itemStaging  = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit_staging_micro");
$imageStaging = (int)$db->get_var("SELECT count(*) FROM strabosearch.image_hit_staging_micro");
line(sprintf('  micrographs walked:      %s', number_format($totalMicrographs)));
line(sprintf('  skipped (no project id): %d', $skippedNoProject));
line(sprintf('  item_hit staging rows:   %s', number_format($itemStaging)));
line(sprintf('  image_hit staging rows:  %s', number_format($imageStaging)));
line(sprintf('  vocab raw values:        %d', count($vocabSeen)));
line(sprintf('  source pass elapsed:     %.1fs', microtime(true) - $t0));

// ---------------------------------------------------------------------------
section('4. Atomic swap (§5.2.3)');

if (!$APPLY) {
	line('  (skipped — --apply not passed)');
} elseif ($NO_SWAP) {
	line('  (skipped — --no-swap passed; staging retained)');
} else {
	// item_hit slice
	$itemSlice = "project_subsystem = 'micro' AND item_type = 'micrograph'";
	if ($SINGLE_USER !== null) {
		$itemSlice .= " AND project_userpkey = " . (int)$SINGLE_USER;
		line('  (slice scoped to project_userpkey = ' . (int)$SINGLE_USER . ')');
	}
	$insItem = swapStagingInto($db, 'strabosearch.item_hit', 'strabosearch.item_hit_staging_micro',
		$itemSlice, $ITEM_COLS,
		array('item_type', 'item_id', 'item_userpkey', 'project_id', 'project_userpkey', 'project_subsystem'));
	if ($insItem === null) {
		line('  ITEM SWAP FAILED: ' . $db->last_error);
		exit(1);
	}
	line(sprintf('  swapped:  %s rows into item_hit', number_format($insItem)));

	// image_hit slice
	$imageSlice = "image_subsystem = 'micro'";
	if ($SINGLE_USER !== null) {
		$imageSlice .= " AND project_userpkey = " . (int)$SINGLE_USER;
	}
	$insImage = swapStagingInto($db, 'strabosearch.image_hit', 'strabosearch.image_hit_staging_micro',
		$imageSlice, $IMAGE_COLS,
		array('image_subsystem', 'image_id', 'image_userpkey'));
	if ($insImage === null) {
		line('  IMAGE SWAP FAILED: ' . $db->last_error);
		exit(1);
	}
	line(sprintf('  swapped:  %s rows into image_hit', number_format($insImage)));

	// vocab_image_type upsert under subsystem='micro'.
	if ($vocabSeen) {
		$upserted = upsertVocabImageTypes($db, $vocabSeen, 'micro');
		line(sprintf('  vocab_image_type rows upserted: %d', $upserted));
	}

	if ($SINGLE_USER === null) {
		updateSyncState($db, 'micro', $insItem);
		line('  sync_state.micro updated (last_full_backfill = now)');
	} else {
		line('  sync_state NOT updated (partial run via --source-userpkey)');
	}
}

// ---------------------------------------------------------------------------
line();
line(sprintf('Done in %.1fs.', microtime(true) - $t0));

// Former local helpers (microTagNamesFromJson, normalizeMicroImageType)
// moved to _row_builders.php — shared with the sync touch path.
?>
