<?php
/**
 * File: tests/strabosamples/smoke_test_auth_matrix.php
 * Description: Phase C of the pre-cutover test plan — auth + access pivots.
 *              Programmatically walks the full actor × action matrix from
 *              docs/StraboSamples/pre_cutover_test_plan.md §Phase C across
 *              all three auth surfaces:
 *
 *                1. REST API   — /samplesdb/* via HTTP Basic Auth
 *                2. Proxies    — /samples_{edit,collab,changelog,family,
 *                                invitations}.php via forged PHP sessions
 *                3. Detail page — /samples_detail.php (+ /samples/{o}/{id}
 *                                rewrite) — the §12.2 public share URL
 *
 *              Actors:
 *                owner      — owner@test.strabospot.org
 *                editor     — editor@test.strabospot.org   (accepted, edit)
 *                readonly   — readonly@test.strabospot.org (accepted, readonly)
 *                stranger   — outsider@test.strabospot.org (no grant, Parts 4/7/8)
 *                pending    — outsider, after an un-accepted invite (Part 5)
 *                anonymous  — no session / bad Basic creds (Part 6)
 *
 *              Matrix expectations (per implementation, asserted here):
 *                - Reads (GET sample/changelog/family/composition) gate on
 *                  canRead; denial → 404 (defensive — hides existence).
 *                - Mutations gate on canEdit/canManage; denial on an
 *                  existing sample → 403.
 *                - Detail page is public-read for any LOGGED-IN user
 *                  (§12.2: the share URL is the canonical detail URL);
 *                  anonymous → 302 to /login.php. Edit/Collaborate
 *                  affordances driven by the embedded payload's
 *                  permissions.{isOwner,canEdit} flags.
 *
 *              C.3 — share-URL E2E for a logged-in stranger (Part 7).
 *              C.4 — cross-system "View <Subsystem>" gates (Part 8):
 *                field → always linked (Field page self-gates);
 *                micro/exp → view_href iff host project ispublic OR viewer
 *                owns it, else view_unavailable. Includes the
 *                (strabo_id, userpkey) disambiguation trap: a SECOND
 *                micro_projectmetadata row with the SAME strabo_id but a
 *                different userpkey and ispublic=TRUE must NOT leak a
 *                view_href onto a link that references the private copy.
 *
 *              IDs use the 'authmx-' prefix; cleanup targets that prefix
 *              and runs at start + in finally{} so the suite is hermetic.
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/strabosamples/smoke_test_auth_matrix.php
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/includes/UUID.php';
require_once '/srv/app/www/samplesdb/services/StraboSamplesService.php';

if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

// -------------------------------------------------------------------------
// Test framework
// -------------------------------------------------------------------------

$failures = array();
function check($label, $cond) {
    global $failures;
    $mark = $cond ? '  PASS' : '  FAIL';
    echo "$mark  $label\n";
    if (!$cond) $failures[] = $label;
}

// -------------------------------------------------------------------------
// Test users — provisioned by tests/collaboration/setup_test_data.php
// -------------------------------------------------------------------------

$password = 'testpass123';
$users = array();
foreach (array('owner', 'editor', 'readonly', 'outsider') as $who) {
    $email = $who . '@test.strabospot.org';
    $pkey = (int)$db->get_var_prepared(
        "SELECT pkey FROM users WHERE email=$1 AND active=TRUE AND deleted=FALSE",
        array($email)
    );
    if (!$pkey) {
        echo "Test user $email not found. Run tests/collaboration/setup_test_data.php first.\n";
        exit(1);
    }
    $users[$who] = array('email' => $email, 'pkey' => $pkey);
}
$ownerPkey    = $users['owner']['pkey'];
$editorPkey   = $users['editor']['pkey'];
$readonlyPkey = $users['readonly']['pkey'];
$outsiderPkey = $users['outsider']['pkey'];
echo "owner=$ownerPkey editor=$editorPkey readonly=$readonlyPkey outsider=$outsiderPkey\n\n";

// -------------------------------------------------------------------------
// HTTP wrappers
// -------------------------------------------------------------------------

function httpBasic($method, $path, $jsonBody, $email, $password) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'authmx_body_');
    $args = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    if ($email !== null) {
        $args .= "-u " . escapeshellarg("$email:$password") . " ";
    }
    $args .= "-X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) {
        $args .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
        $args .= "-d " . escapeshellarg($jsonBody) . " ";
    }
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

function httpSession($method, $path, $jsonBody, $sid) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'authmx_body_');
    $args = "-s -o " . escapeshellarg($bodyFile) . " -w '%{http_code}' ";
    if ($sid !== null) {
        $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    }
    $args .= "-X " . escapeshellarg($method) . " ";
    if ($jsonBody !== null) {
        $args .= "-H " . escapeshellarg('Content-Type: application/json') . " ";
        $args .= "-d " . escapeshellarg($jsonBody) . " ";
    }
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body = file_get_contents($bodyFile);
    @unlink($bodyFile);
    return array('status' => $status, 'body' => $body, 'json' => json_decode($body, true));
}

/**
 * GET a full page (no -L). Returns status, body, and the Location header
 * (empty string when none) so redirect-to-login can be asserted.
 */
function httpPage($path, $sid) {
    $bodyFile = tempnam(sys_get_temp_dir(), 'authmx_page_');
    $hdrFile  = tempnam(sys_get_temp_dir(), 'authmx_hdr_');
    $args = "-s -o " . escapeshellarg($bodyFile) . " -D " . escapeshellarg($hdrFile) . " -w '%{http_code}' ";
    if ($sid !== null) {
        $args .= "-H " . escapeshellarg("Cookie: PHPSESSID=$sid") . " ";
    }
    $args .= escapeshellarg("http://localhost" . $path);
    $status = (int)shell_exec("curl $args");
    $body = file_get_contents($bodyFile);
    $location = '';
    foreach (explode("\n", (string)file_get_contents($hdrFile)) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }
    @unlink($bodyFile);
    @unlink($hdrFile);
    return array('status' => $status, 'body' => $body, 'location' => $location);
}

/** Extract the server-embedded payload from a samples_detail.php render. */
function parseSdPayload($html) {
    if (!preg_match('#<script type="application/json" id="sd-data">(.*?)</script>#s', $html, $m)) {
        return null;
    }
    return json_decode($m[1], true);
}

/** Find a link card in the payload by subsystem + reference_id. */
function findLink($payload, $subsystem, $referenceId) {
    if (!is_array($payload) || empty($payload['links'])) return null;
    foreach ($payload['links'] as $l) {
        if ($l['subsystem'] === $subsystem && (string)$l['reference_id'] === (string)$referenceId) {
            return $l;
        }
    }
    return null;
}

// -------------------------------------------------------------------------
// Forged sessions — same harness as the Phase 4 proxy smokes
// -------------------------------------------------------------------------

$sessionDir = '/var/lib/php/sessions';
$sessionFiles = array();
function forgeSession($pkey) {
    global $sessionDir, $sessionFiles;
    $sid  = substr(bin2hex(random_bytes(16)), 0, 26);
    $path = $sessionDir . '/sess_' . $sid;
    $body = 'loggedin|s:3:"yes";userpkey|i:' . $pkey . ';LAST_ACTIVITY|i:' . time() . ';';
    file_put_contents($path, $body);
    chmod($path, 0600);
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    $sessionFiles[] = $path;
    return $sid;
}

// -------------------------------------------------------------------------
// Fixture ids + cleanup
// -------------------------------------------------------------------------

$stamp = time();
$idParent = "authmx-parent-$stamp";
$idMain   = "authmx-main-$stamp";
$idChild  = "authmx-child-$stamp";
$idDisp   = "authmx-disp-$stamp";

$microPrivSid = "authmx-mpriv-$stamp";   // private, owner's — ALSO the decoy's strabo_id
$microPubSid  = "authmx-mpub-$stamp";    // public, owner's
$expPrivUuid  = "authmx-expriv-$stamp";
$expPubUuid   = "authmx-exppub-$stamp";
$projPrivUuid = "authmx-eppriv-$stamp";
$projPubUuid  = "authmx-eppub-$stamp";

function authmxCleanup($db) {
    // Samples first (CASCADE clears collaborators/links/changelog/children rows).
    $db->prepare_query("DELETE FROM strabosamples.samples WHERE id LIKE 'authmx-%'", array());
    $db->prepare_query("DELETE FROM micro_projectmetadata WHERE strabo_id LIKE 'authmx-%'", array());
    $db->prepare_query("DELETE FROM straboexp.experiment WHERE uuid LIKE 'authmx-%'", array());
    $db->prepare_query("DELETE FROM straboexp.project WHERE uuid LIKE 'authmx-%'", array());
}

authmxCleanup($db);   // catch a previous crashed run

try {

// -------------------------------------------------------------------------
// Part 0 — fixtures
// -------------------------------------------------------------------------

echo "--- Part 0: fixtures ---\n";

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($ownerPkey);

$r = $svc->createSample(array('id' => $idParent, 'name' => 'AuthMx Parent'));
check("fixture: parent sample created", !empty($r['ok']));
$r = $svc->createSample(array(
    'id' => $idMain, 'name' => 'AuthMx Main Sample',
    'igsn' => 'AUTHMX0001', 'description' => 'Auth matrix fixture',
    'notes' => 'baseline', 'latitude' => 39.05, 'longitude' => -95.7,
    'display_sample_type' => 'Intact Sample',
    'parent_sample_id' => $idParent, 'parent_userpkey' => $ownerPkey,
));
check("fixture: main sample created", !empty($r['ok']));
$r = $svc->createSample(array(
    'id' => $idChild, 'name' => 'AuthMx Child',
    'parent_sample_id' => $idMain, 'parent_userpkey' => $ownerPkey,
));
check("fixture: child sample created", !empty($r['ok']));
$r = $svc->createSample(array('id' => $idDisp, 'name' => 'AuthMx Disposable'));
check("fixture: disposable sample created", !empty($r['ok']));

// Accepted grants: editor (edit) + readonly (readonly) on main + disposable.
foreach (array($idMain, $idDisp) as $sid) {
    foreach (array(array($editorPkey, 'edit'), array($readonlyPkey, 'readonly')) as $g) {
        $db->prepare_query(
            "INSERT INTO strabosamples.sample_collaborators
               (sample_id, sample_userpkey, collaborator_pkey, permission_level,
                uuid, accepted, accepted_at, added_by)
             VALUES ($1, $2, $3, $4, $5, TRUE, now(), $6)",
            array($sid, $ownerPkey, $g[0], $g[1], UUID::v4(), $ownerPkey)
        );
    }
}
$grantCount = (int)$db->get_var_prepared(
    "SELECT COUNT(*) FROM strabosamples.sample_collaborators
      WHERE sample_id LIKE 'authmx-%' AND accepted=TRUE AND removed_at IS NULL",
    array()
);
check("fixture: 4 accepted grants seeded", $grantCount === 4);

// Micro host projects: private (owner's), public (owner's), and the
// disambiguation decoy — SAME strabo_id as the private one, but owned by
// editor and PUBLIC. The link below references the OWNER's private copy;
// the decoy must not leak a view_href onto it.
// NOTE: the ezSQL wrapper does not fetch INSERT…RETURNING rows, so insert
// first, then SELECT the generated id back by natural key.
$db->prepare_query(
    "INSERT INTO micro_projectmetadata (strabo_id, userpkey, name, ispublic)
     VALUES ($1, $2, 'AuthMx Micro Private', FALSE)",
    array($microPrivSid, $ownerPkey)
);
$db->prepare_query(
    "INSERT INTO micro_projectmetadata (strabo_id, userpkey, name, ispublic)
     VALUES ($1, $2, 'AuthMx Micro Public', TRUE)",
    array($microPubSid, $ownerPkey)
);
$db->prepare_query(
    "INSERT INTO micro_projectmetadata (strabo_id, userpkey, name, ispublic)
     VALUES ($1, $2, 'AuthMx Micro Decoy (public, same strabo_id)', TRUE)",
    array($microPrivSid, $editorPkey)
);
$microPrivId = (int)$db->get_var_prepared(
    "SELECT id FROM micro_projectmetadata WHERE strabo_id=$1 AND userpkey=$2",
    array($microPrivSid, $ownerPkey)
);
$microPubId = (int)$db->get_var_prepared(
    "SELECT id FROM micro_projectmetadata WHERE strabo_id=$1 AND userpkey=$2",
    array($microPubSid, $ownerPkey)
);
$microDecoyId = (int)$db->get_var_prepared(
    "SELECT id FROM micro_projectmetadata WHERE strabo_id=$1 AND userpkey=$2",
    array($microPrivSid, $editorPkey)
);
check("fixture: 3 micro host projects", $microPrivId > 0 && $microPubId > 0 && $microDecoyId > 0);

// Experimental host projects + experiments: private and public.
$db->prepare_query(
    "INSERT INTO straboexp.project (userpkey, uuid, name, ispublic)
     VALUES ($1, $2, 'AuthMx Exp Private', FALSE)",
    array($ownerPkey, $projPrivUuid)
);
$db->prepare_query(
    "INSERT INTO straboexp.project (userpkey, uuid, name, ispublic)
     VALUES ($1, $2, 'AuthMx Exp Public', TRUE)",
    array($ownerPkey, $projPubUuid)
);
$projPrivPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM straboexp.project WHERE uuid=$1", array($projPrivUuid));
$projPubPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM straboexp.project WHERE uuid=$1", array($projPubUuid));
$db->prepare_query(
    "INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid)
     VALUES ($1, $2, 'authmx-exp', $3)",
    array($projPrivPkey, $ownerPkey, $expPrivUuid)
);
$db->prepare_query(
    "INSERT INTO straboexp.experiment (project_pkey, userpkey, id, uuid)
     VALUES ($1, $2, 'authmx-exp', $3)",
    array($projPubPkey, $ownerPkey, $expPubUuid)
);
$expPrivPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM straboexp.experiment WHERE uuid=$1", array($expPrivUuid));
$expPubPkey = (int)$db->get_var_prepared(
    "SELECT pkey FROM straboexp.experiment WHERE uuid=$1", array($expPubUuid));
check("fixture: 2 exp projects + 2 experiments", $expPrivPkey > 0 && $expPubPkey > 0);

// Subsystem links on the main sample — one field, two micro, two exp.
$linkSpecs = array(
    array('field',        'authmx-spot-1',        json_encode(array('dataset_id' => 'authmx-ds-1'))),
    array('micro',        (string)$microPrivId,   json_encode(array('project_strabo_id' => $microPrivSid))),
    array('micro',        (string)$microPubId,    json_encode(array('project_strabo_id' => $microPubSid))),
    array('experimental', 'authmx-exp-priv-ref',  json_encode(array('experiment_uuid' => $expPrivUuid))),
    array('experimental', 'authmx-exp-pub-ref',   json_encode(array('experiment_uuid' => $expPubUuid))),
);
foreach ($linkSpecs as $ls) {
    $db->prepare_query(
        "INSERT INTO strabosamples.sample_subsystem_links
           (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
         VALUES ($1, $2, $3, $4, $5, $6)",
        array($idMain, $ownerPkey, $ls[0], $ls[1], $ownerPkey, $ls[2])
    );
}
$linkCount = (int)$db->get_var_prepared(
    "SELECT COUNT(*) FROM strabosamples.sample_subsystem_links WHERE sample_id=$1 AND sample_userpkey=$2",
    array($idMain, $ownerPkey)
);
check("fixture: 5 subsystem links seeded", $linkCount === 5);

// Sessions for every actor.
$sidOwner    = forgeSession($ownerPkey);
$sidEditor   = forgeSession($editorPkey);
$sidReadonly = forgeSession($readonlyPkey);
$sidOutsider = forgeSession($outsiderPkey);

$ownerEmail    = $users['owner']['email'];
$editorEmail   = $users['editor']['email'];
$readonlyEmail = $users['readonly']['email'];
$outsiderEmail = $users['outsider']['email'];

$restMain = "/samplesdb/sample/$idMain";
$restDisp = "/samplesdb/sample/$idDisp";
$pageMain = "/samples_detail.php?owner=$ownerPkey&id=" . rawurlencode($idMain);

// -------------------------------------------------------------------------
// Part 1 — OWNER: everything allowed
// -------------------------------------------------------------------------

echo "\n--- Part 1: owner — full access ---\n";

$r = httpBasic('GET', $restMain, null, $ownerEmail, $password);
check("owner REST GET sample → 200 + correct id",
    $r['status'] === 200 && is_array($r['json']) && $r['json']['id'] === $idMain);

$r = httpBasic('GET', "$restMain/changelog", null, $ownerEmail, $password);
check("owner REST GET changelog → 200 + changelog array",
    $r['status'] === 200 && isset($r['json']['changelog']) && is_array($r['json']['changelog']));

$r = httpBasic('GET', "$restMain/family", null, $ownerEmail, $password);
check("owner REST GET family → 200 + parent + child resolved",
    $r['status'] === 200
    && isset($r['json']['focus']['id']) && $r['json']['focus']['id'] === $idMain
    && isset($r['json']['parent']['id']) && $r['json']['parent']['id'] === $idParent
    && isset($r['json']['children'][0]['id']) && $r['json']['children'][0]['id'] === $idChild);

$r = httpBasic('GET', "$restMain/composition", null, $ownerEmail, $password);
check("owner REST GET composition → 200", $r['status'] === 200);

$r = httpBasic('PUT', $restMain, json_encode(array('notes' => 'owner-rest-edit')), $ownerEmail, $password);
check("owner REST PUT sample → 200 ok", $r['status'] === 200 && !empty($r['json']['ok']));
$v = $db->get_var_prepared(
    "SELECT notes FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
    array($idMain, $ownerPkey)
);
check("owner REST PUT persisted notes", $v === 'owner-rest-edit');

// canManage: set readonly collaborator's level (same value — exercise the gate).
$r = httpBasic('PUT', "$restMain/collaborator/$readonlyPkey",
    json_encode(array('permission_level' => 'readonly')), $ownerEmail, $password);
check("owner REST PUT collaborator level → 200 ok", $r['status'] === 200 && !empty($r['json']['ok']));

$r = httpSession('POST', '/samples_edit.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey, 'description' => 'owner-proxy-edit')),
    $sidOwner);
check("owner proxy edit → 200 ok", $r['status'] === 200 && !empty($r['json']['ok']));

$r = httpSession('POST', '/samples_changelog.php',
    json_encode(array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidOwner);
check("owner proxy changelog list → 200", $r['status'] === 200 && !empty($r['json']['ok']));

$r = httpSession('POST', '/samples_family.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidOwner);
check("owner proxy family → 200", $r['status'] === 200 && !empty($r['json']['ok']));

$r = httpSession('POST', '/samples_collab.php',
    json_encode(array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidOwner);
$collabEmails = array();
if (!empty($r['json']['collaborators'])) {
    foreach ($r['json']['collaborators'] as $c) $collabEmails[] = $c['email'];
}
check("owner proxy collab list → 200 + sees both accepted grants",
    $r['status'] === 200 && in_array($editorEmail, $collabEmails) && in_array($readonlyEmail, $collabEmails));

$p = httpPage($pageMain, $sidOwner);
$payload = parseSdPayload($p['body']);
check("owner detail page → 200 + payload", $p['status'] === 200 && is_array($payload));
check("owner detail page → isOwner=true canEdit=true",
    is_array($payload) && !empty($payload['permissions']['isOwner']) && !empty($payload['permissions']['canEdit']));

// -------------------------------------------------------------------------
// Part 2 — ACCEPTED EDIT COLLAB: read + edit yes; delete + manage no
// -------------------------------------------------------------------------

echo "\n--- Part 2: accepted edit collaborator ---\n";

$r = httpBasic('GET', "$restMain?owner=$ownerPkey", null, $editorEmail, $password);
check("editor REST GET sample → 200", $r['status'] === 200 && is_array($r['json']) && $r['json']['id'] === $idMain);

$r = httpBasic('GET', "$restMain/changelog?owner=$ownerPkey", null, $editorEmail, $password);
check("editor REST GET changelog → 200", $r['status'] === 200 && isset($r['json']['changelog']));

$r = httpBasic('PUT', "$restMain?owner=$ownerPkey",
    json_encode(array('notes' => 'editor-rest-edit')), $editorEmail, $password);
check("editor REST PUT sample → 200 ok", $r['status'] === 200 && !empty($r['json']['ok']));
$v = $db->get_var_prepared(
    "SELECT notes FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
    array($idMain, $ownerPkey)
);
check("editor REST PUT persisted notes", $v === 'editor-rest-edit');

$r = httpBasic('DELETE', "$restDisp?owner=$ownerPkey", null, $editorEmail, $password);
check("editor REST DELETE sample → 403 forbidden",
    $r['status'] === 403 && isset($r['json']['Error']) && $r['json']['Error'] === 'forbidden');
$v = $db->get_var_prepared(
    "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
    array($idDisp, $ownerPkey)
);
check("editor REST DELETE left the row intact", (bool)$v);

$r = httpBasic('POST', "$restMain/collaborators?owner=$ownerPkey",
    json_encode(array('emails' => array($outsiderEmail), 'permission_level' => 'readonly')),
    $editorEmail, $password);
check("editor REST POST collaborators (invite) → 403", $r['status'] === 403);

$r = httpBasic('PUT', "$restMain/collaborator/$readonlyPkey?owner=$ownerPkey",
    json_encode(array('permission_level' => 'edit')), $editorEmail, $password);
check("editor REST PUT collaborator level → 403", $r['status'] === 403);
$v = $db->get_var_prepared(
    "SELECT permission_level FROM strabosamples.sample_collaborators
      WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3 AND removed_at IS NULL",
    array($idMain, $ownerPkey, $readonlyPkey)
);
check("editor manage attempt left readonly grant untouched", $v === 'readonly');

$r = httpSession('POST', '/samples_edit.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey, 'description' => 'editor-proxy-edit')),
    $sidEditor);
check("editor proxy edit → 200 ok", $r['status'] === 200 && !empty($r['json']['ok']));

$r = httpSession('POST', '/samples_changelog.php',
    json_encode(array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidEditor);
check("editor proxy changelog → 200", $r['status'] === 200 && !empty($r['json']['ok']));

$r = httpSession('POST', '/samples_family.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidEditor);
check("editor proxy family → 200", $r['status'] === 200 && !empty($r['json']['ok']));

$r = httpSession('POST', '/samples_collab.php',
    json_encode(array('action' => 'invite', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey,
                      'emails' => array($outsiderEmail), 'permission_level' => 'readonly')),
    $sidEditor);
check("editor proxy collab invite → 403", $r['status'] === 403);

$p = httpPage($pageMain, $sidEditor);
$payload = parseSdPayload($p['body']);
check("editor detail page → isOwner=false canEdit=true",
    $p['status'] === 200 && is_array($payload)
    && empty($payload['permissions']['isOwner']) && !empty($payload['permissions']['canEdit']));

// -------------------------------------------------------------------------
// Part 3 — ACCEPTED READONLY COLLAB: read yes; edit/delete/manage no
// -------------------------------------------------------------------------

echo "\n--- Part 3: accepted readonly collaborator ---\n";

$r = httpBasic('GET', "$restMain?owner=$ownerPkey", null, $readonlyEmail, $password);
check("readonly REST GET sample → 200", $r['status'] === 200 && is_array($r['json']) && $r['json']['id'] === $idMain);

$r = httpBasic('GET', "$restMain/changelog?owner=$ownerPkey", null, $readonlyEmail, $password);
check("readonly REST GET changelog → 200", $r['status'] === 200 && isset($r['json']['changelog']));

$r = httpBasic('GET', "$restMain/family?owner=$ownerPkey", null, $readonlyEmail, $password);
check("readonly REST GET family → 200", $r['status'] === 200 && isset($r['json']['focus']));

$r = httpBasic('PUT', "$restMain?owner=$ownerPkey",
    json_encode(array('notes' => 'readonly-rest-edit-SHOULD-NOT-LAND')), $readonlyEmail, $password);
check("readonly REST PUT sample → 403", $r['status'] === 403);
$v = $db->get_var_prepared(
    "SELECT notes FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
    array($idMain, $ownerPkey)
);
check("readonly REST PUT did not land", $v === 'editor-rest-edit');

$r = httpBasic('PUT', "$restMain/composition?owner=$ownerPkey",
    json_encode(array('items' => array())), $readonlyEmail, $password);
check("readonly REST PUT composition → 403", $r['status'] === 403);

$r = httpBasic('PUT', "$restMain/parent?owner=$ownerPkey",
    json_encode(array('parent_sample_id' => $idParent, 'parent_userpkey' => $ownerPkey)),
    $readonlyEmail, $password);
check("readonly REST PUT parent → 403", $r['status'] === 403);

$r = httpBasic('DELETE', "$restDisp?owner=$ownerPkey", null, $readonlyEmail, $password);
check("readonly REST DELETE sample → 403", $r['status'] === 403);

$r = httpBasic('POST', "$restMain/collaborators?owner=$ownerPkey",
    json_encode(array('emails' => array($outsiderEmail), 'permission_level' => 'readonly')),
    $readonlyEmail, $password);
check("readonly REST POST collaborators (invite) → 403", $r['status'] === 403);

$r = httpSession('POST', '/samples_edit.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey, 'description' => 'readonly-proxy-edit')),
    $sidReadonly);
check("readonly proxy edit → 403", $r['status'] === 403);

$r = httpSession('POST', '/samples_changelog.php',
    json_encode(array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidReadonly);
check("readonly proxy changelog → 200", $r['status'] === 200 && !empty($r['json']['ok']));

$r = httpSession('POST', '/samples_family.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidReadonly);
check("readonly proxy family → 200", $r['status'] === 200 && !empty($r['json']['ok']));

$p = httpPage($pageMain, $sidReadonly);
$payload = parseSdPayload($p['body']);
check("readonly detail page → isOwner=false canEdit=false",
    $p['status'] === 200 && is_array($payload)
    && empty($payload['permissions']['isOwner']) && empty($payload['permissions']['canEdit']));

// -------------------------------------------------------------------------
// Part 4 — LOGGED-IN STRANGER (no grant): API/proxies deny; page is the
//          only read surface (§12.2 share URL)
// -------------------------------------------------------------------------

echo "\n--- Part 4: logged-in stranger (no grant) ---\n";

$r = httpBasic('GET', "$restMain?owner=$ownerPkey", null, $outsiderEmail, $password);
check("stranger REST GET sample → 404 (existence hidden)", $r['status'] === 404);

$r = httpBasic('GET', "$restMain/changelog?owner=$ownerPkey", null, $outsiderEmail, $password);
check("stranger REST GET changelog → 404", $r['status'] === 404);

$r = httpBasic('GET', "$restMain/family?owner=$ownerPkey", null, $outsiderEmail, $password);
check("stranger REST GET family → 404", $r['status'] === 404);

$r = httpBasic('GET', "$restMain/composition?owner=$ownerPkey", null, $outsiderEmail, $password);
check("stranger REST GET composition → 404", $r['status'] === 404);

$r = httpBasic('PUT', "$restMain?owner=$ownerPkey",
    json_encode(array('notes' => 'stranger-edit-SHOULD-NOT-LAND')), $outsiderEmail, $password);
check("stranger REST PUT sample denied (403)", $r['status'] === 403);

$r = httpBasic('DELETE', "$restDisp?owner=$ownerPkey", null, $outsiderEmail, $password);
check("stranger REST DELETE sample denied (403)", $r['status'] === 403);

$r = httpBasic('POST', "$restMain/collaborators?owner=$ownerPkey",
    json_encode(array('emails' => array($outsiderEmail), 'permission_level' => 'edit')),
    $outsiderEmail, $password);
check("stranger REST POST collaborators (self-invite) → 403", $r['status'] === 403);

$r = httpSession('POST', '/samples_edit.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey, 'description' => 'stranger-proxy-edit')),
    $sidOutsider);
check("stranger proxy edit → 403", $r['status'] === 403);

$r = httpSession('POST', '/samples_changelog.php',
    json_encode(array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidOutsider);
check("stranger proxy changelog → 404", $r['status'] === 404);

$r = httpSession('POST', '/samples_family.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidOutsider);
check("stranger proxy family → 404", $r['status'] === 404);

$r = httpSession('POST', '/samples_collab.php',
    json_encode(array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidOutsider);
check("stranger proxy collab list → 403", $r['status'] === 403);

$v = $db->get_var_prepared(
    "SELECT notes FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
    array($idMain, $ownerPkey)
);
check("no stranger mutation landed", $v === 'editor-rest-edit');

// -------------------------------------------------------------------------
// Part 5 — PENDING INVITE: invite exists but not accepted → still no access
// -------------------------------------------------------------------------

echo "\n--- Part 5: pending invitee ---\n";

// Owner invites the outsider (also the owner's canManage YES cell for invite).
$r = httpBasic('POST', "$restMain/collaborators",
    json_encode(array('emails' => array($outsiderEmail), 'permission_level' => 'readonly')),
    $ownerEmail, $password);
$inviteStatus = isset($r['json']['results'][0]['status']) ? $r['json']['results'][0]['status'] : null;
check("owner REST POST collaborators (invite outsider) → 200 invited",
    $r['status'] === 200 && !empty($r['json']['ok']) && $inviteStatus === 'invited');

$v = $db->get_row_prepared(
    "SELECT accepted, removed_at FROM strabosamples.sample_collaborators
      WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3 AND removed_at IS NULL",
    array($idMain, $ownerPkey, $outsiderPkey)
);
check("invite row is pending (accepted=FALSE)", $v && ($v->accepted === 'f' || $v->accepted === false));

// Positive control: invitee sees the invite in their list…
$r = httpSession('POST', '/samples_invitations.php',
    json_encode(array('action' => 'list')), $sidOutsider);
$foundInvite = false;
if (!empty($r['json']['invitations'])) {
    foreach ($r['json']['invitations'] as $i) {
        if ($i['sample_id'] === $idMain) $foundInvite = true;
    }
}
check("pending invitee sees the invite in invitations list", $r['status'] === 200 && $foundInvite);

// …but still has ZERO access until accept.
$r = httpBasic('GET', "$restMain?owner=$ownerPkey", null, $outsiderEmail, $password);
check("pending REST GET sample → 404", $r['status'] === 404);

$r = httpBasic('GET', "$restMain/changelog?owner=$ownerPkey", null, $outsiderEmail, $password);
check("pending REST GET changelog → 404", $r['status'] === 404);

$r = httpBasic('PUT', "$restMain?owner=$ownerPkey",
    json_encode(array('notes' => 'pending-edit-SHOULD-NOT-LAND')), $outsiderEmail, $password);
check("pending REST PUT sample denied (403)", $r['status'] === 403);

$r = httpSession('POST', '/samples_edit.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey, 'description' => 'pending-proxy-edit')),
    $sidOutsider);
check("pending proxy edit → 403", $r['status'] === 403);

$r = httpSession('POST', '/samples_changelog.php',
    json_encode(array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidOutsider);
check("pending proxy changelog → 404", $r['status'] === 404);

$r = httpSession('POST', '/samples_family.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey)),
    $sidOutsider);
check("pending proxy family → 404", $r['status'] === 404);

$p = httpPage($pageMain, $sidOutsider);
$payload = parseSdPayload($p['body']);
check("pending detail page → isOwner=false canEdit=false",
    $p['status'] === 200 && is_array($payload)
    && empty($payload['permissions']['isOwner']) && empty($payload['permissions']['canEdit']));

// Pending collaborator must NOT appear in the page's public collaborator list.
$pendingListed = false;
if (is_array($payload) && !empty($payload['collaborators'])) {
    foreach ($payload['collaborators'] as $c) {
        if ((int)$c['pkey'] === $outsiderPkey) $pendingListed = true;
    }
}
check("pending invitee not in page collaborator list", is_array($payload) && !$pendingListed);

// -------------------------------------------------------------------------
// Part 6 — ANONYMOUS: REST 401, proxies 401, page redirects to login
// -------------------------------------------------------------------------

echo "\n--- Part 6: anonymous ---\n";

$r = httpBasic('GET', "$restMain?owner=$ownerPkey", null, $ownerEmail, 'wrong-password');
check("anon REST (bad password) → 401", $r['status'] === 401);

$r = httpBasic('GET', "$restMain?owner=$ownerPkey", null, null, null);
check("anon REST (no credentials) → 401", $r['status'] === 401);

foreach (array(
    'samples_edit.php'      => array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey, 'notes' => 'x'),
    'samples_collab.php'    => array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey),
    'samples_changelog.php' => array('action' => 'list', 'sample_id' => $idMain, 'owner_pkey' => $ownerPkey),
    'samples_family.php'    => array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey),
    'samples_invitations.php' => array('action' => 'list'),
) as $proxy => $body) {
    $r = httpSession('POST', "/$proxy", json_encode($body), null);
    check("anon proxy $proxy → 401",
        $r['status'] === 401 && isset($r['json']['error']) && $r['json']['error'] === 'not_authenticated');
}

$p = httpPage($pageMain, null);
check("anon detail page → 302 redirect to /login.php",
    $p['status'] === 302 && strpos($p['location'], 'login.php') !== false);

$p = httpPage($pageMain, substr(bin2hex(random_bytes(16)), 0, 26));   // garbage SID
check("garbage-session detail page → 302 redirect to /login.php",
    $p['status'] === 302 && strpos($p['location'], 'login.php') !== false);

// -------------------------------------------------------------------------
// Part 7 — C.3: public share URL E2E for the logged-in stranger
// -------------------------------------------------------------------------

echo "\n--- Part 7: C.3 share URL E2E (stranger) ---\n";

// The canonical share URL is the rewrite /samples/{owner}/{id}.
$p = httpPage("/samples/$ownerPkey/" . rawurlencode($idMain), $sidOutsider);
$payload = parseSdPayload($p['body']);
check("share URL (rewrite path) → 200, no redirect", $p['status'] === 200 && $p['location'] === '');
check("share URL → payload parsed", is_array($payload));

// (description was legitimately mutated by the owner/editor edits in
// Parts 1–2 — assert the immutable identity fields.)
check("stranger sees metadata (name, igsn)",
    is_array($payload)
    && $payload['sample']['name'] === 'AuthMx Main Sample'
    && $payload['sample']['igsn'] === 'AUTHMX0001');

check("stranger sees family tree (parent + child embedded)",
    is_array($payload)
    && isset($payload['family']['parent']['id']) && $payload['family']['parent']['id'] === $idParent
    && isset($payload['family']['children'][0]['id']) && $payload['family']['children'][0]['id'] === $idChild);

check("stranger sees all 5 subsystem cards",
    is_array($payload) && count($payload['links']) === 5);

check("stranger gets no Edit/Collaborate affordance (permissions both false)",
    is_array($payload)
    && empty($payload['permissions']['isOwner']) && empty($payload['permissions']['canEdit']));

// The buttons themselves ship hidden; JS only reveals them when the payload
// flags allow. Assert the markup contract the JS relies on.
check("Edit/Collaborate buttons default to display:none in markup",
    strpos($p['body'], 'id="sd-edit-btn" style="display:none"') !== false
    && strpos($p['body'], 'id="sd-collab-btn" style="display:none"') !== false);

// The share URL is embedded as JSON (slashes escaped), so parse it out.
$shareUrlEmbedded = null;
if (preg_match('#<script type="application/json" id="sd-share-url-data">(.*?)</script>#s', $p['body'], $m)) {
    $shareUrlEmbedded = json_decode($m[1], true);
}
check("share URL embedded in page matches canonical form",
    is_string($shareUrlEmbedded)
    && substr($shareUrlEmbedded, -strlen("/samples/$ownerPkey/" . rawurlencode($idMain)))
       === "/samples/$ownerPkey/" . rawurlencode($idMain));

// Direct edit POST as the stranger (C.3's "cannot trigger an edit") — 403.
$r = httpSession('POST', '/samples_edit.php',
    json_encode(array('sample_id' => $idMain, 'owner_pkey' => $ownerPkey, 'name' => 'hijacked')),
    $sidOutsider);
check("stranger direct samples_edit.php POST → 403", $r['status'] === 403);
$v = $db->get_var_prepared(
    "SELECT name FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
    array($idMain, $ownerPkey)
);
check("sample name unchanged after hijack attempt", $v === 'AuthMx Main Sample');

// -------------------------------------------------------------------------
// Part 8 — C.4: cross-system "View <Subsystem>" gates
// -------------------------------------------------------------------------

echo "\n--- Part 8: C.4 cross-system gates ---\n";

// Viewer: stranger.
$p = httpPage($pageMain, $sidOutsider);
$payload = parseSdPayload($p['body']);
check("C.4 stranger page loads", $p['status'] === 200 && is_array($payload));

$l = findLink($payload, 'field', 'authmx-spot-1');
check("stranger: field card links out (Field self-gates)",
    $l && !empty($l['view_href']) && strpos($l['view_href'], 'authmx-ds-1') !== false);

$l = findLink($payload, 'micro', (string)$microPrivId);
check("stranger: PRIVATE micro card disabled with privacy explanation",
    $l && empty($l['view_href']) && !empty($l['view_unavailable'])
    && stripos($l['view_unavailable'], 'private') !== false);

$l = findLink($payload, 'micro', (string)$microPubId);
check("stranger: PUBLIC micro card links to /microproject?id=" . $microPubId,
    $l && $l['view_href'] === '/microproject?id=' . $microPubId);

$l = findLink($payload, 'experimental', 'authmx-exp-priv-ref');
check("stranger: PRIVATE exp card disabled with privacy explanation",
    $l && empty($l['view_href']) && !empty($l['view_unavailable'])
    && stripos($l['view_unavailable'], 'private') !== false);

$l = findLink($payload, 'experimental', 'authmx-exp-pub-ref');
check("stranger: PUBLIC exp card links to overview_experiment",
    $l && !empty($l['view_href'])
    && strpos($l['view_href'], '/experimental/overview_experiment.php?u=' . $expPubUuid) === 0);

// Viewer: owner — private hosts are THEIR projects, so links resolve.
$p = httpPage($pageMain, $sidOwner);
$payload = parseSdPayload($p['body']);
$l = findLink($payload, 'micro', (string)$microPrivId);
check("owner: PRIVATE micro card links out (host owner)",
    $l && $l['view_href'] === '/microproject?id=' . $microPrivId);
$l = findLink($payload, 'experimental', 'authmx-exp-priv-ref');
check("owner: PRIVATE exp card links out (host owner)",
    $l && !empty($l['view_href'])
    && strpos($l['view_href'], '/experimental/overview_experiment.php?u=' . $expPrivUuid) === 0);

// Viewer: editor — owns the PUBLIC DECOY micro project that shares the
// private project's strabo_id. The link references the OWNER's private
// copy, so the editor must still see it as unavailable. This is the
// (strabo_id, userpkey) disambiguation regression: keying on strabo_id
// alone would resolve to the editor's public/owned copy and leak a link.
$p = httpPage($pageMain, $sidEditor);
$payload = parseSdPayload($p['body']);
$l = findLink($payload, 'micro', (string)$microPrivId);
check("editor: private micro card stays disabled despite same-strabo_id public decoy",
    $l && empty($l['view_href']) && !empty($l['view_unavailable'])
    && stripos($l['view_unavailable'], 'private') !== false);
$l = findLink($payload, 'micro', (string)$microPubId);
check("editor: public micro card resolves to the OWNER's copy, not the decoy",
    $l && $l['view_href'] === '/microproject?id=' . $microPubId);

// -------------------------------------------------------------------------
// Part 9 — owner delete (the matrix's only YES delete cell) + cascade
// -------------------------------------------------------------------------

echo "\n--- Part 9: owner delete ---\n";

$r = httpBasic('DELETE', $restDisp, null, $ownerEmail, $password);
check("owner REST DELETE sample → 200 ok", $r['status'] === 200 && !empty($r['json']['ok']));
$v = $db->get_var_prepared(
    "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
    array($idDisp, $ownerPkey)
);
check("disposable sample row gone", !$v);
$v = $db->get_var_prepared(
    "SELECT COUNT(*) FROM strabosamples.sample_collaborators WHERE sample_id=$1 AND sample_userpkey=$2",
    array($idDisp, $ownerPkey)
);
check("disposable sample's grants cascaded away", (int)$v === 0);

} finally {
    authmxCleanup($db);
    foreach ($sessionFiles as $f) @unlink($f);
}

// -------------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------------

echo "\n=================================================\n";
if (empty($failures)) {
    echo "AUTH MATRIX: ALL CHECKS PASSED\n";
    exit(0);
}
echo "AUTH MATRIX: " . count($failures) . " FAILURE(S)\n";
foreach ($failures as $f) echo "  - $f\n";
exit(1);
