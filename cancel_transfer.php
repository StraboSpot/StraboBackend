<?php
/**
 * File: cancel_transfer.php
 * Description: Owner withdraws a pending StraboField project transfer
 *              request (docs/ProjectTransfer_Design.md §4). Linked from My
 *              Field Data and transfer_project.php with a confirm() in the
 *              browser, in the style of the other one-shot action links
 *              (delete_collaborator, deny_collaboration). Mails the
 *              recipient, leaves a one-shot notice for My Field Data.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once("includes/transfer/ProjectTransfer.php");
require_once("includes/transfer/ProjectTransferMail.php");

$token = preg_replace('/[^a-fA-F0-9\-]/', '', (string)($_GET['t'] ?? ''));
$svc = new ProjectTransfer($db, $neodb);
$row = $token !== '' ? $svc->getByUuid($token) : null;

if (!$row || (int)$row->from_user_pkey !== (int)$userpkey) {
	sleep(1);
	$_SESSION['transfer_notice'] = array('kind' => 'warn', 'text' => 'That transfer request was not found.');
	header("Location: /my_field_data");
	exit();
}

$res = $svc->cancel($token, $userpkey);
if ($res['ok']) {
	$mailer = new ProjectTransferMail($db, $neodb);
	$mailer->cancelled($res['row']);
	$_SESSION['transfer_notice'] = array('kind' => 'ok', 'text' => 'The transfer request for "' . $row->project_name . '" has been withdrawn. The project is unchanged.');
} else {
	$_SESSION['transfer_notice'] = array('kind' => 'warn', 'text' => (string)$res['reason']);
}

if (($_GET['back'] ?? '') === 'project') {
	header("Location: /transfer_project?p=" . urlencode($row->strabo_project_id));
} else {
	header("Location: /my_field_data");
}
exit();
