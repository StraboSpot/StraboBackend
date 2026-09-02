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
 *              Maps (M2), stereonets (M3) and photo sheets (M4) plug into
 *              the hooks marked below.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

require_once __DIR__ . '/FieldbookPdf.php';
require_once __DIR__ . '/FieldbookModel.php';

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

	public function __construct(FieldbookModel $m, $progress = null)
	{
		$this->m = $m;
		$this->progress = is_callable($progress) ? $progress : null;
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
			if ($this->multiProject) { $this->titlePage('Project', $p['name'], $this->projectFacts($p), 0); $level = 1; }
			foreach ($p['datasets'] as $ds) {
				$dlevel = $level;
				if ($this->multiDataset) { $this->titlePage('Dataset', $ds['name'], $this->datasetFacts($p, $ds), $level); $dlevel = $level + 1; }
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
		// HOOK M2: overview map goes here (design §6), between the facts and the colophon line.
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

	private function titlePage($kind, $name, array $facts, $level)
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
		// HOOK M2: project / dataset overview map (design §4).
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

		// HOOK M2: day locator map (left) beside the numbered spot list (right).
		$this->spotList($day['spots']);

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

	/** Numbered list of the day's spots in two columns. */
	private function spotList(array $spots)
	{
		$pdf = $this->pdf;
		$pdf->SetFont($pdf->body, '', 8.5);
		$w = $pdf->innerW();
		$half = ($w - 6) / 2;
		$n = count($spots);
		$rows = (int)ceil($n / 2);
		$y0 = $pdf->GetY();
		$colH = $rows * self::LHS;
		if ($y0 + $colH > $pdf->pageH() - $pdf->bm()) { $pdf->AddPage(); $y0 = $pdf->GetY(); }
		for ($i = 0; $i < $n; $i++) {
			$col = $i < $rows ? 0 : 1;
			$row = $col === 0 ? $i : $i - $rows;
			$x = $pdf->lm() + $col * ($half + 6);
			$pdf->SetXY($x, $y0 + $row * self::LHS);
			$s = $spots[$i];
			$label = $s['n'] . '.  ' . $s['name'] . ($s['geomType'] !== '' ? ' (' . $s['geomType'] . ')' : '');
			$pdf->Cell($half, self::LHS, $pdf->fit($label, $half), 0, 0, 'L');
		}
		$pdf->SetXY($pdf->lm(), $y0 + $colH + 3);
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
		if ($s['orientations']) $this->orientationTable($s['orientations'], $x0, $w);
		// HOOK M3: stereonet beside / below the orientation table.
		if ($s['samples']) $this->itemList('Samples', $s['samples'], $x0, $w);
		if ($s['units']) $this->tagLine('Geologic unit' . (count($s['units']) > 1 ? 's' : ''), $s['units'], $x0, $w);
		if ($s['tags']) $this->tagLine('Tags', $s['tags'], $x0, $w);
		if ($s['images']) $this->imageList($s['images'], $x0, $w, $indent);
		// HOOK M4: contact sheets replace imageList.
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

	private function orientationTable(array $rows, $x0, $w)
	{
		$pdf = $this->pdf;
		$pdf->need(16);
		$this->subhead('Orientations', $x0);
		$cols = array(
			array('Type', 16, 'L'), array('Feature', 28, 'L'), array('Strike / Trend', 20, 'R'), array('Dip / Plunge', 20, 'R'),
			array('Dip dir.', 14, 'R'), array('Quality', 18, 'L'), array('Facing', 16, 'L'), array('Notes', 0, 'L'),
		);
		$flat = array();
		foreach ($rows as $r) {
			$flat[] = array($r, false);
			foreach ($r['children'] as $c) $flat[] = array($c, true);
		}
		$data = array();
		foreach ($flat as $pair) {
			list($r, $child) = $pair;
			$data[] = array(
				'cells' => array(($child ? '- ' : '') . $r['kind'], $r['feature'], $r['a'], $r['b'], $r['dipdir'], $r['quality'], $r['facing'], $r['notes']),
				'more' => $r['more'],
			);
		}
		$this->table($cols, $data, $x0, $w);
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
	private function imageList(array $images, $x0, $w, $indent)
	{
		$pdf = $this->pdf;
		$pdf->need(10);
		$this->subhead('Photos (' . count($images) . ')', $x0);
		foreach ($images as $img) {
			$pdf->need(8);
			$pdf->SetX($x0);
			$pdf->SetFont($pdf->head, 'B', 9);
			$label = $img['title'] !== '' ? $img['title'] : 'Image ' . $img['id'];
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
			if ($img['children']) {
				$pdf->need(40);
				$pdf->SetX($x0 + 3);
				$pdf->SetFont($pdf->head, 'I', 8);
				$pdf->SetTextColor(100, 100, 100);
				$pdf->Cell($w - 3, 4, count($img['children']) . ' spot' . (count($img['children']) > 1 ? 's' : '') . ' drawn on this image:', 0, 1, 'L');
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
		$pdf->need(12);
		$this->subhead('Other observations', $x0);
		foreach ($fams as $fam) {
			$pdf->need(8);
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
		foreach ($rows as $r) {
			$ind = 3 * (int)$r['d'];
			$x = $x0 + $ind;
			if ($r['h']) {
				$pdf->need(6);
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
		// HOOK M3: dataset stereonets first.
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
		if ($m->summary['images']) {
			$this->summaryHead('Photos');
			$data = array();
			foreach ($m->summary['images'] as $i) $data[] = array('cells' => array($i['title'], $i['spot'], $i['caption']));
			$this->table(array(array('Photo', 55, 'L'), array('Spot', 50, 'L'), array('Caption', 0, 'L')), $data, $pdf->lm(), $w);
		}
		$this->summaryHead('About this document');
		$pdf->SetFont($pdf->body, '', 8.5);
		$lines = array();
		$lines[] = 'Generated ' . $m->meta['generated'] . ' by the StraboSpot fieldbook generator (enhanced fieldbook, M1).';
		$opts = $m->meta['options'];
		$lines[] = 'Options: page ' . (isset($opts['page']) ? $opts['page'] : 'letter') . ', photos ' . (isset($opts['photos']) ? $opts['photos'] : 'sheets') . ', map ' . (isset($opts['map']) ? $opts['map'] : 'outdoors') . ', stereonets ' . (isset($opts['nets']) ? $opts['nets'] : 'on') . '.';
		$lines[] = 'Spots are grouped by field day (creation date) and listed in creation order. Every observation stored with a spot is included; families without a designed layout appear under "Other observations".';
		foreach ($m->notes as $n) $lines[] = $n;
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

	private function contents()
	{
		$pdf = $this->pdf;
		if (!$this->toc) return;
		$lh = self::TOC_LH;
		$usable = $pdf->pageH() - $pdf->tm() - $pdf->bm();
		$capFirst = (int)floor(($usable - 16) / $lh);
		$capNext = (int)floor($usable / $lh);
		$n = count($this->toc);
		$tocPages = $n <= $capFirst ? 1 : 1 + (int)ceil(($n - $capFirst) / $capNext);
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
