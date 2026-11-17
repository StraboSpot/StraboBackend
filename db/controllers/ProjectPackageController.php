<?php
/**
 * File: ProjectPackageController.php
 * Description: Projects prepared for an account on the web, for the app.
 *              GET /db/profile lists them under "prepared_projects"
 *              (ProfileController), each with an id. Today the only kind is
 *              a field camp account's camp package (Field Camp Wizard); the
 *              API is generic so a later wizard for single users needs no
 *              second app change (2026-11-17).
 *
 *                GET  /db/projectPackage                  {"prepared_projects": [...]}
 *                GET  /db/projectPackage/{id}             the zip (the same file the
 *                     wizard downloads; the app's own "import project from
 *                     zip" reads it). Content-Length, ETag = version, single
 *                     byte ranges, so a large download can go on where it stopped.
 *                HEAD /db/projectPackage/{id}             the headers only
 *                POST /db/projectPackage/{id}/installed   {"version": "..."}: the
 *                     app loaded that version; the profile reports it back
 *                     as installed_version.
 *
 *              Only the account's own packages, never another one's.
 *              FieldCampPackageController keeps the first address
 *              (/db/fieldCampPackage) working. Spec for the app developers:
 *              docs/Field_Camp_Builder/APP_PACKAGE_API.md.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class ProjectPackageController extends MyController
{
	/** Scheme and host the request came to ("https://strabospot.org"). */
	public static function serverBase()
	{
		$host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'strabospot.org'));
		$host = preg_replace('/[^A-Za-z0-9.:\-]/', '', explode(',', $host)[0]);
		$https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		// behind the production proxy the request reaches PHP as http: only local hosts are offered as http
		$local = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
		return (($https || !$local) ? 'https' : 'http') . '://' . $host;
	}

	/** The package the address names: [account, camp] or null. ($request->url_elements[2] = the id.) */
	protected function load($request)
	{
		require_once __DIR__ . '/../../includes/fieldcamp/FieldCampPackage.php';
		return FieldCampPackage::packageFor($this->strabo->db, $this->strabo->userpkey, (string)($request->url_elements[2] ?? ''));
	}

	/** Where "installed" sits in the address. */
	protected function installedAt() { return 3; }

	protected function fail($code, $text, $message, $extra = array())
	{
		header($text, true, $code);
		return array('Error' => $message) + $extra;
	}

	public function getAction($request) {
		if (static::class === self::class && (string)($request->url_elements[2] ?? '') === '') {
			require_once __DIR__ . '/../../includes/fieldcamp/FieldCampPackage.php';
			return array('prepared_projects' => FieldCampPackage::preparedProjects($this->strabo->db, $this->strabo->userpkey, self::serverBase()));
		}
		return $this->send($request, true);
	}
	public function headAction($request) { return $this->send($request, false); }

	protected function send($request, $withBody)
	{
		$pair = $this->load($request);
		if (!$pair) return $this->fail(404, "Not Found", "There is no such prepared project for this account.");
		list($account, $camp) = $pair;
		$db = $this->strabo->db;
		$status = FieldCampPackage::offerStatus($db, $camp);
		if ($status === 'building') {
			header('Retry-After: 30');
			return $this->fail(409, "Conflict", "The offline maps of this project are being built. Try again in a little while.", array('status' => 'building'));
		}
		if ($status !== 'ready') return $this->fail(404, "Not Found", "The project package is not ready yet.", array('status' => $status));

		set_time_limit(0);
		$zip = FieldCampPackage::cachedZip($db, $camp);
		if ($zip['error'] !== '') return $this->fail(500, "Internal Server Error", $zip['error']);

		$size = $zip['bytes']; $start = 0; $end = $size - 1; $code = 200;
		$etag = '"' . $zip['version'] . '"';
		$range = isset($_SERVER['HTTP_RANGE']) ? trim((string)$_SERVER['HTTP_RANGE']) : '';
		// A range is honoured only for the version it was started on (If-Range), else the whole file is sent again.
		$ifRange = isset($_SERVER['HTTP_IF_RANGE']) ? trim((string)$_SERVER['HTTP_IF_RANGE']) : '';
		if ($range !== '' && ($ifRange === '' || $ifRange === $etag) && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
			if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
			else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($size - 1, (int)$m[2]); }
			if ($start > $end || $start >= $size) {
				if ($zip['temp']) @unlink($zip['path']);
				header("Range Not Satisfiable", true, 416);
				header("Content-Range: bytes */$size");
				exit();
			}
			$code = 206;
		}
		while (ob_get_level() > 0) ob_end_clean();
		header($code === 206 ? "Partial Content" : "OK", true, $code);
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . str_replace(array('"', "\r", "\n"), '', $zip['name']) . '"');
		header('Content-Length: ' . ($end - $start + 1));
		header('Accept-Ranges: bytes');
		header('ETag: ' . $etag);
		header('X-Package-Version: ' . $zip['version']);
		header('Cache-Control: private, no-store');
		if ($code === 206) header("Content-Range: bytes $start-$end/$size");
		if ($withBody) {
			$fh = fopen($zip['path'], 'rb');
			fseek($fh, $start);
			$left = $end - $start + 1;
			while ($left > 0 && !feof($fh)) {
				$chunk = fread($fh, min(1048576, $left));
				if ($chunk === false || $chunk === '') break;
				echo $chunk; $left -= strlen($chunk);
				flush();
			}
			fclose($fh);
		}
		if ($zip['temp']) @unlink($zip['path']);
		exit();
	}

	public function postAction($request) {
		$pair = $this->load($request);
		if (!$pair) return $this->fail(404, "Not Found", "There is no such prepared project for this account.");
		if ((string)($request->url_elements[$this->installedAt()] ?? '') !== 'installed' || isset($request->url_elements[$this->installedAt() + 1])) {
			return $this->fail(400, "Bad Request", "Bad Request.");
		}
		$version = strtolower(trim((string)($request->parameters['version'] ?? '')));
		if (!FieldCampPackage::recordInstall($this->strabo->db, $this->strabo->userpkey, $version)) {
			return $this->fail(400, "Bad Request", "Send the version of the package that was loaded, as {\"version\": \"...\"}.");
		}
		return array('installed_version' => $version);
	}

	public function deleteAction($request) { return $this->fail(400, "Bad Request", "Bad Request."); }
	public function putAction($request) { return $this->fail(400, "Bad Request", "Bad Request."); }
	public function optionsAction($request) { return $this->fail(400, "Bad Request", "Bad Request."); }
	public function copyAction($request) { return $this->fail(400, "Bad Request", "Bad Request."); }
}
