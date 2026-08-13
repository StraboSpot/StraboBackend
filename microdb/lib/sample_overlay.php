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
 *                  name                   → sampleID + label + name
 *                                           (the StraboMicro2 new/edit sample
 *                                           modal writes its single "Sample ID"
 *                                           field to all three, so the overlay
 *                                           reproduces an in-app edit; viewers
 *                                           display label, the desktop app and
 *                                           server PDF key on sampleID)
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

                if ($sp->name !== null) {
                    // Triple-write mirrors the StraboMicro2 sample modal
                    // (NewSampleDialog/EditSampleDialog write one value to
                    // name, label, and sampleID). Viewers render label,
                    // the desktop app and server PDF prefer sampleID.
                    $s->sampleID = $sp->name;
                    $s->label    = $sp->name;
                    $s->name     = $sp->name;
                }
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
     * Overlay the strabosamples spine onto the project.json *inside* an
     * uploaded Micro .smz/zip, preserving the zip's exact structure so the
     * StraboMicro desktop client can still re-import it. Used by the website
     * .smz download endpoint (download_micro_file.php).
     *
     * The uploaded zip is shaped {desktop-uuid}/project.json + tiles/images
     * and can be very large (hundreds of MB). To stay cheap:
     *   - We read ONLY the small project.json entry (ZipArchive seeks via the
     *     central directory — no full scan).
     *   - If the overlay changes nothing (project has no Samples-app spine
     *     edits — the common case), we return the ORIGINAL path untouched, so
     *     a plain readfile streams it with zero rewrite.
     *   - Only when the overlay actually changes the JSON do we copy the zip
     *     to a temp file and replace that single entry. The copy lives on the
     *     same filesystem under /ziptemp.
     *
     * Never throws and never breaks the download: on any failure (missing
     * zip, no project.json entry, parse error, ZipArchive failure) it returns
     * $srcZipPath so the caller streams the original, stale-but-valid file.
     *
     * @return string  path to stream (either $srcZipPath or a temp overlaid zip)
     */
    function micro_overlay_smz($db, $srcZipPath, $ownerPkey)
    {
        if (!class_exists('ZipArchive')) return $srcZipPath;
        if (!is_string($srcZipPath) || !file_exists($srcZipPath)) return $srcZipPath;

        $za = new ZipArchive();
        if ($za->open($srcZipPath) !== true) return $srcZipPath;

        // Find the top-level project.json entry (shallowest path).
        $entry = null; $entryDepth = PHP_INT_MAX;
        for ($i = 0; $i < $za->numFiles; $i++) {
            $name = $za->getNameIndex($i);
            if ($name === 'project.json'
                || substr($name, -strlen('/project.json')) === '/project.json') {
                $depth = substr_count($name, '/');
                if ($depth < $entryDepth) { $entryDepth = $depth; $entry = $name; }
            }
        }
        if ($entry === null) { $za->close(); return $srcZipPath; }

        $raw = $za->getFromName($entry);
        $za->close();
        if ($raw === false) return $srcZipPath;

        $json = json_decode($raw);
        if ($json === null) return $srcZipPath;   // malformed → serve original

        // Canonical "before" snapshot, then overlay, then canonical "after".
        $before = json_encode($json);
        micro_sample_overlay_apply($json, $db, $ownerPkey);
        $after = json_encode($json);
        if ($after === $before) return $srcZipPath;   // no spine edits → no rewrite

        // Overlay changed the JSON — build a temp zip with the single entry
        // replaced. Temp lives under /ziptemp (same fs as the source tree).
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : dirname(dirname(__DIR__));
        $zipTempDir = "$docRoot/ziptemp";
        if (!is_dir($zipTempDir)) @mkdir($zipTempDir, 0775, true);
        $tmp = $zipTempDir . '/smz_overlay_' . uniqid('', true) . '.zip';
        if (!@copy($srcZipPath, $tmp)) { @unlink($tmp); return $srcZipPath; }

        $zw = new ZipArchive();
        if ($zw->open($tmp) !== true) { @unlink($tmp); return $srcZipPath; }
        if ($zw->addFromString($entry, json_encode($json, JSON_PRETTY_PRINT)) !== true) {
            $zw->close(); @unlink($tmp); return $srcZipPath;
        }
        $zw->close();
        return $tmp;
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

    /**
     * Lazily refresh the STATIC on-disk Micro project files with the
     * strabosamples.* spine overlay when micro_projectmetadata.files_dirty=TRUE,
     * then clear the flag. Sibling to micro_regenerate_pdf_if_dirty.
     *
     * Why this exists: the streamed/rendered Micro read surfaces overlay at
     * read time, but a few readers serve a raw file directly with no per-file
     * PHP hook — jwtmicrodb getProjectURL/getSharedURL hand back a static
     * /straboMicroFiles/<id>/project.zip URL, and straboMicroView serves a
     * static ./smzFiles/<id>/project.json. For those the overlay has to be
     * baked into the on-disk file. The dirty flag (set by a Samples-app edit
     * via StraboSamplesService) gates the rebuild so it happens once per edit,
     * on the next access, not on every request.
     *
     * Revert-safe: the overlay is always re-derived from the AUTHORITATIVE raw
     * upload (micro_projectmetadata.projectjson, never mutated by spine edits),
     * NOT from the possibly-already-overlaid on-disk file. So if a spine field
     * is later cleared, the regen reverts the on-disk file to the upload value.
     *
     * Regenerates all static copies under one flag (so the PDF-style per-store
     * clear-race can't strand a copy):
     *   - straboMicroFiles/<id>/project.json  (standalone)
     *   - the project.json entry inside straboMicroFiles/<id>/project.zip
     *   - straboMicroView/smzFiles/<id>/project.json  (only if that dir exists)
     *
     * Never throws and never breaks a download: a stale file is preferable to
     * a 500.
     *
     * @param object $db                StraboDbPostgreSQL handle
     * @param int    $projectInternalId micro_projectmetadata.id
     * @param int    $ownerPkey         project owner pkey (spine lookup scope)
     */
    function micro_regenerate_files_if_dirty($db, $projectInternalId, $ownerPkey) {
        try {
            $row = $db->get_row_prepared(
                "SELECT files_dirty, projectjson FROM micro_projectmetadata WHERE id = $1 LIMIT 1",
                array((int)$projectInternalId)
            );
            if (!$row || $row->files_dirty !== 't') return;

            // Re-derive from the authoritative raw upload + current spine.
            $json = json_decode($row->projectjson);
            if ($json !== null) {
                micro_sample_overlay_apply($json, $db, (int)$ownerPkey);
                $encoded = json_encode($json, JSON_PRETTY_PRINT);

                // Treat an empty DOCUMENT_ROOT (CLI / cron) as unset so the
                // path resolves to the repo root, not the filesystem root.
                $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : dirname(dirname(__DIR__));
                $pid     = (int)$projectInternalId;

                // 1. Standalone straboMicroFiles/<id>/project.json.
                //    NOTE: the /microview/ React viewer fetches
                //    microview/smzFiles/<id>/project.json, and microview/smzFiles
                //    is a SYMLINK to ../straboMicroFiles (gitignored, created by
                //    hand on each box). This write is what keeps that viewer
                //    fresh — if a viewer deploy ever replaces the symlink with a
                //    real directory, the viewer goes permanently stale.
                $mainDir = "$docRoot/straboMicroFiles/$pid";
                if (is_dir($mainDir)) {
                    @file_put_contents("$mainDir/project.json", $encoded);
                }

                // 2. The project.json entry inside straboMicroFiles/<id>/project.zip.
                $zipPath = "$mainDir/project.zip";
                if (class_exists('ZipArchive') && file_exists($zipPath)) {
                    $za = new ZipArchive();
                    if ($za->open($zipPath) === true) {
                        $entry = null; $entryDepth = PHP_INT_MAX;
                        for ($i = 0; $i < $za->numFiles; $i++) {
                            $name = $za->getNameIndex($i);
                            if ($name === 'project.json'
                                || substr($name, -strlen('/project.json')) === '/project.json') {
                                $depth = substr_count($name, '/');
                                if ($depth < $entryDepth) { $entryDepth = $depth; $entry = $name; }
                            }
                        }
                        if ($entry !== null) { $za->addFromString($entry, $encoded); }
                        $za->close();
                    }
                }

                // 3. straboMicroView's separate static store (prod-populated;
                //    skip silently when the per-project dir isn't present).
                $viewDir = "$docRoot/straboMicroView/smzFiles/$pid";
                if (is_dir($viewDir)) {
                    @file_put_contents("$viewDir/project.json", $encoded);
                }

                // Clear the flag ONLY after a successful re-derive. An
                // unparseable projectjson used to clear it anyway, leaving
                // every static copy permanently stale with nothing left to
                // retry; now the flag stays set so the next access retries
                // (and keeps retrying — acceptable, the regen is cheap and
                // the row is broken anyway until projectjson is repaired).
                $db->prepare_query(
                    "UPDATE micro_projectmetadata SET files_dirty = FALSE WHERE id = $1",
                    array((int)$projectInternalId)
                );
            }
        } catch (\Throwable $e) {
            // Swallow — a stale static file is better than a 500 on download.
        }
    }

}
