<?php
/**
 * File: includes/sesar/SesarBatchTemplate.php
 * Description: Reads and fills a SESAR batch registration spreadsheet (the
 *              .xlsx SESAR's web Batch Template Creator generates). Phase 8,
 *              decision B1: StraboSpot fills the user's OWN template, so
 *              any field selection or collector setup works, and SESAR's
 *              cover page, hidden Metadata and Lookups sheets, dropdowns and
 *              comments stay exactly as SESAR made them. Only the Samples
 *              sheet part is rewritten (cells written into its empty data
 *              rows); every other part of the package is left untouched.
 *
 *              Template layout (version 8.0, sandbox 09-27): "Cover Page"
 *              (SESAR code, object type), "Metadata" (veryHidden:
 *              sesar_code / object_type / template_version), "Samples" (row 1
 *              = headers, empty rows 2-5001 carrying list validations that
 *              point into "Lookups").
 *
 *              No database, no network. Throws SesarError (validation) with a
 *              plain message for anything that is not a usable template.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarClient.php';

class SesarBatchTemplate
{
	/** SESAR: at most 5000 rows per file including the header row. */
	const MAX_SAMPLES = 4999;
	const MAX_BYTES = 20971520;   // 20 MB; SESAR's own template is under 1 MB
	/**
	 * Largest part (UNPACKED) that is read: MAX_BYTES only bounds the packed
	 * file, and a small .xlsx can unpack to gigabytes. SESAR's largest part is
	 * under 0.5 MB, about 20 MB with all 5000 rows filled.
	 */
	const MAX_PART_BYTES = 52428800;   // 50 MB

	const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

	private $path;
	private $samplesPart;          // e.g. xl/worksheets/sheet3.xml
	private $samplesXml;           // original part text
	private $headers = array();    // column index (1-based) => header text
	private $meta = array();       // sesar_code, object_type, template_version
	private $lists = array();      // header => allowed values (from list validations)
	private $dataRows = 0;         // rows below the header that already hold a value

	private function __construct() {}

	/**
	 * @param string $path uploaded file
	 * @return SesarBatchTemplate
	 * @throws SesarError validation
	 */
	public static function load($path)
	{
		$t = new self();
		$t->path = $path;
		if (!is_file($path) || filesize($path) === 0) self::bad('The file is empty.');
		if (filesize($path) > self::MAX_BYTES) self::bad('The file is too large to be a SESAR template.');

		$zip = new ZipArchive();
		if ($zip->open($path) !== true) self::bad('This is not an .xlsx file. Upload the spreadsheet SESAR\'s Batch Template Creator gave you.');
		try {
			$sheets = self::sheetParts($zip);
			if (!isset($sheets['Samples'])) self::bad('This is not a SESAR batch template: it has no "Samples" sheet. Upload the spreadsheet SESAR\'s Batch Template Creator gave you.');
			$shared = self::sharedStrings($zip);

			$t->samplesPart = $sheets['Samples'];
			$t->samplesXml = self::part($zip, $t->samplesPart);
			if ($t->samplesXml === false) self::bad('The Samples sheet could not be read.');
			$doc = self::dom($t->samplesXml);
			$grid = self::grid($doc, $shared);

			foreach (isset($grid[1]) ? $grid[1] : array() as $col => $v) {
				$v = trim((string)$v);
				if ($v !== '') $t->headers[$col] = $v;
			}
			if (!in_array('Sample Name', $t->headers, true)) {
				self::bad('This is not a SESAR batch template: its Samples sheet has no "Sample Name" column.');
			}
			foreach ($grid as $r => $cells) {
				if ($r < 2) continue;
				foreach ($cells as $v) {
					if (trim((string)$v) !== '') { $t->dataRows++; break; }
				}
			}

			// SESAR code + template version: hidden Metadata sheet, else the cover page.
			if (isset($sheets['Metadata'])) {
				$mg = self::grid(self::dom(self::part($zip, $sheets['Metadata'])), $shared);
				foreach ($mg as $cells) {
					if (isset($cells[1], $cells[2])) $t->meta[trim((string)$cells[1])] = trim((string)$cells[2]);
				}
			}
			if (empty($t->meta['sesar_code']) && isset($sheets['Cover Page'])) {
				$cg = self::grid(self::dom(self::part($zip, $sheets['Cover Page'])), $shared);
				foreach ($cg as $cells) {
					if (isset($cells[1], $cells[2]) && strcasecmp(trim((string)$cells[1]), 'SESAR Code') === 0) $t->meta['sesar_code'] = trim((string)$cells[2]);
				}
			}
			if (empty($t->meta['sesar_code'])) self::bad('This is not a SESAR batch template: no SESAR code was found on its cover page.');

			$t->lists = self::validationLists($doc, $t->headers, $zip, $sheets, $shared);
		} finally {
			$zip->close();
		}
		return $t;
	}

	public function headers() { return array_values($this->headers); }
	public function hasHeader($h) { return in_array($h, $this->headers, true); }
	public function sesarCode() { return $this->meta['sesar_code']; }
	public function templateVersion() { return isset($this->meta['template_version']) ? $this->meta['template_version'] : null; }
	/** "Enter per sample in spreadsheet" or a fixed object type label. */
	public function objectTypeSetting() { return isset($this->meta['object_type']) ? $this->meta['object_type'] : null; }
	public function dataRowCount() { return $this->dataRows; }

	/** Dropdown values SESAR put on a column, or null when the column has no list. */
	public function allowedValues($header)
	{
		return isset($this->lists[$header]) ? $this->lists[$header] : null;
	}

	/**
	 * The template with $rows written into the Samples sheet from row 2.
	 * Each row is header => value (string, int or float; null / '' = leave
	 * blank). Headers the template lacks are ignored (the caller reports
	 * them). Numbers become numeric cells, everything else inline text.
	 *
	 * @return string the .xlsx bytes
	 * @throws SesarError validation
	 */
	public function fill(array $rows)
	{
		if ($this->dataRows > 0) {
			self::bad('This template already has samples on its Samples sheet. Generate a fresh template in SESAR\'s Batch Template Creator and upload that.');
		}
		if (count($rows) > self::MAX_SAMPLES) {
			self::bad('SESAR takes at most ' . self::MAX_SAMPLES . ' samples per file. Select fewer samples.');
		}
		$colOf = array();
		foreach ($this->headers as $col => $h) {
			if (!isset($colOf[$h])) $colOf[$h] = $col;   // a repeated header: the first one wins
		}
		$maxCol = empty($this->headers) ? 1 : max(array_keys($this->headers));

		$doc = self::dom($this->samplesXml);
		$xp = new DOMXPath($doc);
		$xp->registerNamespace('m', self::NS);
		$sheetData = $xp->query('/m:worksheet/m:sheetData')->item(0);
		if ($sheetData === null) self::bad('The Samples sheet could not be read.');
		$rowEls = array();
		foreach ($xp->query('m:row', $sheetData) as $el) $rowEls[(int)$el->getAttribute('r')] = $el;

		$last = 1;
		foreach (array_values($rows) as $i => $row) {
			$r = $i + 2;
			$cells = array();
			foreach ($row as $h => $v) {
				if (!isset($colOf[$h]) || $v === null || $v === '') continue;
				$cells[$colOf[$h]] = $v;
			}
			ksort($cells);
			$rowEl = isset($rowEls[$r]) ? $rowEls[$r] : null;
			if ($rowEl === null) {
				$rowEl = $doc->createElementNS(self::NS, 'row');
				$rowEl->setAttribute('r', (string)$r);
				$next = null;
				foreach ($rowEls as $n => $el) { if ($n > $r) { $next = $el; break; } }
				if ($next !== null) $sheetData->insertBefore($rowEl, $next); else $sheetData->appendChild($rowEl);
				$rowEls[$r] = $rowEl;
				ksort($rowEls);
			}
			while ($rowEl->firstChild) $rowEl->removeChild($rowEl->firstChild);
			$rowEl->setAttribute('spans', '1:' . $maxCol);
			foreach ($cells as $col => $v) {
				$c = $doc->createElementNS(self::NS, 'c');
				$c->setAttribute('r', self::colName($col) . $r);
				if (is_int($v) || is_float($v)) {
					$c->appendChild($doc->createElementNS(self::NS, 'v', self::num($v)));
				} else {
					$c->setAttribute('t', 'inlineStr');
					$is = $doc->createElementNS(self::NS, 'is');
					$tEl = $doc->createElementNS(self::NS, 't');
					$tEl->setAttribute('xml:space', 'preserve');
					$tEl->appendChild($doc->createTextNode(self::cleanText((string)$v)));
					$is->appendChild($tEl);
					$c->appendChild($is);
				}
				$rowEl->appendChild($c);
			}
			$last = $r;
		}
		// Keep the used range covering what we wrote (SESAR's template already spans A1:..5001).
		$dim = $xp->query('/m:worksheet/m:dimension')->item(0);
		if ($dim !== null && preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $dim->getAttribute('ref'), $m)) {
			$endCol = max(self::colIndex($m[3]), $maxCol);
			$endRow = max((int)$m[4], $last);
			$dim->setAttribute('ref', $m[1] . $m[2] . ':' . self::colName($endCol) . $endRow);
		}
		$xml = $doc->saveXML();

		$tmp = tempnam(sys_get_temp_dir(), 'sesarbt');
		try {
			if (!copy($this->path, $tmp)) throw new SesarError(500, 'The filled template could not be written.');
			$zip = new ZipArchive();
			if ($zip->open($tmp) !== true) throw new SesarError(500, 'The filled template could not be written.');
			$zip->addFromString($this->samplesPart, $xml);
			$zip->close();
			$bytes = file_get_contents($tmp);
		} finally {
			@unlink($tmp);
		}
		if ($bytes === false || $bytes === '') throw new SesarError(500, 'The filled template could not be written.');
		return $bytes;
	}

	// =======================================================================
	// Package reading
	// =======================================================================

	/** Sheet name => part path, via workbook.xml and its relationships. */
	private static function sheetParts(ZipArchive $zip)
	{
		$wb = self::part($zip, 'xl/workbook.xml');
		$rels = self::part($zip, 'xl/_rels/workbook.xml.rels');
		if ($wb === false || $rels === false) self::bad('This is not an .xlsx workbook. Upload the spreadsheet SESAR\'s Batch Template Creator gave you.');
		$targets = array();
		$rd = self::dom($rels);
		foreach ($rd->getElementsByTagNameNS(self::NS_PKG_REL, 'Relationship') as $rel) {
			$target = $rel->getAttribute('Target');
			$targets[$rel->getAttribute('Id')] = (strpos($target, '/') === 0) ? ltrim($target, '/') : 'xl/' . $target;
		}
		$out = array();
		$wd = self::dom($wb);
		foreach ($wd->getElementsByTagNameNS(self::NS, 'sheet') as $s) {
			$rid = $s->getAttributeNS(self::NS_REL, 'id');
			if (isset($targets[$rid])) $out[$s->getAttribute('name')] = $targets[$rid];
		}
		return $out;
	}

	private static function sharedStrings(ZipArchive $zip)
	{
		$xml = self::part($zip, 'xl/sharedStrings.xml');
		if ($xml === false) return array();
		$out = array();
		$d = self::dom($xml);
		foreach ($d->getElementsByTagNameNS(self::NS, 'si') as $si) {
			$txt = '';
			foreach ($si->getElementsByTagNameNS(self::NS, 't') as $t) $txt .= $t->textContent;
			$out[] = $txt;
		}
		return $out;
	}

	/** row => col => text for every cell holding a value. */
	private static function grid(DOMDocument $doc, array $shared)
	{
		$out = array();
		foreach ($doc->getElementsByTagNameNS(self::NS, 'c') as $c) {
			if (!preg_match('/^([A-Z]+)(\d+)$/', $c->getAttribute('r'), $m)) continue;
			$type = $c->getAttribute('t');
			$val = null;
			if ($type === 'inlineStr') {
				$val = '';
				foreach ($c->getElementsByTagNameNS(self::NS, 't') as $t) $val .= $t->textContent;
			} else {
				$v = $c->getElementsByTagNameNS(self::NS, 'v')->item(0);
				if ($v !== null) {
					$val = $v->textContent;
					if ($type === 's') $val = isset($shared[(int)$val]) ? $shared[(int)$val] : '';
				}
			}
			if ($val !== null) $out[(int)$m[2]][self::colIndex($m[1])] = $val;
		}
		return $out;
	}

	/**
	 * header => allowed values, from the Samples sheet's list validations
	 * (formula "Lookups!$A$1:$A$74" or a literal "a,b,c" list).
	 */
	private static function validationLists(DOMDocument $doc, array $headers, ZipArchive $zip, array $sheets, array $shared)
	{
		$out = array();
		$grids = array();
		foreach ($doc->getElementsByTagNameNS(self::NS, 'dataValidation') as $dv) {
			if ($dv->getAttribute('type') !== 'list') continue;
			$f = $dv->getElementsByTagNameNS(self::NS, 'formula1')->item(0);
			if ($f === null) continue;
			$formula = trim($f->textContent);
			$values = null;
			if (preg_match('/^\'?([^\'!]+)\'?!\$?([A-Z]+)\$?(\d+):\$?([A-Z]+)\$?(\d+)$/', $formula, $m)) {
				if (!isset($sheets[$m[1]])) continue;
				if (!isset($grids[$m[1]])) $grids[$m[1]] = self::grid(self::dom(self::part($zip, $sheets[$m[1]])), $shared);
				$col = self::colIndex($m[2]);
				$values = array();
				for ($r = (int)$m[3]; $r <= (int)$m[5]; $r++) {
					if (isset($grids[$m[1]][$r][$col]) && trim($grids[$m[1]][$r][$col]) !== '') $values[] = trim($grids[$m[1]][$r][$col]);
				}
			} elseif (preg_match('/^"(.*)"$/s', $formula, $m)) {
				$values = array_values(array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
			}
			if ($values === null) continue;
			foreach (preg_split('/\s+/', trim($dv->getAttribute('sqref'))) as $range) {
				if (!preg_match('/^([A-Z]+)\d+(?::([A-Z]+)\d+)?$/', $range, $rm)) continue;
				$from = self::colIndex($rm[1]);
				$to = isset($rm[2]) && $rm[2] !== '' ? self::colIndex($rm[2]) : $from;
				for ($c = $from; $c <= $to; $c++) {
					if (isset($headers[$c]) && !isset($out[$headers[$c]])) $out[$headers[$c]] = $values;
				}
			}
		}
		return $out;
	}

	// =======================================================================
	// Helpers
	// =======================================================================

	/** One part of the package, or false when it has none by that name. Never unpacks more than MAX_PART_BYTES. */
	private static function part(ZipArchive $zip, $name)
	{
		$st = $zip->statName($name);
		if ($st === false) return false;
		if ((int)$st['size'] > self::MAX_PART_BYTES) self::bad('The file is too large to be a SESAR template.');
		return $zip->getFromName($name);
	}

	private static function dom($xml)
	{
		if ($xml === false || $xml === null || $xml === '') self::bad('The spreadsheet is damaged: a part of it is missing.');
		// A spreadsheet part never declares a DOCTYPE; one that does could define entities that balloon when read.
		if (stripos($xml, '<!DOCTYPE') !== false) self::bad('The spreadsheet is damaged and could not be read.');
		$d = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok) self::bad('The spreadsheet is damaged and could not be read.');
		return $d;
	}

	public static function colIndex($letters)
	{
		$n = 0;
		foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
		return $n;
	}

	public static function colName($index)
	{
		$s = '';
		while ($index > 0) {
			$m = ($index - 1) % 26;
			$s = chr(65 + $m) . $s;
			$index = intdiv($index - 1, 26);
		}
		return $s;
	}

	private static function num($v)
	{
		if (is_int($v)) return (string)$v;
		$s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
		return $s === '-0' ? '0' : $s;
	}

	/** XML 1.0 cannot carry control characters; Excel caps a cell at 32767 characters. */
	private static function cleanText($s)
	{
		$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
		return mb_substr($s, 0, 32767);
	}

	private static function bad($message)
	{
		throw new SesarError(400, $message, array('file' => array('invalid')));
	}
}
