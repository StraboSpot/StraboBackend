<?php
/**
 * File: smoke_test_exp_linking.php
 * Description: Service-layer suite for "Link Sample From StraboSamples"
 *              (Exp_StraboSamples_Linking.md §8). Exercises the link-intent
 *              transitions in exp_sample_sync() and the reference-scoped
 *              spine removal:
 *                multi-link (two experiments share one strabo_id) →
 *                slice LWW under multi-link →
 *                reference-scoped removal (one experiment leaves, sample
 *                  survives; last experiment leaves, sample goes) →
 *                unlink (explicit null → fresh mint, old sample intact
 *                  when still referenced) →
 *                relink (old link dropped, new adopted) →
 *                identity stability (absent / malformed / same id) →
 *                cross-owner rejection (foreign spine id → fresh mint) →
 *                FK-ordering heal preserved + hijack guard →
 *                field-managed priority (writeSpine suppressed; spine
 *                  survives exp departure, exp slice cleared)
 *
 *              Hermetic: sentinel straboexp.project + experiments and
 *              direct spine fixtures, all torn down in finally.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_exp_linking.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/experimental/lib/sample_sync.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

function expLinkCount($db, $sid, $upk) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='experimental'",
        array($sid, $upk)
    );
}
function spineCount($db, $sid, $upk) {
    return (int)$db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($sid, $upk)
    );
}
function mkSample($name, $extra = array()) {
    $s = (object)array(
        'id'          => $name,
        'name'        => $name,
        'igsn'        => '',
        'description' => "desc of $name",
        'material'    => (object)array(
            'material' => (object)array('type' => 'igneous', 'name' => 'basalt', 'state' => '', 'note' => ''),
        ),
    );
    foreach ($extra as $k => $v) $s->$k = $v;
    return $s;
}

// Two non-deleted users: owner + a stranger (for cross-owner).
$rows = $db->get_results_prepared(
    "SELECT pkey FROM users WHERE deleted = FALSE AND active = TRUE ORDER BY pkey LIMIT 2", array()
);
$ownerPkey    = (int)$rows[0]->pkey;
$strangerPkey = (int)$rows[1]->pkey;
$uuid_gen     = new UUID();
$stamp        = time();

// --- Seed straboexp.project + experiments A..E ---
$projectPkey = (int)$db->get_var("SELECT nextval('straboexp.project_pkey_seq')");
$projectUuid = $uuid_gen->v4();
$db->prepare_query(
    "INSERT INTO straboexp.project (pkey, userpkey, uuid, name, ispublic)
     VALUES ($1, $2, $3, $4, FALSE)",
    array($projectPkey, $ownerPkey, $projectUuid, "smoketest-explink-$stamp")
);
$exps = array();
foreach (array('A', 'B', 'C', 'D', 'E') as $tag) {
    $ep = (int)$db->get_var("SELECT nextval('straboexp.experiment_pkey_seq')");
    $db->prepare_query(
        "INSERT INTO straboexp.experiment (pkey, project_pkey, userpkey, id, uuid, json)
         VALUES ($1, $2, $3, $4, $5, '{}')",
        array($ep, $projectPkey, $ownerPkey, "explink-$tag-$stamp", $uuid_gen->v4())
    );
    $exps[$tag] = $ep;
}

echo "owner=$ownerPkey stranger=$strangerPkey project_pkey=$projectPkey\n\n";

$spineCleanup = array();   // array of [id, userpkey]
try {

    // ================= Part 1: multi-link =================
    echo "=== Part 1: two experiments share one spine sample ===\n";
    $s1 = exp_sample_sync($db, $uuid_gen, $exps['A'], $ownerPkey, mkSample("Shared Rock A-$stamp"));
    check("baseline sync (exp A) minted an id",                 !empty($s1));
    $spineCleanup[] = array($s1, $ownerPkey);

    $bSample = mkSample("Shared Rock B-$stamp", array('strabo_id' => $s1));
    $s1b = exp_sample_sync($db, $uuid_gen, $exps['B'], $ownerPkey, $bSample);
    check("exp B adopted the SAME strabo_id (link)",            $s1b === $s1);
    check("still exactly one spine row",                        spineCount($db, $s1, $ownerPkey) === 1);
    check("two experimental link rows",                         expLinkCount($db, $s1, $ownerPkey) === 2);

    $expRows = $db->get_results_prepared(
        "SELECT experiment_pkey FROM straboexp.sample WHERE strabo_id = $1 ORDER BY experiment_pkey",
        array($s1)
    );
    check("two straboexp.sample rows share the id (DDL relaxed)", is_array($expRows) && count($expRows) === 2);

    $row = $db->get_row_prepared(
        "SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($s1, $ownerPkey)
    );
    check("spine slice is last-writer (B's name)",              $row && $row->name === "Shared Rock B-$stamp");

    // ================= Part 2: reference-scoped removal =================
    echo "\n=== Part 2: one experiment leaves, the sample survives ===\n";
    $r = exp_sample_sync($db, $uuid_gen, $exps['B'], $ownerPkey, null);
    check("empty-sample sync (exp B) returns null",             $r === null);
    check("spine row SURVIVES (exp A still linked)",            spineCount($db, $s1, $ownerPkey) === 1);
    check("one experimental link row remains (A's)",            expLinkCount($db, $s1, $ownerPkey) === 1);
    $expData = $db->get_var_prepared(
        "SELECT experimental_data::text FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($s1, $ownerPkey)
    );
    check("experimental slice NOT cleared (same source remains)", !empty($expData));
    $refLeft = $db->get_var_prepared(
        "SELECT reference_id FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='experimental'",
        array($s1, $ownerPkey)
    );
    check("surviving link is exp A's",                          (string)$refLeft === (string)$exps['A']);

    echo "\n=== Part 2b: last experiment leaves, the sample goes ===\n";
    $r = exp_sample_sync($db, $uuid_gen, $exps['A'], $ownerPkey, null);
    check("empty-sample sync (exp A) returns null",             $r === null);
    check("spine row deleted with the last link",               spineCount($db, $s1, $ownerPkey) === 0);

    // ================= Part 3: unlink + relink =================
    echo "\n=== Part 3: unlink mints fresh; relink transfers ===\n";
    $s2 = exp_sample_sync($db, $uuid_gen, $exps['C'], $ownerPkey, mkSample("Solo Rock C-$stamp"));
    check("exp C sample created",                               !empty($s2));
    $spineCleanup[] = array($s2, $ownerPkey);

    // Round-trip save with the SAME id: identity stable.
    $same = exp_sample_sync($db, $uuid_gen, $exps['C'], $ownerPkey,
        mkSample("Solo Rock C-$stamp", array('strabo_id' => $s2)));
    check("same-id round-trip keeps identity",                  $same === $s2);

    // Absent property: identity stable.
    $same = exp_sample_sync($db, $uuid_gen, $exps['C'], $ownerPkey, mkSample("Solo Rock C-$stamp"));
    check("absent strabo_id keeps identity",                    $same === $s2);

    // Malformed id: identity stable.
    $same = exp_sample_sync($db, $uuid_gen, $exps['C'], $ownerPkey,
        mkSample("Solo Rock C-$stamp", array('strabo_id' => 'not-a-uuid')));
    check("malformed strabo_id keeps identity",                 $same === $s2);

    // Explicit null: UNLINK -> fresh mint, old spine row gone (last link).
    $s3 = exp_sample_sync($db, $uuid_gen, $exps['C'], $ownerPkey,
        mkSample("Solo Rock C-$stamp", array('strabo_id' => null)));
    check("explicit-null unlink minted a NEW id",               !empty($s3) && $s3 !== $s2);
    $spineCleanup[] = array($s3, $ownerPkey);
    check("old spine row removed (was C's last link)",          spineCount($db, $s2, $ownerPkey) === 0);
    check("new spine row exists",                               spineCount($db, $s3, $ownerPkey) === 1);

    // Relink: point C at a fresh shared sample created by exp D.
    $s4 = exp_sample_sync($db, $uuid_gen, $exps['D'], $ownerPkey, mkSample("Target Rock D-$stamp"));
    check("relink target (exp D) created",                      !empty($s4));
    $spineCleanup[] = array($s4, $ownerPkey);
    $s4c = exp_sample_sync($db, $uuid_gen, $exps['C'], $ownerPkey,
        mkSample("Solo Rock C-$stamp", array('strabo_id' => $s4)));
    check("relink adopted the target id",                       $s4c === $s4);
    check("old free-standing spine row removed on relink",      spineCount($db, $s3, $ownerPkey) === 0);
    check("target now has two experimental links",              expLinkCount($db, $s4, $ownerPkey) === 2);

    // ================= Part 4: cross-owner rejection =================
    echo "\n=== Part 4: a foreign spine id is never adopted ===\n";
    $foreignId = $uuid_gen->v4();
    $db->prepare_query(
        "INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by)
         VALUES ($1, $2, $3, $2, $2)",
        array($foreignId, $strangerPkey, "Foreign Rock $stamp")
    );
    $spineCleanup[] = array($foreignId, $strangerPkey);

    // Re-use exp C: an update pointing at a foreign id must keep identity.
    $kept = exp_sample_sync($db, $uuid_gen, $exps['C'], $ownerPkey,
        mkSample("Solo Rock C-$stamp", array('strabo_id' => $foreignId)));
    check("update ignores foreign id (identity kept)",          $kept === $s4);
    check("foreign sample untouched (no exp link)",             expLinkCount($db, $foreignId, $strangerPkey) === 0);

    // Create path: exp B is sample-less again; a foreign id mints fresh.
    $minted = exp_sample_sync($db, $uuid_gen, $exps['B'], $ownerPkey,
        mkSample("Stranger Wannabe B-$stamp", array('strabo_id' => $foreignId)));
    check("create with foreign id minted fresh",                !empty($minted) && $minted !== $foreignId);
    $spineCleanup[] = array($minted, $ownerPkey);
    check("foreign sample still untouched",                     expLinkCount($db, $foreignId, $strangerPkey) === 0);

    // ================= Part 5: heal + hijack guard =================
    echo "\n=== Part 5: FK-ordering heal preserved; hijack rejected ===\n";
    // Heal: an embedded id with NO spine row and NO straboexp claim adopts.
    $db->prepare_query("DELETE FROM straboexp.sample WHERE experiment_pkey = $1", array($exps['B']));
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($minted, $ownerPkey));
    $healId = $uuid_gen->v4();
    $healed = exp_sample_sync($db, $uuid_gen, $exps['B'], $ownerPkey,
        mkSample("Healed Rock B-$stamp", array('strabo_id' => $healId)));
    check("unclaimed pre-minted id adopted (heal)",             $healed === $healId);
    $spineCleanup[] = array($healId, $ownerPkey);

    // Hijack: exp E arrives with an id claimed by B's row but NOT backed by
    // an owned spine row. Delete the spine row to simulate the stale copy.
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2", array($healId, $ownerPkey));
    $hijack = exp_sample_sync($db, $uuid_gen, $exps['E'], $ownerPkey,
        mkSample("Hijack Rock E-$stamp", array('strabo_id' => $healId)));
    check("claimed-but-unbacked id NOT adopted (fresh mint)",   !empty($hijack) && $hijack !== $healId);
    $spineCleanup[] = array($hijack, $ownerPkey);

    // ================= Part 6: field-managed priority =================
    echo "\n=== Part 6: linking a field-managed sample ===\n";
    $fieldId = $uuid_gen->v4();
    $db->prepare_query(
        "INSERT INTO strabosamples.samples
            (id, userpkey, name, igsn, description, field_data, created_by, modified_by)
         VALUES ($1, $2, $3, $4, $5, $6, $2, $2)",
        array($fieldId, $ownerPkey, "Field Rock $stamp", "FIGSN-$stamp", 'from the field',
              json_encode(array('spot_id' => 'spot-1', 'label' => "Field Rock $stamp")))
    );
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
            (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey)
         VALUES ($1, $2, 'field', 'spot-1', $2)",
        array($fieldId, $ownerPkey)
    );
    $spineCleanup[] = array($fieldId, $ownerPkey);

    // Exp E relinks to the field-managed sample.
    $eLinked = exp_sample_sync($db, $uuid_gen, $exps['E'], $ownerPkey,
        mkSample("Exp View Of Field Rock $stamp", array('strabo_id' => $fieldId)));
    check("exp E adopted the field-managed id",                 $eLinked === $fieldId);
    check("old free-standing E sample removed",                 spineCount($db, $hijack, $ownerPkey) === 0);

    $row = $db->get_row_prepared(
        "SELECT name, experimental_data::text AS ed FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($fieldId, $ownerPkey)
    );
    check("spine identity UNCHANGED (field priority)",          $row && $row->name === "Field Rock $stamp");
    check("experimental slice written",                         $row && !empty($row->ed));
    check("experimental link row added",                        expLinkCount($db, $fieldId, $ownerPkey) === 1);

    // Exp E departs: sample must SURVIVE (field still cares), exp slice cleared.
    $r = exp_sample_sync($db, $uuid_gen, $exps['E'], $ownerPkey, null);
    check("empty-sample sync (exp E) returns null",             $r === null);
    $row = $db->get_row_prepared(
        "SELECT name, experimental_data FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
        array($fieldId, $ownerPkey)
    );
    check("field-managed sample SURVIVES exp departure",        $row !== null);
    check("experimental slice cleared on last exp link",        $row && $row->experimental_data === null);
    check("exp link rows gone",                                 expLinkCount($db, $fieldId, $ownerPkey) === 0);
    $fieldLink = $db->get_var_prepared(
        "SELECT count(*) FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'",
        array($fieldId, $ownerPkey)
    );
    check("field link row untouched",                           (int)$fieldLink === 1);

} finally {
    // Defensive cleanup (project CASCADE covers straboexp experiments+samples).
    foreach ($spineCleanup as $pair) {
        $db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($pair[0], $pair[1])
        );
    }
    $db->prepare_query("DELETE FROM straboexp.project WHERE pkey=$1", array($projectPkey));
    // Sync hooks index the app-endpoint fixtures; the raw source deletes
    // never unindex (orphaned globe markers, 2026-08-23). Project uuids
    // are random, so sweep by the distinctive fixture project name.
    $db->prepare_query("DELETE FROM strabosearch.item_hit WHERE project_name LIKE 'smoketest-explink-%'", array());
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
