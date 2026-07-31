<?php
/**
 * Scan: find straboexp.file_holdings rows whose file is missing on disk
 * ----------------------------------------------------------------------
 * READ-ONLY. Background: the Dec-2025 Vue-rewrite deploy replaced the whole
 * experimental/ directory on prod and silently deleted the
 * experimental/expimages -> /StraboData/bigDriveData/expImages/ symlink
 * (recreated 2026-07-10). While the link was gone, upload_document.php kept
 * inserting file_holdings rows BEFORE move_uploaded_file() failed, so the DB
 * may hold rows for files whose bytes never landed anywhere. The bytes are
 * unrecoverable; this scan identifies the affected rows/users so they can be
 * asked to re-upload.
 *
 * For each missing file the report shows whether the uuid is referenced by a
 * normalized straboexp.document row and/or inside any straboexp.experiment
 * json blob — unreferenced orphans mean the user likely saw the failure and
 * never saved the experiment; referenced ones are real user-visible losses.
 *
 * NOTE: dev's experimental/expimages is empty by design (files live only on
 * prod), so on dev virtually every row reports missing. Run this on PROD.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/tests/experimental/scan_orphaned_document_uploads.php
 *   ... --since=YYYY-MM-DD   only rows uploaded on/after this date (default 2025-12-01)
 *   ... --all                scan every file_holdings row regardless of date
 *
 * Exit codes: 0 = no missing files, 1 = missing files found, 2 = setup error.
 *
 * @package StraboSpot Tests
 */

chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');

$since = '2025-12-01';
$all = false;

foreach ($argv as $a) {
    if (strpos($a, '--since=') === 0) $since = substr($a, 8);
    if ($a === '--all')               $all = true;
}

if (!$all && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
    echo "Bad --since date (want YYYY-MM-DD).\n";
    exit(2);
}

function line($s = '') { echo $s . "\n"; }
function hr() { echo str_repeat('=', 100) . "\n"; }

// Guard against the exact failure mode this scan exists for: a missing or
// dangling expimages symlink would make EVERY row report missing.
$expDir = 'experimental/expimages';
if (!is_dir($expDir)) {
    line("ERROR: $expDir is not a directory (missing or dangling symlink?).");
    line("Restore it per READMEdocs/Build_Sym_Links.txt before scanning:");
    line("  ln -s /StraboData/bigDriveData/expImages/ experimental/expimages");
    exit(2);
}
$onDisk = count(array_diff(scandir($expDir), array('.', '..', '.htaccess')));

if ($all) {
    $rows = $db->get_results_prepared(
        "SELECT pkey, userpkey, uuid, original_filename, upload_date
           FROM straboexp.file_holdings ORDER BY upload_date", array());
} else {
    $rows = $db->get_results_prepared(
        "SELECT pkey, userpkey, uuid, original_filename, upload_date
           FROM straboexp.file_holdings WHERE upload_date >= $1 ORDER BY upload_date",
        array($since));
}
$rows = $rows ? $rows : array();

hr();
line("Orphaned experimental document upload scan (READ-ONLY)");
line("Scope: " . ($all ? "ALL file_holdings rows" : "upload_date >= $since"));
line("file_holdings rows in scope: " . count($rows) . " | files in $expDir: $onDisk");
hr();

$missing = array();

foreach ($rows as $r) {
    $uuid = preg_replace('/[^a-zA-Z0-9\-]/', '', $r->uuid);
    if ($uuid === '' || file_exists("$expDir/$uuid")) continue;

    $email = $db->get_var_prepared(
        "SELECT email FROM users WHERE pkey = $1", array($r->userpkey));
    $docRefs = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.document WHERE uuid = $1", array($uuid));
    $jsonRefs = (int)$db->get_var_prepared(
        "SELECT count(*) FROM straboexp.experiment WHERE json LIKE $1", array("%$uuid%"));

    $r->email = $email ? $email : '(no user row)';
    $r->doc_refs = $docRefs;
    $r->json_refs = $jsonRefs;
    $missing[] = $r;
}

if (count($missing) === 0) {
    line("No missing files. Every file_holdings row in scope has its file on disk.");
    hr();
    exit(0);
}

line(sprintf("%-6s %-8s %-30s %-38s %-26s %-8s %s",
    "pkey", "userpkey", "email", "uuid", "upload_date", "docrefs", "original_filename / jsonrefs"));
line(str_repeat('-', 100));
foreach ($missing as $m) {
    line(sprintf("%-6s %-8s %-30s %-38s %-26s %-8s %s (json:%d)",
        $m->pkey, $m->userpkey, substr($m->email, 0, 30), $m->uuid,
        $m->upload_date, $m->doc_refs, $m->original_filename, $m->json_refs));
}

hr();
$referenced = 0;
$users = array();
foreach ($missing as $m) {
    if ($m->doc_refs > 0 || $m->json_refs > 0) $referenced++;
    $users[$m->userpkey] = $m->email;
}
line("MISSING FILES: " . count($missing) . " of " . count($rows) . " rows in scope");
line("  referenced by an experiment/document row (user-visible loss): $referenced");
line("  unreferenced (upload failed, experiment likely never saved):  " . (count($missing) - $referenced));
line("  affected users: " . count($users));
foreach ($users as $upk => $email) line("    $upk  $email");
line("These files' bytes are unrecoverable — affected users need to re-upload.");
hr();
exit(1);
