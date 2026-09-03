<?php
/**
 * File: transfer_project.php
 * Description: Owner side of a StraboField project transfer
 *              (docs/ProjectTransfer_Design.md §4 step 1). The owner enters
 *              the recipient's full email address and chooses whether to
 *              stay on as an admin collaborator. Whatever the outcome, the
 *              page shows the same neutral message (D5): account existence
 *              is never confirmed here. An eligible request creates a
 *              pending row and mails the recipient; anything else writes a
 *              refused audit row. While a request is pending the page shows
 *              it with a Cancel button instead of the form.
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

$project_id = preg_replace('/[^0-9]/', '', (string)($_POST['p'] ?? $_GET['p'] ?? ''));
if ($project_id === '') exit("No project id provided.");

$svc = new ProjectTransfer($db, $neodb);
$nids = $svc->projectNodeIds($project_id, $userpkey);
if (!$nids) {
	sleep(1);
	exit("Project not found.");
}

$project_name = $svc->projectName($project_id, $userpkey);
$counts = $svc->projectCounts($nids);
$collabCount = (int)$db->get_var_prepared("SELECT count(*) FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND accepted = TRUE AND disabled = FALSE", array($project_id, (int)$userpkey));
$doiCount = (int)$db->get_var_prepared("SELECT count(*) FROM dois WHERE strabo_project_id = $1 AND user_pkey = $2", array($project_id, (int)$userpkey));
$svc->expireStale();
$pending = $svc->pendingRow($project_id, $userpkey);

$result = null;
$formError = null;
$email = '';
$keep = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_transfer']) && !$pending) {
	$email = trim((string)($_POST['email'] ?? ''));
	$keep = !empty($_POST['keep']);
	if (empty($_POST['confirm'])) {
		$formError = 'Please confirm that you understand the transfer cannot be undone.';
	} else {
		$r = $svc->request($project_id, $userpkey, $email, $keep, $userpkey);
		if ($r['ok']) {
			if ($r['mail'] && $r['row']) {
				$mailer = new ProjectTransferMail($db, $neodb);
				$mailer->request($r['row']);
			}
			$result = $r['message'];
			$pending = $svc->pendingRow($project_id, $userpkey);
		} else {
			$formError = $r['message'];
		}
	}
}

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
.tp-form label { display: inline; }
.tp-form input[type="email"] { max-width: 28em; }
.tp-check { margin: 0.75em 0; }
.tp-actions { margin-top: 1.5em; }
.tp-muted { color: rgba(255,255,255,0.65); font-size: 0.92em; }
</style>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Transfer Project to Another Account</h2>
						</header>

						<div class="tp-card">
							<ul class="tp-facts">
								<li><strong>Project</strong> <?php echo $h($project_name); ?></li>
								<li><strong>Datasets</strong> <?php echo (int)$counts['datasets']; ?></li>
								<li><strong>Spots</strong> <?php echo (int)$counts['spots']; ?></li>
								<li><strong>Photos</strong> <?php echo (int)$counts['images']; ?></li>
								<li><strong>Collaborators</strong> <?php echo $collabCount; ?><?php if ($collabCount > 0) echo ' <span class="tp-muted">(they keep their access after the transfer)</span>'; ?></li>
<?php if ($doiCount > 0) { ?>
								<li><strong>DOI</strong> <?php echo $doiCount; ?> <span class="tp-muted">(a DOI stays with the account that minted it and keeps resolving)</span></li>
<?php } ?>
							</ul>
						</div>

<?php if ($result !== null) { ?>
						<div class="tp-card ok" id="tp-result">
							<p><?php echo $h($result); ?></p>
							<p class="tp-muted">The receiving account must accept before anything changes. You will get an email when they do, or when the request is declined or expires.</p>
						</div>
						<ul class="actions">
							<li><a href="/my_field_data" class="button primary">Back to My StraboField Data</a></li>
						</ul>
<?php } elseif ($pending) { ?>
						<div class="tp-card warn" id="tp-pending">
							<p><strong>A transfer request for this project is pending.</strong></p>
							<ul class="tp-facts">
								<li><strong>Offered to</strong> <?php echo $h($svc->summaryOf($pending)['requested_email'] ?? ''); ?></li>
								<li><strong>Requested</strong> <?php echo $h(date('F j, Y, g:i a T', strtotime($pending->created_date))); ?></li>
								<li><strong>Expires</strong> <?php echo $h(date('F j, Y, g:i a T', strtotime($pending->expires_date))); ?></li>
								<li><strong>Afterwards</strong> <?php echo ($pending->keep_as_collaborator === 't') ? 'you stay on the project as an admin collaborator' : 'you will no longer have access to the project'; ?></li>
							</ul>
							<p class="tp-muted">Nothing changes until the receiving account accepts. You can withdraw the request until then.</p>
						</div>
						<ul class="actions">
							<li><a href="/cancel_transfer?t=<?php echo $h($pending->uuid); ?>&amp;back=project" class="button primary" onclick="return confirm('Withdraw the transfer request for <?php echo $h(str_replace("'", '', $project_name)); ?>?')">Cancel Transfer Request</a></li>
							<li><a href="/my_field_data" class="button">Back to My StraboField Data</a></li>
						</ul>
<?php } else { ?>
						<div class="tp-card">
							<p><strong>This cannot be undone from your account.</strong> The project and everything in it (datasets, spots, photos, tags, samples, saved versions and collaborator relationships) will belong to the receiving account. Their acceptance completes the transfer; until then nothing changes and you can withdraw the request.</p>
							<p class="tp-muted">After the transfer, delete the project from StraboField on your devices unless you stay on as a collaborator. The server refuses uploads of the old copy, but deleting it avoids sync errors.</p>
						</div>

<?php if ($formError !== null) { ?>
						<div class="tp-card warn" id="tp-error"><?php echo $h($formError); ?></div>
<?php } ?>

						<form method="post" action="/transfer_project" class="tp-form" id="tp-form">
							<input type="hidden" name="p" value="<?php echo $h($project_id); ?>">
							<div class="row gtr-uniform gtr-50">
								<div class="col-12">
									<label for="email">Email address of the receiving StraboSpot account</label>
									<input type="email" name="email" id="email" value="<?php echo $h($email); ?>" placeholder="name@example.edu" required autocomplete="off" spellcheck="false">
									<p class="tp-muted" style="margin-top:0.5em;">Enter the complete address. For privacy, the page will not tell you whether an account exists; the receiving person gets an email with the request.</p>
								</div>
								<div class="col-12 tp-check">
									<input type="checkbox" name="keep" id="keep" value="1"<?php echo $keep ? ' checked' : ''; ?>>
									<label for="keep">Keep me on this project as an admin collaborator after the transfer</label>
								</div>
								<div class="col-12 tp-check">
									<input type="checkbox" name="confirm" id="confirm" value="1">
									<label for="confirm">I understand that this transfer cannot be undone once the receiving account accepts</label>
								</div>
								<div class="col-12 tp-actions">
									<ul class="actions">
										<li><input type="submit" name="submit_transfer" value="Send Transfer Request" class="primary"></li>
										<li><a href="/my_field_data" class="button">Cancel</a></li>
									</ul>
								</div>
							</div>
						</form>
<?php } ?>

						<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
