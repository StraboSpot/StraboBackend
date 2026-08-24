<?php
/**
 * File: heal_micro_spine_gaps.php
 * Description: CLI healer for two Micro-to-StraboSamples gaps found on prod
 *              2026-08-23 (design doc: docs/StraboSearch/GlobeView_Design_Proposal.md
 *              §9.8 follow-up; audit: audit_cross_system_drift.php):
 *
 *              1. MISSING SPINE ROW - a micro_samplemetadata sample with a
 *                 strabo_id and a resolvable project owner but no
 *                 strabosamples.samples row under that owner. Seen when a
 *                 Micro-owned sample is deleted through the samples
 *                 interface (the DELETE endpoint has no subsystem-ownership
 *                 gate and there is no writeback, so the Micro source keeps
 *                 the sample). Consequences: audit_cross_system_drift
 *                 micro_coverage DRIFT missing_spine_row; strabosearch
 *                 verify_extended [sanity] "image parent_sample -> spine row
 *                 missing" for every micrograph of that sample.
 *                 Heal = exactly what a fresh Micro upload would do for that
 *                 one sample: micro_sample_sync() with an object rebuilt
 *                 from the source row (same camelCase shape loadProjectJSON
 *                 hands it), which re-creates the spine row + link and
 *                 refreshes the search index.
 *
 *              2. ZERO-COORDINATE RESIDUE - spine latitude/longitude (and
 *                 the micro_data JSONB keys) hold 0 where the source column
 *                 is NULL. Cause: the Micro app sends numeric 0 for
 *                 un-located samples; PHP 7's loose `0 != ""` made the
 *                 source insert drop it while the mirror kept 0.0 (fixed
 *                 in microdb/lib/sample_sync.php micro_coord_or_null()).
 *                 Consequences: audit micro_fidelity DRIFT on latitude/
 *                 longitude; (0,0) sample points on the StraboSearch globe.
 *                 Heal = per field, NULL the spine value (only when Micro
 *                 owns the spine, i.e. field_data IS NULL) and the
 *                 micro_data key, write a changelog row, re-touch the
 *                 search index. Rows a user has since re-located through
 *                 the samples interface are not matched (value <> 0).
 *
 *              Usage (dry-run default; nothing written without --apply):
 *                docker exec strabo-php php \
 *                  /srv/app/www/samplesdb/audit/heal_micro_spine_gaps.php [--user PKEY]
 *                docker exec strabo-php php \
 *                  /srv/app/www/samplesdb/audit/heal_micro_spine_gaps.php --apply [--user PKEY]
 *
 *              Exit code: 0 clean or healed, 1 when findings remain
 *              (dry-run with hits, or an apply that could not heal a row).
 */

require_once __DIR__ . '/../../includes/config.inc.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../microdb/lib/sample_sync.php';
require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';

$apply = in_array('--apply', $argv, true);
$userFilter = null;
foreach ($argv as $i => $a) {
    if ($a === '--user' && isset($argv[$i + 1])) $userFilter = (int)$argv[$i + 1];
    elseif (strpos($a, '--user=') === 0) $userFilter = (int)substr($a, 7);
}
echo $apply ? "MODE: APPLY\n" : "MODE: dry-run (pass --apply to heal)\n";
if ($userFilter !== null) echo "SCOPE: userpkey $userFilter\n";
$scopePm = ($userFilter !== null) ? " AND pm.userpkey = " . (int)$userFilter : '';

$remaining = 0;

// ---------------------------------------------------------------------------
// 1. Missing spine rows
// ---------------------------------------------------------------------------
echo "\n== 1. Micro samples with no strabosamples row ==\n";
$missing = $db->get_results("
    SELECT sm.*, dm.id AS dataset_internal_id,
           pm.id AS project_internal_id, pm.strabo_id AS project_strabo_id,
           pm.userpkey AS project_userpkey, pm.name AS project_name,
           (SELECT count(*) FROM strabomicro.micro_micrographmetadata mm
             WHERE mm.sample_id = sm.id) AS micrograph_count
      FROM strabomicro.micro_samplemetadata sm
      JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
      JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
     WHERE sm.strabo_id IS NOT NULL AND sm.strabo_id <> ''
       AND pm.userpkey IS NOT NULL$scopePm
       AND NOT EXISTS (SELECT 1 FROM strabosamples.samples s
                        WHERE s.id = sm.strabo_id AND s.userpkey = pm.userpkey)
     ORDER BY pm.userpkey, pm.id, sm.id
");
if (!is_array($missing) || count($missing) === 0) {
    echo "  CLEAN - none.\n";
} else {
    foreach ($missing as $r) {
        $label = $r->sampleid !== null && $r->sampleid !== '' ? $r->sampleid : $r->label;
        echo sprintf("  sample %s (\"%s\") owner %d project %d \"%s\" micrographs %d\n",
            $r->strabo_id, $label, (int)$r->project_userpkey, (int)$r->project_internal_id,
            $r->project_name, (int)$r->micrograph_count);
        if (!$apply) { $remaining++; continue; }

        // Rebuild the loadProjectJSON-shaped sample object from the source
        // row (camelCase keys micro_sample_sync reads).
        $sample = (object)array(
            'id'                     => $r->strabo_id,
            'existsOnServer'         => $r->existsonserver,
            'label'                  => $r->label,
            'sampleID'               => $r->sampleid,
            'mainSamplingPurpose'    => $r->mainsamplingpurpose,
            'sampleDescription'      => $r->sampledescription,
            'materialType'           => $r->materialtype,
            'inplacenessOfSample'    => $r->inplacenessofsample,
            'orientedSample'         => $r->orientedsample,
            'sampleSize'             => $r->samplesize,
            'degreeOfWeathering'     => $r->degreeofweathering,
            'sampleNotes'            => $r->samplenotes,
            'sampleType'             => $r->sampletype,
            'color'                  => $r->color,
            'lithology'              => $r->lithology,
            'sampleUnit'             => $r->sampleunit,
            'otherMaterialType'      => $r->othermaterialtype,
            'sampleOrientationNotes' => $r->sampleorientationnotes,
            'otherSamplingPurpose'   => $r->othersamplingpurpose,
            'longitude'              => $r->longitude,
            'latitude'               => $r->latitude,
        );
        $sid = micro_sample_sync(
            $db, $sample,
            (string)$r->project_strabo_id,
            (int)$r->project_internal_id,
            (int)$r->project_userpkey,
            (int)$r->dataset_internal_id,
            (int)$r->micrograph_count
        );
        $ok = $sid !== null && (bool)$db->get_var_prepared(
            "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($r->strabo_id, (int)$r->project_userpkey));
        echo $ok ? "    -> healed (spine row + link re-created)\n"
                 : "    -> FAILED to re-create (see StraboSamplesService errors)\n";
        if (!$ok) $remaining++;
    }
}

// ---------------------------------------------------------------------------
// 2. Zero-coordinate residue
// ---------------------------------------------------------------------------
echo "\n== 2. Spine/micro_data coordinates = 0 where the Micro source is NULL ==\n";
$zero = $db->get_results("
    SELECT s.id, s.userpkey, s.name, s.latitude AS s_lat, s.longitude AS s_lon,
           s.field_data IS NULL AS micro_owns_spine,
           s.micro_data IS NOT NULL AS has_micro_data,
           NULLIF(s.micro_data->>'latitude', '')  AS md_lat,
           NULLIF(s.micro_data->>'longitude', '') AS md_lon,
           sm.latitude AS src_lat, sm.longitude AS src_lon
      FROM strabosamples.samples s
      JOIN strabomicro.micro_samplemetadata sm ON sm.strabo_id = s.id
      JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
      JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id AND pm.userpkey = s.userpkey
     WHERE ( (sm.latitude  IS NULL AND (s.latitude  = 0 OR (s.micro_data->>'latitude')::float  = 0))
          OR (sm.longitude IS NULL AND (s.longitude = 0 OR (s.micro_data->>'longitude')::float = 0)) )$scopePm
     ORDER BY s.userpkey, s.id
");
if (!is_array($zero) || count($zero) === 0) {
    echo "  CLEAN - none.\n";
} else {
    $seen = array();
    foreach ($zero as $r) {
        $k = $r->id . '|' . $r->userpkey;
        if (isset($seen[$k])) continue;   // a sample hosted by several micro projects
        $seen[$k] = true;
        $microOwns = ($r->micro_owns_spine === 't' || $r->micro_owns_spine === true);
        $fixLat = ($r->src_lat === null && ((float)$r->s_lat == 0.0 && $r->s_lat !== null || (float)$r->md_lat == 0.0 && $r->md_lat !== null));
        $fixLon = ($r->src_lon === null && ((float)$r->s_lon == 0.0 && $r->s_lon !== null || (float)$r->md_lon == 0.0 && $r->md_lon !== null));
        $fields = array();
        if ($fixLat) $fields[] = 'latitude';
        if ($fixLon) $fields[] = 'longitude';
        echo sprintf("  sample %s (\"%s\") owner %d: %s -> NULL (spine %s, micro_data %s)\n",
            $r->id, $r->name, (int)$r->userpkey, implode('+', $fields),
            $microOwns ? 'micro-owned: fixed' : 'field-owned: left alone',
            ($r->has_micro_data === 't' || $r->has_micro_data === true) ? 'fixed' : 'absent');
        if (!$apply) { $remaining++; continue; }

        $db->query('BEGIN');
        $changes = array('source' => 'heal_micro_spine_gaps', 'spine_written' => false, 'fields' => $fields);
        if ($microOwns) {
            $sets = array();
            foreach ($fields as $f) $sets[] = "$f = NULL";
            $db->prepare_query(
                "UPDATE strabosamples.samples SET " . implode(', ', $sets) . ", modified_at = now(), modified_by = $3
                  WHERE id=$1 AND userpkey=$2 AND field_data IS NULL",
                array($r->id, (int)$r->userpkey, (int)$r->userpkey));
            $changes['spine_written'] = true;
        }
        foreach ($fields as $f) {
            $db->prepare_query(
                "UPDATE strabosamples.samples SET micro_data = jsonb_set(micro_data, $3::text[], 'null'::jsonb)
                  WHERE id=$1 AND userpkey=$2 AND micro_data IS NOT NULL",
                array($r->id, (int)$r->userpkey, '{' . $f . '}'));
        }
        $db->prepare_query(
            "INSERT INTO strabosamples.sample_changelog
               (sample_id, sample_userpkey, changed_by, change_type, source_subsystem, changes)
             VALUES ($1, $2, $3, 'update', 'micro', $4)",
            array($r->id, (int)$r->userpkey, (int)$r->userpkey, json_encode($changes)));
        $db->query('COMMIT');
        StraboSearchSync::touchSample($db, $r->id, (int)$r->userpkey);
        echo "    -> healed\n";
    }
}

echo "\n" . ($remaining === 0 ? "DONE - clean.\n" : "DONE - $remaining finding(s) " . ($apply ? "not healed" : "would be healed with --apply") . ".\n");
exit($remaining === 0 ? 0 : 1);
