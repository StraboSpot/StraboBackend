<?php
/**
 * StraboSearch Phase 2 — schema verifier.
 *
 * Read-only integrity check that the install landed everything that §5.1
 * + §5.5.2 of DESIGN_PROPOSAL.md require. Distinct from the later
 * `verify_extended.php` (per §5.6.1 — that compares row counts between
 * source stores and the index; this just checks schema shape).
 *
 * Exits 0 on clean, non-zero on any drift. Safe on prod or dev.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/searchdb/verify_schema.php
 *
 * @package StraboSearch Phase 2 schema
 */

chdir(__DIR__ . '/../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/census/_census_lib.php');  // line()/section()/subsection()

line('STRABOSEARCH SCHEMA VERIFIER — ' . date('Y-m-d H:i:s'));

$failures = array();
$check = function ($label, $ok, $detail = '') use (&$failures) {
	if ($ok) {
		line(sprintf('  PASS  %-58s %s', $label, $detail));
	} else {
		line(sprintf('  FAIL  %-58s %s', $label, $detail));
		$failures[] = $label;
	}
};

// ---------------------------------------------------------------------------
section('1. Schema');

$schemaPresent = (int)$db->get_var(
	"SELECT count(*) FROM information_schema.schemata WHERE schema_name = 'strabosearch'"
);
$check('strabosearch schema exists', $schemaPresent === 1);

$spikePresent = (int)$db->get_var(
	"SELECT count(*) FROM information_schema.schemata WHERE schema_name = 'strabosearch_spike'"
);
$check('strabosearch_spike scratch schema dropped (§5.7 Q4)', $spikePresent === 0,
	$spikePresent ? '— still present; rerun install.php' : '');

// ---------------------------------------------------------------------------
section('2. Tables');

$expectedTables = array(
	'item_hit',
	'image_hit',
	'vocab_image_type',
	'vocab_rock_type',
	'vocab_tag_type',     // install/08 TAGS amendment
	'vocab_facet_counts',
	'saved_search',
	'sync_state',
);

$actualTables = array();
$rows = $db->get_results(
	"SELECT table_name FROM information_schema.tables
	 WHERE table_schema = 'strabosearch' ORDER BY table_name"
);
foreach ((array)$rows as $r) $actualTables[] = $r->table_name;

foreach ($expectedTables as $t) {
	$check('table strabosearch.' . $t, in_array($t, $actualTables, true));
}

// Extractor staging tables ({item,image}_hit_staging_<source>) are part of
// the §5.2.3 swap lifecycle — TRUNCATEd (not dropped) after a swap so
// --resume-from works. Empty ones are normal; a NON-EMPTY one means an
// extract was interrupted mid-run.
$staging = array();
$extra   = array();
foreach (array_diff($actualTables, $expectedTables) as $t) {
	if (preg_match('/^(item|image)_hit_staging_[a-z_]+$/', $t)) {
		$n = (int)$db->get_var("SELECT count(*) FROM strabosearch.\"$t\"");
		if ($n > 0) $staging[] = "$t ($n rows)";
	} else {
		$extra[] = $t;
	}
}
if ($staging) {
	$check('staging tables empty (interrupted extract otherwise)', false,
		'— non-empty: ' . implode(', ', $staging));
}
if ($extra) {
	$check('no unexpected tables in strabosearch', false,
		'— extras: ' . implode(', ', $extra));
}

// ---------------------------------------------------------------------------
section('3. Required columns — item_hit');

// (column_name => expected udt_name from information_schema.columns).
// udt_name is the canonical type name PG returns regardless of whether
// the source DDL said "varchar" or "character varying".
$itemHitCols = array(
	'item_hit_pkey'           => 'int8',
	'item_type'               => 'varchar',
	'item_id'                 => 'varchar',
	'item_userpkey'           => 'int4',
	'project_id'              => 'varchar',
	'project_userpkey'        => 'int4',
	'project_subsystem'       => 'varchar',
	'project_name'            => 'text',
	'project_ispublic'        => 'bool',
	'location'                => 'geometry',
	'date_value'              => 'date',
	'searchtext_tsv'          => 'tsvector',
	'sample_id'               => 'varchar',
	'sample_name'             => 'text',
	'igsn'                    => 'varchar',
	'display_sample_type'     => 'varchar',
	'display_sample_purpose'  => 'varchar',
	'has_orientation'         => 'bool',
	'has_samples'             => 'bool',
	'has_images'              => 'bool',
	'has_microstructure'      => 'bool',
	'has_strat'               => 'bool',
	'orientation_strike'      => '_numeric',
	'orientation_dip'         => '_numeric',
	'orientation_trend'       => '_numeric',
	'orientation_plunge'      => '_numeric',
	'orientation_features'    => '_text',
	'orientation_planar'      => '_bool',
	'rock_types'              => '_text',
	'met_facies'              => '_text',
	'trace_types'             => '_text',
	'minerals'                => '_text',
	'mineral_methods'         => '_text',
	'instrument_type'         => 'varchar',
	'detector_type'           => 'varchar',
	'apparatus_type'          => 'varchar',
	'daq_sensor_type'         => 'varchar',
	'measurement_type'        => 'varchar',
	'source_modified'         => 'timestamptz',
	'last_synced'             => 'timestamptz',
	// install/08 TAGS amendment
	'tag_names'               => '_text',
	'tag_types'               => '_text',
	'tag_text_tsv'            => 'tsvector',
	// install/10 dataset_ids amendment (Phase 3)
	'dataset_ids'             => '_text',
);
verifyColumns($db, $check, 'item_hit', $itemHitCols);

// ---------------------------------------------------------------------------
section('4. Required columns — image_hit');

$imageHitCols = array(
	'image_hit_pkey'          => 'int8',
	'image_id'                => 'varchar',
	'image_subsystem'         => 'varchar',
	'image_userpkey'          => 'int4',
	'image_type'              => 'varchar',
	'annotated'               => 'bool',
	'title'                   => 'text',
	'caption'                 => 'text',
	'imagetext_tsv'           => 'tsvector',
	'filename'                => 'varchar',
	'parent_spot_id'          => 'varchar',
	'parent_sample_id'        => 'varchar',
	'project_id'              => 'varchar',
	'project_userpkey'        => 'int4',
	'project_subsystem'       => 'varchar',
	'project_ispublic'        => 'bool',
	'location'                => 'geometry',
	'date_value'              => 'date',
	'orientation_strike'      => '_numeric',
	'orientation_dip'         => '_numeric',
	'orientation_trend'       => '_numeric',
	'orientation_plunge'      => '_numeric',
	'orientation_features'    => '_text',
	'orientation_planar'      => '_bool',
	'rock_types'              => '_text',
	'met_facies'              => '_text',
	'trace_types'             => '_text',
	'minerals'                => '_text',
	'mineral_methods'         => '_text',
	'instrument_type'         => 'varchar',
	'detector_type'           => 'varchar',
	'source_modified'         => 'timestamptz',
	'last_synced'             => 'timestamptz',
	// install/08 TAGS amendment
	'tag_names'               => '_text',
	'tag_types'               => '_text',
	'tag_text_tsv'            => 'tsvector',
);
verifyColumns($db, $check, 'image_hit', $imageHitCols);

// ---------------------------------------------------------------------------
section('5. Required columns — vocab + sync + saved_search');

verifyColumns($db, $check, 'vocab_image_type', array(
	'unified_value'   => 'varchar',
	'normalized_from' => 'varchar',
	'subsystem'       => 'varchar',
));
verifyColumns($db, $check, 'vocab_rock_type', array(
	'path'        => 'varchar',
	'parent_path' => 'varchar',
	'depth'       => 'int2',
));
verifyColumns($db, $check, 'vocab_tag_type', array(
	'raw_value'     => 'varchar',
	'display_label' => 'varchar',
	'subsystem'     => 'varchar',
));
verifyColumns($db, $check, 'vocab_facet_counts', array(
	'criterion_id' => 'varchar',
	'value'        => 'varchar',
	'count'        => 'int4',
));
verifyColumns($db, $check, 'saved_search', array(
	'saved_search_pkey' => 'int8',
	'user_pkey'         => 'int4',
	'search_name'       => 'text',
	'dsl_json'          => 'jsonb',
	'created_at'        => 'timestamptz',
	'modified_at'       => 'timestamptz',
));
verifyColumns($db, $check, 'sync_state', array(
	'source'                 => 'varchar',
	'last_full_backfill'     => 'timestamptz',
	'last_incremental_sync'  => 'timestamptz',
	'last_sync_rows_added'   => 'int4',
	'last_sync_rows_updated' => 'int4',
	'last_sync_rows_removed' => 'int4',
));

// ---------------------------------------------------------------------------
section('6. UNIQUE constraints (drive §5.3.2 ON CONFLICT upserts)');

$uqItem = (int)$db->get_var(
	"SELECT count(*) FROM information_schema.table_constraints
	 WHERE table_schema = 'strabosearch'
	   AND table_name   = 'item_hit'
	   AND constraint_name = 'item_hit_fanout_uq'
	   AND constraint_type = 'UNIQUE'"
);
$check('item_hit.item_hit_fanout_uq UNIQUE constraint', $uqItem === 1);

$uqImage = (int)$db->get_var(
	"SELECT count(*) FROM information_schema.table_constraints
	 WHERE table_schema = 'strabosearch'
	   AND table_name   = 'image_hit'
	   AND constraint_name = 'image_hit_identity_uq'
	   AND constraint_type = 'UNIQUE'"
);
$check('image_hit.image_hit_identity_uq UNIQUE constraint', $uqImage === 1);

$uqSaved = (int)$db->get_var(
	"SELECT count(*) FROM pg_indexes
	 WHERE schemaname = 'strabosearch'
	   AND tablename  = 'saved_search'
	   AND indexname  = 'saved_search_user_name_uq'"
);
$check('saved_search.saved_search_user_name_uq unique index', $uqSaved === 1);

// ---------------------------------------------------------------------------
section('7. Seeded data — sync_state has one row per source');

$expectedSources = array('field', 'micro', 'exp', 'samples');
$actualSources = array();
$rows = $db->get_results("SELECT source FROM strabosearch.sync_state ORDER BY source");
foreach ((array)$rows as $r) $actualSources[] = $r->source;
sort($expectedSources);

$check('sync_state seed = {field, micro, exp, samples}',
	$actualSources === $expectedSources,
	'— got: [' . implode(', ', $actualSources) . ']');

// ---------------------------------------------------------------------------
section('8. Collaborators ACL index (§5.7 Q3 adjacent install task)');

$aclIdx = (int)$db->get_var(
	"SELECT count(*) FROM pg_indexes
	 WHERE schemaname = 'public'
	   AND tablename  = 'collaborators'
	   AND indexname  = 'collaborators_search_acl_idx'"
);
$check('collaborators_search_acl_idx exists on public.collaborators', $aclIdx === 1);

// ---------------------------------------------------------------------------
section('9. Install-file indexes (Phase 5.A — self-updating sweep)');

// Every CREATE INDEX in searchdb/install/*.sql must exist on the live
// schema. Parsing the install files instead of a hand-kept list means a
// future install/NN can't silently ship an index this verifier ignores.
$declared = array();
foreach (glob(__DIR__ . '/install/*.sql') as $f) {
	// The trailing "\s+ON\s" anchors to real DDL — prose mentions of
	// "CREATE INDEX" in file-header comments don't have "name ON table".
	if (preg_match_all('/CREATE\s+INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z0-9_]+)\s+ON\s/i',
			file_get_contents($f), $m)) {
		foreach ($m[1] as $idx) $declared[$idx] = basename($f);
	}
}
$check('install files declare indexes to sweep', count($declared) > 0,
	'(' . count($declared) . ' declared)');

$rows = $db->get_results(
	"SELECT indexname FROM pg_indexes WHERE schemaname IN ('strabosearch', 'public')");
$live = array();
foreach ((array)$rows as $r) $live[$r->indexname] = true;

$missing = array();
foreach ($declared as $idx => $srcFile) {
	if (!isset($live[$idx])) $missing[] = "$idx ($srcFile)";
}
$check('all declared install indexes exist on the live schema', $missing === array(),
	$missing ? '— missing: ' . implode(', ', $missing) : '');

$trgm = (int)$db->get_var("SELECT count(*) FROM pg_extension WHERE extname = 'pg_trgm'");
$check('pg_trgm extension installed (install/11 prerequisite)', $trgm === 1,
	$trgm ? '' : '— run CREATE EXTENSION pg_trgm as postgres (Phase 6 runbook line)');

// ---------------------------------------------------------------------------
section('Result');

if ($failures) {
	line(sprintf('  %d FAIL(s) — verification did not pass.', count($failures)));
	foreach ($failures as $f) line('    - ' . $f);
	exit(1);
}
line('  ALL CHECKS PASSED.');
exit(0);


// ---------------------------------------------------------------------------
/**
 * Look up every column in information_schema.columns for one table and call
 * the verifier $check on each expected column's presence + type match.
 */
function verifyColumns($db, $check, $table, $expectedCols) {
	$rows = $db->get_results(
		"SELECT column_name, udt_name
		 FROM information_schema.columns
		 WHERE table_schema = 'strabosearch' AND table_name = '" . pg_escape_string($table) . "'"
	);
	$actual = array();
	foreach ((array)$rows as $r) $actual[$r->column_name] = $r->udt_name;

	foreach ($expectedCols as $col => $udt) {
		if (!isset($actual[$col])) {
			$check("$table.$col present", false, '— column missing');
		} else if ($actual[$col] !== $udt) {
			$check("$table.$col type = $udt", false, "— got '{$actual[$col]}'");
		} else {
			$check("$table.$col type = $udt", true);
		}
	}
}
?>
