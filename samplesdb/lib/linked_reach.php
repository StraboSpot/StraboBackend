<?php
/**
 * File: samplesdb/lib/linked_reach.php
 * Description: May a caller read someone else's StraboMicro project or
 *              StraboExperimental experiment because it holds a sample the
 *              caller can already reach? (Jason 2026-09-29, for the ids in
 *              the Field download's strabosamples_linked key.)
 *
 *              Yes when the project / experiment holds a StraboSamples sample
 *              (same owner) and the caller either
 *                - is an accepted, not-removed collaborator on that sample, or
 *                - is an accepted, enabled collaborator on the owner's Field
 *                  project whose dataset holds that sample (the people who get
 *                  the key in the download).
 *
 *              Read access only; callers check owner / public themselves
 *              first. Field links often record only dataset_id (migrated
 *              rows), so the dataset -> project step asks Neo4j, the Field
 *              source of truth, anchored on the owner (ids are not unique).
 *
 * @package    StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

if (!function_exists('linked_reach_micro_project')) {

/**
 * @param object $db               StraboDbPostgreSQL
 * @param object $neodb            StraboDbNeo4j
 * @param int    $callerPkey
 * @param int    $projectInternalId micro_projectmetadata.id
 * @param int    $ownerPkey        the project's owner
 */
function linked_reach_micro_project($db, $neodb, $callerPkey, $projectInternalId, $ownerPkey) {
    return _linked_reach($db, $neodb, (int)$callerPkey, 'micro', (string)(int)$projectInternalId, (int)$ownerPkey);
}

/**
 * @param int $experimentPkey straboexp.experiment.pkey
 */
function linked_reach_experiment($db, $neodb, $callerPkey, $experimentPkey, $ownerPkey) {
    return _linked_reach($db, $neodb, (int)$callerPkey, 'experimental', (string)(int)$experimentPkey, (int)$ownerPkey);
}

/** @internal */
function _linked_reach($db, $neodb, $caller, $subsystem, $referenceId, $owner) {
    if ($caller <= 0 || $owner <= 0 || $caller === $owner) return false;

    // 1. Accepted collaborator on one of the samples it holds.
    $hit = $db->get_var_prepared(
        "SELECT 1
           FROM strabosamples.sample_subsystem_links l
           JOIN strabosamples.sample_collaborators c
             ON c.sample_id = l.sample_id AND c.sample_userpkey = l.sample_userpkey
          WHERE l.subsystem = $1 AND l.reference_id = $2 AND l.sample_userpkey = $3
            AND c.collaborator_pkey = $4 AND c.accepted AND c.removed_at IS NULL
          LIMIT 1",
        array($subsystem, $referenceId, $owner, $caller)
    );
    if (!empty($hit)) return true;

    // 2. Collaborator on the owner's Field project holding one of those samples.
    $projects = $db->get_results_prepared(
        "SELECT strabo_project_id FROM collaborators
          WHERE project_owner_user_pkey = $1 AND collaborator_user_pkey = $2
            AND accepted = true AND disabled = false",
        array($owner, $caller)
    );
    $projectIds = array();
    foreach ((is_array($projects) ? $projects : array()) as $p) {
        if (ctype_digit((string)$p->strabo_project_id)) $projectIds[(string)$p->strabo_project_id] = true;
    }
    if (!$projectIds) return false;

    $fieldLinks = $db->get_results_prepared(
        "SELECT f.reference_metadata->>'project_id' AS project_id,
                f.reference_metadata->>'dataset_id' AS dataset_id
           FROM strabosamples.sample_subsystem_links l
           JOIN strabosamples.sample_subsystem_links f
             ON f.sample_id = l.sample_id AND f.sample_userpkey = l.sample_userpkey AND f.subsystem = 'field'
          WHERE l.subsystem = $1 AND l.reference_id = $2 AND l.sample_userpkey = $3",
        array($subsystem, $referenceId, $owner)
    );
    $datasetIds = array();
    foreach ((is_array($fieldLinks) ? $fieldLinks : array()) as $f) {
        if ($f->project_id !== null && isset($projectIds[(string)$f->project_id])) return true;
        if ($f->dataset_id !== null && ctype_digit((string)$f->dataset_id)) $datasetIds[(string)$f->dataset_id] = true;
    }
    if (!$datasetIds) return false;

    // Integer literals only (checked above), so inlining is Cypher-safe.
    try {
        $n = $neodb->get_var(
            "MATCH (p:Project {userpkey: $owner})-[:HAS_DATASET]->(d:Dataset {userpkey: $owner})
              WHERE p.id IN [" . implode(',', array_keys($projectIds)) . "]
                AND d.id IN [" . implode(',', array_keys($datasetIds)) . "]
             RETURN count(d)"
        );
    } catch (\Throwable $e) {
        if (method_exists($neodb, 'reconnect')) $neodb->reconnect();   // a failed query poisons the Bolt connection
        error_log('linked_reach: ' . $e->getMessage());
        return false;
    }
    return (int)$n > 0;
}

} // function_exists guard
