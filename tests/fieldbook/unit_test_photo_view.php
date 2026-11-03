<?php
/**
 * File: tests/fieldbook/unit_test_photo_view.php
 * Description: Photo view direction under fieldbook figures (2026-11-03): FieldbookModel::imageBlocks
 *              carries view_azimuth_trend / view_angle_plunge as viewTrend / viewPlunge (0 kept,
 *              junk dropped, still listed in rows for the index), and the renderer's photoMeta line
 *              puts "view T°, plunge P°" right after the id.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/fieldbook/unit_test_photo_view.php
 */

chdir(dirname(__DIR__, 2)); $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require_once 'includes/straboClasses/straboOutputClass.php';
require_once 'includes/fieldbook/FieldbookModel.php';
require_once 'includes/fieldbook/FieldbookRenderer.php';

$pass = 0; $fail = 0;
function check($name, $cond) {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}

check('viewAngle: int', FieldbookModel::viewAngle(155) === '155');
check('viewAngle: 0 is a value', FieldbookModel::viewAngle(0) === '0');
check('viewAngle: negative plunge', FieldbookModel::viewAngle(-12) === '-12');
check('viewAngle: numeric string', FieldbookModel::viewAngle('40') === '40');
check('viewAngle: null / empty / text -> ""', FieldbookModel::viewAngle(null) === '' && FieldbookModel::viewAngle('') === '' && FieldbookModel::viewAngle('abc') === '');

$b = FieldbookModel::imageBlocks(array(
	array('id' => 1, 'image_type' => 'photo', 'width' => 4080, 'height' => 3060, 'view_azimuth_trend' => 23, 'view_angle_plunge' => 0),
	array('id' => 2, 'image_type' => 'photo', 'width' => 4080, 'height' => 3060, 'view_azimuth_trend' => 155),
	array('id' => 3, 'image_type' => 'photo', 'width' => 4080, 'height' => 3060),
));
check('imageBlocks: trend + plunge 0', $b[0]['viewTrend'] === '23' && $b[0]['viewPlunge'] === '0');
check('imageBlocks: trend only', $b[1]['viewTrend'] === '155' && $b[1]['viewPlunge'] === '');
check('imageBlocks: none', $b[2]['viewTrend'] === '' && $b[2]['viewPlunge'] === '');
$keys = array_map(function ($r) { return $r['k']; }, $b[0]['rows']);
check('imageBlocks: view fields still in rows (index line)', count(array_filter($keys, function ($k) { return stripos($k, 'azimuth') !== false; })) === 1);

$r = (new ReflectionClass('FieldbookRenderer'))->newInstanceWithoutConstructor();
$meta = new ReflectionMethod('FieldbookRenderer', 'photoMeta'); $meta->setAccessible(true);
check('photoMeta: view after id, before size', $meta->invoke($r, $b[0]) === 'id 1 · view 23°, plunge 0° · 4080 x 3060');
check('photoMeta: trend only', $meta->invoke($r, $b[1]) === 'id 2 · view 155° · 4080 x 3060');
check('photoMeta: unchanged without view', $meta->invoke($r, $b[2]) === 'id 3 · 4080 x 3060');
$pl = $b[2]; $pl['viewPlunge'] = '-5';
check('photoMeta: plunge only', $meta->invoke($r, $pl) === 'id 3 · plunge -5° · 4080 x 3060');

echo "\n$pass passed, $fail failed\n";
echo $fail === 0 ? "ALL PASS\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
