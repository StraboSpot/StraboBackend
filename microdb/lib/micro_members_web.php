<?php
/**
 * File: micro_members_web.php
 * Description: Loads the StraboMicro sync membership code (microsync/lib)
 *              for website pages: My StraboMicro Data invitations,
 *              micro_invitation.php, micro_collaborators.php and its action
 *              handler. The rules
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

/** This session's secret for the owner's forms on the Collaborators page (needs session_start). */
function micro_collab_token() {
	if (empty($_SESSION['micro_collab_token'])) {
		$_SESSION['micro_collab_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['micro_collab_token'];
}

/** Did this form come from one of our own pages? */
function micro_collab_token_ok($token) {
	return !empty($_SESSION['micro_collab_token']) && is_string($token) && hash_equals($_SESSION['micro_collab_token'], $token);
}

/** Display text of a role. */
function micro_role_label($role) {
	$labels = array('owner' => 'Owner', 'editor' => 'Editor', 'contributor' => 'Contributor', 'viewer' => 'Viewer');
	return isset($labels[$role]) ? $labels[$role] : $role;
}
