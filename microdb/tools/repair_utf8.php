<?php
/**
 * File: repair_utf8.php
 * Description: One-time repair of StraboMicro projects whose non-ASCII text
 *              the old upload code garbled (it utf8_encoded project.json
 *              twice; see microdb/lib/micro_text.php).
 *
 *              For every legacy project whose projectjson looks like the old
 *              code's output, the uploaded text is recovered exactly
 *              (micro_projectjson_repair) and the project is rebuilt in place
 *              with the same id, the way a re-upload of that file would:
 *              relational rows, keywords, projectjson, search slice, samples
 *              spine; then the project.json copies and the project.zip entry
 *              are rewritten (StraboSamples overlay applied) and the PDF is
 *              marked for regeneration. Uploaded files, ids, dates, sharing,
 *              public and DOI state are not touched.
 *
 *              StraboSamples: a rebuild writes the project's values to the
 *              spine. A spine value that is NOT simply the garbled form of
 *              the new value (an edit made in the Samples app after the
 *              upload, or another project's value for the same sample) is
 *              put back, and dropped from the new changelog entry, which is
 *              marked utf8_repair.
 *
 *              Journal: before a project is touched, its old projectjson,
 *              recovered text and spine snapshot go to <journal>/<id>.json;
 *              a later run resumes an unfinished project from it.
 *
 *              Usage (as www-data, so rewritten files stay writable by Apache):
 *                docker exec -u www-data strabo-php php /srv/app/www/microdb/tools/repair_utf8.php            (dry run)
 *                docker exec -u www-data strabo-php php /srv/app/www/microdb/tools/repair_utf8.php --apply
 *                  [--only=ID[,ID]] [--journal=DIR] [--verbose]
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
set_time_limit(0);
chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require 'includes/config.inc.php';
require 'db.php';
require_once 'jwtmicrodb/strabomicroclass.php';
require_once 'microdb/lib/micro_text.php';
require_once 'microdb/lib/search_sync.php';
require_once 'microdb/lib/sample_overlay.php';
require_once 'searchdb/sync/StraboSearchSync.php';

$apply = in_array('--apply', $argv, true);
$verbose = in_array('--verbose', $argv, true);
$only = null;
$journal = '/srv/app/www/microsync_data/utf8_repair';
foreach ($argv as $a) {
	if (preg_match('/^--only=([\d,]+)$/', $a, $m)) $only = array_map('intval', explode(',', $m[1]));
	if (preg_match('/^--journal=(.+)$/', $a, $m)) $journal = rtrim($m[1], '/');
}
$SPINE = array('name', 'igsn', 'description', 'notes', 'latitude', 'longitude', 'display_sample_type', 'display_sample_purpose');

$db->get_var('SELECT 1');
$conn = $db->dbh;
function q($sql, $params = array()) {
	global $conn;
	$r = @pg_query_params($conn, $sql, $params);
	if ($r === false) throw new Exception(pg_last_error($conn));
	return $r;
}
function rows($sql, $params = array()) {
	$r = q($sql, $params);
	$out = pg_fetch_all($r);
	return $out === false ? array() : $out;
}

/** Sample ids in a project.json text. */
function sample_ids($text) {
	$ids = array();
	$j = json_decode($text);
	foreach ((isset($j->datasets) && is_array($j->datasets)) ? $j->datasets : array() as $d) {
		foreach ((isset($d->samples) && is_array($d->samples)) ? $d->samples : array() as $s) {
			if (is_object($s) && isset($s->id) && (string)$s->id !== '') $ids[] = (string)$s->id;
		}
	}
	return array_values(array_unique($ids));
}

function spine_rows($ids, $owner) {
	global $SPINE;
	$out = array();
	foreach ($ids as $sid) {
		$r = rows("SELECT " . implode(', ', $SPINE) . " FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($sid, $owner));
		if ($r) $out[$sid] = $r[0];
	}
	return $out;
}

/** True when $old is what the old upload code made of $new (or equal). */
function is_garbled_form($old, $new) {
	if ($old === $new) return true;
	if ($old === null || $new === null) return false;
	return $old === utf8_encode($new) || micro_utf8_fix_c1($old) === $new;
}

if ($apply && !is_dir($journal) && !@mkdir($journal, 0775, true)) {
	fwrite(STDERR, "journal folder $journal cannot be created (create it, owned by www-data, or pass --journal=DIR)\n");
	exit(2);
}
if ($apply && !is_writable($journal)) {
	fwrite(STDERR, "journal folder $journal is not writable by this user\n");
	exit(2);
}

$cand = rows(
	"SELECT id, strabo_id, userpkey, sharekey, projectjson FROM strabomicro.micro_projectmetadata
	  WHERE sync_format = 'legacy' AND projectjson ~ '[^\\x01-\\x7f]' ORDER BY id");
$stats = array('clean' => 0, 'utf8' => 0, 'cp1252' => 0, 'resumed' => 0, 'repaired' => 0, 'failed' => 0, 'spine_kept' => 0, 'disk_checked' => 0, 'disk_differs' => 0);
foreach ($cand as $p) {
	$pid = (int)$p['id'];
	if ($only !== null && !in_array($pid, $only, true)) continue;
	$jf = "$journal/$pid.json";
	$j = is_file($jf) ? json_decode(file_get_contents($jf), true) : null;
	if (is_array($j) && isset($j['state']) && $j['state'] === 'done') { $stats['clean']++; continue; }

	if (is_array($j) && isset($j['text'])) {
		$fix = array('text' => $j['text'], 'source' => $j['source']);
		$stats['resumed']++;
	} else {
		$fix = micro_projectjson_repair($p['projectjson']);
		if ($fix === null) { $stats['clean']++; continue; }
	}
	$stats[$fix['source']]++;

	// Cross-check with the unzipped upload when it is still the original.
	$disk = "/srv/app/www/straboMicroFiles/$pid/project.json";
	$diskNote = 'no project.json on disk';
	if (is_file($disk)) {
		$dt = micro_json_to_utf8(file_get_contents($disk));
		$same = json_decode($dt, true) == json_decode($fix['text'], true);
		$stats['disk_checked']++;
		if (!$same) $stats['disk_differs']++;
		$diskNote = $same ? 'on-disk project.json equal' : 'on-disk project.json differs (rewritten earlier from projectjson, or re-uploaded)';
	}
	preg_match_all('/[^\x00-\x7f]+/u', $fix['text'], $m);
	$chars = implode(' ', array_slice(array_values(array_unique($m[0])), 0, 10));
	echo "#$pid {$p['strabo_id']} user {$p['userpkey']}: {$fix['source']} file; $diskNote; text: $chars\n";
	if (!$apply) continue;

	$owner = (int)$p['userpkey'];
	$samples = sample_ids($fix['text']);
	try {
		if (!is_array($j)) {
			$j = array('pid' => $pid, 'straboId' => $p['strabo_id'], 'owner' => $owner, 'source' => $fix['source'],
				'oldProjectjson' => $p['projectjson'], 'text' => $fix['text'],
				'spineBefore' => spine_rows($samples, $owner), 'state' => 'started', 'at' => gmdate('c'));
			if (file_put_contents($jf, json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
				throw new Exception("journal $jf not written");
			}
		}
		$logMax = (int)rows("SELECT COALESCE(max(pkey), 0) AS m FROM strabosamples.sample_changelog")[0]['m'];

		$sm = new StraboMicro(null, $owner, $db);
		$sm->deleteProjectRows($p['strabo_id'], true);
		$res = $sm->loadProjectJSON($fix['text'], $pid, $p['sharekey'], true);
		if (is_object($res) && isset($res->Error) && $res->Error != '') throw new Exception('loadProjectJSON: ' . $res->Error);

		// Spine: keep values the rebuild should not have replaced.
		$after = spine_rows($samples, $owner);
		foreach ($j['spineBefore'] as $sid => $before) {
			if (!isset($after[$sid])) continue;
			$restore = array();
			foreach ($SPINE as $f) {
				if (!is_garbled_form($before[$f], $after[$sid][$f])) $restore[$f] = $before[$f];
			}
			$logRows = rows("SELECT pkey, changes::text AS changes FROM strabosamples.sample_changelog
			                  WHERE pkey > $1 AND sample_id = $2 AND sample_userpkey = $3 AND source_subsystem = 'micro'",
				array($logMax, $sid, $owner));
			if ($restore) {
				$sets = array(); $params = array($sid, $owner); $i = 3;
				foreach ($restore as $f => $v) { $sets[] = "$f = \$$i"; $params[] = $v; $i++; }
				q("UPDATE strabosamples.samples SET " . implode(', ', $sets) . " WHERE id = $1 AND userpkey = $2", $params);
				StraboSearchSync::touchSample($db, $sid, $owner);
				$stats['spine_kept'] += count($restore);
				echo "    sample $sid: kept later spine values for " . implode(', ', array_keys($restore)) . "\n";
			}
			foreach ($logRows as $lr) {
				$c = json_decode($lr['changes'], true);
				if (!is_array($c)) continue;
				if (isset($c['spine_diff']) && is_array($c['spine_diff'])) {
					foreach (array_keys($restore) as $f) unset($c['spine_diff'][$f]);
					if (!$c['spine_diff']) unset($c['spine_diff']);
				}
				$c['utf8_repair'] = true;
				q("UPDATE strabosamples.sample_changelog SET changes = $2::jsonb WHERE pkey = $1", array($lr['pkey'], json_encode($c)));
			}
		}

		micro_search_sync_project($db, $pid, $p['strabo_id'], $owner);
		q("UPDATE strabomicro.micro_projectmetadata SET files_dirty = true, pdf_dirty = true WHERE id = $1", array($pid));
		micro_regenerate_files_if_dirty($db, $pid, $owner);

		$j['state'] = 'done';
		$j['doneAt'] = gmdate('c');
		file_put_contents($jf, json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$stats['repaired']++;
		if ($verbose) echo "    rebuilt; " . count($samples) . " samples\n";
	} catch (Exception $e) {
		$stats['failed']++;
		echo "    FAILED: " . $e->getMessage() . " (journal kept; rerun resumes)\n";
	}
}

echo "\n" . count($cand) . " legacy projects with non-ASCII projectjson: "
	. "{$stats['utf8']} UTF-8 uploads garbled, {$stats['cp1252']} CP1252 uploads, {$stats['clean']} already clean or done"
	. ($stats['resumed'] ? ", {$stats['resumed']} resumed from the journal" : '')
	. "; on-disk check {$stats['disk_checked']} (" . $stats['disk_differs'] . " differ)\n";
echo $apply
	? "repaired {$stats['repaired']}, failed {$stats['failed']}, spine values kept {$stats['spine_kept']}; journal $journal\n"
	: "dry run; add --apply to repair\n";
exit($stats['failed'] ? 1 : 0);
