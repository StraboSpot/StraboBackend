<?php
/**
 * StraboSearch — shared per-item row builders.
 *
 * Factored out of the Phase 2 extractors (field.php / field_images.php /
 * micro.php / exp.php / samples.php) so the SAME extraction logic serves
 * both:
 *
 *   1. the full-backfill extractors (batch loops → staging → atomic swap),
 *   2. the §5.3 incremental live-sync (StraboSearchSync::touch* — single-item
 *      re-extract → ON CONFLICT upsert).
 *
 * This is the anti-drift guarantee of §5.3.2: backfill and live-sync can
 * never disagree about how a source row maps to an index row, because there
 * is exactly one implementation of that mapping — this file.
 *
 * Contents per subsystem:
 *   - <src>ItemCols()/<src>ImageCols():   INSERT column lists (single source
 *     of truth for staging INSERT, swap projection, and sync upsert).
 *   - <src>SourceSql($whereExtra) / <src>*Cypher(...): the source queries,
 *     parameterized on an extra WHERE fragment (backfill passes the
 *     --source-userpkey filter or ''; sync passes a single-item filter).
 *   - <src>Tuple(s)(...): source row → "(...)" VALUES-tuple literal(s).
 *
 * Everything here is PURE string/array building except the two vocab
 * upsert helpers at the bottom (which take $db). No echo, no top-level
 * side effects — safe to require from web-request context (the sync hooks)
 * as well as from the CLI extractors.
 *
 * @package StraboSearch
 */

require_once(__DIR__ . '/_extractor_lib.php');

// ===========================================================================
// Column lists — single source of truth per (subsystem, target table).
// Excludes the bigserial PK and last_synced (defaulted / set by upsert).
// ===========================================================================

function fieldItemCols() {
	return array(
		'item_type', 'item_id', 'item_userpkey',
		'project_id', 'project_userpkey', 'project_subsystem', 'project_name', 'project_ispublic',
		'location', 'date_value', 'searchtext_tsv',
		'has_orientation', 'has_samples', 'has_images', 'has_microstructure', 'has_strat',
		'orientation_strike', 'orientation_dip', 'orientation_trend', 'orientation_plunge',
		'orientation_features', 'orientation_planar',
		'rock_types', 'met_facies', 'trace_types',
		'tag_names', 'tag_types', 'tag_text_tsv',
		'source_modified',
	);
}

function fieldImageCols() {
	return array(
		'image_id', 'image_subsystem', 'image_userpkey',
		'image_type', 'annotated', 'title', 'caption', 'imagetext_tsv', 'filename',
		'parent_spot_id', 'parent_sample_id',
		'project_id', 'project_userpkey', 'project_subsystem', 'project_ispublic',
		'location', 'date_value',
		'orientation_strike', 'orientation_dip', 'orientation_trend', 'orientation_plunge',
		'orientation_features', 'orientation_planar',
		'rock_types', 'met_facies', 'trace_types',
		'tag_names', 'tag_types', 'tag_text_tsv',
		'source_modified',
	);
}

function microItemCols() {
	return array(
		'item_type', 'item_id', 'item_userpkey',
		'project_id', 'project_userpkey', 'project_subsystem', 'project_name', 'project_ispublic',
		'location', 'date_value', 'searchtext_tsv',
		'has_orientation', 'has_samples', 'has_images', 'has_microstructure', 'has_strat',
		'minerals', 'mineral_methods', 'instrument_type', 'detector_type',
		'tag_names', 'tag_text_tsv',
		'source_modified',
	);
}

function microImageCols() {
	return array(
		'image_id', 'image_subsystem', 'image_userpkey',
		'image_type', 'annotated', 'title', 'caption', 'imagetext_tsv', 'filename',
		'parent_spot_id', 'parent_sample_id',
		'project_id', 'project_userpkey', 'project_subsystem', 'project_ispublic',
		'location', 'date_value',
		'minerals', 'mineral_methods', 'instrument_type', 'detector_type',
		'tag_names', 'tag_text_tsv',
		'source_modified',
	);
}

function expItemCols() {
	return array(
		'item_type', 'item_id', 'item_userpkey',
		'project_id', 'project_userpkey', 'project_subsystem', 'project_name', 'project_ispublic',
		'location', 'date_value', 'searchtext_tsv',
		'has_orientation', 'has_samples', 'has_images', 'has_microstructure', 'has_strat',
		'apparatus_type', 'daq_sensor_type', 'measurement_type',
		'source_modified',
	);
}

function samplesItemCols() {
	return array(
		'item_type', 'item_id', 'item_userpkey',
		'project_id', 'project_userpkey', 'project_subsystem', 'project_name', 'project_ispublic',
		'location', 'date_value', 'searchtext_tsv',
		'sample_id', 'sample_name', 'igsn', 'display_sample_type', 'display_sample_purpose',
		'has_orientation', 'has_samples', 'has_images', 'has_microstructure', 'has_strat',
		'source_modified',
	);
}

// ===========================================================================
// Neo4j literal helper — Strabo Project/Dataset/Spot ids are LONGs on prod;
// quoting a numeric id yields string-vs-long mismatch against the indexed
// property. Emit numeric literal when purely-numeric, quoted+escaped
// string otherwise. (Was inlined 4× across field.php / field_images.php.)
// ===========================================================================

function neoIdLiteral($id) {
	if (ctype_digit((string)$id)) return (string)$id;
	// CYPHER string escaping is backslash-based. The previous
	// pg_escape_string here used PG-style quote-DOUBLING, which produces a
	// Cypher syntax error for quote-bearing input — and a failed query
	// leaves the GraphAware Bolt connection permanently hung for the next
	// caller (see StraboDbNeo4j::reconnect). Real Strabo ids are numeric;
	// this branch guards legacy/garbage input.
	return "'" . str_replace(array("\\", "'"), array("\\\\", "\\'"), (string)$id) . "'";
}

// ===========================================================================
// FIELD — Cypher builders
// ===========================================================================

/**
 * Per-(project, dataset) batch pull — the field.php backfill inner query,
 * verbatim. Keyset-paginates by s.id via $cursorClause.
 *
 * Pull only the blobs the §4 search model actually uses for indexable
 * facets or has_* flags. Other blobs (json_inferences, json_sed,
 * json_other_features, json__3d_structures, json_rock_unit) are
 * intentionally NOT pulled — they would inflate payload (some spots carry
 * 5MB blobs) for no facet gain. rock_types come from tags, not from
 * json_rock_unit. Tag map projection collects only the 7 properties
 * buildRockTypePath uses (full Tag node collection was OOMing the Bolt
 * stream channel). has_samples/has_microstructure computed server-side to
 * skip pulling MB-sized blobs.
 *
 * Walk anchored through User → HAS_PROJECT → Project → HAS_DATASET →
 * Dataset → HAS_SPOT → Spot per project_neo4j_user_anchored_walks —
 * Strabo ids are NOT unique; the HAS_PROJECT edge is the authoritative
 * ownership relationship.
 */
function fieldSpotBatchCypher($upk, $pidLit, $didLit, $cursorClause, $batchSize) {
	return "
		MATCH (u:User {userpkey: $upk})
		      -[:HAS_PROJECT]->(p:Project {id: $pidLit})
		      -[:HAS_DATASET]->(d:Dataset {id: $didLit})
		      -[:HAS_SPOT]->(s:Spot)
		WHERE 1=1 $cursorClause
		OPTIONAL MATCH (s)-[:IS_TAGGED]->(t:Tag {type:'geologic_unit'})
		OPTIONAL MATCH (s)-[:IS_TAGGED]->(at:Tag)
		OPTIONAL MATCH (s)-[hi:HAS_IMAGE]->()
		WITH s, collect(distinct {
			rock_type: t.rock_type,
			igneous_rock_class: t.igneous_rock_class,
			plutonic_rock_types: t.plutonic_rock_types,
			metamorphic_rock_types: t.metamorphic_rock_types,
			metamorphic_grade: t.metamorphic_grade,
			sedimentary_rock_type: t.sedimentary_rock_type,
			sediment_type: t.sediment_type
		}) AS tags,
		collect(distinct {name: at.name, type: at.type}) AS all_tags,
		count(distinct hi) AS image_count
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
		       tags, all_tags, image_count
		LIMIT $batchSize
	";
}

/**
 * Single-spot touch pull for incremental sync (§5.3.2). Same field set as
 * the batch query, PLUS the project/dataset context columns the backfill
 * loop carries externally — the sync path has no outer (project, dataset)
 * loop, so every row is self-describing. One row per
 * (project, dataset) path that reaches the spot; a spot linked into two
 * datasets of one project yields two rows collapsing to one item_hit row
 * at upsert (same 6-tuple).
 */
function fieldSpotTouchCypher($upk, $sidLit) {
	return "
		MATCH (u:User {userpkey: $upk})
		      -[:HAS_PROJECT]->(p:Project)
		      -[:HAS_DATASET]->(d:Dataset)
		      -[:HAS_SPOT]->(s:Spot {id: $sidLit})
		OPTIONAL MATCH (s)-[:IS_TAGGED]->(t:Tag {type:'geologic_unit'})
		OPTIONAL MATCH (s)-[:IS_TAGGED]->(at:Tag)
		OPTIONAL MATCH (s)-[hi:HAS_IMAGE]->()
		WITH s, p, d, collect(distinct {
			rock_type: t.rock_type,
			igneous_rock_class: t.igneous_rock_class,
			plutonic_rock_types: t.plutonic_rock_types,
			metamorphic_rock_types: t.metamorphic_rock_types,
			metamorphic_grade: t.metamorphic_grade,
			sedimentary_rock_type: t.sedimentary_rock_type,
			sediment_type: t.sediment_type
		}) AS tags,
		collect(distinct {name: at.name, type: at.type}) AS all_tags,
		count(distinct hi) AS image_count
		RETURN p.id AS pid, coalesce(p.desc_project_name, p.projectname) AS pname,
		       d.id AS did, d.name AS dname,
		       s.id AS sid, s.userpkey AS suk, s.name AS sname,
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
		       tags, all_tags, image_count
	";
}

/**
 * Per-(project, dataset) batch pull for Field images — the
 * field_images.php backfill inner query, verbatim. Per-spot collection:
 * images + tags collected in one go so the PHP loop parses the spot
 * context once and applies it to every image.
 */
function fieldImagesBatchCypher($upk, $pidLit, $didLit, $cursorClause, $batchSize) {
	return "
		MATCH (u:User {userpkey: $upk})
		      -[:HAS_PROJECT]->(p:Project {id: $pidLit})
		      -[:HAS_DATASET]->(d:Dataset {id: $didLit})
		      -[:HAS_SPOT]->(s:Spot)
		WHERE 1=1 $cursorClause
		MATCH (s)-[:HAS_IMAGE]->(:Image)
		WITH DISTINCT s ORDER BY s.id LIMIT $batchSize
		MATCH (s)-[:HAS_IMAGE]->(i:Image)
		OPTIONAL MATCH (s)-[:IS_TAGGED]->(t:Tag {type:'geologic_unit'})
		OPTIONAL MATCH (s)-[:IS_TAGGED]->(at:Tag)
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
		}) AS tags,
		collect(distinct {name: at.name, type: at.type}) AS all_tags
		RETURN s.id AS sid, s.userpkey AS suk,
		       substring(toString(s.json_orientation_data), 0, 100000) AS jod,
		       substring(toString(s.orientation_data), 0, 100000) AS od_legacy,
		       substring(toString(s.json_trace), 0, 100000) AS jtr,
		       s.wkt AS wkt, s.modified_timestamp AS smt,
		       s.date AS date_str,
		       images, tags, all_tags
		ORDER BY s.id
	";
}

/**
 * Single-spot image touch pull for incremental sync — same field set as
 * the batch query plus self-describing project context (pid). Rows fan
 * out per (project, dataset) path like fieldSpotTouchCypher; duplicate
 * image tuples collapse at upsert (identity 3-tuple).
 */
function fieldImagesTouchCypher($upk, $sidLit) {
	return "
		MATCH (u:User {userpkey: $upk})
		      -[:HAS_PROJECT]->(p:Project)
		      -[:HAS_DATASET]->(d:Dataset)
		      -[:HAS_SPOT]->(s:Spot {id: $sidLit})
		MATCH (s)-[:HAS_IMAGE]->(i:Image)
		OPTIONAL MATCH (s)-[:IS_TAGGED]->(t:Tag {type:'geologic_unit'})
		OPTIONAL MATCH (s)-[:IS_TAGGED]->(at:Tag)
		WITH s, p, collect(distinct {
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
		}) AS tags,
		collect(distinct {name: at.name, type: at.type}) AS all_tags
		RETURN p.id AS pid,
		       s.id AS sid, s.userpkey AS suk,
		       substring(toString(s.json_orientation_data), 0, 100000) AS jod,
		       substring(toString(s.orientation_data), 0, 100000) AS od_legacy,
		       substring(toString(s.json_trace), 0, 100000) AS jtr,
		       s.wkt AS wkt, s.modified_timestamp AS smt,
		       s.date AS date_str,
		       images, tags, all_tags
	";
}

// ===========================================================================
// FIELD — tuple builders
// ===========================================================================

/**
 * Parse the tag map collections a Field Cypher pull returns (`tags` =
 * geologic_unit projection for F7 rock_types, `all_tags` = every attached
 * tag's name+type for U10/F11). Shared by the spot and image builders.
 *
 * Returns array($rockTypes, $metFacies, $tagNames, $tagTypes) — all
 * deduped, deny-list (NULL/empty) applied.
 */
function fieldParseTagCollections($r, &$tagTypeVocabSeen) {
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

	$tagNames = array();
	$tagTypes = array();
	$allTagList = $r->get('all_tags');
	if (is_array($allTagList)) {
		foreach ($allTagList as $tg) {
			if ($tg === null) continue;
			$tn = null; $tt = null;
			if (is_object($tg) && method_exists($tg, 'get')) {
				try { $tn = $tg->get('name'); } catch (\Exception $e) {}
				try { $tt = $tg->get('type'); } catch (\Exception $e) {}
			} elseif (is_object($tg)) {
				$tn = isset($tg->name) ? $tg->name : null;
				$tt = isset($tg->type) ? $tg->type : null;
			} elseif (is_array($tg)) {
				$tn = isset($tg['name']) ? $tg['name'] : null;
				$tt = isset($tg['type']) ? $tg['type'] : null;
			}
			if ($tn !== null && $tn !== '') $tagNames[] = (string)$tn;
			if ($tt !== null && $tt !== '') {
				$tagTypes[] = (string)$tt;
				$tagTypeVocabSeen[(string)$tt] = true;
			}
		}
	}
	$tagNames = array_values(array_unique($tagNames));
	$tagTypes = array_values(array_unique($tagTypes));

	return array($rockTypes, $metFacies, $tagNames, $tagTypes);
}

/**
 * Parse json_orientation_data (dual conventions per Phase 0.2 census —
 * bare orientation_data is the 2016-era fallback). Returns
 * array($hasOrientation, $strikes, $dips, $trends, $plunges, $featTypes,
 * $planars).
 */
function fieldParseOrientation($r) {
	$od = safeJsonDecode($r->get('jod'));
	if ($od === null) {
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
	return array($hasOrientation, $strikes, $dips, $trends, $plunges, $featTypes, $planars);
}

/**
 * Parse json_trace into trace-type facet paths. json_trace is typically a
 * single object per spot, but the array-of-objects shape has been seen
 * historically; handle both. Key is `trace_type`, not the legacy-misread
 * `trace_feature_type` — see buildTracePath docblock.
 */
function fieldParseTraceTypes($r) {
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
	return array_values(array_unique($traceTypes));
}

/**
 * date_value literal: prefer parsed properties.date; fallback to the
 * modified_timestamp epoch. Validates ISO-8601 prefix shape — Field
 * carries garbage dates in the wild ('0000-00-00', '1932-00-00') that
 * poison whole batches. $mtField names the Cypher alias carrying the
 * epoch ('mt' for spots, 'smt' in the image pull).
 */
function fieldDateLiteral($r, $mtField) {
	$dateLit = 'NULL';
	$dateStr = $r->get('date_str');
	if (is_string($dateStr) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])/', $dateStr, $dm)) {
		$dateLit = "'" . $dm[1] . '-' . $dm[2] . '-' . $dm[3] . "'::date";
	} else {
		$tsLit = pgTimestamp($r->get($mtField));
		if ($tsLit !== 'NULL') $dateLit = '(' . $tsLit . ')::date';
	}
	return $dateLit;
}

/**
 * One Field spot Cypher row → one item_hit VALUES tuple (column order =
 * fieldItemCols()). $r carries the spot fields from fieldSpot*Cypher;
 * project/dataset context arrives via the scalar params (the batch loop
 * carries it externally; the touch query returns it per-row and the
 * caller passes it through). Returns the "(...)" literal string.
 */
function fieldSpotTuple($r, $pid, $puk, $pname, $dname, $pispubBool, &$tagTypeVocabSeen) {
	$spotId = $r->get('sid');

	list($hasOrientation, $strikes, $dips, $trends, $plunges, $featTypes, $planars)
		= fieldParseOrientation($r);
	list($rockTypes, $metFacies, $tagNames, $tagTypes)
		= fieldParseTagCollections($r, $tagTypeVocabSeen);
	$traceTypes = fieldParseTraceTypes($r);

	// --- has_* flags (server-side booleans skip pulling MB-sized blobs)
	$has_orientation    = $hasOrientation;
	$has_samples        = (bool)$r->get('hsamples');
	$has_images         = ((int)$r->get('image_count') > 0);
	$has_microstructure = (bool)$r->get('hmicro');
	$has_strat          = !empty($r->get('strat_id')) || !empty($r->get('image_basemap'));

	$dateLit = fieldDateLiteral($r, 'mt');

	// --- searchtext: names + notes only. Buried-blob content becomes TYPED
	// facets (orientation arrays, rock_types, trace_types), NOT bag-of-words
	// — per Phase 0.1 autopsy + §4 design intent. Tag names ride along per
	// the U10 amendment (§4.3-Tag: U1 safety net).
	$bagParts = array();
	$bagParts[] = (string)$r->get('sname');
	$bagParts[] = (string)$pname;
	$bagParts[] = (string)$dname;
	$bagParts[] = (string)$r->get('notes');
	if ($tagNames) $bagParts[] = implode(' ', $tagNames);
	$searchText = implode(' ', $bagParts);

	return '(' . implode(',', array(
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
		pgTextArray($tagNames),
		pgTextArray($tagTypes),
		pgTsvector(implode(' ', $tagNames)),
		pgTimestamp($r->get('mt')),
	)) . ')';
}

/**
 * One Field spot-with-images Cypher row → N image_hit VALUES tuples
 * (column order = fieldImageCols()), one per servable image. Spot context
 * is parsed ONCE and inherited by every image (§6 Q4a + Q4b).
 *
 * $stats picks up 'no_filename' / 'no_id' skip counts (the §5.5
 * servability gate: no filename ⇒ unservable ⇒ skip).
 */
function fieldImageTuples($r, $spotId, $pid, $puk, $pispubBool, &$vocabSeen, &$stats) {
	$tagTypeVocabIgnored = array();  // image pull inherits tag types but F11 vocab is upserted by the spot pass
	list(, $strikes, $dips, $trends, $plunges, $featTypes, $planars)
		= fieldParseOrientation($r);
	list($rockTypes, $metFacies, $tagNames, $tagTypes)
		= fieldParseTagCollections($r, $tagTypeVocabIgnored);
	$traceTypes = fieldParseTraceTypes($r);

	$dateLit = fieldDateLiteral($r, 'smt');

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
	$tagNamesLit  = pgTextArray($tagNames);
	$tagTypesLit  = pgTextArray($tagTypes);
	$tagTsvLit    = pgTsvector(implode(' ', $tagNames));

	$tuples = array();
	$images = $r->get('images');
	if (!is_array($images)) return $tuples;

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
		if ($iid === null || $iid === '') { $stats['no_id']++; continue; }

		// §5.5 servability gate: no filename ⇒ unservable ⇒ skip. Per 0.4
		// audit: 43k images have no filename. They cannot be served, so
		// they cannot appear in search results.
		$filename = $imgGet('filename');
		if ($filename === null || trim((string)$filename) === '') {
			$stats['no_filename']++;
			continue;
		}

		// Image userpkey is mixed-type on prod (3 string-typed). pgInt
		// coerces via (int) which handles both numeric and string-numeric.
		$iuk = $imgGet('userpkey');

		// image_type normalization via §4.5 vocab mapping. Collect
		// raw → unified pairs for the vocab_image_type upsert.
		$rawType = $imgGet('image_type');
		$unifiedType = normalizeFieldImageType($rawType);
		if ($rawType !== null && $rawType !== '') {
			$vocabSeen[(string)$rawType] = $unifiedType;
		}

		// annotated is stored as "1" (true) or "" (false) on prod.
		$annLit = pgBoolFromAnnotated($imgGet('annotated'));

		$title   = $imgGet('title');
		$caption = $imgGet('caption');
		$imageText = trim((string)$title . ' ' . (string)$caption);
		$imageTextLit = pgTsvector($imageText);

		$imgMtLit = pgTimestamp($imgGet('modified_timestamp'));
		// source_modified preference: image's own mtime; fall back to
		// spot's mtime if image carries none.
		$sourceModLit = ($imgMtLit !== 'NULL') ? $imgMtLit : $spotModLit;

		$tuples[] = '(' . implode(',', array(
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
			$tagNamesLit, $tagTypesLit, $tagTsvLit,
			$sourceModLit,
		)) . ')';
	}
	return $tuples;
}

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

// ===========================================================================
// MICRO — source SQL + tuple builder
// ===========================================================================

/**
 * The Micro single source pass — chain join micrograph → sample → dataset
 * → project with LATERAL sub-selects for the fan-out facets. EXISTS chain
 * for has_microstructure covers the 8 most common structural tables
 * (pseudotachylyte / clastic bands / extinction / lithology omitted for
 * v1 — separate v2 facets, rarely fire stand-alone).
 *
 * $whereExtra: '' for the full backfill, "AND pm.userpkey = N" for
 * --source-userpkey, "AND pm.id = N" for a single-project sync touch.
 */
function microSourceSql($whereExtra) {
	return "
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
	  $whereExtra
	ORDER BY pm.userpkey, mm.id
	";
}

/**
 * One Micro source row → array($itemTuple, $imageTuple) (column orders =
 * microItemCols() / microImageCols()), or null when the row must be
 * skipped (no project strabo_id). The same micrograph feeds BOTH targets
 * — that is the consistency design: it lands in both or neither.
 */
function microTuples($r, &$vocabSeen) {
	if ($r->project_strabo_id === null || $r->project_strabo_id === '') {
		return null;
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
	// No tag_types (F11 is Field-only).
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

	// Native Micro arrays come back as PG array literals; parse and
	// re-emit via pgTextArray to normalize quoting + handle empties.
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

	return array($itemValues, $imageValues);
}

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

// ===========================================================================
// EXP — source SQL + tuple builder
// ===========================================================================

/**
 * The Exp single source pass — experiment + project + scalar sub-selects
 * for E1–E3 facets, plus the array_agg(distinct header_type) that feeds
 * the searchtext bag (keyword path keeps all measurement types reachable).
 *
 * $whereExtra: '' for the full backfill, "AND e.userpkey = N" for
 * --source-userpkey, "AND e.pkey = N" for a single-experiment sync touch,
 * "AND e.project_pkey = N" for a whole-project touch (rename refresh).
 */
function expSourceSql($whereExtra) {
	return "
	SELECT
		e.pkey AS exp_pkey,
		e.id   AS experiment_id,
		e.userpkey,
		e.modified_timestamp,
		p.uuid     AS project_uuid,
		p.name     AS project_name,
		p.ispublic AS project_ispublic,
		p.notes    AS project_notes,
		(SELECT a.type FROM straboexp.apparatus a
		 WHERE a.experiment_pkey = e.pkey AND a.type IS NOT NULL AND a.type <> ''
		 ORDER BY a.pkey LIMIT 1) AS apparatus_type,
		(SELECT a.name FROM straboexp.apparatus a
		 WHERE a.experiment_pkey = e.pkey ORDER BY a.pkey LIMIT 1) AS apparatus_name,
		(SELECT a.description FROM straboexp.apparatus a
		 WHERE a.experiment_pkey = e.pkey ORDER BY a.pkey LIMIT 1) AS apparatus_desc,
		(SELECT c.type FROM straboexp.daq_device_channel c
		 JOIN straboexp.daq_device dd ON dd.pkey = c.daq_device_pkey
		 JOIN straboexp.daq d ON d.pkey = dd.daq_pkey
		 WHERE d.experiment_pkey = e.pkey AND c.type IS NOT NULL AND c.type <> ''
		 ORDER BY c.pkey LIMIT 1) AS daq_sensor_type,
		(SELECT c.header_type FROM straboexp.daq_device_channel c
		 JOIN straboexp.daq_device dd ON dd.pkey = c.daq_device_pkey
		 JOIN straboexp.daq d ON d.pkey = dd.daq_pkey
		 WHERE d.experiment_pkey = e.pkey AND c.header_type IS NOT NULL AND c.header_type <> ''
		 ORDER BY c.pkey LIMIT 1) AS measurement_type,
		COALESCE((
			SELECT array_agg(DISTINCT c.header_type) FILTER (WHERE c.header_type IS NOT NULL AND c.header_type <> '')
			FROM straboexp.daq_device_channel c
			JOIN straboexp.daq_device dd ON dd.pkey = c.daq_device_pkey
			JOIN straboexp.daq d ON d.pkey = dd.daq_pkey
			WHERE d.experiment_pkey = e.pkey
		), ARRAY[]::TEXT[]) AS all_measurement_types,
		(SELECT f.name FROM straboexp.facility f
		 WHERE f.experiment_pkey = e.pkey ORDER BY f.pkey LIMIT 1) AS facility_name,
		(SELECT f.institute FROM straboexp.facility f
		 WHERE f.experiment_pkey = e.pkey ORDER BY f.pkey LIMIT 1) AS facility_institute,
		(SELECT f.latitude FROM straboexp.facility f
		 WHERE f.experiment_pkey = e.pkey AND f.latitude ~ '^-?[0-9.]+\$' AND f.longitude ~ '^-?[0-9.]+\$'
		 ORDER BY f.pkey LIMIT 1) AS facility_lat,
		(SELECT f.longitude FROM straboexp.facility f
		 WHERE f.experiment_pkey = e.pkey AND f.latitude ~ '^-?[0-9.]+\$' AND f.longitude ~ '^-?[0-9.]+\$'
		 ORDER BY f.pkey LIMIT 1) AS facility_lng,
		(SELECT s.provenance_loc_latitude FROM straboexp.sample s
		 WHERE s.experiment_pkey = e.pkey
		   AND s.provenance_loc_latitude  ~ '^-?[0-9.]+\$'
		   AND s.provenance_loc_longitude ~ '^-?[0-9.]+\$'
		 ORDER BY s.pkey LIMIT 1) AS sample_lat,
		(SELECT s.provenance_loc_longitude FROM straboexp.sample s
		 WHERE s.experiment_pkey = e.pkey
		   AND s.provenance_loc_latitude  ~ '^-?[0-9.]+\$'
		   AND s.provenance_loc_longitude ~ '^-?[0-9.]+\$'
		 ORDER BY s.pkey LIMIT 1) AS sample_lng,
		COALESCE((
			SELECT string_agg(DISTINCT coalesce(s.name, '') || ' ' || coalesce(s.material_name, '') || ' ' || coalesce(s.description, ''), ' ')
			FROM straboexp.sample s WHERE s.experiment_pkey = e.pkey
		), '') AS sample_bag,
		EXISTS (SELECT 1 FROM straboexp.sample s WHERE s.experiment_pkey = e.pkey) AS has_samples,
		(EXISTS (
			SELECT 1 FROM straboexp.document d
			JOIN straboexp.experiment_setup es ON es.pkey = d.experiment_setup_pkey
			WHERE es.experiment_pkey = e.pkey
		) OR EXISTS (
			SELECT 1 FROM straboexp.document d
			JOIN straboexp.apparatus a ON a.pkey = d.apparatus_pkey
			WHERE a.experiment_pkey = e.pkey
		)) AS has_images
	FROM straboexp.experiment e
	JOIN straboexp.project p ON p.pkey = e.project_pkey
	WHERE e.userpkey IS NOT NULL
	  AND e.id IS NOT NULL AND e.id <> ''
	  AND p.uuid IS NOT NULL AND p.uuid <> ''
	  $whereExtra
	ORDER BY e.userpkey, e.pkey
	";
}

/**
 * One Exp source row → one item_hit VALUES tuple (column order =
 * expItemCols()).
 */
function expTuple($r) {
	$expId       = (string)$r->experiment_id;
	$puk         = (int)$r->userpkey;
	$projectId   = (string)$r->project_uuid;
	$projectName = (string)$r->project_name;
	$projectPub  = ($r->project_ispublic === 't' || $r->project_ispublic === true);

	// Location: facility first, fall back to first sample provenance.
	$lat = null;
	$lng = null;
	if (is_numeric($r->facility_lat) && is_numeric($r->facility_lng)) {
		$lat = (float)$r->facility_lat;
		$lng = (float)$r->facility_lng;
	} elseif (is_numeric($r->sample_lat) && is_numeric($r->sample_lng)) {
		$lat = (float)$r->sample_lat;
		$lng = (float)$r->sample_lng;
	}
	$locLit = ($lat !== null && $lng !== null) ? pgPointLiteral($lng, $lat) : 'NULL';

	$mtTsLit = pgTimestamp($r->modified_timestamp);
	$dateLit = ($mtTsLit !== 'NULL') ? '(' . $mtTsLit . ')::date' : 'NULL';

	// searchtext bag — includes ALL distinct measurement types so the
	// keyword path covers experiments whose scalar measurement_type is
	// some other primary value but who do measure (e.g.) Pressure too.
	$allMeasures = pgParseTextArray($r->all_measurement_types);
	$bag = $projectName . ' ' . (string)$r->project_notes . ' '
		. $expId . ' '
		. (string)$r->apparatus_name . ' ' . (string)$r->apparatus_type . ' '
		. (string)$r->apparatus_desc . ' '
		. (string)$r->facility_name . ' ' . (string)$r->facility_institute . ' '
		. (string)$r->sample_bag . ' '
		. implode(' ', $allMeasures);
	$searchtext = pgTsvector($bag);

	$hasSamples = ($r->has_samples === 't' || $r->has_samples === true);
	$hasImages  = ($r->has_images  === 't' || $r->has_images  === true);

	return '(' . implode(',', array(
		pgText('experiment'),
		pgText($expId, 64),
		pgInt($puk),
		pgText($projectId, 64),
		pgInt($puk),
		pgText('exp'),
		pgText($projectName, 500),
		pgBool($projectPub),
		$locLit,
		$dateLit,
		$searchtext,
		pgBool(false),           // has_orientation
		pgBool($hasSamples),
		pgBool($hasImages),
		pgBool(false),           // has_microstructure
		pgBool(false),           // has_strat
		pgText($r->apparatus_type),
		pgText($r->daq_sensor_type),
		pgText($r->measurement_type),
		$mtTsLit,
	)) . ')';
}

// ===========================================================================
// SAMPLES — resolution SQL builders + tuple builder
// ===========================================================================

/**
 * Shared SELECT list for the spine columns every resolution query needs.
 */
function samplesSpineCols() {
	return "
	s.id            AS sample_id,
	s.userpkey      AS sample_userpkey,
	s.name          AS sample_name,
	s.igsn,
	s.description,
	s.notes,
	s.latitude,
	s.longitude,
	s.display_sample_type,
	s.display_sample_purpose,
	s.modified_at,
	s.custom_data::text AS custom_data_json";
}

/**
 * Field fan-out resolution — links resolved via the indexed Field slice
 * (JOIN strabosearch.item_hit ON the spot identity; this is WHY the
 * Samples backfill runs LAST per §5.2.2 and why a Field sync touch must
 * index the spot BEFORE re-touching its linked samples).
 *
 * $whereExtra filters on the spine: '' | "AND s.userpkey = N" |
 * "AND s.id = '...' AND s.userpkey = N".
 */
function samplesFieldSql($whereExtra) {
	$cols = samplesSpineCols();
	return "
	SELECT DISTINCT ON (s.id, s.userpkey, ih.project_id, ih.project_userpkey)
		$cols,
		ih.project_id,
		ih.project_userpkey,
		ih.project_name,
		ih.project_ispublic,
		ST_AsText(ih.location) AS host_loc_wkt
	FROM strabosamples.samples s
	JOIN strabosamples.sample_subsystem_links l
	  ON l.sample_id = s.id AND l.sample_userpkey = s.userpkey
	 AND l.subsystem = 'field'
	JOIN strabosearch.item_hit ih
	  ON ih.item_type = 'spot' AND ih.project_subsystem = 'field'
	 AND ih.item_id = l.reference_id AND ih.item_userpkey = l.reference_userpkey
	WHERE TRUE $whereExtra
	ORDER BY s.id, s.userpkey, ih.project_id, ih.project_userpkey
	";
}

/**
 * Micro fan-out resolution — reference_metadata->>'project_strabo_id' +
 * reference_userpkey → micro_projectmetadata. BOTH keys required: micro
 * strabo_id is NOT unique across users.
 */
function samplesMicroSql($whereExtra) {
	$cols = samplesSpineCols();
	return "
	SELECT DISTINCT ON (s.id, s.userpkey, pm.strabo_id, pm.userpkey)
		$cols,
		pm.strabo_id AS project_id,
		pm.userpkey  AS project_userpkey,
		pm.name      AS project_name,
		COALESCE(pm.ispublic, FALSE) AS project_ispublic
	FROM strabosamples.samples s
	JOIN strabosamples.sample_subsystem_links l
	  ON l.sample_id = s.id AND l.sample_userpkey = s.userpkey
	 AND l.subsystem = 'micro'
	JOIN strabomicro.micro_projectmetadata pm
	  ON pm.strabo_id = l.reference_metadata->>'project_strabo_id'
	 AND pm.userpkey  = l.reference_userpkey
	WHERE TRUE $whereExtra
	ORDER BY s.id, s.userpkey, pm.strabo_id, pm.userpkey
	";
}

/**
 * Exp fan-out resolution — reference_metadata->>'project_uuid' →
 * straboexp.project.uuid; project_userpkey = reference_userpkey (the
 * experiment owner — matching the Exp extractor's convention). Subsystem
 * value translated 'experimental' → 'exp' at emit time.
 */
function samplesExpSql($whereExtra) {
	$cols = samplesSpineCols();
	return "
	SELECT DISTINCT ON (s.id, s.userpkey, p.uuid, l.reference_userpkey)
		$cols,
		p.uuid               AS project_id,
		l.reference_userpkey AS project_userpkey,
		p.name               AS project_name,
		COALESCE(p.ispublic, FALSE) AS project_ispublic
	FROM strabosamples.samples s
	JOIN strabosamples.sample_subsystem_links l
	  ON l.sample_id = s.id AND l.sample_userpkey = s.userpkey
	 AND l.subsystem = 'experimental'
	JOIN straboexp.project p
	  ON p.uuid = l.reference_metadata->>'project_uuid'
	WHERE TRUE $whereExtra
	ORDER BY s.id, s.userpkey, p.uuid, l.reference_userpkey
	";
}

/**
 * One resolved (sample × host project) row → one item_hit VALUES tuple
 * (column order = samplesItemCols()). $r must carry the samplesSpineCols()
 * aliases + project_id / project_userpkey / project_name /
 * project_ispublic; $subsystem is the item_hit value ('field' | 'micro' |
 * 'exp'); $hostLocWkt is the host spot's location WKT for the field
 * fallback (null elsewhere).
 */
function sampleTuple($r, $subsystem, $hostLocWkt) {
	// Location: the sample's own coords win; field rows fall back to the
	// host spot's already-indexed point.
	$locLit = pgPointLiteral($r->longitude, $r->latitude);
	if ($locLit === 'NULL' && $hostLocWkt !== null && $hostLocWkt !== '') {
		$locLit = "ST_SetSRID(ST_GeomFromText('" . pg_escape_string($hostLocWkt) . "'), 4326)";
	}

	$mtTsLit = pgTimestamp($r->modified_at);
	$dateLit = ($mtTsLit !== 'NULL') ? '(' . $mtTsLit . ')::date' : 'NULL';

	$bag = (string)$r->sample_name . ' ' . (string)$r->igsn . ' '
		. (string)$r->description . ' ' . (string)$r->notes . ' '
		. (string)$r->display_sample_type . ' ' . (string)$r->display_sample_purpose . ' '
		. (string)$r->project_name . ' '
		. flattenKeywords(safeJsonDecode($r->custom_data_json));

	$projectPub = ($r->project_ispublic === 't' || $r->project_ispublic === true);

	return '(' . implode(',', array(
		pgText('sample'),
		pgText($r->sample_id),
		pgInt($r->sample_userpkey),
		pgText($r->project_id),
		pgInt($r->project_userpkey),
		pgText($subsystem),
		pgText($r->project_name, 500),
		pgBool($projectPub),
		$locLit,
		$dateLit,
		pgTsvector($bag),
		pgText($r->sample_id),
		pgText($r->sample_name, 500),
		pgText($r->igsn),
		pgText($r->display_sample_type),
		pgText($r->display_sample_purpose),
		pgBool(false),           // has_orientation
		pgBool(true),            // has_samples — the item IS a sample
		pgBool(false),           // has_images
		pgBool(false),           // has_microstructure
		pgBool(false),           // has_strat
		$mtTsLit,
	)) . ')';
}

// ===========================================================================
// Vocab upsert helper (shared by field_images.php, micro.php, and sync)
// ===========================================================================

/**
 * Upsert observed (raw → unified) image-type pairs into
 * strabosearch.vocab_image_type under the given subsystem. $vocabSeen is
 * the raw => unified map the tuple builders accumulate.
 */
function upsertVocabImageTypes($db, $vocabSeen, $subsystem) {
	$n = 0;
	foreach ($vocabSeen as $raw => $unified) {
		$rawEsc     = pg_escape_string((string)$raw);
		$unifiedEsc = pg_escape_string((string)$unified);
		$db->query("INSERT INTO strabosearch.vocab_image_type (subsystem, normalized_from, unified_value)
			VALUES ('" . pg_escape_string($subsystem) . "', '$rawEsc', '$unifiedEsc')
			ON CONFLICT (subsystem, normalized_from) DO UPDATE SET unified_value = EXCLUDED.unified_value");
		$n++;
	}
	return $n;
}
?>
