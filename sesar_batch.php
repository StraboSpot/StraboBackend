<?php
/**
 * File: sesar_batch.php
 * Description: "Export for SESAR batch upload" (Phase 8, B1): the user
 *              uploads the spreadsheet SESAR's Batch Template Creator gave
 *              them; StraboSpot writes the selected samples into its Samples
 *              sheet and sends it back (SesarBatchExport). POST multipart:
 *                action = check | fill
 *                ids    = JSON array of sample ids
 *                file   = the SESAR template (.xlsx)
 *              check -> JSON {ok, plan} (what would be written, what is left
 *              out and why); fill -> the filled .xlsx as a download, or JSON
 *              {ok:false, message} on a problem. Session-authenticated, gated
 *              by SesarAccess::canUse. Needs no SESAR connection.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once __DIR__ . '/includes/session_config.php';
session_start();
header('Cache-Control: no-store');

function sesar_batch_out($code, $payload)
{
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($payload);
	exit;
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT)) {
	$_SESSION['loggedin'] = 'no';
}
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey'])) {
	sesar_batch_out(401, array('ok' => false, 'error' => 'not_authenticated', 'message' => 'Your session has ended. Please log in again.'));
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sesar_batch_out(405, array('ok' => false, 'error' => 'method', 'message' => 'POST only.'));
}
$action = isset($_POST['action']) && in_array($_POST['action'], array('check', 'fill'), true) ? $_POST['action'] : null;
if ($action === null) sesar_batch_out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
$ids = isset($_POST['ids']) ? json_decode((string)$_POST['ids'], true) : null;
$ids = is_array($ids) ? array_values(array_filter($ids, 'is_scalar')) : array();
if (empty($_FILES['file']) || !is_array($_FILES['file']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'])) {
	$tooBig = !empty($_FILES['file']) && in_array((int)$_FILES['file']['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true);
	sesar_batch_out(400, array('ok' => false, 'error' => 'validation',
		'message' => $tooBig ? 'The file is too large to be a SESAR template.' : 'Choose the spreadsheet SESAR\'s Batch Template Creator gave you.'));
}

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
include __DIR__ . '/neodb.php';
require_once __DIR__ . '/includes/sesar/SesarBatchExport.php';

if (!SesarAccess::canUse($userpkey)) {
	sesar_batch_out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => 'SESAR features are not available for this account.'));
}

$client = new SesarClient();
$views = new SesarSampleView($db, $neodb);
$conn = new SesarConnection($db, $client);   // read only, for the SESAR code warning
$vocab = new SesarVocab($db, $client);
$export = new SesarBatchExport($db, $views, new SesarMint($db, $client, $conn, $vocab, $views, null), $vocab, $conn);

try {
	$t = SesarBatchTemplate::load($_FILES['file']['tmp_name']);
	if ($action === 'check') {
		$plan = $export->plan($userpkey, $ids, $t);
		unset($plan['rows']);
		sesar_batch_out(200, array('ok' => true, 'plan' => $plan));
	}
	$res = $export->fill($userpkey, $ids, $t);
	// SESAR requires a unique file name for every batch upload.
	$name = 'SESAR_batch_' . preg_replace('/[^A-Za-z0-9]/', '', $t->sesarCode()) . '_' . date('Ymd-His') . '.xlsx';
	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	header('Content-Length: ' . strlen($res['bytes']));
	header('X-Batch-Included: ' . (int)$res['plan']['included']);
	echo $res['bytes'];
	exit;
} catch (SesarError $e) {
	sesar_batch_out($e->kind === 'validation' ? 400 : 500, array('ok' => false, 'error' => $e->kind, 'message' => $e->getMessage()));
}
