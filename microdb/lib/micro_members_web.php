<?php
/**
 * File: micro_members_web.php
 * Description: Loads the StraboMicro sync membership code (microsync/lib)
 *              for website pages: My StraboMicro Data invitations,
 *              micro_invitation.php and micro_collaborators.php. The rules
 *              live in MsMembers so the app API and the website share them.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/../../microsync/lib/MsHttp.php';
require_once __DIR__ . '/../../microsync/lib/MsDb.php';
require_once __DIR__ . '/../../microsync/lib/MsStore.php';
require_once __DIR__ . '/../../microsync/lib/MsMembers.php';

/** MsDb over the page's $db wrapper. */
function micro_members_db($db) {
	return new MsDb($db);
}

/** Display text of a role. */
function micro_role_label($role) {
	$labels = array('owner' => 'Owner', 'editor' => 'Editor', 'contributor' => 'Contributor', 'viewer' => 'Viewer');
	return isset($labels[$role]) ? $labels[$role] : $role;
}
