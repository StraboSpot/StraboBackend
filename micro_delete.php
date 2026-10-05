<?php
/**
 * File: micro_delete.php
 * Description: Delete a synced StraboMicro project from StraboSpot, or
 *              restore one, from My StraboMicro Data (collaboration v3 §12b
 *              17ac, 17ad; microsync/lib/MsDelete.php). Owner only.
 *                GET  ?project_id=<id>  the confirmation: who else has the
 *                                       project, what happens, the 30 days,
 *                                       type the name to confirm
 *                POST action=delete     (pid, name, form token)
 *                POST action=restore    (pid, form token), within 30 days
 *              The result is shown once on My StraboMicro Data. Legacy
 *              (not synced) projects keep the old delete
 *              (delete_micrograph_project.php).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once(__DIR__ . '/microdb/lib/micro_members_web.php');
require_once(__DIR__ . '/microdb/lib/sync_guard.php');

/** Back to My StraboMicro Data with a one-time message. */
function micro_delete_done($ok, $text) {
	$_SESSION['micro_delete_result'] = array('ok' => $ok, 'text' => $text);
	header('Location: /my_micro_data');
	exit();
}

function micro_delete_day($iso) {
	$t = strtotime((string)$iso);
	return $t ? date('F j, Y', $t) : (string)$iso;
}

if (empty($_SESSION['micro_delete_token'])) {
	$_SESSION['micro_delete_token'] = bin2hex(random_bytes(16));
}
$formToken = $_SESSION['micro_delete_token'];
$msdb = micro_members_db($db);
$me = (int)$userpkey;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$pid = isset($_POST['pid']) ? (int)$_POST['pid'] : 0;
	$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
	if (!isset($_POST['token']) || !hash_equals($formToken, (string)$_POST['token']) || $pid <= 0) {
		micro_delete_done(false, 'This form has expired. Please try again.');
	}
	try {
		if ($action === 'restore') {
			$t = MsDelete::restore($msdb, $pid, $me);
			micro_delete_done(true, '"' . $t['name'] . '" was restored. Its members see it again in StraboMicro '
				. '(File > Open Remote Project). A copy you kept on your computer when it was deleted stays separate: '
				. 'open the restored project with File > Open Remote Project to work in it. Turning sync on for the kept copy '
				. 'would make a second project with the same name.');
		}
		if ($action === 'delete') {
			$p = MsStore::project($msdb, $pid, $me);
			MsStore::requireRole($p, array('owner'));
			$name = MsDelete::liveName($msdb, $pid);
			$typed = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
			if ($typed !== trim($name)) {
				micro_delete_done(false, 'The name you typed did not match, so "' . $name . '" was not deleted.');
			}
			$t = MsDelete::softDelete($db, $msdb, $pid, $me);
			micro_delete_done(true, '"' . $name . '" was deleted from StraboSpot. You can restore it below until '
				. micro_delete_day($t['restorable_until']) . '.');
		}
		micro_delete_done(false, 'Unknown action.');
	} catch (MsHttpError $e) {
		micro_delete_done(false, $e->errorCode === 'not_found' || $e->errorCode === 'project_deleted'
			? 'This project is not available.' : $e->getMessage());
	}
}

// GET: the confirmation
$pid = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
try {
	$p = MsStore::project($msdb, $pid, $me);
	MsStore::requireRole($p, array('owner'));
} catch (MsHttpError $e) {
	micro_delete_done(false, 'This project is not available.');
}
$name = MsDelete::liveName($msdb, $pid);
$list = MsMembers::memberList($msdb, $p);
$others = array_values(array_filter($list['members'], function ($m) { return $m['role'] !== 'owner' && $m['state'] === 'active'; }));
$invited = count(array_filter($list['members'], function ($m) { return $m['state'] === 'invited'; }));
$until = micro_delete_day(date('c', time() + MsDelete::KEEP_DAYS * 86400));

include 'includes/mheader.php';
?>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Delete from StraboSpot</h2>
		</header>

		<section id="content">
			<h3><?php echo htmlspecialchars($name)?></h3>
<?php if (count($others) > 0) { ?>
<?php if (count($others) === 1) { ?>
			<p>This project is shared with one other person, listed below. Deleting it removes it from StraboSpot for both of you.
				In StraboMicro, their copy becomes a separate copy on their computer and no longer syncs; any changes they have
				not synced yet stay only in that copy.</p>
<?php } else { ?>
			<p>This project is shared with <?php echo count($others)?> other people, listed below. Deleting it removes it from StraboSpot
				for everyone. In StraboMicro, their copies become separate copies on their computers and no longer sync; any changes
				they have not synced yet stay only in those copies.</p>
<?php } ?>
			<div class="table-wrapper">
				<table class="myDataTable">
					<thead>
						<tr><th>Name</th><th class="hideSmall">Email</th><th>Role</th></tr>
					</thead>
					<tbody>
<?php foreach ($others as $m) { ?>
						<tr>
							<td><?php echo htmlspecialchars($m['user']['name'])?></td>
							<td class="hideSmall"><?php echo htmlspecialchars(isset($m['user']['email']) ? $m['user']['email'] : '')?></td>
							<td><?php echo htmlspecialchars(micro_role_label($m['role']))?></td>
						</tr>
<?php } ?>
					</tbody>
				</table>
			</div>
<?php } else { ?>
			<p>In StraboMicro, copies of this project on your computers become separate copies and no longer sync.</p>
<?php } ?>
<?php if ($invited > 0) { ?>
			<p><?php echo $invited === 1 ? 'The pending invitation waits' : "The $invited pending invitations wait"?> until the project is restored.</p>
<?php } ?>
			<p>StraboSpot keeps the project for <?php echo MsDelete::KEEP_DAYS?> days: until <?php echo htmlspecialchars($until)?> you can restore it
				from the Deleted projects section of My StraboMicro Data. After that it is deleted for good. Published DOIs are not affected.</p>

			<form method="post" action="/micro_delete">
				<input type="hidden" name="action" value="delete">
				<input type="hidden" name="pid" value="<?php echo (int)$pid?>">
				<input type="hidden" name="token" value="<?php echo htmlspecialchars($formToken)?>">
				<label for="micro-delete-name">Type the project name to confirm: <strong><?php echo htmlspecialchars($name)?></strong></label>
				<input type="text" id="micro-delete-name" name="name" autocomplete="off" style="margin-bottom:16px;"
					oninput="document.getElementById('micro-delete-go').disabled = this.value.trim() !== <?php echo htmlspecialchars(json_encode(trim($name)), ENT_QUOTES)?>;">
				<ul class="actions">
					<li><input type="submit" id="micro-delete-go" class="button primary" value="Delete from StraboSpot" disabled></li>
					<li><a href="/my_micro_data" class="button">Cancel</a></li>
				</ul>
			</form>
		</section>

	<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include 'includes/mfooter.php';
