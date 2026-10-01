<?php
/**
 * File: decompose_dump.php
 * Description: Reference output for the StraboMicro2 client's entity model
 *              (electron/shared/entityModel.mjs). For every dev project
 *              (the stored projectjson of each row, plus each
 *              straboMicroFiles/<id>/project.json with its point-counts/),
 *              runs the server's own MsConvert::normalize and ::decompose
 *              and prints one JSON document: the input, the point counts in
 *              the order the converter reads them, and either the creates
 *              or the reason the converter stops. The client test compares
 *              its explode() against this, change by change.
 *
 *              Read only. Usage (writes to stdout):
 *              docker exec strabo-php php /srv/app/www/tests/microsync/decompose_dump.php > dump.json
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (php_sapi_name() !== 'cli') {
	exit(1);
}

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/microsync/lib/MsConvert.php';

$FILES = '/srv/app/www/straboMicroFiles';

$conv = (new ReflectionClass('MsConvert'))->newInstanceWithoutConstructor();
$normalize = new ReflectionMethod('MsConvert', 'normalize');
$normalize->setAccessible(true);

/** One case: normalize + decompose, or the converter's stop reason. */
function run_case($source, $raw, $pointCounts) {
	global $conv, $normalize;
	$out = array('source' => $source, 'input' => $raw, 'pointCounts' => array_values($pointCounts));
	try {
		if (!is_object($raw)) {
			throw new MsConvertStop('bad_json', 'not a JSON object');
		}
		$report = array('warnings' => array(), 'stats' => array());
		$norm = $normalize->invokeArgs($conv, array($raw, &$report));
		$changes = MsConvert::decompose($norm);
		foreach ($pointCounts as $id => $pc) {
			$changes[] = array('op' => 'create', 'type' => 'point_count', 'id' => $id,
				'parentType' => 'micrograph', 'parentId' => $pc->micrographId, 'body' => $pc);
		}
		$out['ok'] = true;
		$out['normalized'] = $norm;
		$out['changes'] = $changes;
		$out['warnings'] = $report['warnings'];
	} catch (MsConvertStop $e) {
		$out['ok'] = false;
		$out['reason'] = $e->reason;
		$out['message'] = $e->getMessage();
	}
	return $out;
}

$cases = array();

$db->get_var('SELECT 1');
$rows = $db->get_results("SELECT id, projectjson FROM strabomicro.micro_projectmetadata
                           WHERE projectjson IS NOT NULL AND projectjson <> '' ORDER BY id");
foreach ($rows as $r) {
	$cases[] = run_case('db:' . $r->id, json_decode($r->projectjson), array());
}

foreach ((array)@scandir($FILES) as $d) {
	$file = "$FILES/$d/project.json";
	if ($d === '.' || $d === '..' || !is_file($file)) {
		continue;
	}
	$pointCounts = array();
	foreach ((array)@scandir("$FILES/$d/point-counts") as $f) {
		if (substr($f, -5) !== '.json') {
			continue;
		}
		$pc = json_decode((string)file_get_contents("$FILES/$d/point-counts/$f"));
		if (is_object($pc) && isset($pc->id) && is_string($pc->id)) {
			$pointCounts[$pc->id] = $pc;
		}
	}
	$cases[] = run_case("folder:$d", json_decode((string)file_get_contents($file)), $pointCounts);
}

echo json_encode($cases, MsHttp::JSON_OUT);
