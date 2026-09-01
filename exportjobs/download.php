<?php
/**
 * File: exportjobs/download.php
 * Description: Streams one finished export zip to its owner (design §9.3).
 *              ?j=<uuid>. The row must belong to the SESSION user and be
 *              `done`; a uuid alone never serves anything. A done row whose
 *              file is gone (external cleanup) is flipped to `expired` and
 *              the user lands on My Exports with the re-run hint instead of
 *              a 404. Downloads are appended to exportjobs/log/downloads.log.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir(dirname(__DIR__));
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
include('logincheck.php');                       // anonymous -> /login.php (returns here afterwards)
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();

include_once 'includes/config.inc.php';
include      'db.php';
include_once 'includes/UUID.php';
require_once __DIR__ . '/lib/export_config.php';
require_once __DIR__ . '/lib/ExportJobService.php';

$cfg = export_config();
$svc = new ExportJobService($db, $cfg);

$uuid = isset($_GET['j']) ? (string)$_GET['j'] : '';
$row = $uuid !== '' ? $svc->get($uuid, $userpkey) : null;
if (!$row) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Export not found.\n";
	exit();
}
if ($row['status'] !== 'done') {
	header('Location: /my_exports?notice=' . ($row['status'] === 'expired' ? 'expired' : 'notready') . '&j=' . urlencode($row['uuid']), true, 303);
	exit();
}
$abs = $svc->resultPath($row);
if (!$abs || !is_file($abs)) {
	$svc->expire($row, 'file missing at download');
	header('Location: /my_exports?notice=expired&j=' . urlencode($row['uuid']), true, 303);
	exit();
}

$stamp = $row['finished_at'] ? date('Ymd', strtotime($row['finished_at'])) : date('Ymd');
$name = 'strabospot_export_' . $stamp . '_' . substr($row['uuid'], 0, 8) . '.zip';
@file_put_contents(rtrim($cfg['log_root'], '/') . '/downloads.log',
	'[' . date('Y-m-d H:i:s') . "] user $userpkey job {$row['uuid']} " . filesize($abs) . " bytes\n", FILE_APPEND);

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($abs);
exit();
