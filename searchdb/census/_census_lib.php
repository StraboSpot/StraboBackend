<?php
/**
 * Shared helpers for the StraboSearch Phase 0.2 searchable-data census.
 *
 * Every census script is READ-ONLY: profiling queries only, no writes to
 * any database. Designed to run on dev (prod restore) or directly on prod.
 *
 * @package StraboSearch Census
 */

function line($s = '') { echo $s . "\n"; }

function section($title) {
	line();
	line(str_repeat('=', 78));
	line('== ' . $title);
	line(str_repeat('=', 78));
}

function subsection($title) {
	line();
	line('-- ' . $title);
}

function pct($n, $total) {
	if ($total == 0) return '0.0%';
	return sprintf('%.1f%%', 100.0 * $n / $total);
}

function progress($msg) {
	fwrite(STDERR, '[' . date('H:i:s') . '] ' . $msg . "\n");
}

/**
 * Print one coverage row: label, count, percent-of-total.
 */
function covRow($label, $n, $total) {
	line(sprintf('  %-42s %10s  %s', $label, number_format($n), pct($n, $total)));
}

/**
 * Run a 2-column (value, n) query and print as a value table.
 */
function valueTable($db, $sql, $title, $total = null) {
	subsection($title);
	$rows = $db->get_results($sql);
	if (!$rows) { line('  (no rows)'); return; }
	foreach ($rows as $r) {
		$vals = array_values(get_object_vars($r));
		$label = ($vals[0] === null || $vals[0] === '') ? '(empty/null)' : $vals[0];
		if (mb_strlen($label) > 50) $label = mb_substr($label, 0, 47) . '...';
		$suffix = ($total !== null) ? '  ' . pct($vals[1], $total) : '';
		line(sprintf('  %-52s %10s%s', $label, number_format($vals[1]), $suffix));
	}
}

/**
 * Per-column coverage for a table: non-null AND non-empty-string counts.
 * $cols entries may be plain column names; varchar columns are tested for
 * empty string too, others for null only.
 */
function columnCoverage($db, $qualifiedTable, $cols, $total) {
	foreach ($cols as $col) {
		$n = (int)$db->get_var(
			"select count(*) from $qualifiedTable where $col is not null and trim($col::text) != ''"
		);
		covRow($col, $n, $total);
	}
}

/**
 * Bounded tally: count occurrences of string keys, capping the number of
 * distinct keys tracked so junk-key floods can't exhaust memory.
 */
class Tally {
	public $counts = array();
	public $overflow = 0;
	private $cap;
	public function __construct($cap = 5000) { $this->cap = $cap; }
	public function add($key, $n = 1) {
		if (isset($this->counts[$key])) {
			$this->counts[$key] += $n;
		} elseif (count($this->counts) < $this->cap) {
			$this->counts[$key] = $n;
		} else {
			$this->overflow += $n;
		}
	}
	public function printTop($limit, $total = null, $indent = '  ') {
		arsort($this->counts);
		$i = 0;
		foreach ($this->counts as $k => $n) {
			if (++$i > $limit) break;
			$label = ($k === '') ? '(empty)' : $k;
			if (mb_strlen($label) > 50) $label = mb_substr($label, 0, 47) . '...';
			$suffix = ($total !== null) ? '  ' . pct($n, $total) : '';
			line(sprintf('%s%-52s %10s%s', $indent, $label, number_format($n), $suffix));
		}
		$rest = count($this->counts) - $limit;
		if ($rest > 0) line(sprintf('%s... and %d more distinct values', $indent, $rest));
		if ($this->overflow > 0) line(sprintf('%s... plus %d occurrences past the %d-key cap', $indent, $this->overflow, count($this->counts)));
	}
	public function distinct() { return count($this->counts); }
}

?>
