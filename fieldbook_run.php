<?php
/**
 * File: fieldbook_run.php
 * Description: Builds one Field Book in this request for fieldbook_build.php
 *              (?k=build key, docs/Fieldbook_Design.md §14 M6). Writes its
 *              progress to the build's state file as it goes and answers with
 *              the final state as JSON. A build already running for the key
 *              (another tab, a double click) is not started twice: the state
 *              file is flock'd for the duration. Keeps running if the browser
 *              leaves (the finished book is served to the next visit within
 *              two minutes). Releases the session lock first so the page's
 *              polls are not queued behind it. A build queued by the Field Camp
 *              Wizard door (state has camp + run_as) has its access checked
 *              again here and runs as the camp account.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
ini_set('max_execution_time', 1800);
include("logincheck.php");
include("prepare_connections.php");
session_write_close();
ignore_user_abort(true);
set_time_limit(0);
require_once("includes/fieldbook/FieldbookBuild.php");

header('Content-Type: application/json');
header('Cache-Control: no-store');

$key = (string)($_GET['k'] ?? '');
$state = FieldbookBuild::load($key);
if (!$state || (int)$state['userpkey'] !== (int)$userpkey) { http_response_code(404); echo json_encode(array('found' => false)); exit(); }
// A book from the Field Camp Wizard door reads another account's data: check that access again now,
// a queued or older state is not proof of it.
if (!empty($state['camp']) || !empty($state['run_as'])) {
	require_once("includes/fieldcamp/FieldCampRoster.php");
	$door = FieldCampRoster::book($db, $neodb, $userpkey, (int)($state['camp'] ?? 0), (int)($state['run_as'] ?? 0), (string)($state['project'] ?? ''));
	if (!$door) { http_response_code(404); echo json_encode(array('found' => false)); exit(); }
}
if (FieldbookBuild::isRunning($state)) { $v = FieldbookBuild::view($key, $state); $v['attached'] = true; echo json_encode($v); exit(); }
if (FieldbookBuild::isReusable($state, $key)) { echo json_encode(FieldbookBuild::view($key, $state)); exit(); }

$lockPath = FieldbookBuild::statePath($key) . '.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
	$state = FieldbookBuild::load($key) ?: $state;
	$v = FieldbookBuild::view($key, $state); $v['attached'] = true;
	echo json_encode($v); exit();
}
$state = FieldbookBuild::run($key, $state, $strabo);
flock($lock, LOCK_UN); fclose($lock); @unlink($lockPath);
echo json_encode(FieldbookBuild::view($key, $state));
