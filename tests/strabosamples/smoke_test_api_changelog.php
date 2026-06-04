<?php
/**
 * File: smoke_test_api_changelog.php
 * Description: Service-layer smoke test for samples/api-changelog. Exercises
 *              §8.5 paginated audit log over the changelog rows that the
 *              earlier sub-branches already write (create/update/parent_set/
 *              parent_clear/composition_change/...).
 *
 *              Flow:
 *                seed sample → drive several writes that each append a row →
 *                listChangelog → verify newest-first ordering, per-entry shape,
 *                and total → pagination (limit + offset) → canRead gate
 *                (editor, readonly, stranger).
 *
 *              Hermetic: sentinel ids cleaned up in finally; CASCADE wipes
 *              changelog + collaborators along with the sample.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_api_changelog.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

$users = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 3", array()
);
if (!is_array($users) || count($users) < 3) { echo "Need 3 users\n"; exit(1); }
$ownerPkey  = (int)$users[0]->pkey;
$editorPkey = (int)$users[1]->pkey;
$readerPkey = (int)$users[2]->pkey;
echo "owner=$ownerPkey  editor=$editorPkey  reader=$readerPkey\n\n";

$stamp = time();
$sId       = "smoketest-clog-$stamp";
$parentId  = "smoketest-clog-parent-$stamp";
$ids = array(array($sId, $ownerPkey), array($parentId, $ownerPkey));

$svc = new StraboSamplesService($db, $neodb);

try {
    $svc->setUserpkey($ownerPkey);

    // Drive a sequence of changelog-appending writes.
    $svc->createSample(array('id' => $sId, 'name' => 'changelog-smoke'));        // create
    $svc->createSample(array('id' => $parentId, 'name' => 'parent-for-clog'));    // create (parent)
    $svc->updateSample($sId, $ownerPkey, array('name' => 'rename-1'));            // update
    $svc->updateSample($sId, $ownerPkey, array('description' => 'desc-1'));        // update
    $svc->setParent($sId, $ownerPkey, $parentId, $ownerPkey);                     // parent_set
    $svc->clearParent($sId, $ownerPkey);                                          // parent_clear
    $svc->replaceComposition($sId, $ownerPkey, array(                             // composition_change
        array('mineral' => 'quartz'),
    ));

    // Seed accepted edit + readonly grants so we can exercise the canRead gate.
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
        array($sId, $ownerPkey, $editorPkey, bin2hex(random_bytes(8)))
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'readonly', $4, TRUE, now(), $2)",
        array($sId, $ownerPkey, $readerPkey, bin2hex(random_bytes(8)))
    );

    echo "=== listChangelog (owner, defaults) ===\n";
    $r = $svc->listChangelog($sId, $ownerPkey);
    check("returns wrapper",                              is_array($r) && array_key_exists('changelog', $r));
    check("total = 6 (1 create + 2 update + parent_set + parent_clear + composition_change)",
        isset($r['total']) && $r['total'] === 6);
    check("count = total (under default limit)",          $r['count'] === $r['total']);
    check("limit default = 50",                           $r['limit'] === 50);
    check("offset default = 0",                           $r['offset'] === 0);

    echo "\n=== ordering: newest first ===\n";
    $types = array_map(function ($e) { return $e['change_type']; }, $r['changelog']);
    // newest first: composition_change should be at index 0; create at the end.
    check("newest entry = 'composition_change'",          $types[0] === 'composition_change');
    check("oldest entry = 'create'",                      end($types) === 'create');
    // Mid-sequence: parent_clear should appear before parent_set in the list.
    $pcIdx = array_search('parent_clear', $types, true);
    $psIdx = array_search('parent_set',   $types, true);
    check("parent_clear is newer than parent_set",        $pcIdx !== false && $psIdx !== false && $pcIdx < $psIdx);

    echo "\n=== per-entry shape ===\n";
    $first = $r['changelog'][0];
    check("pkey is int",                                  isset($first['pkey']) && is_int($first['pkey']));
    check("changed_by = owner",                           isset($first['changed_by']) && $first['changed_by'] === $ownerPkey);
    check("changed_at present",                           !empty($first['changed_at']));
    check("source_subsystem = 'samples_api'",             $first['source_subsystem'] === 'samples_api');
    check("changes decoded from JSONB",                   is_array($first['changes']));

    echo "\n=== pagination (limit=2, offset=0) ===\n";
    $page1 = $svc->listChangelog($sId, $ownerPkey, 2, 0);
    check("page1 count = 2",                              $page1['count'] === 2);
    check("page1 total = 6",                              $page1['total'] === 6);
    check("page1 limit echoed",                           $page1['limit'] === 2);
    check("page1 offset echoed",                          $page1['offset'] === 0);

    echo "\n=== pagination (limit=2, offset=2) ===\n";
    $page2 = $svc->listChangelog($sId, $ownerPkey, 2, 2);
    check("page2 count = 2",                              $page2['count'] === 2);
    check("page2 first row differs from page1's",         $page2['changelog'][0]['pkey'] !== $page1['changelog'][0]['pkey']);

    echo "\n=== pagination clamping ===\n";
    $clamped = $svc->listChangelog($sId, $ownerPkey, 0, -10);
    check("limit=0 clamped to default 50",                $clamped['limit'] === 50);
    check("offset=-10 clamped to 0",                      $clamped['offset'] === 0);
    $clamped = $svc->listChangelog($sId, $ownerPkey, 99999, 0);
    check("limit=99999 clamped to max 500",               $clamped['limit'] === 500);

    echo "\n=== canRead gate ===\n";
    $svc->setUserpkey($editorPkey);
    $r = $svc->listChangelog($sId, $ownerPkey);
    check("editor (collab edit) can read",                is_array($r) && $r['total'] === 6);

    $svc->setUserpkey($readerPkey);
    $r = $svc->listChangelog($sId, $ownerPkey);
    check("reader (collab readonly) can read",            is_array($r) && $r['total'] === 6);

    $svc->setUserpkey(999999999);
    $r = $svc->listChangelog($sId, $ownerPkey);
    check("stranger returns null (→ 404)",                $r === null);

} finally {
    $svc->setUserpkey($ownerPkey);
    foreach ($ids as $pair) {
        $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($pair[0], $pair[1]));
    }
}

echo "\n";
if (empty($failures)) {
    echo "RESULT: all checks PASS\n";
    exit(0);
} else {
    echo "RESULT: " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
