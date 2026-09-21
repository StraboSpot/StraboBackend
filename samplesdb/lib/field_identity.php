<?php
/**
 * File: field_identity.php
 * Description: The one place that answers "which StraboSamples row does this
 *              StraboField sample object belong to?".
 *
 * A Field sample object (an entry of a spot's samples[]) carries two ids:
 *
 *   id                - the app's own LOCAL id. For a rich sample it equals
 *                       the sample-spot id and is referenced all over the app
 *                       (stubs, nesting, tags, backups). It never changes.
 *   strabosamples_id  - OPTIONAL linking id (2026-09-21). Set by the app when
 *                       the user links the sample to an existing StraboSamples
 *                       sample picked from /samplesdb/mysamples. Removing the
 *                       key unlinks.
 *
 * Identity rule: the linking id when present AND honored, else the local id.
 * A linking id is honored only when (linking id, owner) already exists on the
 * spine: the id is a claim the client makes, and an unknown value (typo,
 * fabricated id, a sample owned by someone else, a sample deleted since the
 * snapshot being restored) must not mint a junk row. Such entries quietly
 * keep their local identity.
 *
 * Callers: db/lib/sample_sync.php (live upload + remove mirrors),
 * StraboSamplesService::writeBackFieldSpot, samplesdb/migration/extract_field.php,
 * samplesdb/audit/audit_cross_system_drift.php. Keep them on these helpers so
 * the four never disagree.
 *
 * @package    StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

if (!function_exists('field_sample_identity')) {

/** Key on the Field sample object that carries the linking id. */
define('FIELD_SAMPLE_LINKING_KEY', 'strabosamples_id');

/**
 * The app's own id for this sample object, as a string ('' when absent).
 */
function field_sample_local_id($entry) {
    $entry = (array)$entry;
    if (!isset($entry['id']) || is_array($entry['id']) || is_object($entry['id'])) return '';
    return (string)$entry['id'];
}

/**
 * The linking id the client claims, trimmed ('' when absent or unusable).
 * A linking id equal to the local id is not a link.
 */
function field_sample_linking_id($entry) {
    $entry = (array)$entry;
    if (!isset($entry[FIELD_SAMPLE_LINKING_KEY])) return '';
    $v = $entry[FIELD_SAMPLE_LINKING_KEY];
    if (is_array($v) || is_object($v) || is_bool($v)) return '';
    $v = trim((string)$v);
    if ($v === '' || strlen($v) > 64) return '';
    if ($v === field_sample_local_id($entry)) return '';
    return $v;
}

/**
 * Does (sampleId, ownerPkey) exist on the spine? Memoized per request: bulk
 * uploads ask about the same handful of linked samples over and over.
 * Pass $forget = true to drop the memo (tests, and the fold path after a
 * delete).
 */
function field_sample_spine_exists($db, $sampleId, $ownerPkey, $forget = false) {
    static $memo = array();
    if ($forget) { $memo = array(); return false; }
    $k = (int)$ownerPkey . '|' . $sampleId;
    // Only positive answers are cached: a row can appear mid-request (the
    // Micro side uploading), but a claimed link never outlives its row.
    if (isset($memo[$k])) return true;
    $hit = $db->get_var_prepared(
        "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array((string)$sampleId, (int)$ownerPkey)
    );
    if (!empty($hit)) { $memo[$k] = true; return true; }
    return false;
}

/**
 * Resolve the StraboSamples identity of one Field sample object.
 *
 * @param array|object $entry     samples[] entry
 * @param object|null  $db        StraboDbPostgreSQL handle; null = trust the
 *                                claim without the existence check (offline
 *                                tooling only)
 * @param int|null     $ownerPkey spot owner (= sample owner for Field)
 * @return string  identity ('' when the entry has no id at all)
 */
function field_sample_identity($entry, $db = null, $ownerPkey = null) {
    $link = field_sample_linking_id($entry);
    if ($link !== '') {
        if ($db === null || field_sample_spine_exists($db, $link, $ownerPkey)) {
            return $link;
        }
    }
    return field_sample_local_id($entry);
}

} // function_exists guard
