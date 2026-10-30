<?php
/**
 * File: micro_join_web.php
 * Description: Website side of StraboMicro join links: the addresses, the QR
 *              code (on screen as SVG, in the PDF as squares) and the owner's
 *              waiting list. The rules are in microsync/lib/MsJoin.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/micro_members_web.php';
require_once __DIR__ . '/../../microsync/lib/MsJoin.php';
require_once __DIR__ . '/../../includes/qrcode/qrcode.php';

/** Share of the QR code's width kept clear for the logo; error correction level H covers it. */
const MICRO_JOIN_LOGO_SHARE = 0.22;

/** This site's address (the dev server's own, so a phone test reaches it). */
function micro_join_site() {
	$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
	return preg_match('/^localhost(:\d+)?$/', $host) ? 'http://' . $host : MsMembers::SITE;
}

function micro_join_url($code) {
	return micro_join_site() . '/join/' . $code;
}

/** The address without https://, to read off a screen or a page. */
function micro_join_short($code) {
	return preg_replace('#^https?://#', '', micro_join_url($code));
}

/** The code's squares as rows of booleans, with the center cleared for the logo. */
function micro_join_matrix($url) {
	$qr = QRCode::getMinimumQRCode($url, QR_ERROR_CORRECT_LEVEL_H);
	$n = $qr->getModuleCount();
	$clear = (int)floor($n * MICRO_JOIN_LOGO_SHARE);
	$clear += $clear % 2 === $n % 2 ? 0 : 1;
	$from = (int)(($n - $clear) / 2);
	$rows = array();
	for ($r = 0; $r < $n; $r++) {
		$row = array();
		for ($c = 0; $c < $n; $c++) {
			$inLogo = $r >= $from && $r < $from + $clear && $c >= $from && $c < $from + $clear;
			$row[] = !$inLogo && $qr->isDark($r, $c);
		}
		$rows[] = $row;
	}
	return array('rows' => $rows, 'size' => $n, 'logoFrom' => $from, 'logoSize' => $clear);
}

/** The code as an SVG with a white border and the Strabo logo in the middle. */
function micro_join_svg($url, $label) {
	$m = micro_join_matrix($url);
	$border = 4;
	$total = $m['size'] + 2 * $border;
	$path = '';
	foreach ($m['rows'] as $r => $row) {
		foreach ($row as $c => $dark) {
			if ($dark) {
				$path .= 'M' . ($c + $border) . ' ' . ($r + $border) . 'h1v1h-1z';
			}
		}
	}
	$inset = 0.5;
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" role="img" aria-label="' . htmlspecialchars($label) . '" shape-rendering="crispEdges">'
		. '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/><path d="' . $path . '" fill="#000"/>'
		. '<image href="/includes/images/strabo_icon_web.png" x="' . ($m['logoFrom'] + $border + $inset) . '" y="' . ($m['logoFrom'] + $border + $inset) . '" width="' . ($m['logoSize'] - 2 * $inset) . '" height="' . ($m['logoSize'] - 2 * $inset) . '"/></svg>';
}

/** The owner's waiting list; also what the page reloads while it stays open. */
function micro_join_waiting_html($msdb, $pid, $csrf) {
	$waiting = MsJoin::waiting($msdb, $pid);
	$fields = '<input type="hidden" name="pid" value="' . (int)$pid . '"><input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">';
	ob_start();
	if (count($waiting) === 0) {
		echo '<p class="collabHelp">Nobody is waiting. People who ask to join appear here without reloading the page.</p>';
	} else {
?>
	<div class="collabWaitHead">
		<h4 style="margin:0;">Waiting for you (<?php echo count($waiting)?>)</h4>
		<form method="post" action="/micro_collaborators_action" class="collabForm"><?php echo $fields?><input type="hidden" name="action" value="approve_all"><button type="submit" class="button primary small">Approve All</button></form>
	</div>
	<div class="table-wrapper">
		<table class="myDataTable">
			<thead><tr><th>Name</th><th>Email</th><th>Joins as</th><th></th></tr></thead>
			<tbody>
<?php foreach ($waiting as $w) { ?>
				<tr>
					<td><?php echo htmlspecialchars(trim($w['firstname'] . ' ' . $w['lastname']))?></td>
					<td><?php echo htmlspecialchars($w['email'])?></td>
					<td><?php echo htmlspecialchars(micro_role_label($w['role']))?></td>
					<td>
						<div class="collabActions">
							<form method="post" action="/micro_collaborators_action" class="collabForm"><?php echo $fields?><input type="hidden" name="action" value="approve"><input type="hidden" name="pkey" value="<?php echo (int)$w['user_pkey']?>"><button type="submit" class="button primary small">Approve</button></form>
							<form method="post" action="/micro_collaborators_action" class="collabForm"><?php echo $fields?><input type="hidden" name="action" value="deny"><input type="hidden" name="pkey" value="<?php echo (int)$w['user_pkey']?>"><button type="submit" class="button small">Deny</button></form>
						</div>
					</td>
				</tr>
<?php } ?>
			</tbody>
		</table>
	</div>
<?php
	}
	return ob_get_clean();
}
