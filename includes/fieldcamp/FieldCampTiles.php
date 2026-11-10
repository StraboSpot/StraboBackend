<?php
/**
 * File: includes/fieldcamp/FieldCampTiles.php
 * Description: Field Camp Wizard, stage 6: offline map tiles for a camp's
 *              project package.
 *
 *              The instructor picks the maps (the four default basemaps and
 *              the camp's custom maps) and the deepest zoom; options() gives,
 *              per zoom, the map scale, the number of tiles and the
 *              approximate size, the way the app's own "save offline map"
 *              dialog lets a user find the sweet spot.
 *
 *              The tiles come from our tile server exactly as the app gets
 *              them (StraboTiles: asynczip, asyncstatus, ziptemp), one request
 *              per map: every tile of the study area at the chosen zoom plus
 *              the tiles above them up to zoom 0. tileCount() is the tile
 *              server's own count (StraboTileClass::tileCounts), so the
 *              numbers shown are the numbers built.
 *
 *              build() runs from the command line (build_tiles.php, started
 *              in the background by startBuild()) and leaves, in
 *              fieldcamp_files/<token>/:
 *                OfflineTiles.zip  <map id>/tiles/<z>_<x>_<y>.png, the layout
 *                                  of the app's tile cache and of the
 *                                  maps/OfflineTiles.zip in its backups
 *                tiles.json        what was built (maps, counts, zoom, area)
 *              FieldCampPackage puts the zip into the package and writes the
 *              mapNamesDb entries from tiles.json.
 *
 *              PROVISIONAL limits (Jason's boss has not set them yet):
 *              MAX_ZOOM, MAX_TILES_PER_MAP, MAX_TILES_TOTAL.
 *
 *              Tables: sql/fieldcamp.sql. Design: docs/Field_Camp_Builder/DESIGN.md.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/FieldCampUnits.php';

class FieldCampTiles
{
	/** Deepest zoom offered. Provisional. */
	const MAX_ZOOM = 16;

	/** How many zoom choices the step lists (the app's dialog lists up to six). */
	const ZOOM_CHOICES = 6;

	/** Most tiles one map may hold, and all maps together. Provisional. */
	const MAX_TILES_PER_MAP = 6000;
	const MAX_TILES_TOTAL = 25000;

	/** The default zoom is the deepest one that keeps each map at or under this many tiles. */
	const DEFAULT_TILES_PER_MAP = 1500;

	/** A build that has not finished after this many minutes is taken as dead. */
	const STALE_MINUTES = 45;

	/** Longest wait for the tile server to gather one map, in seconds. */
	const LAYER_WAIT = 900;

	/**
	 * The default basemaps: tile server layer id => [name in the app, average
	 * kB per tile]. The averages come from a backup made by the app and a test
	 * build in 11-2026 and only feed the size estimate.
	 */
	const BASEMAPS = array(
		'mapbox.outdoors' => array('Mapbox Topo', 16),
		'mapbox.satellite' => array('Mapbox Satellite', 23),
		'macrostrat' => array('Geology from macrostrat', 10),
		'usgs.hillshade' => array('USGS Hillshade', 37),
	);
	const CUSTOM_MAP_KB = 12;

	/**
	 * Tests replace the HTTP calls: a callable taking (url, file to save to or
	 * null) and returning array(http code, body). Null = the real request.
	 */
	public static $http = null;

	/** Seconds between two status polls (tests set 0). */
	public static $pollSeconds = 2;

	private static $columnsChecked = null;

	/** True once the stage 6 part of sql/fieldcamp.sql has been applied. */
	public static function installed($db)
	{
		if (self::$columnsChecked === null) {
			$n = (int)$db->get_var_prepared("select count(*) from information_schema.columns
				where table_name = 'fieldcamp' and column_name = 'files_token'", array());
			self::$columnsChecked = ($n > 0);
		}
		return self::$columnsChecked;
	}

	/** The tile server. $fieldcamp_tile_host in config.inc.php overrides it. */
	public static function tileHost()
	{
		global $fieldcamp_tile_host;
		$host = (isset($fieldcamp_tile_host) && is_string($fieldcamp_tile_host) && $fieldcamp_tile_host !== '')
			? $fieldcamp_tile_host : 'https://tiles.strabospot.org';
		return rtrim($host, '/');
	}

	// -------------------------------------------------------------------
	// Counting
	// -------------------------------------------------------------------

	/**
	 * How many tiles one map takes for an area and a deepest zoom: the tiles
	 * of the area at that zoom plus the tiles above them up to zoom 0. Same
	 * arithmetic as the tile server (StraboTileClass::tileCounts and
	 * countZip), so this is the number it will deliver.
	 */
	public static function tileCount($bbox, $zoom)
	{
		$zoom = (int)$zoom;
		$west = (float)$bbox['west']; $south = (float)$bbox['south'];
		$east = (float)$bbox['east']; $north = (float)$bbox['north'];
		if ($west == -180) $west = -179.9;
		if ($south == -90) $south = -89.9;
		if ($east == 180) $east = 179.9;
		if ($north == 90) $north = 89.9;

		$xa = self::lonToTile($west, $zoom); $xb = self::lonToTile($east, $zoom);
		$ya = self::latToTile($south, $zoom); $yb = self::latToTile($north, $zoom);
		$x0 = min($xa, $xb); $x1 = max($xa, $xb);
		$y0 = max(min($ya, $yb), 0); $y1 = max($ya, $yb);

		$atZoom = ($x1 - $x0 + 1) * ($y1 - $y0 + 1);
		$above = 1;   // 0/0/0
		for ($level = 1; $level <= $zoom - 1; $level++) {
			$shift = $zoom - $level;
			$last = (1 << $level) - 1;
			$above += (min($x1 >> $shift, $last) - min($x0 >> $shift, $last) + 1)
				* (min($y1 >> $shift, $last) - min($y0 >> $shift, $last) + 1);
		}
		return $above + ($zoom > 0 ? $atZoom : 0);
	}

	private static function lonToTile($lon, $zoom)
	{
		return (int)floor(($lon + 180) / 360 * pow(2, $zoom));
	}

	private static function latToTile($lat, $zoom)
	{
		$y = (int)floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) / 2 * (1 << $zoom));
		return max(0, min($y, (1 << $zoom) - 1));
	}

	/**
	 * The map scale (the N of 1:N) at a latitude and zoom, as the app's dialog
	 * states it (scale.js calculateScaleRatio: 256 px tiles on a 96 dpi screen).
	 */
	public static function scale($lat, $zoom)
	{
		$metersPerPixel = 40075017 * cos(deg2rad($lat)) / (256 * pow(2, $zoom));
		return (int)round($metersPerPixel * 96 / 0.0254);
	}

	// -------------------------------------------------------------------
	// The step: maps and zoom
	// -------------------------------------------------------------------

	/**
	 * The maps a camp can carry offline: the default basemaps, then the camp's
	 * custom maps.
	 *
	 * @param array $customMaps custom map objects (FieldCampPackage::customMapsForCamp)
	 * @return array id => ['id', 'name', 'custom' (bool), 'kb', 'bbox']
	 */
	public static function layers($customMaps)
	{
		$out = array();
		foreach (self::BASEMAPS as $id => $b) {
			$out[$id] = array('id' => $id, 'name' => $b[0], 'custom' => false, 'kb' => $b[1], 'bbox' => '');
		}
		foreach ((array)$customMaps as $m) {
			$out[$m['id']] = array('id' => $m['id'], 'name' => $m['title'], 'custom' => true,
				'kb' => self::CUSTOM_MAP_KB, 'bbox' => (string)($m['bbox'] ?? ''));
		}
		return $out;
	}

	/**
	 * The ids of the maps selected for offline use: what was saved (as far as
	 * those maps still exist), or every map when nothing was saved yet.
	 */
	public static function chosenLayerIds($camp, $layers)
	{
		$saved = json_decode((string)($camp->tiles_layers ?? ''), true);
		if (!is_array($saved)) return array_keys($layers);
		$out = array();
		foreach ($saved as $id) { if (is_string($id) && isset($layers[$id])) $out[] = $id; }
		return $out;
	}

	/**
	 * The zoom choices for an area: one row per zoom, deepest last.
	 *
	 * @param array $bbox      the study area
	 * @param array $layers    the selected maps (values of layers())
	 * @return array of ['zoom', 'scale', 'per_map', 'total', 'bytes', 'allowed' (bool), 'why' (string)]
	 */
	public static function options($bbox, $layers)
	{
		$lat = ($bbox['south'] + $bbox['north']) / 2;
		$kb = 0;
		foreach ($layers as $l) $kb += $l['kb'];
		$out = array();
		for ($zoom = self::MAX_ZOOM - self::ZOOM_CHOICES + 1; $zoom <= self::MAX_ZOOM; $zoom++) {
			$perMap = self::tileCount($bbox, $zoom);
			$total = $perMap * count($layers);
			$why = '';
			if ($perMap > self::MAX_TILES_PER_MAP) {
				$why = "More than " . number_format(self::MAX_TILES_PER_MAP) . " tiles per map. Use a smaller study area for this zoom.";
			} elseif ($total > self::MAX_TILES_TOTAL) {
				$why = "More than " . number_format(self::MAX_TILES_TOTAL) . " tiles in all. Select fewer maps or use a smaller study area.";
			}
			$out[] = array('zoom' => $zoom, 'scale' => self::scale($lat, $zoom), 'per_map' => $perMap, 'total' => $total,
				'bytes' => $perMap * $kb * 1000, 'allowed' => ($why === ''), 'why' => $why);
		}
		return $out;
	}

	/** The zoom the step starts on: the deepest allowed one that keeps a map at or under DEFAULT_TILES_PER_MAP tiles. */
	public static function defaultZoom($options)
	{
		$best = null; $firstAllowed = null;
		foreach ($options as $o) {
			if (!$o['allowed']) continue;
			if ($firstAllowed === null) $firstAllowed = $o['zoom'];
			if ($o['per_map'] <= self::DEFAULT_TILES_PER_MAP) $best = $o['zoom'];
		}
		return $best !== null ? $best : $firstAllowed;
	}

	/**
	 * Check a choice of maps and zoom against the area and the limits.
	 *
	 * @return array [clean zoom, clean layer ids, list of problems]
	 */
	public static function validateChoice($camp, $layers, $zoom, $layerIds)
	{
		$errors = array();
		$bbox = FieldCampUnits::campBbox($camp);
		if ($bbox === null) return array(0, array(), array("Choose the study area first."));
		$ids = array();
		foreach ((array)$layerIds as $id) { if (is_string($id) && isset($layers[$id]) && !in_array($id, $ids, true)) $ids[] = $id; }
		if (!$ids) $errors[] = "Select at least one map.";
		$zoom = (int)$zoom;
		$picked = array();
		foreach ($ids as $id) $picked[] = $layers[$id];
		$row = null;
		foreach (self::options($bbox, $picked ? $picked : array_values($layers)) as $o) { if ($o['zoom'] === $zoom) $row = $o; }
		if ($row === null) $errors[] = "Choose one of the zoom levels listed.";
		elseif (!$row['allowed'] && $ids) $errors[] = "Zoom $zoom is not available for this study area: " . $row['why'];
		return array($zoom, $ids, $errors);
	}

	public static function saveChoice($db, $camp, $zoom, $layerIds)
	{
		$db->prepare_query("update fieldcamp set tiles_zoom = $2, tiles_layers = $3, modified_date = now() where pkey = $1",
			array((int)$camp->pkey, (int)$zoom, json_encode(array_values($layerIds))));
	}

	// -------------------------------------------------------------------
	// Files
	// -------------------------------------------------------------------

	/** The camp's folder of built files, or null when it has none yet. */
	public static function dir($camp)
	{
		$token = preg_replace('/[^a-f0-9]/', '', (string)($camp->files_token ?? ''));
		if (strlen($token) < 32) return null;
		return dirname(__DIR__, 2) . '/fieldcamp_files/' . $token;
	}

	/** Give the camp its folder (name: a random token) if it has none, and return the fresh camp row. */
	private static function ensureDir($db, $camp)
	{
		if (self::dir($camp) === null) {
			$db->prepare_query("update fieldcamp set files_token = $2 where pkey = $1 and files_token is null",
				array((int)$camp->pkey, bin2hex(random_bytes(16))));
			$camp = $db->get_row_prepared("select * from fieldcamp where pkey = $1", array((int)$camp->pkey));
		}
		$dir = self::dir($camp);
		if (!is_dir($dir)) @mkdir($dir, 0775, true);
		return $camp;
	}

	/** What the last finished build holds (tiles.json), or null when there is no built tile zip. */
	public static function manifest($camp)
	{
		$dir = self::dir($camp);
		if ($dir === null || (string)($camp->build_status ?? '') !== 'ready') return null;
		if (!is_file("$dir/tiles.json") || !is_file("$dir/OfflineTiles.zip")) return null;
		$m = json_decode((string)file_get_contents("$dir/tiles.json"), true);
		return (is_array($m) && !empty($m['layers'])) ? $m : null;
	}

	public static function tilesZipPath($camp)
	{
		return self::dir($camp) . '/OfflineTiles.zip';
	}

	/**
	 * Why the built tiles no longer match the camp, or '' when they do: a
	 * different study area, zoom or set of maps than the ones built.
	 */
	public static function outdated($camp, $manifest, $layers)
	{
		if (!$manifest) return '';
		$bbox = FieldCampUnits::campBbox($camp);
		if ($bbox !== null && FieldCampUnits::bboxString($bbox) !== (string)$manifest['bbox']) {
			return "The study area has changed since the tiles were built.";
		}
		if ((int)$camp->tiles_zoom !== (int)$manifest['zoom']) return "The zoom has changed since the tiles were built.";
		$built = array_column($manifest['layers'], 'id');
		$want = self::chosenLayerIds($camp, $layers);
		$skipped = array_column($manifest['skipped'] ?? array(), 'id');
		if (array_diff($want, $built, $skipped) || array_diff($built, $want)) {
			return "The selected maps have changed since the tiles were built.";
		}
		return '';
	}

	// -------------------------------------------------------------------
	// Building
	// -------------------------------------------------------------------

	public static function isBuilding($camp)
	{
		if ((string)($camp->build_status ?? '') !== 'building') return false;
		$started = strtotime((string)$camp->build_started);
		return $started && (time() - $started) < self::STALE_MINUTES * 60;
	}

	/**
	 * Mark the camp as building and start build_tiles.php in the background.
	 *
	 * @return string '' when started, else what is wrong
	 */
	public static function startBuild($db, $camp)
	{
		if (self::isBuilding($camp)) return "The tiles for this camp are being built already.";
		if (FieldCampUnits::campBbox($camp) === null) return "Choose the study area first.";
		if ((int)$camp->tiles_zoom <= 0) return "Choose the maps and the zoom first.";
		$camp = self::ensureDir($db, $camp);
		if (!is_dir(self::dir($camp)) || !is_writable(self::dir($camp))) {
			error_log("FieldCampTiles::startBuild: cannot write " . self::dir($camp));
			return "The server could not make room for the tiles. Please tell the StraboSpot team.";
		}
		// Only one request wins the switch to "building".
		$db->prepare_query("
			update fieldcamp set build_status = 'building', build_started = now(), build_finished = null,
				build_message = 'Starting...'
			where pkey = $1 and (build_status <> 'building' or build_started < now() - interval '" . self::STALE_MINUTES . " minutes')
			", array((int)$camp->pkey));
		$script = __DIR__ . '/build_tiles.php';
		exec('nohup ' . escapeshellarg(PHP_BINDIR . '/php') . ' ' . escapeshellarg($script) . ' ' . (int)$camp->pkey
			. ' > /dev/null 2>&1 &');
		return '';
	}

	/** What the page's status line needs, as a plain array (answered as JSON while a build runs). */
	public static function status($db, $camp)
	{
		$building = self::isBuilding($camp);
		$status = (string)($camp->build_status ?? '');
		if ($status === 'building' && !$building) {
			$status = 'failed';
			$message = "The build stopped before it finished. Please start it again.";
		} else {
			$message = (string)($camp->build_message ?? '');
		}
		$m = self::manifest($camp);
		return array(
			'status' => $status,
			'message' => $message,
			'tiles' => $m ? (int)$m['tiles'] : 0,
			'maps' => $m ? count($m['layers']) : 0,
			'bytes' => $m ? (int)$m['bytes'] : 0,
		);
	}

	private static function progress($db, $camp, $message)
	{
		$db->prepare_query("update fieldcamp set build_message = $2 where pkey = $1 and build_status = 'building'",
			array((int)$camp->pkey, $message));
	}

	/**
	 * Fetch the tiles of every selected map and write OfflineTiles.zip and
	 * tiles.json. Called by build_tiles.php. A map the tile server cannot
	 * deliver is left out and named in the result; the build fails only when
	 * no map could be fetched.
	 *
	 * @param array $layers values of layers() for the camp
	 * @return array ['error' => string, 'tiles' => int, 'maps' => int, 'skipped' => [[id, name, reason]]]
	 */
	public static function build($db, $camp, $layers)
	{
		$out = array('error' => '', 'tiles' => 0, 'maps' => 0, 'skipped' => array());
		$camp = self::ensureDir($db, $camp);
		$dir = self::dir($camp);
		$bbox = FieldCampUnits::campBbox($camp);
		$zoom = (int)$camp->tiles_zoom;
		$ids = self::chosenLayerIds($camp, $layers);
		if ($bbox === null || $zoom <= 0 || !$ids) return self::finish($db, $camp, $out, "Nothing to build: choose the study area, the maps and the zoom first.");
		$extent = FieldCampUnits::bboxString($bbox);

		$work = $dir . '/work_' . getmypid() . '_' . bin2hex(random_bytes(4));
		if (!@mkdir($work, 0775, true)) return self::finish($db, $camp, $out, "The server could not make room for the tiles.");

		$built = array();
		$n = 0;
		foreach ($ids as $id) {
			$n++;
			$layer = $layers[$id];
			$label = $layer['name'] . " ($n of " . count($ids) . ")";
			$result = self::fetchLayer($db, $camp, $layer, $extent, $zoom, $work, $label);
			if ($result['error'] !== '') {
				error_log("FieldCampTiles::build camp " . (int)$camp->pkey . " map $id: " . $result['error']);
				$out['skipped'][] = array('id' => $id, 'name' => $layer['name'], 'reason' => $result['error']);
				continue;
			}
			$entry = array('id' => $id, 'name' => $layer['name'], 'count' => $result['count'], 'bytes' => $result['bytes']);
			if ($layer['custom']) { $entry['customMapSource'] = 'strabospot_mymaps'; $entry['bbox'] = $layer['bbox']; }
			$built[] = $entry;
			$out['tiles'] += $result['count'];
		}

		if (!$built) {
			self::removeTree($work);
			$why = $out['skipped'] ? $out['skipped'][0]['reason'] : 'no maps';
			return self::finish($db, $camp, $out, "No map tiles could be fetched (" . $why . "). Please try again later.");
		}

		self::progress($db, $camp, "Packing " . number_format($out['tiles']) . " tiles...");
		$zipTmp = "$dir/OfflineTiles.zip.tmp";
		@unlink($zipTmp);
		$packed = self::packTiles($work, $built, $zipTmp);
		self::removeTree($work);
		if (!$packed) { @unlink($zipTmp); return self::finish($db, $camp, $out, "The tiles could not be packed."); }

		$manifest = array(
			'built' => gmdate('Y-m-d\TH:i:s\Z'),
			'zoom' => $zoom,
			'bbox' => $extent,
			'tiles' => $out['tiles'],
			'bytes' => (int)filesize($zipTmp),
			'layers' => $built,
			'skipped' => $out['skipped'],
		);
		rename($zipTmp, "$dir/OfflineTiles.zip");
		file_put_contents("$dir/tiles.json", json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$out['maps'] = count($built);
		return self::finish($db, $camp, $out, '');
	}

	/** Record how a build ended and hand the result back. */
	private static function finish($db, $camp, $out, $error)
	{
		$out['error'] = $error;
		if ($error !== '') {
			$status = 'failed'; $message = $error;
		} else {
			$status = 'ready';
			$message = number_format($out['tiles']) . " tiles in " . $out['maps'] . " map" . ($out['maps'] === 1 ? '' : 's') . ".";
			foreach ($out['skipped'] as $s) $message .= " Left out: " . $s['name'] . " (" . $s['reason'] . ").";
		}
		$db->prepare_query("update fieldcamp set build_status = $2, build_message = $3, build_finished = now() where pkey = $1",
			array((int)$camp->pkey, $status, $message));
		return $out;
	}

	/**
	 * One map: ask the tile server for its zip, wait for it, download it and
	 * unpack the tiles into $work/<map id>/tiles/.
	 *
	 * @return array ['error' => string, 'count' => int, 'bytes' => int]
	 */
	private static function fetchLayer($db, $camp, $layer, $extent, $zoom, $work, $label)
	{
		$res = array('error' => '', 'count' => 0, 'bytes' => 0);
		$host = self::tileHost();
		$id = $layer['id'];
		if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id)) { $res['error'] = "not a usable map id"; return $res; }

		self::progress($db, $camp, "$label: asking the map server...");
		$url = "$host/asynczip?layer=" . ($layer['custom'] ? 'strabomymaps' : rawurlencode($id))
			. '&extent=' . $extent . '&zoom=' . (int)$zoom . ($layer['custom'] ? '&id=' . rawurlencode($id) : '');
		list($code, $body) = self::get($url);
		$json = json_decode((string)$body, true);
		$uid = is_array($json) ? (string)($json['id'] ?? '') : '';
		if ($code !== 200 || !preg_match('/^[A-Za-z0-9]{1,40}$/', $uid)) {
			$res['error'] = "the map server did not accept the request";
			return $res;
		}

		$deadline = time() + self::LAYER_WAIT;
		$failures = 0;
		while (true) {
			list($code, $body) = self::get("$host/asyncstatus/$uid");
			$s = json_decode((string)$body, true);
			if ($code === 200 && is_array($s)) {
				$failures = 0;
				$text = (string)($s['status'] ?? '');
				if (isset($s['error']) || stripos($text, 'error') !== false) {
					$res['error'] = "the map server reported: " . trim((string)($s['error'] ?? $text));
					return $res;
				}
				if ($text === 'Zip File Ready.') break;
				$pct = (int)($s['percent'] ?? 0);
				self::progress($db, $camp, "$label: " . ($text !== '' ? rtrim($text, '.') : 'working') . ($pct > 0 ? " $pct%" : ''));
			} elseif (++$failures >= 10) {
				$res['error'] = "the map server stopped answering";
				return $res;
			}
			if (time() >= $deadline) { $res['error'] = "the map server took too long"; return $res; }
			if (self::$pollSeconds > 0) sleep(self::$pollSeconds);
		}

		self::progress($db, $camp, "$label: downloading...");
		$zipFile = "$work/$uid.zip";
		list($code) = self::get("$host/ziptemp/$uid/$uid.zip", $zipFile);
		if ($code !== 200 || !is_file($zipFile)) { $res['error'] = "the tiles could not be downloaded"; return $res; }

		$zip = new ZipArchive();
		if ($zip->open($zipFile) !== true) { @unlink($zipFile); $res['error'] = "the downloaded tiles could not be read"; return $res; }
		$target = "$work/$id/tiles";
		@mkdir($target, 0775, true);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			// Only tile files, and only under the name they must have; nothing else leaves the zip.
			if (!preg_match('~^[A-Za-z0-9]+/tiles/(\d{1,2}_\d+_\d+\.png)$~', (string)$name, $m)) continue;
			$data = $zip->getFromIndex($i);
			if ($data === false || $data === '') continue;
			file_put_contents("$target/" . $m[1], $data);
			$res['count']++;
			$res['bytes'] += strlen($data);
		}
		$zip->close();
		@unlink($zipFile);
		if ($res['count'] === 0) $res['error'] = "the map server sent no tiles";
		return $res;
	}

	/** Write OfflineTiles.zip: <map id>/tiles/<tile>, stored without compression (PNG is compressed already). */
	private static function packTiles($work, $built, $zipPath)
	{
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
		$pending = 0;
		foreach ($built as $layer) {
			$folder = "$work/" . $layer['id'] . '/tiles';
			foreach (scandir($folder) as $file) {
				if ($file === '.' || $file === '..') continue;
				$name = $layer['id'] . '/tiles/' . $file;
				$zip->addFile("$folder/$file", $name);
				$zip->setCompressionName($name, ZipArchive::CM_STORE);
				// Written out in batches: every added file stays open until the archive is closed.
				if (++$pending >= 400) {
					if (!$zip->close() || $zip->open($zipPath) !== true) return false;
					$pending = 0;
				}
			}
		}
		return $zip->close();
	}

	private static function removeTree($dir)
	{
		if (!is_dir($dir)) return;
		foreach (scandir($dir) as $f) {
			if ($f === '.' || $f === '..') continue;
			$p = "$dir/$f";
			if (is_dir($p) && !is_link($p)) self::removeTree($p); else @unlink($p);
		}
		@rmdir($dir);
	}

	private static function get($url, $toFile = null)
	{
		if (self::$http !== null) return call_user_func(self::$http, $url, $toFile);
		$ch = curl_init($url);
		$opts = array(
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT => $toFile === null ? 60 : 1800,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERAGENT => 'StraboSpot',
		);
		$fh = null;
		if ($toFile === null) $opts[CURLOPT_RETURNTRANSFER] = true;
		else { $fh = fopen($toFile, 'w'); $opts[CURLOPT_FILE] = $fh; }
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($body === false) { error_log("FieldCampTiles: $url: " . curl_error($ch)); $code = 0; }
		if ($fh) { fclose($fh); if ($code !== 200) @unlink($toFile); }
		return array($code, $toFile === null ? (string)$body : '');
	}

	// -------------------------------------------------------------------
	// For the package
	// -------------------------------------------------------------------

	/**
	 * The mapNamesDb entries of data.json for the built maps: what the app's
	 * createOfflineMapObject writes. The tile and glyph paths are the app's own
	 * folders on a device; the app rebuilds them from its own location when a
	 * map is shown (setOfflineMapTiles), so the device part is left generic.
	 *
	 * @param array $manifest manifest()
	 * @param array $layers   layers() of the camp today (a custom map no longer in the project is left out)
	 */
	public static function mapNamesDb($manifest, $layers)
	{
		$out = new stdClass();
		if (!$manifest) return $out;
		$built = strtotime((string)$manifest['built']);
		// The app stamps Date.toLocaleString(): "11/10/2026, 3:50:34 PM" with a narrow no-break space before AM/PM.
		$date = date('n/j/Y, g:i:s', $built ? $built : time()) . "\u{202F}" . date('A', $built ? $built : time());
		foreach ($manifest['layers'] as $l) {
			$id = (string)$l['id'];
			if (!isset($layers[$id])) continue;
			$map = array(
				'id' => $id,
				'name' => $layers[$id]['name'],
				'count' => (int)$l['count'],
				'bbox' => $layers[$id]['custom'] ? (string)$layers[$id]['bbox'] : '',
				'source' => 'direct from filesystem',
			);
			if ($layers[$id]['custom']) $map['customMapSource'] = 'strabospot_mymaps';
			$map += array(
				'overlay' => false,
				'mapId' => substr(md5($id . $manifest['built']), 0, 13),
				'date' => $date,
				'isOfflineMapVisible' => false,
				'version' => 8,
				'sources' => array('raster-tiles' => array(
					'type' => 'raster',
					'tiles' => array('file:///Documents/StraboSpotTiles/TileCache/' . $id . '/tiles/{z}_{x}_{y}.png'),
					'tileSize' => 256,
				)),
				'glyphs' => 'file:///Documents/StraboSpot/Glyphs/{fontstack}/{range}.pbf',
				'layers' => array(array('id' => $id, 'type' => 'raster', 'source' => 'raster-tiles', 'minzoom' => 0)),
			);
			$out->{$id} = $map;
		}
		return $out;
	}
}
