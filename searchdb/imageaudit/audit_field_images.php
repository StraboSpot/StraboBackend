<?php
/**
 * StraboSearch Phase 0.4 image-results audit — StraboField subsystem.
 *
 * Profiles the Field image population from BOTH stores:
 *   - Neo4j :Image nodes (source of truth): property universe (what metadata
 *     actually lives on the node), filename-extension distribution (the
 *     on-the-fly thumbnailer publicthumbnailimage.php is JPEG-only)
 *   - PG mirror `image` table: image_type / title / caption fill rates,
 *     parent linkage (spot/dataset/project pkeys) and public/private split
 *     via the project.ispublic join — i.e. everything an image-result card
 *     would need
 *   - local /dbimages/ file availability (NOTE: on dev only the DB is a
 *     prod restore; the image file tree is NOT synced, so disk numbers are
 *     only meaningful when this script runs on prod)
 *
 * Neo4j scans are BATCHED BY USERPKEY (whole-graph aggregations are the
 * known heap-OOM class).
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/imageaudit/audit_field_images.php
 * READ-ONLY. Progress goes to stderr; report to stdout.
 *
 * @package StraboSearch ImageAudit
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');
require_once(__DIR__ . '/../census/_census_lib.php');

$t0 = microtime(true);
line('FIELD IMAGE AUDIT — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
section('1. Inventory — Neo4j source vs PG mirror');

$rows = $neodb->query("MATCH (i:Image) RETURN count(i) AS c");
$neoImages = (int)$rows[0]->get('c');
$pgImages  = (int)$db->get_var("select count(*) from image");

covRow('Neo4j :Image nodes (source of truth)', $neoImages, $neoImages);
covRow('PG mirror image rows', $pgImages, $neoImages);
line('  mirror gap: ' . number_format($pgImages - $neoImages) . ' (positive = stale mirror rows)');

covRow('spots with >=1 image (mirror)', (int)$db->get_var("select count(distinct spot_pkey) from image"),
	(int)$db->get_var("select count(*) from spot"));
covRow('distinct owners with images (mirror)', (int)$db->get_var("select count(distinct user_pkey) from image"), 0);

// ---------------------------------------------------------------------------
section('2. Neo4j :Image property universe (batched by userpkey)');

// NOTE: unlike Spot nodes, :Image nodes carry MIXED-TYPE userpkey values
// (numeric on most, string on some — e.g. "0"), so every comparison must
// coerce via toInt() or Neo4j throws "Don't know how to compare that".
$rows = $neodb->query("MATCH (i:Image) RETURN max(toInt(i.userpkey)) AS hi");
$maxUser = (int)$rows[0]->get('hi');
progress("max image userpkey: $maxUser");

$rows = $neodb->query("MATCH (i:Image) WHERE toString(i.userpkey) = i.userpkey RETURN count(i) AS c");
covRow('image nodes with STRING-typed userpkey', (int)$rows[0]->get('c'), $neoImages);

$keys = new Tally(2000);
$exts = new Tally(500);
$scanned = 0;
$BATCH = 1000;

for ($lo = 0; $lo <= $maxUser; $lo += $BATCH) {
	$hi = $lo + $BATCH;
	$rows = $neodb->query("
		MATCH (i:Image)
		WHERE toInt(i.userpkey) >= $lo AND toInt(i.userpkey) < $hi
		WITH keys(i) AS ks
		UNWIND ks AS k
		RETURN k, count(*) AS n
	");
	foreach ($rows as $r) {
		$keys->add($r->get('k'), (int)$r->get('n'));
		if ($r->get('k') === 'userpkey') $scanned += (int)$r->get('n');
	}
	// Disk files are EXTENSIONLESS (insertImage stores raw bytes at
	// /dbimages/<seq> with no conversion; mime type is discarded), so
	// origfilename is the only surviving format evidence — and the
	// on-the-fly thumbnailer assumes JPEG content unconditionally.
	$rows = $neodb->query("
		MATCH (i:Image)
		WHERE toInt(i.userpkey) >= $lo AND toInt(i.userpkey) < $hi AND i.origfilename IS NOT NULL
		WITH split(toLower(i.origfilename), '.') AS parts
		RETURN CASE WHEN size(parts) > 1 THEN parts[size(parts)-1] ELSE '(no extension)' END AS ext, count(*) AS n
	");
	foreach ($rows as $r) $exts->add((string)$r->get('ext'), (int)$r->get('n'));
	if (($lo / $BATCH) % 5 == 0) progress("image node scan: userpkey < $hi");
}

$rows = $neodb->query("MATCH (i:Image) WHERE i.userpkey IS NULL RETURN count(i) AS c");
$nullUser = (int)$rows[0]->get('c');
progress("scan complete: " . number_format($scanned) . " nodes in " . round(microtime(true) - $t0) . "s");

covRow('image nodes scanned (by userpkey ranges)', $scanned, $neoImages);
covRow('image nodes with NULL userpkey (excluded)', $nullUser, $neoImages);

subsection('property key counts (all ' . $keys->distinct() . ' distinct keys)');
$keys->printTop(30, $scanned);

subsection('origfilename extension distribution (thumbnailer is imagecreatefromjpeg-only)');
$exts->printTop(15, $scanned);

// ---------------------------------------------------------------------------
section('3. PG mirror metadata quality (image-result card fields)');

valueTable($db, "select image_type, count(*) from image group by 1 order by 2 desc limit 15",
	'image_type distribution', $pgImages);

subsection('card-field fill rates');
columnCoverage($db, 'image', array('title', 'caption', 'image_type'), $pgImages);

subsection('caption fill by image_type (top 8 types)');
$rows = $db->get_results("
	select image_type,
	       count(*) as total,
	       count(*) filter (where caption is not null and trim(caption) != '') as with_caption
	from image group by 1 order by 2 desc limit 8");
foreach ($rows as $r) {
	line(sprintf('  %-30s %10s total  %10s captioned  %s',
		($r->image_type === null || $r->image_type === '') ? '(empty/null)' : $r->image_type,
		number_format($r->total), number_format($r->with_caption), pct($r->with_caption, $r->total)));
}

// ---------------------------------------------------------------------------
section('4. Parent linkage + access split (mirror joins)');

subsection('parent pkey completeness');
covRow('spot_pkey resolves to live spot', (int)$db->get_var(
	"select count(*) from image i join spot s on s.spot_pkey = i.spot_pkey"), $pgImages);
covRow('project_pkey resolves to live project', (int)$db->get_var(
	"select count(*) from image i join project p on p.project_pkey = i.project_pkey"), $pgImages);
covRow('dataset_pkey resolves to live dataset', (int)$db->get_var(
	"select count(*) from image i join dataset d on d.dataset_pkey = i.dataset_pkey"), $pgImages);

subsection('public/private split (project.ispublic — the fullsearch access rule)');
covRow('images in PUBLIC projects', (int)$db->get_var(
	"select count(*) from image i join project p on p.project_pkey = i.project_pkey where p.ispublic = true"), $pgImages);
covRow('images in private projects', (int)$db->get_var(
	"select count(*) from image i join project p on p.project_pkey = i.project_pkey where p.ispublic is not true"), $pgImages);
covRow('images with no resolvable project', (int)$db->get_var(
	"select count(*) from image i left join project p on p.project_pkey = i.project_pkey where p.project_pkey is null"), $pgImages);

subsection('public images: caption/type quality (what a v1 public image grid would show)');
$pubTotal = (int)$db->get_var(
	"select count(*) from image i join project p on p.project_pkey = i.project_pkey where p.ispublic = true");
covRow('public images with caption', (int)$db->get_var(
	"select count(*) from image i join project p on p.project_pkey = i.project_pkey
	 where p.ispublic = true and i.caption is not null and trim(i.caption) != ''"), $pubTotal);
covRow('public images with title', (int)$db->get_var(
	"select count(*) from image i join project p on p.project_pkey = i.project_pkey
	 where p.ispublic = true and i.title is not null and trim(i.title) != ''"), $pubTotal);

// ---------------------------------------------------------------------------
section('5. Disk availability (/dbimages) — ONLY meaningful on prod');

$dir = '/srv/app/www/dbimages';
$files = is_dir($dir) ? array_values(array_diff(scandir($dir), array('.', '..'))) : array();
covRow('files present in /dbimages on THIS host', count($files), $neoImages);
line('  (dev hosts only carry the DB restore, not the image tree — re-run on prod for real numbers)');

if (count($files) > 0) {
	// Do local files round-trip to Neo4j nodes? (validates the filename join)
	$sample = array_slice($files, 0, 50);
	$hits = 0;
	foreach ($sample as $f) {
		$safe = addslashes($f);
		$r = $neodb->query("MATCH (i:Image) WHERE i.filename = '$safe' RETURN count(i) AS c");
		if ((int)$r[0]->get('c') > 0) $hits++;
	}
	covRow('sampled local files with a matching :Image node', $hits, count($sample));
}

line();
line('Done in ' . round(microtime(true) - $t0) . 's.');
?>
