<?php
/**
 * File: field_camp_package.php
 * Description: Field Camp Wizard: download a camp's project package
 *              (?id=N). Puts the zip together on request (FieldCampPackage:
 *              the project as it is saved now, plus the offline tiles of the
 *              last tile build if there is one) and sends it. Open only to the camp's
 *              owner (an instructor) and the admin.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . '/includes/fieldcamp/FieldCamp.php';
require_once __DIR__ . '/includes/fieldcamp/FieldCampPackage.php';

$camp = FieldCamp::canUse($db, $userpkey) ? FieldCamp::getCamp($db, (int)($_GET['id'] ?? 0)) : null;
if(!$camp || !FieldCamp::canManage($camp, $userpkey) || !FieldCampPackage::installed($db)){
	header("HTTP/1.0 404 Not Found");
	exit("Not found.");
}

set_time_limit(0);   // with offline tiles the package can be a few hundred MB
$tmp = tempnam(sys_get_temp_dir(), 'fcpkg');
$result = FieldCampPackage::buildZip($db, $camp, $tmp);
if($result['error'] !== ''){
	@unlink($tmp);
	$_SESSION['fieldcamp_flash'] = array('ok' => '', 'err' => array($result['error']), 'form' => array());
	header("Location: /field_camp?id=" . (int)$camp->pkey . "&step=package");
	exit();
}

$name = FieldCampPackage::fileName($camp) . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
while(ob_get_level() > 0) ob_end_clean();   // send the file as it is read, not from memory
readfile($tmp);
unlink($tmp);
