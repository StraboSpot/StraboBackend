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

// INSERT column lists (must match the per-target VALUES tuples below).
$ITEM_COLS = array(
	'item_type', 'item_id', 'item_userpkey',
	'project_id', 'project_userpkey', 'project_subsystem', 'project_name', 'project_ispublic',
	'location', 'date_value', 'searchtext_tsv',
	'has_orientation', 'has_samples', 'has_images', 'has_microstructure', 'has_strat',
	'minerals', 'mineral_methods', 'instrument_type', 'detector_type',
	'tag_names', 'tag_text_tsv',
	'source_modified',
);
$IMAGE_COLS = array(
	'image_id', 'image_subsystem', 'image_userpkey',
	'image_type', 'annotated', 'title', 'caption', 'imagetext_tsv', 'filename',
	'parent_spot_id', 'parent_sample_id',
	'project_id', 'project_userpkey', 'project_subsystem', 'project_ispublic',
	'location', 'date_value',
	'minerals', 'mineral_methods', 'instrument_type', 'detector_type',
	'tag_names', 'tag_text_tsv',
	'source_modified',
);
$itemBuf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.item_hit_staging_micro (" . implode(', ', $ITEM_COLS) . ") VALUES ",
	500);
$imageBuf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.image_hit_staging_micro (" . implode(', ', $IMAGE_COLS) . ") VALUES ",
	500);

// ---------------------------------------------------------------------------
section('2. Single source pass');

// Single SQL — chain joined + LATERAL sub-selects for the fan-out facets.
// EXISTS chain for has_microstructure: any non-empty join into the most
// common structural tables (fabric / grain / fold / fault / fracture /
// intragrain / vein / grainboundary). pseudotachylyte / clastic bands /
// extinction / lithology omitted from has_microstructure for v1 — they
// have separate v2 facets and rarely fire stand-alone.
$userFilter = $SINGLE_USER !== null ? "AND pm.userpkey = " . (int)$SINGLE_USER : '';

$rows = $db->get_results("
	SELECT
		mm.id AS micrograph_pkey,
		mm.strabo_id AS micrograph_strabo_id,
		mm.name AS micrograph_name,
		mm.notes AS micrograph_notes,
		mm.imagetype AS raw_image_type,
		sm.strabo_id AS sample_strabo_id,
		sm.sampleid AS sample_sampleid,
		sm.label AS sample_label,
		sm.longitude AS sample_longitude,
		sm.latitude AS sample_latitude,
		sm.samplenotes AS sample_notes,
		pm.strabo_id AS project_strabo_id,
		pm.userpkey AS project_userpkey,
		pm.name AS project_name,
		pm.ispublic AS project_ispublic,
		pm.modifiedtimestamp AS project_mt,
		pm.notes AS project_notes,
		COALESCE((
			SELECT array_agg(DISTINCT mi.name) FILTER (WHERE mi.name IS NOT NULL AND mi.name <> '')
			FROM strabomicro.micro_mineralogy mg
			LEFT JOIN strabomicro.micro_mineral mi ON mi.mineralogy_id = mg.id
			WHERE mg.micrograph_id = mm.id
		), ARRAY[]::TEXT[]) AS minerals,
		COALESCE((
			SELECT array_agg(DISTINCT mg.mineralogymethod) FILTER (WHERE mg.mineralogymethod IS NOT NULL AND mg.mineralogymethod <> '')
			FROM strabomicro.micro_mineralogy mg
			WHERE mg.micrograph_id = mm.id
		), ARRAY[]::TEXT[]) AS mineral_methods,
		(SELECT instrumenttype FROM strabomicro.micro_instrument
		 WHERE micrograph_id = mm.id AND instrumenttype IS NOT NULL AND instrumenttype <> ''
		 LIMIT 1) AS instrument_type,
		(SELECT d.detectortype FROM strabomicro.micro_instrument i
		 JOIN strabomicro.micro_instrumentdetector d ON d.instrument_id = i.id
		 WHERE i.micrograph_id = mm.id AND d.detectortype IS NOT NULL AND d.detectortype <> ''
		 LIMIT 1) AS detector_type,
		COALESCE((
			SELECT array_agg(DISTINCT t.name) FILTER (WHERE t.name IS NOT NULL AND t.name <> '')
			FROM strabomicro.micro_micrograph_tag mt
			JOIN strabomicro.micro_tag t ON t.id = mt.tag_id
			WHERE mt.micrograph_id = mm.id
		), ARRAY[]::TEXT[]) AS junction_tag_names,
		COALESCE((
			SELECT array_agg(DISTINCT t.name) FILTER (WHERE t.name IS NOT NULL AND t.name <> '')
			FROM strabomicro.micro_spot_tag st
			JOIN strabomicro.micro_spotmetadata sp2 ON sp2.id = st.spot_id
			JOIN strabomicro.micro_tag t ON t.id = st.tag_id
			WHERE sp2.micrograph_id = mm.id
		), ARRAY[]::TEXT[]) AS spot_junction_tag_names,
		mm.tags_json AS micrograph_tags_json,
		COALESCE((
			SELECT array_agg(sp.tags_json) FILTER (WHERE sp.tags_json IS NOT NULL AND sp.tags_json <> '' AND sp.tags_json <> '[]')
			FROM strabomicro.micro_spotmetadata sp
			WHERE sp.micrograph_id = mm.id
		), ARRAY[]::TEXT[]) AS spot_tags_jsons,
		(EXISTS (SELECT 1 FROM strabomicro.micro_fabricinfo            WHERE micrograph_id = mm.id) OR
		 EXISTS (SELECT 1 FROM strabomicro.micro_graininfo             WHERE micrograph_id = mm.id) OR
		 EXISTS (SELECT 1 FROM strabomicro.micro_foldinfo              WHERE micrograph_id = mm.id) OR
		 EXISTS (SELECT 1 FROM strabomicro.micro_fractureinfo          WHERE micrograph_id = mm.id) OR
		 EXISTS (SELECT 1 FROM strabomicro.micro_faultsshearzonesinfo  WHERE micrograph_id = mm.id) OR
		 EXISTS (SELECT 1 FROM strabomicro.micro_intragraininfo        WHERE micrograph_id = mm.id) OR
		 EXISTS (SELECT 1 FROM strabomicro.micro_veininfo              WHERE micrograph_id = mm.id) OR
		 EXISTS (SELECT 1 FROM strabomicro.micro_grainboundaryinfo     WHERE micrograph_id = mm.id))
		   AS has_microstructure
	FROM strabomicro.micro_micrographmetadata mm
	JOIN strabomicro.micro_samplemetadata sm     ON sm.id = mm.sample_id
	JOIN strabomicro.micro_datasetmetadata dm    ON dm.id = sm.dataset_id
	JOIN strabomicro.micro_projectmetadata pm    ON pm.id = dm.project_id
	WHERE pm.userpkey IS NOT NULL
	  AND mm.strabo_id IS NOT NULL AND mm.strabo_id <> ''
	  $userFilter
	ORDER BY pm.userpkey, mm.id
");
$rows = (array)$rows;
line('  source rows fetched: ' . number_format(count($rows)));

$totalMicrographs = 0;
$vocabSeen        = array();
$skippedNoProject = 0;

foreach ($rows as $r) {
	if ($r->project_strabo_id === null || $r->project_strabo_id === '') {
		$skippedNoProject++;
		continue;
	}

	$puk          = (int)$r->project_userpkey;
	$projectId    = (string)$r->project_strabo_id;
	$projectName  = (string)$r->project_name;
	$projectPub   = ($r->project_ispublic === 't' || $r->project_ispublic === true);

	$micrographId   = (string)$r->micrograph_strabo_id;
	$micrographName = (string)$r->micrograph_name;
	$micrographNotes = (string)$r->micrograph_notes;
	$sampleId       = (string)$r->sample_strabo_id;
	$sampleLabel    = (string)$r->sample_label;
	$sampleSampleId = (string)$r->sample_sampleid;
	$sampleNotes    = (string)$r->sample_notes;

	// Location: sample lat/lng when both present and valid.
	$locLit = pgPointLiteral($r->sample_longitude, $r->sample_latitude);

	// date_value derived from project.modifiedtimestamp epoch.
	$mtTsLit = pgTimestamp($r->project_mt);
	$dateLit = ($mtTsLit !== 'NULL') ? '(' . $mtTsLit . ')::date' : 'NULL';

	// --- tag_names (U10 amendment): name-only union of the four Micro tag
	// sources — micrograph junction tags, spot junction tags, micrograph
	// tags_json, and the tags_json of every spot drawn on this micrograph.
	// No tag_types (F11 is Field-only; Micro tagType vocab is not folded in).
	$tagNames = array_merge(
		pgParseTextArray($r->junction_tag_names),
		pgParseTextArray($r->spot_junction_tag_names),
		microTagNamesFromJson($r->micrograph_tags_json)
	);
	foreach (pgParseTextArray($r->spot_tags_jsons) as $spotTagsJson) {
		$tagNames = array_merge($tagNames, microTagNamesFromJson($spotTagsJson));
	}
	$tagNames = array_values(array_unique(array_filter($tagNames, function ($v) {
		return $v !== null && $v !== '';
	})));
	$tagNamesLit   = pgTextArray($tagNames);
	$tagTextTsvLit = pgTsvector(implode(' ', $tagNames));

	// Bag of words — names + notes from all chain rungs. Tag names ride
	// along per the U10 amendment (U1 safety net).
	$bag = $micrographName . ' ' . $micrographNotes . ' '
		. $sampleLabel . ' ' . $sampleSampleId . ' ' . $sampleNotes . ' '
		. $projectName . ' ' . (string)$r->project_notes
		. ($tagNames ? ' ' . implode(' ', $tagNames) : '');
	$searchtext = pgTsvector($bag);

	// Native Micro arrays come back as PG array literals (e.g. "{Quartz,Olivine}").
	// Parse and re-emit via pgTextArray to normalize quoting + handle empties
	// (array_agg returning {} when no rows).
	$minerals       = pgParseTextArray($r->minerals);
	$mineralMethods = pgParseTextArray($r->mineral_methods);
	$mineralsLit       = pgTextArray($minerals);
	$mineralMethodsLit = pgTextArray($mineralMethods);

	$instrumentTypeLit = pgText($r->instrument_type);
	$detectorTypeLit   = pgText($r->detector_type);

	$hasMicrostructure = ($r->has_microstructure === 't' || $r->has_microstructure === true);

	// image_type normalization → unified vocab + capture raw for the
	// vocab_image_type upsert.
	$rawImageType = $r->raw_image_type;
	$unifiedImageType = normalizeMicroImageType($rawImageType);
	if ($rawImageType !== null && $rawImageType !== '') {
		$vocabSeen[(string)$rawImageType] = $unifiedImageType;
	}

	// Caption for image_hit: micrograph.notes (description is dead per 0.4 audit).
	$imageText = trim((string)$micrographName . ' ' . (string)$micrographNotes);
	$imageTextLit = pgTsvector($imageText);

	// Micro composite JPEG filename pattern is `<strabo_id>.jpg` under
	// /straboMicroFiles/<project_pkey>/images/ — the server side already
	// guarantees this exists for every micrograph (server-PDF pipeline).
	// We store the basename only; the API resolves the full path.
	$filename = $micrographId . '.jpg';

	// Assemble item_hit row.
	$itemValues = '(' . implode(',', array(
		pgText('micrograph'),
		pgText($micrographId, 64),
		pgInt($puk),
		pgText($projectId, 64),
		pgInt($puk),
		pgText('micro'),
		pgText($projectName, 500),
		pgBool($projectPub),
		$locLit,
		$dateLit,
		$searchtext,
		pgBool(false),                       // has_orientation
		pgBool(true),                        // has_samples
		pgBool(true),                        // has_images
		pgBool($hasMicrostructure),          // has_microstructure
		pgBool(false),                       // has_strat
		$mineralsLit,
		$mineralMethodsLit,
		$instrumentTypeLit,
		$detectorTypeLit,
		$tagNamesLit,
		$tagTextTsvLit,
		$mtTsLit,
	)) . ')';

	// Assemble image_hit row.
	$imageValues = '(' . implode(',', array(
		pgText($micrographId, 64),
		pgText('micro'),
		pgInt($puk),
		pgText($unifiedImageType),
		'NULL',                              // annotated (not a Micro concept)
		pgText($micrographName),             // title
		pgText($micrographNotes),            // caption (description is dead column)
		$imageTextLit,
		pgText($filename, 500),
		'NULL',                              // parent_spot_id (no Field spot)
		pgText($sampleId, 64),               // parent_sample_id
		pgText($projectId, 64),
		pgInt($puk),
		pgText('micro'),
		pgBool($projectPub),
		$locLit,
		$dateLit,
		$mineralsLit,
		$mineralMethodsLit,
		$instrumentTypeLit,
		$detectorTypeLit,
		$tagNamesLit,
		$tagTextTsvLit,
		$mtTsLit,
	)) . ')';

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
		$upserted = 0;
		foreach ($vocabSeen as $raw => $unified) {
			$rawEsc     = pg_escape_string((string)$raw);
			$unifiedEsc = pg_escape_string((string)$unified);
			$db->query("INSERT INTO strabosearch.vocab_image_type (subsystem, normalized_from, unified_value)
				VALUES ('micro', '$rawEsc', '$unifiedEsc')
				ON CONFLICT (subsystem, normalized_from) DO UPDATE SET unified_value = EXCLUDED.unified_value");
			$upserted++;
		}
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

// ===========================================================================
// Helpers — local to this extractor
// ===========================================================================

/**
 * Map a raw Micro `imagetype` value to the §4.5 unified vocabulary.
 * Substring-based — Micro `imagetype` is freeform on prod with significant
 * drift (0.4 audit: 82% fill, 30+ distinct values).
 *
 *   thin_section        — "thin section" anywhere
 *   micrograph_optical  — polarized / polarised / PPL / XPL / "reflected
 *                         light" / "no polarizer" — visible-light microscopy
 *   micrograph_sem      — SEM / BSE / EBSD / "secondary electron" /
 *                         "backscatter" / "backscattered electron" —
 *                         electron microscopy
 *   micrograph_other    — everything else (CL, phase maps, element maps,
 *                         orientation/IPF maps, etc.)
 *
 * Returns NULL for empty/null raw input (no row, no vocab entry).
 */
/**
 * Extract tag names from a Micro tags_json blob — a JSON array of
 * {"id":..., "name":..., "tagType":...} objects as written by the
 * StraboMicro client on micrograph and spot metadata rows. Name-only per
 * the U10 amendment (Micro contributes no tag_types). Returns array of
 * non-empty names; tolerates null / empty / malformed input.
 */
function microTagNamesFromJson($raw) {
	if ($raw === null || $raw === '' || $raw === false) return array();
	$decoded = json_decode((string)$raw);
	if (!is_array($decoded)) return array();
	$names = array();
	foreach ($decoded as $t) {
		if (is_object($t) && isset($t->name) && $t->name !== '') $names[] = (string)$t->name;
	}
	return $names;
}

function normalizeMicroImageType($raw) {
	if ($raw === null || $raw === '' || $raw === false) return null;
	$v = strtolower(trim((string)$raw));
	if (strpos($v, 'thin section') !== false) return 'thin_section';
	$sem_needles = array('sem', 'bse', 'ebsd', 'secondary electron',
		'backscatter', 'backscattered electron');
	foreach ($sem_needles as $n) {
		if (strpos($v, $n) !== false) return 'micrograph_sem';
	}
	$optical_needles = array('polarized', 'polarised', 'ppl', 'xpl',
		'reflected light', 'no polarizer', 'no polariser');
	foreach ($optical_needles as $n) {
		if (strpos($v, $n) !== false) return 'micrograph_optical';
	}
	return 'micrograph_other';
}
?>
