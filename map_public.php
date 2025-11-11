<?php
/**
 * File: map_public.php
 * Description: Public map viewing interface
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");

include("prepare_connections.php");

$hash = $_GET['maphash'];

if($_GET['state']=='public'){
	$db->prepare_query("update geotiffs set ispublic = TRUE where hash=$1 and userpkey=$2", array($hash, $userpkey));
}

if($_GET['state']=='private'){
	$db->prepare_query("update geotiffs set ispublic = FALSE where hash=$1 and userpkey=$2", array($hash, $userpkey));
}

?>