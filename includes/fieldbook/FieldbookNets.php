<?php
/**
 * File: includes/fieldbook/FieldbookNets.php
 * Description: Stereonet figures of the enhanced fieldbook
 *              (docs/Fieldbook_Design.md §7, D4). Pure geometry, no PDF
 *              calls: equal-area (Schmidt) lower-hemisphere projection in
 *              the idiom of Allmendinger's Stereonet (right-hand-rule
 *              strike / dip for planes, trend / plunge for lines, planes as
 *              great circles + poles on spot nets, poles only on dataset
 *              nets unless few planes, lines as open symbols, symbols by
 *              feature type with a legend, n = count, omitted-measurement
 *              note). One instance per book so a symbol means the same
 *              thing on every net. Coordinates come out on the unit disc
 *              (x east, y north, radius 1); FieldbookRenderer scales them
 *              onto the page through FieldbookPdf's vector primitives.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/FieldbookProps.php';

class FieldbookNets
{
	const SAMPLES = 60;             // segments per great circle
	const CIRCLES_MAX = 20;         // dataset nets draw great circles only up to this many planes

	/** Marker shapes in assignment order (FieldbookPdf::vSymbol knows how to draw them). */
	public static $shapes = array('circle', 'square', 'triangle', 'diamond', 'tridown', 'pentagon', 'hexagon', 'star');
	/** Gray levels (0 = black) used once the shapes are exhausted for a kind. */
	public static $tones = array(0, 120, 180);

	/** Fixed symbol slots for the common feature types, so books agree with each other. */
	private static $planeSlots = array('bedding' => 'circle', 'foliation' => 'square', 'fault' => 'triangle', 'shear_zone' => 'diamond', 'joint' => 'tridown', 'fracture' => 'hexagon', 'vein' => 'pentagon', 'contact' => 'star');
	private static $lineSlots = array('fold_hinge' => 'circle', 'stretching' => 'square', 'mineral_align' => 'triangle', 'slickenlines' => 'diamond', 'intersection' => 'tridown', 'mineral_streak' => 'hexagon', 'groove_marks' => 'pentagon', 'boudin' => 'star');

	public $spotNets = 0;
	public $datasetNets = 0;
	public $plotted = 0;
	public $skipped = 0;

	private $registry = array();   // "P|feature" / "L|feature" => ['shape', 'tone', 'filled', 'label']

	// ------------------------------------------------------------ projection

	/** Equal-area, lower hemisphere: (trend, plunge) degrees => [x, y] on the unit disc (x east, y north). */
	public static function projectLine($trend, $plunge)
	{
		$plunge = max(0.0, min(90.0, (float)$plunge));
		$r = sqrt(2.0) * sin(deg2rad((90.0 - $plunge) / 2.0));
		$t = deg2rad(self::norm360($trend));
		return array($r * sin($t), $r * cos($t));
	}

	/** Pole of a plane (right-hand-rule strike, dip) => [trend, plunge]. */
	public static function pole($strike, $dip)
	{
		return array(self::norm360((float)$strike - 90.0), 90.0 - max(0.0, min(90.0, (float)$dip)));
	}

	/**
	 * Great circle of a plane as SAMPLES + 1 projected points from the strike end (trend S, plunge 0)
	 * through the dip vector to the opposite strike end.
	 */
	public static function greatCircle($strike, $dip, $samples = self::SAMPLES)
	{
		$S = deg2rad(self::norm360($strike)); $D = deg2rad(max(0.0, min(90.0, (float)$dip)));
		// unit vectors in (E, N, up)
		$s = array(sin($S), cos($S), 0.0);
		$d = array(sin($S + M_PI / 2) * cos($D), cos($S + M_PI / 2) * cos($D), -sin($D));
		$pts = array();
		for ($i = 0; $i <= $samples; $i++) {
			$a = M_PI * $i / $samples;
			$v = array(cos($a) * $s[0] + sin($a) * $d[0], cos($a) * $s[1] + sin($a) * $d[1], cos($a) * $s[2] + sin($a) * $d[2]);
			list($t, $p) = self::vectorToTrendPlunge($v);
			$pts[] = self::projectLine($t, $p);
		}
		return $pts;
	}

	/** (E, N, up) unit vector => [trend, plunge] on the lower hemisphere (the vector is flipped when it points up). */
	public static function vectorToTrendPlunge(array $v)
	{
		if ($v[2] > 1e-12) $v = array(-$v[0], -$v[1], -$v[2]);
		$plunge = rad2deg(asin(max(-1.0, min(1.0, -$v[2]))));
		$trend = (abs($v[0]) < 1e-12 && abs($v[1]) < 1e-12) ? 0.0 : self::norm360(rad2deg(atan2($v[0], $v[1])));
		return array($trend, $plunge);
	}

	public static function norm360($a)
	{
		$a = fmod((float)$a, 360.0);
		if ($a < 0) $a += 360.0;
		return $a;
	}

	// ------------------------------------------------------------ measurements

	/**
	 * Model orientation rows (FieldbookModel::orientationRow, children included) => plottable measurements
	 * + the count of rows without usable angles. A plane needs strike (or dip direction, right-hand rule
	 * strike = dip direction - 90) and a dip in [0, 90]; a line needs trend and plunge in [0, 90].
	 */
	public static function measurements(array $rows)
	{
		$out = array(); $skipped = 0;
		foreach ($rows as $r) {
			$m = self::measurement($r);
			if ($m) $out[] = $m; else $skipped++;
			foreach ((array)(isset($r['children']) ? $r['children'] : array()) as $c) {
				$cm = self::measurement($c);
				if ($cm) { $cm['associated'] = true; $out[] = $cm; } else $skipped++;
			}
		}
		return array($out, $skipped);
	}

	private static function measurement(array $r)
	{
		$feature = isset($r['feature']) ? (string)$r['feature'] : '';
		$a = isset($r['a']) ? trim((string)$r['a']) : '';
		$b = isset($r['b']) ? trim((string)$r['b']) : '';
		if (!empty($r['planar'])) {
			$dipdir = isset($r['dipdir']) ? trim((string)$r['dipdir']) : '';
			if ($a === '' && is_numeric($dipdir)) $a = (string)((float)$dipdir - 90.0);
			if (!is_numeric($a) || !is_numeric($b)) return null;
			$dip = (float)$b;
			if ($dip < 0 || $dip > 90) return null;
			return array('planar' => true, 'feature' => $feature, 'strike' => self::norm360($a), 'dip' => $dip, 'associated' => false);
		}
		if (!is_numeric($a) || !is_numeric($b)) return null;
		$plunge = (float)$b;
		if ($plunge < 0 || $plunge > 90) return null;
		return array('planar' => false, 'feature' => $feature, 'trend' => self::norm360($a), 'plunge' => $plunge, 'associated' => false);
	}

	// ------------------------------------------------------------ symbols

	/**
	 * Pre-register the feature types that have a fixed symbol slot (call once with every orientation row of
	 * the book) so the common types keep their book-to-book symbols whatever order the nets are built in.
	 */
	public function prime(array $rows)
	{
		list($ms) = self::measurements($rows);
		foreach ($ms as $m) {
			$slots = $m['planar'] ? self::$planeSlots : self::$lineSlots;
			if (isset($slots[strtolower(str_replace(' ', '_', $m['feature']))])) $this->symbol($m['planar'], $m['feature']);
		}
	}

	/** Book-wide symbol for a (kind, feature type): filled shapes for poles, open shapes for lines. */
	public function symbol($planar, $feature)
	{
		$key = ($planar ? 'P|' : 'L|') . $feature;
		if (isset($this->registry[$key])) return $this->registry[$key];
		$slots = $planar ? self::$planeSlots : self::$lineSlots;
		$token = strtolower(str_replace(' ', '_', $feature));
		$shape = null; $tone = 0;
		if (isset($slots[$token]) && !$this->taken($planar, $slots[$token], 0)) $shape = $slots[$token];
		if ($shape === null) {
			foreach (self::$tones as $tn) {
				foreach (self::$shapes as $sh) {
					if (!$this->taken($planar, $sh, $tn)) { $shape = $sh; $tone = $tn; break 2; }
				}
			}
			if ($shape === null) { $shape = 'circle'; $tone = 180; }   // beyond 24 types per kind: share
		}
		$label = $feature !== '' ? $feature : ($planar ? 'Plane' : 'Line');
		$this->registry[$key] = array('shape' => $shape, 'tone' => $tone, 'filled' => (bool)$planar, 'label' => $label, 'key' => $key);
		return $this->registry[$key];
	}

	private function taken($planar, $shape, $tone)
	{
		foreach ($this->registry as $k => $s) if ($s['filled'] === (bool)$planar && $s['shape'] === $shape && $s['tone'] === $tone) return true;
		return false;
	}

	// ------------------------------------------------------------ figures

	/**
	 * Build a net figure from measurements. $circles: true / false, or null = auto (spot nets always,
	 * dataset nets only when planes <= CIRCLES_MAX).
	 * Returns ['n', 'skipped', 'planes' => [{pole:[x,y], circle:[[x,y]...]|null, sym}], 'lines' => [{pt:[x,y], sym}],
	 *          'legend' => [{sym, label, count}], 'circles' => bool, 'kinds' => int]
	 */
	public function figure(array $measurements, $skipped, $circles = null, $dataset = false)
	{
		$planes = array(); $lines = array(); $counts = array(); $order = array();
		$nPlanes = 0;
		foreach ($measurements as $m) if ($m['planar']) $nPlanes++;
		if ($circles === null) $circles = !$dataset || $nPlanes <= self::CIRCLES_MAX;
		foreach ($measurements as $m) {
			$sym = $this->symbol($m['planar'], $m['feature']);
			if (!isset($counts[$sym['key']])) { $counts[$sym['key']] = 0; $order[] = $sym; }
			$counts[$sym['key']]++;
			if ($m['planar']) {
				list($t, $p) = self::pole($m['strike'], $m['dip']);
				$planes[] = array('pole' => self::projectLine($t, $p), 'circle' => $circles ? self::greatCircle($m['strike'], $m['dip']) : null, 'sym' => $sym, 'associated' => $m['associated']);
			} else {
				$lines[] = array('pt' => self::projectLine($m['trend'], $m['plunge']), 'sym' => $sym, 'associated' => $m['associated']);
			}
		}
		$legend = array();
		foreach ($order as $sym) $legend[] = array('sym' => $sym, 'label' => $sym['label'], 'count' => $counts[$sym['key']]);
		if ($dataset) $this->datasetNets++; else $this->spotNets++;
		return array('n' => count($measurements), 'skipped' => (int)$skipped, 'planes' => $planes, 'lines' => $lines, 'legend' => $legend, 'circles' => (bool)$circles, 'kinds' => count($order), 'dataset' => (bool)$dataset);
	}

	/**
	 * Dataset nets: the combined figure first, then one per feature type (symbol) with >= 2 measurements
	 * when the dataset holds more than one type. Returns [{title, fig}] or [] without plottable data.
	 */
	public function datasetFigures(array $rows)
	{
		list($ms, $skipped) = self::measurements($rows);
		$this->plotted += count($ms);   // every orientation belongs to exactly one dataset: book totals live here
		$this->skipped += $skipped;
		if (!$ms) return array();
		$out = array(array('title' => 'All measurements', 'fig' => $this->figure($ms, $skipped, null, true)));
		$groups = array(); $labels = array();
		foreach ($ms as $m) { $sym = $this->symbol($m['planar'], $m['feature']); $groups[$sym['key']][] = $m; $labels[$sym['key']] = $sym['label']; }
		if (count($groups) > 1) {
			uasort($groups, function ($a, $b) { return count($b) - count($a); });
			foreach ($groups as $k => $g) {
				if (count($g) < 2) continue;
				$title = $labels[$k];
				if ($title === 'Plane') $title = 'Planes without a feature type'; elseif ($title === 'Line') $title = 'Lines without a feature type';
				$out[] = array('title' => $title, 'fig' => $this->figure($g, 0, null, true));
			}
		}
		return $out;
	}

	/** Colophon lines about the stereonets of this book. */
	public function notes()
	{
		$n = array();
		$n[] = 'Stereonets: ' . $this->spotNets . ' spot net' . ($this->spotNets === 1 ? '' : 's') . ', ' . $this->datasetNets . ' dataset net' . ($this->datasetNets === 1 ? '' : 's')
			. ' (equal-area, lower hemisphere; planes by right-hand-rule strike and dip, plotted as great circles and poles; lines by trend and plunge, open symbols).';
		if ($this->skipped) $n[] = $this->skipped . ' orientation' . ($this->skipped === 1 ? '' : 's') . ' without usable angles (no strike / dip or trend / plunge) omitted from the nets; the tables still list them.';
		return $n;
	}
}
