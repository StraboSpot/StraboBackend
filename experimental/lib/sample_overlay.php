<?php
/**
 * File: experimental/lib/sample_overlay.php
 * Description: Read-through helper for the Experimental download paths.
 *              At download time, overlays the strabosamples.* spine onto
 *              the experiment.json embedded sample block so Samples-app
 *              spine edits surface in every Experimental export — JSON
 *              and PDF, single-experiment and project-level.
 *
 *              experiment.json shape (relevant subset):
 *                {
 *                  sample: {
 *                    strabo_id,
 *                    name,
 *                    igsn,
 *                    description,
 *                    material: {
 *                      material: { type, name, state, note },
 *                      provenance: {
 *                        location: { latitude, longitude, ... },
 *                        ...
 *                      },
 *                      ...
 *                    },
 *                    ...
 *                  },
 *                  ...
 *                }
 *
 *              Spine → JSON path mapping (REVERSE of
 *              experimental/lib/sample_sync.php's forward direction):
 *                  name                → sample.name
 *                  igsn                → sample.igsn
 *                  description         → sample.description
 *                  latitude            → sample.material.provenance.location.latitude
 *                  longitude           → sample.material.provenance.location.longitude
 *                  display_sample_type → sample.material.material.type
 *
 *              Two spine columns have no Experimental analogue and are
 *              intentionally NOT overlaid:
 *                  notes                  — sample object has no notes field
 *                  display_sample_purpose — Experimental has no sampling purpose
 *
 *              v1 limitations:
 *                  - Spine NULL is treated as "no edit" — only overlay
 *                    when the spine value is non-null. Conservative.
 *                  - Nested paths (material.provenance.location, etc.) are
 *                    created on overlay if missing. The Experimental
 *                    client expects a stable shape; defensive helper
 *                    here keeps that intact even when the source JSON
 *                    omits intermediate branches.
 *
 *              The single-experiment helper takes (experiment_data, db,
 *              ownerPkey); the batch variant takes a list of (experiment_data,
 *              ownerPkey) tuples and does ONE strabosamples query for all
 *              of them. Project-level downloads can mix experiments from
 *              different owners (per-experiment userpkey) so the batch
 *              key is (strabo_id, ownerPkey).
 */

if (!function_exists('experimental_sample_overlay_apply')) {

    /**
     * Apply spine overlay to a single decoded experiment JSON object.
     * Mutates in place; returns same reference for chaining.
     *
     * @param object $expData    decoded experiment.json (stdClass)
     * @param object $db         ezSQL-style PG handle
     * @param int    $ownerPkey  the spine row's userpkey (typically the
     *                           experiment's owner)
     * @return object            same $expData reference
     */
    function experimental_sample_overlay_apply($expData, $db, $ownerPkey)
    {
        if (!is_object($expData) || !isset($expData->sample) || !is_object($expData->sample)) {
            return $expData;
        }
        $sid = isset($expData->sample->strabo_id) ? (string)$expData->sample->strabo_id : '';
        if ($sid === '') return $expData;

        $row = $db->get_row_prepared(
            "SELECT name, igsn, description, latitude, longitude,
                    display_sample_type
               FROM strabosamples.samples
              WHERE id = $1 AND userpkey = $2 LIMIT 1",
            array($sid, (int)$ownerPkey)
        );
        if (!$row) return $expData;

        experimental_sample_overlay_apply_one($expData->sample, $row);
        return $expData;
    }

    /**
     * Apply spine overlay across multiple experiments in a single batched
     * lookup. Each tuple is array($expData, $ownerPkey) — callers that
     * iterate experiments from a project should build the tuples once
     * and pass them in.
     *
     * @param array  $tuples  array of array($expData_obj, $ownerPkey_int)
     * @param object $db      ezSQL-style PG handle
     * @return void           mutates each $expData in place
     */
    function experimental_sample_overlay_apply_batch($tuples, $db)
    {
        if (empty($tuples)) return;

        // Collect (strabo_id, ownerPkey) pairs that have a real sample
        // block. Build an index: ownerPkey → list of strabo_ids.
        $byOwner = array();      // ownerPkey => array of strabo_ids
        $tupleByKey = array();   // "$sid|$owner" => list of $expData refs
        foreach ($tuples as $t) {
            if (!is_array($t) || count($t) < 2) continue;
            $expData   = $t[0];
            $ownerPkey = (int)$t[1];
            if (!is_object($expData) || !isset($expData->sample) || !is_object($expData->sample)) continue;
            $sid = isset($expData->sample->strabo_id) ? (string)$expData->sample->strabo_id : '';
            if ($sid === '') continue;

            if (!isset($byOwner[$ownerPkey])) $byOwner[$ownerPkey] = array();
            $byOwner[$ownerPkey][$sid] = true;
            $k = $sid . '|' . $ownerPkey;
            if (!isset($tupleByKey[$k])) $tupleByKey[$k] = array();
            $tupleByKey[$k][] = $expData;
        }
        if (empty($byOwner)) return;

        // One query per owner — N owners usually = 1 (typical project).
        // Inside the per-owner query, batch the strabo_id list as IN(...).
        foreach ($byOwner as $ownerPkey => $sidsMap) {
            $sids = array_keys($sidsMap);
            $placeholders = array();
            $params = array((int)$ownerPkey);
            foreach ($sids as $i => $s) {
                $params[] = (string)$s;
                $placeholders[] = '$' . ($i + 2);
            }
            $sql = "SELECT id, name, igsn, description, latitude, longitude,
                           display_sample_type
                      FROM strabosamples.samples
                     WHERE userpkey = \$1 AND id IN (" . implode(',', $placeholders) . ")";
            $rows = $db->get_results_prepared($sql, $params);
            if (!is_array($rows)) continue;
            foreach ($rows as $r) {
                $k = (string)$r->id . '|' . (int)$ownerPkey;
                if (!isset($tupleByKey[$k])) continue;
                foreach ($tupleByKey[$k] as $expData) {
                    experimental_sample_overlay_apply_one($expData->sample, $r);
                }
            }
        }
    }

    /**
     * Apply a single strabosamples spine row onto a single sample block.
     * Mutates $sample in place. Only overlays when the spine value is
     * non-null; null is treated as "no edit" (conservative).
     *
     * @internal
     */
    function experimental_sample_overlay_apply_one($sample, $spine)
    {
        if (!is_object($sample) || !is_object($spine)) return;

        if ($spine->name        !== null) $sample->name        = $spine->name;
        if ($spine->igsn        !== null) $sample->igsn        = $spine->igsn;
        if ($spine->description !== null) $sample->description = $spine->description;

        // Nested: sample.material.material.type
        if ($spine->display_sample_type !== null) {
            if (!isset($sample->material) || !is_object($sample->material))                       $sample->material           = new stdClass();
            if (!isset($sample->material->material) || !is_object($sample->material->material))   $sample->material->material = new stdClass();
            $sample->material->material->type = (string)$spine->display_sample_type;
        }

        // Nested: sample.material.provenance.location.{latitude,longitude}
        if ($spine->latitude !== null || $spine->longitude !== null) {
            if (!isset($sample->material) || !is_object($sample->material))                                                 $sample->material                       = new stdClass();
            if (!isset($sample->material->provenance) || !is_object($sample->material->provenance))                         $sample->material->provenance           = new stdClass();
            if (!isset($sample->material->provenance->location) || !is_object($sample->material->provenance->location))     $sample->material->provenance->location = new stdClass();
            if ($spine->latitude  !== null) $sample->material->provenance->location->latitude  = (string)$spine->latitude;
            if ($spine->longitude !== null) $sample->material->provenance->location->longitude = (string)$spine->longitude;
        }
    }

/**
 * Absolute URL of the sample's StraboSamples detail page
 * (/samples/{owner}/{id}, the .htaccess pretty form of samples_detail.php).
 * Used by the PDF export, where a relative URL is useless. Host comes from
 * the serving request so dev PDFs link to dev; falls back to production
 * for CLI/backfill contexts.
 */
function experimental_samples_detail_url($ownerPkey, $straboId) {
    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'strabospot.org';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($host === 'strabospot.org') $scheme = 'https';
    return $scheme . '://' . $host . '/samples/' . (int)$ownerPkey . '/' . rawurlencode((string)$straboId);
}

}
