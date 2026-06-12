<?php
/**
 * StraboSearch Phase 0.4 image-results audit — StraboExperimental subsystem.
 *
 * Experimental has THREE image/document stores:
 *   1. straboexp.document        — Vue-app uploads (experiment/sample/apparatus/
 *                                  daq attachment points), files at
 *                                  /experimental/expimages/<uuid>
 *   2. apprepo.apparatus_document — apparatus-repo uploads, same file dir
 *   3. public.exp_images          — legacy apparatus photos/schematics/sensor
 *                                  images at /expimages/<type>_<pkey>[_<n>]
 *
 * Profiles type/format distributions (which rows are actually PICTURES vs
 * PDFs/data files), description fill, attachment-point spread, the
 * project-level ispublic split, and disk availability (prod-only meaningful).
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/imageaudit/audit_exp_images.php
 * READ-ONLY.
 *
 * @package StraboSearch ImageAudit
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/../census/_census_lib.php');

$t0 = microtime(true);
line('EXPERIMENTAL IMAGE AUDIT — ' . date('Y-m-d H:i:s'));

$IMG_FORMATS = "('jpg','jpeg','png','gif','tif','tiff','bmp','webp','image')";

// ---------------------------------------------------------------------------
section('1. straboexp.document (Vue-app uploads)');

$nDoc = (int)$db->get_var("select count(*) from straboexp.document");
covRow('documents total', $nDoc, $nDoc);

valueTable($db, "select type, count(*) from straboexp.document group by 1 order by 2 desc limit 15",
	'type distribution', $nDoc);
valueTable($db, "select lower(format), count(*) from straboexp.document group by 1 order by 2 desc limit 15",
	'format distribution', $nDoc);

subsection('picture subset (format looks like an image)');
$nPic = (int)$db->get_var("select count(*) from straboexp.document where lower(format) in $IMG_FORMATS
	or lower(coalesce(original_filename,'')) ~ '\\.(jpe?g|png|gif|tiff?|bmp|webp)$'");
covRow('picture-format documents', $nPic, $nDoc);

subsection('attachment-point spread');
covRow('attached to apparatus', (int)$db->get_var("select count(*) from straboexp.document where apparatus_pkey is not null"), $nDoc);
covRow('attached to daq device', (int)$db->get_var("select count(*) from straboexp.document where daq_device_pkey is not null"), $nDoc);
covRow('attached to sample', (int)$db->get_var("select count(*) from straboexp.document where sample_pkey is not null"), $nDoc);
covRow('attached to experiment setup', (int)$db->get_var("select count(*) from straboexp.document where experiment_setup_pkey is not null"), $nDoc);

subsection('card-field fill');
columnCoverage($db, 'straboexp.document', array('description', 'original_filename', 'uuid'), $nDoc);

subsection('ispublic reachability (document -> experiment_setup -> experiment -> project)');
// ispublic only exists on straboexp.project; only setup-attached docs can reach it
$rows = $db->get_results("
	select coalesce(pj.ispublic::text, '(no project chain)') as pub, count(*) as n
	from straboexp.document d
	left join straboexp.experiment_setup es on es.pkey = d.experiment_setup_pkey
	left join straboexp.experiment ex on ex.pkey = es.experiment_pkey
	left join straboexp.project pj on pj.pkey = ex.project_pkey
	group by 1 order by 2 desc");
foreach ($rows as $r) covRow('ispublic = ' . $r->pub, (int)$r->n, $nDoc);

// ---------------------------------------------------------------------------
section('2. apprepo.apparatus_document');

$nApp = (int)$db->get_var("select count(*) from apprepo.apparatus_document");
covRow('apparatus documents total', $nApp, $nApp);
valueTable($db, "select type, count(*) from apprepo.apparatus_document group by 1 order by 2 desc limit 10",
	'type distribution', $nApp);
valueTable($db, "select lower(format), count(*) from apprepo.apparatus_document group by 1 order by 2 desc limit 10",
	'format distribution', $nApp);

// ---------------------------------------------------------------------------
section('3. public.exp_images (legacy apparatus photos)');

$nLegacy = (int)$db->get_var("select count(*) from exp_images");
covRow('legacy exp_images rows', $nLegacy, $nLegacy);
valueTable($db, "select type, count(*) from exp_images group by 1 order by 2 desc limit 15",
	'type distribution', $nLegacy);

// ---------------------------------------------------------------------------
section('4. Disk availability — ONLY meaningful on prod');

$newDir = '/srv/app/www/experimental/expimages';
$newFiles = is_dir($newDir) ? count(array_diff(scandir($newDir), array('.', '..'))) : 0;
covRow('files in /experimental/expimages on THIS host', $newFiles, $nDoc + $nApp);

$found = 0;
$rows = $db->get_results("select uuid from straboexp.document where uuid is not null limit 100");
foreach ($rows as $r) if (file_exists("$newDir/{$r->uuid}")) $found++;
covRow('sampled document uuids with a file', $found, $rows ? count($rows) : 0);

$legacyDir = '/srv/app/www/expimages';
$legacyFiles = is_dir($legacyDir) ? count(array_diff(scandir($legacyDir), array('.', '..'))) : 0;
covRow('files in /expimages (legacy) on THIS host', $legacyFiles, $nLegacy);
line('  (dev hosts only carry the DB restore, not the file tree — re-run on prod for real numbers)');

line();
line('Done in ' . round(microtime(true) - $t0) . 's.');
?>
