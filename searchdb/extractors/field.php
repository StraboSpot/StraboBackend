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

// INSERT column list excludes the PK + last_synced (defaulted). Used as a
// single source of truth for both the staging INSERT and the swap's
// projection back into item_hit.
$INSERT_COLS = array(
	'item_type', 'item_id', 'item_userpkey',
	'project_id', 'project_userpkey', 'project_subsystem', 'project_name', 'project_ispublic',
	'location', 'date_value', 'searchtext_tsv',
	'has_orientation', 'has_samples', 'has_images', 'has_microstructure', 'has_strat',
	'orientation_strike', 'orientation_dip', 'orientation_trend', 'orientation_plunge',
	'orientation_features', 'orientation_planar',
	'rock_types', 'met_facies', 'trace_types',
	'source_modified',
);
$buf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.item_hit_staging_field (" . implode(', ', $INSERT_COLS) . ") VALUES ",
	500);

// ---------------------------------------------------------------------------
section('2. Pre-fetch PG project metadata');

// Project.ispublic in Neo4j is NULL everywhere. The source of truth is the
// PG `project` table (~8299 rows on prod), which the upload/create API
// path keeps current. Build an in-memory map for O(1) per-spot lookup.
$pgPubMap = array();
$rows = $db->get_results("SELECT strabo_project_id, ispublic FROM project");
foreach ((array)$rows as $r) {
	$pgPubMap[(string)$r->strabo_project_id] = ($r->ispublic === 't' || $r->ispublic === true);
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

		// Keyset-paginate by s.id within a dataset (handles the rare
		// pathologically large dataset without re-scan penalty).
		// Real Project/Dataset/Spot ids are LONGs in Neo4j; quoting them
		// yields string-vs-long mismatch with the indexed property. Emit
		// numeric literal when purely-numeric, quoted+escaped string otherwise.
		$pidLit = ctype_digit((string)$pid) ? (string)$pid
			: "'" . pg_escape_string((string)$pid) . "'";
		$didLit = ctype_digit((string)$did) ? (string)$did
			: "'" . pg_escape_string((string)$did) . "'";
		$cursor = null;
		while (true) {
			$cursorClause = '';
			if ($cursor !== null) {
				$cursorLit = ctype_digit((string)$cursor) ? (string)$cursor
					: "'" . pg_escape_string((string)$cursor) . "'";
				$cursorClause = "AND s.id > $cursorLit ";
			}
			// Pull only the blobs the §4 search model actually uses for
			// indexable facets or has_* flags. Other blobs (json_inferences,
			// json_sed, json_other_features, json__3d_structures,
			// json_rock_unit) are intentionally NOT pulled — they would
			// inflate payload (some spots carry 5MB blobs) for no facet gain.
			// rock_types come from tags, not from json_rock_unit. The
			// searchtext_tsv bag-of-words uses names + notes only —
			// per Phase 0.1 the buried blobs become TYPED facets, not bag.
			// Tag map projection: collect only the 7 properties
			// buildRockTypePath uses. Full Tag node collection inflates
			// payload (some tags carry ~25 properties incl. long
			// descriptions / map_unit_name / color / etc. that we don't
			// need) and was OOMing the Bolt stream channel.
			//
			// has_samples/has_microstructure/has_pet were also payload
			// sinks (some json_samples blobs are MB-sized). We compute the
			// boolean flag server-side and skip pulling the blob content.
			//
			// Walk anchored through User → HAS_PROJECT → Project → HAS_DATASET
			// → Dataset → HAS_SPOT → Spot per project_neo4j_user_anchored_walks.
			// This scopes Project/Dataset/Spot by the edge rather than by id
			// alone — Strabo ids are NOT unique (same id can exist on multiple
			// nodes owned by different users; matching by id alone returns ALL
			// same-id nodes and produces cross-product fan-out).
			$rows = $neodb->query("
				MATCH (u:User {userpkey: $upk})
				      -[:HAS_PROJECT]->(p:Project {id: $pidLit})
				      -[:HAS_DATASET]->(d:Dataset {id: $didLit})
				      -[:HAS_SPOT]->(s:Spot)
				WHERE 1=1 $cursorClause
				OPTIONAL MATCH (s)-[:IS_TAGGED]->(t:Tag {type:'geologic_unit'})
				OPTIONAL MATCH (s)-[hi:HAS_IMAGE]->()
				WITH s, collect(distinct {
					rock_type: t.rock_type,
					igneous_rock_class: t.igneous_rock_class,
					plutonic_rock_types: t.plutonic_rock_types,
					metamorphic_rock_types: t.metamorphic_rock_types,
					metamorphic_grade: t.metamorphic_grade,
					sedimentary_rock_type: t.sedimentary_rock_type,
					sediment_type: t.sediment_type
				}) AS tags, count(distinct hi) AS image_count
				ORDER BY s.id
				RETURN s.id AS sid, s.userpkey AS suk, s.name AS sname,
				       substring(toString(s.json_orientation_data), 0, 100000) AS jod,
				       substring(toString(s.orientation_data), 0, 100000) AS od_legacy,
				       substring(toString(s.json_trace), 0, 100000) AS jtr,
				       (s.json_samples IS NOT NULL AND s.json_samples <> '') AS hsamples,
				       ((s.json_microstructure IS NOT NULL AND s.json_microstructure <> '')
				        OR (s.json_pet IS NOT NULL AND s.json_pet <> '')) AS hmicro,
				       substring(toString(s.notes), 0, 10000) AS notes,
				       s.wkt AS wkt, s.modified_timestamp AS mt,
				       s.date AS date_str, s.strat_section_id AS strat_id,
				       s.image_basemap AS image_basemap,
				       tags, image_count
				LIMIT $BATCH_SIZE
			");
			if (!$rows) break;

			foreach ($rows as $r) {
				$spotId = $r->get('sid');
				if ($spotId === null) { $totalSkipped++; continue; }
				$cursor = $spotId;  // preserve original type for keyset comparison

				// ispublic comes from the pre-fetched PG project map (Neo4j
				// Project.ispublic is NULL everywhere on prod). Unknown projects
				// (not yet in PG mirror) default to private.
				$pispubBool = !empty($pgPubMap[(string)$pid]);

			// --- orientation parse (dual conventions per Phase 0.2 census)
			$od = safeJsonDecode($r->get('jod'));
			if ($od === null) {
				// bare orientation_data is the 2016-era convention; still seen
				$od = safeJsonDecode($r->get('od_legacy'));
			}
			$strikes = $dips = $trends = $plunges = array();
			$featTypes = $planars = array();
			$hasOrientation = false;
			if (is_array($od)) {
				$hasOrientation = !empty($od);
				foreach ($od as $el) {
					if (!is_object($el)) continue;
					if (isset($el->strike) && is_numeric($el->strike)) $strikes[] = (float)$el->strike;
					if (isset($el->dip)    && is_numeric($el->dip))    $dips[]    = (float)$el->dip;
					if (isset($el->trend)  && is_numeric($el->trend))  $trends[]  = (float)$el->trend;
					if (isset($el->plunge) && is_numeric($el->plunge)) $plunges[] = (float)$el->plunge;
					if (isset($el->feature_type)) $featTypes[] = (string)$el->feature_type;
					// type field tells us planar vs linear
					if (isset($el->type)) {
						$t = (string)$el->type;
						$planars[] = (strpos($t, 'linear') === false);  // default planar
					}
				}
			} elseif (is_object($od)) {
				$hasOrientation = true;  // single-object form (rare; Phase 0.2 saw it)
			}

			// --- rock_types + met_facies from tags
			$rockTypes = array();
			$metFacies = array();
			$tagList = $r->get('tags');
			if (is_array($tagList)) {
				foreach ($tagList as $tag) {
					if ($tag === null) continue;
					list($path, $facies) = buildRockTypePath($tag);
					if ($path !== '') $rockTypes[] = $path;
					if ($facies !== '') $metFacies[] = $facies;
				}
			}
			$rockTypes = array_values(array_unique($rockTypes));
			$metFacies = array_values(array_unique($metFacies));

			// --- trace_types from json_trace (key is `trace_type`, not
			// the legacy-misread `trace_feature_type` — see buildTracePath
			// docblock + feedback_verify_property_name_before_assuming_source).
			// json_trace is typically a single object per spot (not an
			// array like json_orientation_data), but the array-of-objects
			// shape has been seen historically; handle both.
			$traceTypes = array();
			$jtr = safeJsonDecode($r->get('jtr'));
			if (is_array($jtr)) {
				foreach ($jtr as $el) {
					$p = buildTracePath($el);
					if ($p !== '') $traceTypes[] = $p;
				}
			} elseif (is_object($jtr)) {
				$p = buildTracePath($jtr);
				if ($p !== '') $traceTypes[] = $p;
			}
			$traceTypes = array_values(array_unique($traceTypes));

			// --- has_* flags (server-side booleans skip pulling MB-sized blobs)
			$has_orientation    = $hasOrientation;
			$has_samples        = (bool)$r->get('hsamples');
			$has_images         = ((int)$r->get('image_count') > 0);
			$has_microstructure = (bool)$r->get('hmicro');
			$has_strat          = !empty($r->get('strat_id')) || !empty($r->get('image_basemap'));

			// --- date_value: prefer parsed properties.date; fallback to mt epoch.
			// Validate ISO-8601 prefix shape — Field carries garbage dates
			// in the wild ('0000-00-00', '1932-00-00') that poison whole batches.
			$dateLit = 'NULL';
			$dateStr = $r->get('date_str');
			if (is_string($dateStr) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])/', $dateStr, $dm)) {
				$dateLit = "'" . $dm[1] . '-' . $dm[2] . '-' . $dm[3] . "'::date";
			} else {
				// pgTimestamp handles ms-vs-s epoch detection (Field's
				// modified_timestamp is ms-epoch since the 2024-ish client
				// revision; older spots used seconds).
				$tsLit = pgTimestamp($r->get('mt'));
				if ($tsLit !== 'NULL') $dateLit = '(' . $tsLit . ')::date';
			}

			// --- searchtext: flatten the spot.name + project.name + dataset.name + notes
			//     + every blob we pulled, for U1 keyword fidelity to /fullsearch's
			//     existing getKeywords behavior.
			$bagParts = array();
			$bagParts[] = (string)$r->get('sname');
			$bagParts[] = (string)$pname;
			$bagParts[] = (string)$dname;
			$bagParts[] = (string)$r->get('notes');
			// Names + notes only. Buried-blob content becomes TYPED facets
			// (orientation arrays, rock_types, trace_types), NOT bag-of-words
			// — per Phase 0.1 autopsy + §4 design intent.
			$searchText = implode(' ', $bagParts);

			// --- assemble VALUES tuple
			$values = '(' . implode(',', array(
				pgText('spot'),
				pgText((string)$spotId, 64),
				pgInt($r->get('suk')),
				pgText((string)$pid, 64),
				pgInt($puk),
				pgText('field'),
				pgText($pname, 500),
				pgBool($pispubBool),
				pgCentroidFromWkt($r->get('wkt')),
				$dateLit,
				pgTsvector($searchText),
				pgBool($has_orientation),
				pgBool($has_samples),
				pgBool($has_images),
				pgBool($has_microstructure),
				pgBool($has_strat),
				pgNumericArray($strikes),
				pgNumericArray($dips),
				pgNumericArray($trends),
				pgNumericArray($plunges),
				pgTextArray($featTypes),
				pgBoolArray($planars),
				pgTextArray($rockTypes),
				pgTextArray($metFacies),
				pgTextArray($traceTypes),
				pgTimestamp($r->get('mt')),
			)) . ')';

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
	$ins = swapStagingInto($db, 'strabosearch.item_hit', 'strabosearch.item_hit_staging_field',
		$slice, $INSERT_COLS,
		array('item_type', 'item_id', 'item_userpkey', 'project_id', 'project_userpkey', 'project_subsystem'));
	if ($ins === null) {
		line('  SWAP FAILED: ' . $db->last_error);
		exit(1);
	}
	line(sprintf('  swapped:  %s rows into item_hit', number_format($ins)));
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
