<?php
/**
 * File: micro_collaborators.php
 * Description: Collaborators of a synced StraboMicro project (collaboration
 *              Phase 2, 17a, revised 2026-10-30): name, email, role, state.
 *              Open to the project's active members. The owner also invites
 *              (one address or many pasted), changes roles, and removes
 *              people here, through micro_collaborators_action.php; the app's
 *              File > Collaborate... works on the same list. The owner also
 *              makes the join link (QR code, printable page, micro_join_pdf.php)
 *              and approves who asks to join. ?waiting=1 answers only the
 *              waiting list, which the open page reloads.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once(__DIR__ . '/microdb/lib/micro_join_web.php');

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$msdb = micro_members_db($db);
try {
	$p = MsStore::project($msdb, $project_id, (int)$userpkey);
} catch (MsHttpError $e) {
	exit('Project not found.');
}
if (isset($_GET['waiting'])) {
	header('Cache-Control: no-store');
	echo $p['role'] === 'owner' ? micro_join_waiting_html($msdb, $project_id, micro_collab_token()) : '';
	exit();
}
$list = MsMembers::memberList($msdb, $p);
$name = $db->get_var_prepared(
	"SELECT COALESCE((SELECT e.body->>'name' FROM strabomicro.micro_entities e
	                   WHERE e.project_id = p.id AND e.entity_type = 'project' AND e.entity_id = p.strabo_id), p.name)
	   FROM strabomicro.micro_projectmetadata p WHERE p.id = $1",
	array($project_id));
$stateText = array('active' => 'Member', 'invited' => 'Invited', 'declined' => 'Declined');
$isOwner = $list['myRole'] === 'owner';
$csrf = micro_collab_token();
$joinLink = $isOwner ? MsJoin::linkOf($msdb, $project_id) : null;
$joinOpen = $joinLink !== null && !($joinLink['expired'] === true || $joinLink['expired'] === 't');
$result = isset($_SESSION['micro_collab_result']) && $_SESSION['micro_collab_result']['pid'] === $project_id ? $_SESSION['micro_collab_result'] : null;
unset($_SESSION['micro_collab_result']);

/** The hidden fields of every owner form. */
function collab_form_fields($project_id, $csrf) {
	return '<input type="hidden" name="pid" value="' . (int)$project_id . '">'
		. '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">';
}

include 'includes/mheader.php';
?>

<style>
.collabResult { padding:10px 14px; margin-bottom:20px; border:1px solid #2e7d32; }
.collabResult.bad { border-color:#c0392b; }
.collabResult ul { margin:4px 0 12px 0; }
.collabResult textarea { background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.2); color:inherit; height:5em; }
.collabForm { margin:0; }
.collabActions { display:flex; gap:8px; flex-wrap:wrap; }
.collabInvite { margin-top:2em; }
.collabInvite textarea { background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.2); color:inherit; height:7em; margin-bottom:1em; }
.collabInviteRow { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:1em; }
.collabInviteRow select { width:auto; min-width:12em; }
.collabHelp { opacity:0.75; font-size:0.9em; }
.collabJoin { margin-top:2em; }
.collabJoinOpen { display:flex; gap:2em; flex-wrap:wrap; align-items:flex-start; margin-bottom:1.5em; }
.collabQr { width:240px; max-width:100%; flex:none; }
.collabQr svg { display:block; width:100%; height:auto; }
.collabJoinInfo { flex:1; min-width:260px; }
.collabJoinInfo input[type=text], .collabJoinInfo textarea { background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.2); color:inherit; margin-bottom:0.8em; }
.collabJoinInfo textarea { height:5em; }
.collabAddress { font-size:1.2em; font-weight:bold; word-break:break-all; }
.collabWaitHead { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:0.8em; }
#collabFull { display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:100000; background:#fff; color:#000; text-align:center; cursor:pointer; padding:2vh 2vw; box-sizing:border-box; flex-direction:column; align-items:center; justify-content:center; }
#collabFull.show { display:flex; }
#collabFull svg { width:min(82vh, 94vw); height:min(82vh, 94vw); }
#collabFullAddress { font-size:4vh; font-weight:bold; word-break:break-all; color:#000; }
@media (max-width: 736px) {
	.collabForm select { font-size:0.8em; padding:0 1.8em 0 0.5em; min-width:6.5em; }
	.collabForm .button { padding:0 0.8em; }
	td { padding-left:0.3em !important; padding-right:0.3em !important; }
}
</style>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Collaborators</h2>
		</header>

		<section id="content">
			<h3><?php echo htmlspecialchars((string)$name)?></h3>
<?php if ($result !== null && isset($result['report'])) {
	$rep = $result['report'];
	$groups = array('invited' => 'Invited', 'again' => 'Invitation sent again', 'member' => 'Already members');
?>
			<div class="collabResult">
<?php foreach ($groups as $key => $label) { if (count($rep[$key]) > 0) { ?>
				<strong><?php echo $label?> (<?php echo count($rep[$key])?>)</strong>
				<ul><?php foreach ($rep[$key] as $e) { ?><li><?php echo htmlspecialchars($e)?></li><?php } ?></ul>
<?php } } ?>
<?php if (count($rep['not_emailed']) > 0) { ?>
				<strong>Invited, but the email could not be sent (<?php echo count($rep['not_emailed'])?>)</strong>
				<p>They will still see the invitation when they log in.</p>
				<ul><?php foreach ($rep['not_emailed'] as $e) { ?><li><?php echo htmlspecialchars($e)?></li><?php } ?></ul>
<?php } ?>
<?php if (count($rep['no_account']) > 0) { ?>
				<strong>No StraboSpot account (<?php echo count($rep['no_account'])?>)</strong>
				<p>Nothing was sent to these addresses. Ask them to create an account, then paste them here again.</p>
				<textarea readonly aria-label="Addresses with no StraboSpot account"><?php echo htmlspecialchars(implode("\n", $rep['no_account']))?></textarea>
<?php } ?>
<?php if (count($rep['refused']) > 0) { ?>
				<strong>Could not invite (<?php echo count($rep['refused'])?>)</strong>
				<ul><?php foreach ($rep['refused'] as $r) { ?><li><?php echo htmlspecialchars($r[0])?>: <?php echo htmlspecialchars($r[1])?></li><?php } ?></ul>
<?php } ?>
			</div>
<?php } elseif ($result !== null) { ?>
			<div class="collabResult<?php echo $result['ok'] ? '' : ' bad'?>"><?php echo htmlspecialchars($result['text'])?></div>
<?php } ?>
<?php if ($isOwner) { ?>
			<p>Changes here and in StraboMicro (File &gt; Collaborate...) are made to the same list.</p>
<?php } else { ?>
			<p>Only the project owner can change this list.</p>
<?php } ?>

			<div class="table-wrapper">
				<table class="myDataTable">
					<thead>
						<tr>
							<th>Name</th>
							<th class="hideSmall">Email</th>
							<th>Role</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
<?php foreach ($list['members'] as $m) {
	$pkey = (int)$m['user']['pkey'];
	$email = isset($m['user']['email']) ? $m['user']['email'] : '';
	$canChangeRole = $isOwner && $m['role'] !== 'owner' && $m['state'] !== 'declined';
?>
						<tr>
							<td><?php echo htmlspecialchars($m['user']['name'])?></td>
							<td class="hideSmall"><?php echo htmlspecialchars($email)?></td>
							<td>
<?php if ($canChangeRole) { ?>
								<form method="post" action="/micro_collaborators_action" class="collabForm">
									<?php echo collab_form_fields($project_id, $csrf)?>
									<input type="hidden" name="action" value="role">
									<input type="hidden" name="pkey" value="<?php echo $pkey?>">
									<select name="role" aria-label="Role of <?php echo htmlspecialchars($m['user']['name'])?>" onchange="this.form.submit()">
<?php foreach (MsMembers::ASSIGNABLE as $r) { ?>
										<option value="<?php echo $r?>"<?php echo $r === $m['role'] ? ' selected' : ''?>><?php echo micro_role_label($r)?></option>
<?php } ?>
									</select>
								</form>
<?php } else { ?>
								<?php echo htmlspecialchars(micro_role_label($m['role']))?>

<?php } ?>
							</td>
							<td><?php echo htmlspecialchars(isset($stateText[$m['state']]) ? $stateText[$m['state']] : $m['state'])?></td>
<?php if ($isOwner) { ?>
							<td>
<?php if ($m['role'] !== 'owner') { ?>
								<div class="collabActions">
<?php if ($m['state'] !== 'active' && $email !== '') { ?>
									<form method="post" action="/micro_collaborators_action" class="collabForm">
										<?php echo collab_form_fields($project_id, $csrf)?>
										<input type="hidden" name="action" value="invite">
										<input type="hidden" name="emails" value="<?php echo htmlspecialchars($email)?>">
										<input type="hidden" name="role" value="<?php echo htmlspecialchars($m['role'])?>">
										<button type="submit" class="button small" title="Send this person the invitation email again"><?php echo $m['state'] === 'invited' ? 'Send again' : 'Invite again'?></button>
									</form>
<?php } ?>
<?php
	$removeLabel = $m['state'] === 'active' ? 'Remove' : ($m['state'] === 'invited' ? 'Withdraw' : 'Clear');
	$removeAsk = $m['state'] === 'active'
		? 'Remove this person from the project? Their copy in StraboMicro stops syncing, and any changes they have not synced come to you for review.'
		: ($m['state'] === 'invited' ? 'Withdraw this invitation?' : 'Clear this declined invitation from the list?');
?>
									<form method="post" action="/micro_collaborators_action" class="collabForm" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($removeAsk))?>)">
										<?php echo collab_form_fields($project_id, $csrf)?>
										<input type="hidden" name="action" value="remove">
										<input type="hidden" name="pkey" value="<?php echo $pkey?>">
										<button type="submit" class="button small"><?php echo $removeLabel?></button>
									</form>
								</div>
<?php } ?>
							</td>
<?php } ?>
						</tr>
<?php } ?>
					</tbody>
				</table>
			</div>
<?php if ($list['transferTo'] !== null) { ?>
			<p>Ownership has been offered to <?php echo htmlspecialchars($list['transferTo']['name'])?> and is waiting for them to accept in StraboMicro.</p>
<?php } ?>
<?php if ($isOwner) { ?>
			<div class="collabJoin">
				<h3>Join link</h3>
<?php if ($joinOpen) {
	$joinUrl = micro_join_url($joinLink['code']);
	$joinShort = micro_join_short($joinLink['code']);
	$joinSvg = micro_join_svg($joinUrl, 'QR code for ' . $joinShort);
?>
				<div class="collabJoinOpen">
					<div class="collabQr"><?php echo $joinSvg?></div>
					<div class="collabJoinInfo">
						<p class="collabAddress" id="collabAddress"><?php echo htmlspecialchars($joinShort)?></p>
						<p>People who open this link ask to join as <?php echo htmlspecialchars(micro_role_label($joinLink['role']))?>, and you approve each one below. It stops working on <?php echo htmlspecialchars(date('F j, Y', strtotime($joinLink['expires_on'])))?>.</p>
						<div class="collabActions" style="margin-bottom:1.5em;">
							<button type="button" class="button small" onclick="collabFull(true)">Show Full Screen</button>
							<button type="button" class="button small" onclick="collabCopy(this)" data-url="<?php echo htmlspecialchars($joinUrl)?>">Copy Link</button>
							<form method="post" action="/micro_collaborators_action" class="collabForm" onsubmit="return confirm('Stop this join link? The QR code and any printed pages stop working. People who already joined stay.')">
								<?php echo collab_form_fields($project_id, $csrf)?>
								<input type="hidden" name="action" value="revoke_link">
								<button type="submit" class="button small">Revoke Link</button>
							</form>
						</div>
						<form method="post" action="/micro_join_pdf">
							<?php echo collab_form_fields($project_id, $csrf)?>
							<label for="joinTitle">Title for the printable page (optional)</label>
							<input type="text" id="joinTitle" name="title" maxlength="<?php echo MsJoin::TITLE_MAX?>" value="<?php echo htmlspecialchars((string)$joinLink['pdf_title'])?>" placeholder="Join this StraboMicro project">
							<label for="joinDetails">Details (optional)</label>
							<textarea id="joinDetails" name="details" maxlength="<?php echo MsJoin::DETAILS_MAX?>" placeholder="Workshop name, date, room, what to bring..."><?php echo htmlspecialchars((string)$joinLink['pdf_details'])?></textarea>
							<button type="submit" class="button small">Download PDF</button>
						</form>
					</div>
				</div>
				<div id="collabFull" onclick="collabFull(false)" role="dialog" aria-label="Join link QR code">
					<?php echo $joinSvg?>
					<div id="collabFullAddress"><?php echo htmlspecialchars($joinShort)?></div>
				</div>
<?php } else { ?>
<?php if ($joinLink !== null) { ?>
				<p>The last join link expired on <?php echo htmlspecialchars(date('F j, Y', strtotime($joinLink['expires_on'])))?>.</p>
<?php } ?>
				<p>Make a QR code and a short address for a workshop or class. Anyone with the link can ask to join, including people who still need to create an account, and you approve each person.</p>
				<form method="post" action="/micro_collaborators_action">
					<?php echo collab_form_fields($project_id, $csrf)?>
					<input type="hidden" name="action" value="create_link">
					<div class="collabInviteRow">
						<label for="joinRole" style="margin:0;">Role</label>
						<select id="joinRole" name="role">
							<option value="contributor" selected>Contributor</option>
							<option value="editor">Editor</option>
							<option value="viewer">Viewer</option>
						</select>
						<label for="joinDays" style="margin:0;">Stays open for</label>
						<select id="joinDays" name="days">
<?php foreach (MsJoin::EXPIRY_DAYS as $d) { ?>
							<option value="<?php echo $d?>"<?php echo $d === 7 ? ' selected' : ''?>><?php echo $d === 1 ? '1 day' : "$d days"?></option>
<?php } ?>
						</select>
						<button type="submit" class="button primary small">Create Join Link</button>
					</div>
				</form>
<?php } ?>
				<div id="collabWaiting"><?php echo micro_join_waiting_html($msdb, $project_id, $csrf)?></div>
			</div>
			<script>
			function collabFull(show) {
				document.getElementById('collabFull').classList.toggle('show', show);
			}
			function collabCopy(button) {
				navigator.clipboard.writeText(button.getAttribute('data-url')).then(function () {
					button.textContent = 'Copied';
					setTimeout(function () { button.textContent = 'Copy Link'; }, 2000);
				});
			}
			document.addEventListener('keydown', function (e) {
				var full = document.getElementById('collabFull');
				if (e.key === 'Escape' && full) { full.classList.remove('show'); }
			});
			setInterval(function () {
				fetch('/micro_collaborators?project_id=<?php echo (int)$project_id?>&waiting=1', { credentials: 'same-origin', redirect: 'error' })
					.then(function (r) { return r.ok ? r.text() : null; })
					.then(function (html) {
						var box = document.getElementById('collabWaiting');
						if (html !== null && box.innerHTML !== html) { box.innerHTML = html; }
					})
					.catch(function () {});
			}, 5000);
			</script>

			<div class="collabInvite">
				<h3>Invite people</h3>
				<form method="post" action="/micro_collaborators_action">
					<?php echo collab_form_fields($project_id, $csrf)?>
					<input type="hidden" name="action" value="invite">
					<label for="collabEmails">Email addresses</label>
					<textarea id="collabEmails" name="emails" placeholder="jane@example.edu, sam@example.edu"></textarea>
					<p class="collabHelp">One address or many, up to 100 at a time. Separate them with commas, spaces or new lines, or paste a list from an email or a column from a spreadsheet. Each person needs a StraboSpot account.</p>
					<div class="collabInviteRow">
						<label for="collabRole" style="margin:0;">Role</label>
						<select id="collabRole" name="role">
							<option value="contributor" selected>Contributor</option>
							<option value="editor">Editor</option>
							<option value="viewer">Viewer</option>
						</select>
						<button type="submit" class="button primary small">Send Invitations</button>
					</div>
					<p class="collabHelp">Contributors add anything and change or delete what they added. Editors can change anything. Viewers can only look.</p>
				</form>
			</div>
<?php } ?>
			<p><a href="/my_micro_data" class="button primary small">Back to My StraboMicro Data</a></p>
		</section>

	<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include 'includes/mfooter.php';
