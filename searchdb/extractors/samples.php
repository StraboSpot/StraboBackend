<?php
/**
 * StraboSearch Phase 2 — Samples extractor (item_hit.item_type='sample').
 *
 * Phase 2 sub-branch off search/main per MASTER_PLAN.md. Implements the
 * §5.2.1 Samples row of the index population table — the LAST extractor
 * per §5.2.2, because it depends on the other subsystems' slices to know
 * which host projects to fan out to:
 *
 *   Source: PG `strabosamples.*` (the spine — samples + sample_subsystem_links).
 *   Writes: strabosearch.item_hit — fan-out: for each sample, one row per
 *           DISTINCT linked host project, tagged item_type='sample'. A
 *           sample linked to a Field project AND a Micro project produces
 *           two rows (§4.1 roll-up rule: a sample search surfaces every
 *           project that references the sample, across subsystems).
 *   DOES NOT write image_hit (no native sample images — Field/Micro images
 *           of samples arrive via their own subsystem extractors).
 *
 * ## Fan-out resolution — how a link row becomes a host-project tuple
 *
 * `sample_subsystem_links.reference_id` points at the subsystem-NATIVE
 * record, not the project, so each subsystem resolves differently:
 *
 *   field         reference_id = Spot strabo id, reference_userpkey = spot
 *                 owner. Resolved via the already-extracted Field slice:
 *                 JOIN strabosearch.item_hit (item_type='spot') ON
 *                 (item_id, item_userpkey). This is exactly WHY Samples
 *                 runs last (§5.2.2) — the Field extractor already did the
 *                 Neo4j spot→dataset→project walk; re-walking the graph
 *                 here would duplicate it. Links whose spot is not in the
 *                 index (deleted spot / not-yet-extracted) are counted and
 *                 logged as unresolved, then skipped.
 *
 *   micro         reference_metadata->>'project_strabo_id' +
 *                 reference_userpkey → strabomicro.micro_projectmetadata
 *                 (strabo_id, userpkey). BOTH keys required: micro
 *                 strabo_id is NOT unique across users (see
 *                 project_strabosamples_cross_system_auth). Resolving from
 *                 the source table (not the item_hit micro slice) keeps
 *                 micrograph-less projects resolvable.
 *
 *   experimental  reference_metadata->>'project_uuid' →
 *                 straboexp.project.uuid; project_userpkey =
 *                 reference_userpkey (the experiment owner — matching the
 *                 Exp extractor's project_userpkey convention, which uses
 *                 experiment.userpkey). Subsystem value is translated
 *                 'experimental' → 'exp' to match the Exp slice.
 *
 * All three resolutions emit tuples in the SAME (project_id,
 * project_userpkey, project_subsystem) shape their subsystem extractor
 * writes, so §4.1 GROUP BY project aggregation folds sample rows into the
 * same project card as the host subsystem's own rows.
 *
 * Orphan samples (zero links) produce NO rows in v1 — per §4.2 U8
 * resolution note (line ~144 of DESIGN_PROPOSAL.md): the dedicated
 * orphan-sample pathway is deferred to v2.
 *
 * ## Source-of-truth choices
 *
 *   item_id / sample_id   = samples.id (spine identity is (id, userpkey))
 *   item_userpkey         = samples.userpkey — the SPINE owner, which may
 *                           differ from project_userpkey (§5.1.3 comment;
 *                           sample-level collab does NOT cascade, §5.5.1)
 *   sample_name / igsn / display_sample_type / display_sample_purpose
 *                         = straight from the spine row (U5/U6/U7 facets)
 *   location              = sample's own longitude/latitude when present;
 *                           for field-hosted rows falls back to the host
 *                           spot's indexed location (a field sample is
 *                           physically AT its spot); NULL otherwise
 *   date_value            = samples.modified_at::date
 *   source_modified       = samples.modified_at
 *   searchtext_tsv        = bag of name + igsn + description + notes +
 *                           display type/purpose + host project name +
 *                           flattened custom_data values (user-authored
 *                           tabular-import columns). The field_data /
 *                           micro_data / experimental_data JSONB blobs are
 *                           deliberately EXCLUDED — they mirror subsystem
 *                           content already indexed by the subsystem
 *                           extractors; folding them in would double-count
 *                           keyword hits on the same underlying data.
 *
 * ## Has-flags
 *
 *   has_samples = TRUE (the item IS a sample — keeps U9 "has samples"
 *   consistent at the project roll-up). All other flags FALSE — they are
 *   Field-spot concepts (§4.2 U9).
 *
 * ## Tag columns
 *
 *   Left NULL per §5.2.1 — Samples has no tag concept today; the schema
 *   accommodates it without migration when it does.
 *
 * ## Duplicate links
 *
 *   One sample linked to N spots in the SAME Field project must yield ONE
 *   row: resolution queries use DISTINCT ON (sample, project tuple), and
 *   the atomic swap conflict-soaks on the standard 6-tuple as backstop.
 *
 * ## Atomic swap
 *
 *   Slice: item_type='sample' (across ALL project_subsystem values — sample
 *   rows live inside field/micro/exp host slices, which is also why every
 *   OTHER extractor's slice predicate includes its own item_type).
 *   With --source-userpkey, the slice narrows on item_userpkey (the spine
 *   owner — NOT project_userpkey, which can belong to someone else).
 *
 * ## CLI
 *
 *   docker exec strabo-php php /srv/app/www/searchdb/extractors/samples.php
 *
 *   Flags:
 *     --apply               Required for writes. Without it, runs dry.
 *     --source-userpkey=N   Limit to samples OWNED by one spine user — test
 *                           isolation (sync_state not updated).
 *     --no-swap             Build staging only; skip the atomic swap.
 *
 *   No --batch-size or --resume-from: ~50k links resolve in one pass in
 *   seconds; an OOM is non-credible at this scale.
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
line('SAMPLES EXTRACTOR — ' . date('Y-m-d H:i:s'));
line('  mode:        ' . ($APPLY ? 'APPLY' : 'DRY RUN (no writes)'));
if ($SINGLE_USER !== null) line('  single user: samples.userpkey = ' . $SINGLE_USER);
if ($NO_SWAP)      line('  --no-swap:   staging will not be swapped into live');

// ---------------------------------------------------------------------------
section('1. Staging table prep');

$db->query("DROP TABLE IF EXISTS strabosearch.item_hit_staging_samples");
$db->query("CREATE TABLE strabosearch.item_hit_staging_samples
	(LIKE strabosearch.item_hit INCLUDING DEFAULTS)");
$db->query("ALTER TABLE strabosearch.item_hit_staging_samples DROP COLUMN item_hit_pkey");
line('  staging table created (fresh): strabosearch.item_hit_staging_samples');

// Single source of truth shared with the sync path — see _row_builders.php.
$INSERT_COLS = samplesItemCols();
$buf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.item_hit_staging_samples (" . implode(', ', $INSERT_COLS) . ") VALUES ",
	500);

$userFilter = $SINGLE_USER !== null ? "AND s.userpkey = " . (int)$SINGLE_USER : '';

/**
 * Emit one staging VALUES tuple for a resolved (sample × host project) pair.
 * Row → tuple mapping shared with the sync touch path — see sampleTuple
 * in _row_builders.php.
 */
function emitSampleRow($buf, $APPLY, $r, $subsystem, $hostLocWkt) {
	$values = sampleTuple($r, $subsystem, $hostLocWkt);
	if ($APPLY) {
		$ret = $buf->add($values);
		if ($ret !== null && $ret < 0) {
			line('  batch dropped (' . abs((int)$ret) . ' rows) — ' . $buf->lastError);
		}
	}
}

// ---------------------------------------------------------------------------
section('2. Field fan-out (links resolved via the indexed Field slice)');

$rows = $db->get_results(samplesFieldSql($userFilter));
$rows = (array)$rows;
$fieldRows = count($rows);
foreach ($rows as $r) emitSampleRow($buf, $APPLY, $r, 'field', $r->host_loc_wkt);
unset($rows);

$unresolvedField = (int)$db->get_var("
	SELECT count(*)
	FROM strabosamples.sample_subsystem_links l
	JOIN strabosamples.samples s
	  ON s.id = l.sample_id AND s.userpkey = l.sample_userpkey
	WHERE l.subsystem = 'field' $userFilter
	  AND NOT EXISTS (
		SELECT 1 FROM strabosearch.item_hit ih
		WHERE ih.item_type = 'spot' AND ih.project_subsystem = 'field'
		  AND ih.item_id = l.reference_id AND ih.item_userpkey = l.reference_userpkey
	  )
");
line('  field rows emitted:     ' . number_format($fieldRows));
line('  field links unresolved: ' . number_format($unresolvedField)
	. ($unresolvedField ? '  (spot not in index — deleted or not extracted; skipped)' : ''));

// ---------------------------------------------------------------------------
section('3. Micro fan-out (links resolved via micro_projectmetadata)');

$rows = $db->get_results(samplesMicroSql($userFilter));
$rows = (array)$rows;
$microRows = count($rows);
foreach ($rows as $r) emitSampleRow($buf, $APPLY, $r, 'micro', null);
unset($rows);
line('  micro rows emitted:     ' . number_format($microRows));

// ---------------------------------------------------------------------------
section('4. Exp fan-out (links resolved via straboexp.project)');

$rows = $db->get_results(samplesExpSql($userFilter));
$rows = (array)$rows;
$expRows = count($rows);
foreach ($rows as $r) emitSampleRow($buf, $APPLY, $r, 'exp', null);
unset($rows);
line('  exp rows emitted:       ' . number_format($expRows));

if ($APPLY) $buf->flush();

// ---------------------------------------------------------------------------
section('5. Staging summary');

$stagingCount = (int)$db->get_var("SELECT count(*) FROM strabosearch.item_hit_staging_samples");
line(sprintf('  fan-out rows emitted:  %s (field %s / micro %s / exp %s)',
	number_format($fieldRows + $microRows + $expRows),
	number_format($fieldRows), number_format($microRows), number_format($expRows)));
line(sprintf('  staging rows:          %s', number_format($stagingCount)));
if ($buf->dropped) line('  DROPPED batches:       ' . number_format($buf->dropped) . ' rows — ' . $buf->lastError);
line(sprintf('  source pass elapsed:   %.1fs', microtime(true) - $t0));

// ---------------------------------------------------------------------------
section('6. Atomic swap (§5.2.3)');

if (!$APPLY) {
	line('  (skipped — --apply not passed)');
} elseif ($NO_SWAP) {
	line('  (skipped — --no-swap passed; staging retained)');
} else {
	// Slice on item_type only: sample rows live inside every host
	// subsystem's project_subsystem value.
	$slice = "item_type = 'sample'";
	if ($SINGLE_USER !== null) {
		// item_userpkey = spine owner (project_userpkey may be another user).
		$slice .= " AND item_userpkey = " . (int)$SINGLE_USER;
		line('  (slice scoped to item_userpkey = ' . (int)$SINGLE_USER . ')');
	}
	$ins = swapStagingInto($db, 'strabosearch.item_hit', 'strabosearch.item_hit_staging_samples',
		$slice, $INSERT_COLS,
		array('item_type', 'item_id', 'item_userpkey', 'project_id', 'project_userpkey', 'project_subsystem'));
	if ($ins === null) {
		line('  SWAP FAILED: ' . $db->last_error);
		exit(1);
	}
	line(sprintf('  swapped:  %s rows into item_hit', number_format($ins)));

	if ($SINGLE_USER === null) {
		updateSyncState($db, 'samples', $ins);
		line('  sync_state.samples updated (last_full_backfill = now)');
	} else {
		line('  sync_state NOT updated (partial run via --source-userpkey)');
	}
}

// ---------------------------------------------------------------------------
line();
line(sprintf('Done in %.1fs.', microtime(true) - $t0));
?>
