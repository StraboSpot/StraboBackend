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
// Single source of truth for staging INSERT and swap projection.
// Micro-native cols (minerals/mineral_methods/instrument_type/detector_type)
// are NOT in this list — they stay NULL on Field rows and the Micro
// extractor's swap will project them separately.
$INSERT_COLS = array(
	'image_id', 'image_subsystem', 'image_userpkey',
	'image_type', 'annotated', 'title', 'caption', 'imagetext_tsv', 'filename',
	'parent_spot_id', 'parent_sample_id',
	'project_id', 'project_userpkey', 'project_subsystem', 'project_ispublic',
	'location', 'date_value',
	'orientation_strike', 'orientation_dip', 'orientation_trend', 'orientation_plunge',
	'orientation_features', 'orientation_planar',
	'rock_types', 'met_facies', 'trace_types',
	'source_modified',
);
$buf = new BulkInsertBuffer($db,
	"INSERT INTO strabosearch.image_hit_staging_field (" . implode(', ', $INSERT_COLS) . ") VALUES ",
	500);

// ---------------------------------------------------------------------------
section('2. Pre-fetch PG project metadata');

$pgPubMap = array();
$rows = $db->get_results("SELECT strabo_project_id, ispublic FROM project");
foreach ((array)$rows as $r) {
	$pgPubMap[(string)$r->strabo_project_id] = ($r->ispublic === 't' || $r->ispublic === true);
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

		// Numeric ids → numeric literals; string ids → escaped + quoted.
		// Same pattern as field.php (Strabo ids are LONGs on prod; quoting
		// them yields string-vs-long mismatch with the indexed property).
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

			// Walk anchored through User → HAS_PROJECT → Project → HAS_DATASET
			// → Dataset → HAS_SPOT → Spot — same as field.php.
			// Then the inner MATCH s-HAS_IMAGE-i filters out image-less spots.
			// DISTINCT s + ORDER BY s.id + LIMIT pages by spot id.
			//
			// Per-spot collection: collect images + tags in one go so the
			// PHP loop can parse the spot context once and apply it to every
			// image. Avoids re-parsing per-image when a spot has 5 images.
			$rows = $neodb->query("
				MATCH (u:User {userpkey: $upk})
				      -[:HAS_PROJECT]->(p:Project {id: $pidLit})
				      -[:HAS_DATASET]->(d:Dataset {id: $didLit})
				      -[:HAS_SPOT]->(s:Spot)
				WHERE 1=1 $cursorClause
				MATCH (s)-[:HAS_IMAGE]->(:Image)
				WITH DISTINCT s ORDER BY s.id LIMIT $BATCH_SIZE
				MATCH (s)-[:HAS_IMAGE]->(i:Image)
				OPTIONAL MATCH (s)-[:IS_TAGGED]->(t:Tag {type:'geologic_unit'})
				WITH s, collect(distinct {
					id: i.id, userpkey: i.userpkey,
					image_type: i.image_type, title: i.title, caption: i.caption,
					annotated: i.annotated, filename: i.filename,
					modified_timestamp: i.modified_timestamp
				}) AS images, collect(distinct {
					rock_type: t.rock_type,
					igneous_rock_class: t.igneous_rock_class,
					plutonic_rock_types: t.plutonic_rock_types,
					metamorphic_rock_types: t.metamorphic_rock_types,
					metamorphic_grade: t.metamorphic_grade,
					sedimentary_rock_type: t.sedimentary_rock_type,
					sediment_type: t.sediment_type
				}) AS tags
				RETURN s.id AS sid, s.userpkey AS suk,
				       substring(toString(s.json_orientation_data), 0, 100000) AS jod,
				       substring(toString(s.orientation_data), 0, 100000) AS od_legacy,
				       substring(toString(s.json_trace), 0, 100000) AS jtr,
				       s.wkt AS wkt, s.modified_timestamp AS smt,
				       s.date AS date_str,
				       images, tags
				ORDER BY s.id
			");
			if (!$rows) break;

			foreach ($rows as $r) {
				$spotId = $r->get('sid');
				if ($spotId === null) continue;
				$cursor = $spotId;

				// ----- Parse spot context ONCE per spot ---------------------
				$pispubBool = !empty($pgPubMap[(string)$pid]);

				// Orientation arrays (dual-conventions per Phase 0.2 census)
				$od = safeJsonDecode($r->get('jod'));
				if ($od === null) $od = safeJsonDecode($r->get('od_legacy'));
				$strikes = $dips = $trends = $plunges = array();
				$featTypes = $planars = array();
				if (is_array($od)) {
					foreach ($od as $el) {
						if (!is_object($el)) continue;
						if (isset($el->strike) && is_numeric($el->strike)) $strikes[] = (float)$el->strike;
						if (isset($el->dip)    && is_numeric($el->dip))    $dips[]    = (float)$el->dip;
						if (isset($el->trend)  && is_numeric($el->trend))  $trends[]  = (float)$el->trend;
						if (isset($el->plunge) && is_numeric($el->plunge)) $plunges[] = (float)$el->plunge;
						if (isset($el->feature_type)) $featTypes[] = (string)$el->feature_type;
						if (isset($el->type)) {
							$t = (string)$el->type;
							$planars[] = (strpos($t, 'linear') === false);
						}
					}
				}

				// rock_types + met_facies from tags
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

				// trace_types from json_trace — key is `trace_type` (not
				// `trace_feature_type`). See buildTracePath docblock.
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

				// date_value (validated ISO-8601 prefix; epoch fallback)
				$dateLit = 'NULL';
				$dateStr = $r->get('date_str');
				if (is_string($dateStr) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])/', $dateStr, $dm)) {
					$dateLit = "'" . $dm[1] . '-' . $dm[2] . '-' . $dm[3] . "'::date";
				} else {
					$tsLit = pgTimestamp($r->get('smt'));
					if ($tsLit !== 'NULL') $dateLit = '(' . $tsLit . ')::date';
				}

				$locLit = pgCentroidFromWkt($r->get('wkt'));
				$spotModLit = pgTimestamp($r->get('smt'));
				$orStrikeLit  = pgNumericArray($strikes);
				$orDipLit     = pgNumericArray($dips);
				$orTrendLit   = pgNumericArray($trends);
				$orPlungeLit  = pgNumericArray($plunges);
				$orFeatLit    = pgTextArray($featTypes);
				$orPlanarLit  = pgBoolArray($planars);
				$rockLit      = pgTextArray($rockTypes);
				$facLit       = pgTextArray($metFacies);
				$traceLit     = pgTextArray($traceTypes);

				$totalSpotsWalked++;

				// ----- Emit one row per image of this spot ------------------
				$images = $r->get('images');
				if (!is_array($images)) continue;

				foreach ($images as $img) {
					if (!is_object($img) && !is_array($img)) continue;
					$imgGet = function ($k) use ($img) {
						if (is_object($img) && method_exists($img, 'get')) {
							try { return $img->get($k); } catch (\Exception $e) { return null; }
						}
						if (is_object($img)) return isset($img->$k) ? $img->$k : null;
						if (is_array($img))  return isset($img[$k]) ? $img[$k] : null;
						return null;
					};

					$iid = $imgGet('id');
					if ($iid === null || $iid === '') { $totalSkippedNoId++; continue; }

					// §5.5 servability gate: no filename ⇒ unservable ⇒ skip.
					// Per 0.4 audit: 43k images have no filename. They cannot
					// be served, so they cannot appear in search results.
					$filename = $imgGet('filename');
					if ($filename === null || trim((string)$filename) === '') {
						$totalSkippedNoFn++;
						continue;
					}

					// Image userpkey is mixed-type on prod (3 string-typed).
					// pgInt coerces via (int) which handles both numeric and
					// string-numeric. Null/non-numeric collapses to NULL.
					$iuk = $imgGet('userpkey');

					// image_type normalization via §4.5 vocab mapping.
					// Collect raw → unified pairs for end-of-run upsert into
					// vocab_image_type.
					$rawType = $imgGet('image_type');
					$unifiedType = normalizeFieldImageType($rawType);
					if ($rawType !== null && $rawType !== '') {
						$vocabSeen[(string)$rawType] = $unifiedType;
					}

					// annotated is stored as "1" (true) or "" (false) on
					// prod; coerce string → bool, NULL preserved.
					$annRaw = $imgGet('annotated');
					$annLit = pgBoolFromAnnotated($annRaw);

					$title   = $imgGet('title');
					$caption = $imgGet('caption');
					$imageText = trim((string)$title . ' ' . (string)$caption);
					$imageTextLit = pgTsvector($imageText);

					$imgMtLit = pgTimestamp($imgGet('modified_timestamp'));
					// source_modified preference: image's own mtime; fall
					// back to spot's mtime if image carries none.
					$sourceModLit = ($imgMtLit !== 'NULL') ? $imgMtLit : $spotModLit;

					$values = '(' . implode(',', array(
						pgText((string)$iid, 64),
						pgText('field'),
						pgInt($iuk),
						pgText($unifiedType),
						$annLit,
						pgText($title),
						pgText($caption),
						$imageTextLit,
						pgText($filename, 500),
						pgText((string)$spotId, 64),
						'NULL',                  // parent_sample_id (filled by samples extractor)
						pgText((string)$pid, 64),
						pgInt($puk),
						pgText('field'),
						pgBool($pispubBool),
						$locLit,
						$dateLit,
						$orStrikeLit, $orDipLit, $orTrendLit, $orPlungeLit,
						$orFeatLit, $orPlanarLit,
						$rockLit, $facLit, $traceLit,
						$sourceModLit,
					)) . ')';

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
		$inserted = 0;
		foreach ($vocabSeen as $raw => $unified) {
			$rawEsc     = pg_escape_string((string)$raw);
			$unifiedEsc = pg_escape_string((string)$unified);
			$db->query("INSERT INTO strabosearch.vocab_image_type (subsystem, normalized_from, unified_value)
				VALUES ('field', '$rawEsc', '$unifiedEsc')
				ON CONFLICT (subsystem, normalized_from) DO UPDATE SET unified_value = EXCLUDED.unified_value");
			$inserted++;
		}
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

// ===========================================================================
// Helpers — local to this extractor (Field image-type vocab + annotated coerce)
// ===========================================================================

/**
 * Map a raw Field :Image.image_type value to the §4.5 unified vocabulary.
 * 5 named buckets ('photo'/'sketch'/'thin_section'/'outcrop'/'sample') with
 * everything else falling to 'other'. NULL/empty raw collapses to NULL
 * (no row emitted for vocab_image_type either).
 *
 * The mapping is explicit and exhaustive — adding a new bucket requires a
 * design change + this function update, not a config tweak.
 */
function normalizeFieldImageType($raw) {
	if ($raw === null || $raw === '' || $raw === false) return null;
	$v = strtolower(trim((string)$raw));
	static $direct = array(
		'photo'        => 'photo',
		'sketch'       => 'sketch',
		'thin_section' => 'thin_section',
		'outcrop'      => 'outcrop',
		'sample'       => 'sample',
	);
	return isset($direct[$v]) ? $direct[$v] : 'other';
}

/**
 * Coerce the stored 'annotated' value to a PG bool literal. Prod stores
 * "1" for true, "" or absent for false. Returns the literal string ready
 * to embed in VALUES.
 */
function pgBoolFromAnnotated($v) {
	if ($v === null) return 'NULL';
	$s = (string)$v;
	if ($s === '') return 'FALSE';
	return ($s === '1' || strtolower($s) === 'true') ? 'TRUE' : 'FALSE';
}
?>
