<?php
/**
 * File: micro_text.php
 * Description: Text encoding of StraboMicro project.json files.
 *
 *              StraboMicro2 writes UTF-8. The legacy JavaFX app wrote the
 *              Windows code page (CP1252 in practice: 0x92 for a curly
 *              apostrophe, 0xF6 for ö), which json_decode rejects. Uploads
 *              used to utf8_encode() every file, valid UTF-8 or not, and
 *              loadProjectJSON did it again for projectjson, so non-ASCII
 *              text was stored as mojibake (000° became 000Ã\u0082Â°).
 *
 *              micro_json_to_utf8()        what every upload path uses now
 *              micro_projectjson_repair()  recovers the uploaded text from a
 *                                          projectjson stored by the old code
 *                                          (microdb/repair_utf8.php)
 *
 *              No mbstring (not confirmed on production PHP 7.3): PCRE's /u
 *              flag checks UTF-8, utf8_decode undoes the old utf8_encode.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (!function_exists('micro_json_to_utf8')) {

	function micro_text_is_utf8($s) {
		return preg_match('//u', (string)$s) === 1;
	}

	/** CP1252 characters in 0x80-0x9F (the rest of CP1252 equals Latin-1). */
	function micro_cp1252_table() {
		return array(
			0x80 => "\u{20AC}", 0x82 => "\u{201A}", 0x83 => "\u{0192}", 0x84 => "\u{201E}",
			0x85 => "\u{2026}", 0x86 => "\u{2020}", 0x87 => "\u{2021}", 0x88 => "\u{02C6}",
			0x89 => "\u{2030}", 0x8A => "\u{0160}", 0x8B => "\u{2039}", 0x8C => "\u{0152}",
			0x8E => "\u{017D}", 0x91 => "\u{2018}", 0x92 => "\u{2019}", 0x93 => "\u{201C}",
			0x94 => "\u{201D}", 0x95 => "\u{2022}", 0x96 => "\u{2013}", 0x97 => "\u{2014}",
			0x98 => "\u{02DC}", 0x99 => "\u{2122}", 0x9A => "\u{0161}", 0x9B => "\u{203A}",
			0x9C => "\u{0153}", 0x9E => "\u{017E}", 0x9F => "\u{0178}",
		);
	}

	/** CP1252 bytes to UTF-8 (undefined 0x81/0x8D/0x8F/0x90/0x9D kept as Latin-1). */
	function micro_cp1252_to_utf8($s) {
		$map = array();
		for ($b = 0x80; $b <= 0xFF; $b++) {
			$map[chr($b)] = utf8_encode(chr($b));
		}
		foreach (micro_cp1252_table() as $b => $u) {
			$map[chr($b)] = $u;
		}
		return strtr((string)$s, $map);
	}

	/**
	 * In UTF-8 text, the C1 controls U+0080-U+009F that a Latin-1 reading of
	 * CP1252 bytes produced, replaced by the CP1252 characters.
	 */
	function micro_utf8_fix_c1($s) {
		$map = array();
		foreach (micro_cp1252_table() as $b => $u) {
			$map["\xC2" . chr($b)] = $u;
		}
		return strtr((string)$s, $map);
	}

	/**
	 * project.json text as UTF-8: valid UTF-8 unchanged (a leading BOM
	 * removed), anything else read as CP1252.
	 */
	function micro_json_to_utf8($s) {
		$s = (string)$s;
		if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
			$s = substr($s, 3);
		}
		return micro_text_is_utf8($s) ? $s : micro_cp1252_to_utf8($s);
	}

	/**
	 * The uploaded project.json text behind a projectjson value the old
	 * upload code stored, or null when the value does not look like the
	 * old code's output (already clean, or never garbled).
	 *
	 * The old code stored utf8_encode(utf8_encode(file)). utf8_encode output
	 * only holds U+0000-U+00FF and utf8_decode inverts it exactly, so:
	 *   once  = utf8_decode(stored), which must be valid UTF-8 with non-ASCII
	 *           (for clean text it is not: it would be raw Latin-1 bytes);
	 *   twice = utf8_decode(once): valid UTF-8 means the file was UTF-8
	 *           (the common case); otherwise the file was CP1252 and once
	 *           is its Latin-1 reading, which needs only the C1 fix.
	 * Returns ['text' => UTF-8 JSON, 'source' => 'utf8' | 'cp1252'].
	 */
	function micro_projectjson_repair($stored) {
		$stored = (string)$stored;
		if (!preg_match('/[\x80-\xFF]/', $stored) || !micro_text_is_utf8($stored)) {
			return null;
		}
		// Anything above U+00FF cannot come from utf8_encode.
		if (preg_match('/[\x{0100}-\x{10FFFF}]/u', $stored)) {
			return null;
		}
		$once = utf8_decode($stored);
		if (!preg_match('/[\x80-\xFF]/', $once) || !micro_text_is_utf8($once)) {
			return null;
		}
		$twice = utf8_decode($once);
		if (!preg_match('/[\x{0100}-\x{10FFFF}]/u', $once) && preg_match('/[\x80-\xFF]/', $twice)
			&& micro_text_is_utf8($twice) && json_decode($twice) !== null) {
			return array('text' => $twice, 'source' => 'utf8');
		}
		$fixed = micro_utf8_fix_c1($once);
		return json_decode($fixed) === null ? null : array('text' => $fixed, 'source' => 'cp1252');
	}
}
