<?php
/**
 * File: samples_edit.php
 * Description: Session-authed JSON proxy that forwards to
 *              StraboSamplesService::updateSample(). Mirrors
 *              samples_create.php — same auth-from-$_SESSION trick,
 *              same defensive strip of identity/ownership fields, same
 *              error-to-status mapping.
 *
 *              Body shape: {sample_id, owner_pkey, ...writable fields}.
 *              The proxy forwards every other key in the body to the
 *              service, which itself filters to its writable lists
 *              ($WRITABLE_SPINE_FIELDS / $WRITABLE_JSONB_FIELDS) — so
 *              extra keys are silently dropped, not echoed.
 *
 *              Parent management (parent_sample_id / parent_userpkey)
 *              is NOT exposed here — those go through the separate
 *              /sample/{id}/parent sub-resource per §8.3. The Edit
 *              Metadata modal is scoped to spine metadata only.
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

// Identity/ownership/parent fields are session-derived or out-of-scope,
// never client-supplied via this proxy. The service filters its own
// writable lists too, but defense-in-depth: strip anything the modal
// shouldn't be sending.
unset(
    $input['sample_id'], $input['owner_pkey'],
    $input['id'], $input['userpkey'],
    $input['created_by'], $input['modified_by'],
    $input['parent_sample_id'], $input['parent_userpkey'],
    $input['created_at'], $input['modified_at']
);

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);
$result = $svc->updateSample($sampleId, $ownerPkey, $input);

if (!empty($result['ok'])) {
    http_response_code(200);
} else {
    $err = isset($result['error']) ? $result['error'] : 'unknown';
    switch ($err) {
        case 'not_found':             http_response_code(404); break;
        case 'forbidden':             http_response_code(403); break;
        case 'field_link_read_only':  http_response_code(409); break;
        case 'no_writable_fields':    http_response_code(400); break;
        case 'not_authenticated':     http_response_code(401); break;
        default:                      http_response_code(500);
    }
}

echo json_encode($result);
