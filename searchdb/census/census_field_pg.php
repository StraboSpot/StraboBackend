<?php
/**
 * StraboSearch Phase 0.2 census — Field subsystem, PostgreSQL mirror side.
 *
 * Profiles the denormalized PG mirror (project/dataset/spot/image/sample/
 * rock_type) that today's /fullsearch queries, plus a full streaming pass
 * over every spot.spotjson blob measuring per-field coverage, inner
 * structure, value-type consistency, and schema drift by upload year.
 *
 * The streaming pass deliberately uses PHP json_decode (not SQL jsonb
 * casts) so a single malformed blob is COUNTED, not fatal — and so the run
 * itself rehearses the extraction layer every StraboSearch architecture
 * needs.
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/census/census_field_pg.php
 * READ-ONLY. Progress goes to stderr; report to stdout.
 *
 * @package StraboSearch Census
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/_census_lib.php');

$t0 = microtime(true);
line('FIELD SUBSYSTEM CENSUS (PostgreSQL mirror) — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
section('1. Inventory');

$nProjects = (int)$db->get_var("select count(*) from project");
$nDatasets = (int)$db->get_var("select count(*) from dataset");
$nSpots    = (int)$db->get_var("select count(*) from spot");
$nImages   = (int)$db->get_var("select count(*) from image");
$nSamples  = (int)$db->get_var("select count(*) from sample");
$nRockType = (int)$db->get_var("select count(*) from rock_type");

covRow('projects', $nProjects, $nProjects);
covRow('datasets', $nDatasets, $nDatasets);
covRow('spots', $nSpots, $nSpots);
covRow('image rows', $nImages, $nImages);
covRow('sample rows', $nSamples, $nSamples);
covRow('rock_type rows (tag-derived)', $nRockType, $nRockType);

$nPublicProjects = (int)$db->get_var("select count(*) from project where ispublic = true");
subsection('Access split');
covRow('public projects', $nPublicProjects, $nProjects);
$nSpotsPublic = (int)$db->get_var(
	"select count(*) from spot s join project p on s.project_pkey = p.project_pkey where p.ispublic = true");
covRow('spots in public projects', $nSpotsPublic, $nSpots);

subsection('Spot geometry');
covRow('spots with non-null location', (int)$db->get_var("select count(*) from spot where location is not null"), $nSpots);
valueTable($db, "select coalesce(geotype,'(null)'), count(*) from spot group by 1 order by 2 desc", 'geotype distribution', $nSpots);

subsection('date_created range');
$r = $db->get_row("select min(date_created) as lo, max(date_created) as hi from spot");
line("  $r->lo .. $r->hi");

// ---------------------------------------------------------------------------
section('2. Mirror column coverage (spot table)');

covRow('keywords tsvector populated', (int)$db->get_var("select count(*) from spot where keywords is not null"), $nSpots);
covRow('spotjson populated', (int)$db->get_var("select count(*) from spot where spotjson is not null and spotjson != ''"), $nSpots);
covRow('real_world_geometry', (int)$db->get_var("select count(*) from spot where real_world_geometry is not null and real_world_geometry != ''"), $nSpots);
covRow('orientation_exists flag', (int)$db->get_var("select count(*) from spot where orientation_exists"), $nSpots);
covRow('sample_exists flag', (int)$db->get_var("select count(*) from spot where sample_exists"), $nSpots);
covRow('micro_exists flag', (int)$db->get_var("select count(*) from spot where micro_exists"), $nSpots);
covRow('strat_exists flag', (int)$db->get_var("select count(*) from spot where strat_exists"), $nSpots);
covRow('has_rosetta flag', (int)$db->get_var("select count(*) from spot where has_rosetta"), $nSpots);
covRow('project.keywords populated', (int)$db->get_var("select count(*) from project where keywords is not null"), $nProjects);

// ---------------------------------------------------------------------------
section('3. Mirror child tables (today\'s hidden facets)');

subsection('rock_type (written only from rock-unit TAGS, not feature data)');
covRow('distinct spots with rock_type rows', (int)$db->get_var("select count(distinct spot_pkey) from rock_type"), $nSpots);
covRow('rows with metamorphic_facies', (int)$db->get_var("select count(*) from rock_type where metamorphic_facies is not null and metamorphic_facies != ''"), $nRockType);
valueTable($db, "select strabo_rock_type, count(*) from rock_type group by 1 order by 2 desc limit 20", 'top strabo_rock_type values', $nRockType);
valueTable($db, "select metamorphic_facies, count(*) from rock_type where metamorphic_facies is not null and metamorphic_facies != '' group by 1 order by 2 desc limit 15", 'metamorphic_facies values');

subsection('image table');
valueTable($db, "select image_type, count(*) from image group by 1 order by 2 desc limit 15", 'image_type distribution', $nImages);
covRow('images with title', (int)$db->get_var("select count(*) from image where title is not null and title != ''"), $nImages);
covRow('images with caption', (int)$db->get_var("select count(*) from image where caption is not null and caption != ''"), $nImages);

subsection('sample table');
covRow('rows with sample_id', (int)$db->get_var("select count(*) from sample where sample_id is not null and sample_id != ''"), $nSamples);
covRow('rows with rock_type column', (int)$db->get_var("select count(*) from sample where rock_type is not null and rock_type != ''"), $nSamples);
covRow('distinct spots with sample rows', (int)$db->get_var("select count(distinct spot_pkey) from sample"), $nSpots);

// ---------------------------------------------------------------------------
section('4. Full spotjson blob pass (streaming, all spots)');

$BATCH = 5000;
$lastPkey = 0;
$seen = 0;
$parseFail = 0;
$noProps = 0;

$propKeys = new Tally(5000);          // properties key universe
$byYear = array();                     // year => [n, orientation, rock_unit, samples, notes, images, legacy_orientation]

// orientation_data deep
$oriSpots = 0; $oriTypeofs = new Tally(10); $oriElems = 0;
$oriElemKeys = new Tally(500);
$oriFieldPresence = array('type'=>0,'strike'=>0,'dip'=>0,'dip_direction'=>0,'trend'=>0,'plunge'=>0,'rake'=>0,'quality'=>0,'unix_timestamp'=>0,'associated_orientation'=>0);
$oriValueTypes = array();              // field => ['number'=>n,'numeric-string'=>n,'other'=>n]
foreach (array('strike','dip','trend','plunge') as $f) $oriValueTypes[$f] = array('number'=>0,'numeric-string'=>0,'other'=>0);

// rock_unit deep
$ruSpots = 0; $ruTypeofs = new Tally(10); $ruKeys = new Tally(500);

// samples deep
$smpSpots = 0; $smpElems = 0; $smpKeys = new Tally(500);

// images deep (light — 0.4 owns the full image audit)
$imgSpots = 0; $imgElems = 0; $imgTypes = new Tally(200); $imgAnnotated = 0; $imgCaption = 0;

// traces & misc
$traceSpots = 0; $traceTypes = new Tally(100);
$miscCounts = array('sed'=>0,'pet'=>0,'other_features'=>0,'_3d_structures'=>0,'inferences'=>0,'fabrics'=>0,'custom_fields'=>0,'surface_feature'=>0,'strat_section_id'=>0,'image_basemap'=>0);
$notesSpots = 0; $notesLen = 0; $notesMax = 0;

function valType($v) {
	if (is_int($v) || is_float($v)) return 'number';
	if (is_string($v) && is_numeric($v)) return 'numeric-string';
	return 'other';
}

while (true) {
	$rows = $db->get_results("select spot_pkey, extract(year from date_created)::int as yr, spotjson from spot where spot_pkey > $lastPkey order by spot_pkey limit $BATCH");
	if (!$rows) break;
	foreach ($rows as $row) {
		$lastPkey = (int)$row->spot_pkey;
		$seen++;
		$j = json_decode($row->spotjson);
		if ($j === null) { $parseFail++; continue; }
		$p = isset($j->properties) ? $j->properties : null;
		if (!is_object($p)) { $noProps++; continue; }

		$yr = (int)$row->yr;
		if (!isset($byYear[$yr])) $byYear[$yr] = array('n'=>0,'ori'=>0,'ru'=>0,'smp'=>0,'notes'=>0,'img'=>0,'legacy_ori'=>0);
		$byYear[$yr]['n']++;

		foreach (get_object_vars($p) as $k => $v) $propKeys->add($k);

		// orientation_data
		if (isset($p->orientation_data)) {
			$oriSpots++; $byYear[$yr]['ori']++;
			$od = $p->orientation_data;
			if (is_array($od)) {
				$oriTypeofs->add('array');
				foreach ($od as $el) {
					if (!is_object($el)) { $oriElemKeys->add('(non-object element)'); continue; }
					$oriElems++;
					foreach (get_object_vars($el) as $ek => $ev) {
						$oriElemKeys->add($ek);
						if (array_key_exists($ek, $oriFieldPresence)) $oriFieldPresence[$ek]++;
						if (isset($oriValueTypes[$ek])) $oriValueTypes[$ek][valType($ev)]++;
					}
				}
			} elseif (is_object($od)) {
				$oriTypeofs->add('object');
			} else {
				$oriTypeofs->add(gettype($od));
			}
		}
		if (isset($p->orientation)) $byYear[$yr]['legacy_ori']++;

		// rock_unit
		if (isset($p->rock_unit)) {
			$ruSpots++; $byYear[$yr]['ru']++;
			$ru = $p->rock_unit;
			if (is_object($ru)) {
				$ruTypeofs->add('object');
				foreach (get_object_vars($ru) as $rk => $rv) $ruKeys->add($rk);
			} elseif (is_array($ru)) {
				$ruTypeofs->add('array');
				foreach ($ru as $el) if (is_object($el)) foreach (get_object_vars($el) as $rk => $rv) $ruKeys->add($rk);
			} else {
				$ruTypeofs->add(gettype($ru));
			}
		}

		// samples
		if (isset($p->samples) && is_array($p->samples)) {
			$smpSpots++; $byYear[$yr]['smp']++;
			foreach ($p->samples as $el) {
				if (!is_object($el)) continue;
				$smpElems++;
				foreach (get_object_vars($el) as $sk => $sv) $smpKeys->add($sk);
			}
		}

		// images
		if (isset($p->images) && is_array($p->images)) {
			$imgSpots++; $byYear[$yr]['img']++;
			foreach ($p->images as $el) {
				if (!is_object($el)) continue;
				$imgElems++;
				if (isset($el->image_type)) $imgTypes->add((string)$el->image_type);
				if (!empty($el->annotated)) $imgAnnotated++;
				if (isset($el->caption) && trim((string)$el->caption) !== '') $imgCaption++;
			}
		}

		// trace
		if (isset($p->trace)) {
			$traceSpots++;
			if (is_object($p->trace) && isset($p->trace->trace_type)) $traceTypes->add((string)$p->trace->trace_type);
		}

		// misc presence
		foreach ($miscCounts as $mk => $_) {
			if (isset($p->$mk)) $miscCounts[$mk]++;
		}

		// notes
		if (isset($p->notes) && is_string($p->notes) && trim($p->notes) !== '') {
			$notesSpots++; $byYear[$yr]['notes']++;
			$len = mb_strlen($p->notes);
			$notesLen += $len;
			if ($len > $notesMax) $notesMax = $len;
		}
	}
	if ($seen % 200000 < $BATCH) progress("blob pass: " . number_format($seen) . " / " . number_format($nSpots));
}
progress("blob pass complete: " . number_format($seen) . " spots in " . round(microtime(true) - $t0) . "s");

subsection('Parse health (the extraction-layer feasibility number)');
covRow('spots streamed', $seen, $nSpots);
covRow('json_decode failures', $parseFail, $seen);
covRow('parsed but no properties object', $noProps, $seen);

subsection('properties key universe (top 40 of ' . $propKeys->distinct() . ' distinct keys)');
$propKeys->printTop(40, $seen);

subsection('orientation_data');
covRow('spots with orientation_data', $oriSpots, $seen);
line('  container typeof distribution:');
$oriTypeofs->printTop(10, $oriSpots, '    ');
line('  total orientation elements: ' . number_format($oriElems));
line('  element key histogram (top 25):');
$oriElemKeys->printTop(25, $oriElems, '    ');
line('  measurement value types (number / numeric-string / other):');
foreach ($oriValueTypes as $f => $t) {
	line(sprintf('    %-12s %10s / %s / %s', $f, number_format($t['number']), number_format($t['numeric-string']), number_format($t['other'])));
}

subsection('rock_unit (feature data — compare against tag-derived rock_type table above)');
covRow('spots with rock_unit', $ruSpots, $seen);
line('  container typeof distribution:');
$ruTypeofs->printTop(10, $ruSpots, '    ');
line('  rock_unit key histogram (top 25):');
$ruKeys->printTop(25, $ruSpots, '    ');

subsection('samples (inline sample objects)');
covRow('spots with samples[]', $smpSpots, $seen);
line('  total sample elements: ' . number_format($smpElems));
line('  sample key histogram (top 25):');
$smpKeys->printTop(25, $smpElems, '    ');

subsection('images (inline image objects — full audit belongs to Phase 0.4)');
covRow('spots with images[]', $imgSpots, $seen);
line('  total image elements: ' . number_format($imgElems));
covRow('elements with annotated=true', $imgAnnotated, $imgElems);
covRow('elements with caption', $imgCaption, $imgElems);
line('  image_type values:');
$imgTypes->printTop(15, $imgElems, '    ');

subsection('trace');
covRow('spots with trace', $traceSpots, $seen);
line('  trace_type values:');
$traceTypes->printTop(15, $traceSpots, '    ');

subsection('other candidate blocks (presence only)');
foreach ($miscCounts as $mk => $n) covRow($mk, $n, $seen);

subsection('notes');
covRow('spots with non-empty notes', $notesSpots, $seen);
if ($notesSpots > 0) line('  avg length: ' . round($notesLen / $notesSpots) . ' chars, max: ' . number_format($notesMax));

// ---------------------------------------------------------------------------
section('5. Schema drift by upload year');

ksort($byYear);
line(sprintf('  %-6s %10s %22s %18s %14s %14s %14s %12s', 'year', 'spots', 'orientation_data', 'legacy orient.', 'rock_unit', 'samples', 'notes', 'images'));
foreach ($byYear as $yr => $c) {
	line(sprintf('  %-6s %10s %12s %s %10s %s %8s %s %8s %s %8s %s %6s %s',
		$yr, number_format($c['n']),
		number_format($c['ori']), pct($c['ori'], $c['n']),
		number_format($c['legacy_ori']), pct($c['legacy_ori'], $c['n']),
		number_format($c['ru']), pct($c['ru'], $c['n']),
		number_format($c['smp']), pct($c['smp'], $c['n']),
		number_format($c['notes']), pct($c['notes'], $c['n']),
		number_format($c['img']), pct($c['img'], $c['n'])));
}

line();
line('Done in ' . round(microtime(true) - $t0) . 's.');
?>
