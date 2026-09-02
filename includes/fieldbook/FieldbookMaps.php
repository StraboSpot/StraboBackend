<?php
/**
 * File: includes/fieldbook/FieldbookMaps.php
 * Description: Map figures for the enhanced fieldbook (docs/Fieldbook_Design.md
 *              §6, decision D3). Tiles come from the site's own proxy
 *              (tiles.strabospot.org/v5/<set>/z/x/y.png), are cached on
 *              disk under exportjobs_data/tilecache, fetched in parallel
 *              with short timeouts and one retry, and composited in GD:
 *              a mosaic window whose pixel size IS the tile budget of the
 *              figure (a 768 px wide window can never touch more than four
 *              tile columns), the zoom chosen as the deepest level at which
 *              the padded extent still fits the window. Markers (numbered
 *              pills, clustered when they overlap), line / polygon outlines,
 *              a scale bar. Any tile failure paints gray; more than 30 %
 *              missing degrades to a graticule frame. A per-book tile
 *              budget stops the optional figures (day locators) once spent.
 *              Never throws for network reasons: a figure that cannot be
 *              drawn returns null and the book goes on without it.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

class FieldbookMaps
{
	const TILE = 256;
	const MAX_ZOOM = 17;
	const MIN_ZOOM = 2;

	public static $sets = array('outdoors' => 'mapbox.outdoors', 'satellite' => 'mapbox.satellite');
	const OVERLAY_GEOLOGY = 'macrostrat';

	public $set = 'outdoors';         // outdoors | satellite | geology | none
	public $tilesUsed = 0;            // tiles counted against the budget (fetched or cached)
	public $tilesMissing = 0;         // tiles that could not be obtained
	public $fetched = 0;              // network fetches
	public $cacheHits = 0;
	public $budgetHit = false;        // a figure was skipped because the budget was spent
	public $fallbacks = 0;            // figures drawn as a graticule frame
	public $figures = 0;

	private $base;
	private $cacheDir;
	private $budget;
	private $ttl;
	private $connectTimeout = 2;
	private $totalTimeout = 4;
	private $font;
	private $fontBold;
	private $cacheOk = false;

	/**
	 * @param array $cfg set, tile_base, cache_dir, budget, ttl_days, (timeouts for tests)
	 */
	public function __construct(array $cfg)
	{
		$this->set = isset($cfg['set']) ? $cfg['set'] : 'outdoors';
		$this->base = rtrim(isset($cfg['tile_base']) ? $cfg['tile_base'] : 'https://tiles.strabospot.org/v5/', '/') . '/';
		$this->cacheDir = isset($cfg['cache_dir']) ? rtrim($cfg['cache_dir'], '/') : '';
		$this->budget = isset($cfg['budget']) ? (int)$cfg['budget'] : 300;
		$this->ttl = (isset($cfg['ttl_days']) ? (int)$cfg['ttl_days'] : 90) * 86400;
		if (isset($cfg['connect_timeout'])) $this->connectTimeout = (int)$cfg['connect_timeout'];
		if (isset($cfg['total_timeout'])) $this->totalTimeout = (int)$cfg['total_timeout'];
		$fontDir = $_SERVER['DOCUMENT_ROOT'] . '/includes/tfpdf/font/unifont/';
		$this->font = is_file($fontDir . 'DejaVuSansCondensed.ttf') ? $fontDir . 'DejaVuSansCondensed.ttf' : null;
		$this->fontBold = is_file($fontDir . 'DejaVuSansCondensed-Bold.ttf') ? $fontDir . 'DejaVuSansCondensed-Bold.ttf' : $this->font;
		if ($this->cacheDir !== '') {
			if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0775, true);
			$this->cacheOk = is_dir($this->cacheDir) && is_writable($this->cacheDir);
		}
	}

	public function enabled() { return $this->set !== 'none' && function_exists('imagecreatetruecolor'); }

	public function attribution()
	{
		$a = $this->set === 'satellite' ? '© Mapbox © Maxar' : '© Mapbox © OpenStreetMap';
		if ($this->set === 'geology') $a .= ' · geology © Macrostrat';
		return $a . ' · StraboSpot tiles';
	}

	// ------------------------------------------------------------ projection

	/** Web Mercator world pixel coordinates at zoom $z. */
	public static function project($lon, $lat, $z)
	{
		$n = self::TILE * pow(2, $z);
		$lat = max(-85.05112878, min(85.05112878, $lat));
		$x = ($lon + 180) / 360 * $n;
		$latr = deg2rad($lat);
		$y = (1 - log(tan($latr) + 1 / cos($latr)) / M_PI) / 2 * $n;
		return array($x, $y);
	}

	public static function metersPerPixel($lat, $z)
	{
		return 156543.03392 * cos(deg2rad($lat)) / pow(2, $z);
	}

	/** Deepest zoom at which the extent (lon/lat bbox) fits an inner box of $w x $h px. */
	public static function zoomFor(array $bbox, $w, $h)
	{
		for ($z = self::MAX_ZOOM; $z >= self::MIN_ZOOM; $z--) {
			list($x0, $y0) = self::project($bbox[0], $bbox[3], $z);   // top-left = west, north
			list($x1, $y1) = self::project($bbox[2], $bbox[1], $z);
			if (($x1 - $x0) <= $w && ($y1 - $y0) <= $h) return $z;
		}
		return self::MIN_ZOOM;
	}

	/** Padded bbox [minLon, minLat, maxLon, maxLat] of a coordinate list, with a minimum span. */
	public static function extent(array $coords, $pad = 0.15, $minSpanM = 250)
	{
		if (!$coords) return null;
		$minLon = 180; $minLat = 90; $maxLon = -180; $maxLat = -90;
		foreach ($coords as $c) {
			$minLon = min($minLon, $c[0]); $maxLon = max($maxLon, $c[0]);
			$minLat = min($minLat, $c[1]); $maxLat = max($maxLat, $c[1]);
		}
		$midLat = ($minLat + $maxLat) / 2;
		$minLatSpan = $minSpanM / 111320;
		$minLonSpan = $minSpanM / (111320 * max(0.05, cos(deg2rad($midLat))));
		$dLon = max($maxLon - $minLon, $minLonSpan); $dLat = max($maxLat - $minLat, $minLatSpan);
		$cLon = ($minLon + $maxLon) / 2; $cLat = $midLat;
		$dLon *= (1 + 2 * $pad); $dLat *= (1 + 2 * $pad);
		return array($cLon - $dLon / 2, max(-85, $cLat - $dLat / 2), $cLon + $dLon / 2, min(85, $cLat + $dLat / 2));
	}

	// ------------------------------------------------------------ tiles

	private function tileUrl($setPath, $z, $x, $y) { return $this->base . $setPath . "/$z/$x/$y.png"; }

	private function cachePath($setPath, $z, $x, $y)
	{
		if (!$this->cacheOk) return null;
		return $this->cacheDir . "/$setPath/$z/$x/$y.png";
	}

	/**
	 * Obtain tiles: [key => GD image|null]. Cache first, then parallel fetch with one retry.
	 * $wanted = [[setPath, z, x, y], ...]
	 */
	private function tiles(array $wanted)
	{
		$out = array(); $fetch = array();
		foreach ($wanted as $t) {
			list($sp, $z, $x, $y) = $t;
			$key = "$sp/$z/$x/$y";
			$cp = $this->cachePath($sp, $z, $x, $y);
			if ($cp && is_file($cp) && filemtime($cp) > time() - $this->ttl && filesize($cp) > 0) {
				$im = @imagecreatefrompng($cp);
				if ($im) { $out[$key] = $im; $this->cacheHits++; continue; }
			}
			$fetch[$key] = array($this->tileUrl($sp, $z, $x, $y), $cp);
		}
		for ($attempt = 0; $attempt < 2 && $fetch; $attempt++) {
			$got = $this->fetchMany($fetch);
			foreach ($got as $key => $bytes) {
				$im = @imagecreatefromstring($bytes);
				if (!$im) continue;
				$out[$key] = $im;
				$this->fetched++;
				$cp = $fetch[$key][1];
				if ($cp) {
					if (!is_dir(dirname($cp))) @mkdir(dirname($cp), 0775, true);
					$tmp = $cp . '.' . getmypid() . '.tmp';
					if (@file_put_contents($tmp, $bytes) !== false) @rename($tmp, $cp); else @unlink($tmp);
				}
				unset($fetch[$key]);
			}
		}
		foreach ($fetch as $key => $x) { $out[$key] = null; $this->tilesMissing++; }
		return $out;
	}

	/**
	 * The tile host resolved ONCE per process and pinned on every handle (CURLOPT_RESOLVE):
	 * a slow resolver (the dev Docker 5 s stall) would otherwise eat the per-tile timeout.
	 */
	private static $resolved = array();
	private function resolveOpt()
	{
		$u = parse_url($this->base);
		if (!$u || empty($u['host'])) return array();
		$host = $u['host'];
		$port = isset($u['port']) ? (int)$u['port'] : ((isset($u['scheme']) && $u['scheme'] === 'https') ? 443 : 80);
		if (!isset(self::$resolved[$host])) {
			$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
			self::$resolved[$host] = ($ip !== $host || filter_var($host, FILTER_VALIDATE_IP)) ? $ip : null;
		}
		if (!self::$resolved[$host]) return array();
		return array(CURLOPT_RESOLVE => array("$host:$port:" . self::$resolved[$host]));
	}

	/** Parallel GET; returns [key => bytes] for the successes only. */
	private function fetchMany(array $fetch)
	{
		$got = array();
		if (!function_exists('curl_multi_init')) return $got;
		$mh = curl_multi_init();
		$handles = array();
		$resolve = $this->resolveOpt();
		foreach ($fetch as $key => $f) {
			$ch = curl_init($f[0]);
			curl_setopt_array($ch, $resolve + array(
				CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
				CURLOPT_CONNECTTIMEOUT => $this->connectTimeout, CURLOPT_TIMEOUT => $this->totalTimeout,
				CURLOPT_USERAGENT => 'StraboSpot fieldbook (+https://strabospot.org)',
				CURLOPT_SSL_VERIFYPEER => true,
			));
			curl_multi_add_handle($mh, $ch);
			$handles[$key] = $ch;
		}
		$running = null;
		do {
			$st = curl_multi_exec($mh, $running);
			if ($running) curl_multi_select($mh, 0.5);
		} while ($running && $st == CURLM_OK);
		foreach ($handles as $key => $ch) {
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$body = curl_multi_getcontent($ch);
			if ($code === 200 && $body !== false && strlen($body) > 0) $got[$key] = $body;
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		curl_multi_close($mh);
		return $got;
	}

	// ------------------------------------------------------------ figure

	/**
	 * Render one map figure.
	 * @param array $points [[lon, lat, label], ...] label '' = plain dot
	 * @param array $shapes [['type' => 'LineString'|'Polygon'|'MultiLineString'|'MultiPolygon', 'coordinates' => GeoJSON coordinates], ...]
	 * @param int $winW window width px (the tile budget: <= ceil(w/256)+1 columns)
	 * @param int $winH window height px
	 * @param bool $optional skip (return null) when the budget is spent
	 * @return array|null {im, w, h, zoom, fallback, scaleLabel}
	 */
	public function render(array $points, array $shapes, $winW, $winH, $optional = false)
	{
		if (!$this->enabled()) return null;
		$coords = array();
		foreach ($points as $p) $coords[] = array((float)$p[0], (float)$p[1]);
		foreach ($shapes as $s) self::flatten($s['coordinates'], $coords);
		$coords = array_values(array_filter($coords, function ($c) { return abs($c[0]) <= 180 && abs($c[1]) <= 90; }));
		$bbox = self::extent($coords);
		if (!$bbox) return null;
		$winW = (int)$winW; $winH = (int)$winH;
		$z = self::zoomFor($bbox, $winW - 48, $winH - 48);
		list($cx, $cy) = self::project(($bbox[0] + $bbox[2]) / 2, ($bbox[1] + $bbox[3]) / 2, $z);
		$ox = (int)round($cx - $winW / 2); $oy = (int)round($cy - $winH / 2);
		$n = self::TILE * pow(2, $z);
		$oy = max(0, min($n - $winH, $oy));
		$tx0 = (int)floor($ox / self::TILE); $tx1 = (int)floor(($ox + $winW - 1) / self::TILE);
		$ty0 = (int)floor($oy / self::TILE); $ty1 = (int)floor(($oy + $winH - 1) / self::TILE);
		$setPath = $this->set === 'satellite' ? self::$sets['satellite'] : self::$sets['outdoors'];
		$wanted = array();
		for ($tx = $tx0; $tx <= $tx1; $tx++) for ($ty = $ty0; $ty <= $ty1; $ty++) {
			if ($ty < 0 || $ty >= pow(2, $z)) continue;
			$wx = (($tx % (int)pow(2, $z)) + (int)pow(2, $z)) % (int)pow(2, $z);   // wrap the antimeridian
			$wanted[] = array($setPath, $z, $wx, $ty, $tx);
			if ($this->set === 'geology') $wanted[] = array(self::OVERLAY_GEOLOGY, $z, $wx, $ty, $tx);
		}
		if ($this->tilesUsed + count($wanted) > $this->budget) {
			$this->budgetHit = true;
			if ($optional) return null;
		}
		$this->tilesUsed += count($wanted);
		$tiles = $this->tiles(array_map(function ($t) { return array($t[0], $t[1], $t[2], $t[3]); }, $wanted));
		$missing = 0;
		foreach ($tiles as $t) if ($t === null) $missing++;
		$im = imagecreatetruecolor($winW, $winH);
		$fallback = $missing > 0 && $missing / max(1, count($wanted)) > 0.3;
		if ($fallback) {
			$this->fallbacks++;
			$this->graticule($im, $bbox, $z, $ox, $oy, $winW, $winH);
		} else {
			$gray = imagecolorallocate($im, 222, 222, 222);
			imagefilledrectangle($im, 0, 0, $winW, $winH, $gray);
			foreach ($wanted as $t) {
				$key = "{$t[0]}/{$t[1]}/{$t[2]}/{$t[3]}";
				$tile = isset($tiles[$key]) ? $tiles[$key] : null;
				if (!$tile) continue;
				$dx = $t[4] * self::TILE - $ox; $dy = $t[3] * self::TILE - $oy;
				if ($t[0] === self::OVERLAY_GEOLOGY) {
					imagecopymerge($im, $tile, $dx, $dy, 0, 0, self::TILE, self::TILE, 60);
				} else {
					imagecopy($im, $tile, $dx, $dy, 0, 0, self::TILE, self::TILE);
				}
			}
		}
		foreach ($tiles as $t) if ($t) imagedestroy($t);
		$this->drawShapes($im, $shapes, $z, $ox, $oy);
		$this->drawMarkers($im, $points, $z, $ox, $oy);
		$scale = $this->drawScaleBar($im, ($bbox[1] + $bbox[3]) / 2, $z, $winW, $winH);
		$this->figures++;
		return array('im' => $im, 'w' => $winW, 'h' => $winH, 'zoom' => $z, 'fallback' => $fallback, 'missing' => $missing, 'scaleLabel' => $scale);
	}

	private static function flatten($c, array &$out)
	{
		if (!is_array($c) || !$c) return;
		if (is_numeric($c[0])) { if (count($c) >= 2) $out[] = array((float)$c[0], (float)$c[1]); return; }
		foreach ($c as $x) self::flatten($x, $out);
	}

	/** Light frame with lon/lat grid lines and labels: the no-tiles fallback. */
	private function graticule($im, array $bbox, $z, $ox, $oy, $w, $h)
	{
		$bg = imagecolorallocate($im, 246, 246, 244);
		imagefilledrectangle($im, 0, 0, $w, $h, $bg);
		$line = imagecolorallocate($im, 205, 205, 205);
		$text = imagecolorallocate($im, 120, 120, 120);
		$span = max($bbox[2] - $bbox[0], $bbox[3] - $bbox[1]);
		$steps = array(0.0005, 0.001, 0.002, 0.005, 0.01, 0.02, 0.05, 0.1, 0.2, 0.5, 1, 2, 5, 10);
		$step = 10;
		foreach ($steps as $s) if ($span / $s <= 6) { $step = $s; break; }
		$dec = max(0, (int)ceil(-log10($step)));
		for ($lon = floor($bbox[0] / $step) * $step; $lon <= $bbox[2] + $step; $lon += $step) {
			list($px,) = self::project($lon, $bbox[1], $z); $px -= $ox;
			if ($px < 0 || $px > $w) continue;
			imageline($im, (int)$px, 0, (int)$px, $h, $line);
			$this->text($im, (int)$px + 3, 14, number_format($lon, $dec) . '°', 10, $text, false);
		}
		for ($lat = floor($bbox[1] / $step) * $step; $lat <= $bbox[3] + $step; $lat += $step) {
			list(, $py) = self::project($bbox[0], $lat, $z); $py -= $oy;
			if ($py < 0 || $py > $h) continue;
			imageline($im, 0, (int)$py, $w, (int)$py, $line);
			$this->text($im, 4, (int)$py - 3, number_format($lat, $dec) . '°', 10, $text, false);
		}
		$label = 'Basemap unavailable';
		$this->text($im, $w - $this->textWidth($label, 10, false) - 8, $h - 8, $label, 10, $text, false);
	}

	private function drawShapes($im, array $shapes, $z, $ox, $oy)
	{
		if (!$shapes) return;
		$halo = imagecolorallocate($im, 255, 255, 255);
		$ink = imagecolorallocate($im, 35, 35, 35);
		foreach ($shapes as $s) {
			$type = isset($s['type']) ? $s['type'] : '';
			$paths = array();
			if ($type === 'LineString') $paths[] = $s['coordinates'];
			elseif ($type === 'MultiLineString' || $type === 'Polygon') $paths = $s['coordinates'];
			elseif ($type === 'MultiPolygon') foreach ($s['coordinates'] as $poly) foreach ($poly as $ring) $paths[] = $ring;
			foreach ($paths as $path) {
				$pts = array();
				foreach ($path as $c) { list($px, $py) = self::project($c[0], $c[1], $z); $pts[] = array((int)round($px - $ox), (int)round($py - $oy)); }
				foreach (array(array($halo, 5), array($ink, 2)) as $pen) {
					imagesetthickness($im, $pen[1]);
					for ($i = 1; $i < count($pts); $i++) imageline($im, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $pen[0]);
				}
			}
		}
		imagesetthickness($im, 1);
	}

	/** Numbered pills (clustered when they overlap) or plain dots when labels are empty. */
	private function drawMarkers($im, array $points, $z, $ox, $oy)
	{
		if (!$points) return;
		$px = array();
		foreach ($points as $i => $p) {
			list($x, $y) = self::project($p[0], $p[1], $z);
			$px[] = array('x' => $x - $ox, 'y' => $y - $oy, 'label' => isset($p[2]) ? (string)$p[2] : '');
		}
		$fill = imagecolorallocate($im, 30, 30, 30);
		$edge = imagecolorallocate($im, 255, 255, 255);
		$txt = imagecolorallocate($im, 255, 255, 255);
		// clusters: greedy grouping of markers whose centres are within 16 px
		$clusters = array();
		foreach ($px as $m) {
			$placed = false;
			foreach ($clusters as &$c) {
				if (hypot($c['x'] - $m['x'], $c['y'] - $m['y']) < 16) { $c['members'][] = $m; $placed = true; break; }
			}
			unset($c);
			if (!$placed) $clusters[] = array('x' => $m['x'], 'y' => $m['y'], 'members' => array($m));
		}
		foreach ($clusters as $c) {
			$labels = array();
			foreach ($c['members'] as $m) if ($m['label'] !== '') $labels[] = $m['label'];
			$x = (int)round($c['x']); $y = (int)round($c['y']);
			if (!$labels) {   // plain dots
				$r = count($c['members']) > 1 ? 7 : 5;
				imagefilledellipse($im, $x, $y, 2 * $r + 4, 2 * $r + 4, $edge);
				imagefilledellipse($im, $x, $y, 2 * $r, 2 * $r, $fill);
				continue;
			}
			$label = self::rangeLabel($labels);
			$size = 11;
			$bw = $this->textWidth($label, $size, true);
			$h = 22; $w = max($h, $bw + 12);
			$this->pill($im, $x, $y, $w, $h, $edge, 2);
			$this->pill($im, $x, $y, $w - 4, $h - 4, $fill, 0);
			$this->text($im, (int)round($x - $bw / 2), $y + 4, $label, $size, $txt, true);
		}
	}

	/** "3" / "3-6" / "3, 7" / "3-5, 9" from a list of numeric labels. */
	public static function rangeLabel(array $labels)
	{
		$nums = array(); $other = array();
		foreach ($labels as $l) { if (ctype_digit((string)$l)) $nums[] = (int)$l; else $other[] = $l; }
		sort($nums); $nums = array_values(array_unique($nums));
		$parts = array();
		$i = 0;
		while ($i < count($nums)) {
			$j = $i;
			while ($j + 1 < count($nums) && $nums[$j + 1] === $nums[$j] + 1) $j++;
			$parts[] = $j > $i + 1 ? $nums[$i] . '-' . $nums[$j] : ($j === $i ? (string)$nums[$i] : $nums[$i] . ', ' . $nums[$j]);
			$i = $j + 1;
		}
		foreach ($other as $o) $parts[] = $o;
		if (count($parts) > 3) $parts = array_merge(array_slice($parts, 0, 2), array('+' . (count($labels) - 2)));
		return implode(', ', $parts);
	}

	private function pill($im, $cx, $cy, $w, $h, $color, $pad)
	{
		$r = (int)($h / 2);
		$x0 = (int)round($cx - $w / 2); $x1 = (int)round($cx + $w / 2);
		imagefilledellipse($im, $x0 + $r, $cy, 2 * $r, $h, $color);
		imagefilledellipse($im, $x1 - $r, $cy, 2 * $r, $h, $color);
		if ($x1 - $r > $x0 + $r) imagefilledrectangle($im, $x0 + $r, (int)($cy - $h / 2), $x1 - $r, (int)($cy + $h / 2) - 1, $color);
	}

	private function drawScaleBar($im, $lat, $z, $w, $h)
	{
		$mpp = self::metersPerPixel($lat, $z);
		$choices = array(5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000, 20000, 50000, 100000, 200000, 500000);
		$len = 100; $px = 0;
		foreach ($choices as $m) { $p = $m / $mpp; if ($p >= 60 && $p <= 150) { $len = $m; $px = $p; break; } if ($p > 150) break; $len = $m; $px = $p; }
		$label = $len >= 1000 ? ($len / 1000) . ' km' : $len . ' m';
		$ink = imagecolorallocate($im, 30, 30, 30);
		$box = imagecolorallocatealpha($im, 255, 255, 255, 40);
		$x0 = 10; $y0 = $h - 12;
		$tw = $this->textWidth($label, 10, false);
		imagefilledrectangle($im, $x0 - 5, $y0 - 20, (int)($x0 + max($px, $tw) + 6), $h - 4, $box);
		imagesetthickness($im, 2);
		imageline($im, $x0, $y0, (int)($x0 + $px), $y0, $ink);
		imageline($im, $x0, $y0 - 5, $x0, $y0, $ink);
		imageline($im, (int)($x0 + $px), $y0 - 5, (int)($x0 + $px), $y0, $ink);
		imagesetthickness($im, 1);
		$this->text($im, $x0, $y0 - 7, $label, 10, $ink, false);
		return $label;
	}

	private function text($im, $x, $y, $s, $size, $color, $bold)
	{
		$font = $bold ? $this->fontBold : $this->font;
		if ($font) { imagettftext($im, $size, 0, $x, $y, $color, $font, $s); }
		else { imagestring($im, $bold ? 3 : 2, $x, $y - 10, $s, $color); }
	}

	private function textWidth($s, $size, $bold)
	{
		$font = $bold ? $this->fontBold : $this->font;
		if ($font) { $b = imagettfbbox($size, 0, $font, $s); return abs($b[2] - $b[0]); }
		return strlen($s) * ($bold ? 7 : 6);
	}

	/** Colophon lines about the maps of this book. */
	public function notes()
	{
		if (!$this->enabled()) return array('Maps: none (option).');
		$n = array();
		$n[] = 'Maps: ' . $this->figures . ' figure' . ($this->figures === 1 ? '' : 's') . ', basemap ' . $this->set . ', ' . $this->tilesUsed . ' tiles (' . $this->fetched . ' fetched, ' . $this->cacheHits . ' from cache'
			. ($this->tilesMissing ? ', ' . $this->tilesMissing . ' unavailable' : '') . '). ' . $this->attribution() . '.';
		if ($this->fallbacks) $n[] = $this->fallbacks . ' map' . ($this->fallbacks === 1 ? '' : 's') . ' drawn without a basemap (tiles unavailable while building).';
		if ($this->budgetHit) $n[] = 'Day locator maps were omitted once the per-book tile budget (' . $this->budget . ') was spent.';
		return $n;
	}
}
