<?php
/**
 * File: transfer_status.php
 * Description: JSON progress of one project transfer row, polled once a
 *              second by transfer_respond.php (recipient, ?t=token) and
 *              admin_transfers.php (userpkey 3, ?pkey= for Retry or
 *              ?reverse_of= for Reverse) while the rewrite runs in the
 *              submitted form's request. Same existence-hiding as the
 *              respond page: a token not addressed to the signed-in user
 *              answers {found:false}. Never writes, releases the session
 *              lock at once so polls do not queue behind the working
 *              request. Design docs/ProjectTransfer_Design.md §14 M4.
 */
include("logincheck.php");
include("prepare_connections.php");
session_write_close();
require_once("includes/transfer/ProjectTransfer.php");

header('Content-Type: application/json');
header('Cache-Control: no-store');

$svc = new ProjectTransfer($db, $neodb);
$isAdmin = ((int)$userpkey === 3);
$row = null;
$t = (string)($_GET['t'] ?? '');
$pkey = (int)($_GET['pkey'] ?? 0);
$reverseOf = (int)($_GET['reverse_of'] ?? 0);
if ($t !== '') {
	$row = $svc->getByUuid($t);
	if ($row && (int)$row->to_user_pkey !== (int)$userpkey && (int)$row->from_user_pkey !== (int)$userpkey && !$isAdmin) $row = null;
} elseif ($pkey > 0 && $isAdmin) {
	$row = $svc->getByPkey($pkey);
} elseif ($reverseOf > 0 && $isAdmin) {
	// The reversal row is created by the Reverse POST itself; until it exists there is nothing to report.
	$row = $db->get_row_prepared("SELECT * FROM project_transfers WHERE kind = 'reversal' AND summary->>'reverses_pkey' = $1 ORDER BY pkey DESC LIMIT 1", array((string)$reverseOf));
}
echo json_encode($row ? $svc->progressOf($row) : array('found' => false));
