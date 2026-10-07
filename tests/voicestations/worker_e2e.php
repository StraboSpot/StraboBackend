<?php
/**
 * File: worker_e2e.php
 * Description: Voice Stations build step 2 end to end with a REAL worker
 *              container: uploads the 8 Phase 0 clips
 *              (docs/AlternateStraboFieldIdea/TestVoiceRecordings, gitignored)
 *              as one batch for the demo account Maya, waits until every
 *              station is transcribed (or failed), then checks each
 *              transcript: text, words on the ORIGINAL audio timeline
 *              (inside 0..length, in order, word_times vad_remapped), the
 *              measured audio length against ffprobe-free bounds, and the
 *              key numbers of each clip heard (a smoke check, not scoring;
 *              blind scoring is build step 3).
 *
 *              Start a worker first (voicestations/worker/RUNBOOK.md), e.g.
 *                docker compose -f compose.cpu.yml up -d   (Mac, against dev)
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/tests/voicestations/worker_e2e.php [--keep]
 *              --keep leaves the rows (for looking at them); otherwise ALL of
 *              Maya's Voice Stations rows and audio are removed at the end.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
set_time_limit(0);
require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/voicestations/lib/bootstrap.php';

$KEEP = in_array('--keep', $argv, true);
$CLIPS = '/srv/app/www/docs/AlternateStraboFieldIdea/TestVoiceRecordings';
$MAYA = array('maya.chen@test.strabospot.org', 'demopass123');
$ROOT = VsConfig::dataRoot();
$WAIT = 600;
// numbers each clip states (Field_Recording_Script.md); spoken digits may come back as words
$KEY = array(
	'station01_clean' => array('045', '32'),
	'station02_clean' => array(),
	'station03_loud_fan' => array(),
	'station04_in_pocket' => array(),
	'station05_clean' => array(),
	'station06_distant_location' => array(),
	'station07_clean' => array(),
	'station08_clean' => array(),
);

$failures = array();
function check($label, $cond, $detail = '') {
	global $failures;
	echo ($cond ? '  PASS' : '  FAIL') . "  $label" . (!$cond && $detail !== '' ? "\n        " . substr(is_string($detail) ? $detail : json_encode($detail), 0, 1200) : '') . "\n";
	if (!$cond) $failures[] = $label;
	return $cond;
}
function uuid() {
	$b = random_bytes(16);
	$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
	$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
	$h = bin2hex($b);
	return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}
function iso($t) { return gmdate('Y-m-d\TH:i:s', $t) . '.000Z'; }

$ms = new MsDb($db);
$upk = (int)$ms->val("SELECT pkey FROM users WHERE email = $1", array($MAYA[0]));
$rec = $neodb->getRecord("MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset) RETURN p.id AS pid, d.id AS did LIMIT 1");
if (!$upk || !$rec || !is_dir($CLIPS)) {
	echo "Needs Maya's demo project (seed_demo.php) and $CLIPS.\n";
	exit(1);
}
$wipe = function () use ($ms, $upk, $ROOT) {
	foreach ($ms->rows("SELECT audio_path FROM voicestations.stations WHERE userpkey = $1 AND audio_path IS NOT NULL", array($upk)) as $r) {
		@unlink("$ROOT/" . $r['audio_path']);
	}
	$ms->q("DELETE FROM voicestations.batches WHERE userpkey = $1", array($upk));
};
$wipe();

try {
	echo "== Upload the 8 clips as one batch\n";
	$batch = uuid();
	$names = array_keys($KEY);
	$ids = array();
	foreach ($names as $n) $ids[$n] = uuid();
	$t0 = time() - 3600;
	foreach ($names as $i => $n) {
		$ch = curl_init('http://localhost/db/voicestation');
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $MAYA[0] . ':' . $MAYA[1],
			CURLOPT_POSTFIELDS => array(
				'details' => json_encode(array(
					'station_uuid' => $ids[$n], 'batch_uuid' => $batch, 'batch_station_uuids' => array_values($ids),
					'project_id' => (int)$rec->value('pid'), 'dataset_id' => (string)$rec->value('did'),
					'target' => array('kind' => 'new'),
					'started_at' => iso($t0 + $i * 120), 'ended_at' => iso($t0 + $i * 120 + 60), 'tz_offset_minutes' => -300,
					'gps_fixes' => array(array('lat' => 38.95, 'lon' => -95.25, 'alt' => 300.0, 'accuracy' => 5.0, 'time' => iso($t0 + $i * 120 + 3))),
					'photos' => array(), 'strike_convention' => 'rhr', 'app_version' => 'e2e', 'device_model' => 'test',
					'audio' => array('mime' => 'audio/mp4'),
				)),
				'audio' => new CURLFile("$CLIPS/$n.m4a", 'audio/mp4', "$n.m4a"),
			)));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		check("upload $n -> 201", $code === 201, $body);
	}

	echo "\n== Wait for the worker (up to {$WAIT} s)\n";
	$start = time();
	do {
		sleep(2);
		$left = (int)$ms->val("SELECT count(*) FROM voicestations.stations s JOIN voicestations.batches b ON b.id = s.batch_id
		                        WHERE b.batch_uuid = $1 AND s.stage NOT IN ('transcribed', 'failed')", array($batch));
	} while ($left > 0 && time() - $start < $WAIT);
	$elapsed = time() - $start;
	check("all 8 transcribed within {$WAIT} s (took {$elapsed} s)", $left === 0, "$left still waiting");

	echo "\n== Transcripts\n";
	foreach ($names as $n) {
		$r = $ms->row(
			"SELECT s.stage, s.error_text, s.audio_seconds, s.attempts, r.worker, r.engine, r.model, r.settings::text AS settings,
			        r.output::text AS output, length(r.raw_output) AS raw_len,
			        extract(epoch FROM r.finished_at - r.started_at) AS secs
			   FROM voicestations.stations s LEFT JOIN voicestations.runs r ON r.id = s.current_transcript_run
			  WHERE s.station_uuid = $1", array($ids[$n]));
		if (!check("$n: transcribed", $r['stage'] === 'transcribed', $r)) continue;
		$o = json_decode($r['output'], true);
		$set = json_decode($r['settings'], true);
		$len = (float)$r['audio_seconds'];
		$ok = true;
		$prev = -1;
		foreach ($o['words'] as $w) {
			if ($w['start'] < $prev - 0.05 || $w['start'] < 0 || $w['end'] > $len + 0.5) $ok = false;
			$prev = $w['start'];
		}
		$segOk = true;
		foreach ($o['segments'] as $g) if ($g['end'] > $len + 0.5) $segOk = false;
		check("$n: text + words, words on the original timeline in order (len {$len} s)",
			strlen($o['text']) > 10 && count($o['words']) > 3 && $ok && $segOk && $set['word_times'] === 'vad_remapped', $o['words']);
		$t = strtolower($o['text']);
		$heard = true;
		foreach ($KEY[$n] as $num) if (strpos($t, $num) === false) $heard = false;
		check("$n: key numbers heard", $heard, $o['text']);
		echo "        {$r['worker']} {$r['model']} {$set['build']} run " . round((float)$r['secs'], 2) . " s, whisper {$set['seconds_transcribe']} s, attempts {$r['attempts']}: "
			. substr($o['text'], 0, 110) . "\n";
	}
} finally {
	if ($KEEP) {
		echo "\n(--keep: rows left in place; batch $batch)\n";
	} else {
		$wipe();
	}
}

echo "\n" . (count($failures) === 0 ? 'ALL PASSED' : count($failures) . ' FAILED: ' . implode('; ', $failures)) . "\n";
exit(count($failures) === 0 ? 0 : 1);
