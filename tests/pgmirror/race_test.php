<?php
/**
 * File: race_test.php
 * Description: Concurrent rebuilds of the Postgres search mirror (StraboSpot::buildPgDataset,
 *              buildPgProject). The StraboField web app posts /db/projectdatasetsspots with one
 *              spot per edit, several per 100 ms, and every post rebuilds the whole dataset
 *              mirror (delete dataset row, spots cascade, re-insert). Before the advisory lock,
 *              overlapping rebuilds deleted each other's rows (spot_dataset_fkey warnings) and
 *              left duplicate dataset / project rows in search.
 *
 *              R.1  first upload race: N parallel single-spot posts create a brand-new project
 *                   and dataset at once -> 1 project row, 1 dataset row.
 *              R.2  edit bursts (3 rounds): N parallel single-spot posts to the existing dataset
 *                   -> no FK warnings, 1 dataset row, every Neo4j spot mirrored exactly once.
 *              R.3  a duplicate dataset row (the legacy damage) heals on the next rebuild.
 *
 *              Hermetic: fixture ids use the '93737' prefix. Cleanup at start and in finally{}.
 *
 *              Run inside the container (needs the owner test user from
 *              tests/collaboration/setup_test_data.php):
 *                docker exec strabo-php php /srv/app/www/tests/pgmirror/race_test.php
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';

$failures = array();
function check($label, $cond) {
    global $failures;
    echo ($cond ? '  PASS' : '  FAIL') . "  $label\n";
    if (!$cond) $failures[] = $label;
}
function note($msg) { echo "        $msg\n"; }

$ownerEmail = 'owner@test.strabospot.org';
$password   = 'testpass123';
$ownerPkey  = (int)$db->get_var_prepared(
    "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE", array($ownerEmail));
if (!$ownerPkey) {
    echo "Test user not found. Run tests/collaboration/setup_test_data.php first.\n";
    exit(1);
}
$auth = "$ownerEmail:$password";

$PARALLEL   = 12;
$idBase     = "93737" . substr((string)time(), -4);   // 9 digits
$NEO_ID_MIN = 937370000000;
$NEO_ID_MAX = 937379999999;
$projectId  = (int)($idBase . "001");
$datasetId  = (int)($idBase . "002");
$ERROR_LOG  = '/var/log/apache2/error.log';

function postAsync($json, $auth) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'pgrace_');
    $cmd = "curl -s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' -u " . escapeshellarg($auth)
         . " -X POST -H 'Content-Type: application/json' -d " . escapeshellarg($json)
         . " http://localhost/db/projectdatasetsspots";
    $pipes = array();
    $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    return array('proc' => $proc, 'pipes' => $pipes, 'bodyFile' => $bodyFile);
}

function postWait(array $h) {
    $status = (int)stream_get_contents($h['pipes'][1]);
    fclose($h['pipes'][1]);
    fclose($h['pipes'][2]);
    proc_close($h['proc']);
    $body = file_get_contents($h['bodyFile']);
    @unlink($h['bodyFile']);
    return array('status' => $status, 'body' => $body);
}

// Same wire shape as the web app: the whole project/dataset wrapper around ONE spot.
function onePayload($projectId, $datasetId, $spotId, $n, $ts) {
    return json_encode(array('project' => array(
        'id'                 => $projectId,
        'description'        => array('project_name' => 'PG Mirror Race Project'),
        'modified_timestamp' => $ts,
        'datasets'           => array(array(
            'id'                 => $datasetId,
            'name'               => 'PG Mirror Race Dataset',
            'modified_timestamp' => $ts,
            'spots'              => array('features' => array(array(
                'type'       => 'Feature',
                'geometry'   => array('type' => 'Point', 'coordinates' => array(-100.0 + $n * 0.01, 38.0 + $n * 0.01)),
                'properties' => array('id' => $spotId, 'name' => "Race Spot $n", 'modified_timestamp' => $ts),
            ))),
        )),
    )));
}

// Fires one burst; returns the number of responses that were not 2xx or that carried a PHP warning.
function burst($projectId, $datasetId, array $spotIds, $auth) {
    $ts = (int)(microtime(true) * 1000);
    $handles = array();
    foreach ($spotIds as $n => $sid) $handles[] = postAsync(onePayload($projectId, $datasetId, $sid, $n, $ts), $auth);
    $bad = 0;
    $fkInBody = 0;
    foreach ($handles as $h) {
        $r = postWait($h);
        if ($r['status'] < 200 || $r['status'] > 299) $bad++;
        if (strpos($r['body'], 'spot_dataset_fkey') !== false) $fkInBody++;
    }
    return array($bad, $fkInBody);
}

function logSize($f) { clearstatcache(); return file_exists($f) ? filesize($f) : 0; }
function newFkLines($f, $from) {
    clearstatcache();
    if (!file_exists($f) || filesize($f) <= $from) return 0;
    $tail = file_get_contents($f, false, null, $from);
    return substr_count($tail, 'spot_dataset_fkey');
}

function mirrorState($db, $neodb, $ownerPkey, $projectId, $datasetId) {
    $s = array();
    $s['project_rows'] = (int)$db->get_var("select count(*) from project where user_pkey=$ownerPkey and strabo_project_id='$projectId'");
    $s['dataset_rows'] = (int)$db->get_var("select count(*) from dataset where user_pkey=$ownerPkey and strabo_dataset_id='$datasetId'");
    $s['pg_spots']     = (int)$db->get_var("select count(*) from spot s join dataset d on d.dataset_pkey=s.dataset_pkey
                                            where d.user_pkey=$ownerPkey and d.strabo_dataset_id='$datasetId'");
    $s['pg_distinct']  = (int)$db->get_var("select count(distinct s.strabo_spot_id) from spot s join dataset d on d.dataset_pkey=s.dataset_pkey
                                            where d.user_pkey=$ownerPkey and d.strabo_dataset_id='$datasetId'");
    $s['neo_spots']    = (int)$neodb->get_var("MATCH (d:Dataset {id:$datasetId, userpkey:$ownerPkey})-[:HAS_SPOT]->(s:Spot) RETURN count(s) AS c");
    return $s;
}

function cleanup($db, $neodb, $ownerPkey, $NEO_ID_MIN, $NEO_ID_MAX) {
    $db->query("delete from dataset where user_pkey=$ownerPkey and strabo_dataset_id like '93737%'");
    $db->query("delete from project where user_pkey=$ownerPkey and strabo_project_id like '93737%'");
    $db->query("DELETE FROM strabosamples.samples WHERE id LIKE '93737%'");
    $db->query("DELETE FROM vprojects WHERE projectid::text LIKE '93737%'");
    $neodb->query("MATCH (s:Spot) WHERE s.id >= $NEO_ID_MIN AND s.id <= $NEO_ID_MAX DETACH DELETE s");
    $neodb->query("MATCH (d:Dataset) WHERE d.id >= $NEO_ID_MIN AND d.id <= $NEO_ID_MAX DETACH DELETE d");
    $neodb->query("MATCH (p:Project) WHERE p.id >= $NEO_ID_MIN AND p.id <= $NEO_ID_MAX DETACH DELETE p");
}

cleanup($db, $neodb, $ownerPkey, $NEO_ID_MIN, $NEO_ID_MAX);
echo "owner=$ownerPkey project=$projectId dataset=$datasetId parallel=$PARALLEL\n\n";

try {
    // The bulk controller 404s an unknown project (the app creates it first), so seed the
    // Neo4j node only. The PG project row does not exist yet: R.1 still races its first insert.
    $neodb->query("CREATE (p:Project {id:$projectId, userpkey:$ownerPkey, desc_project_name:'PG Mirror Race Project'})");

    // =====================================================================
    echo "=== R.1: first upload race (new dataset + first PG project row, $PARALLEL single-spot posts at once) ===\n";
    $spotIds = array();
    for ($i = 0; $i < $PARALLEL; $i++) $spotIds[] = (int)($idBase . sprintf("%03d", 100 + $i));
    $logFrom = logSize($ERROR_LOG);
    list($bad, $fkBody) = burst($projectId, $datasetId, $spotIds, $auth);
    $st = mirrorState($db, $neodb, $ownerPkey, $projectId, $datasetId);
    note("non-2xx=$bad fk_in_body=$fkBody fk_in_log=" . newFkLines($ERROR_LOG, $logFrom) . " " . json_encode($st));
    check("R.1 all posts 2xx", $bad === 0);
    check("R.1 exactly 1 project row", $st['project_rows'] === 1);
    check("R.1 exactly 1 dataset row", $st['dataset_rows'] === 1);
    check("R.1 no spot_dataset_fkey warnings", $fkBody === 0 && newFkLines($ERROR_LOG, $logFrom) === 0);

    // =====================================================================
    echo "\n=== R.2: edit bursts on the existing dataset (3 rounds x $PARALLEL) ===\n";
    $all = $spotIds;
    for ($round = 1; $round <= 3; $round++) {
        // Half the posts edit existing spots, half add new ones (the web app does both).
        $ids = array_slice($all, 0, $PARALLEL / 2);
        for ($i = 0; $i < $PARALLEL / 2; $i++) $ids[] = (int)($idBase . sprintf("%03d", 100 + $round * 100 + $i));
        $all = array_values(array_unique(array_merge($all, $ids)));
        $logFrom = logSize($ERROR_LOG);
        list($bad, $fkBody) = burst($projectId, $datasetId, $ids, $auth);
        $fkLog = newFkLines($ERROR_LOG, $logFrom);
        $st = mirrorState($db, $neodb, $ownerPkey, $projectId, $datasetId);
        note("round $round: non-2xx=$bad fk_in_body=$fkBody fk_in_log=$fkLog " . json_encode($st));
        check("R.2.$round all posts 2xx", $bad === 0);
        check("R.2.$round no spot_dataset_fkey warnings", $fkBody === 0 && $fkLog === 0);
        check("R.2.$round exactly 1 dataset row", $st['dataset_rows'] === 1);
        check("R.2.$round Neo4j has every spot (" . count($all) . ")", $st['neo_spots'] === count($all));
        check("R.2.$round PG mirrors every Neo4j spot exactly once",
              $st['pg_spots'] === $st['neo_spots'] && $st['pg_distinct'] === $st['neo_spots']);
    }

    // =====================================================================
    echo "\n=== R.3: a legacy duplicate dataset row heals on the next rebuild ===\n";
    $row = $db->get_row("select * from dataset where user_pkey=$ownerPkey and strabo_dataset_id='$datasetId' limit 1");
    $dupPkey = (int)$db->get_var("select nextval('dataset_dataset_pkey_seq')");
    $db->query("insert into dataset values ($dupPkey, {$row->project_pkey}, $ownerPkey, 'dup', '$datasetId', null)");
    check("R.3 duplicate planted (2 rows)", (int)$db->get_var("select count(*) from dataset where user_pkey=$ownerPkey and strabo_dataset_id='$datasetId'") === 2);
    list($bad, $fkBody) = burst($projectId, $datasetId, array($all[0]), $auth);
    $st = mirrorState($db, $neodb, $ownerPkey, $projectId, $datasetId);
    note(json_encode($st));
    check("R.3 back to 1 dataset row", $st['dataset_rows'] === 1);
    check("R.3 PG spots = Neo4j spots", $st['pg_spots'] === $st['neo_spots']);

} finally {
    cleanup($db, $neodb, $ownerPkey, $NEO_ID_MIN, $NEO_ID_MAX);
}

echo "\n" . (count($failures) ? count($failures) . " FAILURE(S):\n  - " . implode("\n  - ", $failures) : "ALL PASS") . "\n";
exit(count($failures) ? 1 : 0);
