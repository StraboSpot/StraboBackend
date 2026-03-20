<?php
/**
 * File: delete_institute.php
 * Description: Deletes records from institute table(s) and related child records
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");

$userpkey = (int)$_SESSION['userpkey'];

include("prepare_connections.php");

$credentials = $_SESSION['credentials'];

if(!in_array($userpkey, $admin_pkeys)){
	header("Location: instrumentcatalog");
	exit();
}

$pkey = isset($_GET['p']) ? (int)$_GET['p'] : 0;

if($pkey == 0){
	header("Location: instrumentcatalog");
	exit();
}

// Delete child records: detectors for all instruments in this institute
$db->prepare_query("
	DELETE FROM instrument_detector
	WHERE instrument_pkey IN (SELECT pkey FROM instrument WHERE institution_pkey = $1)
", array($pkey));

// Delete instruments belonging to this institute
$db->prepare_query("DELETE FROM instrument WHERE institution_pkey = $1", array($pkey));

// Delete instrument_users associations for this institute
$db->prepare_query("DELETE FROM instrument_users WHERE institution_pkey = $1", array($pkey));

// Delete the institute itself
$db->prepare_query("DELETE FROM institute WHERE pkey = $1", array($pkey));

header("Location: instrumentcatalog");
?>
