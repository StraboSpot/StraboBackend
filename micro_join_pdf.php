<?php
/**
 * File: micro_join_pdf.php
 * Description: The owner's printable page for a join link (POST: pid, csrf,
 *              title, details): always one page with the Strabo header, the
 *              owner's Title and Details, the QR code, the short address and
 *              how to join.
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
require_once(__DIR__ . '/includes/tfpdf/tfpdf.php');

$pid = isset($_POST['pid']) ? (int)$_POST['pid'] : 0;
$back = $pid > 0 ? '/micro_collaborators?project_id=' . $pid : '/my_micro_data';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !micro_collab_token_ok(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
	header('Location: ' . $back);
	exit();
}

$msdb = micro_members_db($db);
try {
	$saved = MsJoin::savePdfFields($msdb, (int)$userpkey, $pid, isset($_POST['title']) ? $_POST['title'] : '', isset($_POST['details']) ? $_POST['details'] : '');
	$p = MsStore::project($msdb, $pid, (int)$userpkey);
	$link = MsJoin::linkOf($msdb, $pid);
	if ($link === null) {
		throw new MsHttpError(404, 'not_found', 'This project has no join link.');
	}
} catch (MsHttpError $e) {
	$_SESSION['micro_collab_result'] = array('pid' => $pid, 'ok' => false, 'text' => $e->getMessage());
	header('Location: ' . $back);
	exit();
}

/** Lines the text takes at the current font in this width, new lines kept. */
function join_pdf_lines($pdf, $text, $width) {
	$lines = array();
	foreach (explode("\n", $text) as $para) {
		$line = '';
		foreach (preg_split('/ +/u', $para) as $word) {
			$try = $line === '' ? $word : "$line $word";
			if ($line !== '' && $pdf->GetStringWidth($try) > $width) {
				$lines[] = $line;
				$line = $word;
			} else {
				$line = $try;
			}
		}
		$lines[] = $line;
	}
	return $lines;
}

$pageW = 215.9;
$margin = 20;
$textW = $pageW - 2 * $margin;
$pdf = new tFPDF('P', 'mm', 'Letter');
$pdf->SetAutoPageBreak(false);
$pdf->SetTitle('Join a StraboMicro project', true);
$pdf->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
$pdf->AddFont('DejaVu', 'B', 'DejaVuSansCondensed-Bold.ttf', true);
$pdf->AddPage();

// Strabo header
$pdf->SetFillColor(27, 29, 38);
$pdf->Rect(0, 0, $pageW, 28, 'F');
$pdf->Image(__DIR__ . '/includes/images/strabo_icon_web.png', $margin, 5, 18, 18);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('DejaVu', 'B', 20);
$pdf->SetXY($margin + 22, 9);
$pdf->Cell(90, 10, 'StraboMicro');
$pdf->SetFont('DejaVu', '', 11);
$pdf->SetXY($pageW - $margin - 60, 9);
$pdf->Cell(60, 10, 'strabospot.org', 0, 0, 'R');

// Title and Details; what would run off the page is cut, so it stays one page
$pdf->SetTextColor(27, 29, 38);
$y = 36;
$title = $saved['title'] !== '' ? $saved['title'] : 'Join this StraboMicro project';
$pdf->SetFont('DejaVu', 'B', 22);
$titleLines = array_slice(join_pdf_lines($pdf, $title, $textW), 0, 2);
foreach ($titleLines as $line) {
	$pdf->SetXY($margin, $y);
	$pdf->Cell($textW, 10, $line, 0, 0, 'C');
	$y += 10;
}
$y += 2;
if ($saved['details'] !== '') {
	$maxH = 42;
	for ($size = 12; $size >= 8; $size--) {
		$pdf->SetFont('DejaVu', '', $size);
		$lineH = $size * 0.47;
		$lines = join_pdf_lines($pdf, $saved['details'], $textW);
		if (count($lines) * $lineH <= $maxH) {
			break;
		}
	}
	$lines = array_slice($lines, 0, (int)floor($maxH / $lineH));
	foreach ($lines as $line) {
		$pdf->SetXY($margin, $y);
		$pdf->Cell($textW, $lineH, $line, 0, 0, 'C');
		$y += $lineH;
	}
	$y += 3;
}

// QR code: as large as the page leaves room for, to scan from across a room
$url = micro_join_url($link['code']);
$m = micro_join_matrix($url);
$qrW = min(145, 262 - 38 - $y);
$cell = $qrW / $m['size'];
$qrX = ($pageW - $qrW) / 2;
$pdf->SetFillColor(0, 0, 0);
foreach ($m['rows'] as $r => $row) {
	foreach ($row as $c => $dark) {
		if ($dark) {
			$pdf->Rect($qrX + $c * $cell, $y + $r * $cell, $cell, $cell, 'F');
		}
	}
}
$inset = 0.5 * $cell;
$pdf->Image(__DIR__ . '/includes/images/strabo_icon_web.png', $qrX + $m['logoFrom'] * $cell + $inset, $y + $m['logoFrom'] * $cell + $inset,
	$m['logoSize'] * $cell - 2 * $inset, $m['logoSize'] * $cell - 2 * $inset);
$y += $qrW + 4;

$pdf->SetTextColor(27, 29, 38);
$pdf->SetFont('DejaVu', '', 11);
$pdf->SetXY($margin, $y);
$pdf->Cell($textW, 6, 'Scan with your phone, or go to:', 0, 0, 'C');
$y += 6;
$pdf->SetFont('DejaVu', 'B', 15);
$pdf->SetXY($margin, $y);
$pdf->Cell($textW, 8, micro_join_short($link['code']), 0, 0, 'C');
$y += 11;

$pdf->SetFont('DejaVu', '', 10);
foreach (array(
	'1. Log in with your StraboSpot account, or create a free one, and click Ask to Join.',
	'2. Wait for the project owner to approve you.',
	'3. Open StraboMicro, log in, and choose File > Open Remote Project.',
) as $step) {
	$pdf->SetXY($margin, $y);
	$pdf->Cell($textW, 5.2, $step, 0, 0, 'C');
	$y += 5.2;
}

$pdf->SetTextColor(110, 110, 120);
$pdf->SetFont('DejaVu', '', 9);
$name = mb_substr(MsMembers::projectName($msdb, $p), 0, 70, 'UTF-8');
$pdf->SetXY($margin, 266);
$pdf->Cell($textW, 5, 'Project: ' . $name . '   |   Link expires ' . date('F j, Y', strtotime($link['expires_on'])), 0, 0, 'C');

$pdf->Output('D', 'StraboMicro-Join-' . $link['code'] . '.pdf');
