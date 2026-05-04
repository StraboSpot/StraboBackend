<?php
/**
 * File: publications_admin.php
 * Description: Admin page for managing the publications CSV (download / upload full replacement)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
$userpkey = (int)$_SESSION['userpkey'];
include("prepare_connections.php");

if(!in_array($userpkey, $admin_pkeys)){
	header("Location: /");
	exit();
}

$csvFile = __DIR__ . '/data/publications.csv';
$bakFile = __DIR__ . '/data/publications.csv.bak';
$expectedHeaders = array('Authors','Title','Publication','Volume','Number','Pages','Year','Publisher','URL','DOI');

$status = null;     // ['type' => 'success'|'error', 'msg' => '...']

// ---------------------------------------------------------------------------
// Download current CSV
// ---------------------------------------------------------------------------

if(($_GET['action'] ?? '') === 'download'){
	if(!file_exists($csvFile)){
		http_response_code(404);
		echo "No publications CSV found.";
		exit();
	}
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="publications.csv"');
	header('Content-Length: ' . filesize($csvFile));
	readfile($csvFile);
	exit();
}

// ---------------------------------------------------------------------------
// Upload handler
// ---------------------------------------------------------------------------

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['publications_csv'])){
	$file = $_FILES['publications_csv'];

	if($file['error'] !== UPLOAD_ERR_OK){
		$status = array('type' => 'error', 'msg' => 'Upload failed (PHP error code ' . $file['error'] . ').');
	} elseif($file['size'] > 10 * 1024 * 1024){
		$status = array('type' => 'error', 'msg' => 'File must be under 10 MB.');
	} elseif(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv'){
		$status = array('type' => 'error', 'msg' => 'Please upload a .csv file.');
	} else {
		// Read, normalize (strip BOM, CRLF/CR → LF), write to temp file for fgetcsv
		$raw = file_get_contents($file['tmp_name']);
		if($raw === false || $raw === ''){
			$status = array('type' => 'error', 'msg' => 'Could not read uploaded file.');
		} else {
			if(substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
			$raw = str_replace(array("\r\n", "\r"), "\n", $raw);

			$tmp = tempnam(sys_get_temp_dir(), 'pub_upload_');
			file_put_contents($tmp, $raw);

			$in = fopen($tmp, 'r');
			$uploadedHeaders = $in ? fgetcsv($in) : false;

			if(!$uploadedHeaders){
				$status = array('type' => 'error', 'msg' => 'Could not parse CSV header row.');
				if($in) fclose($in);
			} else {
				// Build case-insensitive map: lowercase trimmed header → column index
				$headerMap = array();
				foreach($uploadedHeaders as $idx => $h){
					$key = strtolower(trim((string)$h));
					if($key !== '' && !isset($headerMap[$key])) $headerMap[$key] = $idx;
				}

				// Verify all expected columns are present
				$missing = array();
				foreach($expectedHeaders as $h){
					if(!isset($headerMap[strtolower($h)])) $missing[] = $h;
				}

				if(!empty($missing)){
					$status = array('type' => 'error', 'msg' => 'Missing required column(s): ' . implode(', ', $missing) . '. Required columns are: ' . implode(', ', $expectedHeaders) . '.');
					fclose($in);
				} else {
					// Re-emit rows in canonical column order to a fresh temp file
					$canonicalTmp = tempnam(sys_get_temp_dir(), 'pub_canon_');
					$out = fopen($canonicalTmp, 'w');
					fputcsv($out, $expectedHeaders);

					$rowCount = 0;
					while(($row = fgetcsv($in)) !== false){
						// Skip wholly-empty rows
						$nonEmpty = false;
						foreach($row as $v){ if(trim((string)$v) !== ''){ $nonEmpty = true; break; } }
						if(!$nonEmpty) continue;

						$canonRow = array();
						foreach($expectedHeaders as $h){
							$idx = $headerMap[strtolower($h)];
							$canonRow[] = isset($row[$idx]) ? (string)$row[$idx] : '';
						}
						fputcsv($out, $canonRow);
						$rowCount++;
					}
					fclose($in);
					fclose($out);

					if($rowCount === 0){
						$status = array('type' => 'error', 'msg' => 'No data rows found in uploaded CSV.');
						unlink($canonicalTmp);
					} else {
						// Backup previous, then move new file into place
						if(file_exists($csvFile)){
							@copy($csvFile, $bakFile);
						}
						if(!rename($canonicalTmp, $csvFile)){
							// Fallback: copy + unlink
							if(copy($canonicalTmp, $csvFile)){
								unlink($canonicalTmp);
							} else {
								$status = array('type' => 'error', 'msg' => 'Could not save publications file. Check filesystem permissions on /data/.');
							}
						}
						if($status === null){
							@chmod($csvFile, 0664);
							$status = array('type' => 'success', 'msg' => 'Upload successful. ' . $rowCount . ' publication record' . ($rowCount === 1 ? '' : 's') . ' loaded. Previous file saved as publications.csv.bak.');
						}
					}
				}
			}

			@unlink($tmp);
		}
	}
}

// ---------------------------------------------------------------------------
// Page rendering — read current CSV state for summary
// ---------------------------------------------------------------------------

$currentCount = 0;
$lastUpdated = null;
if(file_exists($csvFile)){
	$lastUpdated = date('M j, Y g:i a', filemtime($csvFile));
	$fh = fopen($csvFile, 'r');
	if($fh){
		fgetcsv($fh); // skip header
		while(fgetcsv($fh) !== false) $currentCount++;
		fclose($fh);
	}
}

include("includes/mheader.php");
?>

<style>
	.form-card {
		background: rgba(255, 255, 255, 0.04);
		border: 1px solid rgba(255, 255, 255, 0.1);
		border-radius: 6px;
		padding: 2em;
		margin-bottom: 1.5em;
	}
	.form-section-title {
		color: #fff;
		font-size: 1.1em;
		font-weight: 600;
		margin-bottom: 1em;
		text-transform: uppercase;
		letter-spacing: 0.05em;
	}
	.summary-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
		gap: 1em;
		margin-bottom: 1em;
	}
	.summary-item {
		background: rgba(255, 255, 255, 0.04);
		border: 1px solid rgba(255, 255, 255, 0.1);
		border-radius: 4px;
		padding: 1em;
	}
	.summary-label {
		color: rgba(255, 255, 255, 0.6);
		font-size: 0.85em;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		margin-bottom: 0.3em;
	}
	.summary-value {
		color: #ffffff;
		font-size: 1.4em;
		font-weight: 700;
	}
	.upload-field input[type="file"] {
		width: 100%;
		background: rgba(255, 255, 255, 0.08);
		border: 1px solid rgba(255, 255, 255, 0.2);
		border-radius: 4px;
		color: #ffffff;
		padding: 0.6em 0.8em;
		font-size: 1em;
		box-sizing: border-box;
	}
	.upload-help {
		color: rgba(255, 255, 255, 0.6);
		font-size: 0.9em;
		line-height: 1.55;
		margin-top: 1em;
	}
	.upload-help code {
		background: rgba(255, 255, 255, 0.08);
		padding: 0.1em 0.4em;
		border-radius: 3px;
		font-size: 0.95em;
	}
	.status-msg {
		border-radius: 4px;
		padding: 1em;
		margin-bottom: 1.5em;
		font-size: 1.05em;
	}
	.status-msg.success {
		background: rgba(56, 118, 29, 0.15);
		border: 1px solid #38761d;
		color: #8bc34a;
	}
	.status-msg.error {
		background: rgba(228, 76, 101, 0.15);
		border: 1px solid #e44c65;
		color: #e44c65;
	}
</style>

<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Publications Admin</h2>
		</header>

		<div style="margin-bottom: 1.5em;">
			<a href="/publications" style="color: #e44c65;">&larr; View Publications Page</a>
		</div>

		<?php if($status): ?>
		<div class="status-msg <?php echo htmlspecialchars($status['type']); ?>">
			<?php echo htmlspecialchars($status['msg']); ?>
		</div>
		<?php endif; ?>

		<!-- Summary -->
		<div class="form-card">
			<h3 class="form-section-title">Current Publications File</h3>
			<div class="summary-grid">
				<div class="summary-item">
					<div class="summary-label">Records</div>
					<div class="summary-value"><?php echo (int)$currentCount; ?></div>
				</div>
				<div class="summary-item">
					<div class="summary-label">Last Updated</div>
					<div class="summary-value" style="font-size: 1.05em;"><?php echo htmlspecialchars($lastUpdated ?: 'Never'); ?></div>
				</div>
			</div>
			<a href="/publications_admin?action=download" class="button primary small" style="margin-top: 0.5em;">Download Current CSV</a>
		</div>

		<!-- Upload -->
		<div class="form-card">
			<h3 class="form-section-title">Upload Replacement CSV</h3>
			<form method="post" enctype="multipart/form-data" onsubmit="return confirmUpload();">
				<div class="upload-field" style="margin-bottom: 1em;">
					<input type="file" name="publications_csv" accept=".csv,text/csv" required>
				</div>
				<button type="submit" class="button primary small">Upload &amp; Replace</button>
			</form>
			<div class="upload-help">
				<p style="margin-bottom: 0.6em;"><strong>How this works:</strong></p>
				<ul style="margin-left: 1.2em; line-height: 1.7;">
					<li>Uploading a new CSV <strong>fully replaces</strong> the current publications list.</li>
					<li>The previous file is automatically saved as <code>publications.csv.bak</code> (one rolling backup).</li>
					<li>Required columns (any order, case-insensitive): <code><?php echo implode('</code>, <code>', $expectedHeaders); ?></code>.</li>
					<li>Extra columns are ignored. Empty rows are skipped.</li>
					<li>If both <code>DOI</code> and <code>URL</code> are provided, <code>DOI</code> takes precedence on the public page.</li>
					<li>Excel/Numbers/Sheets exports are all accepted &mdash; line endings and BOM are normalized on save.</li>
				</ul>
			</div>
		</div>

		<div class="bottomSpacer"></div>

	</div>
</div>

<script>
function confirmUpload(){
	return confirm('This will replace the current publications list with the uploaded file. The current file will be backed up as publications.csv.bak. Continue?');
}
</script>

<?php
include("includes/mfooter.php");
?>
