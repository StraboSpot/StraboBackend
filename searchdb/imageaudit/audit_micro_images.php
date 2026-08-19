<?php
/**
 * StraboSearch Phase 0.4 image-results audit — StraboMicro subsystem.
 *
 * Micro is fully columnar in PG. Profiles:
 *   - micrograph inventory (reference vs associated, per-project spread)
 *   - card-field metadata quality (name, imagetype, dims, description, scale)
 *   - access split via the micrograph -> sample -> dataset -> project chain
 *     (ispublic lives on micro_projectmetadata) + chain integrity
 *   - disk availability of the server-generated composite JPEG
 *     (straboMicroFiles/<project_id>/images/<strabo_id>.jpg) and deep-zoom
 *     tiles. NOTE: dev hosts only carry the DB restore, not the file tree —
 *     disk numbers are only meaningful when run on prod.
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/imageaudit/audit_micro_images.php
 * READ-ONLY.
 *
 * @package StraboSearch ImageAudit
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/../census/_census_lib.php');

$t0 = microtime(true);
line('MICRO IMAGE AUDIT — ' . date('Y-m-d H:i:s'));

$CHAIN = "strabomicro.micro_micrographmetadata mg
	left join strabomicro.micro_samplemetadata  smp on smp.id = mg.sample_id
	left join strabomicro.micro_datasetmetadata ds  on ds.id  = smp.dataset_id
	left join strabomicro.micro_projectmetadata pj  on pj.id  = ds.project_id";

// ---------------------------------------------------------------------------
section('1. Inventory');

$nMg = (int)$db->get_var("select count(*) from strabomicro.micro_micrographmetadata");
covRow('micrographs total', $nMg, $nMg);
covRow('reference micrographs (parentid null)', (int)$db->get_var(
	"select count(*) from strabomicro.micro_micrographmetadata where parentid is null or parentid = ''"), $nMg);
covRow('associated micrographs (have parent)', (int)$db->get_var(
	"select count(*) from strabomicro.micro_micrographmetadata where parentid is not null and parentid != ''"), $nMg);

subsection('per-project spread (top 10)');
$rows = $db->get_results("select pj.id as pid, pj.name, count(*) as n from $CHAIN
	where pj.id is not null group by 1, 2 order by 3 desc limit 10");
foreach ($rows as $r) {
	$nm = mb_strlen($r->name) > 40 ? mb_substr($r->name, 0, 37) . '...' : $r->name;
	line(sprintf('  %-46s %10s  %s', "[{$r->pid}] $nm", number_format($r->n), pct($r->n, $nMg)));
}

// ---------------------------------------------------------------------------
section('2. Card-field metadata quality');

columnCoverage($db, 'strabomicro.micro_micrographmetadata',
	array('name', 'imagetype', 'description', 'notes', 'scale', 'tags_json'), $nMg);
covRow('width+height present', (int)$db->get_var(
	"select count(*) from strabomicro.micro_micrographmetadata where width is not null and height is not null"), $nMg);
covRow('scalepixelspercentimeter present', (int)$db->get_var(
	"select count(*) from strabomicro.micro_micrographmetadata where scalepixelspercentimeter is not null"), $nMg);

valueTable($db, "select imagetype, count(*) from strabomicro.micro_micrographmetadata
	group by 1 order by 2 desc limit 15", 'imagetype distribution (vocab drift expected)', $nMg);

// ---------------------------------------------------------------------------
section('3. Parent chain + access split');

subsection('chain integrity (micrograph -> sample -> dataset -> project)');
covRow('resolves to a sample', (int)$db->get_var("select count(*) from $CHAIN where smp.id is not null"), $nMg);
covRow('resolves to a dataset', (int)$db->get_var("select count(*) from $CHAIN where ds.id is not null"), $nMg);
covRow('resolves to a project', (int)$db->get_var("select count(*) from $CHAIN where pj.id is not null"), $nMg);

subsection('public/private split (micro_projectmetadata.ispublic)');
$nPub = (int)$db->get_var("select count(*) from $CHAIN where pj.ispublic = true");
covRow('micrographs in PUBLIC projects', $nPub, $nMg);
covRow('micrographs in private projects', (int)$db->get_var(
	"select count(*) from $CHAIN where pj.id is not null and pj.ispublic is not true"), $nMg);

subsection('public micrographs: card-field quality');
covRow('public w/ imagetype', (int)$db->get_var(
	"select count(*) from $CHAIN where pj.ispublic = true and mg.imagetype is not null and mg.imagetype != ''"), $nPub);
covRow('public w/ name', (int)$db->get_var(
	"select count(*) from $CHAIN where pj.ispublic = true and mg.name is not null and mg.name != ''"), $nPub);
covRow('public w/ sample label (breadcrumb)', (int)$db->get_var(
	"select count(*) from $CHAIN where pj.ispublic = true and smp.label is not null and smp.label != ''"), $nPub);

// ---------------------------------------------------------------------------
section('4. Disk availability (straboMicroFiles) — ONLY meaningful on prod');

$base = '/srv/app/www/straboMicroFiles';
$projRows = $db->get_results("select distinct pj.id as pid from $CHAIN where pj.id is not null order by 1");
$projIds = array_map(function ($r) { return (int)$r->pid; }, $projRows ? $projRows : array());

$foldersOnDisk = 0; $withImagesDir = 0; $withTiles = 0;
$mgChecked = 0; $mgComposite = 0;
foreach ($projIds as $pid) {
	if (!is_dir("$base/$pid")) continue;
	$foldersOnDisk++;
	if (is_dir("$base/$pid/images")) $withImagesDir++;
	if (is_dir("$base/$pid/tiles"))  $withTiles++;
	// per-micrograph composite check for this project
	$rows = $db->get_results("select mg.strabo_id from $CHAIN where pj.id = $pid");
	foreach ($rows as $r) {
		$mgChecked++;
		if (file_exists("$base/$pid/images/{$r->strabo_id}.jpg")) $mgComposite++;
	}
}
covRow('DB projects with a folder on THIS host', $foldersOnDisk, count($projIds));
covRow('  ...with an images/ dir (composites)', $withImagesDir, $foldersOnDisk);
covRow('  ...with a tiles/ dir (deep zoom)', $withTiles, $foldersOnDisk);
covRow('micrographs checked (folder present)', $mgChecked, $nMg);
covRow('  ...with composite JPEG on disk', $mgComposite, $mgChecked);
line('  (dev hosts only carry the DB restore, not the file tree — re-run on prod for real numbers)');

line();
line('Done in ' . round(microtime(true) - $t0) . 's.');
?>
