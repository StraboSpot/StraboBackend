<?php
/**
 * File: extract_field.php
 * Description: Read StraboField :Spot nodes from Neo4j and produce the
 *              normalized source-row stream that run.php upserts into
 *              strabosamples.*. Implements the §9.1 extraction algorithm:
 *              distinguish rich sample-spots (isSample=1, authoritative
 *              object at samples[0]) from legacy inline entries, and skip
 *              parent stubs (entries whose id matches a rich sample-spot).
 *
 * @package    StraboSamples Migration
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (!function_exists('migration_extract_field')) {

/**
 * Stream Field sample source-rows.
 *
 * Pulls every (Dataset)-[:HAS_SPOT]->(Spot) pair from Neo4j and walks each
 * spot's properties.samples[] (or the sample-spot's samples[0]) to produce
 * one row per *real* sample. Stubs left behind on a parent spot's samples[]
 * by promoted rich samples are dropped.
 *
 * @param  object $neodb  StraboDbNeo4j handle
 * @param  array  $opts   { 'limit_spots' => int|null }  optional cap for testing
 * @param  callable|null $emit  called once per row; if null, rows are collected
 *                              and returned as an array
 * @return array  list of source rows (empty if $emit was supplied)
 */
function migration_extract_field($neodb, $opts = array(), $emit = null) {

    $rows = array();
    $emitRow = $emit ?: function($r) use (&$rows) { $rows[] = $r; };

    // Pull every Dataset → Spot pair in one shot. Project: dataset id +
    // userpkey, spot, plus a flag for whether this spot is itself a rich
    // sample-spot. Datasets without spots are uninteresting.
    $cypher = "MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot)
               RETURN d.id AS dataset_id, d.userpkey AS dataset_userpkey, s AS spot";
    if (!empty($opts['limit_spots'])) {
        $cypher .= " LIMIT " . (int)$opts['limit_spots'];
    }

    $records = $neodb->query($cypher);
    if (!$records) {
        return $rows;
    }

    // Pass 1 — bucket spots by dataset so the per-dataset rich/legacy
    // de-dup runs against a contained id space (matches §9.1 algorithm).
    $byDataset = array();
    foreach ($records as $rec) {
        $dsId    = (string)$rec->value('dataset_id');
        $dsOwner = (int)$rec->value('dataset_userpkey');
        $spot    = $rec->get('spot');
        $props   = $spot->values();
        $key = $dsId . ':' . $dsOwner;
        if (!isset($byDataset[$key])) {
            $byDataset[$key] = array(
                'dataset_id' => $dsId,
                'dataset_userpkey' => $dsOwner,
                'spots' => array(),
            );
        }
        $byDataset[$key]['spots'][] = $props;
    }

    // Pass 2 — per dataset, build richIds then emit rows.
    foreach ($byDataset as $bucket) {
        _migration_extract_field_dataset(
            $bucket['dataset_id'],
            $bucket['dataset_userpkey'],
            $bucket['spots'],
            $emitRow
        );
    }

    return $emit ? array() : $rows;
}

/**
 * @internal
 */
function _migration_extract_field_dataset($dsId, $dsOwner, $spots, $emit) {

    // richIds[id] = spot properties of the sample-spot
    $richIds = array();
    foreach ($spots as $p) {
        if (_migration_field_is_rich($p)) {
            $id = isset($p['id']) ? (string)$p['id'] : '';
            if ($id !== '') {
                $richIds[$id] = $p;
            }
        }
    }

    // Emit rich samples first — authoritative at samples[0].
    foreach ($richIds as $sampleId => $spotProps) {
        $samplesArr = _migration_field_decode_samples($spotProps);
        if (empty($samplesArr) || !is_array($samplesArr) || !isset($samplesArr[0])) {
            continue;  // rich spot with no payload — nothing to migrate
        }
        $obj = $samplesArr[0];
        $row = _migration_field_build_row(
            $obj, $spotProps, $dsId, $dsOwner, true
        );
        if ($row !== null) {
            $emit($row);
        }
    }

    // Emit legacy inline samples from non-rich spots, skipping stubs that
    // collide with a rich sample-spot's id.
    foreach ($spots as $p) {
        if (_migration_field_is_rich($p)) {
            continue;
        }
        $samplesArr = _migration_field_decode_samples($p);
        if (!is_array($samplesArr)) {
            continue;
        }
        foreach ($samplesArr as $entry) {
            if (!is_array($entry) && !is_object($entry)) {
                continue;
            }
            $entry = (array)$entry;
            $sampleId = isset($entry['id']) ? (string)$entry['id'] : '';
            if ($sampleId === '') {
                continue;  // headless sample — can't anchor identity
            }
            if (isset($richIds[$sampleId])) {
                continue;  // parent-side stub for a rich sample — skip
            }
            $row = _migration_field_build_row(
                $entry, $p, $dsId, $dsOwner, false
            );
            if ($row !== null) {
                $emit($row);
            }
        }
    }
}

/**
 * @internal
 */
function _migration_field_is_rich($props) {
    if (!isset($props['isSample'])) return false;
    $v = $props['isSample'];
    // Neo4j stores it as int 1 per the prod audit; tolerate bool/string variants
    return ($v === 1 || $v === true || $v === '1' || $v === 'true');
}

/**
 * @internal — decode the spot's flattened json_samples back to an array.
 */
function _migration_field_decode_samples($props) {
    if (!isset($props['json_samples'])) {
        return null;
    }
    $raw = $props['json_samples'];
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @internal — Build the unified source-row from one sample object.
 */
function _migration_field_build_row($sampleObj, $spotProps, $dsId, $dsOwner, $isRich) {
    $sampleObj = (array)$sampleObj;
    $sampleId = isset($sampleObj['id']) ? (string)$sampleObj['id'] : '';
    if ($sampleId === '') {
        return null;
    }
    $userpkey = (int)$spotProps['userpkey'];

    // spine columns — Field key-name mapping (design §9.1 writeback table)
    $name        = isset($sampleObj['sample_id_name'])     ? (string)$sampleObj['sample_id_name']     : null;
    $igsn        = isset($sampleObj['Sample_IGSN'])         ? (string)$sampleObj['Sample_IGSN']         : null;
    $description = isset($sampleObj['sample_description']) ? (string)$sampleObj['sample_description'] : null;
    $notes       = isset($sampleObj['sample_notes'])       ? (string)$sampleObj['sample_notes']       : null;
    $material    = isset($sampleObj['material_type'])      ? (string)$sampleObj['material_type']      : null;
    $purpose     = isset($sampleObj['main_sampling_purpose']) ? (string)$sampleObj['main_sampling_purpose'] : null;
    // Field "other_material_type" fall-through when material_type === 'other'
    if ($material === 'other' && !empty($sampleObj['other_material_type'])) {
        $material = (string)$sampleObj['other_material_type'];
    }

    list($lat, $lng) = _migration_field_extract_geom($spotProps);

    // Timestamps — Neo4j spots carry a flattened modified_timestamp (ms epoch
    // on most rows). Use it for both created_at and modified_at; createdBy
    // falls back to the spot's own userpkey since old spots predate the
    // collaboration created_by column.
    $modTs = null;
    if (isset($spotProps['modified_timestamp'])) {
        $modTs = _migration_normalize_epoch($spotProps['modified_timestamp']);
    }
    $createdBy = $userpkey;
    if (isset($spotProps['created_by']) && (int)$spotProps['created_by'] > 0) {
        $createdBy = (int)$spotProps['created_by'];
    }

    return array(
        'source'                 => 'field',
        'sample_id'              => $sampleId,
        'sample_userpkey'        => $userpkey,
        'name'                   => $name,
        'igsn'                   => $igsn,
        'description'            => $description,
        'notes'                  => $notes,
        'latitude'               => $lat,
        'longitude'              => $lng,
        'display_sample_type'    => $material,
        'display_sample_purpose' => $purpose,
        'created_at'             => $modTs,
        'modified_at'            => $modTs,
        'created_by'             => $createdBy,
        'subsystem_data'         => $sampleObj,  // full sample sub-object → field_data JSONB
        'reference_id'           => (string)$spotProps['id'],
        'reference_userpkey'     => $userpkey,
        'reference_metadata'     => array(
            'dataset_id' => (string)$dsId,
            'rich'       => (bool)$isRich,
        ),
        'composition'            => array(),
        'parameters'             => array(),
        'documents'              => array(),
        'source_label'           => 'spot ' . $spotProps['id'] . ($isRich ? ' (rich)' : ' (legacy)'),
    );
}

/**
 * @internal — pull lat/lng from spot.geometry only when it's a Point.
 * Non-Point spots leave lat/lng NULL; the writeback rules treat lat/lng
 * as read-only on the StraboSamples side for Field-linked samples anyway.
 */
function _migration_field_extract_geom($spotProps) {
    if (!isset($spotProps['geometry'])) {
        return array(null, null);
    }
    $g = $spotProps['geometry'];
    if (is_string($g)) {
        $g = json_decode($g, true);
    } else if (is_object($g)) {
        $g = json_decode(json_encode($g), true);
    }
    if (!is_array($g) || !isset($g['type'], $g['coordinates'])) {
        return array(null, null);
    }
    if ($g['type'] !== 'Point') {
        return array(null, null);
    }
    $c = $g['coordinates'];
    if (!is_array($c) || !isset($c[0], $c[1])) {
        return array(null, null);
    }
    return array((float)$c[1], (float)$c[0]);  // GeoJSON is [lng, lat]
}

/**
 * @internal — accept ms-epoch / s-epoch / ISO 8601 strings; return unix
 * seconds, or null if unparsable.
 */
function _migration_normalize_epoch($v) {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n > 1e12) {
            return (int)floor($n / 1000.0);  // ms → s
        }
        return (int)$n;
    }
    $t = strtotime((string)$v);
    return $t === false ? null : $t;
}

}
