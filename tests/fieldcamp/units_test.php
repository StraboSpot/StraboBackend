<?php
/**
 * File: tests/fieldcamp/units_test.php
 * Description: Suite for the Field Camp Wizard, stage 3: rock units from
 *              Macrostrat and the geologic unit tags made from them
 *              (includes/fieldcamp/FieldCampUnits.php, the Rock units step of
 *              field_camp.php, units_fetch / units_save in field_camp_action.php).
 *
 *              Part 1 calls the service with Macrostrat replaced by saved
 *              answers (fixtures/macrostrat_legend_*.json, real responses of
 *              the legend route from 11-2026: Montana, the Kansas / Missouri
 *              border, Baraboo WI): the zoom rule, the request, bad answers,
 *              saving, a second lookup, the selection, and the tags. Every
 *              tag is checked against the app's own form
 *              (fixtures/app_geologic_unit_form.json, a copy of StraboField
 *              src/assets/forms/geologic_unit.json): known fields only, valid
 *              choice values, required fields filled, and every field shown
 *              by the form's own "relevant" rules.
 *              Part 2 goes through the real Apache with forged PHP sessions.
 *              Part 3 asks the real Macrostrat once (skipped, not failed, when
 *              it cannot be reached).
 *
 *              Fixture users 94711-94712; everything the suite makes is
 *              removed again at the start and at the end.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/fieldcamp/units_test.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'includes/fieldcamp/FieldCamp.php';
require_once 'includes/fieldcamp/FieldCampUnits.php';

$INSTR = 94711; $OUTSIDER = 94712;
$FIX = array($INSTR => 'fcunits-instructor@example.org', $OUTSIDER => 'fcunits-outsider@example.org');
$BASE = 'http://localhost';
$SESS = '/var/lib/php/sessions';
$TOKEN = str_repeat('cd34', 8);
$FIXDIR = __DIR__ . '/fixtures';

$pass = 0; $fail = 0; $skip = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 500) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }

function cleanup() {
	global $db, $FIX;
	$fixpkeys = implode(',', array_keys($FIX));
	$camps = array();
	$rows = $db->get_results_prepared("select pkey from fieldcamp where owner_user_pkey in ($fixpkeys)", array());
	if ($rows) foreach ($rows as $r) $camps[] = (int)$r->pkey;
	if ($camps) {
		$in = implode(',', $camps);
		$db->query("delete from fieldcamp_unit where fieldcamp_pkey in ($in)");
		$db->query("delete from fieldcamp_unit_source where fieldcamp_pkey in ($in)");
		$db->query("delete from fieldcamp where pkey in ($in)");
	}
	$db->query("delete from fieldcamp_instructors where user_pkey in ($fixpkeys)");
	$db->query("delete from users where pkey in ($fixpkeys)");
}

$sids = array();
function forge($pkey, $email) {
	global $SESS, $sids, $TOKEN;
	$sid = substr(bin2hex(random_bytes(16)), 0, 26);
	$data = 'loggedin|s:3:"yes";userpkey|i:' . (int)$pkey . ';username|s:' . strlen($email) . ':"' . $email . '";LAST_ACTIVITY|i:' . time() . ';'
		. 'fieldcamp_csrf|s:' . strlen($TOKEN) . ':"' . $TOKEN . '";';
	file_put_contents("$SESS/sess_$sid", $data);
	chmod("$SESS/sess_$sid", 0600); @chown("$SESS/sess_$sid", 'www-data'); @chgrp("$SESS/sess_$sid", 'www-data');
	$sids[] = $sid;
	return $sid;
}
function http($method, $path, $sid, $post = null) {
	global $BASE;
	$ch = curl_init($BASE . $path);
	$hdr = array();
	if ($sid !== null) $hdr[] = 'Cookie: PHPSESSID=' . $sid;
	curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 120, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $hdr));
	if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
	$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$headers = substr((string)$raw, 0, $hs); $body = substr((string)$raw, $hs);
	$loc = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
	return array($code, (string)$body, $loc);
}
/** POST a wizard form, then GET the page it redirects to. */
function post_follow($sid, $fields, $token = null) {
	global $TOKEN;
	list($code, $body, $loc) = http('POST', '/field_camp_action', $sid, $fields + array('csrf' => $token === null ? $TOKEN : $token));
	if ($loc === '') return array($code, $body, $loc);
	list($c2, $b2) = http('GET', $loc, $sid);
	return array($c2, $b2, $loc);
}
function php_error($body) {
	return (bool)preg_match('~<b>(Warning|Notice|Deprecated|Fatal error|Parse error)</b>~', $body);
}

/** Macrostrat stand-in: the legend route answers $legend, the sources route $source. Records the URLs asked. */
$asked = array();
function stub($legend, $source = null) {
	global $asked;
	$asked = array();
	FieldCampUnits::$fetcher = function ($url) use ($legend, $source) {
		global $asked;
		$asked[] = $url;
		if (strpos($url, '/sources/') !== false) {
			return $source === null
				? array(200, json_encode(array('name' => 'Geologic map of Testland', 'ref_title' => 'Tester, A., 2007, Geologic map of Testland: Test Survey Map 62, scale 1:500,000', 'url' => 'https://example.org/map', 'scale' => 'medium')))
				: $source;
		}
		return $legend;
	};
}
function fixture($name) {
	global $FIXDIR;
	return file_get_contents("$FIXDIR/macrostrat_legend_$name.json");
}
function new_camp($name, $bbox) {
	global $db, $INSTR;
	list($clean) = FieldCamp::validateBasics(array('camp_name' => $name, 'camp_year' => '2027', 'project_name' => "$name Project"));
	$id = FieldCamp::createCamp($db, $INSTR, $clean);
	if ($bbox) FieldCamp::saveArea($db, $id, $bbox);
	return $id;
}
function units_by_name($db, $campid) {
	$out = array();
	foreach (FieldCampUnits::listUnits($db, $campid) as $u) $out[$u->tag_name] = $u;
	return $out;
}

// --- the app's form, as the yardstick for the tags -------------------------
$formJson = json_decode(file_get_contents("$FIXDIR/app_geologic_unit_form.json"), true);
$formChoices = array();
foreach ($formJson['choices'] as $c) $formChoices[$c['list_name']][] = $c['name'];
$formFields = array();
foreach ($formJson['survey'] as $f) {
	if (!isset($f['name']) || in_array($f['type'], array('start', 'end', 'begin_group', 'end_group', 'calculate'), true)) continue;
	$parts = explode(' ', $f['type']);
	$formFields[$f['name']] = array('kind' => $parts[0], 'list' => $parts[1] ?? null,
		'required' => ($f['required'] ?? '') === 'true', 'relevant' => $f['relevant'] ?? '');
}
/** Is a form "relevant" rule true for this tag? Handles the two shapes the form uses, joined by "or". */
function relevant($rule, $tag) {
	if ($rule === '') return true;
	foreach (explode(' or ', $rule) as $part) {
		$part = trim($part);
		if (preg_match('/^selected\(\$\{(\w+)\},\s*\'([^\']*)\'\)$/', $part, $m)) {
			if (isset($tag[$m[1]]) && in_array($m[2], (array)$tag[$m[1]], true)) return true;
		} elseif (preg_match('/^\$\{(\w+)\}\s*(!?=)\s*\'([^\']*)\'$/', $part, $m)) {
			$have = isset($tag[$m[1]]) ? $tag[$m[1]] : '';
			if (($m[2] === '=') === ($have === $m[3])) return true;
		} else {
			return null;   // a rule shape this test does not know
		}
	}
	return false;
}
/** Everything wrong with one tag, measured against the app's form. */
function tag_problems($tag) {
	global $formFields, $formChoices;
	$bad = array();
	foreach (array('id', 'name', 'type', 'unit_label_abbreviation') as $k) {
		if (!isset($tag[$k]) || $tag[$k] === '') $bad[] = "missing $k";
	}
	if (($tag['type'] ?? '') !== 'geologic_unit') $bad[] = "type";
	if (!is_string($tag['id'] ?? null) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $tag['id'])) $bad[] = "id is not a UUID";
	foreach (array('created_timestamp', 'modified_timestamp') as $k) {
		if (!is_int($tag[$k] ?? null) || strlen((string)$tag[$k]) !== 13) $bad[] = "$k is not a time in ms";
	}
	if (isset($tag['color']) && !preg_match('/^#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/', $tag['color'])) $bad[] = "color";
	foreach ($tag as $k => $v) {
		if (in_array($k, array('id', 'type', 'color', 'created_timestamp', 'modified_timestamp', 'spots', 'features'), true)) continue;
		if (!isset($formFields[$k])) { $bad[] = "unknown field $k"; continue; }
		$f = $formFields[$k];
		if ($f['kind'] === 'select_one' && !(is_string($v) && in_array($v, $formChoices[$f['list']], true))) $bad[] = "$k value " . json_encode($v);
		if ($f['kind'] === 'select_multiple') {
			if (!is_array($v) || !$v || array_diff($v, $formChoices[$f['list']]) || count($v) !== count(array_unique($v))) $bad[] = "$k value " . json_encode($v);
		}
		if ($f['kind'] === 'text' && !is_string($v)) $bad[] = "$k is not text";
		if ($f['kind'] === 'decimal' && !(is_int($v) || is_float($v))) $bad[] = "$k is not a number";
		$rel = relevant($f['relevant'], $tag);
		if ($rel === null) $bad[] = "$k has a relevant rule the test cannot read";
		elseif ($rel === false) $bad[] = "$k is set but the form would hide it (" . $f['relevant'] . ")";
	}
	return $bad;
}

cleanup();
foreach ($FIX as $pk => $email) {
	$db->prepare_query("insert into users (pkey, firstname, lastname, password, hash, email, active) values ($1, 'FC', 'Units', crypt('fixture-pw', gen_salt('md5')), $2, $3, true)",
		array($pk, 'fcunitshash' . $pk, $email));
}
FieldCamp::addInstructor($db, $INSTR, 'units test', 3);

$MT = array('west' => -104.83173, 'south' => 45.66478, 'east' => -104.23382, 'north' => 46.71281);

// ===========================================================================
section('Service: which zoom to ask');
check('tables installed', FieldCampUnits::installed($db));
check('a camp-sized area asks zoom 10', FieldCampUnits::zoomFor($MT) === 10);
check('2.8 degrees still fits zoom 10', FieldCampUnits::zoomFor(array('west' => 0, 'south' => 0, 'east' => 2.8, 'north' => 1)) === 10);
check('2.9 degrees drops to zoom 9', FieldCampUnits::zoomFor(array('west' => 0, 'south' => 0, 'east' => 2.9, 'north' => 1)) === 9);
check('the taller side counts', FieldCampUnits::zoomFor(array('west' => 0, 'south' => 0, 'east' => 1, 'north' => 6)) === 8);
check('a continent asks a low zoom, never below 0', FieldCampUnits::zoomFor(array('west' => -180, 'south' => -85, 'east' => 180, 'north' => 85)) === 3);
check('bounds are written west,south,east,north', FieldCampUnits::bboxString($MT) === '-104.83173,45.66478,-104.23382,46.71281', FieldCampUnits::bboxString($MT));
check('whole degrees lose their zeros', FieldCampUnits::bboxString(array('west' => -105, 'south' => 39.5, 'east' => -104, 'north' => 40)) === '-105,39.5,-104,40');

// ===========================================================================
section('Service: lookup (Macrostrat replaced by saved answers)');
stub(array(200, fixture('montana')));
$r = FieldCampUnits::lookup($MT);
check('no error', $r['error'] === '', $r['error']);
check('the legend route was asked with the bounds and zoom 10',
	$asked[0] === 'https://macrostrat.org/api/v3/map/carto/legend?bounds=-104.83173%2C45.66478%2C-104.23382%2C46.71281&zoom=10', $asked[0]);
check('14 units, youngest first', count($r['units']) === 14 && $r['units'][0]['map_unit_name'] === 'Alluvium' && $r['units'][13]['map_unit_name'] === 'Pierre Formation');
check('one source map, asked once', count($r['sources']) === 1 && count($asked) === 2 && $asked[1] === 'https://macrostrat.org/api/v3/sources/543', $asked);
check('source name and citation kept', $r['sources'][543]['name'] === 'Geologic map of Testland' && strpos($r['sources'][543]['ref_title'], 'Test Survey Map 62') !== false);
check('detailed map', $r['detailed'] === true && $r['zoom'] === 10);
$hc = null; foreach ($r['units'] as $u) { if ($u['map_unit_name'] === 'Hell Creek Formation') $hc = $u; }
check('a unit carries age, lithology, description, color and ages in Ma',
	$hc && $hc['age'] === 'Late Cretaceous' && $hc['lith'] === 'claystone, sandstone, shale' && $hc['descrip'] !== '' && $hc['color'] === '#a4e24f'
	&& $hc['t_age'] === 66.0 && $hc['legend_id'] === 128645 && $hc['lith_classes'] === 'sedimentary', $hc);

stub(array(200, fixture('ksmo')));
$r = FieldCampUnits::lookup($MT);
check('border area: 11 units from 2 source maps', count($r['units']) === 11 && count($r['sources']) === 2, array_keys($r['sources']));

stub(array(200, fixture('baraboo')));
$r = FieldCampUnits::lookup($MT);
$water = null; foreach ($r['units'] as $u) { if ($u['map_unit_name'] === 'Water') $water = $u; }
check('missing values arrive empty, not as errors', count($r['units']) === 26 && $water && $water['color'] === '' && $water['lith'] === '' && $water['age'] === '');

stub(array(200, json_encode(array(
	array('legend_id' => 1, 'source_id' => 9, 'map_unit_name' => 'Till', 'age' => 'Pleistocene', 'lith' => null, 'descrip' => 'Same.', 'color' => 'ABC', 't_age' => 0.0117, 'b_age' => 2.58),
	array('legend_id' => 2, 'source_id' => 9, 'map_unit_name' => 'Till', 'age' => 'Pleistocene', 'lith' => null, 'descrip' => 'Same.', 'color' => '#abcabc', 't_age' => 0.0117, 'b_age' => 2.58),
	array('legend_id' => 3, 'source_id' => 9, 'map_unit_name' => 'Till', 'age' => 'Pleistocene', 'lith' => null, 'descrip' => 'Different.', 'color' => 'javascript:x', 't_age' => 0.0117, 'b_age' => 2.58),
	array('legend_id' => 4, 'source_id' => 9, 'map_unit_name' => null, 'strat_name' => 'Named by strat name'),
	array('legend_id' => 5, 'source_id' => 9, 'map_unit_name' => '   '),
	array('legend_id' => 0, 'source_id' => 9, 'map_unit_name' => 'No id'),
	'not a row',
))));
$r = FieldCampUnits::lookup($MT);
check('entries that read the same are one unit; a different description stays its own', count($r['units']) === 3, array_column($r['units'], 'legend_id'));
check('rows without an id or a name are dropped; the strat name stands in for a missing name',
	array_column($r['units'], 'legend_id') === array(1, 3, 4) && $r['units'][2]['map_unit_name'] === 'Named by strat name');
check('colors are #rrggbb or nothing', $r['units'][0]['color'] === '#aabbcc' && $r['units'][1]['color'] === '');

stub(array(200, '[]'));
$r = FieldCampUnits::lookup($MT);
check('an empty answer is no error and no units', $r['error'] === '' && $r['units'] === array() && $r['sources'] === array());

stub(array(400, '{"detail":"`bounds` spans 1.05°, over the 0.703° this route answers for at zoom 12."}'));
$r = FieldCampUnits::lookup($MT);
check('a refusal passes on what Macrostrat said', strpos($r['error'], 'this route answers for at zoom 12') !== false && $r['units'] === array(), $r['error']);
foreach (array('server error' => array(500, 'Internal Server Error'), 'no connection' => array(0, ''),
	'a web page instead of JSON' => array(200, '<html>maintenance</html>'), 'an object instead of a list' => array(200, '{"success":true}'),
	'not found (the route moved)' => array(404, '{"detail":"Not Found"}')) as $what => $answer) {
	stub($answer);
	$r = FieldCampUnits::lookup($MT);
	check("$what: a plain message, no units", $r['error'] === 'Macrostrat did not answer. Please try again in a few minutes.' && $r['units'] === array(), $r['error']);
}
stub(array(200, fixture('montana')), array(500, 'boom'));
$r = FieldCampUnits::lookup($MT);
check('a failing source lookup does not fail the units', $r['error'] === '' && count($r['units']) === 14 && $r['sources'][543]['name'] === 'Macrostrat source map 543' && $r['sources'][543]['ref_title'] === '');

// ===========================================================================
section('Service: saving a lookup');
$campid = new_camp('Units Camp', $MT);
$camp = FieldCamp::getCamp($db, $campid);
check('no lookup yet: area not "changed"', FieldCampUnits::areaChanged($camp) === false && FieldCampUnits::listUnits($db, $campid) === array());
stub(array(200, fixture('montana')));
$lookup = FieldCampUnits::lookup(FieldCampUnits::campBbox($camp));
$saved = FieldCampUnits::save($db, $camp, $lookup);
check('saved: 14 added', $saved['error'] === '' && $saved['total'] === 14 && $saved['added'] === 14 && $saved['removed'] === 0, $saved);
$units = FieldCampUnits::listUnits($db, $campid);
check('14 rows in map order', count($units) === 14 && $units[0]->map_unit_name === 'Alluvium' && $units[13]->map_unit_name === 'Pierre Formation');
$names = array(); $labels = array(); $ids = array();
foreach ($units as $u) { $names[] = $u->tag_name; $labels[] = $u->unit_label; $ids[] = (string)$u->tag_id; }
check('tag names are unique', count(array_unique(array_map('mb_strtolower', $names))) === 14, $names);
check('the three Gravel units are told apart by age',
	in_array('Gravel (Holocene and/or Pleistocene, and/or Pliocene)', $names, true) && in_array('Gravel (Holocene or Pleistocene)', $names, true) && in_array('Gravel (Tertiary)', $names, true), $names);
check('a name that comes up once is kept as it is', in_array('Hell Creek Formation', $names, true) && in_array('Alluvium', $names, true));
check('unit labels are unique', count(array_unique(array_map('mb_strtolower', $labels))) === 14, $labels);
check('labels read like map labels', $labels[0] === 'Qal' && in_array('Khc', $labels, true) && in_array('Kpi', $labels, true) && in_array('Kfht', $labels, true) && in_array('Kfht2', $labels, true), $labels);
check('tag ids are 14 different UUIDs, as the app gives its tags', count(array_unique($ids)) === 14
	&& count(preg_grep('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $ids)) === 14, $ids[0]);
check('every unit starts selected', count(FieldCampUnits::listUnits($db, $campid, true)) === 14);
$sources = FieldCampUnits::listSources($db, $campid);
check('source map saved', count($sources) === 1 && $sources[543]->name === 'Geologic map of Testland');
$camp = FieldCamp::getCamp($db, $campid);
check('the camp records the lookup', (string)$camp->units_fetched_date !== '' && (int)$camp->units_zoom === 10 && $camp->units_bbox === '-104.83173,45.66478,-104.23382,46.71281', $camp->units_bbox);
check('same area: not changed', FieldCampUnits::areaChanged($camp) === false);

// ===========================================================================
section('Service: the selection');
$by = units_by_name($db, $campid);
$rows = array();
foreach ($units as $u) {
	$rows[] = array('pkey' => (int)$u->pkey, 'chosen' => strpos($u->tag_name, 'Gravel') !== 0, 'name' => $u->tag_name, 'label' => $u->unit_label);
}
$hcPkey = (int)$by['Hell Creek Formation']->pkey; $hcTagId = (string)$by['Hell Creek Formation']->tag_id;
foreach ($rows as &$row) { if ($row['pkey'] === $hcPkey) { $row['name'] = ' Hell Creek Fm '; $row['label'] = 'Khc*'; } }
unset($row);
check('selection saved', FieldCampUnits::saveSelection($db, $camp, $rows) === array());
$by = units_by_name($db, $campid);
check('three Gravel units deselected, 11 left', count(FieldCampUnits::listUnits($db, $campid, true)) === 11);
check('name and label changed (trimmed)', isset($by['Hell Creek Fm']) && $by['Hell Creek Fm']->unit_label === 'Khc*' && (int)$by['Hell Creek Fm']->pkey === $hcPkey);

$bad = $rows;
foreach ($bad as &$row) { if ($row['pkey'] === $hcPkey) $row['name'] = ''; }
unset($row);
$errs = FieldCampUnits::saveSelection($db, $camp, $bad);
check('a selected unit needs a name', count($errs) === 1 && strpos($errs[0], 'needs a tag name') !== false, $errs);
$bad = $rows;
foreach ($bad as &$row) { if ($row['pkey'] === $hcPkey) $row['label'] = '  '; }
unset($row);
$errs = FieldCampUnits::saveSelection($db, $camp, $bad);
check('a selected unit needs a label', count($errs) === 1 && strpos($errs[0], 'needs a unit label') !== false, $errs);
$bad = $rows;
foreach ($bad as &$row) { if ($row['pkey'] === $hcPkey) $row['name'] = 'pierre formation'; }
unset($row);
$errs = FieldCampUnits::saveSelection($db, $camp, $bad);
check('two selected units cannot share a name (any case)', count($errs) === 1 && strpos($errs[0], 'both named') !== false, $errs);
$bad = $rows;
foreach ($bad as &$row) { if ($row['pkey'] === $hcPkey) { $row['name'] = str_repeat('n', 121); $row['label'] = str_repeat('l', 21); } }
unset($row);
$errs = FieldCampUnits::saveSelection($db, $camp, $bad);
check('too long a name and label are 2 problems', count($errs) === 2, $errs);
$by = units_by_name($db, $campid);
check('a refused save changes nothing', isset($by['Hell Creek Fm']) && $by['Hell Creek Fm']->unit_label === 'Khc*' && count(FieldCampUnits::listUnits($db, $campid, true)) === 11);
$bad = array(array('pkey' => (int)$by['Gravel (Tertiary)']->pkey, 'chosen' => false, 'name' => '', 'label' => ''));
check('a deselected unit may have empty fields; it keeps what it had',
	FieldCampUnits::saveSelection($db, $camp, $bad) === array() && FieldCampUnits::listUnits($db, $campid)[3]->tag_name === 'Gravel (Tertiary)' && FieldCampUnits::listUnits($db, $campid)[3]->unit_label !== '');

$otherid = new_camp('Other Units Camp', $MT);
$other = FieldCamp::getCamp($db, $otherid);
check('another camp cannot change this camp\'s units',
	FieldCampUnits::saveSelection($db, $other, array(array('pkey' => $hcPkey, 'chosen' => false, 'name' => 'Hijacked', 'label' => 'X'))) === array()
	&& isset(units_by_name($db, $campid)['Hell Creek Fm']) && FieldCamp::truthy(units_by_name($db, $campid)['Hell Creek Fm']->chosen));

// ===========================================================================
section('Service: looking up again');
$again = json_decode(fixture('montana'), true);
$again = array_values(array_filter($again, function ($r) { return !in_array($r['legend_id'], array(128528, 128755), true); }));   // Alluvium, Pierre gone
foreach ($again as &$row) { if ($row['legend_id'] === 128645) { $row['descrip'] = 'New description.'; $row['map_unit_name'] = 'Hell Creek Formation (revised)'; } }
unset($row);
$again[] = array('legend_id' => 999001, 'source_id' => 777, 'scale' => 'large', 'map_unit_name' => 'Hell Creek Fm', 'strat_name' => null, 'age' => 'Cretaceous',
	'lith' => 'sandstone', 'descrip' => 'A new neighbour.', 'comments' => null, 't_age' => 66.0, 'b_age' => 70.0, 'lith_classes' => array('sedimentary'), 'lith_types' => array('siliciclastic'), 'color' => '#123456');
stub(array(200, json_encode($again)));
$saved = FieldCampUnits::save($db, $camp, FieldCampUnits::lookup(FieldCampUnits::campBbox($camp)));
check('second lookup: 1 added, 2 removed, 13 in all', $saved['error'] === '' && $saved['added'] === 1 && $saved['removed'] === 2 && $saved['total'] === 13, $saved);
$by = units_by_name($db, $campid);
check('a kept unit keeps the instructor\'s name, label, selection and its tag id',
	isset($by['Hell Creek Fm']) && $by['Hell Creek Fm']->unit_label === 'Khc*' && $by['Hell Creek Fm']->tag_id === $hcTagId && FieldCamp::truthy($by['Hell Creek Fm']->chosen));
check('and takes Macrostrat\'s new text', $by['Hell Creek Fm']->descrip === 'New description.' && $by['Hell Creek Fm']->map_unit_name === 'Hell Creek Formation (revised)');
check('deselected units stay deselected', !FieldCamp::truthy($by['Gravel (Tertiary)']->chosen));
check('removed units are gone', !isset($by['Alluvium']) && !isset($by['Pierre Formation']));
check('a new unit whose name is taken gets its own name and label', isset($by['Hell Creek Fm 2']) && (int)$by['Hell Creek Fm 2']->legend_id === 999001 && $by['Hell Creek Fm 2']->unit_label === 'Khcf', array_keys($by));
check('its tag id is a new one', !in_array($by['Hell Creek Fm 2']->tag_id, $ids, true) && strlen($by['Hell Creek Fm 2']->tag_id) === 36);
check('sources replaced', array_keys(FieldCampUnits::listSources($db, $campid)) === array(543, 777));

FieldCamp::saveArea($db, $campid, array('west' => -104.8, 'south' => 45.7, 'east' => -104.3, 'north' => 46.6));
check('after a new study area the lookup is out of date', FieldCampUnits::areaChanged(FieldCamp::getCamp($db, $campid)) === true);

// ===========================================================================
section('Tags: ages');
$a = FieldCampUnits::ageFields(66.0, 100.5);
check('Late Cretaceous', $a['eon'] === array('phanerozoic') && $a['era_phanerozoic'] === array('mesozoic') && $a['period_mesozoic'] === array('cretaceous')
	&& !isset($a['period_cenozoic']) && $a['min_absolute_age_of_geol_unit'] === 66.0 && $a['max_absolute_age_of_geol_unit'] === 100.5, $a);
$a = FieldCampUnits::ageFields(0, 0.0117);
check('Holocene', $a['period_cenozoic'] === array('quaternary') && $a['epoch_quaternary'] === array('holocene') && !isset($a['epoch_neogene']), $a);
$a = FieldCampUnits::ageFields(0, 5.333);
check('Holocene to Pliocene keeps the thin Holocene', $a['epoch_quaternary'] === array('holocene', 'pleistocene') && $a['epoch_neogene'] === array('pliocene') && $a['period_cenozoic'] === array('quaternary', 'neogene'), $a);
$a = FieldCampUnits::ageFields(0.0117, 2.588);
check('Pleistocene on an older time scale (base 2.588) does not add the Pliocene', $a['epoch_quaternary'] === array('pleistocene') && !isset($a['epoch_neogene']), $a);
$a = FieldCampUnits::ageFields(485.4, 538.8);
check('Cambrian on an older time scale (top 485.4) does not add the Ordovician', $a['period_paleozoic'] === array('cambrian'), $a);
$a = FieldCampUnits::ageFields(303.7, 304.1);
check('Pennsylvanian is also Carboniferous', $a['period_paleozoic'] === array('carboniferous', 'pennsylvanian'), $a);
$a = FieldCampUnits::ageFields(1690, 1750);
check('Paleoproterozoic, spelled as the form spells it', $a['eon'] === array('proterozoic') && $a['era_proterozoic'] === array('paleoproterozo') && !isset($a['era_phanerozoic']), $a);
$a = FieldCampUnits::ageFields(2600, 3000);
check('Archean', $a['eon'] === array('archean') && $a['era_archean'] === array('neoarchean', 'mesoarchean'), $a);
$a = FieldCampUnits::ageFields(100, 100);
check('a single age', $a['period_mesozoic'] === array('cretaceous') && $a['era_phanerozoic'] === array('mesozoic'), $a);
$a = FieldCampUnits::ageFields(56, 67.054);
check('a unit across the K-Pg boundary names both', $a['era_phanerozoic'] === array('cenozoic', 'mesozoic') && $a['epoch_paleogene'] === array('paleocene') && $a['period_mesozoic'] === array('cretaceous'), $a);
check('no ages, no age fields', FieldCampUnits::ageFields(null, null) === array() && FieldCampUnits::ageFields('', 5) === array());
check('ages given the wrong way round are read anyway', FieldCampUnits::ageFields(100.5, 66.0) == FieldCampUnits::ageFields(66.0, 100.5));

// ===========================================================================
section('Tags: the three sample areas against the app\'s form');
check('the form copy was read (fields and choices)', count($formFields) > 50 && isset($formFields['unit_label_abbreviation']) && $formFields['unit_label_abbreviation']['required'] && count($formChoices) > 30);
$probe = array('id' => 'a7882593-d3e4-4836-8946-f024f45afdd9', 'created_timestamp' => 1794347052683, 'modified_timestamp' => 1794347052683,
	'name' => 'x', 'type' => 'geologic_unit', 'unit_label_abbreviation' => 'x');
// Tags written by the app itself (StraboField 2.32.0 on an iPad, Jason's reference backup of 11-10): one bare, one with
// nearly every field filled. The yardstick has to pass them, or it is measuring the wrong thing.
$appTags = json_decode(file_get_contents("$FIXDIR/app_written_tags.json"), true);
$appProblems = array();
foreach ($appTags as $t) { if ($t['type'] === 'geologic_unit') foreach (tag_problems($t) as $p) $appProblems[] = $t['name'] . ': ' . $p; }
check('the yardstick passes the tags the app wrote itself', count($appTags) === 3 && !$appProblems, $appProblems);
check('an old-style numeric id is caught', count(tag_problems(array('id' => 17000000000000) + $probe)) === 1);
check('the yardstick itself catches a bad value', count(tag_problems($probe + array('rock_type' => 'granite'))) === 1);
check('the yardstick catches a field the form would hide', count(tag_problems($probe + array('sediment_type' => 'till'))) === 1 && count(tag_problems($probe + array('epoch_quaternary' => array('holocene')))) === 1);
check('the yardstick catches an unknown field', count(tag_problems($probe + array('lithology' => 'x'))) === 1);
check('the yardstick passes a good tag', tag_problems($probe + array('rock_type' => 'sediment', 'sediment_type' => 'till', 'eon' => array('phanerozoic'))) === array());

$tagsets = array();
foreach (array('montana' => 14, 'ksmo' => 11, 'baraboo' => 26) as $name => $n) {
	$cid = new_camp("Tags $name", $MT);
	$c = FieldCamp::getCamp($db, $cid);
	stub(array(200, fixture($name)));
	$saved = FieldCampUnits::save($db, $c, FieldCampUnits::lookup(FieldCampUnits::campBbox($c)));
	$tags = FieldCampUnits::tagsForCamp($db, $cid);
	$tagsets[$name] = array();
	$problems = array();
	foreach ($tags as $t) {
		$tagsets[$name][$t['name']] = $t;
		foreach (tag_problems($t) as $p) $problems[] = $t['name'] . ': ' . $p;
		if (strpos($t['notes'] ?? '', 'from Macrostrat (macrostrat.org, CC-BY 4.0)') === false) $problems[] = $t['name'] . ': no attribution';
	}
	$expect = $name === 'baraboo' ? $n - 1 : $n;   // Water starts deselected
	check("$name: $expect tags, all valid for the app's form", $saved['total'] === $n && count($tags) === $expect && !$problems, $problems ? $problems : count($tags));
	check("$name: tag names and ids are unique", count($tagsets[$name]) === $expect && count(array_unique(array_column($tags, 'id'))) === $expect);
}
$t = $tagsets['montana']['Hell Creek Formation'];
check('Hell Creek: sedimentary, Cretaceous, label, color, description', $t['rock_type'] === 'sedimentary' && !isset($t['sedimentary_rock_type'])
	&& $t['period_mesozoic'] === array('cretaceous') && $t['unit_label_abbreviation'] === 'Khc' && $t['color'] === '#a4e24f'
	&& strpos($t['description'], 'sandstone') !== false && $t['map_unit_name'] === 'Hell Creek Formation', $t);
check('its notes hold the age, lithology, the fuller strat name and the citation',
	strpos($t['notes'], "Age: Late Cretaceous.\nLithology: claystone, sandstone, shale.\nStratigraphic name: Hell Creek Formation of Montana Group.\nSource: Tester, A., 2007, Geologic map of Testland: Test Survey Map 62, scale 1:500,000, from Macrostrat") === 0
	&& strpos($t['notes'], 'source map 543, legend entry 128645.') !== false, $t['notes']);
$t = $tagsets['montana']['Alluvium'];
check('Alluvium: sediment / alluvium', $t['rock_type'] === 'sediment' && $t['sediment_type'] === 'alluvium' && $t['unit_label_abbreviation'] === 'Qal', $t);
$t = $tagsets['montana']['Fox Hills Formation, Colgate Member'];
check('one lithology names the rock type; "minor coal" does not count', $t['sedimentary_rock_type'] === 'sandstone'
	&& !isset($tagsets['montana']['Fort Union Formation, Ludlow Member']['sedimentary_rock_type']), $t);
check('a unit with no lithology class gets no rock type', !isset($tagsets['montana']['Pierre Formation']['rock_type']) && !isset($tagsets['ksmo']['Douglas Group']['rock_type']));
$t = $tagsets['baraboo']['Granite in Baxter Hollow'];
check('granite: igneous / plutonic / granite, Paleoproterozoic', $t['rock_type'] === 'igneous' && $t['igneous_rock_class'] === 'plutonic' && $t['plutonic_rock_types'] === 'granite' && $t['era_proterozoic'] === array('paleoproterozo'), $t);
$t = $tagsets['baraboo']['Rhyolite near the Lower Narrows'];
check('rhyolite: igneous / volcanic / rhyolite', $t['igneous_rock_class'] === 'volcanic' && $t['volcanic_rock_type'] === 'rhyolite', $t);
$t = $tagsets['baraboo']['Baraboo Quartzite'];
check('quartzite: metamorphic / quartzite', $t['rock_type'] === 'metamorphic' && $t['metamorphic_rock_types'] === 'quartzite', $t);
check('two rock classes: left for the instructor', !isset($tagsets['baraboo']['Baraboo Formation']['rock_type']));
check('talus is sediment even when its clasts are metamorphic', $tagsets['baraboo']['Talus']['rock_type'] === 'sediment' && $tagsets['baraboo']['Talus']['sediment_type'] === 'talus');
check('Water is not a tag unless selected', !isset($tagsets['baraboo']['Water']));
check('five Till units have five names', count(array_filter(array_keys($tagsets['baraboo']), function ($n) { return strpos($n, 'Till') === 0; })) === 5, array_keys($tagsets['baraboo']));
check('a unit with no color has no color key', (function () use ($db) {
	$u = (object)array('tag_id' => 'fd625467-c057-4fd4-87a8-1b7f855cb2cf', 'created_date' => '2026-11-10 15:00:00-06', 'tag_name' => 'W', 'unit_label' => 'w', 'map_unit_name' => 'W', 'color' => '', 'lith_classes' => '', 'lith_types' => '',
		'lith' => '', 't_age' => null, 'b_age' => null, 'descrip' => '', 'age' => '', 'strat_name' => '', 'comments' => '', 'source_id' => 1, 'legend_id' => 2);
	$t = FieldCampUnits::buildTag($u, null);
	return !isset($t['color']) && !isset($t['description']) && tag_problems($t) === array() && $t['created_timestamp'] === 1794344400000 && $t['notes'] === 'Source: from Macrostrat (macrostrat.org, CC-BY 4.0), source map 1, legend entry 2.';
})());
check('tags are plain JSON', json_decode(json_encode($tagsets['baraboo']), true) == $tagsets['baraboo']);

// ===========================================================================
section('HTTP: the Rock units step');
FieldCampUnits::$fetcher = null;
$sidI = forge($INSTR, $FIX[$INSTR]);
$sidO = forge($OUTSIDER, $FIX[$OUTSIDER]);
FieldCamp::saveArea($db, $campid, $MT);

list($code) = http('GET', "/field_camp?id=$campid&step=units", $sidO);
check('someone not on the instructor list gets 404', $code === 404, $code);
list($code) = http('GET', "/field_camp?id=$campid&step=units", null);
check('not signed in: no page', $code !== 200, $code);
list($code, $body) = http('GET', "/field_camp?id=$campid&step=units", $sidI);
check('the owner opens the step', $code === 200 && !php_error($body) && strpos($body, 'Rock units') !== false, $code);
check('it lists the saved units with their labels and colors', strpos($body, 'value="Hell Creek Fm"') !== false && strpos($body, 'value="Khc*"') !== false
	&& strpos($body, 'background:#a4e24f;') !== false && strpos($body, 'Geologic map of Testland') !== false);
check('13 unit rows, 10 selected', substr_count($body, 'class="fc-unit" data-pkey=') === 13 && preg_match('~<strong id="fcUnitCount">10</strong> of 13~', $body) === 1,
	substr_count($body, 'class="fc-unit" data-pkey='));
check('the steps bar offers Rock units between Study area and Accounts', preg_match('~step=area".*step=units".*step=accounts"~s', $body) === 1);
check('attribution is on the page', strpos($body, 'CC-BY 4.0') !== false);
list($code, $body) = http('GET', "/field_camp?id=$campid&step=maps", $sidI);
check('the step before (Maps) leads on to Rock units', $code === 200 && strpos($body, 'Next: Rock units') !== false);

$noarea = new_camp('No Area Camp', null);
list($code, $body) = http('GET', "/field_camp?id=$noarea&step=units", $sidI);
check('without a study area the step says so', $code === 200 && !php_error($body) && strpos($body, 'Choose the study area first') !== false && strpos($body, 'units_fetch') === false);
list($code, $body) = post_follow($sidI, array('action' => 'units_fetch', 'id' => $noarea));
check('a lookup without a study area is refused', $code === 200 && strpos($body, 'Choose the study area first.') !== false && FieldCampUnits::listUnits($db, $noarea) === array());

$by = units_by_name($db, $campid);
$rows = array();
foreach (FieldCampUnits::listUnits($db, $campid) as $u) {
	$rows[] = array('pkey' => (int)$u->pkey, 'chosen' => FieldCamp::truthy($u->chosen), 'name' => $u->tag_name, 'label' => $u->unit_label);
}
$post = $rows;
foreach ($post as &$row) { if ($row['name'] === 'Hell Creek Fm 2') { $row['chosen'] = false; } if ($row['name'] === 'Arikaree Formation') { $row['label'] = 'Ta'; } }
unset($row);
list($code, $body) = post_follow($sidI, array('action' => 'units_save', 'id' => $campid, 'units_json' => json_encode($post)));
$by = units_by_name($db, $campid);
check('saving the step works', $code === 200 && strpos($body, 'Saved. 9 rock units will become geologic unit tags in the project.') !== false
	&& $by['Arikaree Formation']->unit_label === 'Ta' && !FieldCamp::truthy($by['Hell Creek Fm 2']->chosen), $code);

$post = $rows;
foreach ($post as &$row) { if ($row['name'] === 'Arikaree Formation') { $row['name'] = 'Typed <b>name</b>'; $row['label'] = ''; } }
unset($row);
list($code, $body) = post_follow($sidI, array('action' => 'units_save', 'id' => $campid, 'units_json' => json_encode($post)));
check('a refused save names the problem and keeps what was typed', $code === 200 && strpos($body, 'needs a unit label') !== false
	&& strpos($body, 'value="Typed &lt;b&gt;name&lt;/b&gt;"') !== false && strpos($body, '<b>name</b>') === false
	&& units_by_name($db, $campid)['Arikaree Formation']->unit_label === 'Ta');

list($code, $body) = post_follow($sidI, array('action' => 'units_save', 'id' => $campid, 'units_json' => 'not json'));
check('an unreadable list is refused', $code === 200 && strpos($body, 'could not be read') !== false);
list($code, $body) = post_follow($sidI, array('action' => 'units_save', 'id' => $campid, 'units_json' => json_encode($rows)), 'wrong-token');
check('a wrong form token changes nothing', strpos($body, 'The page had expired') !== false && !FieldCamp::truthy(units_by_name($db, $campid)['Hell Creek Fm 2']->chosen));
$post = array(array('pkey' => (int)$by['Arikaree Formation']->pkey, 'chosen' => true, 'name' => 'Outsider', 'label' => 'X'));
list($code) = http('POST', '/field_camp_action', $sidO, array('action' => 'units_save', 'id' => $campid, 'units_json' => json_encode($post), 'csrf' => $TOKEN));
check('an outsider cannot save units', $code === 404 && isset(units_by_name($db, $campid)['Arikaree Formation']), $code);
list($code) = http('POST', '/field_camp_action', $sidO, array('action' => 'units_fetch', 'id' => $campid, 'csrf' => $TOKEN));
check('an outsider cannot start a lookup', $code === 404, $code);

// ===========================================================================
section('Live: the real Macrostrat, once (Baraboo, Wisconsin)');
$ch = curl_init('https://macrostrat.org/api/v3/map/carto/legend?bounds=-89.75,43.41,-89.73,43.43&zoom=10');
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30));
$probeBody = curl_exec($ch); $probeCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($probeCode !== 200 || !is_array(json_decode((string)$probeBody, true))) {
	$skip += 3;
	echo "  SKIP  Macrostrat's legend route did not answer from here (HTTP $probeCode); 3 live checks skipped\n";
} else {
	$live = new_camp('Live Units Camp', array('west' => -89.80, 'south' => 43.38, 'east' => -89.65, 'north' => 43.48));
	list($code, $body) = post_follow($sidI, array('action' => 'units_fetch', 'id' => $live));
	$liveUnits = FieldCampUnits::listUnits($db, $live);
	check('the wizard looked the units up and saved them', $code === 200 && !php_error($body) && count($liveUnits) >= 5 && preg_match('~\d+ rock units found on \d+ source map~', $body) === 1, count($liveUnits));
	$liveSources = FieldCampUnits::listSources($db, $live);
	$first = $liveSources ? reset($liveSources) : null;
	check('with the source map\'s name and citation', $first && strpos((string)$first->name, 'Macrostrat source map') !== 0 && (string)$first->ref_title !== '', $first);
	$problems = array();
	foreach (FieldCampUnits::tagsForCamp($db, $live) as $t) { foreach (tag_problems($t) as $p) $problems[] = $t['name'] . ': ' . $p; }
	check('and today\'s answer makes tags that are valid for the app\'s form', !$problems && count(FieldCampUnits::tagsForCamp($db, $live)) >= 5, $problems);
}

// ===========================================================================
cleanup();
foreach ($sids as $sid) @unlink("$SESS/sess_$sid");

echo "\n" . ($fail === 0 ? "ALL PASS" : "FAILURES") . ": $pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail === 0 ? 0 : 1);
