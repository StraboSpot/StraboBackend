<?php
/**
 * File: includes/fieldvocab/sync.php
 * Description: Nightly sync of the StraboField choice map
 *              (docs/edine_bug/TRANSLATION_SURFACE_AUDIT.md §2, decision D7).
 *
 *              1. Find the newest release tag vX.Y.Z (rc tags ignored) of the
 *                 public repo StraboSpot/StraboField (GitHub API, no token).
 *              2. Nothing to do when the live map was built from that commit.
 *              3. Fetch src/assets/forms/** (index.js + *.json) at the tag.
 *              4. Build, merge onto the live map (the map only grows),
 *                 validate, write to a temp file, rename over the live map;
 *                 the previous map is kept as field_vocab_map.prev.json.
 *              5. Log to fieldvocab_data/sync.log; mail a change summary on
 *                 change or on new warnings (FieldVocabBuilder::warnings, never
 *                 blocking) and the error on failure (the live map is kept).
 *
 *              The live map is fieldvocab_data/field_vocab_map.json (see
 *              FieldVocab::dataDir); with no live map yet, the repo baseline
 *              includes/fieldvocab/field_vocab_baseline.json is the merge base.
 *
 *              Mail goes to $fieldvocab_notify (string or array of addresses)
 *              from includes/config.inc.php; unset = log only. The
 *              FIELDVOCAB_NOTIFY env var (comma list, empty = none) wins (tests).
 *
 * Usage (inside the container, as www-data on prod):
 *   php includes/fieldvocab/sync.php [--force] [--dry-run] [--quiet]
 *   php includes/fieldvocab/sync.php --from-dir=DIR --tag=vX.Y.Z --sha=SHA [--out=FILE] [--dry-run]
 *
 *   --force       rebuild even when the live map already has the newest tag
 *   --dry-run     build + validate + print the change summary, write nothing, send nothing
 *   --quiet       no stdout unless something changed or failed (for cron)
 *   --from-dir    build from a local forms folder (a checkout's src/assets/forms)
 *                 instead of GitHub; needs --tag and --sha
 *   --out=FILE    write the merged map to FILE instead of the live map (no
 *                 .prev copy, no mail), e.g. to refresh the repo baseline
 *
 *   Exit codes: 0 ok or nothing to do, 1 failure (live map untouched), 2 usage.
 *
 *   Prod host crontab (nightly):
 *   15 3 * * * sudo docker exec -u www-data strabo-php php /srv/app/www/includes/fieldvocab/sync.php --quiet >> /var/log/strabo_fieldvocab.log 2>&1
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/FieldVocabBuilder.php';
require_once __DIR__ . '/FieldVocab.php';

$opts = getopt('', array('force', 'dry-run', 'quiet', 'from-dir:', 'tag:', 'sha:', 'out:', 'help'));
if (isset($opts['help'])) {
	fwrite(STDOUT, "usage: php sync.php [--force] [--dry-run] [--quiet] | --from-dir=DIR --tag=T --sha=S [--out=FILE] [--dry-run]\n");
	exit(0);
}
$force  = isset($opts['force']);
$dry    = isset($opts['dry-run']);
$quiet  = isset($opts['quiet']);
$outArg = isset($opts['out']) ? (string)$opts['out'] : null;
$fromDir = isset($opts['from-dir']) ? rtrim((string)$opts['from-dir'], '/') : null;
if ($fromDir !== null && (empty($opts['tag']) || empty($opts['sha']))) {
	fwrite(STDERR, "--from-dir needs --tag and --sha\n");
	exit(2);
}

// config.inc.php holds $fieldvocab_notify and the site mail account; optional for --from-dir runs.
$cfgFile = dirname(__DIR__) . '/config.inc.php';
if (is_file($cfgFile)) require_once $cfgFile;

$dataDir  = FieldVocab::dataDir();
$livePath = FieldVocab::dataMapPath();
$prevPath = $dataDir . '/field_vocab_map.prev.json';
$logPath  = $dataDir . '/sync.log';
$toFile   = $outArg !== null;

$lines = array();
$say = function ($msg, $always = false) use (&$lines, $quiet) {
	$lines[] = $msg;
	if (!$quiet || $always) fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] $msg\n");
};

try {
	if (!$dry && !$toFile) fv_ensure_data_dir($dataDir);

	$live = FieldVocab::readMapFile($livePath);
	$base = $live !== null ? $live : FieldVocab::readMapFile(FieldVocab::baselinePath());
	$baseName = $live !== null ? 'live map' : ($base !== null ? 'repo baseline' : 'nothing');
	$baseSha = isset($base['source']['sha']) ? $base['source']['sha'] : null;

	if ($fromDir !== null) {
		$tag = (string)$opts['tag'];
		$sha = (string)$opts['sha'];
		$files = fv_read_dir($fromDir);
		$say("building $tag ($sha) from $fromDir, " . count($files) . ' files');
	} else {
		list($tag, $sha) = fv_newest_release();
		if ($live !== null && $live['source']['sha'] === $sha && !$force) {
			$say("up to date: live map is $tag ($sha)");
			fv_log($logPath, $lines, $dry || $toFile);
			exit(0);
		}
		$files = fv_fetch_forms($sha);
		$say("fetched $tag ($sha), " . count($files) . ' files');
	}

	$map = FieldVocabBuilder::merge(FieldVocabBuilder::build($files, $tag, $sha), $base);
	$errors = FieldVocabBuilder::validate($map);
	if ($errors) throw new Exception("map for $tag failed validation:\n  - " . implode("\n  - ", $errors));

	$changes = FieldVocabBuilder::diff($base, $map);
	$from = isset($base['source']['tag']) ? $base['source']['tag'] : 'none';
	$say(($changes ? count($changes) . ' change(s)' : 'no changes') . " vs $baseName ($from -> $tag)", (bool)$changes);
	foreach ($changes as $c) $say("  $c", true);
	// Warnings never block a sync; only ones the previous map did not have are news.
	$newWarnings = array_values(array_diff(FieldVocabBuilder::warnings($map), $base !== null ? FieldVocabBuilder::warnings($base) : array()));
	if ($newWarnings) $say(count($newWarnings) . ' new warning(s) (map still used):', true);
	foreach ($newWarnings as $w) $say("  $w", true);

	if ($dry) {
		$say('dry run: nothing written');
	} else {
		$json = FieldVocabBuilder::encode($map);
		$target = $toFile ? $outArg : $livePath;
		$tmp = $target . '.tmp.' . getmypid();
		if (file_put_contents($tmp, $json) === false) throw new Exception("cannot write $tmp");
		if (FieldVocab::readMapFile($tmp) === null) { @unlink($tmp); throw new Exception('written map does not read back'); }
		if (!$toFile && $live !== null) @copy($livePath, $prevPath);
		if (!rename($tmp, $target)) { @unlink($tmp); throw new Exception("cannot rename $tmp to $target"); }
		$say("wrote $target (" . strlen($json) . ' bytes)');
		if (!$toFile && ($changes || $newWarnings) && $live !== null) {
			$detail = $changes;
			if ($newWarnings) $detail = array_merge($detail, array(count($newWarnings) . ' new warning(s), the map is still used:'), $newWarnings);
			fv_mail("StraboField choice labels updated to $tag", array(
				"The StraboField choice map was updated from $from to $tag (" . substr($sha, 0, 7) . ').',
				count($changes) . ' change(s):',
			), $detail);
		}
	}
	fv_log($logPath, $lines, $dry || $toFile);
	exit(0);
} catch (Exception $e) {
	$say('FAILED: ' . $e->getMessage() . ' (live map untouched)', true);
	if (!$dry && !$toFile) {
		fv_log($logPath, $lines, false);
		fv_mail('StraboField choice map sync FAILED', array(
			'The nightly StraboField choice map sync failed. The last good map stays in use.',
		), explode("\n", $e->getMessage()));
	}
	exit(1);
}

/* ------------------------------------------------------------------ helpers */

function fv_ensure_data_dir($dir)
{
	if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new Exception("cannot create $dir");
	$ht = $dir . '/.htaccess';
	if (!is_file($ht)) @file_put_contents($ht, "# StraboField choice map: read by PHP only, never served\nRequire all denied\n");
}

function fv_log($path, array $lines, $skip)
{
	if ($skip || !$lines) return;
	$stamp = '[' . date('Y-m-d H:i:s') . '] ';
	@file_put_contents($path, $stamp . implode("\n" . $stamp, $lines) . "\n", FILE_APPEND);
}

/** GET a URL; returns the body or throws. */
function fv_http_get($url)
{
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_USERAGENT      => 'StraboSpot-fieldvocab-sync',
		CURLOPT_HTTPHEADER     => array('Accept: application/vnd.github+json'),
	));
	$body = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$err = curl_error($ch);
	curl_close($ch);
	if ($body === false) throw new Exception("GET $url: $err");
	if ($code !== 200) throw new Exception("GET $url: HTTP $code " . substr((string)$body, 0, 200));
	return $body;
}

/** Newest vX.Y.Z tag (rc and other suffixes ignored): [tag, sha]. */
function fv_newest_release()
{
	$tags = json_decode(fv_http_get('https://api.github.com/repos/' . FieldVocabBuilder::REPO . '/tags?per_page=100'), true);
	if (!is_array($tags)) throw new Exception('tags: unexpected response');
	$best = null;
	foreach ($tags as $t) {
		if (!isset($t['name'], $t['commit']['sha']) || !preg_match('/^v(\d+\.\d+\.\d+)$/', $t['name'], $m)) continue;
		if ($best === null || version_compare($m[1], $best[2], '>')) $best = array($t['name'], $t['commit']['sha'], $m[1]);
	}
	if ($best === null) throw new Exception('no vX.Y.Z release tag found');
	return array($best[0], $best[1]);
}

/** index.js + every *.json under src/assets/forms at a commit: relative path => text. */
function fv_fetch_forms($sha)
{
	$repo = FieldVocabBuilder::REPO;
	$dir = FieldVocabBuilder::FORMS_DIR;
	$tree = json_decode(fv_http_get("https://api.github.com/repos/$repo/git/trees/$sha:$dir?recursive=1"), true);
	if (!is_array($tree) || !isset($tree['tree'])) throw new Exception('forms tree: unexpected response');
	if (!empty($tree['truncated'])) throw new Exception('forms tree listing was truncated');
	$files = array();
	foreach ($tree['tree'] as $node) {
		if ($node['type'] !== 'blob') continue;
		$p = $node['path'];
		if ($p !== 'index.js' && substr($p, -5) !== '.json') continue;
		$files[$p] = fv_http_get("https://raw.githubusercontent.com/$repo/$sha/$dir/" . str_replace('%2F', '/', rawurlencode($p)));
	}
	return $files;
}

/** index.js + every *.json under a local forms folder. */
function fv_read_dir($dir)
{
	if (!is_file("$dir/index.js")) throw new Exception("$dir has no index.js");
	$files = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$rel = substr($f->getPathname(), strlen($dir) + 1);
		if ($rel === 'index.js' || substr($rel, -5) === '.json') $files[$rel] = file_get_contents($f->getPathname());
	}
	ksort($files);
	return $files;
}

/** Mail $fieldvocab_notify (if set); a mail failure is logged, never fatal. */
function fv_mail($subject, array $intro, array $detail)
{
	$env = getenv('FIELDVOCAB_NOTIFY');   // tests: wins over config.inc.php; empty = no mail
	if ($env !== false) $to = array_filter(array_map('trim', explode(',', $env)));
	else $to = isset($GLOBALS['fieldvocab_notify']) ? (array)$GLOBALS['fieldvocab_notify'] : array();
	if (!$to) return;
	require_once dirname(__DIR__) . '/StraboMail.php';
	$shown = array_slice($detail, 0, 200);
	if (count($detail) > count($shown)) $shown[] = '... ' . (count($detail) - count($shown)) . ' more in fieldvocab_data/sync.log';
	$rendered = StraboMail::render(array(
		'title'   => $subject,
		'intro'   => array_merge($intro, $shown),
		'closing' => array('StraboSpot field vocab sync'),
		'footer'  => 'Sent by includes/fieldvocab/sync.php (nightly cron).',
	));
	foreach ($to as $addr) {
		try {
			StraboMail::send($addr, $subject, $rendered);
		} catch (Exception $e) {
			fwrite(STDERR, "mail to $addr failed: " . $e->getMessage() . "\n");
		}
	}
}
