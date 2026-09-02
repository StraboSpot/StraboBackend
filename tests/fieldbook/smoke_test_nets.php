<?php
/**
 * File: tests/fieldbook/smoke_test_nets.php
 * Description: M3 smoke suite for the fieldbook stereonets
 *              (docs/Fieldbook_Design.md §7, §12 M3). Pure math first
 *              (equal-area projection of hand-computed lines and poles,
 *              great-circle end points and dip vector, vertical and
 *              horizontal planes, pole orthogonal to every great-circle
 *              sample, a real associated lineation lying on its plane),
 *              then measurement extraction (right-hand-rule strike from a
 *              dip direction, out-of-range and non-numeric angles omitted,
 *              associated lineations flattened), the book-wide symbol
 *              registry (fixed slots, distinct symbols per type, prime()),
 *              figure rules (spot nets always draw great circles, dataset
 *              nets only up to 20 planes, per-type nets for types with
 *              >= 2 measurements), colophon counts, and the renderer on a
 *              synthetic model (spot / dataset net counts, nets=off, the
 *              single-day 800-spot list paginating instead of one page per
 *              spot).
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/fieldbook/smoke_test_nets.php
 */
chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('memory_limit', '1G');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
require_once 'includes/fieldbook/FieldbookNets.php';
require_once 'includes/fieldbook/FieldbookModel.php';
require_once 'includes/fieldbook/FieldbookRenderer.php';
require_once 'includes/fieldbook/FieldbookMaps.php';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function near($a, $b, $eps = 1e-6) { return abs($a - $b) < $eps; }
function pt($p) { return sprintf('(%.4f, %.4f)', $p[0], $p[1]); }
/** Inverse of the projection: unit-disc point => (E, N, up) unit vector on the lower hemisphere. */
function unproject($p) {
	$r = hypot($p[0], $p[1]);
	$plunge = 90.0 - 2.0 * rad2deg(asin(min(1.0, $r / sqrt(2.0))));
	$trend = rad2deg(atan2($p[0], $p[1]));
	return array(cos(deg2rad($plunge)) * sin(deg2rad($trend)), cos(deg2rad($plunge)) * cos(deg2rad($trend)), -sin(deg2rad($plunge)));
}
function poleVector($strike, $dip) {
	list($t, $p) = FieldbookNets::pole($strike, $dip);
	return array(cos(deg2rad($p)) * sin(deg2rad($t)), cos(deg2rad($p)) * cos(deg2rad($t)), -sin(deg2rad($p)));
}
function dot($a, $b) { return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2]; }

echo "Enhanced fieldbook M3 stereonet smoke suite\n";

// ------------------------------------------------------------------ 1. projection
$p = FieldbookNets::projectLine(0, 90);
check('vertical line plots at the centre', near($p[0], 0) && near($p[1], 0), pt($p));
$p = FieldbookNets::projectLine(0, 0);
check('horizontal line due north plots at the top of the primitive (0, 1)', near($p[0], 0) && near($p[1], 1), pt($p));
$p = FieldbookNets::projectLine(90, 0);
check('horizontal line due east plots at (1, 0)', near($p[0], 1) && near($p[1], 0), pt($p));
$p = FieldbookNets::projectLine(180, 30);
check('180/30 plots due south at r = sqrt(2) sin(30 deg) = 0.7071', near($p[0], 0) && near($p[1], -0.70710678), pt($p));
$p = FieldbookNets::projectLine(45, 60);
$r = sqrt(2) * sin(deg2rad(15));
check('045/60 plots at r = 0.3660 on the NE diagonal', near($p[0], $r * sin(M_PI / 4)) && near($p[1], $r * cos(M_PI / 4)), pt($p));
$p = FieldbookNets::projectLine(-90, 0); $q = FieldbookNets::projectLine(270, 0);
check('trend normalised: -90 == 270 (due west)', near($p[0], -1) && near($p[1], 0) && near($q[0], -1), pt($p));

// poles (right-hand rule)
list($t, $pl) = FieldbookNets::pole(90, 45);
check('pole of 090/45 (dips south) = 000/45', near($t, 0) && near($pl, 45), "$t/$pl");
list($t, $pl) = FieldbookNets::pole(0, 90);
check('pole of a vertical N-S plane = 270/00', near($t, 270) && near($pl, 0), "$t/$pl");
list($t, $pl) = FieldbookNets::pole(45, 0);
check('pole of a horizontal plane is vertical (plunge 90)', near($pl, 90), "$t/$pl");

// great circles
$gc = FieldbookNets::greatCircle(90, 45);
check('great circle has SAMPLES + 1 points', count($gc) === FieldbookNets::SAMPLES + 1, count($gc));
check('090/45: starts at the east end of the strike line (1, 0)', near($gc[0][0], 1) && near($gc[0][1], 0), pt($gc[0]));
check('090/45: ends at the west end (-1, 0)', near($gc[60][0], -1) && near($gc[60][1], 0), pt($gc[60]));
$mid = $gc[30];
check('090/45: mid point is the dip vector 180/45, bowed south to r = 0.5412', near($mid[0], 0) && near($mid[1], -sqrt(2) * sin(deg2rad(22.5))), pt($mid));
$gc = FieldbookNets::greatCircle(0, 90);
$maxX = 0; foreach ($gc as $q) $maxX = max($maxX, abs($q[0]));
check('vertical N-S plane: great circle is the N-S diameter (|x| ~ 0)', $maxX < 1e-9, $maxX);
$gc = FieldbookNets::greatCircle(30, 0);
$minR = 2; foreach ($gc as $q) $minR = min($minR, hypot($q[0], $q[1]));
check('horizontal plane: great circle is the primitive (r = 1 everywhere)', near($minR, 1, 1e-9), $minR);
// pole orthogonal to every sample of the circle, for a spread of planes (inverse projection round trip)
$worst = 0;
foreach (array(array(90, 45), array(131, 54), array(0, 90), array(217, 12), array(340, 88), array(61, 85)) as $sd) {
	$n = poleVector($sd[0], $sd[1]);
	foreach (FieldbookNets::greatCircle($sd[0], $sd[1]) as $q) $worst = max($worst, abs(dot($n, unproject($q))));
}
check('pole is orthogonal to every great-circle sample (6 planes, worst |dot| < 1e-6)', $worst < 1e-6, $worst);
// a real associated lineation (QAQC TW1-2: foliation 131/54 with mineral lineation 184/48) lies on its plane
$d = abs(dot(poleVector(131, 54), unproject(FieldbookNets::projectLine(184, 48))));
check('real associated lineation 184/48 lies on foliation 131/54 (|dot| < 0.02)', $d < 0.02, $d);
list($t, $pl) = FieldbookNets::vectorToTrendPlunge(array(0, 0.5, 0.8660254));
check('upward vector is flipped to the lower hemisphere (180/60)', near($t, 180, 1e-6) && near($pl, 60, 1e-6), "$t/$pl");

// ------------------------------------------------------------------ 2. measurements
function row($planar, $feature, $a, $b, $dipdir = '', $children = array()) {
	return array('kind' => $planar ? 'Plane' : 'Line', 'planar' => $planar, 'feature' => $feature, 'a' => $a, 'b' => $b, 'dipdir' => $dipdir, 'quality' => '', 'facing' => '', 'notes' => '', 'more' => array(), 'children' => $children);
}
list($ms, $skipped) = FieldbookNets::measurements(array(
	row(true, 'Foliation', '120', '30'),
	row(true, 'Foliation', '', '87', '150'),           // strike from dip direction
	row(true, 'Other', '', '76'),                      // no strike at all: skipped
	row(true, 'Bedding', '10', '95'),                  // dip > 90: skipped
	row(false, 'Stretching', 'abc', '20'),             // non-numeric: skipped
	row(false, 'Fold hinge', '200', '15', '', array(row(false, 'Stretching', '210', '12'), row(false, '', '', ''))),
));
check('measurements: 4 plottable, 4 skipped (missing strike, dip > 90, non-numeric, empty child)', count($ms) === 4 && $skipped === 4, count($ms) . '/' . $skipped);
check('strike from dip direction: 150 => 60 (right-hand rule)', near($ms[1]['strike'], 60) && $ms[1]['planar'], json_encode($ms[1]));
check('associated lineation flattened and flagged', $ms[3]['associated'] === true && $ms[3]['feature'] === 'Stretching' && $ms[2]['associated'] === false);
list($ms, $skipped) = FieldbookNets::measurements(array(row(true, 'Foliation', '361', '30')));
check('strike 361 normalises to 1', near($ms[0]['strike'], 1));

// ------------------------------------------------------------------ 3. symbols
$nets = new FieldbookNets();
$s = $nets->symbol(true, 'Foliation');
check('foliation pole = filled black square (fixed slot)', $s['shape'] === 'square' && $s['filled'] && $s['tone'] === 0 && $s['label'] === 'Foliation', json_encode($s));
$s = $nets->symbol(false, 'Fold hinge');
check('fold hinge line = open circle (fixed slot)', $s['shape'] === 'circle' && !$s['filled'] && $s['label'] === 'Fold hinge', json_encode($s));
$a = $nets->symbol(true, 'Fold axial surface'); $b = $nets->symbol(true, 'Plane of boudinage'); $c = $nets->symbol(true, 'Fold axial surface');
check('unknown plane types get distinct free symbols, stable on repeat', ($a['shape'] !== $b['shape'] || $a['tone'] !== $b['tone']) && $a === $c && $a['shape'] !== 'square', json_encode(array($a, $b)));
$s = $nets->symbol(true, '');
check('plane without a feature type is labelled Plane', $s['label'] === 'Plane');
$s = $nets->symbol(false, '');
check('line without a feature type is labelled Line', $s['label'] === 'Line');
$seen = array(); $distinct = true;
for ($i = 0; $i < 20; $i++) { $s = $nets->symbol(true, "Type $i"); $k = $s['shape'] . '/' . $s['tone']; if (isset($seen[$k])) $distinct = false; $seen[$k] = 1; }
check('24 plane types keep distinct (shape, tone) pairs', $distinct && count($seen) === 20, count($seen));
// prime(): the fixed slots survive an unknown type arriving first
$n1 = new FieldbookNets();
$w = $n1->symbol(true, 'Weird'); $bd = $n1->symbol(true, 'Bedding');
check('without prime an early unknown type takes the circle and bedding loses its slot', $w['shape'] === 'circle' && $bd['shape'] !== 'circle');
$n2 = new FieldbookNets();
$n2->prime(array(row(true, 'Weird', '10', '20'), row(true, 'Bedding', '10', '20')));
$w = $n2->symbol(true, 'Weird'); $bd = $n2->symbol(true, 'Bedding');
check('with prime bedding keeps the circle and the unknown type moves on', $bd['shape'] === 'circle' && $w['shape'] !== 'circle', json_encode(array($w, $bd)));

// ------------------------------------------------------------------ 4. figures
$nets = new FieldbookNets();
list($ms, $sk) = FieldbookNets::measurements(array(row(true, 'Foliation', '61', '85'), row(false, 'Stretching', '240', '10'), row(true, 'Other', '', '30')));
$fig = $nets->figure($ms, $sk);
check('spot figure: 2 plotted, 1 skipped, great circles on, 2 legend kinds with counts', $fig['n'] === 2 && $fig['skipped'] === 1 && $fig['circles'] && $fig['kinds'] === 2 && count($fig['planes']) === 1 && $fig['planes'][0]['circle'] !== null && count($fig['lines']) === 1 && $fig['legend'][0]['count'] === 1, json_encode(array($fig['n'], $fig['skipped'], $fig['kinds'])));
$pole = $fig['planes'][0]['pole'];
list($t, $pl) = FieldbookNets::pole(61, 85);
$exp = FieldbookNets::projectLine($t, $pl);
check('spot figure pole of 061/85 sits near the NW primitive (331/05)', near($pole[0], $exp[0]) && near($pole[1], $exp[1]) && $pole[0] < 0 && $pole[1] > 0 && hypot($pole[0], $pole[1]) > 0.9, pt($pole));
check('counters: 1 spot net, 0 dataset nets', $nets->spotNets === 1 && $nets->datasetNets === 0);
$rows20 = array(); for ($i = 0; $i < 20; $i++) $rows20[] = row(true, 'Bedding', (string)(10 * $i), '40');
list($ms, $sk) = FieldbookNets::measurements($rows20);
$fig = $nets->figure($ms, $sk, null, true);
check('dataset net with 20 planes draws great circles', $fig['circles'] && $fig['planes'][0]['circle'] !== null && $fig['dataset']);
$rows21 = $rows20; $rows21[] = row(true, 'Bedding', '5', '40');
list($ms, $sk) = FieldbookNets::measurements($rows21);
$fig = $nets->figure($ms, $sk, null, true);
check('dataset net with 21 planes: poles only', !$fig['circles'] && $fig['planes'][0]['circle'] === null);
check('single-kind legend carries no count', $fig['kinds'] === 1 && count($fig['legend']) === 1 && $fig['legend'][0]['count'] === 21);

// dataset figures: combined + per type (>= 2), singletons only on the combined net
$nets = new FieldbookNets();
$rows = array_merge($rows20, array(row(false, 'Stretching', '100', '20'), row(false, 'Stretching', '110', '25'), row(true, 'Vein', '30', '60'), row(true, '', '', '40'), row(false, '', '5', '5'), row(false, '', '15', '15')));
$figs = $nets->datasetFigures($rows);
$titles = array(); foreach ($figs as $f) $titles[] = $f['title'];
check('datasetFigures: combined first, then Bedding, Stretching, Lines without a feature type (Vein singleton excluded)', $titles === array('All measurements', 'Bedding', 'Stretching', 'Lines without a feature type'), json_encode($titles));
check('combined figure n = 25, skipped 1 (plane without strike)', $figs[0]['fig']['n'] === 25 && $figs[0]['fig']['skipped'] === 1, json_encode(array($figs[0]['fig']['n'], $figs[0]['fig']['skipped'])));
check('per-type figures carry their own n', $figs[1]['fig']['n'] === 20 && $figs[2]['fig']['n'] === 2 && $figs[3]['fig']['n'] === 2);
check('book counters: 4 dataset nets, 25 plotted, 1 skipped', $nets->datasetNets === 4 && $nets->plotted === 25 && $nets->skipped === 1, json_encode(array($nets->datasetNets, $nets->plotted, $nets->skipped)));
$notes = $nets->notes();
check('notes: counts + one omitted line', count($notes) === 2 && strpos($notes[0], '4 dataset nets') !== false && strpos($notes[1], '1 orientation without usable angles') !== false, json_encode($notes));
$empty = $nets->datasetFigures(array(row(true, 'Other', '', '30')));
check('dataset without plottable measurements: no figures, skipped still counted', $empty === array() && $nets->skipped === 2);

// ------------------------------------------------------------------ 5. renderer integration (synthetic model, no database)
function feature($id, $name, $ori = null) {
	$p = array('id' => $id, 'name' => $name, 'geometrytype' => 'Point', 'modified_timestamp' => '1722400000000', 'notes' => "notes $name");
	if ($ori !== null) $p['orientation_data'] = $ori;
	return array('type' => 'Feature', 'geometry' => (object)array('type' => 'Point', 'coordinates' => array(-118.25, 34.05)), 'properties' => $p);
}
$plane = array('type' => 'planar_orientation', 'feature_type' => 'foliation', 'strike' => 61, 'dip' => 85, 'dip_direction' => 151, 'id' => 1,
	'associated_orientation' => array(array('type' => 'linear_orientation', 'feature_type' => 'stretching', 'trend' => 240, 'plunge' => 10, 'id' => 2)));
$noStrike = array('type' => 'planar_orientation', 'feature_type' => 'other', 'dip' => 30, 'id' => 3);
$features = array(
	feature('1721030400101', 'A', array($plane, $noStrike)),
	feature('1721034000102', 'B', array($noStrike)),           // table but no net
	feature('1721120400105', 'C'),                             // no orientations
	feature('1721120400106', 'D', array($plane, $plane)),
);
$tree = array(array('owner' => 1, 'project_id' => 'p', 'project_name' => 'Net Project', 'dsids' => array('d'), 'dataset_names' => array('d' => 'Net Dataset'),
	'spot_map' => array('1721030400101' => array('ds' => 'd'), '1721034000102' => array('ds' => 'd'), '1721120400105' => array('ds' => 'd'), '1721120400106' => array('ds' => 'd'))));
$meta = array('title' => 'Net Dataset', 'subtitle' => '', 'owner' => 'Tester', 'generated' => 'today', 'doi' => '', 'options' => array('map' => 'none', 'photos' => 'sheets', 'nets' => 'on', 'page' => 'letter'));
$model = FieldbookModel::build($features, array(), array(), $tree, $meta);
$rows = FieldbookModel::datasetOrientations($model->projects[0]['datasets'][0]);
check('model: datasetOrientations collects 5 rows (children lineations nested, not counted)', count($rows) === 5 && count($rows[0]['children']) === 1, count($rows));
$r = new FieldbookRenderer($model, null, new FieldbookMaps(array('set' => 'none')));
$pdf = $r->render();
$bytesOn = $pdf->Output('', 'S');
check('renderer: 2 spot nets (A, D), dataset nets = combined + Foliation + Stretching', $r->nets instanceof FieldbookNets && $r->nets->spotNets === 2 && $r->nets->datasetNets === 3, json_encode(array($r->nets->spotNets, $r->nets->datasetNets)));
check('renderer: book counters 6 plotted, 2 skipped', $r->nets->plotted === 6 && $r->nets->skipped === 2, json_encode(array($r->nets->plotted, $r->nets->skipped)));
check('renderer: no raster images added by the nets (vector paths only; cover logo at most)', preg_match_all('#/Subtype /Image#', $bytesOn) <= 2);
$meta['options']['nets'] = 'off';
$model2 = FieldbookModel::build($features, array(), array(), $tree, $meta);
$r2 = new FieldbookRenderer($model2, null, new FieldbookMaps(array('set' => 'none')));
$bytesOff = $r2->render()->Output('', 'S');
check('nets=off: no FieldbookNets, smaller book', $r2->nets === null && strlen($bytesOff) < strlen($bytesOn), strlen($bytesOff) . ' vs ' . strlen($bytesOn));

// single-day 800-spot dataset: the numbered list paginates (was one near-empty page per spot)
$many = array(); $map = array();
for ($i = 0; $i < 800; $i++) { $id = (string)(1721030400000 + $i * 7); $many[] = feature($id, 'S' . $i); $map[$id] = array('ds' => 'd'); }
$tree2 = array(array('owner' => 1, 'project_id' => 'p', 'project_name' => 'Big', 'dsids' => array('d'), 'dataset_names' => array('d' => 'Big'), 'spot_map' => $map));
$model3 = FieldbookModel::build($many, array(), array(), $tree2, $meta);
$r3 = new FieldbookRenderer($model3, null, new FieldbookMaps(array('set' => 'none')));
$bytes3 = $r3->render()->Output('', 'S');
$pages = preg_match_all('#/Type /Page\b#', $bytes3);
check('800 spots on one day: list paginates, book under 120 pages', $pages > 30 && $pages < 120, $pages);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
