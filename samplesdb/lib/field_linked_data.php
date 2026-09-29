<?php
/**
 * File: samplesdb/lib/field_linked_data.php
 * Description: The read-only `strabosamples_linked` key on Field samples
 *              (requested by the StraboField app developers, decided with
 *              Jason 2026-09-29).
 *
 *              DOWNLOAD: GET /datasetSpots/{id} (the app's only spot
 *              download, /db/ and /jwtdb/) adds the key to every sample that
 *              StraboMicro or StraboExperimental also uses. It carries those
 *              apps' data for the sample; Field data is not repeated.
 *              Samples used only in StraboField get no key.
 *
 *              StraboMicro sends only where the sample is used: its sample
 *              fields are Field's sample form under other names (the old
 *              copy-from-Field link) plus bookkeeping ids, and the Field
 *              values win on upload, so they add nothing (Jason 2026-09-29).
 *              Null and empty-string values are left out everywhere; lists
 *              are always present, empty or not.
 *
 *              Ids are the ones each app's own API takes, so the Field app
 *              can fetch more (Jason: that is what the app devs are after):
 *              Micro project_id / dataset_id = the Micro strabo_id strings
 *              (/microdb/webProject/{id}, /microdb/projectPDF/{id}; never the
 *              internal row numbers); Experimental project_pkey /
 *              experiment_pkey = the ?id= of experimental/api/get_project.php,
 *              get_experiment.php and the download endpoints. experiment_id
 *              is the ID the user types in StraboExperimental.
 *
 *                "strabosamples_linked": {
 *                  "id": "<StraboSamples id>", "owner": <owner pkey>,
 *                  "micro": {                          (only when linked)
 *                    "projects": [ {project_id, project_name, dataset_id,
 *                                   dataset_name, micrograph_count} ] },
 *                  "experimental": {                   (only when linked)
 *                    "experiments": [ {project_pkey, project_name,
 *                                      experiment_pkey, experiment_id,
 *                                      experiment_uuid} ],
 *                    "data": { ...the sample's StraboExperimental fields... },
 *                    "composition": [...], "parameters": [...],
 *                    "documents": [...] } }
 *
 *              UPLOAD: the server owns this key. insertSpot() (every Field
 *              spot write: app uploads, single-spot edits, moves, shapefile
 *              import, Template Wizard, version restore) drops it before
 *              anything is stored or synced, whatever the app sends back.
 *              The download always rebuilds it from scratch.
 *
 *              Hooked in the controller, never inside getDatasetSpots():
 *              version snapshots are built through the same class methods
 *              and restore into Neo4j.
 *
 * @package    StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 */

if (!function_exists('field_linked_attach')) {

require_once __DIR__ . '/field_identity.php';

/** Key on the Field sample object; server-written, ignored on upload. */
define('FIELD_SAMPLE_LINKED_KEY', 'strabosamples_linked');

/**
 * Remove the key from a spot's properties before it is stored: from every
 * samples[] entry, and from the spot itself in case a client moved it there
 * (insertSpot stores unknown spot-level keys as-is).
 *
 * @param object|array $properties spot properties (modified in place)
 * @return int number of copies removed
 */
function field_linked_strip_properties(&$properties) {
    $removed = 0;
    if (is_object($properties)) {
        if (property_exists($properties, FIELD_SAMPLE_LINKED_KEY)) {
            unset($properties->{FIELD_SAMPLE_LINKED_KEY});
            $removed++;
        }
        if (isset($properties->samples) && is_array($properties->samples)) {
            foreach ($properties->samples as $i => $entry) {
                $removed += _field_linked_strip_entry($properties->samples[$i]);
            }
        }
    } elseif (is_array($properties)) {
        if (array_key_exists(FIELD_SAMPLE_LINKED_KEY, $properties)) {
            unset($properties[FIELD_SAMPLE_LINKED_KEY]);
            $removed++;
        }
        if (isset($properties['samples']) && is_array($properties['samples'])) {
            foreach ($properties['samples'] as $i => $entry) {
                $removed += _field_linked_strip_entry($properties['samples'][$i]);
            }
        }
    }
    return $removed;
}

/** @internal */
function _field_linked_strip_entry(&$entry) {
    if (is_object($entry) && property_exists($entry, FIELD_SAMPLE_LINKED_KEY)) {
        unset($entry->{FIELD_SAMPLE_LINKED_KEY});
        return 1;
    }
    if (is_array($entry) && array_key_exists(FIELD_SAMPLE_LINKED_KEY, $entry)) {
        unset($entry[FIELD_SAMPLE_LINKED_KEY]);
        return 1;
    }
    return 0;
}

/**
 * A parent's {id}-only stub for a rich sample (same shape rule as
 * _field_sample_sync_is_stub_entry, without the graph probe). The key goes
 * on the rich sample-spot's own entry, never on stubs.
 * @internal
 */
function _field_linked_is_stub($entry) {
    foreach ((array)$entry as $k => $v) {
        if ($k === 'id' || $k === FIELD_SAMPLE_LINKING_KEY || $k === FIELD_SAMPLE_LINKED_KEY) continue;
        if (is_array($v) || is_object($v)) {
            if (count((array)$v) > 0) return false;
            continue;
        }
        if ($v !== null && $v !== '') return false;
    }
    return true;
}

/** @internal */
function _field_linked_is_rich($props) {
    $v = isset($props['isSample']) ? $props['isSample'] : null;
    return ($v === 1 || $v === true || $v === '1' || $v === 'true');
}

/**
 * Add the key to the samples of a downloaded feature collection. Replaces
 * any copy already present (never merges). Never throws: a failure leaves
 * the download without the key rather than failing it.
 *
 * @param object $db         StraboDbPostgreSQL handle
 * @param array  $collection getDatasetSpots() result (modified in place)
 * @param int    $ownerPkey  owner of the dataset (= owner of its samples)
 * @return int number of samples that received the key
 */
function field_linked_attach($db, &$collection, $ownerPkey) {
    try {
        return _field_linked_attach($db, $collection, (int)$ownerPkey);
    } catch (\Throwable $e) {
        error_log('field_linked_attach: ' . $e->getMessage());
        return 0;
    }
}

/** @internal */
function _field_linked_attach($db, &$collection, $ownerPkey) {
    if ($ownerPkey <= 0 || !is_array($collection) || empty($collection['features']) || !is_array($collection['features'])) {
        return 0;
    }
    $features =& $collection['features'];

    // Rich sample-spot ids in this download: a legacy entry with the same
    // id is a stale copy of that sample, so it gets no key of its own.
    $richIds = array();
    foreach ($features as $f) {
        if (isset($f['properties']) && is_array($f['properties']) && _field_linked_is_rich($f['properties'])
            && isset($f['properties']['id'])) {
            $richIds[(string)$f['properties']['id']] = true;
        }
    }

    // Which entries could carry the key, and their StraboSamples identity.
    $targets = array();    // list of array(featureIndex, sampleIndex, identity)
    foreach ($features as $fi => $f) {
        if (!isset($f['properties']['samples']) || !is_array($f['properties']['samples'])) continue;
        $rich = _field_linked_is_rich($f['properties']);
        foreach ($f['properties']['samples'] as $si => $entry) {
            // Rebuilt every time, never merged with what is stored.
            _field_linked_strip_entry($features[$fi]['properties']['samples'][$si]);
            if ($rich && $si !== 0) continue;
            if (!is_object($entry) && !is_array($entry)) continue;
            if (_field_linked_is_stub($entry)) continue;
            $localId = field_sample_local_id($entry);
            if ($localId === '') continue;
            if (!$rich && isset($richIds[$localId])) continue;
            $identity = field_sample_identity($entry, $db, $ownerPkey);
            if ($identity === '') continue;
            $targets[] = array($fi, $si, $identity);
        }
    }
    if (!$targets) return 0;

    $ids = array();
    foreach ($targets as $t) $ids[$t[2]] = true;
    $idsJson = json_encode(array_map('strval', array_keys($ids)));

    // Micro / Experimental links of these samples (one query).
    $linkRows = $db->get_results_prepared(
        "SELECT sample_id, subsystem, reference_id, reference_metadata::text AS rm
           FROM strabosamples.sample_subsystem_links
          WHERE sample_userpkey = $1
            AND subsystem IN ('micro', 'experimental')
            AND sample_id IN (SELECT jsonb_array_elements_text($2::jsonb))
          ORDER BY sample_id, subsystem, reference_id",
        array($ownerPkey, $idsJson)
    );
    if (!is_array($linkRows) || !$linkRows) return 0;

    $links = array();          // identity => ['micro' => [...], 'experimental' => [...]]
    $microProjectIds = array();
    $microDatasetIds = array();
    $expUuids = array();
    foreach ($linkRows as $r) {
        $meta = json_decode((string)$r->rm, true);
        if (!is_array($meta)) $meta = array();
        $links[$r->sample_id][$r->subsystem][] = array('ref' => (string)$r->reference_id, 'meta' => $meta);
        if ($r->subsystem === 'micro') {
            if (ctype_digit((string)$r->reference_id)) $microProjectIds[(int)$r->reference_id] = true;
            if (isset($meta['dataset_id']) && ctype_digit((string)$meta['dataset_id'])) $microDatasetIds[(int)$meta['dataset_id']] = true;
        } elseif (!empty($meta['experiment_uuid'])) {
            $expUuids[(string)$meta['experiment_uuid']] = true;
        }
    }
    // Experimental fields + sub-arrays, for samples used in StraboExperimental.
    $expData = array();
    $sub = array('composition' => array(), 'parameters' => array(), 'documents' => array());
    $expIds = array();
    foreach ($links as $id => $l) if (!empty($l['experimental'])) $expIds[] = (string)$id;
    if ($expIds) {
        $expJson = json_encode($expIds);
        $rows = $db->get_results_prepared(
            "SELECT id, experimental_data::text AS ed
               FROM strabosamples.samples
              WHERE userpkey = $1 AND id IN (SELECT jsonb_array_elements_text($2::jsonb))",
            array($ownerPkey, $expJson)
        );
        foreach ((is_array($rows) ? $rows : array()) as $r) $expData[$r->id] = _field_linked_clean($r->ed);
        $subSql = array(
            'composition' => "SELECT sample_id, mineral, other_mineral, fraction, unit, grainsize, ordering
                                FROM strabosamples.sample_composition",
            'parameters'  => "SELECT sample_id, control, other_control, value, unit, prefix, note, ordering
                                FROM strabosamples.sample_parameters",
            'documents'   => "SELECT sample_id, uuid, type, other_type, format, other_format, path,
                                     document_id, original_filename, description, ordering
                                FROM strabosamples.sample_documents",
        );
        foreach ($subSql as $name => $select) {
            $rows = $db->get_results_prepared(
                $select . " WHERE sample_userpkey = $1
                              AND sample_id IN (SELECT jsonb_array_elements_text($2::jsonb))
                            ORDER BY sample_id, ordering, pkey",
                array($ownerPkey, $expJson)
            );
            foreach ((is_array($rows) ? $rows : array()) as $r) {
                $item = (array)$r;
                $sid = $item['sample_id'];
                unset($item['sample_id']);
                if (isset($item['ordering'])) $item['ordering'] = (int)$item['ordering'];
                $sub[$name][$sid][] = $item;
            }
        }
    }

    // Host names. Micro links point at the project row itself (reference_id
    // = micro_projectmetadata.id), so no strabo_id ambiguity.
    $microProjects = array();
    if ($microProjectIds) {
        $rows = $db->get_results_prepared(
            "SELECT id, strabo_id, name FROM micro_projectmetadata WHERE id = ANY($1::int[])",
            array('{' . implode(',', array_keys($microProjectIds)) . '}')
        );
        foreach ((is_array($rows) ? $rows : array()) as $r) $microProjects[(int)$r->id] = $r;
    }
    $microDatasets = array();
    if ($microDatasetIds) {
        $rows = $db->get_results_prepared(
            "SELECT id, strabo_id, name FROM micro_datasetmetadata WHERE id = ANY($1::int[])",
            array('{' . implode(',', array_keys($microDatasetIds)) . '}')
        );
        foreach ((is_array($rows) ? $rows : array()) as $r) $microDatasets[(int)$r->id] = $r;
    }
    $experiments = array();
    if ($expUuids) {
        $rows = $db->get_results_prepared(
            "SELECT e.uuid, e.pkey AS experiment_pkey, e.id AS experiment_id,
                    p.pkey AS project_pkey, p.name AS project_name
               FROM straboexp.experiment e
          LEFT JOIN straboexp.project p ON p.pkey = e.project_pkey
              WHERE e.uuid IN (SELECT jsonb_array_elements_text($1::jsonb))",
            array(json_encode(array_keys($expUuids)))
        );
        foreach ((is_array($rows) ? $rows : array()) as $r) $experiments[(string)$r->uuid] = $r;
    }

    // Build one value per linked sample, then attach.
    $values = array();
    foreach ($links as $id => $l) {
        $v = array('id' => (string)$id, 'owner' => $ownerPkey);
        if (!empty($l['micro'])) {
            $projects = array();
            foreach ($l['micro'] as $m) {
                // Internal row numbers only find the rows; the app gets Micro's own ids.
                $pr = ctype_digit($m['ref']) && isset($microProjects[(int)$m['ref']]) ? $microProjects[(int)$m['ref']] : null;
                $dk = isset($m['meta']['dataset_id']) && ctype_digit((string)$m['meta']['dataset_id']) ? (int)$m['meta']['dataset_id'] : null;
                $dr = ($dk !== null && isset($microDatasets[$dk])) ? $microDatasets[$dk] : null;
                if ($pr === null) continue;   // project row gone: nothing the app could fetch
                $projects[] = array(
                    'project_id'       => (string)$pr->strabo_id,
                    'project_name'     => $pr->name,
                    'dataset_id'       => $dr ? (string)$dr->strabo_id : null,
                    'dataset_name'     => $dr ? $dr->name : null,
                    'micrograph_count' => isset($m['meta']['micrograph_count']) ? (int)$m['meta']['micrograph_count'] : 0,
                );
            }
            if ($projects) $v['micro'] = array('projects' => $projects);
        }
        if (!empty($l['experimental'])) {
            $exps = array();
            foreach ($l['experimental'] as $x) {
                $uuid = isset($x['meta']['experiment_uuid']) ? (string)$x['meta']['experiment_uuid'] : '';
                $e = ($uuid !== '' && isset($experiments[$uuid])) ? $experiments[$uuid] : null;
                if ($e === null) continue;   // experiment gone: nothing the app could fetch
                $exps[] = array(
                    'project_pkey'    => $e->project_pkey !== null ? (int)$e->project_pkey : null,
                    'project_name'    => $e->project_name,
                    'experiment_pkey' => (int)$e->experiment_pkey,
                    'experiment_id'   => $e->experiment_id,
                    'experiment_uuid' => $uuid,
                );
            }
            if ($exps) $v['experimental'] = array(
                'experiments' => $exps,
                'data'        => isset($expData[$id]) ? $expData[$id] : null,
                'composition' => isset($sub['composition'][$id]) ? $sub['composition'][$id] : array(),
                'parameters'  => isset($sub['parameters'][$id]) ? $sub['parameters'][$id] : array(),
                'documents'   => isset($sub['documents'][$id]) ? $sub['documents'][$id] : array(),
            );
        }
        if (isset($v['micro']) || isset($v['experimental'])) $values[$id] = _field_linked_prune($v);
    }

    $attached = 0;
    foreach ($targets as $t) {
        list($fi, $si, $identity) = $t;
        if (!isset($values[$identity])) continue;
        $value = json_decode(json_encode($values[$identity]));   // objects, like the rest of the entry
        if (is_object($features[$fi]['properties']['samples'][$si])) {
            $features[$fi]['properties']['samples'][$si]->{FIELD_SAMPLE_LINKED_KEY} = $value;
        } else {
            $features[$fi]['properties']['samples'][$si][FIELD_SAMPLE_LINKED_KEY] = $value;
        }
        $attached++;
    }
    return $attached;
}

/**
 * Leave out null and empty-string values, and objects left empty by that,
 * at every level. Lists stay, even empty, so clients can always loop.
 * @internal
 */
function _field_linked_prune($v) {
    if (!is_array($v)) return $v;
    $isList = ($v === array() || array_keys($v) === range(0, count($v) - 1));
    $out = array();
    foreach ($v as $k => $x) {
        $x = _field_linked_prune($x);
        if ($isList) { $out[] = $x; continue; }
        if ($x === null || $x === '' || $x === array() && !_field_linked_is_list_key($k)) continue;
        $out[$k] = $x;
    }
    return $out;
}

/** Keys whose value is a list and stays even when empty. @internal */
function _field_linked_is_list_key($k) {
    return in_array($k, array('projects', 'experiments', 'composition', 'parameters', 'documents'), true);
}

/**
 * Decode a JSONB slice for output, dropping internal keys (a leading
 * underscore, e.g. Experimental's raw `_sample_json` copy).
 * @internal
 */
function _field_linked_clean($json) {
    if ($json === null || $json === '') return null;
    $d = json_decode($json, true);
    if (!is_array($d)) return null;
    foreach (array_keys($d) as $k) {
        if (is_string($k) && $k !== '' && $k[0] === '_') unset($d[$k]);
    }
    return $d ?: null;
}

} // function_exists guard
