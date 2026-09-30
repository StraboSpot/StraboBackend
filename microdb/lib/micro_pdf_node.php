<?php
/**
 * File: microdb/lib/micro_pdf_node.php
 * Description: Project PDFs for StraboMicro2-format projects, rendered by the
 *              strabo-node container (pdfservice/) with the desktop app's own
 *              PDF code, so server PDFs match the ones the app makes (with
 *              micrograph images, overlays and spots).
 *
 *              JavaFX-format projects keep the tFPDF generator
 *              (MicroProjectPDF): their folders hold only images/<id>.jpg
 *              composites, which the app's renderer cannot use.
 *
 *              Two callers:
 *                - micro_regenerate_pdf_if_dirty (download paths): waits up
 *                  to MICRO_PDF_NODE_WAIT seconds, else serves the old PDF
 *                - the microsync worker sweep (cron, every minute): renders
 *                  every dirty StraboMicro2-format project in the background
 *
 *              pdf_dirty is cleared BEFORE rendering (atomically, so only one
 *              caller renders a project at a time) and set again when the
 *              render fails or times out. An edit that lands during a render
 *              sets it again too, so the next sweep picks that up.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/sample_overlay.php';

// Seconds a download waits for a render before serving the existing PDF.
if (!defined('MICRO_PDF_NODE_WAIT')) define('MICRO_PDF_NODE_WAIT', 45);

/** Base URL of the strabo-node service on the Docker network. */
function micro_pdf_node_url() {
	return defined('STRABO_NODE_URL') ? STRABO_NODE_URL : 'http://strabo-node:3000';
}

function micro_pdf_files_root() {
	$docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : dirname(dirname(__DIR__));
	return "$docRoot/straboMicroFiles";
}

/**
 * True when the project is in StraboMicro2 format: synced (entity) projects
 * always are; legacy ones are when images/ holds an original stored under the
 * bare micrograph id (JavaFX uploads only have images/<id>.jpg composites).
 */
function micro_pdf_uses_node($db, $projectInternalId) {
	$pid = (int)$projectInternalId;
	$format = $db->get_var_prepared(
		"SELECT sync_format FROM strabomicro.micro_projectmetadata WHERE id = $1",
		array($pid)
	);
	if ($format === 'entity') return true;

	$images = micro_pdf_files_root() . "/$pid/images";
	foreach ((array)@scandir($images) as $f) {
		if ($f === '.' || $f === '..' || strpos($f, '.') !== false) continue;
		if (@filesize("$images/$f") > 0) return true;
	}
	return false;
}

/**
 * Render straboMicroFiles/<id>/project.pdf with strabo-node when the project's
 * pdf_dirty flag is set. Refreshes the on-disk project.json first (the
 * renderer reads it, and a Samples-app edit dirties both).
 *
 * @param object $db                StraboDbPostgreSQL handle
 * @param int    $projectInternalId micro_projectmetadata.id
 * @param int    $ownerPkey         project owner pkey
 * @param int    $waitSeconds       how long to wait for the render
 * @return string 'clean' (flag not set), 'rendered', or 'failed: <reason>'
 */
function micro_pdf_render_node($db, $projectInternalId, $ownerPkey, $waitSeconds) {
	$pid = (int)$projectInternalId;

	micro_regenerate_files_if_dirty($db, $pid, (int)$ownerPkey);

	// The wrapper returns the affected row count for an UPDATE: 0 means the
	// flag was not set, or another caller claimed this render first.
	$claimed = $db->prepare_query(
		"UPDATE strabomicro.micro_projectmetadata SET pdf_dirty = FALSE
		  WHERE id = $1 AND pdf_dirty",
		array($pid)
	);
	if ((int)$claimed === 0) return 'clean';

	$result = micro_pdf_node_request($pid, 'project.pdf', $waitSeconds);
	if ($result !== 'rendered') {
		$db->prepare_query(
			"UPDATE strabomicro.micro_projectmetadata SET pdf_dirty = TRUE WHERE id = $1",
			array($pid)
		);
	}
	return $result;
}

/**
 * POST /pdf to strabo-node. Returns 'rendered' or 'failed: <reason>'.
 */
function micro_pdf_node_request($pid, $outName, $waitSeconds) {
	$ch = curl_init(micro_pdf_node_url() . '/pdf');
	curl_setopt_array($ch, array(
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => json_encode(array('project' => (int)$pid, 'out' => $outName)),
		CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 3,
		CURLOPT_TIMEOUT => max(1, (int)$waitSeconds),
	));
	$body = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$error = curl_error($ch);
	curl_close($ch);

	if ($body === false) return "failed: $error";
	$r = json_decode($body);
	if ($status === 200 && $r && !empty($r->ok)) return 'rendered';
	return "failed: HTTP $status " . ($r && isset($r->error) ? $r->error : substr((string)$body, 0, 200));
}
