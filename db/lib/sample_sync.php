<?php
/**
 * File: sample_sync.php
 * Description: Mirror StraboField sample writes into strabosamples.* via
 *              StraboSamplesService. Called from strabospotclass::insertSpot
 *              (after the Neo4j write) and ::deleteSingleSpot (before the
 *              Neo4j delete), so the cross-system spine + subsystem_link
 *              stays in sync with the authoritative Neo4j Spot.
 *
 * Field is the only HYBRID-storage source: the Neo4j Spot remains
 * authoritative (rich petrology/sed/images stay there) and
 * strabosamples.* mirrors only the sample sub-object + links. The
 * extraction algorithm here mirrors samplesdb/migration/extract_field.php
 * so a re-upload converges on the same row state as the migration.
 *
 * Sample-model split (per design §9.1, confirmed by the v5 prod audit):
 *   RICH    — spot.isSample === 1 (Neo4j int), authoritative object at
 *             samples[0], spot.id == samples[0].id. reference_id = spot.id.
 *   LEGACY  — full sample object inline in a normal spot's samples[] array.
 *             No isSample flag. reference_id = the holding spot.id.
 *   STUB    — entry in a parent spot's samples[] whose id matches a rich
 *             sample-spot's id. Carries no real data — promoted-then-stub.
 *             Detected via Cypher: ANY Spot{id, userpkey, isSample=1} exists.
 *             SKIPPED on upload to avoid duplicating the rich row.
 *
 * Project/dataset context flow:
 *   - Bulk controllers (ProjectDatasetsSpotsController, DatasetSpotsController,
 *     etc.) set $strabo->currentProjectStraboId + ::currentDatasetStraboId
 *     before their spot loop. The helper reads these for link metadata and
 *     auto-seed-collaborators lookups.
 *   - Single-spot paths (FeatureController update, /feature/{id}) don't set
 *     context — the helper falls back to a Cypher lookup off the now-written
 *     Spot's (Dataset)-[:HAS_SPOT] relationship. For UPDATE paths the
 *     relationship already exists so the lookup succeeds. For a NEW spot
 *     via /feature with no dataset attached yet, project context is null;
 *     auto-seed and metadata are deferred to the next re-upload.
 *
 * Phase-1-cutover note: this writes to a schema that doesn't exist on
 * production until the StraboSamples Phase 1 cutover runs. See memory
 * project_strabosamples_phase1_cutover_deferred + the branch isolation
 * rule. On dev (post-Phase-1-schema-apply), the writes land cleanly.
 *
 * @package    StraboField
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

require_once __DIR__ . '/../../samplesdb/services/StraboSamplesService.php';

if (!function_exists('field_sample_sync_spot')) {

/**
 * Walk a Field Spot's samples array (rich + legacy) and mirror each real
 * sample into strabosamples.* via StraboSamplesService::upsertSample.
 *
 * Stubs (entries whose id matches a rich sample-spot in Neo4j) are
 * skipped. When this spot is itself the rich sample-spot, any stale
 * stub-link rows pointing at OTHER spots for this sample id are
 * cleaned up at the end (handles upload-ordering edge cases where the
 * parent spot was uploaded before the rich one).
 *
 * @param object   $db                 StraboDbPostgreSQL handle
 * @param object   $neodb              StraboDbNeo4j handle
 * @param object   $properties         The spot's properties (from the upload JSON's
 *                                     $upload->properties — same object that was just
 *                                     written to Neo4j).
 * @param int      $spotId             The Spot's id
 * @param int      $userpkey           The Spot's owner pkey (= sample owner for Field)
 * @param string|null $projectStraboId Project's strabo_id (for link metadata + auto-seed
 *                                     lookups). When null, Cypher-lookup is attempted.
 * @param string|null $datasetStraboId Dataset's strabo_id (for link metadata). When null,
 *                                     Cypher-lookup is attempted.
 * @return array                        List of strabo_ids of samples mirrored.
 */
function field_sample_sync_spot($db, $neodb, $properties, $spotId, $userpkey, $projectStraboId = null, $datasetStraboId = null) {

    if (!is_object($properties)) {
        return array();
    }
    $samples = isset($properties->samples) ? $properties->samples : null;
    if (empty($samples) || !is_array($samples)) {
        return array();
    }

    $isRichSpot = _field_sample_sync_is_rich_props($properties);
    $spotId   = (int)$spotId;
    $userpkey = (int)$userpkey;

    // Resolve project/dataset context via Cypher when caller didn't supply
    // it (single-spot edit paths). For NEW spots without any dataset
    // relationship yet, the OPTIONAL MATCH leaves both null.
    if ($projectStraboId === null || $datasetStraboId === null) {
        list($projectStraboId, $datasetStraboId) = _field_sample_sync_cypher_context(
            $neodb, $spotId, $userpkey, $projectStraboId, $datasetStraboId
        );
    }

    // Compute auto-seed collaborator list once per spot.
    $seed = _field_sample_sync_compute_seed($db, $projectStraboId, $userpkey);

    $mirrored = array();

    // RICH path: spot.isSample === 1 → authoritative object at samples[0],
    // reference_id is this spot's own id.
    if ($isRichSpot) {
        $obj = is_array($samples) && isset($samples[0]) ? $samples[0] : null;
        if ($obj === null) {
            return array();
        }
        $sampleId = isset(((object)$obj)->id) ? (string)((object)$obj)->id : '';
        if ($sampleId === '') {
            return array();
        }
        _field_sample_sync_emit_one(
            $db, $neodb,
            $obj, $properties, $sampleId, $userpkey,
            $spotId, /* isRich */ true,
            $projectStraboId, $datasetStraboId, $seed
        );
        $mirrored[] = $sampleId;

        // Cleanup stale stub-links from earlier uploads of a parent spot
        // that happened to land before this rich spot. Only affects rows
        // for this same sample id that point at OTHER spots — the
        // canonical link we just wrote (reference_id = $spotId) is preserved.
        $db->prepare_query(
            "DELETE FROM strabosamples.sample_subsystem_links
              WHERE sample_id = $1
                AND sample_userpkey = $2
                AND subsystem = 'field'
                AND reference_id <> $3",
            array($sampleId, $userpkey, (string)$spotId)
        );
        return $mirrored;
    }

    // LEGACY path: walk every entry, skip stubs that resolve to a rich
    // sample-spot in Neo4j.
    foreach ($samples as $entry) {
        $obj = is_array($entry) || is_object($entry) ? (object)$entry : null;
        if ($obj === null) continue;
        $sampleId = isset($obj->id) ? (string)$obj->id : '';
        if ($sampleId === '') continue;

        // Stub check: another Spot owned by the same user with id == $sampleId
        // and isSample = 1 means this entry is a parent-stub left behind by
        // a rich sample-spot. Skip it — the rich row already exists (or
        // will exist when the rich spot uploads).
        $sampleIdInt = ctype_digit($sampleId) ? (int)$sampleId : 0;
        if ($sampleIdInt > 0) {
            $stubProbe = $neodb->get_var(
                "MATCH (s:Spot {id:$sampleIdInt, userpkey:$userpkey, isSample:1}) RETURN s.id LIMIT 1"
            );
            if (!empty($stubProbe)) {
                continue;
            }
        }

        _field_sample_sync_emit_one(
            $db, $neodb,
            $obj, $properties, $sampleId, $userpkey,
            $spotId, /* isRich */ false,
            $projectStraboId, $datasetStraboId, $seed
        );
        $mirrored[] = $sampleId;
    }
    return $mirrored;
}

/**
 * Mirror the removal of all samples attached to a Spot that's about to be
 * deleted from Neo4j. Call BEFORE the DETACH DELETE so we can still read
 * the spot's json_samples and its isSample flag.
 *
 * For a rich sample-spot, removes the sample itself (priority-aware:
 * removeSubsystemSample only deletes when no other subsystem has data).
 * For a parent spot with legacy inline samples, removes each legacy
 * sample's Field link (and the sample row when no other subsystem
 * still owns it).
 *
 * @param object $db         StraboDbPostgreSQL handle
 * @param object $neodb      StraboDbNeo4j handle
 * @param int    $spotId     The Spot's id (about to be deleted)
 * @param int    $userpkey   The Spot's owner pkey
 * @return int   Number of strabosamples sync calls attempted (one per real sample).
 */
function field_sample_sync_remove_spot($db, $neodb, $spotId, $userpkey) {

    $spotId   = (int)$spotId;
    $userpkey = (int)$userpkey;
    if ($spotId <= 0) return 0;

    // Read the spot's flattened json_samples + isSample BEFORE the delete.
    $rec = $neodb->getRecord(
        "MATCH (s:Spot {id:$spotId, userpkey:$userpkey}) RETURN s LIMIT 1"
    );
    if (!$rec) return 0;
    $props = $rec->get('s')->values();
    if (!is_array($props)) return 0;

    $isRich = _field_sample_sync_is_rich_props((object)$props);
    $raw = isset($props['json_samples']) ? $props['json_samples'] : null;
    if (!is_string($raw) || $raw === '') {
        return 0;
    }
    $samples = json_decode($raw, true);
    if (!is_array($samples)) return 0;

    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey($userpkey);
    $n = 0;

    if ($isRich) {
        // Rich sample-spot: the canonical sample is samples[0].id (== spot.id).
        $obj = isset($samples[0]) ? (array)$samples[0] : null;
        $sampleId = ($obj && isset($obj['id'])) ? (string)$obj['id'] : (string)$spotId;
        if ($sampleId !== '') {
            $svc->removeSubsystemSample('field', $sampleId, $userpkey);
            $n++;
        }
        return $n;
    }

    // Legacy: walk all entries, skip stubs (entries that point at a rich
    // spot still alive in Neo4j).
    foreach ($samples as $entry) {
        $entry = (array)$entry;
        $sampleId = isset($entry['id']) ? (string)$entry['id'] : '';
        if ($sampleId === '') continue;
        $sampleIdInt = ctype_digit($sampleId) ? (int)$sampleId : 0;
        if ($sampleIdInt > 0) {
            $stub = $neodb->get_var(
                "MATCH (s:Spot {id:$sampleIdInt, userpkey:$userpkey, isSample:1}) RETURN s.id LIMIT 1"
            );
            if (!empty($stub)) continue;
        }
        $svc->removeSubsystemSample('field', $sampleId, $userpkey);
        $n++;
    }
    return $n;
}

/**
 * @internal
 */
function _field_sample_sync_is_rich_props($p) {
    $v = is_object($p) ? (isset($p->isSample) ? $p->isSample : null)
                       : (isset($p['isSample']) ? $p['isSample'] : null);
    return ($v === 1 || $v === true || $v === '1' || $v === 'true');
}

/**
 * @internal — Cypher lookup for project/dataset context off the current Spot.
 * Returns array($projectId, $datasetId), each possibly null. Only overwrites
 * fields the caller didn't already supply.
 */
function _field_sample_sync_cypher_context($neodb, $spotId, $userpkey, $projectIn, $datasetIn) {
    $rec = $neodb->getRecord("
        MATCH (s:Spot {id:$spotId, userpkey:$userpkey})
        OPTIONAL MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s)
        RETURN d.id AS did, p.id AS pid LIMIT 1
    ");
    $pid = $projectIn;
    $did = $datasetIn;
    if ($rec) {
        if ($pid === null) {
            $v = $rec->value('pid');
            if ($v !== null && $v !== '') $pid = (string)$v;
        }
        if ($did === null) {
            $v = $rec->value('did');
            if ($v !== null && $v !== '') $did = (string)$v;
        }
    }
    return array($pid, $did);
}

/**
 * @internal — pull accepted, non-disabled project collaborators from the
 * public.collaborators table for auto-seed. Returns an array with two
 * keys: 'list' (int pkeys) and 'level' ('edit'|'readonly'). Empty list
 * when project context is unknown.
 */
function _field_sample_sync_compute_seed($db, $projectStraboId, $ownerPkey) {
    $out = array('list' => array(), 'level' => 'edit');
    if (!$projectStraboId) return $out;
    $rows = $db->get_results_prepared(
        "SELECT collaborator_user_pkey, collaboration_level
           FROM collaborators
          WHERE strabo_project_id = $1
            AND project_owner_user_pkey = $2
            AND accepted = TRUE
            AND disabled = FALSE",
        array((string)$projectStraboId, (int)$ownerPkey)
    );
    if (!is_array($rows)) return $out;
    foreach ($rows as $r) {
        $pk = (int)$r->collaborator_user_pkey;
        if ($pk > 0) $out['list'][] = $pk;
        if (isset($r->collaboration_level) && $r->collaboration_level === 'readonly') {
            $out['level'] = 'readonly';
        }
    }
    return $out;
}

/**
 * @internal — project one sample object into strabosamples.* and emit the
 * subsystem_link row. Spine projection mirrors extract_field.php.
 */
function _field_sample_sync_emit_one($db, $neodb, $sampleObj, $spotProps, $sampleId, $userpkey,
                                     $spotId, $isRich, $projectStraboId, $datasetStraboId, $seed) {
    $sampleObj = (object)$sampleObj;

    // Spine projection — Field key-name mapping per design §9.1.
    $name        = isset($sampleObj->sample_id_name)        ? (string)$sampleObj->sample_id_name        : null;
    $igsn        = isset($sampleObj->Sample_IGSN)           ? (string)$sampleObj->Sample_IGSN           : null;
    $description = isset($sampleObj->sample_description)    ? (string)$sampleObj->sample_description    : null;
    $notes       = isset($sampleObj->sample_notes)          ? (string)$sampleObj->sample_notes          : null;
    $material    = isset($sampleObj->material_type)         ? (string)$sampleObj->material_type         : null;
    $purpose     = isset($sampleObj->main_sampling_purpose) ? (string)$sampleObj->main_sampling_purpose : null;
    if ($material === 'other' && !empty($sampleObj->other_material_type)) {
        $material = (string)$sampleObj->other_material_type;
    }

    list($lat, $lng) = _field_sample_sync_extract_geom($spotProps);

    $spine = array(
        'name'                   => $name,
        'igsn'                   => $igsn,
        'description'            => $description,
        'notes'                  => $notes,
        'latitude'               => $lat,
        'longitude'              => $lng,
        'display_sample_type'    => $material,
        'display_sample_purpose' => $purpose,
    );

    // field_data JSONB carries the full sample sub-object verbatim
    // (mirrors extract_field's subsystem_data = $sampleObj). Decode to
    // associative arrays for stable JSON serialization.
    $jsonbData = json_decode(json_encode($sampleObj), true);

    $reference = array(
        'reference_id'         => (string)$spotId,
        'reference_userpkey'   => (int)$userpkey,
        'reference_metadata'   => array(
            'dataset_id' => $datasetStraboId !== null ? (string)$datasetStraboId : null,
            'project_id' => $projectStraboId !== null ? (string)$projectStraboId : null,
            'rich'       => (bool)$isRich,
        ),
    );

    $opts = array();
    if (!empty($seed['list'])) {
        $opts['autoSeedCollaborators'] = $seed['list'];
        $opts['autoSeedLevel']         = $seed['level'];
    }

    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey((int)$userpkey);
    $svc->upsertSample(
        'field',
        $sampleId,
        (int)$userpkey,
        $spine,
        $jsonbData,
        $reference,
        array(),   // Field children (composition/parameters/documents) not modeled today
        $opts
    );
}

/**
 * @internal — thin pass-through to the canonical lat/lng extractor in
 * samplesdb/lib/field_geom.php. See that file for the full rule + history.
 * Kept as a wrapper so existing callers don't need to change name.
 */
function _field_sample_sync_extract_geom($spotProps) {
    require_once __DIR__ . '/../../samplesdb/lib/field_geom.php';
    return samples_field_extract_geom_from_spot($spotProps);
}

}
