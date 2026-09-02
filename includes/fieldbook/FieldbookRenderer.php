<?php
/**
 * File: includes/fieldbook/FieldbookRenderer.php
 * Description: tFPDF layout of a FieldbookModel (docs/Fieldbook_Design.md
 *              §4, §5). Cover, contents (rendered last, moved to the front,
 *              with links and outline bookmarks), project / dataset title
 *              pages when the book holds more than one, day sections with
 *              a numbered spot list and daily notes, spot blocks (designed
 *              header, notes, orientation table, samples, units, tags,
 *              photos list, generic "Other observations"), back matter
 *              (units, tags, samples, image index, colophon).
 *              Maps (M2), stereonets (M3, vector-drawn from FieldbookNets
 *              geometry: spot net beside the orientation table, dataset nets
 *              first in the Summary) and photos (M4: contact sheets, promoted
 *              full-width basemaps with the child spots drawn on and
 *              sketches, numbered photo index with page links) are in.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/FieldbookPdf.php';
require_once __DIR__ . '/FieldbookModel.php';
require_once __DIR__ . '/FieldbookMaps.php';
require_once __DIR__ . '/FieldbookNets.php';
require_once __DIR__ . '/FieldbookPhotos.php';

class FieldbookRenderer
{
	const LH = 4.2;      // body line height (mm)
	const LHS = 3.8;     // small line height
	const TOC_LH = 5.0;

	private $m;
	private $pdf;
	private $progress;
	private $toc = array();       // [{level, label, page, link, note}]
	private $coverPages = 1;
	private $spotsDone = 0;
	private $spotsTotal = 0;
	private $multiProject = false;
	private $multiDataset = false;
	private $maps = null;          // FieldbookMaps or null (map option "none")
	public $nets = null;           // FieldbookNets or null (nets option "off"); public for the suites' counters
	public $photos = null;         // FieldbookPhotos or null (photos option "none")
	public $photoIndex = array();  // [{no, title, spot, spotId, page, caption, details, link}] in book order
	private $photoNo = 0;
	const PHOTO_FULL_H = 120;      // max height (mm) of a promoted image
	const NET_W = 50;              // spot net box width (mm), beside the orientation table
	const NET_GRID_W = 55;         // dataset net box width (mm), three per row

	public function __construct(FieldbookModel $m, $progress = null, $maps = null, $photos = null)
	{
		$this->m = $m;
		$this->progress = is_callable($progress) ? $progress : null;
		$this->maps = ($maps instanceof FieldbookMaps && $maps->enabled()) ? $maps : null;
		$this->photos = ($photos instanceof FieldbookPhotos && $photos->enabled()) ? $photos : null;
		$opts = isset($m->meta['options']) ? $m->meta['options'] : array();
		if (!isset($opts['nets']) || $opts['nets'] !== 'off') {
			$this->nets = new FieldbookNets();
			$all = array();
			foreach ($m->projects as $p) foreach ($p['datasets'] as $ds) foreach (FieldbookModel::datasetOrientations($ds) as $o) $all[] = $o;
			$this->nets->prime($all);
		}
	}

	public function render()
	{
		$m = $this->m;
		$opts = $m->meta['options'];
		$format = (isset($opts['page']) && strtolower($opts['page']) === 'a4') ? 'A4' : 'Letter';
		$this->pdf = new FieldbookPdf($format);
		$pdf = $this->pdf;
		$pdf->SetTitle($m->meta['title'], true);
		$pdf->SetAuthor($m->meta['owner'], true);
		$pdf->SetCreator('StraboSpot fieldbook', true);
		$pdf->SetSubject('StraboSpot field book', true);
		$pdf->bookTitle = $m->meta['title'];

		$nd = 0;
		foreach ($m->projects as $p) $nd += count($p['datasets']);
		$this->multiProject = count($m->projects) > 1;
		$this->multiDataset = $nd > 1;
		$this->spotsTotal = $m->counts['spots'] + $m->counts['children'];

		$this->cover();
		$this->coverPages = $pdf->PageNo();

		foreach ($m->projects as $p) {
			$level = 0;
			if ($this->multiProject) { $this->titlePage('Project', $p['name'], $this->projectFacts($p), 0, array($p)); $level = 1; }
			foreach ($p['datasets'] as $ds) {
				$dlevel = $level;
				if ($this->multiDataset) { $this->titlePage('Dataset', $ds['name'], $this->datasetFacts($p, $ds), $level, array(array('datasets' => array($ds)))); $dlevel = $level + 1; }
				$pdf->sectionLabel = $this->multiDataset ? $ds['name'] : '';
				foreach ($ds['days'] as $day) $this->daySection($p, $ds, $day, $dlevel);
			}
		}
		$this->backMatter();
		$this->contents();
		return $pdf;
	}

	// ------------------------------------------------------------ cover

	private function cover()
	{
		$pdf = $this->pdf; $m = $this->m;
		$pdf->plainPage = true;
		$pdf->AddPage();
		$logo = $_SERVER['DOCUMENT_ROOT'] . '/assets/files/fieldbook.png';
		if (is_file($logo)) $pdf->Image($logo, $pdf->lm(), 16, 55);
		$pdf->SetY(72);
		$pdf->SetFont($pdf->head, 'B', 24);
		$pdf->MultiCell(0, 11, $m->meta['title'], 0, 'L');
		if ($m->meta['subtitle'] !== '') {
			$pdf->SetFont($pdf->head, '', 13);
			$pdf->SetTextColor(90, 90, 90);
			$pdf->MultiCell(0, 7, $m->meta['subtitle'], 0, 'L');
			$pdf->SetTextColor(0, 0, 0);
		}
		$pdf->Ln(4);
		$pdf->rule(120, 0.5);
		$pdf->Ln(6);
		$facts = $this->bookFacts();
		$this->kvBlock($facts, 46, 10, 5.6);
		// overview map between the facts and the colophon line (design §6)
		$top = $pdf->GetY() + 6;
		$avail = ($pdf->pageH() - 48) - $top;
		if ($avail >= 45) {
			$this->mapFigure($this->bookGeometry($m->projects), $pdf->lm(), $top, $pdf->innerW(), min($avail, $pdf->innerW() * 0.62), 768, false);
		}
		$pdf->SetY(-46);
		$pdf->rule(190, 0.2);
		$pdf->Ln(3);
		$pdf->SetFont($pdf->head, '', 8.5);
		$pdf->SetTextColor(90, 90, 90);
		$pdf->MultiCell(0, 4.5, 'Generated ' . $m->meta['generated'] . ' by StraboSpot (https://strabospot.org). ' . $this->citation(), 0, 'L');
		$pdf->SetTextColor(0, 0, 0);
		$pdf->plainPage = false;
	}

	private function bookFacts()
	{
		$m = $this->m;
		$rows = array();
		if ($m->meta['owner'] !== '') $rows[] = array('Owner', $m->meta['owner']);
		$pn = array(); $dn = array();
		foreach ($m->projects as $p) { $pn[] = $p['name']; foreach ($p['datasets'] as $d) $dn[] = $d['name']; }
		$rows[] = array(count($pn) > 1 ? 'Projects' : 'Project', implode('; ', $pn));
		$rows[] = array(count($dn) > 1 ? 'Datasets' : 'Dataset', implode('; ', $dn));
		$rows[] = array('Field days', $this->dateRangeLabel() . ' (' . $m->counts['days'] . ' ' . ($m->counts['days'] === 1 ? 'day' : 'days') . ')');
		$rows[] = array('Spots', $m->counts['spots'] . ($m->counts['children'] ? ' (+ ' . $m->counts['children'] . ' on image basemaps)' : ''));
		$rows[] = array('Orientation measurements', (string)$m->counts['orientations']);
		$rows[] = array('Samples', (string)$m->counts['samples']);
		$rows[] = array('Photos', (string)$m->counts['images']);
		if ($m->meta['doi'] !== '') $rows[] = array('DOI', $m->meta['doi']);
		return $rows;
	}

	private function projectFacts(array $p)
	{
		$spots = 0; $days = 0;
		foreach ($p['datasets'] as $d) { $spots += $d['spotCount']; $days += count($d['days']); }
		return array(array('Datasets', (string)count($p['datasets'])), array('Field days', (string)$days), array('Spots', (string)$spots));
	}

	private function datasetFacts(array $p, array $ds)
	{
		return array(array('Project', $p['name']), array('Field days', (string)count($ds['days'])), array('Spots', (string)$ds['spotCount']));
	}

	private function dateRangeLabel()
	{
		list($a, $b) = $this->m->dateRange;
		if ($a === null) return 'undated';
		$fa = $a === '0000-00-00' ? 'undated' : date('F j, Y', strtotime($a));
		$fb = $b === '0000-00-00' ? 'undated' : date('F j, Y', strtotime($b));
		return $fa === $fb ? $fa : "$fa to $fb";
	}

	private function citation()
	{
		$m = $this->m;
		$year = $m->dateRange[1] && $m->dateRange[1] !== '0000-00-00' ? substr($m->dateRange[1], 0, 4) : date('Y');
		$who = $m->meta['owner'] !== '' ? $m->meta['owner'] : 'StraboSpot user';
		$where = $m->meta['doi'] !== '' ? 'https://doi.org/' . $m->meta['doi'] : 'https://strabospot.org';
		return "Cite as: $who ($year). " . $m->meta['title'] . '. StraboSpot field data. ' . $where;
	}

	// ------------------------------------------------------------ title pages, days

	private function titlePage($kind, $name, array $facts, $level, array $scope = array())
	{
		$pdf = $this->pdf;
		$pdf->sectionLabel = $name;
		$pdf->AddPage();
		$pdf->Ln(30);
		$pdf->SetFont($pdf->head, '', 11);
		$pdf->SetTextColor(120, 120, 120);
		$pdf->Cell(0, 6, strtoupper($kind), 0, 1, 'L');
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont($pdf->head, 'B', 20);
		$pdf->MultiCell(0, 9, $name, 0, 'L');
		$pdf->Bookmark($name, $level, $pdf->GetY() - 12);
		$this->tocAdd($level, $name, $pdf->PageNo());
		$pdf->Ln(3);
		$pdf->rule(120, 0.4);
		$pdf->Ln(5);
		$this->kvBlock($facts, 40, 10, 5.6);
		$top = $pdf->GetY() + 6;
		$avail = ($pdf->pageH() - $pdf->bm()) - $top;
		if ($avail >= 45) $this->mapFigure($this->bookGeometry($scope), $pdf->lm(), $top, $pdf->innerW(), min($avail, $pdf->innerW() * 0.62), 768, false);
	}

	private function daySection(array $p, array $ds, array $day, $level)
	{
		$pdf = $this->pdf;
		$pdf->sectionLabel = ($this->multiDataset ? $ds['name'] . ' · ' : '') . $day['label'];
		$pdf->need(70);
		if ($pdf->GetY() > $pdf->tm() + 1) { $pdf->Ln(4); }
		$y = $pdf->GetY();
		$pdf->SetFont($pdf->head, 'B', 15);
		$pdf->Cell(0, 8, $day['label'], 0, 1, 'L');
		$pdf->Bookmark($day['label'], $level, $y);
		$this->tocAdd($level, $day['label'], $pdf->PageNo(), count($day['spots']) . ' ' . (count($day['spots']) === 1 ? 'spot' : 'spots'));
		$pdf->rule(60, 0.5);
		$pdf->Ln(3);

		$this->dayLocatorAndList($day['spots']);

		foreach ($day['notes'] as $n) {
			if (trim($n) === '') continue;
			$pdf->need(20);
			$this->subhead('Daily setup notes');
			$pdf->SetFont($pdf->body, '', 9);
			$pdf->SetFillColor(245, 245, 245);
			$pdf->MultiCell(0, self::LH, $n, 0, 'L', true);
			$pdf->Ln(2);
		}
		foreach ($day['spots'] as $s) {
			$this->spotBlock($s, 0);
		}
	}

	/** Located top-level spots of a scope (projects array): points + shapes for the maps. */
	private function bookGeometry(array $projects, $numbered = false)
	{
		$points = array(); $shapes = array();
		foreach ($projects as $p) foreach ($p['datasets'] as $ds) foreach ($ds['days'] as $day) $this->collectGeometry($day['spots'], $points, $shapes, $numbered);
		return array($points, $shapes);
	}

	private function collectGeometry(array $spots, array &$points, array &$shapes, $numbered)
	{
		foreach ($spots as $s) {
			if (!$s['point']) continue;
			$points[] = array($s['point'][0], $s['point'][1], $numbered ? (string)$s['n'] : '');
			if ($s['geometry'] && isset($s['geometry']['type']) && strtolower($s['geometry']['type']) !== 'point') {
				$shapes[] = array('type' => $s['geometry']['type'], 'coordinates' => $s['geometry']['coordinates']);
			}
		}
	}

	/**
	 * Draw a map figure at (x, y) sized w x h mm: window pixels = $winW wide, aspect from w/h.
	 * Returns the figure height used (0 when nothing drawn).
	 */
	private function mapFigure(array $geom, $x, $y, $w, $h, $winW, $optional)
	{
		if (!$this->maps) return 0;
		list($points, $shapes) = $geom;
		if (!$points && !$shapes) return 0;
		$pdf = $this->pdf;
		$winH = (int)round($winW * $h / $w);
		$fig = $this->maps->render($points, $shapes, $winW, $winH, $optional);
		if (!$fig) return 0;
		$pdf->GDImage($fig['im'], $x, $y, $w, $h);
		imagedestroy($fig['im']);
		$pdf->SetDrawColor(150, 150, 150); $pdf->SetLineWidth(0.25);
		$pdf->Rect($x, $y, $w, $h);
		$pdf->SetXY($x, $y + $h + 0.8);
		$pdf->SetFont($pdf->head, '', 6.5);
		$pdf->SetTextColor(120, 120, 120);
		$pdf->Cell($w, 3.2, $pdf->fit(($fig['fallback'] ? 'Basemap unavailable while building. ' : '') . $this->maps->attribution(), $w), 0, 1, 'R');
		$pdf->SetTextColor(0, 0, 0);
		return $h + 4;
	}

	/** Day section opener: locator map (left) with the numbered spot list beside it; list alone when no map. */
	private function dayLocatorAndList(array $spots)
	{
		$pdf = $this->pdf;
		$points = array(); $shapes = array();
		$this->collectGeometry($spots, $points, $shapes, true);
		$mapW = 78; $mapH = 60; $gap = 6;
		if (!$this->maps || (!$points && !$shapes)) { $this->spotList($spots, 2); return; }
		$pdf->need($mapH + 8);
		$y0 = $pdf->GetY();
		$used = $this->mapFigure(array($points, $shapes), $pdf->lm(), $y0, $mapW, $mapH, 512, true);
		if ($used === 0) { $this->spotList($spots, 2); return; }
		$page = $pdf->PageNo();
		$pdf->SetXY($pdf->lm() + $mapW + $gap, $y0);
		$listEnd = $this->spotList($spots, 1, $pdf->lm() + $mapW + $gap, $pdf->innerW() - $mapW - $gap);
		$pdf->SetXY($pdf->lm(), ($pdf->PageNo() === $page ? max($y0 + $used, $listEnd) : $listEnd) + 2);
	}

	/**
	 * Numbered list of the day's spots in $cols columns inside [$x, $x + $w], column-major per page; a list
	 * that overflows continues on the next page across the full width (2 columns). Returns the y after the list.
	 */
	private function spotList(array $spots, $cols = 2, $x = null, $w = null)
	{
		$pdf = $this->pdf;
		$pdf->SetFont($pdf->body, '', 8.5);
		if ($x === null) $x = $pdf->lm();
		if ($w === null) $w = $pdf->innerW();
		$n = count($spots);
		$bottom = $pdf->pageH() - $pdf->bm();
		$i = 0; $maxY = $pdf->GetY();
		while ($i < $n) {
			$y0 = $pdf->GetY();
			$fit = (int)floor(($bottom - $y0) / self::LHS);
			if ($fit < 3) { $pdf->AddPage(); $y0 = $pdf->GetY(); $fit = (int)floor(($bottom - $y0) / self::LHS); }
			$colW = ($w - 6 * ($cols - 1)) / $cols;
			$chunk = min($n - $i, $fit * $cols);
			$rows = (int)ceil($chunk / $cols);
			for ($j = 0; $j < $chunk; $j++) {
				$col = (int)floor($j / $rows); $row = $j - $col * $rows;
				$pdf->SetXY($x + $col * ($colW + 6), $y0 + $row * self::LHS);
				$s = $spots[$i + $j];
				$label = $s['n'] . '.  ' . $s['name'] . ($s['geomType'] !== '' ? ' (' . $s['geomType'] . ')' : '');
				$pdf->Cell($colW, self::LHS, $pdf->fit($label, $colW), 0, 0, 'L');
			}
			$maxY = $y0 + $rows * self::LHS;
			$i += $chunk;
			if ($i < $n) {
				$pdf->AddPage();
				$x = $pdf->lm(); $w = $pdf->innerW(); $cols = 2;   // continuation: full width
			}
		}
		$pdf->SetXY($pdf->lm(), $maxY + 3);
		return $maxY;
	}

	// ------------------------------------------------------------ spot block

	private function spotBlock(array $s, $indent)
	{
		$pdf = $this->pdf;
		$x0 = $pdf->lm() + $indent;
		$w = $pdf->innerW() - $indent;
		$pdf->need(32);
		$pdf->SetX($x0);
		$y = $pdf->GetY();
		if ($indent > 0) { $pdf->SetDrawColor(200, 200, 200); $pdf->SetLineWidth(0.6); $pdf->Line($x0 - 3, $y, $x0 - 3, $y + 8); }
		$pdf->SetFont($pdf->head, 'B', 12);
		$title = ($s['n'] ? $s['n'] . '.  ' : '') . $s['name'];
		if ($s['geomType'] !== '') $title .= '  (' . $s['geomType'] . ')';
		$pdf->MultiCell($w, 6, $title, 0, 'L');
		// meta line(s)
		$pdf->SetX($x0);
		$pdf->SetFont($pdf->head, '', 8);
		$pdf->SetTextColor(100, 100, 100);
		$bits = array();
		foreach ($s['meta'] as $r) $bits[] = $r[0] . ' ' . $r[1];
		foreach ($s['coords'] as $r) $bits[] = $r[0] . ' ' . $r[1];
		if ($s['orphan']) $bits[] = 'Image basemap not part of this book';
		if ($s['unassigned']) $bits[] = 'Dataset not identified';
		$pdf->MultiCell($w, 4, implode('   ·   ', $bits), 0, 'L');
		$pdf->SetTextColor(0, 0, 0);
		$pdf->Ln(1.5);

		if ($s['notes'] !== '') {
			$pdf->SetX($x0);
			$pdf->SetFont($pdf->body, '', 9);
			$pdf->MultiCell($w, self::LH, $s['notes'], 0, 'L');
			$pdf->Ln(1.5);
		}
		if ($s['orientations']) $this->orientationsWithNet($s['orientations'], $x0, $w);
		if ($s['samples']) $this->itemList('Samples', $s['samples'], $x0, $w);
		if ($s['units']) $this->tagLine('Geologic unit' . (count($s['units']) > 1 ? 's' : ''), $s['units'], $x0, $w);
		if ($s['tags']) $this->tagLine('Tags', $s['tags'], $x0, $w);
		if ($s['images']) $this->photoBlock($s, $x0, $w, $indent);
		if ($s['families']) $this->families($s['families'], $x0, $w);
		$pdf->Ln(3);
		$this->spotsDone++;
		if ($this->progress) {
			call_user_func($this->progress, 'format:fieldbook', $this->spotsDone, $this->spotsTotal, 'rendering spot ' . $this->spotsDone . ' of ' . $this->spotsTotal);
		}
	}

	private function subhead($text, $x = null)
	{
		$pdf = $this->pdf;
		if ($x !== null) $pdf->SetX($x);
		$pdf->SetFont($pdf->head, 'B', 8.5);
		$pdf->SetTextColor(60, 60, 60);
		$pdf->Cell(0, 4.5, strtoupper($text), 0, 1, 'L');
		$pdf->SetTextColor(0, 0, 0);
	}

	/** Orientation table with the spot stereonet (design §7) beside it when the spot has plottable measurements. */
	private function orientationsWithNet(array $rows, $x0, $w)
	{
		$pdf = $this->pdf;
		$ms = array(); $skipped = 0;
		if ($this->nets) list($ms, $skipped) = FieldbookNets::measurements($rows);
		if (!$ms) { $this->orientationTable($rows, $x0, $w, false); return; }
		$fig = $this->nets->figure($ms, $skipped);
		$netW = self::NET_W; $gap = 5;
		$h = $this->netHeight($fig, $netW, '');
		$pdf->need(min($h + 2, $pdf->pageH() - $pdf->tm() - $pdf->bm() - 2));
		$y0 = $pdf->GetY(); $page = $pdf->PageNo();
		$this->netFigure($fig, $x0 + $w - $netW, $y0, $netW, '');
		$pdf->SetXY($x0, $y0);
		$this->orientationTable($rows, $x0, $w - $netW - $gap, true);
		if ($pdf->PageNo() === $page && $pdf->GetY() < $y0 + $h + 1) $pdf->SetY($y0 + $h + 1);
	}

	/** $compact: narrower columns beside a stereonet; the notes move under their row. */
	private function orientationTable(array $rows, $x0, $w, $compact = false)
	{
		$pdf = $this->pdf;
		$pdf->need(16);
		$this->subhead('Orientations', $x0);
		if ($compact) {
			$cols = array(
				array('Type', 13, 'L'), array('Feature', 24, 'L'), array('Str. / Trend', 17, 'R'), array('Dip / Plunge', 17, 'R'),
				array('Dip dir.', 12, 'R'), array('Quality', 14, 'L'), array('Facing', 0, 'L'),
			);
		} else {
			$cols = array(
				array('Type', 16, 'L'), array('Feature', 28, 'L'), array('Strike / Trend', 20, 'R'), array('Dip / Plunge', 20, 'R'),
				array('Dip dir.', 14, 'R'), array('Quality', 18, 'L'), array('Facing', 16, 'L'), array('Notes', 0, 'L'),
			);
		}
		$flat = array();
		foreach ($rows as $r) {
			$flat[] = array($r, false);
			foreach ($r['children'] as $c) $flat[] = array($c, true);
		}
		$data = array();
		foreach ($flat as $pair) {
			list($r, $child) = $pair;
			$cells = array(($child ? '- ' : '') . $r['kind'], $r['feature'], $r['a'], $r['b'], $r['dipdir'], $r['quality'], $r['facing']);
			$more = $r['more'];
			if ($compact) { if ($r['notes'] !== '') array_unshift($more, array('k' => 'Notes', 'v' => $r['notes'], 'd' => 0, 'h' => false)); }
			else $cells[] = $r['notes'];
			$data[] = array('cells' => $cells, 'more' => $more);
		}
		$this->table($cols, $data, $x0, $w);
	}

	// ------------------------------------------------------------ stereonets (M3, design §7)

	/** Legend rows of a figure as [[symbol, text]] plus the layout (columns, row count) for a box $w wide. */
	private function netLegend(array $fig, $w)
	{
		$rows = array();
		foreach ($fig['legend'] as $l) $rows[] = array($l['sym'], $l['label'] . ($fig['kinds'] > 1 ? ' ' . $l['count'] : ''));
		if (count($rows) === 1 && in_array($fig['legend'][0]['label'], array('Plane', 'Line'), true)) $rows = array();
		$cols = (count($rows) > 3 && $w >= 48) ? 2 : 1;
		return array($rows, $cols, (int)ceil(count($rows) / $cols));
	}

	/** Total height (mm) netFigure will use for $fig in a box $w wide. */
	private function netHeight(array $fig, $w, $title)
	{
		$R = ($w - 10) / 2;
		list($rows, $cols, $nRows) = $this->netLegend($fig, $w);
		return ($title !== '' ? 4.5 : 0) + 5.0 + 2 * $R + 4.2 + $nRows * 3.2 + ($fig['skipped'] ? 3.2 : 0) + count($this->netFooter($fig)) * 3.2 + 1;
	}

	/** Projection / symbol convention lines under a net. */
	private function netFooter(array $fig)
	{
		$hasP = count($fig['planes']) > 0; $hasL = count($fig['lines']) > 0;
		$lines = array('Equal-area, lower hemisphere');
		if ($hasP && $hasL) $lines[] = 'Filled: poles to planes. Open: lines.';
		elseif ($hasP) $lines[] = $fig['circles'] ? 'Great circles and poles to planes' : 'Poles to planes';
		else $lines[] = 'Lines';
		return $lines;
	}

	/**
	 * Draw a stereonet figure (FieldbookNets::figure) in a box at (x, y) $w wide: optional title, N tick and
	 * label, great circles, primitive circle, centre cross, poles (filled) and lines (open), n = count, legend,
	 * omitted-measurement note, projection line. Returns the height used.
	 */
	private function netFigure(array $fig, $x, $y, $w, $title)
	{
		$pdf = $this->pdf;
		$pdf->SetAutoPageBreak(false, $pdf->bm());   // figures are placed at absolute positions after need(); a stray Cell must not open a page
		$top = $y;
		if ($title !== '') {
			$pdf->SetXY($x, $y);
			$pdf->SetFont($pdf->head, 'B', 8);
			$pdf->Cell($w, 4.5, $pdf->fit($title, $w), 0, 0, 'L');
			$y += 4.5;
		}
		$R = ($w - 10) / 2;
		$cx = $x + $w / 2; $cy = $y + 5.0 + $R;
		$sx = function ($p) use ($cx, $cy, $R) { return array($cx + $p[0] * $R, $cy - $p[1] * $R); };
		// great circles
		$pdf->SetDrawColor(140, 140, 140); $pdf->SetLineWidth(0.18);
		foreach ($fig['planes'] as $pl) {
			if (!$pl['circle']) continue;
			$pts = array();
			foreach ($pl['circle'] as $p) $pts[] = $sx($p);
			$pdf->vPolyline($pts);
		}
		// primitive, ticks, centre
		$pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.35);
		$pdf->vCircle($cx, $cy, $R);
		$pdf->SetLineWidth(0.25);
		$pdf->Line($cx, $cy - $R, $cx, $cy - $R - 1.8);
		$pdf->Line($cx + $R, $cy, $cx + $R + 1.0, $cy);
		$pdf->Line($cx, $cy + $R, $cx, $cy + $R + 1.0);
		$pdf->Line($cx - $R, $cy, $cx - $R - 1.0, $cy);
		$pdf->SetLineWidth(0.2);
		$pdf->Line($cx - 0.9, $cy, $cx + 0.9, $cy);
		$pdf->Line($cx, $cy - 0.9, $cx, $cy + 0.9);
		$pdf->SetFont($pdf->head, 'B', 7);
		$pdf->SetXY($cx - 3, $cy - $R - 5.3);
		$pdf->Cell(6, 3.5, 'N', 0, 0, 'C');
		// symbols
		$n = $fig['n'];
		$size = $n <= 60 ? 1.7 : ($n <= 300 ? 1.2 : 0.9);
		$pdf->SetLineWidth(0.15);
		foreach ($fig['planes'] as $pl) $this->netSymbol($pl['sym'], $sx($pl['pole']), $size);
		foreach ($fig['lines'] as $ln) $this->netSymbol($ln['sym'], $sx($ln['pt']), $size);
		$pdf->SetDrawColor(0, 0, 0); $pdf->SetFillColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0);
		// n
		$yy = $cy + $R + 1.0;
		$pdf->SetFont($pdf->head, '', 7);
		$pdf->SetXY($x, $yy);
		$pdf->Cell($w, 3.2, 'n = ' . $n, 0, 0, 'R');
		$yy += 4.2;
		// legend
		list($rows, $cols, $nRows) = $this->netLegend($fig, $w);
		if ($rows) {
			$colW = $w / $cols;
			$pdf->SetFont($pdf->head, '', 6.5);
			foreach ($rows as $i => $row) {
				$c = (int)floor($i / $nRows); $r = $i - $c * $nRows;
				$lx = $x + $c * $colW; $ly = $yy + $r * 3.2;
				$this->netSymbol($row[0], array($lx + 1.6, $ly + 1.6), 1.7);
				$pdf->SetDrawColor(0, 0, 0); $pdf->SetFillColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0);
				$pdf->SetXY($lx + 3.6, $ly);
				$pdf->Cell($colW - 3.6, 3.2, $pdf->fit($row[1], $colW - 3.8), 0, 0, 'L');
			}
			$yy += $nRows * 3.2;
		}
		$pdf->SetFont($pdf->head, '', 6.5);
		$pdf->SetTextColor(120, 120, 120);
		if ($fig['skipped']) {
			$pdf->SetXY($x, $yy);
			$pdf->Cell($w, 3.2, $pdf->fit($fig['skipped'] . ' without angles omitted', $w), 0, 0, 'L');
			$yy += 3.2;
		}
		$pdf->SetXY($x, $yy);
		foreach ($this->netFooter($fig) as $line) {
			$pdf->SetXY($x, $yy);
			$pdf->Cell($w, 3.2, $pdf->fit($line, $w), 0, 0, 'L');
			$yy += 3.2;
		}
		$yy += 1;
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetXY($x, $yy);
		$pdf->SetAutoPageBreak(true, $pdf->bm());
		return $yy - $top;
	}

	private function netSymbol(array $sym, array $at, $size)
	{
		$pdf = $this->pdf;
		$t = $sym['tone'];
		if ($sym['filled']) { $pdf->SetFillColor($t, $t, $t); $pdf->SetDrawColor(0, 0, 0); }
		else { $pdf->SetDrawColor($t, $t, $t); }
		$pdf->vSymbol($sym['shape'], $at[0], $at[1], $size, $sym['filled']);
	}

	/** Summary: one row of figures per dataset (combined + per feature type), three per row. */
	private function datasetNets()
	{
		if (!$this->nets) return;
		$pdf = $this->pdf; $m = $this->m;
		$blocks = array();
		foreach ($m->projects as $p) foreach ($p['datasets'] as $ds) {
			$figs = $this->nets->datasetFigures(FieldbookModel::datasetOrientations($ds));
			if ($figs) $blocks[] = array('label' => ($this->multiProject ? $p['name'] . ' · ' : '') . $ds['name'], 'figs' => $figs);
		}
		if (!$blocks) return;
		$this->summaryHead('Stereonets');
		$w = $pdf->innerW(); $bw = self::NET_GRID_W; $per = max(1, (int)floor(($w + 6) / ($bw + 6)));
		$gap = $per > 1 ? ($w - $per * $bw) / ($per - 1) : 0;
		foreach ($blocks as $b) {
			if ($this->multiDataset) { $pdf->need(30); $this->subhead($b['label']); $pdf->Ln(1); }
			$figs = $b['figs'];
			for ($i = 0; $i < count($figs); $i += $per) {
				$row = array_slice($figs, $i, $per);
				$hMax = 0;
				foreach ($row as $f) $hMax = max($hMax, $this->netHeight($f['fig'], $bw, $f['title']));
				$pdf->need($hMax + 2);
				$y0 = $pdf->GetY();
				foreach ($row as $j => $f) $this->netFigure($f['fig'], $pdf->lm() + $j * ($bw + $gap), $y0, $bw, $f['title']);
				$pdf->SetXY($pdf->lm(), $y0 + $hMax + 3);
			}
			if (count($figs) > 1) {
				$pdf->SetFont($pdf->head, '', 6.5); $pdf->SetTextColor(120, 120, 120);
				$pdf->Cell(0, 3.2, 'Feature types with a single measurement appear on the combined net only.', 0, 1, 'L');
				$pdf->SetTextColor(0, 0, 0);
			}
			$pdf->Ln(2);
		}
	}

	/**
	 * Generic table: $cols = [[label, width mm (0 = rest), align]], $data = [{cells:[], more:[rows]}].
	 * Header repeats after a page break; "more" rows print beneath their row in small type.
	 */
	private function table(array $cols, array $data, $x0, $w)
	{
		$pdf = $this->pdf;
		$fixed = 0; $rest = -1;
		foreach ($cols as $i => $c) { if ($c[1] > 0) $fixed += $c[1]; else $rest = $i; }
		if ($rest >= 0) $cols[$rest][1] = max(20, $w - $fixed);
		$header = function () use ($pdf, $cols, $x0) {
			$pdf->SetX($x0);
			$pdf->SetFont($pdf->head, 'B', 7.5);
			$pdf->SetFillColor(235, 235, 235);
			foreach ($cols as $c) $pdf->Cell($c[1], 4.5, $c[0], 0, 0, $c[2], true);
			$pdf->Ln(4.5);
		};
		$header();
		$pdf->SetFont($pdf->body, '', 8.5);
		$lh = self::LHS;
		foreach ($data as $row) {
			$pdf->SetFont($pdf->body, '', 8.5);
			$nb = 1;
			foreach ($cols as $i => $c) $nb = max($nb, $pdf->nbLines($c[1], isset($row['cells'][$i]) ? $row['cells'][$i] : ''));
			$h = $nb * $lh;
			$moreTxt = '';
			if (!empty($row['more'])) {
				$bits = array();
				foreach ($row['more'] as $mr) $bits[] = $mr['k'] . ($mr['v'] !== '' ? ': ' . $mr['v'] : '');
				$moreTxt = implode(';  ', $bits);
			}
			$moreH = $moreTxt !== '' ? $pdf->nbLines($w - 4, $moreTxt) * 3.4 + 1 : 0;
			if ($pdf->GetY() + $h + $moreH > $pdf->pageH() - $pdf->bm()) { $pdf->AddPage(); $header(); $pdf->SetFont($pdf->body, '', 8.5); }
			$y = $pdf->GetY(); $x = $x0;
			foreach ($cols as $i => $c) {
				$pdf->SetXY($x, $y);
				$pdf->MultiCell($c[1], $lh, isset($row['cells'][$i]) ? $row['cells'][$i] : '', 0, $c[2]);
				$x += $c[1];
			}
			$pdf->SetXY($x0, $y + $h);
			if (!empty($row['link'])) $pdf->Link($x0, $y, $w, $h, $row['link']);
			if ($moreTxt !== '') {
				$pdf->SetX($x0 + 4);
				$pdf->SetFont($pdf->body, '', 7.5);
				$pdf->SetTextColor(80, 80, 80);
				$pdf->MultiCell($w - 4, 3.4, $moreTxt, 0, 'L');
				$pdf->SetTextColor(0, 0, 0);
				$pdf->Ln(1);
			}
			$pdf->SetDrawColor(225, 225, 225); $pdf->SetLineWidth(0.15);
			$pdf->Line($x0, $pdf->GetY(), $x0 + $w, $pdf->GetY());
		}
		$pdf->Ln(2.5);
	}

	/** Titled items with key/value rows (samples). */
	private function itemList($title, array $items, $x0, $w)
	{
		$pdf = $this->pdf;
		$pdf->need(14);
		$this->subhead($title, $x0);
		foreach ($items as $it) {
			$pdf->need(10);
			$pdf->SetX($x0);
			$pdf->SetFont($pdf->head, 'B', 9);
			$pdf->MultiCell($w, 4.5, $it['title'], 0, 'L');
			$this->kvRows($it['rows'], $x0 + 3, $w - 3);
			$pdf->Ln(1);
		}
		$pdf->Ln(1);
	}

	/** Units / tags: name (type) on one line, extra fields beneath. */
	private function tagLine($title, array $items, $x0, $w)
	{
		$pdf = $this->pdf;
		$pdf->need(10);
		$this->subhead($title, $x0);
		foreach ($items as $it) {
			$pdf->need(8);
			$pdf->SetX($x0);
			$pdf->SetFont($pdf->head, 'B', 9);
			$label = $it['name'];
			if ($it['type'] !== '' && $it['type'] !== 'geologic_unit') $label .= '  (' . FieldbookProps::humanize($it['type']) . ')';
			$pdf->MultiCell($w, 4.5, $label, 0, 'L');
			if ($it['rows']) $this->kvRows($it['rows'], $x0 + 3, $w - 3);
		}
		$pdf->Ln(1.5);
	}

	/** M1 photo listing (attributes only; sheets arrive in M4), with child spots nested under their image. */
	// ------------------------------------------------------------ photos (M4, design §8)

	/** Photos of a spot: contact sheets, promoted full-width figures (basemaps with children, sketches, photos=full), or the text list without figures. */
	private function photoBlock(array $s, $x0, $w, $indent)
	{
		$pdf = $this->pdf;
		if (!$this->photos) { $this->imageList($s['images'], $x0, $w, $indent, $s); return; }
		$pdf->need(30);
		$this->subhead('Photos (' . count($s['images']) . ')', $x0);
		// the spot's own photos as a sheet first, then the promoted figures (basemaps with their nested child spots, sketches)
		$sheet = array(); $promoted = array();
		foreach ($s['images'] as $img) {
			if ($this->photos->mode === 'full' || !empty($img['children']) || strtolower($img['type']) === 'sketch') $promoted[] = $img; else $sheet[] = $img;
		}
		$this->contactSheet($sheet, $s, $x0, $w);
		foreach ($promoted as $img) $this->promotedFigure($img, $s, $x0, $w, $indent);
		$pdf->Ln(1);
	}

	/** Image title for labels and the index. */
	private function photoTitle(array $img) { return $img['title'] !== '' ? $img['title'] : 'Image ' . $img['id']; }

	/** Short attribute line under a figure; the full details go to the index. */
	private function photoMeta(array $img)
	{
		$bits = array('id ' . $img['id']);
		if ($img['width'] && $img['height']) $bits[] = $img['width'] . ' x ' . $img['height'];
		if ($img['type'] !== '' && strtolower($img['type']) !== 'photo') $bits[] = FieldbookProps::humanize($img['type']);
		if ($img['annotated']) $bits[] = 'annotated';
		return implode(' · ', $bits);
	}

	/** Everything stored with the image, for the index. */
	private function photoDetails(array $img)
	{
		$bits = array('id ' . $img['id']);
		if ($img['width'] && $img['height']) $bits[] = $img['width'] . ' x ' . $img['height'];
		if ($img['type'] !== '') $bits[] = FieldbookProps::humanize($img['type']);
		$bits[] = $img['annotated'] ? 'annotated' : 'not annotated';
		foreach ($img['rows'] as $r) if (!$r['h']) $bits[] = $r['k'] . ($r['v'] !== '' ? ': ' . $r['v'] : '');
		if (!empty($img['children'])) $bits[] = count($img['children']) . ' spot' . (count($img['children']) === 1 ? '' : 's') . ' drawn on it';
		return implode(';  ', $bits);
	}

	private function indexPhoto($no, array $img, array $spot, $y)
	{
		$pdf = $this->pdf;
		$link = $pdf->AddLink();
		$pdf->SetLink($link, max(0, $y - 4), $pdf->PageNo());
		$this->photoIndex[] = array('no' => $no, 'title' => $this->photoTitle($img), 'spot' => $spot['name'], 'spotId' => $spot['id'], 'page' => $pdf->PageNo(), 'caption' => $img['caption'], 'details' => $this->photoDetails($img), 'link' => $link);
	}

	/** Word-wrap $text into at most $max lines of width $w for the CURRENT font; the last line is ellipsised when cut. */
	private function wrapLines($text, $w, $max)
	{
		$pdf = $this->pdf;
		$text = trim(preg_replace('/\s+/u', ' ', (string)$text));
		if ($text === '') return array();
		$words = explode(' ', $text);
		$lines = array(); $cur = ''; $i = 0;
		for (; $i < count($words); $i++) {
			$try = $cur === '' ? $words[$i] : $cur . ' ' . $words[$i];
			if ($pdf->GetStringWidth($try) <= $w) { $cur = $try; continue; }
			if ($cur === '') { $cur = $pdf->fit($words[$i], $w); continue; }
			$lines[] = $cur; $cur = $words[$i];
			if (count($lines) === $max) break;
		}
		if (count($lines) < $max) { if ($cur !== '') $lines[] = $cur; }
		else { $rest = implode(' ', array_slice($words, $i)); $lines[$max - 1] = $pdf->fit($lines[$max - 1] . ' ' . $rest . ' ', $w - 1); }
		return $lines;
	}

	/** Place a thumbnail (['data', 'w', 'h'] or null) fitted inside the box, top-aligned and centred; a placeholder when null. */
	private function placeImage($t, $x, $y, $boxW, $boxH, $id)
	{
		$pdf = $this->pdf;
		if ($t) {
			$scale = min($boxW / $t['w'], $boxH / $t['h']);
			$fw = $t['w'] * $scale; $fh = $t['h'] * $scale;
			$fx = $x + ($boxW - $fw) / 2;
			$pdf->MemImage($t['data'], $fx, $y, $fw, $fh);
			$pdf->SetDrawColor(200, 200, 200); $pdf->SetLineWidth(0.15);
			$pdf->Rect($fx, $y, $fw, $fh);
			return $fh;
		}
		$pdf->SetFillColor(240, 240, 240); $pdf->SetDrawColor(200, 200, 200); $pdf->SetLineWidth(0.15);
		$pdf->Rect($x, $y, $boxW, $boxH, 'DF');
		$pdf->SetFont($pdf->head, 'I', 7); $pdf->SetTextColor(130, 130, 130);
		$pdf->SetXY($x, $y + $boxH / 2 - 4);
		$pdf->Cell($boxW, 3.5, 'Image unavailable', 0, 2, 'C');
		$pdf->Cell($boxW, 3.5, $pdf->fit('id ' . $id, $boxW), 0, 0, 'C');
		$pdf->SetTextColor(0, 0, 0);
		return $boxH;
	}

	/** Contact sheet: rows of three cells (two when the block is narrow), a row never splits across pages. */
	private function contactSheet(array $images, array $spot, $x0, $w)
	{
		if (!$images) return;
		$pdf = $this->pdf;
		$cols = $w >= 150 ? 3 : 2; $gap = 4;
		$cw = ($w - $gap * ($cols - 1)) / $cols; $boxH = $cw * 0.75;
		for ($i = 0; $i < count($images); $i += $cols) {
			$row = array_slice($images, $i, $cols);
			$cells = array(); $capMax = 0;
			$pdf->SetFont($pdf->body, '', 7);
			foreach ($row as $img) {
				$cap = $this->wrapLines($img['caption'], $cw - 1, 2);
				$capMax = max($capMax, count($cap));
				$cells[] = array('img' => $img, 'cap' => $cap);
			}
			$rowH = $boxH + 1 + 3.4 + $capMax * 3.2 + 3.0 + 2.5;
			$pdf->need($rowH);
			$y0 = $pdf->GetY();
			$pdf->SetAutoPageBreak(false, $pdf->bm());
			foreach ($cells as $j => $c) {
				$img = $c['img'];
				$no = ++$this->photoNo;
				$x = $x0 + $j * ($cw + $gap);
				$this->placeImage($this->photos->thumb($img['id']), $x, $y0, $cw, $boxH, $img['id']);
				$yy = $y0 + $boxH + 1;
				$pdf->SetFont($pdf->head, 'B', 7.5);
				$pdf->SetXY($x, $yy);
				$pdf->Cell($cw, 3.4, $pdf->fit('[' . $no . ']  ' . $this->photoTitle($img), $cw), 0, 0, 'L');
				$yy += 3.4;
				$pdf->SetFont($pdf->body, '', 7);
				foreach ($c['cap'] as $line) { $pdf->SetXY($x, $yy); $pdf->Cell($cw, 3.2, $line, 0, 0, 'L'); $yy += 3.2; }
				$pdf->SetFont($pdf->head, '', 6.5); $pdf->SetTextColor(120, 120, 120);
				$pdf->SetXY($x, $y0 + $boxH + 1 + 3.4 + $capMax * 3.2);
				$pdf->Cell($cw, 3.0, $pdf->fit($this->photoMeta($img), $cw), 0, 0, 'L');
				$pdf->SetTextColor(0, 0, 0);
				$this->indexPhoto($no, $img, $spot, $y0);
			}
			$pdf->SetAutoPageBreak(true, $pdf->bm());
			$pdf->SetXY($x0, $y0 + $rowH);
		}
	}

	/** Full-width figure: image basemap with its child spots drawn on (then the children as nested blocks), a sketch, or any image with photos=full. */
	private function promotedFigure(array $img, array $spot, $x0, $w, $indent)
	{
		$pdf = $this->pdf;
		$no = ++$this->photoNo;
		$fig = !empty($img['children']) ? $this->photos->overlay($img['id'], $img['width'], $img['height'], $img['children']) : $this->photos->full($img['id']);
		if ($fig) {
			$fw = $w; $fh = $w * $fig['h'] / $fig['w'];
			if ($fh > self::PHOTO_FULL_H) { $fh = self::PHOTO_FULL_H; $fw = $fh * $fig['w'] / $fig['h']; }
		} else { $fw = $w; $fh = 26; }
		// use the rest of the page when a reasonable figure still fits there, else start a new page
		$avail = $pdf->pageH() - $pdf->bm() - $pdf->GetY() - 16;
		if ($fig && $fh > $avail && $avail >= 70) { $fh = $avail; $fw = $fh * $fig['w'] / $fig['h']; }
		$pdf->need(min($fh + 14, $pdf->pageH() - $pdf->tm() - $pdf->bm() - 2));
		$y0 = $pdf->GetY();
		$pdf->SetAutoPageBreak(false, $pdf->bm());
		$this->placeImage($fig, $x0, $y0, $fw, $fh, $img['id']);
		$pdf->SetAutoPageBreak(true, $pdf->bm());
		$yy = $y0 + $fh + 1;
		$pdf->SetXY($x0, $yy);
		$pdf->SetFont($pdf->head, 'B', 8.5);
		$pdf->MultiCell($w, 4, '[' . $no . ']  ' . $this->photoTitle($img), 0, 'L');
		if ($img['caption'] !== '') {
			$pdf->SetX($x0);
			$pdf->SetFont($pdf->body, '', 8);
			$pdf->MultiCell($w, self::LHS, $img['caption'], 0, 'L');
		}
		$pdf->SetX($x0);
		$pdf->SetFont($pdf->head, '', 6.5); $pdf->SetTextColor(120, 120, 120);
		$meta = $this->photoMeta($img);
		if (!empty($img['children'])) $meta .= ' · ' . count($img['children']) . ' spot' . (count($img['children']) === 1 ? '' : 's') . ' drawn on this image' . ($fig && empty($fig['drawn']) ? ' (positions not available)' : '');
		$pdf->MultiCell($w, 3.2, $meta, 0, 'L');
		$pdf->SetTextColor(0, 0, 0);
		$this->indexPhoto($no, $img, $spot, $y0);
		$pdf->Ln(1.5);
		if (!empty($img['children'])) {
			$pdf->SetX($x0 + 3);
			$pdf->SetFont($pdf->head, 'I', 8);
			$pdf->SetTextColor(100, 100, 100);
			$pdf->Cell($w - 3, 4, 'Spots drawn on image [' . $no . ']:', 0, 1, 'L');
			$pdf->SetTextColor(0, 0, 0);
			foreach ($img['children'] as $c) $this->spotBlock($c, $indent + 8);
		}
	}

	/** photos=none: the M1 text list (numbered, indexed), children nested as before. */
	private function imageList(array $images, $x0, $w, $indent, array $spot)
	{
		$pdf = $this->pdf;
		$pdf->need(10);
		$this->subhead('Photos (' . count($images) . ')', $x0);
		foreach ($images as $img) {
			$pdf->need(8);
			$no = ++$this->photoNo;
			$pdf->SetX($x0);
			$y = $pdf->GetY();
			$pdf->SetFont($pdf->head, 'B', 9);
			$label = '[' . $no . ']  ' . $this->photoTitle($img);
			$bits = array();
			if ($img['type'] !== '') $bits[] = FieldbookProps::humanize($img['type']);
			if ($img['annotated']) $bits[] = 'annotated';
			if ($img['width'] && $img['height']) $bits[] = $img['width'] . ' x ' . $img['height'];
			$bits[] = 'id ' . $img['id'];
			$pdf->MultiCell($w, 4.5, $label . '   ' . implode(' · ', $bits), 0, 'L');
			if ($img['caption'] !== '') {
				$pdf->SetX($x0 + 3);
				$pdf->SetFont($pdf->body, '', 8.5);
				$pdf->MultiCell($w - 3, self::LHS, $img['caption'], 0, 'L');
			}
			if ($img['rows']) $this->kvRows($img['rows'], $x0 + 3, $w - 3);
			$this->indexPhoto($no, $img, $spot, $y);
			if ($img['children']) {
				$pdf->need(40);
				$pdf->SetX($x0 + 3);
				$pdf->SetFont($pdf->head, 'I', 8);
				$pdf->SetTextColor(100, 100, 100);
				$pdf->Cell($w - 3, 4, count($img['children']) . ' spot' . (count($img['children']) > 1 ? 's' : '') . ' drawn on image [' . $no . ']:', 0, 1, 'L');
				$pdf->SetTextColor(0, 0, 0);
				foreach ($img['children'] as $c) $this->spotBlock($c, $indent + 8);
			}
		}
		$pdf->Ln(1);
	}

	/** Generic "Other observations" families. */
	private function families(array $fams, $x0, $w)
	{
		$pdf = $this->pdf;
		$pdf->need(min(12 + $this->kvEstimate($fams[0]['rows']), 36));   // heading + the first family travel together
		$this->subhead('Other observations', $x0);
		foreach ($fams as $fam) {
			$pdf->need(min(6 + $this->kvEstimate($fam['rows']), 30));
			$pdf->SetX($x0);
			$pdf->SetFont($pdf->head, 'B', 9);
			$pdf->Cell($w, 4.5, $fam['label'], 0, 1, 'L');
			$this->kvRows($fam['rows'], $x0 + 3, $w - 3);
			$pdf->Ln(1);
		}
	}

	/** Key/value rows with depth indentation; heading rows in bold. */
	private function kvRows(array $rows, $x0, $w)
	{
		$pdf = $this->pdf;
		$labW = min(52, $w * 0.36);
		// keep a short block (or the first rows of a long one) with its heading: no one-line widows
		$pdf->need(min($this->kvEstimate($rows), 20));
		foreach ($rows as $i => $r) {
			$ind = 3 * (int)$r['d'];
			$x = $x0 + $ind;
			if ($r['h']) {
				$pdf->need(min(3.8 + $this->kvEstimate(array_slice($rows, $i + 1)), 20));
				$pdf->SetX($x);
				$pdf->SetFont($pdf->head, 'B', 8);
				$pdf->SetTextColor(60, 60, 60);
				$pdf->MultiCell($w - $ind, 3.8, $r['k'], 0, 'L');
				$pdf->SetTextColor(0, 0, 0);
				continue;
			}
			$pdf->SetFont($pdf->body, '', 8.5);
			$vw = $w - $ind - $labW;
			$nb = max($pdf->nbLines($labW, $r['k']), $pdf->nbLines($vw, $r['v']));
			$h = $nb * self::LHS;
			if ($pdf->GetY() + $h > $pdf->pageH() - $pdf->bm()) $pdf->AddPage();
			$y = $pdf->GetY();
			$pdf->SetXY($x, $y);
			$pdf->SetTextColor(110, 110, 110);
			$pdf->MultiCell($labW, self::LHS, $r['k'], 0, 'L');
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetXY($x + $labW, $y);
			$pdf->MultiCell($vw, self::LHS, $r['v'], 0, 'L');
			$pdf->SetXY($x0, $y + $h);
		}
	}

	/** Rough height (mm) of key/value rows up to the next heading row: one line per row. */
	private function kvEstimate(array $rows)
	{
		$est = 0;
		foreach ($rows as $i => $r) { if ($i > 0 && $r['h']) break; $est += $r['h'] ? 3.8 : self::LHS; }
		return $est;
	}

	/** Two-column facts block (cover / title pages). */
	private function kvBlock(array $rows, $labW, $size, $lh)
	{
		$pdf = $this->pdf;
		foreach ($rows as $r) {
			$pdf->SetFont($pdf->head, '', $size);
			$pdf->SetTextColor(110, 110, 110);
			$y = $pdf->GetY();
			$pdf->Cell($labW, $lh, $r[0], 0, 0, 'L');
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont($pdf->head, 'B', $size);
			$pdf->MultiCell($pdf->innerW() - $labW, $lh, $r[1], 0, 'L');
		}
	}

	// ------------------------------------------------------------ back matter

	private function backMatter()
	{
		$pdf = $this->pdf; $m = $this->m;
		$pdf->sectionLabel = 'Summary';
		$pdf->AddPage();
		$y = $pdf->GetY();
		$pdf->SetFont($pdf->head, 'B', 18);
		$pdf->Cell(0, 9, 'Summary', 0, 1, 'L');
		$pdf->Bookmark('Summary', 0, $y);
		$this->tocAdd(0, 'Summary', $pdf->PageNo());
		$pdf->rule(60, 0.5);
		$pdf->Ln(4);
		$this->datasetNets();
		$w = $pdf->innerW();
		if ($m->summary['units']) {
			$this->summaryHead('Geologic units');
			$data = array();
			foreach ($m->summary['units'] as $u) {
				$desc = array();
				foreach ($u['rows'] as $r) if (!$r['h']) $desc[] = $r['k'] . ': ' . $r['v'];
				$data[] = array('cells' => array($u['name'], (string)$u['count'], implode(';  ', $desc)));
			}
			$this->table(array(array('Unit', 45, 'L'), array('Spots', 14, 'R'), array('Description', 0, 'L')), $data, $pdf->lm(), $w);
		}
		if ($m->summary['tags']) {
			$this->summaryHead('Tags');
			$data = array();
			foreach ($m->summary['tags'] as $t) {
				$desc = array();
				foreach ($t['rows'] as $r) if (!$r['h']) $desc[] = $r['k'] . ': ' . $r['v'];
				$data[] = array('cells' => array($t['name'], FieldbookProps::humanize($t['type']), (string)$t['count'], implode(';  ', $desc)));
			}
			$this->table(array(array('Tag', 45, 'L'), array('Type', 28, 'L'), array('Spots', 14, 'R'), array('Notes', 0, 'L')), $data, $pdf->lm(), $w);
		}
		if ($m->summary['samples']) {
			$this->summaryHead('Samples');
			$data = array();
			foreach ($m->summary['samples'] as $s) $data[] = array('cells' => array($s['title'], $s['spot'], $s['day']));
			$this->table(array(array('Sample', 60, 'L'), array('Spot', 0, 'L'), array('Day', 48, 'L')), $data, $pdf->lm(), $w);
		}
		if ($this->photoIndex) {
			$this->summaryHead('Photos');
			$tocPages = $this->tocPageCount();
			$data = array();
			foreach ($this->photoIndex as $i) {
				$page = $i['page'] > $this->coverPages ? $i['page'] + $tocPages : $i['page'];
				$data[] = array('cells' => array('[' . $i['no'] . ']', $i['title'], $i['spot'], (string)$page, $i['caption']), 'more' => array(array('k' => $i['details'], 'v' => '', 'd' => 0, 'h' => false)), 'link' => $i['link']);
			}
			$this->table(array(array('No.', 10, 'R'), array('Photo', 42, 'L'), array('Spot', 40, 'L'), array('Page', 11, 'R'), array('Caption', 0, 'L')), $data, $pdf->lm(), $w);
		} elseif ($m->summary['images']) {
			$this->summaryHead('Photos');
			$data = array();
			foreach ($m->summary['images'] as $i) $data[] = array('cells' => array($i['title'], $i['spot'], $i['caption']));
			$this->table(array(array('Photo', 55, 'L'), array('Spot', 50, 'L'), array('Caption', 0, 'L')), $data, $pdf->lm(), $w);
		}
		$this->summaryHead('About this document');
		$pdf->SetFont($pdf->body, '', 8.5);
		$lines = array();
		$lines[] = 'Generated ' . $m->meta['generated'] . ' by the StraboSpot fieldbook generator (enhanced fieldbook, M4).';
		$opts = $m->meta['options'];
		$lines[] = 'Options: page ' . (isset($opts['page']) ? $opts['page'] : 'letter') . ', photos ' . (isset($opts['photos']) ? $opts['photos'] : 'sheets') . ', map ' . (isset($opts['map']) ? $opts['map'] : 'outdoors') . ', stereonets ' . (isset($opts['nets']) ? $opts['nets'] : 'on') . '.';
		$lines[] = 'Spots are grouped by field day (creation date) and listed in creation order. Every observation stored with a spot is included; families without a designed layout appear under "Other observations".';
		foreach ($m->notes as $n) $lines[] = $n;
		if ($this->maps) foreach ($this->maps->notes() as $n) $lines[] = $n; else $lines[] = 'Maps: none (option).';
		if ($this->nets) foreach ($this->nets->notes() as $n) $lines[] = $n; else $lines[] = 'Stereonets: none (option).';
		if ($this->photos) foreach ($this->photos->notes() as $n) $lines[] = $n; else $lines[] = 'Photos: listed without figures (option).';
		$lines[] = $this->citation();
		$pdf->MultiCell($w, 4, implode("\n", $lines), 0, 'L');
	}

	private function summaryHead($text)
	{
		$pdf = $this->pdf;
		$pdf->need(24);
		$pdf->Ln(2);
		$pdf->SetFont($pdf->head, 'B', 12);
		$pdf->Cell(0, 7, $text, 0, 1, 'L');
		$pdf->Bookmark($text, 1);
		$pdf->Ln(1);
	}

	// ------------------------------------------------------------ contents (rendered last, moved behind the cover)

	private function tocAdd($level, $label, $page, $note = '')
	{
		$this->toc[] = array('level' => (int)$level, 'label' => (string)$label, 'page' => (int)$page, 'note' => (string)$note, 'link' => $this->pdf->AddLink());
		$i = count($this->toc) - 1;
		$this->pdf->SetLink($this->toc[$i]['link'], 0, $page);
	}

	/** Pages the contents will take (entries per page from the line height), used for every body page number printed before the move. */
	private function tocPageCount()
	{
		$pdf = $this->pdf;
		$lh = self::TOC_LH;
		$usable = $pdf->pageH() - $pdf->tm() - $pdf->bm();
		$capFirst = (int)floor(($usable - 16) / $lh);
		$capNext = (int)floor($usable / $lh);
		$n = count($this->toc);
		return $n <= $capFirst ? 1 : 1 + (int)ceil(($n - $capFirst) / $capNext);
	}

	private function contents()
	{
		$pdf = $this->pdf;
		if (!$this->toc) return;
		$lh = self::TOC_LH;
		$usable = $pdf->pageH() - $pdf->tm() - $pdf->bm();
		$capFirst = (int)floor(($usable - 16) / $lh);
		$capNext = (int)floor($usable / $lh);
		$tocPages = $this->tocPageCount();
		$first = $pdf->PageNo() + 1;
		$pdf->sectionLabel = 'Contents';
		$pdf->AddPage();
		$y = $pdf->GetY();
		$pdf->SetFont($pdf->head, 'B', 18);
		$pdf->Cell(0, 9, 'Contents', 0, 1, 'L');
		$pdf->rule(60, 0.5);
		$pdf->Ln(5);
		$pdf->Bookmark('Contents', 0, $y, true);
		$w = $pdf->innerW();
		$onPage = 0; $cap = $capFirst;
		foreach ($this->toc as $e) {
			if ($onPage >= $cap) { $pdf->AddPage(); $onPage = 0; $cap = $capNext; }
			$target = $e['page'] > $this->coverPages ? $e['page'] + $tocPages : $e['page'];
			$ind = 6 * $e['level'];
			$pdf->SetX($pdf->lm() + $ind);
			$pdf->SetFont($pdf->head, $e['level'] === 0 ? 'B' : '', $e['level'] === 0 ? 10 : 9.5);
			$numW = 12; $noteW = $e['note'] !== '' ? 24 : 0;
			$labW = $w - $ind - $numW - $noteW;
			$label = $pdf->fit($e['label'], $labW - 2);
			$pdf->Cell($labW, $lh, $label, 0, 0, 'L', false, $e['link']);
			if ($noteW) {
				$pdf->SetFont($pdf->head, '', 8);
				$pdf->SetTextColor(120, 120, 120);
				$pdf->Cell($noteW, $lh, $e['note'], 0, 0, 'R', false, $e['link']);
				$pdf->SetTextColor(0, 0, 0);
				$pdf->SetFont($pdf->head, $e['level'] === 0 ? 'B' : '', $e['level'] === 0 ? 10 : 9.5);
			}
			$pdf->Cell($numW, $lh, (string)$target, 0, 1, 'R', false, $e['link']);
			$onPage++;
		}
		$last = $pdf->PageNo();
		if ($last - $first + 1 !== $tocPages) {
			// keep the document consistent even if the estimate slipped: the printed numbers would be off by the difference
			$this->m->notes[] = 'Contents page numbers may be offset by ' . (($last - $first + 1) - $tocPages) . '.';
		}
		$pdf->movePagesAfter($first, $last, $this->coverPages);
	}
}
