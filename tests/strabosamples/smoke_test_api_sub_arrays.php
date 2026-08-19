<?php
/**
 * File: smoke_test_api_sub_arrays.php
 * Description: Service-layer smoke test for samples/api-sub-arrays. Exercises
 *              §8.4 composition / parameters / documents sub-resources with
 *              full-replace semantics, validation, permission gates, and
 *              changelog writes.
 *
 *              The same flow is run against each of the three resources:
 *                list (empty) → replace with 3 items → list (3 items,
 *                proper ordering) → replace with 2 (count drops, no
 *                stragglers) → replace with empty (all gone) →
 *                missing-required-field rejected → editor can replace →
 *                readonly cannot → changelog row appended on each replace
 *
 *              Hermetic: sentinel ids cleaned up in finally; CASCADE wipes
 *              the child rows + collaborator grants along with the sample.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_api_sub_arrays.php
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
$sId = "smoketest-suba-$stamp";
$ids = array(array($sId, $ownerPkey));

$svc = new StraboSamplesService($db, $neodb);

/**
 * Per-resource config: which list/replace methods, required field, change_type,
 * and item factories. Keeps the per-resource asserts compact.
 */
$resources = array(
    'composition' => array(
        'list'     => 'listComposition',
        'replace'  => 'replaceComposition',
        'required' => 'mineral',
        'log_type' => 'composition_change',
        'items3'   => array(
            array('mineral' => 'quartz',    'fraction' => '60',  'unit' => 'Vol%'),
            array('mineral' => 'feldspar',  'fraction' => '30',  'unit' => 'Vol%'),
            array('mineral' => 'pyroxene',  'fraction' => '10',  'unit' => 'Vol%'),
        ),
        'items2'   => array(
            array('mineral' => 'quartz',    'fraction' => '70',  'unit' => 'Vol%'),
            array('mineral' => 'feldspar',  'fraction' => '30',  'unit' => 'Vol%'),
        ),
        'bad'      => array(
            array('other_mineral' => 'olivine'),  // missing 'mineral'
        ),
    ),
    'parameters' => array(
        'list'     => 'listParameters',
        'replace'  => 'replaceParameters',
        'required' => 'control',
        'log_type' => 'parameters_change',
        'items3'   => array(
            array('control' => 'temperature', 'value' => '800', 'unit' => 'C'),
            array('control' => 'pressure',    'value' => '1.5', 'unit' => 'GPa'),
            array('control' => 'duration',    'value' => '72',  'unit' => 'h'),
        ),
        'items2'   => array(
            array('control' => 'temperature', 'value' => '900', 'unit' => 'C'),
            array('control' => 'pressure',    'value' => '2.0', 'unit' => 'GPa'),
        ),
        'bad' => array(
            array('value' => 'no control field'),
        ),
    ),
    'documents' => array(
        'list'     => 'listDocuments',
        'replace'  => 'replaceDocuments',
        'required' => 'uuid',
        'log_type' => 'documents_change',
        'items3'   => array(
            array('uuid' => 'doc-aaa-' . $stamp, 'type' => 'image',  'format' => 'png', 'original_filename' => 'a.png'),
            array('uuid' => 'doc-bbb-' . $stamp, 'type' => 'report', 'format' => 'pdf', 'original_filename' => 'b.pdf'),
            array('uuid' => 'doc-ccc-' . $stamp, 'type' => 'data',   'format' => 'csv', 'original_filename' => 'c.csv'),
        ),
        'items2'   => array(
            array('uuid' => 'doc-aaa-' . $stamp, 'type' => 'image',  'format' => 'png', 'original_filename' => 'a.png'),
            array('uuid' => 'doc-ddd-' . $stamp, 'type' => 'report', 'format' => 'pdf', 'original_filename' => 'd.pdf'),
        ),
        'bad'      => array(
            array('type' => 'no uuid'),
        ),
    ),
);

try {
    // Seed the sample + accepted edit + readonly grants.
    $svc->setUserpkey($ownerPkey);
    $svc->createSample(array('id' => $sId, 'name' => 'subarray-smoke'));

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

    foreach ($resources as $name => $cfg) {
        echo "==================================================\n";
        echo "  resource: $name\n";
        echo "==================================================\n";

        $list = $cfg['list'];
        $replace = $cfg['replace'];

        echo "--- list (empty) ---\n";
        $svc->setUserpkey($ownerPkey);
        $r = $svc->$list($sId, $ownerPkey);
        check("returns wrapper",                              is_array($r) && array_key_exists($name, $r));
        check("count = 0",                                    $r['count'] === 0);

        echo "--- replace with 3 items (owner) ---\n";
        $r = $svc->$replace($sId, $ownerPkey, $cfg['items3']);
        check("replace returns ok",                            !empty($r['ok']));
        check("replace returns count 3",                       $r['count'] === 3);

        echo "--- list (3 items, properly ordered) ---\n";
        $r = $svc->$list($sId, $ownerPkey);
        check("count = 3",                                     $r['count'] === 3);
        check("first item required field matches",             isset($r[$name][0][$cfg['required']]) && $r[$name][0][$cfg['required']] === $cfg['items3'][0][$cfg['required']]);
        check("third item required field matches",             isset($r[$name][2][$cfg['required']]) && $r[$name][2][$cfg['required']] === $cfg['items3'][2][$cfg['required']]);

        echo "--- replace with 2 (count drops, no stragglers) ---\n";
        $r = $svc->$replace($sId, $ownerPkey, $cfg['items2']);
        check("replace returns ok",                            !empty($r['ok']));
        check("replace returns count 2",                       $r['count'] === 2);
        $check = $svc->$list($sId, $ownerPkey);
        check("list count = 2 after replace",                  $check['count'] === 2);
        check("stragglers gone (count matches)",               count($check[$name]) === 2);

        echo "--- replace with empty (all gone) ---\n";
        $r = $svc->$replace($sId, $ownerPkey, array());
        check("empty replace returns ok",                      !empty($r['ok']));
        $check = $svc->$list($sId, $ownerPkey);
        check("list count = 0 after empty replace",            $check['count'] === 0);

        echo "--- missing required field rejected ---\n";
        $r = $svc->$replace($sId, $ownerPkey, $cfg['bad']);
        check("returns 'missing_required_field'",              empty($r['ok']) && $r['error'] === 'missing_required_field');
        check("detail.field = " . $cfg['required'],            isset($r['detail']['field']) && $r['detail']['field'] === $cfg['required']);

        echo "--- editor can replace; readonly cannot ---\n";
        $svc->setUserpkey($editorPkey);
        $r = $svc->$replace($sId, $ownerPkey, $cfg['items3']);
        check("editor replace returns ok",                     !empty($r['ok']));
        $svc->setUserpkey($readerPkey);
        $r = $svc->$replace($sId, $ownerPkey, $cfg['items2']);
        check("reader replace refused 'forbidden'",            empty($r['ok']) && $r['error'] === 'forbidden');
        // Also: reader can still read.
        $r = $svc->$list($sId, $ownerPkey);
        check("reader can still list",                         is_array($r) && $r['count'] === 3);

        echo "--- changelog row for the latest replace ---\n";
        $svc->setUserpkey($ownerPkey);
        $clog = $db->get_row_prepared(
            "SELECT change_type, source_subsystem, changes::text AS changes_text
               FROM strabosamples.sample_changelog
              WHERE sample_id=$1 AND sample_userpkey=$2 AND change_type=$3
              ORDER BY pkey DESC LIMIT 1",
            array($sId, $ownerPkey, $cfg['log_type'])
        );
        check("latest $name changelog exists",                 $clog !== null);
        check("source_subsystem = 'samples_api'",              $clog && $clog->source_subsystem === 'samples_api');
        $changes = $clog ? json_decode($clog->changes_text, true) : null;
        check("changes diff has $name.old_count and new_count", isset($changes[$name]['old_count']) && isset($changes[$name]['new_count']));

        echo "\n";
    }

    echo "=== stranger gets 404 ===\n";
    $svc->setUserpkey(999999999);
    foreach ($resources as $name => $cfg) {
        $list = $cfg['list'];
        $r = $svc->$list($sId, $ownerPkey);
        check("stranger $list returns null",                   $r === null);
    }

} finally {
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
