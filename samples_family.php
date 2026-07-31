<?php
/**
 * File: samples_family.php
 * Description: Session-authed JSON proxy that forwards to
 *              StraboSamplesService::getFamily(). Powers the inline
 *              mini-explorer mode of the Sample Overview family-tree
 *              widget — clicking a child or parent in the widget
 *              re-fetches that node's 1-hop family payload and the
 *              widget re-renders centered on the new focus.
 *
 *              Body shape: {sample_id, owner_pkey}.
 *
 *              The documented /samplesdb/sample/{id}/family endpoint
 *              is HTTP Basic / JWT only; this is the session-cookie
 *              entry point for the Phase 4 in-page widget.
 *
 *              Same dispatch + auth pattern as samples_changelog.php /
 *              samples_collab.php / samples_invitations.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();

header('Content-Type: application/json');

if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey'])) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'not_authenticated'));
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];

include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.inc.php';
include       $_SERVER['DOCUMENT_ROOT'] . '/db.php';
include       $_SERVER['DOCUMENT_ROOT'] . '/neodb.php';
require_once  $_SERVER['DOCUMENT_ROOT'] . '/samplesdb/services/StraboSamplesService.php';

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'invalid_json'));
    exit;
}

$sampleId  = isset($input['sample_id'])  ? (string)$input['sample_id']  : '';
$ownerPkey = isset($input['owner_pkey']) ? (int)$input['owner_pkey']    : 0;
if ($sampleId === '' || $ownerPkey <= 0) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'missing_required_fields'));
    exit;
}

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);
$family = $svc->getFamily($sampleId, $ownerPkey);

if ($family === null) {
    // canRead failed → sample missing or not visible. Mirrors the
    // documented controller's 404 for the same case.
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'not_found'));
    exit;
}

echo json_encode(array('ok' => true, 'family' => $family));
