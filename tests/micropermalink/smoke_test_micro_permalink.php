<?php
/**
 * File: smoke_test_micro_permalink.php
 * Description: Smoke test for upload-stable StraboMicro landing permalinks
 *              (micro_permalinks table + microdb/lib/permalink.php + the
 *              ?m= entry points, 2026-08-20).
 *
 *              Covers:
 *                - helper: mint is stable, resolve maps slug -> CURRENT row,
 *                  bad/unknown slugs return null
 *                - THE CORE SCENARIO: slug survives a simulated re-upload
 *                  (delete metadata row + reinsert with a new pkey)
 *                - /microproject?m= renders the inline tier with the slug
 *                  kept in the address bar (200, no redirect)
 *                - legacy /microproject?id= 302-upgrades to the ?m= form
 *                - tiles tier routes ?m= -> /microview/?m= (slug-to-slug,
 *                  never slug-to-pkey) and /microview/ injects the
 *                  history.replaceState line the deferred viewer bundle
 *                  relies on; same for the webImages tier via
 *                  /straboMicroView/view?m=
 *                - stale tier URLs self-heal through /microproject?m=
 *                - gates: private project is "not found" to anon on every
 *                  entry point, matching the pkey behavior
 *                - /mpl/<strabo_id> (search clickthrough) redirects into the
 *                  permalink form
 *
 *              Fixtures: synthetic rows inserted directly (ids 99981/99982,
 *              strabo_id prefixed "permatest-") plus temporary tier dirs
 *              under straboMicroFiles/. Zero residue on success or failure
 *              (cleanup runs in shutdown).
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/micropermalink/smoke_test_micro_permalink.php
 *
 * @package    StraboSpot Web Site
 */

chdir('/srv/app/www');
require 'includes/config.inc.php';
require 'db.php';
require 'microdb/lib/permalink.php';

$BASE = 'http://localhost';
$PUB_ID = 99981;
$PRIV_ID = 99982;
$REUP_ID = 99983;
$SID_PUB = 'permatest-pub-' . getmypid();
$SID_PRIV = 'permatest-priv-' . getmypid();
$UPK = 3;

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . '  ' . $label . ($detail !== '' ? "  -- $detail" : '') . PHP_EOL;
	if (!$cond) $failures[] = $label;
}

function http_get($url) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_HEADER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	$raw = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);
	return array($code, substr($raw, 0, $hsize), substr($raw, $hsize));
}

function location_of($headers) {
	if (preg_match('/^Location:\s*(\S+)/mi', $headers, $mm)) return $mm[1];
	return '';
}

$cleanup = function () use ($db, $PUB_ID, $PRIV_ID, $REUP_ID, $SID_PUB, $SID_PRIV, $UPK) {
	$db->prepare_query("DELETE FROM micro_projectmetadata WHERE id IN ($1,$2,$3)", array($PUB_ID, $PRIV_ID, $REUP_ID));
	$db->prepare_query("DELETE FROM micro_permalinks WHERE strabo_id IN ($1,$2)", array($SID_PUB, $SID_PRIV));
	foreach (array($PUB_ID, $PRIV_ID, $REUP_ID) as $fid) {
		@rmdir("/srv/app/www/straboMicroFiles/$fid/tiles");
		@rmdir("/srv/app/www/straboMicroFiles/$fid/webImages");
		@rmdir("/srv/app/www/straboMicroFiles/$fid");
	}
};
register_shutdown_function($cleanup);
$cleanup(); // in case a previous run died mid-way

echo "=== StraboMicro permalink smoke ===\n";

// --- fixtures -------------------------------------------------------------
$pj = '{"name":"PermaTest","datasets":[]}';
$db->prepare_query(
	"INSERT INTO micro_projectmetadata (id, strabo_id, userpkey, name, ispublic, projectjson) VALUES ($1,$2,$3,'PermaTest',true,$4)",
	array($PUB_ID, $SID_PUB, $UPK, $pj));
$db->prepare_query(
	"INSERT INTO micro_projectmetadata (id, strabo_id, userpkey, name, ispublic, projectjson) VALUES ($1,$2,$3,'PermaTestPriv',false,$4)",
	array($PRIV_ID, $SID_PRIV, $UPK, $pj));

// --- 1. helper library ----------------------------------------------------
echo "\n--- 1. helper library\n";
$slug = micro_permalink_get_or_create($db, $SID_PUB, $UPK);
check('mints an 8-char slug', $slug !== null && preg_match('/^[a-z0-9]{8}$/', $slug), var_export($slug, true));
check('mint is stable (get-or-create returns same slug)',
	micro_permalink_get_or_create($db, $SID_PUB, $UPK) === $slug);
$r = micro_permalink_resolve($db, $slug);
check('slug resolves to current metadata row', $r !== null && (int)$r->id === $PUB_ID);
check('unknown slug resolves to null', micro_permalink_resolve($db, 'qqqqqqqq') === null);
check('malformed slug resolves to null (no SQL reach)',
	micro_permalink_resolve($db, "x'; drop--") === null);
$slugPriv = micro_permalink_get_or_create($db, $SID_PRIV, $UPK);
check('different identity gets different slug', $slugPriv !== null && $slugPriv !== $slug);

// --- 2. THE CORE SCENARIO: slug survives a re-upload ----------------------
echo "\n--- 2. re-upload survival\n";
$db->prepare_query("DELETE FROM micro_projectmetadata WHERE id = $1", array($PUB_ID));
check('mid-upload gap: slug resolves to null (not an error)',
	micro_permalink_resolve($db, $slug) === null);
$db->prepare_query(
	"INSERT INTO micro_projectmetadata (id, strabo_id, userpkey, name, ispublic, projectjson) VALUES ($1,$2,$3,'PermaTest',true,$4)",
	array($REUP_ID, $SID_PUB, $UPK, $pj));
$r = micro_permalink_resolve($db, $slug);
check('after re-upload (new pkey) the SAME slug resolves to the NEW row',
	$r !== null && (int)$r->id === $REUP_ID, 'got id ' . ($r ? $r->id : 'null'));
$PUB_ID = $REUP_ID; // the live public fixture row for the HTTP checks below

// --- 3. front door /microproject ------------------------------------------
echo "\n--- 3. /microproject front door\n";
list($code, $h, $body) = http_get("$BASE/microproject?m=$slug");
check('?m= inline tier answers 200 (slug stays in the bar)', $code === 200, "got $code");
check('inline page shows the project name', strpos($body, 'PermaTest') !== false);

list($code, $h, $body) = http_get("$BASE/microproject?id=$PUB_ID");
check('legacy ?id= 302-upgrades to the permalink form', $code === 302
	&& location_of($h) === "/microproject?m=$slug", location_of($h));

list($code, $h, $body) = http_get("$BASE/microproject?m=qqqqqqqq");
check('unknown slug: not found', strpos($body, 'Project not found') !== false);

// --- 4. tiles tier: /microview/ -------------------------------------------
echo "\n--- 4. tiles tier (/microview/)\n";
mkdir("/srv/app/www/straboMicroFiles/$PUB_ID", 0777, true);
mkdir("/srv/app/www/straboMicroFiles/$PUB_ID/tiles");
list($code, $h, $body) = http_get("$BASE/microproject?m=$slug");
check('tiles tier routes slug-to-slug into /microview/', $code === 302
	&& location_of($h) === "/microview/?m=$slug", location_of($h));
list($code, $h, $body) = http_get("$BASE/microview/?m=$slug");
check('/microview/?m= serves the shell', $code === 200, "got $code");
check('shell carries the replaceState bar-upgrade with current pkey',
	strpos($body, "history.replaceState(null, '', '/microview/?m=$slug&p=$PUB_ID')") !== false);
list($code, $h, $body) = http_get("$BASE/microview/?p=$PUB_ID");
check('legacy /microview/?p= also injects the permalink upgrade',
	strpos($body, "history.replaceState(null, '', '/microview/?m=$slug&p=$PUB_ID')") !== false);

// --- 5. webImages tier: /straboMicroView/view + self-heal ------------------
echo "\n--- 5. webImages tier + self-heal\n";
rmdir("/srv/app/www/straboMicroFiles/$PUB_ID/tiles");
mkdir("/srv/app/www/straboMicroFiles/$PUB_ID/webImages");
list($code, $h, $body) = http_get("$BASE/microproject?m=$slug");
check('webImages tier routes slug-to-slug into /straboMicroView/view', $code === 302
	&& location_of($h) === "/straboMicroView/view?m=$slug", location_of($h));
list($code, $h, $body) = http_get("$BASE/straboMicroView/view?m=$slug");
check('view?m= serves the page with the replaceState bar-upgrade', $code === 200
	&& strpos($body, "history.replaceState(null, '', '/straboMicroView/view?m=$slug&p=$PUB_ID')") !== false);
list($code, $h, $body) = http_get("$BASE/microview/?m=$slug");
check('stale /microview/ URL self-heals through the front door', $code === 302
	&& location_of($h) === "/microproject?m=$slug", location_of($h));
rmdir("/srv/app/www/straboMicroFiles/$PUB_ID/webImages");
list($code, $h, $body) = http_get("$BASE/straboMicroView/view?m=$slug");
check('stale view URL self-heals through the front door', $code === 302
	&& location_of($h) === "/microproject?m=$slug", location_of($h));

// --- 6. gates (anon viewer, private project) -------------------------------
echo "\n--- 6. access gates\n";
list($code, $h, $body) = http_get("$BASE/microproject?m=$slugPriv");
check('private project via slug: not found to anon (front door)',
	strpos($body, 'Project not found') !== false);
list($code, $h, $body) = http_get("$BASE/microview/?m=$slugPriv");
check('private project via slug: not found to anon (/microview/)',
	strpos($body, 'Project not found') !== false);
list($code, $h, $body) = http_get("$BASE/straboMicroView/view?m=$slugPriv");
check('private project via slug: not found to anon (view)',
	strpos($body, 'Unable to load project') !== false);
list($code, $h, $body) = http_get("$BASE/microproject?id=$PRIV_ID");
check('private project via legacy id: unchanged not-found behavior',
	strpos($body, 'Project not found') !== false);

// --- 7. /mpl/ search clickthrough ------------------------------------------
echo "\n--- 7. /mpl/ clickthrough\n";
list($code, $h, $body) = http_get("$BASE/mpl/$SID_PUB");
check('/mpl/<strabo_id> redirects into the permalink form', $code === 302
	&& location_of($h) === "/microproject?m=$slug", location_of($h));
list($code, $h, $body) = http_get("$BASE/mpl/$SID_PRIV");
check('/mpl/ private project: not-found page for anon', strpos($body, 'Project Not Found') !== false);

// --- summary ---------------------------------------------------------------
echo "\n=== " . (count($failures) ? count($failures) . " FAILURE(S)" : "ALL CHECKS PASSED") . " ===\n";
if (count($failures)) {
	foreach ($failures as $f) echo "  FAIL: $f\n";
	exit(1);
}
exit(0);
