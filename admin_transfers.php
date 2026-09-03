<?php
/**
 * File: admin_transfers.php
 * Description: Admin page (userpkey 3 only) for StraboField project
 *              ownership transfers (docs/ProjectTransfer_Design.md §9, D6).
 *              Lists every project_transfers row newest first with status /
 *              email / project-id filters, a detail panel for one row (the
 *              audit summary: counts before and after, step reached, error,
 *              refusal reason) and three actions on it:
 *                Retry   - execute() again on a failed row; resumes from the
 *                          last completed step and mails the parties on
 *                          success (accepted, or reversed for a reversal).
 *                Reverse - reverse() on a completed, not yet reversed row:
 *                          a NEW row with the parties swapped; mails both.
 *                Verify  - read-only recount of every ownership store for
 *                          the row, flagging anything still held by the old
 *                          owner (the post-transfer audit in one click).
 *              See includes/transfer/ProjectTransfer.php for the service.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");

if($userpkey !== 3) die("Not authorized.");

require_once("includes/transfer/ProjectTransfer.php");
require_once("includes/transfer/ProjectTransferMail.php");

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

if (!ProjectTransfer::tableExists($db)) die("project_transfers table missing: apply sql/project_transfers.sql as postgres first (docs/ProjectTransfer_deploy_runbook.md).");

$svc = new ProjectTransfer($db, $neodb);
$mailer = new ProjectTransferMail($db, $neodb);

// Lazy expiry, same as My Field Data: the list must not show stale "pending".
foreach ($svc->expireStale() as $expiredRow) { $mailer->expired($expiredRow); }

// ---------------------------------------------------------------------------
// Actions (POST-redirect-GET, back to the detail panel of the row acted on)
// ---------------------------------------------------------------------------
$back = function ($pkey, $msg = '', $err = '') {
	$q = array();
	if ($pkey) $q['sel'] = (int)$pkey;
	if ($msg !== '') $q['msg'] = $msg;
	if ($err !== '') $q['err'] = $err;
	header("Location: /admin_transfers" . ($q ? '?' . http_build_query($q) : ''));
	exit();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$pkey = (int)($_POST['pkey'] ?? 0);
	$row = $pkey > 0 ? $svc->getByPkey($pkey) : null;
	if (!$row) $back(0, '', 'No such transfer row.');
	// The rewrite runs in this request: no PHP time limit, and release the
	// session lock so the page's transfer_status polls are answered meanwhile.
	set_time_limit(0);
	session_write_close();

	if ($action === 'retry') {
		if ($row->status !== 'failed') $back($pkey, '', "Transfer #$pkey is {$row->status}; only a failed row can be retried.");
		$res = $svc->execute($row, $userpkey);
		if ($res['ok']) {
			$done = $res['row'];
			if ($done->kind === 'reversal') {
				$sum = $svc->summaryOf($done);
				if (!empty($sum['reverses_pkey'])) $svc->markReversed((int)$sum['reverses_pkey'], $userpkey);
				$mailer->reversed($done);
			} else {
				$mailer->accepted($done);
			}
			$back($pkey, "Transfer #$pkey retried and completed. Both parties have been emailed.");
		}
		$back($pkey, '', "Transfer #$pkey failed again: " . (string)$res['error']);

	} elseif ($action === 'reverse') {
		$res = $svc->reverse($pkey, $userpkey);
		if ($res['ok']) {
			$mailer->reversed($res['row']);
			$back((int)$res['row']->pkey, "Transfer #$pkey reversed as #" . (int)$res['row']->pkey . ". Both parties have been emailed.");
		}
		if ($res['row'] && (int)$res['row']->pkey !== $pkey) {
			// The reversal row was created but its execute failed: point at it, Retry lives there.
			$back((int)$res['row']->pkey, '', "Reversal of #$pkey started as #" . (int)$res['row']->pkey . " but failed: " . (string)$res['reason'] . " Fix the cause, then Retry from this row.");
		}
		$back($pkey, '', "Transfer #$pkey was not reversed: " . (string)$res['reason']);
	}
	$back($pkey);
}

// ---------------------------------------------------------------------------
// Filters + list
// ---------------------------------------------------------------------------
$STATUSES = array('pending', 'accepted', 'declined', 'cancelled', 'expired', 'refused', 'failed');
$fStatus  = (string)($_GET['status'] ?? '');
if (!in_array($fStatus, $STATUSES, true)) $fStatus = '';
$fEmail   = trim((string)($_GET['email'] ?? ''));
$fProject = preg_replace('/[^0-9a-zA-Z\-]/', '', (string)($_GET['project'] ?? ''));
$sel      = (int)($_GET['sel'] ?? 0);
$doVerify = !empty($_GET['verify']);
$LIMIT    = 300;

$where = array(); $params = array();
if ($fStatus !== '')  { $params[] = $fStatus; $where[] = 't.status = $' . count($params); }
if ($fEmail !== '')   { $params[] = '%' . strtolower($fEmail) . '%'; $n = count($params); $where[] = "(lower(uf.email) LIKE \$$n OR lower(ut.email) LIKE \$$n)"; }
if ($fProject !== '') { $params[] = $fProject; $where[] = 't.strabo_project_id = $' . count($params); }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$listSql = "SELECT t.*, uf.email AS from_email, ut.email AS to_email,
                   (t.applied AND t.tombstone_cleared_date IS NULL) AS tombstone
              FROM project_transfers t
              LEFT JOIN users uf ON uf.pkey = t.from_user_pkey
              LEFT JOIN users ut ON ut.pkey = t.to_user_pkey
              $sqlWhere
             ORDER BY t.pkey DESC LIMIT " . ($LIMIT + 1);
$rows = $params ? (array)$db->get_results_prepared($listSql, $params) : (array)$db->get_results($listSql);
$truncated = count($rows) > $LIMIT;
if ($truncated) array_pop($rows);
$total = (int)$db->get_var("SELECT count(*) FROM project_transfers");

// Selected row + its summary (+ optional verify recount)
$selRow = null; $selSum = array(); $selFrom = null; $selTo = null; $verifyRes = null;
if ($sel > 0) {
	$selRow = $svc->getByPkey($sel);
	if ($selRow) {
		$selSum  = $svc->summaryOf($selRow);
		$selFrom = $db->get_row_prepared("SELECT pkey, email, firstname, lastname, active, deleted FROM users WHERE pkey = $1", array((int)$selRow->from_user_pkey));
		$selTo   = $db->get_row_prepared("SELECT pkey, email, firstname, lastname, active, deleted FROM users WHERE pkey = $1", array((int)$selRow->to_user_pkey));
		if ($doVerify) $verifyRes = $svc->verify($selRow);
	}
}

$flash = (string)($_GET['msg'] ?? '');
$flasherror = (string)($_GET['err'] ?? '');

$who = function ($u) {
	if (!$u) return '(unknown user)';
	$n = trim($u->firstname . ' ' . $u->lastname);
	$s = ($n !== '' ? $n . ' ' : '') . '<' . $u->email . '> (' . (int)$u->pkey . ')';
	if ($u->deleted === 't') $s .= ' DELETED';
	elseif ($u->active !== 't') $s .= ' inactive';
	return $s;
};
$when = function ($ts, $withTime = true) {
	if ($ts === null || $ts === '') return '';
	$t = strtotime($ts);
	return $t ? date($withTime ? 'Y-m-d H:i T' : 'Y-m-d', $t) : (string)$ts;
};
$canRetry   = $selRow && $selRow->status === 'failed';
$canReverse = $selRow && $selRow->status === 'accepted' && $selRow->reversed_date === null;
$canVerify  = $selRow && ($selRow->applied === 't' || in_array($selRow->status, array('accepted', 'failed'), true));

/** Store recount table (verify() output or summary before/after). $flag = paint rows the old owner still holds red (not for the "before" snapshot). */
function atStores($stores, $edgeOwners, $clean, $from, $to, $h, $flag = true) {
	echo '<table class="at-stores"><tr><th>Store</th><th>old owner (' . (int)$from . ')</th><th>new owner (' . (int)$to . ')</th><th>other</th></tr>';
	foreach ((array)$stores as $name => $c) {
		$bad = ($flag && $name !== 'dois_untouched' && !empty($c['from']));
		echo '<tr' . ($bad ? ' class="at-bad"' : '') . '><td>' . $h($name) . ($name === 'dois_untouched' ? ' <span class="at-dim">(stay put by design)</span>' : '') . '</td>'
			. '<td>' . (int)($c['from'] ?? 0) . '</td><td>' . (int)($c['to'] ?? 0) . '</td><td>' . (isset($c['other']) ? (int)$c['other'] : '') . '</td></tr>';
	}
	if ($edgeOwners !== null) {
		$eo = (array)$edgeOwners;
		$bad = ($flag && $eo !== array((int)$to));
		echo '<tr' . ($bad ? ' class="at-bad"' : '') . '><td>HAS_PROJECT edge owner(s)</td><td colspan="3">' . ($eo ? $h(implode(', ', $eo)) : '(none)') . '</td></tr>';
	}
	echo '</table>';
	if ($clean !== null) {
		echo '<p class="' . ($clean ? 'at-clean' : 'at-dirty') . '" id="at-verify-verdict">' . ($clean ? 'CLEAN: no store holds the old owner; the project edge belongs to the new owner only.' : 'NOT CLEAN: a store still holds the old owner (rows in red).') . '</p>';
	}
}

include("includes/mheader.php");
?>

<style>
/* Palette follows the site-wide dark-theme conventions (admin_merge_prefs.php). */
.at-intro { max-width: 760px; margin: 0 auto 1.5em auto; text-align: center; }
.at-flash { text-align: center; margin: 10px; color: #7bd88f; }
.at-flash-err { text-align: center; margin: 10px; color: #f06880; }
.at-panel { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.2);
	border-radius: 6px; padding: 1em 1.25em; margin-bottom: 2em; text-align: left; }
.at-panel h4 { margin-top: 0; }
.at-filters { display: flex; flex-wrap: wrap; gap: 0.75em 1.25em; align-items: flex-end; }
.at-filters label { display: block; font-weight: bold; margin-bottom: 0.25em; color: #ffffff; font-size: 0.9em; }
.at-filters input[type=text], .at-filters select { box-sizing: border-box; min-width: 200px;
	background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.2);
	border-radius: 4px; color: #ffffff; padding: 0.5em 0.7em; font-size: 0.95em; }
.at-filters select option { color: #000000; }
.at-filters input[type=text]:focus, .at-filters select:focus { border-color: #e44c65; outline: none; }
.at-btn { background: #e44c65; color: #ffffff; border: none; border-radius: 4px;
	padding: 0.55em 1.1em; font-size: 0.95em; cursor: pointer; }
.at-btn:hover { background: #f06880; }
.at-btn-quiet { background: rgba(255, 255, 255, 0.12); color: #ffffff; border: none; border-radius: 4px;
	padding: 0.55em 1.1em; font-size: 0.95em; cursor: pointer; text-decoration: none; display: inline-block; }
.at-btn-quiet:hover { background: rgba(255, 255, 255, 0.2); }
.at-actions { display: flex; flex-wrap: wrap; gap: 0.75em; align-items: center; margin: 0.75em 0 0.25em 0; }
.at-actions form { margin: 0; }
.at-kv { width: 100%; border-collapse: collapse; margin-bottom: 0.75em; }
.at-kv td { padding: 0.25em 0.6em; vertical-align: top; }
.at-kv td:first-child { color: rgba(255, 255, 255, 0.6); white-space: nowrap; width: 12em; }
.at-stores { border-collapse: collapse; margin: 0.5em 0 0.75em 0; }
.at-stores th, .at-stores td { padding: 0.25em 0.8em; text-align: left; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.at-stores th { color: rgba(255, 255, 255, 0.6); font-weight: bold; }
.at-stores tr.at-bad td { color: #f06880; font-weight: bold; }
.at-clean { color: #7bd88f; font-weight: bold; }
.at-dirty { color: #f06880; font-weight: bold; }
.at-dim { color: rgba(255, 255, 255, 0.5); font-size: 0.9em; }
.at-err { background: rgba(240, 104, 128, 0.15); border: 1px solid rgba(240, 104, 128, 0.5); border-radius: 4px;
	padding: 0.5em 0.8em; color: #f8b4c0; white-space: pre-wrap; word-break: break-word; }
.at-table td { padding: 0.35em 0.6em; white-space: nowrap; }
.at-table td.at-wrap { white-space: normal; }
.at-table tr.at-selrow td { background: rgba(228, 76, 101, 0.18); }
.at-status { display: inline-block; padding: 0.1em 0.5em; border-radius: 3px; font-size: 0.85em; }
.at-status-pending { background: rgba(255, 255, 255, 0.15); }
.at-status-accepted { background: rgba(123, 216, 143, 0.3); }
.at-status-failed { background: rgba(240, 104, 128, 0.45); }
.at-status-refused { background: rgba(240, 104, 128, 0.25); }
.at-status-declined, .at-status-cancelled, .at-status-expired { background: rgba(255, 255, 255, 0.08); color: rgba(255, 255, 255, 0.7); }
.at-kind-reversal { color: #f6c177; }
.at-tomb { color: #f6c177; font-size: 0.85em; }
.at-count { color: rgba(255, 255, 255, 0.6); margin: 0.25em 0 0.5em 0; }
pre.at-json { background: rgba(0, 0, 0, 0.3); border-radius: 4px; padding: 0.6em 0.8em; font-size: 0.8em;
	max-height: 320px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
details.at-raw summary { cursor: pointer; color: rgba(255, 255, 255, 0.7); }
.at-progress { margin: 1em 0; padding: 0.8em 1em; border-radius: 6px; background: rgba(255,255,255,0.06); border-left: 4px solid #6fbf73; }
.at-spinner { display: inline-block; width: 1em; height: 1em; margin-right: 0.5em; vertical-align: -0.15em; border: 2px solid rgba(255,255,255,0.25); border-top-color: #fff; border-radius: 50%; animation: at-spin 0.8s linear infinite; }
@keyframes at-spin { to { transform: rotate(360deg); } }
.at-steps { margin: 0.5em 0 0.6em 1.5em; padding: 0; }
.at-steps li { padding: 0.1em 0; color: rgba(255,255,255,0.45); }
.at-steps li.active { color: #fff; }
.at-steps li.done { color: #6fbf73; }
.at-steps li.done::after { content: " \2713"; }
.at-steps li.failed { color: #e44c65; }
.at-steps li.failed::after { content: " \2717"; }
</style>

<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Project Transfers</h2>
		</header>

		<p class="at-intro">
			Every StraboField project ownership transfer: requests, outcomes and the audit of what moved.
			<strong>Retry</strong> resumes a failed transfer from its last completed step. <strong>Reverse</strong> hands a
			completed transfer back (a new row with the parties swapped). <strong>Verify</strong> recounts every ownership
			store for a row. Rows marked <span class="at-tomb">tombstone</span> refuse the old owner's device re-uploads of that project.
		</p>

<?php if ($flash !== '') { ?>
	<div class="at-flash" id="at-flash"><?php echo $h($flash); ?></div>
<?php } ?>
<?php if ($flasherror !== '') { ?>
	<div class="at-flash-err" id="at-flash-err"><?php echo $h($flasherror); ?></div>
<?php } ?>

<?php if ($sel > 0 && !$selRow) { ?>
	<div class="at-panel" id="at-detail"><p>No transfer row #<?php echo (int)$sel; ?>.</p></div>
<?php } elseif ($selRow) {
	$pname = (string)$selRow->project_name !== '' ? $selRow->project_name : ('project ' . $selRow->strabo_project_id);
	$tomb = ($selRow->applied === 't' && $selRow->tombstone_cleared_date === null);
	$fromName = $selFrom ? trim($selFrom->firstname . ' ' . $selFrom->lastname) . ' <' . $selFrom->email . '>' : ('user ' . (int)$selRow->from_user_pkey);
	$toName   = $selTo   ? trim($selTo->firstname . ' ' . $selTo->lastname) . ' <' . $selTo->email . '>' : ('user ' . (int)$selRow->to_user_pkey);
?>
	<div class="at-panel" id="at-detail">
		<h4>Transfer #<?php echo (int)$selRow->pkey; ?>
			<span class="at-status at-status-<?php echo $h($selRow->status); ?>"><?php echo $h($selRow->status); ?></span>
			<?php if ($selRow->kind === 'reversal') { ?><span class="at-kind-reversal">reversal</span><?php } ?>
			<?php if ($tomb) { ?><span class="at-tomb">tombstone active for the old owner</span><?php } ?>
		</h4>
		<table class="at-kv">
			<tr><td>Project</td><td><?php echo $h($pname); ?> <span class="at-dim">(id <?php echo $h($selRow->strabo_project_id); ?>)</span></td></tr>
			<tr><td>From (old owner)</td><td><?php echo $h($who($selFrom)); ?></td></tr>
			<tr><td>To (new owner)</td><td><?php echo $h($who($selTo)); ?></td></tr>
			<tr><td>Keep old owner as admin</td><td><?php echo $selRow->keep_as_collaborator === 't' ? 'yes' : 'no'; ?></td></tr>
			<tr><td>Token</td><td><span class="at-dim"><?php echo $h($selRow->uuid); ?></span></td></tr>
			<tr><td>Requested</td><td><?php echo $h($when($selRow->created_date)); ?><?php if ($selRow->requested_by_pkey !== null) echo ' by user ' . (int)$selRow->requested_by_pkey; ?>, expires <?php echo $h($when($selRow->expires_date)); ?></td></tr>
			<tr><td>Decided</td><td><?php echo $selRow->decided_date !== null ? $h($when($selRow->decided_date)) . ($selRow->decided_by_pkey !== null ? ' by user ' . (int)$selRow->decided_by_pkey : '') : '(not yet)'; ?></td></tr>
			<tr><td>Completed</td><td><?php echo $selRow->completed_date !== null ? $h($when($selRow->completed_date)) : '(not completed)'; ?></td></tr>
			<tr><td>Rewrite step</td><td><?php echo (int)$selRow->step; ?> of <?php echo ProjectTransfer::STEP_DONE; ?> completed<?php echo $selRow->applied === 't' ? ' (data rewrite started)' : ' (nothing rewritten)'; ?><?php if (isset($selSum['failed_step'])) echo ', failed at step ' . (int)$selSum['failed_step']; ?></td></tr>
<?php if (!empty($selSum['timings']) && is_array($selSum['timings'])) { $tparts = array(); $ttotal = 0; foreach (array('eligibility', 'preflight', 'neo4j', 'postgres', 'mirror', 'search', 'recount') as $k) { if (isset($selSum['timings'][$k])) { $tparts[] = $k . ' ' . (int)$selSum['timings'][$k] . ' ms'; $ttotal += (int)$selSum['timings'][$k]; } } ?>
			<tr><td>Step timings</td><td id="at-timings"><?php echo $h(implode(', ', $tparts)); ?> <span class="at-dim">(total <?php echo $ttotal >= 1000 ? round($ttotal / 1000, 1) . ' s' : $ttotal . ' ms'; ?>)</span></td></tr>
<?php } ?>
<?php if ($selRow->reversed_date !== null) { ?>
			<tr><td>Reversed</td><td><?php echo $h($when($selRow->reversed_date)); ?> by user <?php echo (int)$selRow->reversed_by_pkey; ?></td></tr>
<?php } ?>
<?php if ($selRow->tombstone_cleared_date !== null) { ?>
			<tr><td>Tombstone cleared</td><td><?php echo $h($when($selRow->tombstone_cleared_date)); ?></td></tr>
<?php } ?>
<?php if (!empty($selSum['reverses_pkey'])) { ?>
			<tr><td>Reverses</td><td><a href="/admin_transfers?sel=<?php echo (int)$selSum['reverses_pkey']; ?>">transfer #<?php echo (int)$selSum['reverses_pkey']; ?></a></td></tr>
<?php } ?>
<?php if (!empty($selSum['reason'])) { ?>
			<tr><td>Reason</td><td id="at-reason"><?php echo $h($selSum['reason']); ?></td></tr>
<?php } ?>
<?php if (!empty($selSum['error'])) { ?>
			<tr><td>Error</td><td><div class="at-err" id="at-error"><?php echo $h($selSum['error']); ?></div></td></tr>
<?php } ?>
<?php if (!empty($selSum['counts_at_request']) && is_array($selSum['counts_at_request'])) { $parts = array(); foreach (array('datasets', 'spots', 'images') as $k) { if (isset($selSum['counts_at_request'][$k])) $parts[] = $k . ' ' . (int)$selSum['counts_at_request'][$k]; } ?>
			<tr><td>Counts at request</td><td><?php echo implode(', ', $parts); ?></td></tr>
<?php } ?>
<?php if (!empty($selSum['nids']) || isset($selSum['sample_ids']) || isset($selSum['removed_collaborator_rows'])) { ?>
			<tr><td>Moved</td><td><?php
				$bits = array();
				if (!empty($selSum['nids'])) $bits[] = count((array)$selSum['nids']) . ' project node' . (count((array)$selSum['nids']) === 1 ? '' : 's');
				if (isset($selSum['sample_ids'])) $bits[] = count((array)$selSum['sample_ids']) . ' spine sample' . (count((array)$selSum['sample_ids']) === 1 ? '' : 's');
				if (isset($selSum['removed_collaborator_rows'])) $bits[] = count((array)$selSum['removed_collaborator_rows']) . ' recipient collaborator row' . (count((array)$selSum['removed_collaborator_rows']) === 1 ? '' : 's') . ' removed';
				if (isset($selSum['edges_rewritten'])) $bits[] = (int)$selSum['edges_rewritten'] . ' tag / relationship edge' . ((int)$selSum['edges_rewritten'] === 1 ? '' : 's');
				echo $h(implode(', ', $bits));
			?></td></tr>
<?php } ?>
		</table>

		<div class="at-actions">
<?php if ($canRetry) { ?>
			<form method="post" action="/admin_transfers" onsubmit="return atWork(<?php echo $h(json_encode('Retry transfer #' . (int)$selRow->pkey . ' from step ' . ((int)$selRow->step + 1) . '? ' . $pname . ' moves from ' . $fromName . ' to ' . $toName . '.')); ?>, <?php echo $h(json_encode('Retrying transfer #' . (int)$selRow->pkey . ' from step ' . ((int)$selRow->step + 1))); ?>, <?php echo $h(json_encode('/transfer_status?pkey=' . (int)$selRow->pkey)); ?>, <?php echo (int)$selRow->step; ?>)">
				<input type="hidden" name="action" value="retry">
				<input type="hidden" name="pkey" value="<?php echo (int)$selRow->pkey; ?>">
				<button type="submit" class="at-btn" id="at-retry">Retry from step <?php echo (int)$selRow->step + 1; ?></button>
			</form>
<?php } ?>
<?php if ($canReverse) { ?>
			<form method="post" action="/admin_transfers" onsubmit="return atWork(<?php echo $h(json_encode('Reverse transfer #' . (int)$selRow->pkey . '? ' . $pname . ' goes BACK from ' . $toName . ' to ' . $fromName . '. Both accounts will be emailed.')); ?>, <?php echo $h(json_encode('Reversing transfer #' . (int)$selRow->pkey . ' (a new row with the parties swapped)')); ?>, <?php echo $h(json_encode('/transfer_status?reverse_of=' . (int)$selRow->pkey)); ?>, 0)">
				<input type="hidden" name="action" value="reverse">
				<input type="hidden" name="pkey" value="<?php echo (int)$selRow->pkey; ?>">
				<button type="submit" class="at-btn" id="at-reverse">Reverse this transfer</button>
			</form>
<?php } ?>
<?php if ($canVerify) { ?>
			<a class="at-btn-quiet" id="at-verify" href="/admin_transfers?sel=<?php echo (int)$selRow->pkey; ?>&amp;verify=1">Verify (recount every store)</a>
<?php } ?>
			<a class="at-btn-quiet" href="/admin_transfers">Close</a>
		</div>
<?php if ($canRetry || $canReverse) { ?>
		<div class="at-progress" id="at-progress" style="display:none">
			<p><span class="at-spinner"></span><strong id="at-progress-title">Working&hellip;</strong></p>
			<ol class="at-steps" id="at-steps">
<?php foreach (ProjectTransfer::STEP_LABELS as $n => $label) { ?>
				<li data-step="<?php echo (int)$n; ?>"><?php echo $h($label); ?></li>
<?php } ?>
			</ol>
			<p class="at-dim">Runs in this request; keep the page open. It redirects to the row when done.</p>
		</div>
		<script>
		// Retry / Reverse: confirm, hide the buttons, show the progress list and
		// poll transfer_status while the form's own POST does the rewrite.
		function atWork(confirmText, title, statusUrl, startStep) {
			if (!confirm(confirmText)) return false;
			var btns = document.querySelectorAll('.at-actions button, .at-actions a');
			for (var i = 0; i < btns.length; i++) { btns[i].disabled = true; btns[i].style.pointerEvents = 'none'; btns[i].style.opacity = '0.4'; }
			document.getElementById('at-progress-title').textContent = title + '\u2026';
			document.getElementById('at-progress').style.display = '';
			atRender(startStep, 'pending', null);
			atPoll(statusUrl);
			return true;
		}
		function atRender(step, status, failedStep) {
			var items = document.querySelectorAll('#at-steps li');
			for (var i = 0; i < items.length; i++) {
				var n = i + 1, cls = '';
				if (status === 'accepted' || n <= step) cls = 'done';
				else if (status === 'failed' && failedStep === n) cls = 'failed';
				else if (n === step + 1 && status === 'pending') cls = 'active';
				items[i].className = cls;
			}
		}
		function atPoll(statusUrl) {
			setTimeout(function () {
				var xhr = new XMLHttpRequest();
				xhr.open('GET', statusUrl + '&_=' + Date.now(), true);
				xhr.onload = function () {
					var d = null;
					try { d = JSON.parse(xhr.responseText); } catch (e) {}
					if (d && d.found) {
						atRender(d.step, d.status, d.failed_step);
						if (d.status !== 'pending' && d.status !== 'failed') return;
					}
					atPoll(statusUrl);
				};
				xhr.onerror = function () { atPoll(statusUrl); };
				xhr.send();
			}, 1000);
		}
		</script>
<?php } ?>

<?php if ($verifyRes !== null) { ?>
		<h4 style="margin-top:1em;">Verify: live recount (<?php echo $h($when($verifyRes['checked'])); ?>)</h4>
		<div id="at-verify-result">
		<?php atStores($verifyRes['stores'], $verifyRes['edge_owners'], $verifyRes['clean'], $selRow->from_user_pkey, $selRow->to_user_pkey, $h); ?>
		</div>
<?php } ?>

<?php if (!empty($selSum['before']) && is_array($selSum['before'])) { ?>
		<details class="at-raw"><summary>Counts before the rewrite (step 1 snapshot)</summary>
		<?php atStores($selSum['before']['stores'] ?? array(), $selSum['before']['edge_owners'] ?? null, null, $selRow->from_user_pkey, $selRow->to_user_pkey, $h, false); ?>
		</details>
<?php } ?>
<?php if (!empty($selSum['after']) && is_array($selSum['after'])) { ?>
		<details class="at-raw"><summary>Counts after the rewrite (recorded at completion)</summary>
		<?php atStores($selSum['after']['stores'] ?? array(), $selSum['after']['edge_owners'] ?? null, $selSum['after']['clean'] ?? null, $selRow->from_user_pkey, $selRow->to_user_pkey, $h); ?>
		</details>
<?php } ?>
		<details class="at-raw"><summary>Raw summary JSON</summary>
			<pre class="at-json"><?php echo $h(json_encode($selSum, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
		</details>
	</div>
<?php } ?>

	<div class="at-panel">
		<form method="get" action="/admin_transfers" class="at-filters" id="at-filterform">
			<div>
				<label for="at-f-status">Status</label>
				<select name="status" id="at-f-status">
					<option value="">(any)</option>
<?php foreach ($STATUSES as $st) { ?>
					<option value="<?php echo $st; ?>"<?php echo $fStatus === $st ? ' selected' : ''; ?>><?php echo $st; ?></option>
<?php } ?>
				</select>
			</div>
			<div>
				<label for="at-f-email">Email (either party)</label>
				<input type="text" name="email" id="at-f-email" value="<?php echo $h($fEmail); ?>" placeholder="part of an address" autocomplete="off">
			</div>
			<div>
				<label for="at-f-project">Project id</label>
				<input type="text" name="project" id="at-f-project" value="<?php echo $h($fProject); ?>" placeholder="exact id" autocomplete="off">
			</div>
			<div>
				<button type="submit" class="at-btn">Filter</button>
				<a class="at-btn-quiet" href="/admin_transfers">Clear</a>
			</div>
		</form>
	</div>

	<p class="at-count" id="at-count"><?php
		$n = count($rows);
		if ($fStatus === '' && $fEmail === '' && $fProject === '') echo $truncated ? "Newest $n of $total transfers." : "$n transfer" . ($n === 1 ? '' : 's') . " in total.";
		else echo ($truncated ? "Newest $n matching" : "$n matching") . " (of $total).";
	?></p>
	<div class="strabotable" style="margin-left:0px;">
		<table class="at-table" id="at-list">
			<tr>
				<td>#</td>
				<td>Requested</td>
				<td>Status</td>
				<td>Project</td>
				<td>From</td>
				<td>To</td>
				<td>Keep</td>
				<td>Step</td>
				<td>Decided</td>
				<td>Notes</td>
				<td>&nbsp;</td>
			</tr>
<?php
if ($rows) {
	foreach ($rows as $r) {
		$notes = array();
		if ($r->kind === 'reversal') $notes[] = '<span class="at-kind-reversal">reversal</span>';
		if ($r->tombstone === 't') $notes[] = '<span class="at-tomb">tombstone</span>';
		if ($r->reversed_date !== null) $notes[] = 'reversed ' . $h($when($r->reversed_date, false));
		$rname = (string)$r->project_name !== '' ? $r->project_name : '(unnamed)';
?>
			<tr<?php echo (int)$r->pkey === $sel ? ' class="at-selrow"' : ''; ?> id="at-row-<?php echo (int)$r->pkey; ?>">
				<td><?php echo (int)$r->pkey; ?></td>
				<td><?php echo $h($when($r->created_date)); ?></td>
				<td><span class="at-status at-status-<?php echo $h($r->status); ?>"><?php echo $h($r->status); ?></span></td>
				<td class="at-wrap"><?php echo $h($rname); ?> <span class="at-dim">(<?php echo $h($r->strabo_project_id); ?>)</span></td>
				<td><?php echo $h($r->from_email !== null ? $r->from_email : 'user ' . (int)$r->from_user_pkey); ?></td>
				<td><?php echo $h($r->to_email !== null ? $r->to_email : 'user ' . (int)$r->to_user_pkey); ?></td>
				<td><?php echo $r->keep_as_collaborator === 't' ? 'yes' : 'no'; ?></td>
				<td><?php echo (int)$r->step; ?>/<?php echo ProjectTransfer::STEP_DONE; ?></td>
				<td><?php echo $h($when($r->decided_date)); ?></td>
				<td><?php echo implode(' ', $notes); ?></td>
				<td><a class="at-btn-quiet" href="/admin_transfers?<?php echo $h(http_build_query(array_filter(array('status' => $fStatus, 'email' => $fEmail, 'project' => $fProject, 'sel' => (int)$r->pkey)))); ?>#at-detail">Details</a></td>
			</tr>
<?php
	}
} else {
?>
			<tr><td colspan="11" id="at-empty">No transfers<?php echo ($fStatus !== '' || $fEmail !== '' || $fProject !== '') ? ' match these filters' : ' yet'; ?>.</td></tr>
<?php
}
?>
		</table>
	</div>

	</div>
</div>

<script>
(function(){
	if(window.history.replaceState && (window.location.search.indexOf('msg=') !== -1 || window.location.search.indexOf('err=') !== -1)){
		var u = new URL(window.location.href);
		u.searchParams.delete('msg'); u.searchParams.delete('err');
		window.history.replaceState(null, '', u.pathname + (u.searchParams.toString() ? '?' + u.searchParams.toString() : '') + u.hash);
	}
})();
</script>

<?php
include("includes/mfooter.php");
?>
