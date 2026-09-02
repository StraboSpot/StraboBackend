<?php
/**
 * File: exportjobs/api.php
 * Description: Session-gated JSON endpoints for the Export Builder and My
 *              Exports pages (docs/ExportBuilder_Design.md §9.3). The
 *              session user is the only identity used: scope authorization,
 *              row lookups and the per-user cap all key on it, never on a
 *              URL or body value.
 *
 *              POST ?action=create   body {recipe, email_on_done, summary, origin}
 *                                    -> validate (scope authz, DSL, formats),
 *                                       insert queued row, kick the worker,
 *                                       {ok, uuid}
 *              POST ?action=count    body {recipe} -> live "N spots match"
 *              GET  ?action=status   -> {jobs:[...]} light columns for polling
 *              GET  ?action=detail&uuid=  -> one row + recipe + README text
 *              POST ?action=rerun    body {uuid} -> clone recipe, kick, {ok, uuid}
 *              POST ?action=cancel   body {uuid}
 *              POST ?action=clear    body {which: finished|expired}
 *
 *              Errors: {ok:false, error:"..."} with 400 (recipe problems),
 *              401 (no session), 404 (unknown uuid for this user), 405.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once(__DIR__ . '/../includes/session_config.php');
session_start();
header('Content-Type: application/json');

function ej_respond($payload, $code = 200)
{
	if ($code !== 200) http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_SLASHES);
	exit();
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_IDLE_TIMEOUT)) {
	$_SESSION['loggedin'] = 'no';
}
if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== 'yes' || empty($_SESSION['userpkey']) || (int)$_SESSION['userpkey'] <= 0) {
	ej_respond(array('ok' => false, 'error' => 'Login required.'), 401);
}
$_SESSION['LAST_ACTIVITY'] = time();
$userpkey = (int)$_SESSION['userpkey'];
session_write_close();   // counts can take a moment; never serialize the user's other tabs

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
include_once dirname(__DIR__) . '/includes/config.inc.php';
include      dirname(__DIR__) . '/db.php';
include      dirname(__DIR__) . '/neodb.php';
include_once dirname(__DIR__) . '/includes/UUID.php';
require_once __DIR__ . '/lib/export_config.php';
require_once __DIR__ . '/lib/ExportJobService.php';
require_once __DIR__ . '/lib/ExportFinder.php';
require_once __DIR__ . '/plugins/FieldExportPlugin.php';   // validateOutput (static); the plugin itself is never constructed here

$cfg = export_config();
export_ensure_dirs($cfg);
$svc = new ExportJobService($db, $cfg);
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

function ej_body()
{
	$raw = file_get_contents('php://input');
	$b = json_decode($raw, true);
	if (!is_array($b)) ej_respond(array('ok' => false, 'error' => 'Request body must be a JSON object.'), 400);
	return $b;
}
function ej_post()
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') ej_respond(array('ok' => false, 'error' => 'POST required.'), 405);
}

/**
 * Normalize + validate a recipe from the browser into the stored form
 * (design §5.2). Everything the user can influence is re-validated here
 * against the SESSION user; the stored recipe carries only known keys.
 */
function ej_normalize_recipe($db, $neodb, $userpkey, $cfg, $in)
{
	if (!is_array($in)) throw new ExportJobError('Recipe missing.');
	$scopeIn = isset($in['scope']) && is_array($in['scope']) ? $in['scope'] : array();
	$scope = array('projects' => array(), 'datasets' => array());
	foreach ((isset($scopeIn['projects']) && is_array($scopeIn['projects'])) ? $scopeIn['projects'] : array() as $p) {
		if (!is_array($p)) continue;
		$scope['projects'][] = array('id' => isset($p['id']) ? (string)$p['id'] : '', 'owner' => isset($p['owner']) ? (int)$p['owner'] : 0);
	}
	foreach ((isset($scopeIn['datasets']) && is_array($scopeIn['datasets'])) ? $scopeIn['datasets'] : array() as $d) {
		if (!is_array($d)) continue;
		$scope['datasets'][] = array('id' => isset($d['id']) ? (string)$d['id'] : '', 'project_id' => isset($d['project_id']) ? (string)$d['project_id'] : '', 'owner' => isset($d['owner']) ? (int)$d['owner'] : 0);
	}
	$recipe = array(
		'v'        => 1,
		'plugin'   => 'field',
		'scope'    => $scope,
		'criteria' => isset($in['criteria']) && is_array($in['criteria']) ? array_values($in['criteria']) : array(),
		'children' => (isset($in['children']) && $in['children'] === 'none') ? 'none' : 'matched_parents',
		'formats'  => isset($in['formats']) && is_array($in['formats']) ? array_values($in['formats']) : array(),
		'layout'   => isset($in['layout']) ? (string)$in['layout'] : 'merged',
		'extras'   => isset($in['extras']) && is_array($in['extras']) ? array_values($in['extras']) : array(),
		'sample_list_csv' => !empty($in['sample_list_csv']),
		'notes'    => isset($in['notes']) ? mb_substr(trim(strip_tags((string)$in['notes'])), 0, 500) : '',
	);
	$finder = new ExportFinder($db, $neodb, $userpkey, $cfg);
	$resolved = $finder->resolveScope($recipe);          // authz: throws for anything the session user cannot export
	$crit = $finder->resolveCriteria($recipe);           // DSL validation
	$rows = $crit['dsl']['criteria'];                    // store the validated rows (U2 back in GeoJSON form)
	foreach ($rows as &$c) {
		if ($c['id'] === 'U2' && is_array($c['value']) && isset($c['value']['geojson'])) {
			$g = json_decode($c['value']['geojson'], true);
			if (is_array($g)) $c['value'] = $g;
		}
	}
	unset($c);
	$recipe['criteria'] = $rows;
	$out = FieldExportPlugin::validateOutput($recipe);
	$recipe['formats'] = $out['formats']; $recipe['extras'] = $out['extras']; $recipe['layout'] = $out['layout'];
	return array('recipe' => $recipe, 'scope' => $resolved, 'criteria' => $crit, 'out' => $out);
}

/** One-line label for lists and emails: client hint (project names) sanitized, else generated. */
function ej_summary($hint, array $norm)
{
	$hint = trim(preg_replace('/\s+/', ' ', strip_tags((string)$hint)));
	$np = count($norm['scope']); $nd = 0;
	foreach ($norm['scope'] as $sc) if (!empty($sc['dataset_ids'])) $nd += count($sc['dataset_ids']);
	$parts = array();
	$parts[] = $hint !== '' ? mb_substr($hint, 0, 120) : ($np . ' project' . ($np === 1 ? '' : 's') . ($nd ? " ($nd datasets)" : ''));
	$fmts = $norm['recipe']['formats'];
	foreach ($norm['recipe']['extras'] as $e) $fmts[] = $e;
	$parts[] = implode(', ', $fmts);
	$nc = count($norm['recipe']['criteria']);
	if ($nc) $parts[] = $nc . ' filter' . ($nc === 1 ? '' : 's');
	if ($norm['recipe']['layout'] !== 'merged') $parts[] = str_replace('_', ' by ', $norm['recipe']['layout']);
	return mb_substr(implode(' · ', $parts), 0, 240);
}

/** Start the worker for one job without blocking the request (design §6.2). */
function ej_kick(array $cfg, $uuid)
{
	if (!UUID::is_valid($uuid) || !function_exists('exec')) return false;
	$cmd = 'cd ' . escapeshellarg($cfg['web_root']) . ' && nohup ' . escapeshellcmd($cfg['php_binary'])
		. ' exportjobs/worker.php --job=' . $uuid
		. ' >> ' . escapeshellarg(rtrim($cfg['log_root'], '/') . '/worker.log') . ' 2>&1 &';
	@exec($cmd);
	return true;
}

/** PostgreSQL timestamptz text -> ISO 8601 (browsers parse that reliably; Firefox rejects the PG form). */
function ej_iso($ts)
{
	if ($ts === null || $ts === '') return null;
	$t = strtotime($ts);
	return $t === false ? $ts : date('c', $t);
}

/** Public view of a row (light columns; recipe only when asked). */
function ej_row_public(array $r, $withRecipe = false)
{
	$recipe = is_array($r['recipe']) ? $r['recipe'] : array();
	$o = array(
		'uuid'           => $r['uuid'],
		'status'         => $r['status'],
		'summary'        => $r['recipe_summary'],
		'origin'         => $r['origin'],
		'email_on_done'  => (bool)$r['email_on_done'],
		'phase'          => $r['phase'],
		'progress_done'  => $r['progress_done'] === null ? null : (int)$r['progress_done'],
		'progress_total' => $r['progress_total'] === null ? null : (int)$r['progress_total'],
		'progress_note'  => $r['progress_note'],
		'item_count'     => $r['item_count'] === null ? null : (int)$r['item_count'],
		'child_count'    => $r['child_count'] === null ? null : (int)$r['child_count'],
		'result_bytes'   => $r['result_bytes'] === null ? null : (int)$r['result_bytes'],
		'error_text'     => $r['error_text'],
		'created_at'     => ej_iso($r['created_at']),
		'started_at'     => ej_iso($r['started_at']),
		'finished_at'    => ej_iso($r['finished_at']),
		'expires_at'     => ej_iso($r['expires_at']),
		'expired_at'     => ej_iso($r['expired_at']),
		'formats'        => isset($recipe['formats']) ? $recipe['formats'] : array(),
		'extras'         => isset($recipe['extras']) ? $recipe['extras'] : array(),
		'layout'         => isset($recipe['layout']) ? $recipe['layout'] : 'merged',
		'project_count'  => isset($recipe['scope']['projects']) ? count($recipe['scope']['projects']) : 0,
		'filter_count'   => isset($recipe['criteria']) ? count($recipe['criteria']) : 0,
		'notes'          => isset($recipe['notes']) ? $recipe['notes'] : '',
	);
	if ($withRecipe) $o['recipe'] = $recipe;
	return $o;
}

try {
	switch ($action) {

		case 'create':
			ej_post();
			$b = ej_body();
			$norm = ej_normalize_recipe($db, $neodb, $userpkey, $cfg, isset($b['recipe']) ? $b['recipe'] : null);
			$origin = (isset($b['origin']) && $b['origin'] === 'search') ? 'search' : 'builder';
			$job = $svc->create($userpkey, $norm['recipe'], array(
				'summary'       => ej_summary(isset($b['summary']) ? $b['summary'] : '', $norm),
				'origin'        => $origin,
				'email_on_done' => !empty($b['email_on_done']),
			));
			$kicked = ej_kick($cfg, $job['uuid']);
			ej_respond(array('ok' => true, 'uuid' => $job['uuid'], 'kicked' => $kicked, 'job' => ej_row_public($job)));

		case 'count':
			ej_post();
			$b = ej_body();
			$in = isset($b['recipe']) && is_array($b['recipe']) ? $b['recipe'] : array();
			// count needs scope + criteria only; formats may still be unchosen on the page
			$in['formats'] = array('geojson');
			$norm = ej_normalize_recipe($db, $neodb, $userpkey, $cfg, $in);
			$finder = new ExportFinder($db, $neodb, $userpkey, $cfg);
			$c = $finder->count($norm['recipe']);
			$c['ok'] = true;
			ej_respond($c);

		case 'status':
			// Crashed-worker recovery on the polling path too (one cheap query):
			// a row whose worker died without reporting is re-queued and kicked
			// here, so My Exports never shows a corpse as RUNNING for longer than
			// stale_seconds even where the per-minute sweep cron is not installed.
			$st = $svc->requeueStale($userpkey);        // the caller's rows only
			foreach ($st['requeued'] as $ru) ej_kick($cfg, $ru);
			$rows = $svc->listForUser($userpkey);
			$out = array();
			foreach ($rows as $r) $out[] = ej_row_public($r);
			ej_respond(array('ok' => true, 'jobs' => $out, 'server_time' => date('c'),
				'max_queued_per_user' => (int)$cfg['max_queued_per_user'], 'retention_days' => (int)$cfg['retention_days']));

		case 'detail':
			$uuid = isset($_GET['uuid']) ? (string)$_GET['uuid'] : '';
			$row = $svc->get($uuid, $userpkey);
			if (!$row) ej_respond(array('ok' => false, 'error' => 'Export not found.'), 404);
			$o = ej_row_public($row, true);
			$o['readme'] = null;
			if ($row['status'] === 'done') {
				$abs = $svc->resultPath($row);
				if ($abs && is_file($abs)) {
					$o['readme'] = shell_exec('unzip -p ' . escapeshellarg($abs) . ' README.txt 2>/dev/null');
				}
			}
			ej_respond(array('ok' => true, 'job' => $o));

		case 'rerun':
			ej_post();
			$b = ej_body();
			$uuid = isset($b['uuid']) ? (string)$b['uuid'] : '';
			if (!$svc->get($uuid, $userpkey)) ej_respond(array('ok' => false, 'error' => 'Export not found.'), 404);
			$job = $svc->rerun($uuid, $userpkey);
			$kicked = ej_kick($cfg, $job['uuid']);
			ej_respond(array('ok' => true, 'uuid' => $job['uuid'], 'kicked' => $kicked, 'job' => ej_row_public($job)));

		case 'cancel':
			ej_post();
			$b = ej_body();
			$uuid = isset($b['uuid']) ? (string)$b['uuid'] : '';
			if (!$svc->get($uuid, $userpkey)) ej_respond(array('ok' => false, 'error' => 'Export not found.'), 404);
			ej_respond(array('ok' => true, 'cancelled' => $svc->cancel($uuid, $userpkey)));

		case 'clear':
			ej_post();
			$b = ej_body();
			$which = (isset($b['which']) && $b['which'] === 'expired') ? 'expired' : 'finished';
			ej_respond(array('ok' => true, 'cleared' => $svc->clear($userpkey, $which)));

		default:
			ej_respond(array('ok' => false, 'error' => 'Unknown action.'), 400);
	}
} catch (ExportJobError $e) {
	ej_respond(array('ok' => false, 'error' => $e->getMessage()), 400);
} catch (SearchDslError $e) {
	ej_respond(array('ok' => false, 'error' => 'Filter error: ' . $e->getMessage()), 400);
}
