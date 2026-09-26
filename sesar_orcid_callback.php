<?php
/**
 * File: sesar_orcid_callback.php
 * Description: ORCID redirect target for "Connect SESAR" (the redirect URI
 *              registered on the WEB ORCID client; must stay at this exact
 *              URL). Runs inside the popup: checks the state nonce, trades
 *              the code for the ORCID id_token, asks SESAR where the user
 *              stands (SesarOnboarding), tells the opener page, and closes.
 *
 *              Never touches orcid_callback.php (the Field app's flow).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include_once("includes/config.inc.php");
include("db.php");
require_once __DIR__ . "/includes/sesar/SesarOrcid.php";
require_once __DIR__ . "/includes/sesar/SesarOnboarding.php";

header('Cache-Control: no-store');
$userpkey = (int)$_SESSION['userpkey'];
$step = null;
$problem = null;

if (!SesarAccess::canUse($userpkey) || !SesarAccess::isConfigured()) {
	http_response_code(403);
	$problem = 'SESAR connections are not available for this account.';
} elseif (isset($_GET['error'])) {
	// User pressed Deny at ORCID (error=access_denied) or ORCID refused.
	SesarOrcid::consumeState(isset($_GET['state']) ? $_GET['state'] : '');
	$problem = 'ORCID sign-in was cancelled. You can start again from the IGSN page whenever you are ready.';
} elseif (!SesarOrcid::consumeState(isset($_GET['state']) ? (string)$_GET['state'] : '')) {
	http_response_code(400);
	$problem = 'This sign-in link has expired or was already used. Please click Connect again on the IGSN page.';
} else {
	try {
		$client = new SesarClient();
		$identity = (new SesarOrcid())->exchangeCode(isset($_GET['code']) ? $_GET['code'] : '');
		$onboarding = new SesarOnboarding($db, $client, new SesarConnection($db, $client));
		$status = $onboarding->acceptOrcid($userpkey, $identity);
		$step = $status['step'];
		if ($status['last_error'] !== null && $step === 'not_connected') $problem = $status['last_error'];
	} catch (SesarError $e) {
		$problem = $e->getMessage();
	}
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connect SESAR</title>
<style>
body { margin: 0; background: #1c1d26; color: rgba(255,255,255,0.85); font: 16px/1.6 "Roboto", Helvetica, sans-serif; }
main { max-width: 32em; margin: 4em auto; padding: 0 1.5em; }
h1 { font-size: 1.3em; font-weight: 400; color: #fff; }
a { color: #e44c65; }
</style>
</head>
<body>
<main>
<?php if ($problem !== null): ?>
	<h1>SESAR connection</h1>
	<p><?= htmlspecialchars($problem, ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
	<h1>Signed in with ORCID</h1>
	<p>You can close this window; the IGSN page has been updated.</p>
<?php endif; ?>
	<p><a href="/samples_igsn.php">Go to the IGSN page</a></p>
</main>
<script>
(function () {
	var msg = { source: 'sesar-connect', step: <?= json_encode($step) ?>, problem: <?= json_encode($problem) ?> };
	if (window.opener && !window.opener.closed) {
		try { window.opener.postMessage(msg, window.location.origin); } catch (e) {}
		if (msg.problem === null) setTimeout(function () { window.close(); }, 600);
	}
})();
</script>
</body>
</html>
