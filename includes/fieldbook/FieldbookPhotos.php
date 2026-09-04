<?php
/**
 * File: includes/fieldbook/FieldbookPhotos.php
 * Description: Photo figures of the enhanced fieldbook
 *              (docs/Fieldbook_Design.md §8, D5). Finds the original file
 *              of a Field image (dbimages/<filename>, the filename stored
 *              on the Image node and resolved once per book by
 *              Fieldbook::imageFilenames; bare id / id.jpg as fallbacks
 *              for tooling), decodes it
 *              through GD with the EXIF orientation applied, scales it to
 *              a contact-sheet or full-width thumbnail (JPEG bytes for the
 *              PDF), caches sheet thumbnails on disk
 *              (exportjobs_data/thumbcache/<id>_<px>.jpg, swept by
 *              cleanup_data.sh), and draws the spot overlay on image
 *              basemaps: child spots in the image's pixel space (points as
 *              strike-and-dip / trend-and-plunge symbols or dots, lines,
 *              polygons, each labelled with the spot name). No PDF calls;
 *              FieldbookRenderer places the bytes.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

class FieldbookPhotos
{
	const SHEET_PX = 400;          // longest side of a contact-sheet thumbnail (640 until 2026-09-04: ~124 KB a photo made 700-photo books 90 MB; 400 px q78 ~44 KB, still ~180 dpi in a 55 mm cell)
	const SHEET_Q = 78;            // JPEG quality of thumbnails (82 until 2026-09-04)
	const FULL_PX = 1400;          // longest side of a promoted (full-width) image
	const MAX_PIXELS = 60000000;   // originals above this many pixels are not decoded (memory)

	public $mode = 'sheets';       // sheets | full | none
	public $images = 0;            // figures placed
	public $missing = 0;           // files not found
	public $skipped = 0;           // too large / undecodable
	public $cacheHits = 0;
	public $generated = 0;
	public $promoted = 0;          // full-width figures (basemaps with children, sketches, or photos=full)
	public $overlays = 0;          // basemaps drawn with their child spots

	private $dir;
	private $filenames = array();   // image id => stored filename (Neo4j Image.filename), resolved by the caller
	private $cacheDir = '';
	private $cacheOk = false;
	private $ttl;
	private $font; private $fontBold;

	public function __construct(array $cfg)
	{
		$this->mode = isset($cfg['mode']) ? $cfg['mode'] : 'sheets';
		$this->dir = rtrim(isset($cfg['image_dir']) ? $cfg['image_dir'] : $_SERVER['DOCUMENT_ROOT'] . '/dbimages', '/');
		if (isset($cfg['filenames']) && is_array($cfg['filenames'])) foreach ($cfg['filenames'] as $k => $v) $this->filenames[(string)$k] = (string)$v;
		$this->cacheDir = isset($cfg['cache_dir']) ? rtrim($cfg['cache_dir'], '/') : '';
		$this->ttl = (isset($cfg['ttl_days']) ? (int)$cfg['ttl_days'] : 90) * 86400;
		$fontDir = $_SERVER['DOCUMENT_ROOT'] . '/includes/tfpdf/font/unifont/';
		$this->font = is_file($fontDir . 'DejaVuSansCondensed.ttf') ? $fontDir . 'DejaVuSansCondensed.ttf' : null;
		$this->fontBold = is_file($fontDir . 'DejaVuSansCondensed-Bold.ttf') ? $fontDir . 'DejaVuSansCondensed-Bold.ttf' : $this->font;
		if ($this->cacheDir !== '') {
			if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0775, true);
			$this->cacheOk = is_dir($this->cacheDir) && is_writable($this->cacheDir);
		}
	}

	public function enabled() { return $this->mode !== 'none' && function_exists('imagecreatetruecolor'); }

	/** Path of an image's original file, or null when absent: the stored filename first, then bare id / id.jpg. */
	public function path($id)
	{
		$id = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$id);
		if ($id === '') return null;
		$candidates = array();
		if (isset($this->filenames[$id]) && $this->filenames[$id] !== '') {
			$fn = basename($this->filenames[$id]);   // never leave dbimages
			if ($fn !== '' && $fn !== '.' && $fn !== '..') $candidates[] = $this->dir . '/' . $fn;
		}
		$candidates[] = $this->dir . '/' . $id; $candidates[] = $this->dir . '/' . $id . '.jpg'; $candidates[] = $this->dir . '/' . $id . '.jpeg';
		foreach ($candidates as $p) if (is_file($p)) return $p;
		return null;
	}

	/** Register stored filenames after construction (id => filename). */
	public function setFilenames(array $map) { foreach ($map as $k => $v) $this->filenames[(string)$k] = (string)$v; }

	/**
	 * Thumbnail with the longest side <= $maxPx: ['data' => jpeg bytes, 'w', 'h'] or null (missing / skipped).
	 * Sheet-size thumbnails are cached on disk; larger requests are generated every time.
	 */
	public function thumb($id, $maxPx = self::SHEET_PX)
	{
		$maxPx = (int)$maxPx;
		$cache = ($this->cacheOk && $maxPx <= self::SHEET_PX) ? $this->cacheDir . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$id) . '_' . $maxPx . '.jpg' : null;
		if ($cache && is_file($cache) && filemtime($cache) > time() - $this->ttl) {
			$data = file_get_contents($cache);
			$size = $data !== false ? @getimagesizefromstring($data) : false;
			if ($size) { $this->cacheHits++; $this->images++; return array('data' => $data, 'w' => $size[0], 'h' => $size[1]); }
		}
		$im = $this->load($id);
		if (!$im) return null;
		$im = self::scaleTo($im, $maxPx);
		ob_start(); imagejpeg($im, null, self::SHEET_Q); $data = ob_get_clean();
		$out = array('data' => $data, 'w' => imagesx($im), 'h' => imagesy($im));
		imagedestroy($im);
		$this->generated++; $this->images++;
		if ($cache) { $tmp = $cache . '.' . getmypid() . '.tmp'; if (@file_put_contents($tmp, $data) !== false) @rename($tmp, $cache); else @unlink($tmp); }
		return $out;
	}

	/**
	 * Full-size figure of an image basemap with its child spots drawn on (design §8): $children are model spot
	 * blocks (name, pixel geometry, orientations); $imgW / $imgH are the dimensions the app recorded for the
	 * image (the children's pixel space), falling back to the file's own. Not cached (children change).
	 */
	public function overlay($id, $imgW, $imgH, array $children, $maxPx = self::FULL_PX)
	{
		$im = $this->load($id);
		if (!$im) return null;
		$fileW = imagesx($im);
		$im = self::scaleTo($im, $maxPx);
		$w = imagesx($im); $h = imagesy($im);
		$appW = (float)$imgW > 0 ? (float)$imgW : $fileW;
		$ratio = $w / $appW;
		$drawn = 0;
		foreach ($children as $c) if (!empty($c['pixel'])) { $this->drawChild($im, $c, $ratio, $w, $h); $drawn++; }
		ob_start(); imagejpeg($im, null, 84); $data = ob_get_clean();
		imagedestroy($im);
		$this->generated++; $this->images++; $this->promoted++;
		if ($drawn) $this->overlays++;
		return array('data' => $data, 'w' => $w, 'h' => $h, 'drawn' => $drawn);
	}

	/** Promoted, full-width, no overlay (sketches, photos=full). */
	public function full($id, $maxPx = self::FULL_PX)
	{
		$t = $this->thumb($id, $maxPx);
		if ($t) $this->promoted++;
		return $t;
	}

	// ------------------------------------------------------------ decoding

	private function load($id)
	{
		$path = $this->path($id);
		if ($path === null) { $this->missing++; return null; }
		$size = @getimagesize($path);
		if (!$size || $size[0] * $size[1] > self::MAX_PIXELS) { $this->skipped++; return null; }
		$im = @imagecreatefromstring(file_get_contents($path));
		if (!$im) { $this->skipped++; return null; }
		$orientation = 1;
		if (function_exists('exif_read_data') && $size[2] === IMAGETYPE_JPEG) {
			$exif = @exif_read_data($path);
			if ($exif && isset($exif['Orientation'])) $orientation = (int)$exif['Orientation'];
		}
		return self::applyExif($im, $orientation);
	}

	/** Apply an EXIF orientation code (1-8) to a GD image; returns the (possibly new) image. */
	public static function applyExif($im, $orientation)
	{
		switch ((int)$orientation) {
			case 2: imageflip($im, IMG_FLIP_HORIZONTAL); return $im;
			case 3: $r = imagerotate($im, 180, 0); imagedestroy($im); return $r;
			case 4: imageflip($im, IMG_FLIP_VERTICAL); return $im;
			case 5: imageflip($im, IMG_FLIP_VERTICAL); $r = imagerotate($im, -90, 0); imagedestroy($im); return $r;
			case 6: $r = imagerotate($im, -90, 0); imagedestroy($im); return $r;
			case 7: imageflip($im, IMG_FLIP_HORIZONTAL); $r = imagerotate($im, -90, 0); imagedestroy($im); return $r;
			case 8: $r = imagerotate($im, 90, 0); imagedestroy($im); return $r;
			default: return $im;
		}
	}

	/** Scale so the longest side is <= $maxPx (never upscales); returns the (possibly new) image. */
	public static function scaleTo($im, $maxPx)
	{
		$w = imagesx($im); $h = imagesy($im);
		$long = max($w, $h);
		if ($long <= $maxPx) return $im;
		$nw = (int)max(1, round($w * $maxPx / $long)); $nh = (int)max(1, round($h * $maxPx / $long));
		$out = imagecreatetruecolor($nw, $nh);
		imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
		imagedestroy($im);
		return $out;
	}

	// ------------------------------------------------------------ overlay drawing

	/** Child spot => image pixels: the app stores x from the left and y from the BOTTOM of the image. */
	private function px(array $c, $ratio, $h) { return array($c[0] * $ratio, $h - $c[1] * $ratio); }

	private function drawChild($im, array $spot, $ratio, $w, $h)
	{
		$g = $spot['pixel'];
		$type = isset($g['type']) ? strtolower($g['type']) : '';
		$coords = isset($g['coordinates']) ? $g['coordinates'] : array();
		$name = isset($spot['name']) ? (string)$spot['name'] : '';
		$L = max(32, $w / 32);                 // symbol size in pixels (~5 mm at page width)
		$fs = $L * 0.6;                        // label size (~3 mm at page width)
		$black = imagecolorallocate($im, 0, 0, 0);
		$white = imagecolorallocate($im, 255, 255, 255);
		imagesetthickness($im, 1);
		imageantialias($im, true);
		if ($type === 'point' && count($coords) >= 2) {
			list($x, $y) = $this->px($coords, $ratio, $h);
			$ori = self::firstOrientation($spot);
			if ($ori && $ori['planar']) {
				$S = deg2rad($ori['a']);
				$dx = sin($S) * $L / 2; $dy = -cos($S) * $L / 2;          // strike bar (north up, y down)
				$tx = sin($S + M_PI / 2) * $L / 3; $ty = -cos($S + M_PI / 2) * $L / 3;   // dip tick
				$this->haloLine($im, $x - $dx, $y - $dy, $x + $dx, $y + $dy, $black, $white, 3);
				$this->haloLine($im, $x, $y, $x + $tx, $y + $ty, $black, $white, 3);
				$this->label($im, $x + $tx * 1.4, $y + $ty * 1.4, (string)round($ori['b']), $fs, $white, $black, true);
				$this->label($im, $x + $L * 0.6, $y - $L * 0.9, $name, $fs, $white, $black, false);
			} elseif ($ori) {
				$T = deg2rad($ori['a']);
				$dx = sin($T) * $L; $dy = -cos($T) * $L;
				$this->haloLine($im, $x, $y, $x + $dx, $y + $dy, $black, $white, 3);
				$this->arrowHead($im, $x + $dx, $y + $dy, $T, $L / 3, $black, $white);
				$this->label($im, $x + $dx * 1.15, $y + $dy * 1.15, (string)round($ori['b']), $fs, $white, $black, true);
				$this->label($im, $x + $L * 0.3, $y - $L * 0.9, $name, $fs, $white, $black, false);
			} else {
				$r = $L / 4;
				imagefilledellipse($im, (int)$x, (int)$y, (int)(2 * $r + 4), (int)(2 * $r + 4), $white);
				imagefilledellipse($im, (int)$x, (int)$y, (int)(2 * $r), (int)(2 * $r), $black);
				$this->label($im, $x + $r + 4, $y - $r - $fs * 0.5, $name, $fs, $white, $black, false);
			}
		} elseif (($type === 'linestring' || $type === 'multilinestring') && $coords) {
			$lines = $type === 'linestring' ? array($coords) : $coords;
			foreach ($lines as $line) {
				$pts = array();
				foreach ($line as $c) if (count($c) >= 2) $pts[] = $this->px($c, $ratio, $h);
				for ($i = 1; $i < count($pts); $i++) $this->haloLine($im, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $black, $white, 3);
				if (count($pts) >= 2) { $m = $pts[(int)floor(count($pts) / 2)]; $this->label($im, $m[0] + 4, $m[1] - $fs - 4, $name, $fs, $white, $black, false); }
			}
		} elseif (($type === 'polygon' || $type === 'multipolygon') && $coords) {
			$polys = $type === 'polygon' ? array($coords) : $coords;
			$fill = imagecolorallocatealpha($im, 129, 124, 215, 80);
			foreach ($polys as $rings) {
				if (!isset($rings[0])) continue;
				$flat = array(); $sx = 0; $sy = 0; $n = 0;
				foreach ($rings[0] as $c) { if (count($c) < 2) continue; $p = $this->px($c, $ratio, $h); $flat[] = $p[0]; $flat[] = $p[1]; $sx += $p[0]; $sy += $p[1]; $n++; }
				if ($n < 3) continue;
				imagefilledpolygon($im, array_map('intval', $flat), $n, $fill);
				imagesetthickness($im, 3); imagepolygon($im, array_map('intval', $flat), $n, $white);
				imagesetthickness($im, 1); imagepolygon($im, array_map('intval', $flat), $n, $black);
				$tw = $this->textWidth($name, $fs, true);
				$this->label($im, $sx / $n - $tw / 2, $sy / $n - $fs / 2, $name, $fs, $white, $black, true);
			}
		}
	}

	/** First orientation with numeric angles: ['planar' => bool, 'a' => strike|trend, 'b' => dip|plunge] or null. */
	public static function firstOrientation(array $spot)
	{
		foreach ((array)(isset($spot['orientations']) ? $spot['orientations'] : array()) as $o) {
			$a = isset($o['a']) ? trim((string)$o['a']) : ''; $b = isset($o['b']) ? trim((string)$o['b']) : '';
			if (!empty($o['planar']) && $a === '' && isset($o['dipdir']) && is_numeric($o['dipdir'])) $a = (string)((float)$o['dipdir'] - 90);
			if (is_numeric($a) && is_numeric($b)) return array('planar' => !empty($o['planar']), 'a' => fmod((float)$a + 360, 360), 'b' => (float)$b);
		}
		return null;
	}

	private function haloLine($im, $x1, $y1, $x2, $y2, $color, $halo, $thick)
	{
		imagesetthickness($im, $thick + 2); imageline($im, (int)$x1, (int)$y1, (int)$x2, (int)$y2, $halo);
		imagesetthickness($im, $thick); imageline($im, (int)$x1, (int)$y1, (int)$x2, (int)$y2, $color);
		imagesetthickness($im, 1);
	}

	private function arrowHead($im, $x, $y, $angle, $size, $color, $halo)
	{
		$dx = sin($angle); $dy = -cos($angle);
		$pts = array($x, $y, $x - $dx * $size - $dy * $size * 0.5, $y - $dy * $size + $dx * $size * 0.5, $x - $dx * $size + $dy * $size * 0.5, $y - $dy * $size - $dx * $size * 0.5);
		$pts = array_map('intval', $pts);
		imagesetthickness($im, 3); imagepolygon($im, $pts, 3, $halo);
		imagefilledpolygon($im, $pts, 3, $color);
		imagesetthickness($im, 1);
	}

	/** Text with a contrasting outline (legacy imagettfstroketext idiom). */
	private function label($im, $x, $y, $s, $size, $color, $stroke, $bold)
	{
		if ($s === '') return;
		$font = $bold ? $this->fontBold : $this->font;
		$x = (int)$x; $y = (int)$y + (int)$size;
		if (!$font) { imagestring($im, 3, $x, $y - 12, $s, $stroke); return; }
		for ($i = -2; $i <= 2; $i++) for ($j = -2; $j <= 2; $j++) if ($i || $j) imagettftext($im, $size, 0, $x + $i, $y + $j, $stroke, $font, $s);
		imagettftext($im, $size, 0, $x, $y, $color, $font, $s);
	}

	private function textWidth($s, $size, $bold)
	{
		$font = $bold ? $this->fontBold : $this->font;
		if ($font) { $b = imagettfbbox($size, 0, $font, $s); return abs($b[2] - $b[0]); }
		return strlen($s) * 7;
	}

	/** Colophon lines about the photos of this book. */
	public function notes()
	{
		if (!$this->enabled()) return array('Photos: listed without figures (option).');
		$n = array();
		$n[] = 'Photos: ' . $this->images . ' figure' . ($this->images === 1 ? '' : 's') . ' (' . ($this->mode === 'full' ? 'all full width' : 'contact sheets') . ', ' . $this->promoted . ' full width, ' . $this->overlays . ' image basemap' . ($this->overlays === 1 ? '' : 's') . ' with spots drawn on, ' . $this->generated . ' rendered, ' . $this->cacheHits . ' from cache).';
		if ($this->missing) $n[] = $this->missing . ' image file' . ($this->missing === 1 ? '' : 's') . ' not found on the server while building (listed with their details, no figure).';
		if ($this->skipped) $n[] = $this->skipped . ' image' . ($this->skipped === 1 ? '' : 's') . ' could not be decoded or exceeded the size limit (listed without a figure).';
		return $n;
	}
}
