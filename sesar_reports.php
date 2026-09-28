<?php
/**
 * File: sesar_reports.php
 * Description: Downloads for the two D9 reports (Phase 8, SesarReports).
 *              POST form fields:
 *                report  = igsns | account
 *                format  = xlsx | csv                 (default xlsx)
 *                refresh = 1 | 0                      (igsns only: read SESAR live)
 *                ids     = JSON array of sample ids   (igsns only; omitted = all)
 *              Answers with the file, or JSON {ok:false, message} on a
 *              problem. Session-authenticated, gated by SesarAccess::canUse.
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

function sesar_reports_out($code, $payload)
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
	sesar_reports_out(401, array('ok' => false, 'error' => 'not_authenticated', 'message' => 'Your session has ended. Please log in again.'));
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();   // live SESAR reads can take seconds

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sesar_reports_out(405, array('ok' => false, 'error' => 'method', 'message' => 'POST only.'));
}
$report = isset($_POST['report']) && in_array($_POST['report'], array('igsns', 'account'), true) ? $_POST['report'] : null;
if ($report === null) sesar_reports_out(400, array('ok' => false, 'error' => 'unknown_report', 'message' => 'Unknown report.'));
$format = (isset($_POST['format']) && $_POST['format'] === 'csv') ? 'csv' : 'xlsx';
$refresh = !empty($_POST['refresh']) && $_POST['refresh'] !== '0';
$ids = null;
if (isset($_POST['ids']) && $_POST['ids'] !== '') {
	$ids = json_decode((string)$_POST['ids'], true);
	$ids = is_array($ids) ? array_values(array_filter($ids, 'is_scalar')) : array();
}

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
include __DIR__ . '/neodb.php';
require_once __DIR__ . '/includes/sesar/SesarReports.php';

if (!SesarAccess::canUse($userpkey)) {
	sesar_reports_out(403, array('ok' => false, 'error' => 'not_allowed', 'message' => 'SESAR features are not available for this account.'));
}
if (!SesarAccess::isConfigured()) {
	sesar_reports_out(503, array('ok' => false, 'error' => 'not_configured', 'message' => 'SESAR is not configured on this server yet.'));
}

$client = new SesarClient();
$conn = new SesarConnection($db, $client);
$views = new SesarSampleView($db, $neodb);
$pull = new SesarPull($db, $client, $conn, $views, $neodb);
$push = new SesarPush($db, $client, $conn, $views, new SesarMint($db, $client, $conn, new SesarVocab($db, $client), $views, null), $pull);
$reports = new SesarReports($db, $client, $conn, $views, $pull, $push);

try {
	if ($report === 'igsns') {
		$r = $reports->igsnReport($userpkey, $ids, $refresh);
		$title = 'My IGSNs';
		$base = 'StraboSpot_IGSNs_';
	} else {
		$sum = $conn->summary($userpkey);
		if (empty($sum['connected'])) {
			sesar_reports_out(409, array('ok' => false, 'error' => 'auth', 'message' => 'Connect your SESAR account on this page first.'));
		}
		$r = $reports->accountReport($userpkey);
		$title = 'My SESAR account';
		$base = 'SESAR_account_';
	}
	$name = $base . (SesarAccess::environment() === 'sandbox' ? 'sandbox_' : '') . date('Ymd') . '.' . $format;
	if ($format === 'csv') {
		$bytes = SesarReports::csv($r['headers'], $r['rows']);
		header('Content-Type: text/csv; charset=UTF-8');
	} else {
		$bytes = SesarReports::xlsx($title, $r['headers'], $r['rows'], $r['notes']);
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	}
	header('Content-Disposition: attachment; filename="' . $name . '"');
	header('Content-Length: ' . strlen($bytes));
	header('X-Report-Rows: ' . count($r['rows']));
	if (!empty($r['notes'])) header('X-Report-Notes: ' . rawurlencode(implode(' ', $r['notes'])));
	echo $bytes;
	exit;
} catch (SesarError $e) {
	$code = ($e->kind === 'validation') ? 400 : (($e->kind === 'network') ? 504 : 502);
	if (in_array($e->kind, array('auth', 'no_permission', 'no_account'), true)) $code = 409;
	if ($e->kind === 'forbidden') $code = 403;
	sesar_reports_out($code, array('ok' => false, 'error' => $e->kind, 'message' => $e->getMessage()));
}
