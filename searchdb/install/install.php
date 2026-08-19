<?php
/**
 * StraboSearch Phase 2 — schema installer / guard.
 *
 * First sub-branch of Phase 2 per docs/StraboSearch/MASTER_PLAN.md.
 * Driven by §5.1 + §5.5.2 + §5.7 Q3/Q4 of DESIGN_PROPOSAL.md
 * (SIGNED OFF v0.7 2026-06-16).
 *
 * ## Permission model — read this first
 *
 * `CREATE SCHEMA strabosearch` requires CREATE on the strabospot DB, which
 * `strabodbuser` does NOT have. The `collaborators_search_acl_idx` on
 * `public.collaborators` requires table ownership, which strabodbuser also
 * does NOT have. Both can only be applied as the postgres superuser.
 *
 * So the canonical first install is the psql pipe:
 *
 *   cat searchdb/install/0?_*.sql searchdb/install/1?_*.sql | \
 *       docker exec -i strabo-postgres psql -U postgres -d strabospot
 *
 * This script's role is therefore not "the installer" — it is the
 * **spike-teardown + guard + table-level re-apply** helper. It:
 *
 *   1. Always drops the Phase 0.3 `strabosearch_spike` schema (the spike was
 *      transferred to strabodbuser ownership at install, so we can drop it).
 *   2. Detects whether the schema + ACL index already exist. If either is
 *      missing, refuses to proceed and emits the pipe command above.
 *   3. If everything is present, re-applies 02–06 (the table-level DDL files
 *      that strabodbuser can execute, since the schema is owned by us once
 *      created). All files are CREATE IF NOT EXISTS — re-runs are no-ops.
 *      This is the path future additions to the schema take.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/searchdb/install/install.php
 *   docker exec strabo-php php /srv/app/www/searchdb/install/install.php --dry-run
 *
 * @package StraboSearch Phase 2 schema
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/../census/_census_lib.php');  // line()/section()/subsection()

$DRY_RUN = in_array('--dry-run', $argv, true);

line('STRABOSEARCH INSTALL / GUARD — ' . date('Y-m-d H:i:s'));
line('  mode:  ' . ($DRY_RUN ? 'DRY RUN (no statements executed)' : 'APPLY'));
line('  files: ' . __DIR__);

function emitPipeCommand() {
	line();
	line('  Run the canonical first install as the postgres superuser:');
	line();
	line('    cat searchdb/install/0?_*.sql searchdb/install/1?_*.sql | \\');
	line('        docker exec -i strabo-postgres psql -U postgres -d strabospot');
	line();
	line('  Then re-run this script (or verify_schema.php) to confirm.');
}

function runSql($db, $label, $sql, $dryRun) {
	if ($dryRun) {
		line('  [dry-run] ' . $label);
		return true;
	}
	$ok = $db->query($sql);
	if ($ok === false) {
		line('  FAIL: ' . $label);
		line('    last_error: ' . $db->last_error);
		return false;
	}
	line('  ok:   ' . $label);
	return true;
}

function applyFile($db, $path, $dryRun) {
	$label = basename($path);
	if (!is_readable($path)) {
		line('  MISSING: ' . $label);
		return false;
	}
	return runSql($db, $label, file_get_contents($path), $dryRun);
}

// ---------------------------------------------------------------------------
section('1. Spike teardown (§5.7 Q4)');

$spikeTeardown = realpath(__DIR__ . '/../spike/spike_teardown.sql');
if (!$spikeTeardown) {
	line('  WARNING: searchdb/spike/spike_teardown.sql not found — skipping.');
} else {
	applyFile($db, $spikeTeardown, $DRY_RUN);
}

// ---------------------------------------------------------------------------
section('2. Prerequisite check');

$schemaPresent = (int)$db->get_var(
	"SELECT count(*) FROM information_schema.schemata WHERE schema_name = 'strabosearch'"
);
line(sprintf('  strabosearch schema:           %s', $schemaPresent ? 'present' : 'MISSING'));

$aclIdx = (int)$db->get_var(
	"SELECT count(*) FROM pg_indexes
	 WHERE schemaname = 'public' AND indexname = 'collaborators_search_acl_idx'"
);
line(sprintf('  collaborators_search_acl_idx:  %s', $aclIdx ? 'present' : 'MISSING'));

if (!$schemaPresent || !$aclIdx) {
	line();
	line('  Prerequisites missing — strabodbuser cannot create them.');
	emitPipeCommand();
	exit(1);
}

// ---------------------------------------------------------------------------
section('3. Re-apply table DDL (idempotent — strabodbuser scope)');

// Skip 01 (schema, superuser-only) and 07 (ACL index, superuser-only).
// Re-apply everything else (02–06, 08+), which strabodbuser owns once 01
// has run. Two-digit files (10+) matched explicitly — `0?_*` misses them.
// NB: 11_query_indexes.sql additionally requires the pg_trgm extension
// (one-time CREATE EXTENSION as postgres — see that file's header); its
// DO-block guard fails the file cleanly if the extension is absent.
$tableFiles = array_filter(
	array_merge(glob(__DIR__ . '/0?_*.sql'), glob(__DIR__ . '/[1-9]?_*.sql')),
	function ($p) {
		$base = basename($p);
		return $base !== '01_schema.sql' && $base !== '07_collaborators_acl_index.sql';
	});
sort($tableFiles);

if (!$tableFiles) {
	line('  ERROR: no 02–06 DDL files matched ' . __DIR__);
	exit(1);
}

$failed = 0;
foreach ($tableFiles as $path) {
	if (!applyFile($db, $path, $DRY_RUN)) {
		$failed++;
	}
}

if ($failed) {
	line();
	line(sprintf('FAILED — %d of %d table DDL files errored.', $failed, count($tableFiles)));
	exit(1);
}

// ---------------------------------------------------------------------------
section('Post-install summary');

if ($DRY_RUN) {
	line('  (dry-run — no state changed; counts not queried)');
} else {
	$tableCount = (int)$db->get_var(
		"SELECT count(*) FROM information_schema.tables WHERE table_schema = 'strabosearch'"
	);
	line(sprintf('  strabosearch tables:               %d (expect 7)', $tableCount));

	$syncRows = (int)$db->get_var("SELECT count(*) FROM strabosearch.sync_state");
	line(sprintf('  sync_state seed rows:              %d (expect 4)', $syncRows));
}

line();
line('Done. Run verify_schema.php for the full integrity check.');
?>
