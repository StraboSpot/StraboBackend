<?php
/**
 * File: samples_parent.php
 * Description: Session-authed JSON proxy for parent management on the
 *              Sample Overview page. The Edit Metadata modal's parent
 *              picker drives it. Three actions:
 *
 *                list  — candidate parents for the picker: every sample
 *                        the caller can read (same visibility predicate
 *                        as My Samples), minus the target sample itself.
 *                        Light rows: {id, userpkey, name,
 *                        display_sample_type}.
 *                set   — forwards to StraboSamplesService::setParent()
 *                        (canEdit on child + canRead on parent + cycle
 *                        rejection per §7.5 / samples/api-parent).
 *                clear — forwards to clearParent(). No-op ok when the
 *                        sample had no parent.
 *
 *              Body shape: {action, sample_id, owner_pkey,
 *              parent_sample_id?, parent_userpkey?}. The documented
 *              /samplesdb/sample/{id}/parent sub-resource is HTTP
 *              Basic / JWT only; this is the session-cookie entry
 *              point, mirroring samples_family.php.
 *
 *              Same dispatch + auth pattern as samples_changelog.php /
 *              samples_collab.php / samples_family.php.
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

$action    = isset($input['action'])     ? (string)$input['action']     : '';
$sampleId  = isset($input['sample_id'])  ? (string)$input['sample_id']  : '';
$ownerPkey = isset($input['owner_pkey']) ? (int)$input['owner_pkey']    : 0;
if ($sampleId === '' || $ownerPkey <= 0) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'missing_required_fields'));
    exit;
}

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);

switch ($action) {
    case 'list':
        // Candidate parents = the caller's readable samples (own +
        // accepted collab grants — listMySamples' predicate matches the
        // canRead gate setParent applies, so every hit here passes
        // server-side validation), minus the sample being edited. Deeper
        // descendants stay in the list; setParent's cycle walk rejects
        // those with 'cycle_detected' and the modal explains why.
        $rows = $svc->listMySamples();
        $candidates = array();
        foreach ($rows as $r) {
            if ($r['id'] === $sampleId && (int)$r['userpkey'] === $ownerPkey) {
                continue;
            }
            $candidates[] = array(
                'id'                  => $r['id'],
                'userpkey'            => (int)$r['userpkey'],
                'name'                => $r['name'],
                'display_sample_type' => $r['display_sample_type'],
            );
        }
        http_response_code(200);
        echo json_encode(array('ok' => true, 'candidates' => $candidates));
        exit;

    case 'set':
        $parentSampleId = isset($input['parent_sample_id']) && $input['parent_sample_id'] !== ''
                        ? (string)$input['parent_sample_id'] : null;
        $parentUserpkey = isset($input['parent_userpkey'])  && $input['parent_userpkey']  !== ''
                        ? (int)$input['parent_userpkey']    : 0;
        $result = $svc->setParent($sampleId, $ownerPkey, $parentSampleId, $parentUserpkey);
        break;

    case 'clear':
        $result = $svc->clearParent($sampleId, $ownerPkey);
        break;

    default:
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'invalid_action'));
        exit;
}

if (!empty($result['ok'])) {
    http_response_code(200);
} else {
    $err = isset($result['error']) ? $result['error'] : 'unknown';
    switch ($err) {
        case 'not_found':             http_response_code(404); break;
        case 'forbidden':             http_response_code(403); break;
        case 'parent_not_accessible': http_response_code(403); break;
        case 'parent_pair_required':  http_response_code(400); break;
        case 'cycle_detected':        http_response_code(409); break;
        default:                      http_response_code(500);
    }
}

echo json_encode($result);
