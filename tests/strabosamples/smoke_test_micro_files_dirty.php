<?php
/**
 * File: smoke_test_micro_files_dirty.php
 * Description: Coverage for the static-file overlay-at-edit mechanism added on
 *              samples/phase-i-deletes. A few Micro readers serve a raw
 *              on-disk file with no per-file PHP serving hook (jwtmicrodb
 *              getProjectURL/getSharedURL return a static
 *              /straboMicroFiles/<id>/project.zip URL; straboMicroView serves a
 *              static ./smzFiles/<id>/project.json), so the spine overlay has
 *              to be baked into the on-disk files when a Samples-app edit
 *              dirties them. New micro_projectmetadata.files_dirty flag +
 *              micro_regenerate_files_if_dirty() (lazy regen on next access).
 *
 *                A. regen overlays the on-disk project.json AND the project.json
 *                   entry inside project.zip, then clears files_dirty
 *                B. clean no-op (files_dirty=FALSE → gate returns early)
 *                C. markDirty integration: a spine edit via updateSample sets
 *                   files_dirty (alongside pdf_dirty)
 *                D. REVERT-SAFETY: a later-cleared spine field reverts the
 *                   on-disk file to the authoritative raw upload value (regen
 *                   re-derives from micro_projectmetadata.projectjson, never
 *                   from the already-overlaid on-disk file)
 *
 *              Hermetic: a micro_projectmetadata row + on-disk
 *              straboMicroFiles/<id>/ (project.json + project.zip) + spine
 *              sample + micro link; cleaned up in finally{}.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_micro_files_dirty.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/microdb/lib/sample_overlay.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}

$owner = (int)$db->get_var("SELECT pkey FROM users WHERE deleted=FALSE AND active=TRUE ORDER BY pkey LIMIT 1");
$stamp = time();
$projStraboId = "micfiles-proj-$stamp";
$sampStraboId = "micfiles-samp-$stamp";
$pmid = (int)$db->get_var("select nextval('micro_projectmetadata_id_seq')");
$dir = "/srv/app/www/straboMicroFiles/$pmid";

// Raw uploaded projectjson — sample's uploaded sampleID is 'OrigName'.
$rawProjectJson = json_encode(array(
    'id' => $projStraboId,
    'datasets' => array(array(
        'id' => 'ds1',
        'samples' => array(array(
            'id'                => $sampStraboId,
            'sampleID'          => 'OrigName',
            'sampleDescription' => 'orig desc',
            'micrographs'       => array(),
        )),
    )),
));

function zipEntryJson($zipPath, $entryGuess) {
    if (!class_exists('ZipArchive')) return null;
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) return null;
    $raw = $za->getFromName($entryGuess);
    $za->close();
    return $raw === false ? null : json_decode($raw);
}

try {
    // ---- seed DB ----
    $db->prepare_query(
        "INSERT INTO micro_projectmetadata (id, userpkey, strabo_id, projectjson, files_dirty) VALUES ($1,$2,$3,$4,FALSE)",
        array($pmid, $owner, $projStraboId, $rawProjectJson));
    // Spine edit already present (name='EditedName').
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, description, created_by, modified_by, created_at, modified_at)
         VALUES ($1,$2,$3,$4,$2,$2,NOW(),NOW())
         ON CONFLICT (id, userpkey) DO UPDATE SET name=$3, description=$4, modified_at=NOW()",
        array($sampStraboId, $owner, 'EditedName', 'edited desc'));
    // Micro subsystem link (reference_id = the project internal pkey).
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
            (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata, created_at, modified_at)
         VALUES ($1,$2,'micro',$3,$2,'{}',NOW(),NOW())",
        array($sampStraboId, $owner, (string)$pmid));

    // ---- seed on-disk straboMicroFiles/<id>/ ----
    @mkdir($dir, 0775, true);
    file_put_contents("$dir/project.json", $rawProjectJson);
    $zipEntry = "$projStraboId/project.json";
    $za = new ZipArchive();
    $za->open("$dir/project.zip", ZipArchive::CREATE);
    $za->addFromString($zipEntry, $rawProjectJson);
    $za->addFromString("$projStraboId/images/placeholder.txt", "img");   // a non-json entry to confirm it's preserved
    $za->close();

    // ===================================================================
    // PART A: regen overlays project.json + zip entry, clears flag
    // ===================================================================
    echo "=== A: micro_regenerate_files_if_dirty overlays static files ===\n";
    $db->query("UPDATE micro_projectmetadata SET files_dirty = TRUE WHERE id = $pmid");
    micro_regenerate_files_if_dirty($db, $pmid, $owner);

    $diskJson = json_decode(file_get_contents("$dir/project.json"));
    check("A: on-disk project.json sampleID overlaid",
        $diskJson && $diskJson->datasets[0]->samples[0]->sampleID === 'EditedName');
    check("A: on-disk project.json description overlaid",
        $diskJson && $diskJson->datasets[0]->samples[0]->sampleDescription === 'edited desc');
    $zipJson = zipEntryJson("$dir/project.zip", $zipEntry);
    check("A: project.zip entry sampleID overlaid",
        $zipJson && $zipJson->datasets[0]->samples[0]->sampleID === 'EditedName');
    $zCheck = new ZipArchive(); $zCheck->open("$dir/project.zip");
    $placeholderPreserved = $zCheck->getFromName("$projStraboId/images/placeholder.txt");
    $zCheck->close();
    check("A: project.zip non-json entry preserved (images untouched)",
        $placeholderPreserved === 'img');
    check("A: files_dirty cleared",
        $db->get_var_prepared("SELECT files_dirty FROM micro_projectmetadata WHERE id=$1", array($pmid)) === 'f');

    // ===================================================================
    // PART B: clean no-op (gate returns early, no rewrite)
    // ===================================================================
    echo "\n=== B: clean no-op gate ===\n";
    file_put_contents("$dir/project.json", '{"sentinel":true}');
    micro_regenerate_files_if_dirty($db, $pmid, $owner);   // files_dirty is FALSE now
    check("B: project.json untouched when not dirty",
        trim(file_get_contents("$dir/project.json")) === '{"sentinel":true}');
    // restore overlaid file for later parts
    $db->query("UPDATE micro_projectmetadata SET files_dirty = TRUE WHERE id = $pmid");
    micro_regenerate_files_if_dirty($db, $pmid, $owner);

    // ===================================================================
    // PART C: markDirty integration — updateSample sets files_dirty
    // ===================================================================
    echo "\n=== C: updateSample flips files_dirty (with pdf_dirty) ===\n";
    $db->query("UPDATE micro_projectmetadata SET files_dirty = FALSE, pdf_dirty = FALSE WHERE id = $pmid");
    $svc = new StraboSamplesService($db, $neodb);
    $svc->setUserpkey($owner);
    $svc->updateSample($sampStraboId, $owner, array('description' => 'changed via samples app'));
    check("C: files_dirty set by the spine edit",
        $db->get_var_prepared("SELECT files_dirty FROM micro_projectmetadata WHERE id=$1", array($pmid)) === 't');
    check("C: pdf_dirty also set (shared trigger)",
        $db->get_var_prepared("SELECT pdf_dirty FROM micro_projectmetadata WHERE id=$1", array($pmid)) === 't');

    // ===================================================================
    // PART D: REVERT-SAFETY — clearing a spine field reverts the on-disk file
    // ===================================================================
    echo "\n=== D: revert-safety (regen re-derives from authoritative raw) ===\n";
    // Clear the spine name → overlay should leave sampleID at the raw upload.
    $db->prepare_query("UPDATE strabosamples.samples SET name = NULL WHERE id=$1 AND userpkey=$2", array($sampStraboId, $owner));
    $db->query("UPDATE micro_projectmetadata SET files_dirty = TRUE WHERE id = $pmid");
    micro_regenerate_files_if_dirty($db, $pmid, $owner);
    $reverted = json_decode(file_get_contents("$dir/project.json"));
    check("D: on-disk sampleID reverted to raw upload ('OrigName')",
        $reverted && $reverted->datasets[0]->samples[0]->sampleID === 'OrigName');

} finally {
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($sampStraboId, $owner));
    $db->query("DELETE FROM micro_projectmetadata WHERE id=$pmid");
    @exec("rm -rf " . escapeshellarg($dir));
}

echo "\n";
if (empty($failures)) { echo "RESULT: all checks PASS\n"; exit(0); }
echo "RESULT: " . count($failures) . " failure(s):\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
