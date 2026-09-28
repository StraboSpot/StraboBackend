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

require_once __DIR__ . '/includes/sesar/SesarEndpoint.php';
$userpkey = SesarEndpoint::user();

$report = isset($_POST['report']) && in_array($_POST['report'], array('igsns', 'account'), true) ? $_POST['report'] : null;
if ($report === null) SesarEndpoint::out(400, array('ok' => false, 'error' => 'unknown_report', 'message' => 'Unknown report.'));
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
SesarEndpoint::gate($userpkey);

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
			SesarEndpoint::out(409, array('ok' => false, 'error' => 'auth', 'message' => 'Connect your SESAR account on this page first.'));
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
	SesarEndpoint::fail($e);
}
