<?php
/**
 * File: fieldbook_status.php
 * Description: JSON progress of one Field Book build (?k=build key), polled
 *              once a second by fieldbook_build.php while fieldbook_run.php
 *              works (docs/Fieldbook_Design.md §14 M6). Reads the state file
 *              only: no database connections, session lock released at once.
 *              A key that is not the signed-in user's answers {found:false}.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
include("logincheck.php");
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();
require_once("includes/fieldbook/FieldbookBuild.php");

header('Content-Type: application/json');
header('Cache-Control: no-store');

$key = (string)($_GET['k'] ?? '');
$state = FieldbookBuild::load($key);
if (!$state || (int)$state['userpkey'] !== $userpkey) { echo json_encode(array('found' => false)); exit(); }
if ($state['state'] === 'running' && !FieldbookBuild::isRunning($state)) {
	// the building request died without recording an outcome (worker killed, server restart)
	$state['state'] = 'failed'; $state['error'] = 'The build stopped unexpectedly. Please try again.';
}
echo json_encode(FieldbookBuild::view($key, $state));
