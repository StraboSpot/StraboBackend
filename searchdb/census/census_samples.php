<?php
/**
 * StraboSearch Phase 0.2 census — StraboSamples spine.
 *
 * Search ships after the samples cutover (locked decision), so the
 * strabosamples schema is available infrastructure. This census profiles
 * the spine as a search source: per-field coverage, vocab conformance of
 * the display_* fields, link distribution, and cross-subsystem reach.
 *
 * Vocab lists are inlined from samplesdb/lib/vocab.php @ samples/main
 * (d2f19ca) because samples/* does not merge to develop until cutover and
 * this census must run from search/* branches. Point-in-time copy is fine
 * for a profiling tool; re-sync if vocab.php changes before cutover.
 *
 * Usage: docker exec strabo-php php /srv/app/www/searchdb/census/census_samples.php
 * READ-ONLY.
 *
 * @package StraboSearch Census
 */

chdir(__DIR__ . '/../../');
require_once('includes/config.inc.php');
require_once('db.php');
require_once(__DIR__ . '/_census_lib.php');

$VOCAB_TYPES = array(
	'intact_rock', 'fragmented_roc', 'sediment', 'tephra', 'carbon_or_animal',
	'Glass', 'Ice', 'Ceramic', 'Plastic', 'Metal', 'Soil', 'Mineral',
	'Igneous Rock', 'Sedimentary Rock', 'Metamorphic Rock', 'Epos Lithologies',
	'Standards', 'Commodity',
);
$VOCAB_PURPOSES = array(
	'fabric___micro', 'petrology', 'geochronology', 'geochemistry',
	'active_eruptio', 'micro_beam', 'particle_size', 'dre_density',
	'clast_density', 'particle_shape', 'cryptotephra',
);

$t0 = microtime(true);
line('SAMPLES SPINE CENSUS — ' . date('Y-m-d H:i:s'));

// ---------------------------------------------------------------------------
section('1. Inventory');

$nSmp = (int)$db->get_var("select count(*) from strabosamples.samples");
$nLink = (int)$db->get_var("select count(*) from strabosamples.sample_subsystem_links");
covRow('samples', $nSmp, $nSmp);
covRow('subsystem links', $nLink, $nLink);
covRow('changelog rows', (int)$db->get_var("select count(*) from strabosamples.sample_changelog"), $nSmp);
covRow('collaborator rows', (int)$db->get_var("select count(*) from strabosamples.sample_collaborators"), $nSmp);

// ---------------------------------------------------------------------------
section('2. Spine field coverage');

columnCoverage($db, 'strabosamples.samples', array(
	'name', 'igsn', 'description', 'notes', 'display_sample_type', 'display_sample_purpose',
), $nSmp);
covRow('latitude/longitude pair', (int)$db->get_var("select count(*) from strabosamples.samples where latitude is not null and longitude is not null"), $nSmp);
covRow('parent_sample_id set', (int)$db->get_var("select count(*) from strabosamples.samples where parent_sample_id is not null"), $nSmp);

// ---------------------------------------------------------------------------
section('3. Vocab conformance (display_* fields)');

function vocabBuckets($db, $col, $vocab, $total) {
	$inList = "'" . implode("','", array_map(function ($v) { return str_replace("'", "''", $v); }, $vocab)) . "'";
	$nVocab = (int)$db->get_var("select count(*) from strabosamples.samples where $col in ($inList)");
	$nSet   = (int)$db->get_var("select count(*) from strabosamples.samples where $col is not null and trim($col) != ''");
	$nJunk  = (int)$db->get_var("select count(*) from strabosamples.samples where $col is not null and ($col ~ '^[0-9 .]+$' or $col ~ '^\\s*$' or $col = '__other__')");
	covRow("$col set", $nSet, $total);
	covRow("  vocab-conformant", $nVocab, $nSet);
	covRow("  free-text", $nSet - $nVocab - $nJunk, $nSet);
	covRow("  junk (numeric/empty/sentinel)", $nJunk, $nSet);
}
vocabBuckets($db, 'display_sample_type', $VOCAB_TYPES, $nSmp);
vocabBuckets($db, 'display_sample_purpose', $VOCAB_PURPOSES, $nSmp);

valueTable($db, "select display_sample_type, count(*) from strabosamples.samples where display_sample_type is not null and trim(display_sample_type) != '' group by 1 order by 2 desc limit 15", 'top display_sample_type values');
valueTable($db, "select display_sample_purpose, count(*) from strabosamples.samples where display_sample_purpose is not null and trim(display_sample_purpose) != '' group by 1 order by 2 desc limit 15", 'top display_sample_purpose values');

// ---------------------------------------------------------------------------
section('4. Cross-subsystem reach');

valueTable($db, "select subsystem, count(*) from strabosamples.sample_subsystem_links group by 1 order by 2 desc", 'links by subsystem', $nLink);
covRow('samples with 0 links (modal-only)', (int)$db->get_var("
	select count(*) from strabosamples.samples s
	where not exists (select 1 from strabosamples.sample_subsystem_links l
		where l.sample_id = s.id and l.sample_userpkey = s.userpkey)"), $nSmp);
covRow('samples linked to 2+ subsystems', (int)$db->get_var("
	select count(*) from (
		select sample_id, sample_userpkey from strabosamples.sample_subsystem_links
		group by sample_id, sample_userpkey having count(distinct subsystem) >= 2) t"), $nSmp);

subsection('changelog activity by source');
valueTable($db, "select source_subsystem, count(*) from strabosamples.sample_changelog group by 1 order by 2 desc", 'changelog rows by source_subsystem');

line();
line('Done in ' . round(microtime(true) - $t0) . 's.');
?>
