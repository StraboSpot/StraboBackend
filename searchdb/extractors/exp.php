<?php
/**
 * StraboSearch Phase 2 — Experimental extractor.
 *
 * Phase 2 sub-branch off search/main per MASTER_PLAN.md. Implements §5.2.1
 * Exp row of the index population table:
 *
 *   Source: PG `straboexp.*` (the canonical store for Exp data).
 *   Writes: strabosearch.item_hit, one row per straboexp.experiment tagged
 *           item_type='experiment', with all Exp-extension columns.
 *   DOES NOT write image_hit — Exp images are excluded from v1 per the
 *           Phase 0.4 image-results audit (72.8% of Exp pictures have no
 *           reachable ispublic; ~25 public-showable in total).
 *
 * ## Algorithm
 *
 *   Single PG query joins experiment → project, with LATERAL sub-selects
 *   for the three Exp-extension scalars + has_* flag presence checks.
 *   104 experiments on dev — well under 1s, no batching needed.
 *
 * ## Native Exp columns (item_hit only)
 *
 *   apparatus_type      — apparatus.type (lossless: every experiment on
 *                         dev has exactly 1 apparatus; 8 clean vocab
 *                         values: Paterson, Triaxial, Griggs, Rotary
 *                         Shear, Biaxial, Heard, Other Apparatus)
 *   daq_sensor_type     — first non-null daq_device_channel.type
 *                         (Analog Input / Output / Calculated / System
 *                         Data / Digital Output / System Clock).
 *   measurement_type    — first non-null daq_device_channel.header_type
 *                         (Displacement / Pressure / Load / Time /
 *                         Electrical / Temperature / Strain / Stress /
 *                         Other).
 *
 *   v1 LIMITATION: an experiment commonly has 5–7 distinct measurement
 *   types (a Paterson rig measures Displacement + Pressure + Load + Time
 *   + Temperature simultaneously). The schema declares VARCHAR scalars
 *   for E1–E3 (Phase 1 signoff, §5.1.3). The scalar holds the FIRST
 *   non-null value per experiment, so filter "measurement_type =
 *   'Pressure'" only matches experiments where Pressure is the primary
 *   measurement. Power users searching for ANY-of-many measurement
 *   types still hit via the keyword path: every distinct header_type
 *   for the experiment is concatenated into the searchtext_tsv bag, so
 *   keyword "pressure" surfaces every Pressure-measuring experiment
 *   regardless of which one is "primary".
 *
 *   The schema is unchanged; relaxing the scalar to TEXT[] is a Phase 1.1
 *   conversation, not a Phase 2 sub-branch.
 *
 * ## Source-of-truth choices
 *
 *   item_id           = experiment.id (100% populated)
 *   item_userpkey     = experiment.userpkey
 *   project_id        = project.uuid (the cross-system id; project.pkey
 *                       is the PG serial)
 *   project_userpkey  = project.userpkey
 *   project_ispublic  = project.ispublic
 *   project_name      = project.name
 *   location          = facility lat+lng when present and parseable
 *                       (54% fill — stored as VARCHAR; we validate
 *                       text→numeric before emitting). Fallback to the
 *                       experiment's first sample provenance_loc_lat/lng
 *                       (~40% additional coverage). NULL if neither.
 *   date_value        = experiment.modified_timestamp::date
 *   searchtext_tsv    = bag of project.name + project.notes +
 *                       experiment.id + apparatus.name + .type + .description
 *                       + facility.name + .institute + sample names +
 *                       sample.material_name + every distinct DAQ
 *                       header_type (powers "Pressure" keyword hits even
 *                       when scalar measurement_type points elsewhere)
 *
 * ## Has-flags
 *
 *   has_orientation     — FALSE (not an Exp concept)
 *   has_samples         — EXISTS in straboexp.sample for this experiment
 *   has_images          — EXISTS document linked via experiment_setup OR
 *                         apparatus for this experiment. Note: the
 *                         image_hit pathway is excluded for Exp per the
 *                         0.4 audit, but the boolean still surfaces "this
 *                         experiment has uploaded files" so Project-pathway
 *                         result cards can show a paperclip / count badge.
 *   has_microstructure  — FALSE (not an Exp concept)
 *   has_strat           — FALSE (not an Exp concept)
 *
 * ## Source-modified
 *
 *   source_modified     — experiment.modified_timestamp (timestamptz on
 *                         this table, clean — unlike micro_projectmetadata's
 *                         varchar modifiedtimestamp).
 *
 * ## Atomic swap
 *
 *   Slice: project_subsystem='exp' AND item_type='experiment'. Soft
 *   uniqueness constraint guards against any future fan-out producing
 *   dups (conflict-soaked on the standard 6-tuple).
 *
 * ## CLI
 *
 *   docker exec strabo-php php /srv/app/www/searchdb/extractors/exp.php
 *
 *   Flags:
 *     --apply               Required for writes. Without it, runs dry.
 *     --source-userpkey=N   Limit to a single user — test isolation.
 *     --no-swap             Build staging only; skip the atomic swap.
 *
 *   No --batch-size or --resume-from: 104 experiments runs in <1s.
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
line('EXP EXTRACTOR — ' . date('Y-m-d H:i:s'));
line('  mode:        ' . ($APPLY ? 'APPLY' : 'DRY RUN (no writes)'));
if ($SINGLE_USER !== null) line('  single user: userpkey = ' . $SINGLE_USER);
if ($NO_SWAP)      line('  --no-swap:   staging will not be swapped into live');

// ---------------------------------------------------------------------------
section('1. Staging table prep');

$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_exp");
$db->query("CREATE TABLE strabosearch.item_hit_staging_exp
	(LIKE strabosearch.item_hit INCLUDING DEFAULTS)");
$db->query("ALTER TABLE strabosearch.item_hit_staging_exp DROP COLUMN item_hit_pkey");
line('  staging table created (fresh): strabosearch.item_hit_staging_exp');

// Single source of truth shared with the sync path — see _row_builders.php.
$INSERT_COLS = expItemCols();
$buf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.item_hit_staging_exp (" . implode(', ', $INSERT_COLS) . ") VALUES ",
	500);

// ---------------------------------------------------------------------------
section('2. Single source pass');

// Single query pulling experiment + project + LATERAL aggregates for
// apparatus/facility/sample/daq facets. Query rationale documented on
// expSourceSql in _row_builders.php (shared with the sync touch path).
$userFilter = $SINGLE_USER !== null ? "AND e.userpkey = " . (int)$SINGLE_USER : '';

$rows = $db->get_results(expSourceSql($userFilter));
$rows = (array)$rows;
line('  experiments fetched: ' . number_format(count($rows)));

$totalExperiments = 0;

foreach ($rows as $r) {
	// Row → tuple mapping shared with the sync touch path — see expTuple
	// in _row_builders.php.
	$values = expTuple($r);

	if ($APPLY) {
		$ret = $buf->add($values);
		if ($ret === false || $ret < 0) {
			line('  batch dropped (' . abs((int)$ret) . ' rows) — ' . $buf->lastError);
		}
	}
	$totalExperiments++;
}

if ($APPLY) $buf->flush();

// ---------------------------------------------------------------------------
section('3. Staging summary');

$stagingCount = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit_staging_exp");
line(sprintf('  experiments walked:  %s', number_format($totalExperiments)));
line(sprintf('  staging rows:        %s', number_format($stagingCount)));
line(sprintf('  source pass elapsed: %.1fs', microtime(true) - $t0));

// ---------------------------------------------------------------------------
section('4. Atomic swap (§5.2.3)');

if (!$APPLY) {
	line('  (skipped — --apply not passed)');
} elseif ($NO_SWAP) {
	line('  (skipped — --no-swap passed; staging retained)');
} else {
	$slice = "project_subsystem = 'exp' AND item_type = 'experiment'";
	if ($SINGLE_USER !== null) {
		$slice .= " AND project_userpkey = " . (int)$SINGLE_USER;
		line('  (slice scoped to project_userpkey = ' . (int)$SINGLE_USER . ')');
	}
	$ins = swapStagingInto($db, 'strabosearch.item_hit', 'strabosearch.item_hit_staging_exp',
		$slice, $INSERT_COLS,
		array('item_type', 'item_id', 'item_userpkey', 'project_id', 'project_userpkey', 'project_subsystem'));
	if ($ins === null) {
		line('  SWAP FAILED: ' . $db->last_error);
		exit(1);
	}
	line(sprintf('  swapped:  %s rows into item_hit', number_format($ins)));

	if ($SINGLE_USER === null) {
		updateSyncState($db, 'exp', $ins);
		line('  sync_state.exp updated (last_full_backfill = now)');
	} else {
		line('  sync_state NOT updated (partial run via --source-userpkey)');
	}
}

// ---------------------------------------------------------------------------
line();
line(sprintf('Done in %.1fs.', microtime(true) - $t0));
?>
