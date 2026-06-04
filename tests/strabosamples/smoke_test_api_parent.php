<?php
/**
 * File: smoke_test_api_parent.php
 * Description: Service-layer smoke test for samples/api-parent. Exercises
 *              the §8.3 parent/child sub-resource end-to-end:
 *
 *                build 3-deep tree (gp → p → c) →
 *                getParent (full assembled) → getChildren →
 *                cycle detection (self-cycle, 2-cycle, 3-deep cycle) →
 *                clearParent → orphaned-parent display →
 *                canEdit gate (editor can set; readonly cannot) →
 *                changelog rows on parent_set and parent_clear
 *
 *              Hermetic: sentinel ids cleaned up in finally. Cross-user
 *              orphan test uses two distinct users so the caller can
 *              point at a parent they lack read access to.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_api_parent.php
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
$gpId   = "smoketest-parent-gp-$stamp";
$pId    = "smoketest-parent-p-$stamp";
$cId    = "smoketest-parent-c-$stamp";
$stranger = "smoketest-parent-stranger-$stamp";
$ids = array(
    array($gpId, $ownerPkey),
    array($pId,  $ownerPkey),
    array($cId,  $ownerPkey),
    array($stranger, $editorPkey),  // sample owned by a different user (orphan-parent test)
);

$svc = new StraboSamplesService($db, $neodb);

try {
    // Seed: three owner samples + one belongs-to-someone-else sample.
    $svc->setUserpkey($ownerPkey);
    $svc->createSample(array('id' => $gpId, 'name' => 'grandparent'));
    $svc->createSample(array('id' => $pId,  'name' => 'parent'));
    $svc->createSample(array('id' => $cId,  'name' => 'child'));
    $svc->setUserpkey($editorPkey);
    $svc->createSample(array('id' => $stranger, 'name' => 'cross-user'));
    $svc->setUserpkey($ownerPkey);

    echo "=== setParent: c → p ===\n";
    $r = $svc->setParent($cId, $ownerPkey, $pId, $ownerPkey);
    check("set returns ok",                              !empty($r['ok']));

    echo "\n=== setParent: p → gp ===\n";
    $r = $svc->setParent($pId, $ownerPkey, $gpId, $ownerPkey);
    check("set returns ok",                              !empty($r['ok']));

    echo "\n=== getParent of c returns full p ===\n";
    $r = $svc->getParent($cId, $ownerPkey);
    check("returns wrapper with 'parent'",               is_array($r) && array_key_exists('parent', $r));
    check("parent.id matches",                           is_array($r['parent']) && $r['parent']['id'] === $pId);
    check("parent.name matches",                         $r['parent']['name'] === 'parent');

    echo "\n=== getChildren of p returns c ===\n";
    $r = $svc->getChildren($pId, $ownerPkey);
    check("returns 1 child",                             is_array($r) && $r['count'] === 1);
    check("child id matches",                            $r['children'][0]['id'] === $cId);

    echo "\n=== getChildren of gp returns p ===\n";
    $r = $svc->getChildren($gpId, $ownerPkey);
    check("returns 1 child",                             is_array($r) && $r['count'] === 1);
    check("child id matches",                            $r['children'][0]['id'] === $pId);

    echo "\n=== cycle detection ===\n";
    $r = $svc->setParent($cId, $ownerPkey, $cId, $ownerPkey);
    check("self-cycle rejected",                         empty($r['ok']) && $r['error'] === 'cycle_detected');

    // 2-cycle: p is already c's parent. Setting p's parent → c would yield p→c→p.
    $r = $svc->setParent($pId, $ownerPkey, $cId, $ownerPkey);
    check("2-cycle (p→c, c→p) rejected",                 empty($r['ok']) && $r['error'] === 'cycle_detected');

    // 3-deep cycle: setting gp's parent → c yields gp→c→p→gp.
    $r = $svc->setParent($gpId, $ownerPkey, $cId, $ownerPkey);
    check("3-deep cycle rejected",                       empty($r['ok']) && $r['error'] === 'cycle_detected');

    echo "\n=== parent_pair_required ===\n";
    $r = $svc->setParent($cId, $ownerPkey, '', $ownerPkey);
    check("empty parent_sample_id rejected",             empty($r['ok']) && $r['error'] === 'parent_pair_required');
    $r = $svc->setParent($cId, $ownerPkey, $pId, 0);
    check("zero parent_userpkey rejected",               empty($r['ok']) && $r['error'] === 'parent_pair_required');

    echo "\n=== parent_not_accessible ===\n";
    $r = $svc->setParent($cId, $ownerPkey, "nope-$stamp", $ownerPkey);
    check("nonexistent parent rejected as not_accessible", empty($r['ok']) && $r['error'] === 'parent_not_accessible');

    echo "\n=== clearParent ===\n";
    $r = $svc->clearParent($cId, $ownerPkey);
    check("clear returns ok",                            !empty($r['ok']));
    $r = $svc->getParent($cId, $ownerPkey);
    check("getParent now returns parent=null",           is_array($r) && $r['parent'] === null);
    $r = $svc->getChildren($pId, $ownerPkey);
    check("p now has 0 children",                        $r['count'] === 0);

    echo "\n=== orphaned-parent shape (cross-user, no access) ===\n";
    // Owner sets c's parent → editor's sample. setParent would normally
    // reject this because owner can't canRead the editor's sample. Wire
    // the orphan state directly via SQL.
    $db->prepare_query(
        "UPDATE strabosamples.samples
            SET parent_sample_id=$1, parent_userpkey=$2
          WHERE id=$3 AND userpkey=$4",
        array($stranger, $editorPkey, $cId, $ownerPkey)
    );
    $r = $svc->getParent($cId, $ownerPkey);
    check("parent wrapper present",                      is_array($r) && array_key_exists('parent', $r));
    check("parent.orphaned = true",                      isset($r['parent']['orphaned']) && $r['parent']['orphaned'] === true);
    check("parent_sample_id preserved",                  $r['parent']['parent_sample_id'] === $stranger);
    check("parent_userpkey preserved",                   (int)$r['parent']['parent_userpkey'] === $editorPkey);

    // Restore the pointer for collab-edit tests below.
    $db->prepare_query(
        "UPDATE strabosamples.samples SET parent_sample_id=NULL, parent_userpkey=NULL
          WHERE id=$1 AND userpkey=$2",
        array($cId, $ownerPkey)
    );

    echo "\n=== canEdit gate: editor can set; reader cannot ===\n";
    // Seed accepted edit + readonly grants on child sample.
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
        array($cId, $ownerPkey, $editorPkey, bin2hex(random_bytes(8)))
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'readonly', $4, TRUE, now(), $2)",
        array($cId, $ownerPkey, $readerPkey, bin2hex(random_bytes(8)))
    );

    $svc->setUserpkey($editorPkey);
    // Editor needs canRead on the proposed parent — give them an edit grant on p.
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_collaborators
           (sample_id, sample_userpkey, collaborator_pkey, permission_level,
            uuid, accepted, accepted_at, added_by)
         VALUES ($1, $2, $3, 'edit', $4, TRUE, now(), $2)",
        array($pId, $ownerPkey, $editorPkey, bin2hex(random_bytes(8)))
    );
    $r = $svc->setParent($cId, $ownerPkey, $pId, $ownerPkey);
    check("editor set parent returns ok",                !empty($r['ok']));

    $svc->setUserpkey($readerPkey);
    $r = $svc->setParent($cId, $ownerPkey, $pId, $ownerPkey);
    check("reader set parent refused 'forbidden'",       empty($r['ok']) && $r['error'] === 'forbidden');
    $r = $svc->clearParent($cId, $ownerPkey);
    check("reader clear parent refused 'forbidden'",     empty($r['ok']) && $r['error'] === 'forbidden');

    echo "\n=== changelog rows for parent_set + parent_clear ===\n";
    $svc->setUserpkey($ownerPkey);
    $svc->clearParent($cId, $ownerPkey);
    $rows = $db->get_results_prepared(
        "SELECT change_type FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
            AND change_type IN ('parent_set', 'parent_clear')
          ORDER BY pkey",
        array($cId, $ownerPkey)
    );
    $types = array_map(function ($r) { return $r->change_type; }, is_array($rows) ? $rows : array());
    check("at least one parent_set logged",              in_array('parent_set', $types, true));
    check("at least one parent_clear logged",            in_array('parent_clear', $types, true));

    echo "\n=== stranger gets 404 ===\n";
    // Pick a pkey that's not owner/editor/reader and use it.
    $svc->setUserpkey(999999999);  // sentinel non-user
    $r = $svc->getParent($cId, $ownerPkey);
    check("stranger getParent returns null (→ 404)",     $r === null);
    $r = $svc->getChildren($pId, $ownerPkey);
    check("stranger getChildren returns null (→ 404)",   $r === null);

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
