<?php
/**
 * File: experimental/lib/experiment_read.php
 * Description: Read-only StraboExperimental data for the /expdb/ REST API
 *              (HTTP Basic, like /db/ and /microdb/), so the StraboField app
 *              can follow the experiment_pkey / project_pkey ids in a Field
 *              download's strabosamples_linked key (2026-09-29).
 *
 *                GET /expdb/experiment/{experiment_pkey}
 *                GET /expdb/project/{project_pkey}
 *
 *              Same JSON as the website's experimental/api/get_experiment.php
 *              and get_project.php (StraboSamples edits overlaid on the
 *              sample), minus the website's edit / delete flags.
 *
 *              Readable: the caller's own, a public project's, or one holding
 *              a sample the caller can reach through StraboSamples (sample or
 *              Field project collaboration; samplesdb/lib/linked_reach.php).
 *              A project read by that last route lists only the experiments
 *              the caller may read.
 *
 * @package    StraboExperimental
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

if (!function_exists('exp_read_experiment')) {

require_once __DIR__ . '/sample_overlay.php';
require_once __DIR__ . '/../../samplesdb/lib/linked_reach.php';

/**
 * @return object|null the experiment, or null when missing or not readable
 */
function exp_read_experiment($db, $neodb, $callerPkey, $experimentPkey) {
    $experimentPkey = (int)$experimentPkey;
    if ($experimentPkey <= 0) return null;
    $row = $db->get_row_prepared("
        SELECT e.pkey, e.project_pkey, e.userpkey, e.id AS experiment_id, e.uuid, e.json,
               to_char(e.created_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY') AS created_date,
               to_char(e.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') AS modified_date,
               EXTRACT(EPOCH FROM e.created_timestamp)::integer AS created_timestamp,
               EXTRACT(EPOCH FROM e.modified_timestamp)::integer AS modified_timestamp,
               p.name AS project_name, p.ispublic AS project_is_public
          FROM straboexp.experiment e
     LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
         WHERE e.pkey = $1", array($experimentPkey));
    if (!$row || empty($row->pkey)) return null;
    $public = ($row->project_is_public === 't' || $row->project_is_public === true);
    if (!_exp_read_allowed($db, $neodb, (int)$callerPkey, (int)$row->userpkey, $public, (int)$row->pkey)) return null;

    $e = new stdClass();
    $e->pkey = (int)$row->pkey;
    $e->project_pkey = (int)$row->project_pkey;
    $e->project_name = $row->project_name;
    $e->experiment_id = $row->experiment_id;
    $e->uuid = $row->uuid;
    $e->created_date = $row->created_date;
    $e->modified_date = $row->modified_date;
    $e->created_timestamp = (int)$row->created_timestamp;
    $e->modified_timestamp = (int)$row->modified_timestamp;
    $e->owner_pkey = (int)$row->userpkey;
    $e->is_owner = ((int)$row->userpkey === (int)$callerPkey);
    $e->project_is_public = $public;
    $data = !empty($row->json) ? json_decode($row->json) : null;
    if ($data) {
        experimental_sample_overlay_apply($data, $db, (int)$row->userpkey);
        $e->data = $data;
    } else {
        $e->data = new stdClass();
    }
    return $e;
}

/**
 * @return object|null the project with its (readable) experiments, or null
 */
function exp_read_project($db, $neodb, $callerPkey, $projectPkey) {
    $projectPkey = (int)$projectPkey;
    if ($projectPkey <= 0) return null;
    $row = $db->get_row_prepared("
        SELECT p.pkey, p.userpkey, p.uuid, p.name, p.notes, p.ispublic,
               to_char(p.created_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY') AS created_date,
               to_char(p.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') AS modified_date,
               EXTRACT(EPOCH FROM p.created_timestamp)::integer AS created_timestamp,
               EXTRACT(EPOCH FROM p.modified_timestamp)::integer AS modified_timestamp
          FROM straboexp.project p
         WHERE p.pkey = $1", array($projectPkey));
    if (!$row || empty($row->pkey)) return null;
    $owner = (int)$row->userpkey;
    $public = ($row->ispublic === 't' || $row->ispublic === true);
    $whole = ($owner === (int)$callerPkey) || $public;

    $expRows = $db->get_results_prepared("
        SELECT e.pkey, e.userpkey, e.uuid, e.id AS experiment_id, e.json,
               to_char(e.modified_timestamp AT TIME ZONE 'UTC', 'Mon DD, YYYY HH24:MI') AS modified_date,
               EXTRACT(EPOCH FROM e.modified_timestamp)::integer AS modified_timestamp
          FROM straboexp.experiment e
         WHERE e.project_pkey = $1
      ORDER BY e.modified_timestamp DESC", array($projectPkey));
    $experiments = array();
    foreach ((is_array($expRows) ? $expRows : array()) as $x) {
        if (!$whole && !linked_reach_experiment($db, $neodb, (int)$callerPkey, (int)$x->pkey, (int)$x->userpkey)) continue;
        $exp = new stdClass();
        $exp->pkey = (int)$x->pkey;
        $exp->uuid = $x->uuid;
        $exp->experiment_id = $x->experiment_id;
        $exp->modified_date = $x->modified_date;
        $exp->modified_timestamp = (int)$x->modified_timestamp;
        $data = !empty($x->json) ? json_decode($x->json) : null;
        $exp->apparatus_type = ($data && isset($data->apparatus->apparatus_type)) ? $data->apparatus->apparatus_type : null;
        if ($data && isset($data->sample)) {
            experimental_sample_overlay_apply($data, $db, (int)$x->userpkey);
            if (isset($data->sample->name)) $exp->sample_name = $data->sample->name;
            if (isset($data->sample->strabo_id)) $exp->sample_strabo_id = $data->sample->strabo_id;
        }
        $experiments[] = $exp;
    }
    if (!$whole && !$experiments) return null;   // nothing here the caller may read

    $p = new stdClass();
    $p->pkey = (int)$row->pkey;
    $p->uuid = $row->uuid;
    $p->name = $row->name;
    $p->description = $row->notes;
    $p->is_public = $public;
    $p->created_date = $row->created_date;
    $p->modified_date = $row->modified_date;
    $p->created_timestamp = (int)$row->created_timestamp;
    $p->modified_timestamp = (int)$row->modified_timestamp;
    $p->owner_pkey = $owner;
    $p->is_owner = ($owner === (int)$callerPkey);
    $p->experiments = $experiments;
    return $p;
}

/** @internal */
function _exp_read_allowed($db, $neodb, $caller, $owner, $public, $experimentPkey) {
    if ($caller > 0 && $caller === $owner) return true;
    if ($public) return true;
    return linked_reach_experiment($db, $neodb, $caller, $experimentPkey, $owner);
}

} // function_exists guard
