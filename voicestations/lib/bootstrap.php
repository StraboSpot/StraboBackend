<?php
/**
 * File: bootstrap.php
 * Description: Loads the Voice Stations server code for the /db/ Voice*
 *              controllers. MsDb is shared with /microsync/v1/.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once dirname(dirname(__DIR__)) . '/microsync/lib/MsDb.php';
require_once __DIR__ . '/VsConfig.php';
require_once __DIR__ . '/VsHttp.php';
require_once __DIR__ . '/VsDetails.php';
require_once __DIR__ . '/VsService.php';

/** Build the service for this request and check the tester gate. */
function voicestations_service($strabo) {
	$svc = new VsService($strabo->db, $strabo->neodb, $strabo->userpkey);
	$svc->requireAccess();
	return $svc;
}
