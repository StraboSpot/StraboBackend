<?php
/**
 * StraboSearch Phase 0.2 census — StraboExperimental subsystem.
 *
 * Two storage classes to profile:
 *   - normalized straboexp.sample columns (the Dec-2025 rewrite + Thread 2
 *     strabo_id work) — straight SQL coverage
 *   - the experiment.json blob, where apparatus / DAQ / geometry live —
 *     full decode of every blob (the subsystem is small enough to not
 *     need sampling) with per-path coverage and per-year drift
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/census/census_exp.php
 * READ-ONLY.
 *
 * @package StraboSearch Census
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/_census_lib.php');

$t0 = microtime(true);
line('EXPERIMENTAL SUBSYSTEM CENSUS — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
section('1. Inventory (all straboexp tables)');

$tables = $db->get_results("select table_name from information_schema.tables where table_schema = 'straboexp' order by table_name");
foreach ($tables as $t) {
	$n = (int)$db->get_var("select count(*) from straboexp." . $t->table_name);
	covRow($t->table_name, $n, $n);
}

$nProj = (int)$db->get_var("select count(*) from straboexp.project");
$nExp  = (int)$db->get_var("select count(*) from straboexp.experiment");
$nSmp  = (int)$db->get_var("select count(*) from straboexp.sample");

subsection('Access split');
covRow('public projects', (int)$db->get_var("select count(*) from straboexp.project where ispublic = true"), $nProj);
covRow('projects with keywords tsvector', (int)$db->get_var("select count(*) from straboexp.project where keywords is not null"), $nProj);

// ---------------------------------------------------------------------------
section('2. Normalized sample columns (straboexp.sample)');

columnCoverage($db, 'straboexp.sample', array(
	'name', 'igsn', 'id', 'description', 'strabo_id',
	'material_type', 'material_name', 'material_state', 'material_note',
	'parent_name', 'parent_igsn',
	'provenance_formation', 'provenance_source',
	'texture_bedding', 'texture_lineation', 'texture_foliation', 'texture_fault',
), $nSmp);
covRow('provenance lat/lng pair', (int)$db->get_var("select count(*) from straboexp.sample where provenance_loc_latitude is not null and provenance_loc_longitude is not null"), $nSmp);

valueTable($db, "select material_type, count(*) from straboexp.sample where material_type is not null and material_type != '' group by 1 order by 2 desc limit 15", 'material_type values', $nSmp);
valueTable($db, "select material_name, count(*) from straboexp.sample where material_name is not null and material_name != '' group by 1 order by 2 desc limit 15", 'material_name values', $nSmp);

// ---------------------------------------------------------------------------
section('3. experiment.json blob (full decode, all rows)');

$rows = $db->get_results("select pkey, extract(year from created_timestamp)::int as yr, json from straboexp.experiment order by pkey");
$nBlob = 0; $parseFail = 0; $noJson = 0;
$topKeys = new Tally(100);
$pathCov = array(
	'apparatus' => 0, 'apparatus.type' => 0, 'apparatus.name' => 0,
	'facility' => 0, 'facility.name' => 0,
	'sample' => 0, 'sample.material' => 0, 'sample.material.composition' => 0, 'sample.parameters' => 0,
	'daq' => 0, 'daq devices >=1' => 0, 'daq channels >=1' => 0,
	'experiment' => 0, 'experiment.geometry' => 0, 'experiment.protocol' => 0, 'data' => 0,
);
$sensorTypes = new Tally(100);
$headerTypes = new Tally(100);
$apparatusTypes = new Tally(100);
$byYear = array();
$daqChannelsTotal = 0;

foreach ($rows as $row) {
	$yr = (int)$row->yr;
	if (!isset($byYear[$yr])) $byYear[$yr] = array('n' => 0, 'blob' => 0);
	$byYear[$yr]['n']++;
	if ($row->json === null || trim($row->json) === '') { $noJson++; continue; }
	$nBlob++;
	$j = json_decode($row->json);
	if ($j === null) { $parseFail++; continue; }
	$byYear[$yr]['blob']++;
	foreach (get_object_vars($j) as $k => $v) $topKeys->add($k);

	if (isset($j->apparatus)) {
		$pathCov['apparatus']++;
		if (!empty($j->apparatus->type)) { $pathCov['apparatus.type']++; $apparatusTypes->add((string)$j->apparatus->type); }
		if (!empty($j->apparatus->name)) $pathCov['apparatus.name']++;
	}
	if (isset($j->facility)) {
		$pathCov['facility']++;
		if (!empty($j->facility->name)) $pathCov['facility.name']++;
	}
	if (isset($j->sample)) {
		$pathCov['sample']++;
		if (isset($j->sample->material)) $pathCov['sample.material']++;
		if (is_object($j->sample->material ?? null) && !empty($j->sample->material->composition)) $pathCov['sample.material.composition']++;
		if (!empty($j->sample->parameters)) $pathCov['sample.parameters']++;
	}
	if (isset($j->daq)) {
		$pathCov['daq']++;
		$devices = isset($j->daq->devices) && is_array($j->daq->devices) ? $j->daq->devices : array();
		if (count($devices) > 0) $pathCov['daq devices >=1']++;
		$expChannels = 0;
		foreach ($devices as $dev) {
			if (!is_object($dev) || !isset($dev->channels) || !is_array($dev->channels)) continue;
			$expChannels += count($dev->channels);
			foreach ($dev->channels as $ch) {
				if (!is_object($ch)) continue;
				if (isset($ch->sensor) && is_object($ch->sensor) && !empty($ch->sensor->detail)) {
					$sensorTypes->add((string)$ch->sensor->detail);
				}
				if (isset($ch->header) && is_object($ch->header) && !empty($ch->header->type)) {
					$headerTypes->add((string)$ch->header->type);
				}
			}
		}
		if ($expChannels > 0) $pathCov['daq channels >=1']++;
		$daqChannelsTotal += $expChannels;
	}
	if (isset($j->experiment)) {
		$pathCov['experiment']++;
		if (is_object($j->experiment)) {
			if (!empty($j->experiment->geometry)) $pathCov['experiment.geometry']++;
			if (!empty($j->experiment->protocol)) $pathCov['experiment.protocol']++;
		}
	}
	if (isset($j->data)) $pathCov['data']++;
}

covRow('experiments', $nExp, $nExp);
covRow('with json blob', $nBlob, $nExp);
covRow('json_decode failures', $parseFail, $nBlob);

subsection('top-level keys');
$topKeys->printTop(15, $nBlob - $parseFail);

subsection('path coverage (of parsed blobs)');
foreach ($pathCov as $path => $n) covRow($path, $n, $nBlob - $parseFail);
line('  total DAQ channels: ' . number_format($daqChannelsTotal));

subsection('apparatus.type values');
$apparatusTypes->printTop(15);
subsection('DAQ sensor types (channel.sensor.detail)');
$sensorTypes->printTop(15);
subsection('DAQ measurement types (channel.header.type)');
$headerTypes->printTop(15);

subsection('blob presence by created year');
ksort($byYear);
line(sprintf('  %-6s %10s %14s', 'year', 'experiments', 'with blob'));
foreach ($byYear as $yr => $c) {
	line(sprintf('  %-6s %10s %10s %s', $yr, number_format($c['n']), number_format($c['blob']), pct($c['blob'], $c['n'])));
}

line();
line('Done in ' . round(microtime(true) - $t0) . 's.');
?>
