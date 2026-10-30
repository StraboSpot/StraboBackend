<?php
/**
 * File: micro_join.php
 * Description: Where a StraboMicro join link leads (/join/<code>, the QR code
 *              or the short address). Open without logging in; the project's
 *              name shows only after logging in. A logged-in person asks to
 *              join and waits for the owner's approval (microsync/lib/MsJoin.php).
 *              ?status=1 answers this person's standing, for the waiting page.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once(__DIR__ . "/includes/session_config.php");
session_start();
include_once("./includes/config.inc.php");
include("db.php");
require_once(__DIR__ . '/microdb/lib/micro_join_web.php');

$loggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === 'yes'
	&& !(isset($_SESSION['LAST_ACTIVITY']) && time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT);
if ($loggedIn) {
	$_SESSION['LAST_ACTIVITY'] = time();
}
$me = $loggedIn ? (int)$_SESSION['userpkey'] : 0;

$db->get_var('SELECT 1');
$msdb = micro_members_db($db);
$link = MsJoin::byCode($msdb, isset($_GET['c']) ? $_GET['c'] : '');
$code = $link !== null ? $link['code'] : '';

if ($link !== null && isset($_GET['status'])) {
	header('Content-Type: text/plain');
	header('Cache-Control: no-store');
	echo $loggedIn ? MsJoin::standing($msdb, $me, $link) : 'none';
	exit();
}

$message = null;
if ($link !== null && $loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
	if (micro_collab_token_ok(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
		try {
			MsJoin::ask($msdb, $me, $code);
		} catch (MsHttpError $e) {
			$_SESSION['micro_join_message'] = $e->getMessage();
		}
	} else {
		$_SESSION['micro_join_message'] = 'This page was open too long. Please try again.';
	}
	header('Location: /join/' . $code);
	exit();
}
if (isset($_SESSION['micro_join_message'])) {
	$message = $_SESSION['micro_join_message'];
	unset($_SESSION['micro_join_message']);
}

$standing = $link !== null && $loggedIn ? MsJoin::standing($msdb, $me, $link) : null;
if ($link !== null && !$loggedIn) {
	// Login, and the first login after signing up in this browser, come back here
	$_SESSION['uri'] = '/join/' . $code;
}

include 'includes/mheader.php';
?>

<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Join a StraboMicro Project</h2>
		</header>

		<section id="content" style="text-align:center;">
<?php if ($message !== null) { ?>
			<p><?php echo htmlspecialchars($message)?></p>
<?php } ?>
<?php if ($link === null) { ?>
			<p>This join link is not valid, or the project owner has stopped it. Please ask them for a new one.</p>
<?php } elseif (!$link['open'] && !in_array($standing, array('member', 'requested'), true)) { ?>
			<p>This join link has expired. Please ask the project owner for a new one.</p>
<?php } elseif (!$loggedIn) { ?>
			<p>You've been given a link to join a StraboMicro project. Log in with your StraboSpot account, or create a free one, and then ask to join.</p>
			<p>
				<a href="/login" class="button primary">Log In</a>
				<a href="/register?join=<?php echo htmlspecialchars($code)?>" class="button">Create Account</a>
			</p>
<?php } else { ?>
			<h3><?php echo htmlspecialchars($link['project_name'])?></h3>
<?php if ($standing === 'none') { ?>
			<p>You'll join as <?php echo htmlspecialchars(micro_role_label($link['role']))?>. The project owner approves each person, and will see your name and email address.</p>
			<form method="post" action="/join/<?php echo htmlspecialchars($code)?>">
				<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(micro_collab_token())?>">
				<button type="submit" class="button primary">Ask to Join</button>
			</form>
<?php } elseif ($standing === 'requested') { ?>
			<p><strong>Your request was sent.</strong> Waiting for the project owner to approve it. This page updates by itself, and you'll also get an email.</p>
			<script>
			setInterval(function () {
				fetch('/join/<?php echo htmlspecialchars($code)?>?status=1', { credentials: 'same-origin' })
					.then(function (r) { return r.text(); })
					.then(function (s) { if (s !== 'requested') { location.reload(); } })
					.catch(function () {});
			}, 5000);
			</script>
<?php } elseif ($standing === 'member') { ?>
			<p><strong>You're in.</strong> Open StraboMicro on your computer, log in with this account, and choose File &gt; Open Remote Project.</p>
<?php } elseif ($standing === 'invited') { ?>
			<p>You already have an invitation to this project.</p>
			<p><a href="/my_micro_data" class="button primary">Review the Invitation</a></p>
<?php } elseif ($standing === 'denied') { ?>
			<p>The project owner did not approve this request. If you think that's a mistake, please ask them.</p>
<?php } elseif ($standing === 'has_copy') { ?>
			<p>You already have your own project with the same id on StraboSpot, for example an upload of a copy, so you cannot join this one. Please ask the project owner, or delete your copy on My StraboMicro Data and scan again.</p>
<?php } ?>
<?php } ?>
		</section>

	<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include 'includes/mfooter.php';
