<?php
/**
 * File: includes/fieldbook/FieldbookPdf.php
 * Description: tFPDF subclass for the enhanced fieldbook
 *              (docs/Fieldbook_Design.md §5). The ONLY place besides
 *              FieldbookRenderer that knows tFPDF. Adds: unicode font set
 *              (msjh body, DejaVu Sans Condensed headings), running header
 *              and footer with a per-page number token that survives page
 *              reordering, PDF outline bookmarks, page reordering (so the
 *              table of contents can be rendered last and moved to the
 *              front), and a few layout helpers (keep-together, rules,
 *              wrapped text height).
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (!class_exists('PDF_MemImage')) {
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/PDF_LabBook.php');
}

class FieldbookPdf extends PDF_MemImage
{
	const PN = '{pn}';   // current page number token (replaced at output time, after reordering)

	public $bookTitle = '';
	public $sectionLabel = '';
	public $plainPage = false;     // true on the cover: no running header / footer

	public $body = 'msjh';         // unicode body face (CJK-capable, the legacy fieldbook face)
	public $head = 'dejavu';       // DejaVu Sans Condensed: regular, B, I (the shipped .mtx.php caches pin the key 'dejavu')

	private $outlines = array();
	private $outlineRoot = 0;

	public function __construct($format = 'Letter')
	{
		parent::__construct('P', 'mm', $format);
		$this->setType('book');
		$this->AddFont($this->body, '', 'msjh.ttf', true);
		$this->AddFont($this->head, '', 'DejaVuSansCondensed.ttf', true);
		$this->AddFont($this->head, 'B', 'DejaVuSansCondensed-Bold.ttf', true);
		$this->AddFont($this->head, 'I', 'DejaVuSansCondensed-Oblique.ttf', true);
		$this->SetMargins(18, 24, 18);
		$this->SetAutoPageBreak(true, 20);
		$this->AliasNbPages('{nb}');
		$this->SetDisplayMode('fullpage', 'single');
	}

	// ------------------------------------------------------------ chrome

	private $plainPages = array();

	public function Header()
	{
		$this->plainPages[$this->page] = $this->plainPage;
		if ($this->plainPage) return;
		$this->SetY(10);
		$this->SetFont($this->head, '', 7.5);
		$this->SetTextColor(120, 120, 120);
		$this->Cell(($this->w - $this->lMargin - $this->rMargin) * 0.6, 4, $this->fit($this->bookTitle, ($this->w - $this->lMargin - $this->rMargin) * 0.6), 0, 0, 'L');
		$this->Cell(0, 4, $this->fit($this->sectionLabel, ($this->w - $this->lMargin - $this->rMargin) * 0.4), 0, 1, 'R');
		$this->SetDrawColor(190, 190, 190);
		$this->SetLineWidth(0.2);
		$this->Line($this->lMargin, 15, $this->w - $this->rMargin, 15);
		$this->SetTextColor(0, 0, 0);
		$this->SetY($this->tMargin);
	}

	public function Footer()
	{
		if (!empty($this->plainPages[$this->page])) return;
		$this->SetY(-14);
		$this->SetFont($this->head, '', 7.5);
		$this->SetTextColor(120, 120, 120);
		$this->Cell(0, 5, 'Page ' . self::PN . ' of {nb}', 0, 0, 'C');
		$this->SetTextColor(0, 0, 0);
	}

	/** Truncate $s with an ellipsis so it fits $w mm in the current font. */
	public function fit($s, $w)
	{
		$s = (string)$s;
		if ($this->GetStringWidth($s) <= $w) return $s;
		$ell = '…';
		while ($s !== '' && $this->GetStringWidth($s . $ell) > $w) {
			$s = mb_substr($s, 0, mb_strlen($s, 'UTF-8') - 1, 'UTF-8');
		}
		return rtrim($s) . $ell;
	}

	// ------------------------------------------------------------ layout helpers

	/** Usable width between the margins. */
	public function innerW() { return $this->w - $this->lMargin - $this->rMargin; }
	// tFPDF keeps geometry protected; the renderer reads it through these
	public function lm() { return $this->lMargin; }
	public function rm() { return $this->rMargin; }
	public function tm() { return $this->tMargin; }
	public function bm() { return $this->bMargin; }
	public function pageH() { return $this->h; }
	public function pageW() { return $this->w; }

	/** Start a new page if fewer than $mm of height remain (keep headings with their content). */
	public function need($mm)
	{
		if ($this->GetY() + $mm > $this->h - $this->bMargin) $this->AddPage();
	}

	public function rule($gray = 190, $width = 0.2)
	{
		$this->SetDrawColor($gray, $gray, $gray);
		$this->SetLineWidth($width);
		$y = $this->GetY();
		$this->Line($this->lMargin, $y, $this->w - $this->rMargin, $y);
	}

	/** Number of lines MultiCell would use for $txt in a cell $w wide (current font). */
	public function nbLines($w, $txt)
	{
		$txt = str_replace("\r", '', (string)$txt);
		if ($w <= 0) return 1;
		$wmax = $w - 2 * $this->cMargin;
		$lines = explode("\n", $txt);
		$nb = 0;
		foreach ($lines as $line) {
			$line = rtrim($line);
			if ($line === '') { $nb++; continue; }
			$words = preg_split('/(?<= )/u', $line);
			$cur = '';
			$n = 1;
			foreach ($words as $wd) {
				if ($this->GetStringWidth($cur . $wd) <= $wmax) { $cur .= $wd; continue; }
				if ($cur !== '') { $n++; $cur = ''; }
				// a single word longer than the cell wraps by character
				while ($this->GetStringWidth($wd) > $wmax) {
					$k = mb_strlen($wd, 'UTF-8');
					$cut = $k;
					while ($cut > 1 && $this->GetStringWidth(mb_substr($wd, 0, $cut, 'UTF-8')) > $wmax) $cut--;
					$wd = mb_substr($wd, $cut, null, 'UTF-8');
					$n++;
				}
				$cur = $wd;
			}
			$nb += $n;
		}
		return max(1, $nb);
	}

	// ------------------------------------------------------------ vector primitives (stereonets, M3)

	/** Point in mm => PDF user-space string. */
	private function vp($x, $y) { return sprintf('%.3F %.3F', $x * $this->k, ($this->h - $y) * $this->k); }

	/** Stroke a circle (four Bezier arcs). */
	public function vCircle($cx, $cy, $r, $op = 'S')
	{
		$k = 0.5522847498 * $r;
		$out = $this->vp($cx + $r, $cy) . ' m';
		$out .= ' ' . $this->vp($cx + $r, $cy - $k) . ' ' . $this->vp($cx + $k, $cy - $r) . ' ' . $this->vp($cx, $cy - $r) . ' c';
		$out .= ' ' . $this->vp($cx - $k, $cy - $r) . ' ' . $this->vp($cx - $r, $cy - $k) . ' ' . $this->vp($cx - $r, $cy) . ' c';
		$out .= ' ' . $this->vp($cx - $r, $cy + $k) . ' ' . $this->vp($cx - $k, $cy + $r) . ' ' . $this->vp($cx, $cy + $r) . ' c';
		$out .= ' ' . $this->vp($cx + $k, $cy + $r) . ' ' . $this->vp($cx + $r, $cy + $k) . ' ' . $this->vp($cx + $r, $cy) . ' c';
		$this->_out($out . ' ' . $op);
	}

	/** Stroke an open polyline through [[x, y], ...] mm. */
	public function vPolyline(array $pts)
	{
		if (count($pts) < 2) return;
		$out = $this->vp($pts[0][0], $pts[0][1]) . ' m';
		for ($i = 1; $i < count($pts); $i++) $out .= ' ' . $this->vp($pts[$i][0], $pts[$i][1]) . ' l';
		$this->_out($out . ' S');
	}

	/** Closed polygon: $op = 'S' stroke, 'f' fill, 'B' fill + stroke. */
	public function vPolygon(array $pts, $op = 'B')
	{
		if (count($pts) < 3) return;
		$out = $this->vp($pts[0][0], $pts[0][1]) . ' m';
		for ($i = 1; $i < count($pts); $i++) $out .= ' ' . $this->vp($pts[$i][0], $pts[$i][1]) . ' l';
		$this->_out($out . ' h ' . $op);
	}

	/**
	 * Marker symbol centred on (cx, cy), $size = width mm. Shapes: circle, square, triangle, diamond,
	 * tridown, pentagon, hexagon, star. Filled symbols use the current fill colour; open ones are white inside.
	 */
	public function vSymbol($shape, $cx, $cy, $size, $filled)
	{
		$r = $size / 2;
		if (!$filled) $this->SetFillColor(255, 255, 255);
		if ($shape === 'circle') { $this->vCircle($cx, $cy, $r, 'B'); return; }
		$pts = array();
		switch ($shape) {
			case 'square': $pts = array(array($cx - $r, $cy - $r), array($cx + $r, $cy - $r), array($cx + $r, $cy + $r), array($cx - $r, $cy + $r)); break;
			case 'triangle': $pts = array(array($cx, $cy - $r * 1.15), array($cx + $r * 1.1, $cy + $r * 0.75), array($cx - $r * 1.1, $cy + $r * 0.75)); break;
			case 'tridown': $pts = array(array($cx, $cy + $r * 1.15), array($cx + $r * 1.1, $cy - $r * 0.75), array($cx - $r * 1.1, $cy - $r * 0.75)); break;
			case 'diamond': $pts = array(array($cx, $cy - $r * 1.25), array($cx + $r * 1.05, $cy), array($cx, $cy + $r * 1.25), array($cx - $r * 1.05, $cy)); break;
			case 'pentagon': case 'hexagon':
				$n = $shape === 'pentagon' ? 5 : 6;
				for ($i = 0; $i < $n; $i++) { $a = -M_PI / 2 + 2 * M_PI * $i / $n; $pts[] = array($cx + $r * 1.1 * cos($a), $cy + $r * 1.1 * sin($a)); }
				break;
			case 'star':
				for ($i = 0; $i < 10; $i++) { $a = -M_PI / 2 + M_PI * $i / 5; $rr = ($i % 2 === 0) ? $r * 1.3 : $r * 0.55; $pts[] = array($cx + $rr * cos($a), $cy + $rr * sin($a)); }
				break;
			default: $this->vCircle($cx, $cy, $r, 'B'); return;
		}
		$this->vPolygon($pts, 'B');
	}

	// ------------------------------------------------------------ bookmarks (FPDF outline extension, 1.8 flavour)

	public function Bookmark($txt, $level = 0, $y = -1, $front = false)
	{
		if ($y == -1) $y = $this->GetY();
		$o = array('t' => (string)$txt, 'l' => (int)$level, 'y' => ($this->h - $y) * $this->k, 'p' => $this->PageNo());
		if ($front) array_unshift($this->outlines, $o); else $this->outlines[] = $o;
	}

	protected function _putbookmarks()
	{
		$nb = count($this->outlines);
		if ($nb == 0) return;
		$lru = array();
		$level = 0;
		foreach ($this->outlines as $i => $o) {
			if ($o['l'] > 0) {
				$parent = $lru[$o['l'] - 1];
				$this->outlines[$i]['parent'] = $parent;
				$this->outlines[$parent]['last'] = $i;
				if ($o['l'] > $level) $this->outlines[$parent]['first'] = $i;
			} else {
				$this->outlines[$i]['parent'] = $nb;
			}
			if ($o['l'] <= $level && $i > 0) {
				$prev = $lru[$o['l']];
				$this->outlines[$prev]['next'] = $i;
				$this->outlines[$i]['prev'] = $prev;
			}
			$lru[$o['l']] = $i;
			$level = $o['l'];
		}
		$n = $this->n + 1;
		foreach ($this->outlines as $i => $o) {
			$this->_newobj();
			$this->_put('<</Title ' . $this->_textstring($o['t']));
			$this->_put('/Parent ' . ($n + $o['parent']) . ' 0 R');
			if (isset($o['prev'])) $this->_put('/Prev ' . ($n + $o['prev']) . ' 0 R');
			if (isset($o['next'])) $this->_put('/Next ' . ($n + $o['next']) . ' 0 R');
			if (isset($o['first'])) $this->_put('/First ' . ($n + $o['first']) . ' 0 R');
			if (isset($o['last'])) $this->_put('/Last ' . ($n + $o['last']) . ' 0 R');
			$this->_put(sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]', $this->PageInfo[$o['p']]['n'], $o['y']));
			$this->_put('/Count 0>>');
			$this->_put('endobj');
		}
		$this->_newobj();
		$this->outlineRoot = $this->n;
		$this->_put('<</Type /Outlines /First ' . $n . ' 0 R');
		$this->_put('/Last ' . ($n + $lru[0]) . ' 0 R>>');
		$this->_put('endobj');
	}

	protected function _putresources()
	{
		parent::_putresources();
		$this->_putbookmarks();
	}

	protected function _putcatalog()
	{
		parent::_putcatalog();
		if (count($this->outlines) > 0) {
			$this->_put('/Outlines ' . $this->outlineRoot . ' 0 R');
			$this->_put('/PageMode /UseOutlines');
		}
	}

	// ------------------------------------------------------------ page numbering + reordering

	/** Replace the current-page token with the final page number (mirrors the {nb} handling of tFPDF). */
	protected function _putpage($n)
	{
		$tok16 = $this->UTF8ToUTF16BE(self::PN, false);
		$num16 = $this->UTF8ToUTF16BE((string)$n, false);
		$this->pages[$n] = str_replace($tok16, $num16, $this->pages[$n]);
		$this->pages[$n] = str_replace(self::PN, (string)$n, $this->pages[$n]);
		parent::_putpage($n);
	}

	/**
	 * Reorder pages. $order[new page number (1-based)] = old page number.
	 * Remaps page info, link annotations, internal link targets and bookmarks.
	 */
	public function reorderPages(array $order)
	{
		$nb = $this->page;
		if (count($order) !== $nb) throw new Exception('reorderPages: order must list every page once');
		$oldToNew = array();
		foreach ($order as $new => $old) $oldToNew[$old] = $new;
		$pages = array(); $info = array(); $plinks = array();
		foreach ($order as $new => $old) {
			$pages[$new] = $this->pages[$old];
			$info[$new] = isset($this->PageInfo[$old]) ? $this->PageInfo[$old] : array();
			$plinks[$new] = isset($this->PageLinks[$old]) ? $this->PageLinks[$old] : array();
		}
		$this->pages = $pages; $this->PageInfo = $info; $this->PageLinks = $plinks;
		foreach ($this->links as $l => $t) $this->links[$l] = array($oldToNew[$t[0]], $t[1]);
		foreach ($this->outlines as $i => $o) $this->outlines[$i]['p'] = $oldToNew[$o['p']];
	}

	/** Move pages $from..$to (inclusive) so they follow page $after (0 = to the front). */
	public function movePagesAfter($from, $to, $after)
	{
		$nb = $this->page;
		$block = range($from, $to);
		$rest = array();
		for ($i = 1; $i <= $nb; $i++) if ($i < $from || $i > $to) $rest[] = $i;
		$order = array();
		$k = 1;
		if ($after == 0) foreach ($block as $b) $order[$k++] = $b;
		foreach ($rest as $p) {
			$order[$k++] = $p;
			if ($p == $after) foreach ($block as $b) $order[$k++] = $b;
		}
		$this->reorderPages($order);
	}
	// ------------------------------------------------------------ delivery

	/**
	 * Stream the finished book inline WITH a Content-Length. tFPDF's Output('I') sends none, so the response goes
	 * out chunked and a browser's PDF viewer shows an indeterminate loading bar for the whole download (a 700-photo
	 * book was 90 MB; Jason's boss read that as a hang, 2026-09-04). Mirrors the 'I' branch otherwise, including
	 * the check that nothing but whitespace was output before the document.
	 */
	public function outputInline($name)
	{
		if ($this->state < 3) $this->Close();
		$this->_checkoutput();
		if (PHP_SAPI != 'cli') {
			header('Content-Type: application/pdf');
			header('Content-Disposition: inline; ' . $this->_httpencode('filename', $name, false));
			header('Cache-Control: private, max-age=0, must-revalidate');
			header('Pragma: public');
			header('Content-Length: ' . strlen($this->buffer));
		}
		echo $this->buffer;
	}
}
