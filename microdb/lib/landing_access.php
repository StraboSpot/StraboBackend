<?php
/**
 * File: landing_access.php
 * Description: Read gate for the files behind the legacy StraboMicro landing
 *              pages (getMicroImage, microLandingScaleBar). Same rule as the
 *              pages that show them (microproject, microproject2,
 *              micrographBigWindow, micrographDetailsPane): the project is
 *              public, or the session user owns it. Callers answer 404 for
 *              anything else so a private project's existence is not revealed.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// micro_projectmetadata row for $pkey when $userpkey may read it, else null.
function micro_landing_readable_row($db, $pkey, $userpkey) {
	if (!is_numeric($pkey) || (int)$pkey <= 0 || (string)(int)$pkey !== (string)$pkey) return null;
	$row = $db->get_row_prepared("SELECT id, userpkey, ispublic FROM micro_projectmetadata WHERE id = $1 AND (ispublic OR userpkey = $2)", array((int)$pkey, (int)$userpkey));
	return ($row && $row->id != "") ? $row : null;
}

// A micrograph id as used in straboMicroFiles/<pkey>/images/<id>.jpg: digits (older
// projects) or a UUID. Anything else (path pieces, dots, slashes) is rejected.
function micro_landing_valid_image_id($id) {
	return is_string($id) && preg_match('/^([0-9]{1,20}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/', $id) === 1;
}

// Session user for these image endpoints, read the way the landing pages read it
// (prepare_connections: $_SESSION['userpkey']), 0 when there is none. The session is
// released right away so a page with many images is not serialized on it.
function micro_landing_session_userpkey() {
	if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
	$userpkey = isset($_SESSION['userpkey']) ? (int)$_SESSION['userpkey'] : 0;
	session_write_close();
	return $userpkey;
}

function micro_landing_not_found() {
	http_response_code(404);
	exit();
}
?>
