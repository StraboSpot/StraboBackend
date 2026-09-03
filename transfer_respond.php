<?php
/**
 * File: transfer_respond.php
 * Description: Recipient side of a StraboField project transfer
 *              (docs/ProjectTransfer_Design.md §4 step 3). Reached from the
 *              request email (/transfer_respond?t=<uuid>) or the Review
 *              button on My Field Data. Anonymous visitors go through
 *              logincheck.php, which stores this URI and brings them back
 *              after sign-in. Shows the live project facts and the owner,
 *              then Accept (runs the transfer, mails both parties) or
 *              Decline (mails the owner). A request that is not addressed
 *              to the signed-in account looks exactly like an unknown one.
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

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$token = preg_replace('/[^a-fA-F0-9\-]/', '', (string)($_POST['t'] ?? $_GET['t'] ?? ''));
$svc = new ProjectTransfer($db, $neodb);
$mailer = new ProjectTransferMail($db, $neodb);

$row = $token !== '' ? $svc->getByUuid($token) : null;
if ($row && (int)$row->to_user_pkey !== (int)$userpkey) $row = null;   // existence-hiding
if (!$row) sleep(1);

$outcome = null;     // array(kind, title, lines[])
if ($row && $row->status === 'pending') {
	$svc->expireStale();
	$row = $svc->getByUuid($token);
}

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
	if ($_POST['action'] === 'accept' && $row->status === 'pending') {
		// The rewrite runs here, in this request. No PHP time limit (a big
		// project's search re-extract is slow), and the session lock goes
		// first or the page's transfer_status polls queue behind us.
		set_time_limit(0);
		session_write_close();
		$res = $svc->accept($token, $userpkey);
		$row = $res['row'] ?: $svc->getByUuid($token);
		if ($res['ok']) {
			$mailer->accepted($row);
			$outcome = array('ok', 'The project is now yours',
				array('"' . $row->project_name . '" and everything in it now belong to your account. Both of you have been emailed a summary.',
					'On your devices: open StraboField, sign in as yourself and download the project from the server.'));
		} else {
			if ($row && $row->status === 'failed') $mailer->failed($row);
			$outcome = array('warn', 'The transfer could not be completed',
				array((string)$res['reason'] ?: (string)($res['error'] ?? 'Unknown problem.'),
					($row && $row->applied === 't') ? 'The transfer had started. StraboSpot staff have been notified and will complete or roll it back.' : 'Nothing has changed.'));
		}
	} elseif ($_POST['action'] === 'decline' && $row->status === 'pending') {
		$res = $svc->decline($token, $userpkey);
		$row = $res['row'] ?: $svc->getByUuid($token);
		if ($res['ok']) {
			$mailer->declined($row);
			$outcome = array('ok', 'Transfer declined', array('The owner has been told. Nothing has changed in your account.'));
		} else {
			$outcome = array('warn', 'Could not decline', array((string)$res['reason']));
		}
	}
}

$owner = $row ? $db->get_row_prepared("SELECT firstname, lastname, email FROM users WHERE pkey = $1", array((int)$row->from_user_pkey)) : null;
$counts = null;
$liveName = '';
if ($row && $row->status === 'pending') {
	$nids = $svc->projectNodeIds($row->strabo_project_id, (int)$row->from_user_pkey);
	$counts = $svc->projectCounts($nids);
	$liveName = $svc->projectName($row->strabo_project_id, (int)$row->from_user_pkey);
}
$statusText = array(
	'accepted'  => 'This transfer has already been completed.',
	'declined'  => 'You declined this transfer request.',
	'cancelled' => 'The owner withdrew this transfer request.',
	'expired'   => 'This transfer request has expired.',
	'failed'    => 'This transfer could not be completed. StraboSpot staff have been notified.',
	'refused'   => 'This transfer request is not available.',
);

include("includes/mheader.php");
?>

<style>
.tp-card { margin: 0 0 1.5em 0; padding: 1em 1.25em; border-radius: 6px; background: rgba(255,255,255,0.06); border-left: 4px solid #e44c65; line-height: 1.55; }
.tp-card.ok { border-left-color: #6fbf73; }
.tp-card.warn { border-left-color: #f0ad4e; }
.tp-facts { margin: 0; padding: 0; list-style: none; }
.tp-facts li { padding: 0.15em 0; }
.tp-card .tp-facts + p { margin-top: 0.9em; }
.tp-card p:last-child { margin-bottom: 0; }
.tp-facts strong { display: inline-block; min-width: 9em; color: rgba(255,255,255,0.75); }
.tp-muted { color: rgba(255,255,255,0.65); font-size: 0.92em; }
.tp-actions form { display: inline; }
.tp-progress { border-left-color: #6fbf73; }
.tp-spinner { display: inline-block; width: 1em; height: 1em; margin-right: 0.5em; vertical-align: -0.15em; border: 2px solid rgba(255,255,255,0.25); border-top-color: #fff; border-radius: 50%; animation: tp-spin 0.8s linear infinite; }
@keyframes tp-spin { to { transform: rotate(360deg); } }
.tp-steps { margin: 0.6em 0 0.8em 1.5em; padding: 0; }
.tp-steps li { padding: 0.15em 0; color: rgba(255,255,255,0.45); }
.tp-steps li.active { color: #fff; }
.tp-steps li.done { color: #6fbf73; }
.tp-steps li.done::after { content: " \2713"; }
.tp-steps li.failed { color: #e44c65; }
.tp-steps li.failed::after { content: " \2717"; }
</style>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Project Transfer Request</h2>
						</header>

<?php if (!$row) { ?>
						<div class="tp-card warn" id="tp-notfound">
							<p>This transfer request was not found, or it was not sent to the account you are signed in as (<?php echo $h($_SESSION['username'] ?? ''); ?>).</p>
						</div>
						<ul class="actions"><li><a href="/my_field_data" class="button primary">Go to My StraboField Data</a></li></ul>
<?php } elseif ($outcome !== null) { ?>
						<div class="tp-card <?php echo $h($outcome[0]); ?>" id="tp-outcome">
							<p><strong><?php echo $h($outcome[1]); ?></strong></p>
<?php foreach ($outcome[2] as $line) { ?>
							<p><?php echo $h($line); ?></p>
<?php } ?>
						</div>
						<ul class="actions"><li><a href="/my_field_data" class="button primary">Go to My StraboField Data</a></li></ul>
<?php } elseif ($row->status !== 'pending') { ?>
						<div class="tp-card warn" id="tp-status">
							<p><?php echo $h($statusText[$row->status] ?? 'This transfer request is no longer open.'); ?></p>
							<ul class="tp-facts">
								<li><strong>Project</strong> <?php echo $h($row->project_name); ?></li>
								<li><strong>From</strong> <?php echo $h(trim($owner->firstname . ' ' . $owner->lastname) . ' (' . $owner->email . ')'); ?></li>
							</ul>
						</div>
						<ul class="actions"><li><a href="/my_field_data" class="button primary">Go to My StraboField Data</a></li></ul>
<?php } else { ?>
						<div class="tp-card" id="tp-details">
							<p><strong><?php echo $h(trim($owner->firstname . ' ' . $owner->lastname)); ?></strong> (<?php echo $h($owner->email); ?>) wants to transfer this StraboField project to your account.</p>
							<ul class="tp-facts">
								<li><strong>Project</strong> <?php echo $h($liveName !== '' ? $liveName : $row->project_name); ?></li>
								<li><strong>Datasets</strong> <?php echo (int)$counts['datasets']; ?></li>
								<li><strong>Spots</strong> <?php echo (int)$counts['spots']; ?></li>
								<li><strong>Photos</strong> <?php echo (int)$counts['images']; ?></li>
								<li><strong>Afterwards</strong> <?php echo ($row->keep_as_collaborator === 't') ? $h($owner->firstname) . ' stays on the project as an admin collaborator' : $h($owner->firstname) . ' will no longer have access to the project'; ?></li>
								<li><strong>Expires</strong> <?php echo $h(date('F j, Y, g:i a T', strtotime($row->expires_date))); ?></li>
							</ul>
						</div>

						<div class="tp-card warn">
							<p><strong>Accepting makes you the owner of this project and everything in it</strong>: datasets, spots, photos, tags, samples, saved versions and the existing collaborator relationships. The transfer cannot be undone from your account.</p>
							<p class="tp-muted">If you already collaborate on this project, your collaborator role is replaced by ownership. Afterwards, open StraboField on your devices and download the project from the server.</p>
						</div>

						<div class="tp-actions" id="tp-actions">
							<ul class="actions">
								<li>
									<form method="post" action="/transfer_respond" id="tp-accept-form" onsubmit="return tpAccept()">
										<input type="hidden" name="t" value="<?php echo $h($row->uuid); ?>">
										<input type="hidden" name="action" value="accept">
										<input type="submit" value="Accept Transfer" class="primary" id="tp-accept">
									</form>
								</li>
								<li>
									<form method="post" action="/transfer_respond">
										<input type="hidden" name="t" value="<?php echo $h($row->uuid); ?>">
										<input type="hidden" name="action" value="decline">
										<input type="submit" value="Decline" id="tp-decline">
									</form>
								</li>
								<li><a href="/my_field_data" class="button">Decide later</a></li>
							</ul>
						</div>

						<div class="tp-card tp-progress" id="tp-progress" style="display:none">
							<p><span class="tp-spinner"></span><strong>Transferring the project to your account&hellip;</strong></p>
							<ol class="tp-steps" id="tp-steps">
<?php foreach (ProjectTransfer::STEP_LABELS as $n => $label) { ?>
								<li data-step="<?php echo (int)$n; ?>"><?php echo $h($label); ?></li>
<?php } ?>
							</ol>
							<p class="tp-muted">A large project can take a few minutes. Please keep this page open; this page will change when the transfer is done, and both of you will be emailed a summary.</p>
						</div>

						<script>
						// Accept: confirm, then swap the buttons for the progress card and poll
						// the row's step while the form's own POST does the work (no JS = the
						// plain POST still works, it just shows nothing until the result page).
						var tpConfirmText = <?php echo json_encode('Accept the transfer of ' . ($liveName !== '' ? $liveName : $row->project_name) . '? This cannot be undone.'); ?>;
						var tpStatusUrl = '/transfer_status?t=' + encodeURIComponent(<?php echo json_encode((string)$row->uuid); ?>);
						function tpAccept() {
							if (!confirm(tpConfirmText)) return false;
							document.getElementById('tp-accept').disabled = true;
							document.getElementById('tp-decline').disabled = true;
							document.getElementById('tp-actions').style.display = 'none';
							document.getElementById('tp-progress').style.display = '';
							tpRender(0, 'pending', null);
							tpPoll();
							return true;
						}
						function tpRender(step, status, failedStep) {
							var items = document.querySelectorAll('#tp-steps li');
							for (var i = 0; i < items.length; i++) {
								var n = i + 1, cls = '';
								if (status === 'accepted' || n <= step) cls = 'done';
								else if (status === 'failed' && failedStep === n) cls = 'failed';
								else if (n === step + 1 && status === 'pending') cls = 'active';
								items[i].className = cls;
							}
						}
						function tpPoll() {
							setTimeout(function () {
								var xhr = new XMLHttpRequest();
								xhr.open('GET', tpStatusUrl + '&_=' + Date.now(), true);
								xhr.onload = function () {
									var d = null;
									try { d = JSON.parse(xhr.responseText); } catch (e) {}
									if (d && d.found) {
										tpRender(d.step, d.status, d.failed_step);
										if (d.status !== 'pending') return;
									}
									tpPoll();
								};
								xhr.onerror = function () { tpPoll(); };
								xhr.send();
							}, 1000);
						}
						</script>
<?php } ?>

						<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
