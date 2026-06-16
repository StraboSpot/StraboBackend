<?php
/**
 * StraboSearch Phase 2 — shared helpers for the per-subsystem extractors.
 *
 * Mirrors the searchdb/census/_census_lib.php pattern (logging + small
 * utilities) and adds the bits every extractor needs: JSON safe-decode,
 * recursive keyword flatten (the §5.4.1 U1 tsvector source), rock-type
 * hierarchy assembly (the canonical pattern from
 * db/strabospotclass.php:5965), the staging-table swap idiom (§5.2.3),
 * and a sync_state writer.
 *
 * Each extractor `requires` this file and the census lib.
 *
 * @package StraboSearch Phase 2 extractors
 */

require_once(__DIR__ . '/../census/_census_lib.php');  // line()/section()/Tally — keep one logger

// ---------------------------------------------------------------------------
// JSON helpers
// ---------------------------------------------------------------------------

/**
 * Tolerant JSON decode. Returns null on failure (the caller decides whether
 * to count it as a parse failure or just a missing field).
 */
function safeJsonDecode($raw) {
	if ($raw === null || $raw === '' || $raw === false) return null;
	if (!is_string($raw)) return $raw;
	$decoded = json_decode($raw);
	return $decoded;
}

/**
 * Recursive keyword flatten — ported from StraboSpot::getKeywords()
 * (db/strabospotclass.php:6037). Walks any object/array tree and emits a
 * single space-separated string of non-numeric/non-bool leaf values. Skips
 * the same keys the legacy logic skips (id/date/strat_section_id/
 * modified_timestamp/self/time/image_type). The Phase 0.1 autopsy noted
 * this is the function that already buries the full Spot JSON into the
 * tsvector today — we reuse the same shape so the Phase 4 keyword search
 * has the same hit recall as `/fullsearch`.
 */
function flattenKeywords($var) {
	static $skip = array('id' => 1, 'date' => 1, 'strat_section_id' => 1,
		'modified_timestamp' => 1, 'self' => 1, 'time' => 1, 'image_type' => 1);
	$out = '';
	if (is_object($var)) {
		foreach (get_object_vars($var) as $key => $value) {
			if (!isset($skip[$key])) $out .= ' ' . flattenKeywords($value);
		}
	} elseif (is_array($var)) {
		// Associative test (PHP's array_keys != range trick).
		$assoc = !empty($var) && array_keys($var) !== range(0, count($var) - 1);
		if ($assoc) {
			foreach ($var as $key => $value) {
				if (!isset($skip[$key])) $out .= ' ' . flattenKeywords($value);
			}
		} else {
			foreach ($var as $v) $out .= ' ' . flattenKeywords($v);
		}
	} elseif (!is_numeric($var) && !is_bool($var)) {
		$out .= ' ' . $var;
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Rock-type hierarchy assembly
// ---------------------------------------------------------------------------

/**
 * Build the colon-delimited rock-type path from a Tag object.
 *
 * Ported verbatim from db/strabospotclass.php:5965 — the canonical
 * assembler that has populated public.rock_type since the mirror's
 * inception. Returns array(rockTypePath, metamorphicFacies) — either may
 * be empty string if the tag doesn't carry that data.
 *
 * Tag object accessors: works with both stdClass (from json_decode) and
 * Neo4j response objects (`get()` method). The Neo4j-side wrapper passes
 * an assoc array of properties — we accept any of the three shapes.
 */
function buildRockTypePath($tag) {
	$g = function ($k) use ($tag) {
		// Neo4j MapAccess->get($k) THROWS on missing key (real-world tags
		// commonly lack one or more sub-classification fields). Use the
		// hasValue/containsKey/keys check, or fall back to try/catch.
		if (is_object($tag) && method_exists($tag, 'get')) {
			try { return $tag->get($k); }
			catch (\Exception $e) { return null; }
		}
		if (is_object($tag)) return isset($tag->$k) ? $tag->$k : null;
		if (is_array($tag))  return isset($tag[$k]) ? $tag[$k] : null;
		return null;
	};
	$path = '';
	$facies = '';
	$rt = $g('rock_type');
	if ($rt === null || $rt === '') return array('', '');

	if ($rt === 'igneous') {
		$path = 'igneous';
		$cls = $g('igneous_rock_class');
		if ($cls) $path .= ':' . $cls;
		$plu = $g('plutonic_rock_types');
		if ($plu) $path .= ':' . $plu;
	} elseif ($rt === 'metamorphic') {
		$path = 'metamorphic';
		$mrt = $g('metamorphic_rock_types');
		if ($mrt) $path .= ':' . $mrt;
		$grade = $g('metamorphic_grade');
		if ($grade) $facies = $grade;
	} elseif ($rt === 'sedimentary' || $rt === 'Sedimentary') {  // 1 stray-cased value on prod
		$path = 'sedimentary';
		$srt = $g('sedimentary_rock_type');
		if ($srt) $path .= ':' . $srt;
	} elseif ($rt === 'sediment') {
		$path = 'sediment';
		$st = $g('sediment_type');
		if ($st) $path .= ':' . $st;
	} else {
		// Unknown top-level — keep verbatim so downstream knows what showed up.
		$path = (string)$rt;
	}
	return array($path, $facies);
}

// ---------------------------------------------------------------------------
// Geometry helpers
// ---------------------------------------------------------------------------

/**
 * Build a PG geometry literal from a longitude/latitude pair, SRID 4326.
 * Returns the literal as a string suitable for direct VALUES inclusion
 * (caller must NOT pg_escape — this is a typed function call).
 *
 * Phase 2 declares srid=4326 properly (§5.1.3 + the shapegeology repair
 * note). Returns 'NULL' (PG literal) for missing/invalid coords.
 */
function pgPointLiteral($lng, $lat) {
	if (!is_numeric($lng) || !is_numeric($lat)) return 'NULL';
	$lng = (float)$lng;
	$lat = (float)$lat;
	if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) return 'NULL';
	return sprintf("ST_SetSRID(ST_MakePoint(%.8f, %.8f), 4326)", $lng, $lat);
}

/**
 * Build a PG geometry literal from a WKT string, taking the centroid so
 * POLYGON / LINESTRING geometries collapse to a single Point (item_hit.location
 * is geometry(Point, 4326)). Field spot.wkt carries POINT, LINESTRING, and
 * POLYGON depending on what the user drew. Returns 'NULL' if WKT is missing
 * or unparseable (the surrounding NULLIF/CASE handles bad input).
 */
function pgCentroidFromWkt($wkt) {
	if (!is_string($wkt) || trim($wkt) === '') return 'NULL';
	return "ST_Centroid(ST_SetSRID(ST_GeomFromText('" . pg_escape_string($wkt) . "'), 4326))";
}

/**
 * Extract (lng, lat) from a GeoJSON-style coordinates pair. Returns
 * array(lng, lat) or null on failure. Robust to both array and object
 * shapes — the Field spot JSON has been seen with both over the years.
 */
function pickLngLat($coords) {
	if (is_array($coords) && count($coords) >= 2
		&& is_numeric($coords[0]) && is_numeric($coords[1])) {
		return array((float)$coords[0], (float)$coords[1]);
	}
	if (is_object($coords) && isset($coords->{0}) && isset($coords->{1})
		&& is_numeric($coords->{0}) && is_numeric($coords->{1})) {
		return array((float)$coords->{0}, (float)$coords->{1});
	}
	return null;
}

// ---------------------------------------------------------------------------
// PG literal helpers — for bulk INSERT VALUES (...) building
// ---------------------------------------------------------------------------

function pgInt($v) {
	if ($v === null || $v === '' || $v === false) return 'NULL';
	return (string)(int)$v;
}

function pgBool($v) {
	if ($v === null) return 'NULL';
	return $v ? 'TRUE' : 'FALSE';
}

function pgText($v, $maxLen = null) {
	if ($v === null || $v === false) return 'NULL';
	$s = (string)$v;
	if ($maxLen !== null && mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen);
	return "'" . pg_escape_string($s) . "'";
}

function pgNumeric($v) {
	if ($v === null || $v === '' || $v === false) return 'NULL';
	if (!is_numeric($v)) return 'NULL';
	return (string)(float)$v;
}

function pgTimestamp($v) {
	if ($v === null || $v === '' || $v === false) return 'NULL';
	if (is_numeric($v)) {
		// Treat as ms-epoch (Field's convention) — fall back to s-epoch if
		// the value looks too small to be ms (anything before year 2001
		// in ms = before 1970 in s).
		$n = (int)$v;
		if ($n > 1000000000000) return "to_timestamp(" . ($n / 1000.0) . ")";
		if ($n > 1000000000)    return "to_timestamp($n)";
	}
	$s = (string)$v;
	return "'" . pg_escape_string($s) . "'::timestamptz";
}

/**
 * Build a PG array literal from a plain PHP array of strings.
 * Returns 'NULL' for empty input. Casts to ARRAY[...]::TYPE[] so the
 * server picks up the element type.
 */
function pgTextArray($arr) {
	if (!is_array($arr) || !$arr) return 'NULL';
	$out = array();
	foreach ($arr as $v) {
		if ($v === null) { $out[] = 'NULL'; continue; }
		$out[] = "'" . pg_escape_string((string)$v) . "'";
	}
	return 'ARRAY[' . implode(',', $out) . ']::TEXT[]';
}

function pgNumericArray($arr) {
	if (!is_array($arr) || !$arr) return 'NULL';
	$out = array();
	foreach ($arr as $v) {
		if (!is_numeric($v)) { $out[] = 'NULL'; continue; }
		$out[] = (string)(float)$v;
	}
	return 'ARRAY[' . implode(',', $out) . ']::NUMERIC[]';
}

function pgBoolArray($arr) {
	if (!is_array($arr) || !$arr) return 'NULL';
	$out = array();
	foreach ($arr as $v) {
		if ($v === null) { $out[] = 'NULL'; continue; }
		$out[] = $v ? 'TRUE' : 'FALSE';
	}
	return 'ARRAY[' . implode(',', $out) . ']::BOOLEAN[]';
}

function pgTsvector($text) {
	if ($text === null || $text === '' || trim($text) === '') return 'NULL';
	return "to_tsvector('" . pg_escape_string(trim($text)) . "')";
}

// ---------------------------------------------------------------------------
// Staging-table swap (§5.2.3)
// ---------------------------------------------------------------------------

/**
 * Atomically replace the per-source slice of the live table with the
 * contents of a staging table. Both tables must have identical columns.
 *
 * Pattern (PG 11; no MERGE — explicit delete+insert in a transaction):
 *   BEGIN
 *     DELETE FROM live WHERE <sliceWhere>
 *     INSERT INTO live SELECT * FROM staging
 *     TRUNCATE staging
 *   COMMIT
 *
 * Returns the number of rows inserted (or null on error; caller checks
 * $db->last_error).
 */
function swapStagingInto($db, $liveTable, $stagingTable, $sliceWhere, $columns, $conflictColumns = null) {
	$cols = is_array($columns) ? implode(', ', $columns) : (string)$columns;
	$db->query('BEGIN');
	$ok = $db->query("DELETE FROM $liveTable WHERE $sliceWhere");
	if ($ok === false) { $db->query('ROLLBACK'); return null; }
	$insertSql = "INSERT INTO $liveTable ($cols) SELECT $cols FROM $stagingTable";
	if ($conflictColumns) {
		// Soak duplicates in the staging table (Field carries known dup
		// spots — same id linked to multiple Datasets within the project).
		$conflict = is_array($conflictColumns) ? implode(', ', $conflictColumns) : (string)$conflictColumns;
		$insertSql .= " ON CONFLICT ($conflict) DO NOTHING";
	}
	$ok = $db->query($insertSql);
	if ($ok === false) { $db->query('ROLLBACK'); return null; }
	$inserted = (int)$db->get_var("SELECT count(*) FROM $liveTable WHERE $sliceWhere");
	$ok = $db->query("TRUNCATE $stagingTable");
	if ($ok === false) { $db->query('ROLLBACK'); return null; }
	$db->query('COMMIT');
	return $inserted;
}

/**
 * Update strabosearch.sync_state for one source. Called at end of a
 * successful extractor run.
 */
function updateSyncState($db, $source, $rowsAdded) {
	$source = pg_escape_string($source);
	$rowsAdded = (int)$rowsAdded;
	$db->query("
		INSERT INTO strabosearch.sync_state
			(source, last_full_backfill, last_sync_rows_added)
		VALUES ('$source', NOW(), $rowsAdded)
		ON CONFLICT (source) DO UPDATE SET
			last_full_backfill   = NOW(),
			last_sync_rows_added = $rowsAdded
	");
}

// ---------------------------------------------------------------------------
// Bulk INSERT helper
// ---------------------------------------------------------------------------

/**
 * Buffered bulk-INSERT writer. Caller appends single VALUES tuples; flush()
 * emits them as one INSERT statement per CHUNK rows.
 */
class BulkInsertBuffer {
	private $db;
	private $sqlPrefix;          // "INSERT INTO foo (col1, col2) VALUES "
	private $chunkSize;
	private $buffer = array();
	private $flushed = 0;
	public function __construct($db, $sqlPrefix, $chunkSize = 1000) {
		$this->db = $db;
		$this->sqlPrefix = $sqlPrefix;
		$this->chunkSize = $chunkSize;
	}
	/**
	 * Returns the flush result if a flush triggered (negative on poison
	 * batch drop, positive count on success), or null when no flush yet.
	 */
	public function add($valuesTuple) {
		$this->buffer[] = $valuesTuple;
		if (count($this->buffer) >= $this->chunkSize) return $this->flush();
		return null;
	}
	public function flush() {
		if (!$this->buffer) return 0;
		$sql = $this->sqlPrefix . implode(",\n", $this->buffer);
		$ok = $this->db->query($sql);
		$lost = 0;
		if ($ok === false) {
			// Drop the poison batch — leaving it in the buffer would retry
			// the same failure forever (OOM by unbounded growth seen on real
			// data with one timestamp-overflow row). Caller can inspect
			// dropped().
			$lost = count($this->buffer);
			$this->dropped += $lost;
			$this->lastError = $this->db->last_error;
			$this->buffer = array();
			return -$lost;
		}
		$this->flushed += count($this->buffer);
		$this->buffer = array();
		return $this->flushed;
	}
	public $dropped = 0;
	public $lastError = '';
	public function total() { return $this->flushed + count($this->buffer); }
}
?>
