<?php
/**
 * StraboSearch Phase 0.2 census — StraboMicro subsystem.
 *
 * Micro is the columnar exception: mineralogy, grain info, fabric, and
 * instrument metadata already live in real PG columns, so this census is
 * straight SQL — coverage, cardinality, and top values per candidate
 * search criterion.
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/census/census_micro.php
 * READ-ONLY.
 *
 * @package StraboSearch Census
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/_census_lib.php');

$t0 = microtime(true);
line('MICRO SUBSYSTEM CENSUS — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
section('1. Inventory');

$nProj  = (int)$db->get_var("select count(*) from strabomicro.micro_projectmetadata");
$nDs    = (int)$db->get_var("select count(*) from strabomicro.micro_datasetmetadata");
$nSmp   = (int)$db->get_var("select count(*) from strabomicro.micro_samplemetadata");
$nMg    = (int)$db->get_var("select count(*) from strabomicro.micro_micrographmetadata");
$nSpot  = (int)$db->get_var("select count(*) from strabomicro.micro_spotmetadata");

covRow('projects', $nProj, $nProj);
covRow('datasets', $nDs, $nDs);
covRow('samples', $nSmp, $nSmp);
covRow('micrographs', $nMg, $nMg);
covRow('spots', $nSpot, $nSpot);

subsection('Access split');
covRow('public projects', (int)$db->get_var("select count(*) from strabomicro.micro_projectmetadata where ispublic = true"), $nProj);
covRow('projects with keywords tsvector', (int)$db->get_var("select count(*) from strabomicro.micro_projectmetadata where keywords is not null"), $nProj);

// ---------------------------------------------------------------------------
section('2. Sample metadata coverage (candidate criteria)');

columnCoverage($db, 'strabomicro.micro_samplemetadata', array(
	'label', 'sampleid', 'materialtype', 'othermaterialtype', 'sampletype',
	'lithology', 'mainsamplingpurpose', 'sampledescription', 'color',
	'degreeofweathering', 'inplacenessofsample', 'orientedsample', 'sampleunit',
), $nSmp);
covRow('latitude/longitude pair', (int)$db->get_var("select count(*) from strabomicro.micro_samplemetadata where latitude is not null and longitude is not null"), $nSmp);

valueTable($db, "select materialtype, count(*) from strabomicro.micro_samplemetadata where materialtype is not null and materialtype != '' group by 1 order by 2 desc limit 15", 'materialtype values', $nSmp);
valueTable($db, "select sampletype, count(*) from strabomicro.micro_samplemetadata where sampletype is not null and sampletype != '' group by 1 order by 2 desc limit 15", 'sampletype values', $nSmp);
valueTable($db, "select lithology, count(*) from strabomicro.micro_samplemetadata where lithology is not null and lithology != '' group by 1 order by 2 desc limit 15", 'lithology values (free text?)', $nSmp);
valueTable($db, "select mainsamplingpurpose, count(*) from strabomicro.micro_samplemetadata where mainsamplingpurpose is not null and mainsamplingpurpose != '' group by 1 order by 2 desc limit 15", 'mainsamplingpurpose values', $nSmp);

// ---------------------------------------------------------------------------
section('3. Micrograph metadata');

valueTable($db, "select imagetype, count(*) from strabomicro.micro_micrographmetadata group by 1 order by 2 desc limit 15", 'imagetype distribution', $nMg);
columnCoverage($db, 'strabomicro.micro_micrographmetadata', array('name', 'description', 'notes', 'polish', 'tags_json'), $nMg);
covRow('width+height present', (int)$db->get_var("select count(*) from strabomicro.micro_micrographmetadata where width is not null and height is not null"), $nMg);
covRow('reference micrographs (no parent)', (int)$db->get_var("select count(*) from strabomicro.micro_micrographmetadata where parentid is null"), $nMg);

// ---------------------------------------------------------------------------
section('4. Feature info — mineralogy / grain / fabric (polymorphic over micrograph+spot)');

$nMin = (int)$db->get_var("select count(*) from strabomicro.micro_mineralogy");
subsection('mineralogy');
covRow('mineralogy rows', $nMin, $nMin);
covRow('  attached to micrographs', (int)$db->get_var("select count(*) from strabomicro.micro_mineralogy where micrograph_id is not null"), $nMin);
covRow('  attached to spots', (int)$db->get_var("select count(*) from strabomicro.micro_mineralogy where spot_id is not null"), $nMin);
covRow('micrographs with mineralogy', (int)$db->get_var("select count(distinct micrograph_id) from strabomicro.micro_mineralogy where micrograph_id is not null"), $nMg);
valueTable($db, "select mineralogymethod, count(*) from strabomicro.micro_mineralogy where mineralogymethod is not null and mineralogymethod != '' group by 1 order by 2 desc limit 10", 'mineralogymethod values', $nMin);

$nMineral = (int)$db->get_var("select count(*) from strabomicro.micro_mineral");
covRow('mineral rows (children)', $nMineral, $nMineral);
covRow('  with percentage', (int)$db->get_var("select count(*) from strabomicro.micro_mineral where percentage is not null and percentage::text != ''"), $nMineral);
valueTable($db, "select name, count(*) from strabomicro.micro_mineral where name is not null and name != '' group by 1 order by 2 desc limit 20", 'top mineral names', $nMineral);

subsection('grain info');
$nGrain = (int)$db->get_var("select count(*) from strabomicro.micro_graininfo");
covRow('graininfo rows', $nGrain, $nGrain);
covRow('grainsize child rows', (int)$db->get_var("select count(*) from strabomicro.micro_grainsize"), $nGrain);
covRow('grainshape child rows', (int)$db->get_var("select count(*) from strabomicro.micro_grainshape"), $nGrain);

subsection('fabric info');
$nFab = (int)$db->get_var("select count(*) from strabomicro.micro_fabricinfo");
covRow('fabricinfo rows', $nFab, $nFab);
$nFabric = (int)$db->get_var("select count(*) from strabomicro.micro_fabric");
covRow('fabric child rows', $nFabric, $nFabric);
valueTable($db, "select fabricelement, count(*) from strabomicro.micro_fabric where fabricelement is not null and fabricelement != '' group by 1 order by 2 desc limit 10", 'fabricelement values', $nFabric);
valueTable($db, "select fabriccategory, count(*) from strabomicro.micro_fabric where fabriccategory is not null and fabriccategory != '' group by 1 order by 2 desc limit 10", 'fabriccategory values', $nFabric);

// ---------------------------------------------------------------------------
section('5. Instrument / detector');

$nInst = (int)$db->get_var("select count(*) from strabomicro.micro_instrument");
covRow('instrument rows', $nInst, $nInst);
covRow('micrographs with instrument', (int)$db->get_var("select count(distinct micrograph_id) from strabomicro.micro_instrument where micrograph_id is not null"), $nMg);
valueTable($db, "select instrumenttype, count(*) from strabomicro.micro_instrument where instrumenttype is not null and instrumenttype != '' group by 1 order by 2 desc limit 15", 'instrumenttype values', $nInst);
valueTable($db, "select datatype, count(*) from strabomicro.micro_instrument where datatype is not null and datatype != '' group by 1 order by 2 desc limit 15", 'datatype values', $nInst);
columnCoverage($db, 'strabomicro.micro_instrument', array('instrumentbrand', 'instrumentmodel', 'university', 'laboratory'), $nInst);

$nDet = (int)$db->get_var("select count(*) from strabomicro.micro_instrumentdetector");
covRow('detector rows', $nDet, $nDet);
valueTable($db, "select detectortype, count(*) from strabomicro.micro_instrumentdetector where detectortype is not null and detectortype != '' group by 1 order by 2 desc limit 10", 'detectortype values', $nDet);

// ---------------------------------------------------------------------------
section('6. Spot metadata + tags');

columnCoverage($db, 'strabomicro.micro_spotmetadata', array('name', 'notes', 'geometrytype'), $nSpot);
$nTag = (int)$db->get_var("select count(*) from strabomicro.micro_tag");
$nSpotTag = (int)$db->get_var("select count(*) from strabomicro.micro_spot_tag");
covRow('tags', $nTag, $nTag);
covRow('spot-tag junction rows', $nSpotTag, $nSpotTag);
covRow('spots with >=1 tag', (int)$db->get_var("select count(distinct spot_id) from strabomicro.micro_spot_tag"), $nSpot);

line();
line('Done in ' . round(microtime(true) - $t0) . 's.');
?>
