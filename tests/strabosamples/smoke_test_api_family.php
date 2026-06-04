<?php
/**
 * File: smoke_test_api_family.php
 * Description: Service-layer smoke test for samples/api-family. Exercises
 *              the §12.2.1 family-tree snapshot endpoint:
 *
 *                single node (no parent, no children) →
 *                focus + parent only →
 *                focus + children only (created_at order) →
 *                full snapshot (parent + children) →
 *                orphaned-parent shape (cross-user, no read access) →
 *                light-spine shape (no field_data / collaborators bloat) →
 *                stranger getFamily → null (controller maps to 404)
 *
 *              Hermetic: sentinel ids cleaned up in finally.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_api_family.php
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
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 2",
    array()
);
if (!is_array($users) || count($users) < 2) { echo "Need 2 users\n"; exit(1); }
$ownerPkey   = (int)$users[0]->pkey;
$strangerPkey = (int)$users[1]->pkey;
echo "owner=$ownerPkey  stranger=$strangerPkey\n\n";

$stamp = time();
$parentId   = "smoketest-family-parent-$stamp";
$focusId    = "smoketest-family-focus-$stamp";
$childAId   = "smoketest-family-child-a-$stamp";
$childBId   = "smoketest-family-child-b-$stamp";
$strangerSampleId = "smoketest-family-stranger-$stamp";

$ids = array(
    array($parentId,         $ownerPkey),
    array($focusId,          $ownerPkey),
    array($childAId,         $ownerPkey),
    array($childBId,         $ownerPkey),
    array($strangerSampleId, $strangerPkey),
);

$svc = new StraboSamplesService($db, $neodb);

try {
    // ----- Phase 1: single-node focus (no parent, no children) -----
    $svc->setUserpkey($ownerPkey);
    $svc->createSample(array(
        'id' => $focusId, 'name' => 'focus',
        'display_sample_type' => 'rock', 'display_sample_purpose' => 'petrology',
    ));

    echo "=== Part 1: focus with no parent and no children ===\n";
    $r = $svc->getFamily($focusId, $ownerPkey);
    check("wrapper has focus/parent/children keys",
        is_array($r) && array_key_exists('focus', $r)
                     && array_key_exists('parent', $r)
                     && array_key_exists('children', $r));
    check("focus.id matches",                              $r['focus']['id'] === $focusId);
    check("focus.userpkey matches",                        (int)$r['focus']['userpkey'] === $ownerPkey);
    check("focus.name matches",                            $r['focus']['name'] === 'focus');
    check("focus.display_sample_type matches",             $r['focus']['display_sample_type'] === 'rock');
    check("focus.display_sample_purpose matches",          $r['focus']['display_sample_purpose'] === 'petrology');
    check("parent is null",                                $r['parent'] === null);
    check("children is empty array",                       is_array($r['children']) && count($r['children']) === 0);

    // ----- Phase 2: add a parent (focus + parent only) -----
    $svc->createSample(array('id' => $parentId, 'name' => 'parent'));
    $svc->setParent($focusId, $ownerPkey, $parentId, $ownerPkey);

    echo "\n=== Part 2: focus with parent, no children ===\n";
    $r = $svc->getFamily($focusId, $ownerPkey);
    check("parent populated",                              is_array($r['parent']));
    check("parent.id matches",                             $r['parent']['id'] === $parentId);
    check("parent.userpkey matches",                       (int)$r['parent']['userpkey'] === $ownerPkey);
    check("parent.name matches",                           $r['parent']['name'] === 'parent');
    check("parent has no 'orphaned' flag",                 !isset($r['parent']['orphaned']));
    check("children still empty",                          count($r['children']) === 0);

    // ----- Phase 3: add two children (created_at order) -----
    $svc->createSample(array('id' => $childAId, 'name' => 'child-a'));
    // Force a measurable created_at gap so the ORDER BY is deterministic.
    sleep(1);
    $svc->createSample(array('id' => $childBId, 'name' => 'child-b'));
    $svc->setParent($childAId, $ownerPkey, $focusId, $ownerPkey);
    $svc->setParent($childBId, $ownerPkey, $focusId, $ownerPkey);

    echo "\n=== Part 3: focus with parent and two children ===\n";
    $r = $svc->getFamily($focusId, $ownerPkey);
    check("parent still populated",                        is_array($r['parent']) && $r['parent']['id'] === $parentId);
    check("two children returned",                         count($r['children']) === 2);
    check("children[0] = child-a (created_at order)",      $r['children'][0]['id'] === $childAId);
    check("children[1] = child-b",                         $r['children'][1]['id'] === $childBId);
    check("child entry carries id/userpkey/name/displays", isset($r['children'][0]['id'])
                                                        && isset($r['children'][0]['userpkey'])
                                                        && array_key_exists('name', $r['children'][0])
                                                        && array_key_exists('display_sample_type', $r['children'][0])
                                                        && array_key_exists('display_sample_purpose', $r['children'][0]));

    // ----- Phase 4: light-spine shape only (no field_data, no collaborators) -----
    echo "\n=== Part 4: light-spine payload only ===\n";
    check("focus has no field_data",                       !array_key_exists('field_data', $r['focus']));
    check("focus has no micro_data",                       !array_key_exists('micro_data', $r['focus']));
    check("focus has no experimental_data",                !array_key_exists('experimental_data', $r['focus']));
    check("focus has no collaborators",                    !array_key_exists('collaborators', $r['focus']));
    check("focus has no subsystem_links",                  !array_key_exists('subsystem_links', $r['focus']));
    check("focus has no composition",                      !array_key_exists('composition', $r['focus']));

    // ----- Phase 5: orphaned parent (cross-user, no read access) -----
    // Seed a sample owned by a stranger, then wire it as focus's parent
    // directly via SQL (setParent's canRead would reject this normally,
    // matching the api-parent test's orphaned-shape setup).
    $svc->setUserpkey($strangerPkey);
    $svc->createSample(array('id' => $strangerSampleId, 'name' => 'stranger-parent'));
    $svc->setUserpkey($ownerPkey);
    $db->prepare_query(
        "UPDATE strabosamples.samples
            SET parent_sample_id=$1, parent_userpkey=$2
          WHERE id=$3 AND userpkey=$4",
        array($strangerSampleId, $strangerPkey, $focusId, $ownerPkey)
    );

    echo "\n=== Part 5: orphaned-parent shape ===\n";
    $r = $svc->getFamily($focusId, $ownerPkey);
    check("parent populated as orphaned wrapper",          is_array($r['parent']) && isset($r['parent']['orphaned']));
    check("parent.orphaned = true",                        $r['parent']['orphaned'] === true);
    check("parent.parent_sample_id preserved",             $r['parent']['parent_sample_id'] === $strangerSampleId);
    check("parent.parent_userpkey preserved",              (int)$r['parent']['parent_userpkey'] === $strangerPkey);
    check("children unaffected",                           count($r['children']) === 2);

    // ----- Phase 6: stranger getFamily → null (controller maps to 404) -----
    echo "\n=== Part 6: stranger has no read → null ===\n";
    $svc->setUserpkey(999999999);  // sentinel non-user
    $r = $svc->getFamily($focusId, $ownerPkey);
    check("stranger getFamily returns null",               $r === null);

} finally {
    $svc->setUserpkey($ownerPkey);
    foreach ($ids as $pair) {
        $db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($pair[0], $pair[1])
        );
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
