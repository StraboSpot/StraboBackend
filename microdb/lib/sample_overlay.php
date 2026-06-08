<?php
/**
 * File: microdb/lib/sample_overlay.php
 * Description: Read-through helper for Micro download paths. At download
 *              time, overlays the strabosamples.* spine onto the
 *              upload-time project.json so that Samples-app spine edits
 *              show up in every Micro export — without rebuilding the JSON
 *              from PG tables and without write-amplification.
 *
 *              The Micro `project.json` is shaped:
 *                {
 *                  datasets: [
 *                    {
 *                      samples: [
 *                        { id, sampleID, sampleDescription, sampleNotes,
 *                          latitude, longitude, materialType,
 *                          mainSamplingPurpose, ... }
 *                      ]
 *                    }
 *                  ]
 *                }
 *
 *              Spine → JSON key mapping (mirrors micro_sample_sync's
 *              forward direction in microdb/lib/sample_sync.php):
 *                  name                   → sampleID
 *                  description            → sampleDescription
 *                  notes                  → sampleNotes
 *                  latitude / longitude   → latitude / longitude
 *                  display_sample_type    → materialType (verbatim, see v1 note)
 *                  display_sample_purpose → mainSamplingPurpose (verbatim)
 *
 *              v1 limitations:
 *                  - Spine values are written verbatim to materialType /
 *                    mainSamplingPurpose. The Micro `other` indirection
 *                    (materialType='other' + otherMaterialType=<value>)
 *                    is NOT reverse-mapped; if the spine carries a free-text
 *                    value like "Soil" the desktop client sees that string
 *                    in materialType. Matches the Field writeback v1.
 *                  - Spine NULL is treated as "no edit" — we only overlay
 *                    when the spine value is non-null, so legitimate
 *                    user-cleared fields don't propagate yet. Conservative
 *                    by design.
 *
 *              Caller responsibility:
 *                  Decode the project.json before calling; this helper
 *                  mutates the passed object graph in place (and returns
 *                  it for chaining).
 */

if (!function_exists('micro_sample_overlay_apply')) {

    /**
     * Overlay strabosamples spine onto a decoded Micro project.json.
     *
     * @param object $projectJson  decoded JSON object (mutated in place)
     * @param object $db           ezSQL-style PG handle (uses get_results_prepared)
     * @param int    $ownerPkey    project owner pkey (used to scope spine lookup)
     * @return object              same $projectJson reference, with overlay applied
     */
    function micro_sample_overlay_apply($projectJson, $db, $ownerPkey)
    {
        if (!is_object($projectJson)) return $projectJson;
        if (!isset($projectJson->datasets)) return $projectJson;
        // Normalize: datasets can be array or stdClass-keyed-by-id.
        $datasets = is_array($projectJson->datasets)
            ? $projectJson->datasets
            : (array)$projectJson->datasets;

        // Collect sample ids across all datasets so we can issue one batch
        // lookup against strabosamples.samples.
        $sampleIds = array();
        foreach ($datasets as $d) {
            if (!is_object($d) || !isset($d->samples)) continue;
            $samples = is_array($d->samples) ? $d->samples : (array)$d->samples;
            foreach ($samples as $s) {
                if (is_object($s) && isset($s->id) && $s->id !== '') {
                    $sampleIds[(string)$s->id] = true;
                }
            }
        }
        if (empty($sampleIds)) return $projectJson;

        // Single batch query — avoids N+1.
        $ids = array_keys($sampleIds);
        $placeholders = array();
        $params = array((int)$ownerPkey);
        foreach ($ids as $i => $sid) {
            $params[] = (string)$sid;
            $placeholders[] = '$' . ($i + 2);
        }
        $sql = "SELECT id, name, description, notes, latitude, longitude,
                       display_sample_type, display_sample_purpose
                  FROM strabosamples.samples
                 WHERE userpkey = \$1 AND id IN (" . implode(',', $placeholders) . ")";
        $rows = $db->get_results_prepared($sql, $params);
        if (!is_array($rows) || empty($rows)) return $projectJson;

        $spineById = array();
        foreach ($rows as $r) $spineById[(string)$r->id] = $r;

        // Apply overlay. Walk datasets again, mutate sample objects in place.
        foreach ($projectJson->datasets as $d) {
            if (!is_object($d) || !isset($d->samples)) continue;
            $samples = is_array($d->samples) ? $d->samples : (array)$d->samples;
            foreach ($samples as $s) {
                if (!is_object($s) || !isset($s->id)) continue;
                $sid = (string)$s->id;
                if (!isset($spineById[$sid])) continue;
                $sp = $spineById[$sid];

                if ($sp->name                   !== null) $s->sampleID            = $sp->name;
                if ($sp->description            !== null) $s->sampleDescription   = $sp->description;
                if ($sp->notes                  !== null) $s->sampleNotes         = $sp->notes;
                if ($sp->latitude               !== null) $s->latitude            = (float)$sp->latitude;
                if ($sp->longitude              !== null) $s->longitude           = (float)$sp->longitude;
                if ($sp->display_sample_type    !== null) $s->materialType        = $sp->display_sample_type;
                if ($sp->display_sample_purpose !== null) $s->mainSamplingPurpose = $sp->display_sample_purpose;
            }
        }

        return $projectJson;
    }

    /**
     * Convenience wrapper: read project.json from $srcPath, apply overlay,
     * write to $destPath. Used by getProjectPDF / getWebProject / etc.
     * Returns true on success, false if the source JSON couldn't be parsed
     * (in which case the destination is filled with a verbatim copy so the
     * download still succeeds with stale data — never break the download).
     *
     * If $modifiedTimestamp is passed, the JSON is also patched with
     * modifiedtimestamp (epoch ms). Matches the existing getProjectPDF
     * behavior.
     */
    function micro_sample_overlay_write_json($db, $srcPath, $destPath, $ownerPkey, $modifiedTimestamp = null)
    {
        if (!file_exists($srcPath)) return false;
        $raw = file_get_contents($srcPath);
        $json = json_decode($raw);
        if ($json === null) {
            // Malformed source JSON — copy verbatim so the download still
            // succeeds. The user gets stale (but legible) data, no 500.
            @copy($srcPath, $destPath);
            return false;
        }
        micro_sample_overlay_apply($json, $db, $ownerPkey);
        if ($modifiedTimestamp !== null) {
            if (isset($json->modifiedTimestamp)) unset($json->modifiedTimestamp);
            $json->modifiedtimestamp = (int)$modifiedTimestamp;
        }
        file_put_contents($destPath, json_encode($json, JSON_PRETTY_PRINT));
        return true;
    }

}
