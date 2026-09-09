<?php
/**
 * File: tests/fieldbook/smoke_test_project_data.php
 * Description: M7 smoke suite for the enhanced fieldbook's project-level
 *              data (docs/Fieldbook_Design.md §14 M7): every project tag
 *              (spot tags, sub-feature tags, tags on spots outside the
 *              book, tags on no spot) and the memos (reports) with their
 *              audience rules (anyone / collaborators / only_me / none
 *              recorded), spots cited inside and outside the book, tags by
 *              name, images resolved by Image.filename, comments, extra
 *              fields; the reader identity on the web door ($strabo user)
 *              and the Export Builder worker (readerUserpkey); the PDF
 *              (Tags + Memos sections, bookmarks, hidden memos absent);
 *              the progress notes; the summary tables; blockScalars.
 *
 * Run: docker exec strabo-php php /srv/app/www/tests/fieldbook/smoke_test_project_data.php
 */
chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
ini_set('memory_limit', '2G');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/geophp/geoPHP.inc';
require_once 'includes/UUID.php';
require_once 'db/strabospotclass.php';
require_once 'includes/straboClasses/straboOutputClass.php';
require_once 'exportjobs/lib/export_config.php';
require_once 'includes/fieldbook/Fieldbook.php';

$OWNER = 94593; $COLLAB = 94594; $STRANGER = 94595;   // fieldbook M7 fixture users (M1 suite uses 94591)
$P1 = 945931001;
$DS_A = 945932001; $DS_B = 945932002;
$S1 = 1721030400201; $S2 = 1721034000202;   // 2024-07-15, dataset A (the book)
$S9 = 1721120400209;                        // 2024-07-16, dataset B (outside the book)
$ORI_ID = 945937001;
$IMG_M = 945934001; $IMG_M_FILE = 945934999;   // memo image: node id != stored filename (prod shape)
$T1 = 945936001; $T2 = 945936002; $T3 = 945936003; $T4 = 945936004;
$TMP = '/tmp/fb_m7_' . getmypid();
$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function rmrf($d) { if (is_dir($d)) exec('rm -rf ' . escapeshellarg($d)); }
function u16($s) { return '/Title (' . $s . ')'; }   // outline (bookmark) title as tFPDF writes an ASCII one
function cleanup() {
	global $db, $neodb, $OWNER, $COLLAB, $STRANGER, $P1, $IMG_M_FILE, $TMP;
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image) DETACH DELETE i, s");
	$neodb->query("MATCH (i:Image {userpkey: $OWNER}) DETACH DELETE i");
	$neodb->query("MATCH (u:User {userpkey: $OWNER})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) DETACH DELETE d, p");
	foreach (array($OWNER, $COLLAB, $STRANGER) as $u) $neodb->query("MATCH (u:User {userpkey: $u}) DETACH DELETE u");
	$db->prepare_query("DELETE FROM collaborators WHERE strabo_project_id = $1", array((string)$P1));
	$db->prepare_query("DELETE FROM project WHERE user_pkey = $1", array($OWNER));
	foreach (array($OWNER, $COLLAB, $STRANGER) as $u) $db->prepare_query("DELETE FROM users WHERE pkey = $1", array($u));
	@unlink("/srv/app/www/dbimages/$IMG_M_FILE");
	rmrf($TMP);
}
echo "Enhanced fieldbook M7 smoke suite (project tags + memos)\n";
cleanup();
mkdir($TMP, 0775, true);

// ------------------------------------------------------------------ fixtures
foreach (array($OWNER => array('Memo', 'Owner'), $COLLAB => array('Cora', 'Collaborator'), $STRANGER => array('Stan', 'Stranger')) as $u => $nm) {
	$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active) VALUES ($1, $2, $3, $4, 'x', 'x', false)", array($u, $nm[0], $nm[1], "fb-$u@test.strabospot.org"));
	$neodb->query("CREATE (u:User {userpkey: $u, email: 'fb-$u@test.strabospot.org'})");
}
$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled) VALUES ($1, $2, $3, 'full', true, false)", array((string)$P1, $OWNER, $COLLAB));
$TAGS = json_encode(array(
	array('id' => $T1, 'name' => 'Field checked', 'type' => 'documentation', 'documentation_type' => 'observation_timing', 'notes' => 'Checked in the field', 'color' => '#FF0000', 'spots' => array($S1)),
	array('id' => $T2, 'name' => 'Basalt of Nowhere', 'type' => 'geologic_unit', 'unit_label_abbreviation' => 'Tbn', 'rock_type' => 'igneous'),   // no spots at all
	array('id' => $T3, 'name' => 'Bedding of note', 'type' => 'concept', 'concept_type' => 'geological_structure', 'features' => array((string)$S1 => array($ORI_ID))),   // on an orientation of S1
	array('id' => $T4, 'name' => 'Elsewhere only', 'type' => 'other', 'other_type' => 'misc', 'spots' => array($S9)),   // only on a spot outside the book
));
$REPORTS = json_encode(array(
	array('id' => 'r1-anyone', 'subject' => 'Anyone memo', 'report_type' => 'summary', 'report_privacy' => 'anyone', 'notes' => 'Visible to everyone.',
		'created_timestamp' => 1721100000000, 'updated_timestamp' => 1721200000000, 'spots' => array($S1, $S9), 'tags' => array($T1, $T3), 'images' => array(array('id' => $IMG_M, 'width' => 64, 'height' => 48, 'caption' => 'memo photo')),
		'comments' => array(array('id' => 1, 'name' => 'Cora Collaborator', 'straboUserId' => $COLLAB, 'text' => 'Agreed.', 'created_timestamp' => 1721150000000), array('id' => 2, 'name' => 'Memo Owner', 'straboUserId' => $OWNER, 'text' => 'Thanks.', 'created_timestamp' => 1721160000000))),
	array('id' => 'r2-collab', 'subject' => 'Collaborators memo', 'report_type' => 'hypothesis', 'report_privacy' => 'collaborators', 'straboUserId' => $COLLAB, 'notes' => 'For the team.', 'created_timestamp' => 1721000000000, 'updated_timestamp' => 1721000000000, 'spots' => array($S2), 'tags' => array(), 'images' => array()),
	array('id' => 'r3-owner-only', 'subject' => 'Owner private memo', 'report_type' => 'question', 'report_privacy' => 'only_me', 'straboUserId' => $OWNER, 'notes' => 'Owner eyes only.', 'created_timestamp' => 1721300000000, 'updated_timestamp' => 1721300000000),
	array('id' => 'r4-collab-only', 'subject' => 'Collaborator private memo', 'report_type' => 'contemplation', 'report_privacy' => 'only_me', 'straboUserId' => $COLLAB, 'notes' => 'Collaborator eyes only.', 'created_timestamp' => 1721400000000, 'updated_timestamp' => 1721400000000),
	array('id' => 'r5-legacy', 'subject' => 'Legacy memo', 'notes' => 'No audience recorded.', 'created_timestamp' => 1720900000000, 'updated_timestamp' => 1720900000000, 'custom_note' => 'kept as a field'),
	array('id' => 'r6-old-privacy', 'subject' => 'Old privacy key memo', 'report_type' => 'other', 'privacy' => 'anyone', 'notes' => 'Older key spelling.', 'created_timestamp' => 1720800000000, 'updated_timestamp' => 1720800000000),
));
$esc = function ($s) { return str_replace(array('\\', "'"), array('\\\\', "\\'"), $s); };
$neodb->query("MATCH (u:User {userpkey: $OWNER}) CREATE (p:Project {id: $P1, userpkey: $OWNER, desc_project_name: 'Memo Fixture Project', json_tags: '" . $esc($TAGS) . "', json_reports: '" . $esc($REPORTS) . "'}) CREATE (u)-[:HAS_PROJECT]->(p)");
$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Memo Fixture Project', $2, FALSE)", array($OWNER, (string)$P1));
foreach (array($DS_A => 'Book Dataset', $DS_B => 'Other Dataset') as $did => $dn) {
	$neodb->query("MATCH (p:Project {id: $P1, userpkey: $OWNER}) CREATE (d:Dataset {id: $did, userpkey: $OWNER, name: '$dn'}) CREATE (p)-[:HAS_DATASET]->(d)");
}
function spot($did, $id, $name, $wkt, $extra = '') {
	global $neodb, $OWNER;
	$neodb->query("MATCH (d:Dataset {id: $did, userpkey: $OWNER}) CREATE (s:Spot {id: $id, userpkey: $OWNER, name: '$name', wkt: '$wkt', origwkt: '$wkt',
		modified_timestamp: 1722400000000, date: '2024-07-15T10:00:00Z', time: '2024-07-15T10:00:00Z', notes: 'notes for $name' $extra}) CREATE (d)-[:HAS_SPOT]->(s)");
}
$ORI = '[{\"strike\": 120, \"dip\": 30, \"type\": \"planar_orientation\", \"feature_type\": \"bedding\", \"id\": ' . $ORI_ID . '}]';
spot($DS_A, $S1, 'M7 Station 1', 'POINT (-118.25 34.05)', ", json_orientation_data: '$ORI'");
spot($DS_A, $S2, 'M7 Station 2', 'POINT (-118.26 34.04)');
spot($DS_B, $S9, 'M7 Far Station', 'POINT (-118.30 34.00)');
$im = imagecreatetruecolor(64, 48); imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 200, 120, 40)); imagejpeg($im, "/srv/app/www/dbimages/$IMG_M_FILE", 80); imagedestroy($im);
$neodb->query("CREATE (i:Image {id: $IMG_M, userpkey: $OWNER, filename: '$IMG_M_FILE', width: 64, height: 48})");   // a memo image: no spot
Fieldbook::$mapsOverride = array('set' => 'none');

$strabo = new StraboSpot($neodb, $OWNER, $db); $strabo->setuuid(new UUID());
$GET = array('dsids' => (string)$DS_A, 'userpkey' => $OWNER);
$json = $strabo->getDatasetSpotsSearch(null, $GET);
check('fixture fetch: 2 features in the book dataset', count($json['features']) === 2, count($json['features']));
$tree = Fieldbook::treeFromNeo4j($strabo, $OWNER, array($DS_A));
$tags = $strabo->getTagsFromDatasetIds((string)$DS_A);
$meta = Fieldbook::meta($strabo, $OWNER, $tree, $GET);
$notes = array((string)$DS_A => array());

// ------------------------------------------------------------------ 1. projectData: audience per reader
$pdOwner = Fieldbook::projectData($strabo, $tree, $OWNER);
$key = "$OWNER|$P1";
check('projectData keyed by owner|project, 4 tags', isset($pdOwner[$key]) && count($pdOwner[$key]['tags']) === 4, json_encode(array_keys($pdOwner)));
$ids = function ($pd) use ($key) { $o = array(); foreach ($pd[$key]['reports'] as $r) $o[] = $r['id']; sort($o); return $o; };
check('owner sees anyone + collaborators + own only_me + legacy + old-key (5), 1 hidden', $ids($pdOwner) === array('r1-anyone', 'r2-collab', 'r3-owner-only', 'r5-legacy', 'r6-old-privacy') && $pdOwner[$key]['hidden'] === 1, json_encode($ids($pdOwner)) . ' hidden=' . $pdOwner[$key]['hidden']);
$pdCollab = Fieldbook::projectData($strabo, $tree, $COLLAB);
check('collaborator sees anyone + collaborators + own only_me + legacy + old-key (5), 1 hidden', $ids($pdCollab) === array('r1-anyone', 'r2-collab', 'r4-collab-only', 'r5-legacy', 'r6-old-privacy') && $pdCollab[$key]['hidden'] === 1, json_encode($ids($pdCollab)));
$pdStranger = Fieldbook::projectData($strabo, $tree, $STRANGER);
check('stranger sees only the two "anyone" memos, 4 hidden', $ids($pdStranger) === array('r1-anyone', 'r6-old-privacy') && $pdStranger[$key]['hidden'] === 4, json_encode($ids($pdStranger)));
check('authors resolved by name (owner + collaborator)', $pdOwner[$key]['authors'][$OWNER] === 'Memo Owner' && $pdOwner[$key]['authors'][$COLLAB] === 'Cora Collaborator', json_encode($pdOwner[$key]['authors']));
check('spot names looked up for cited spots, including the one outside the book', $pdOwner[$key]['spot_names'][(string)$S9] === 'M7 Far Station' && $pdOwner[$key]['spot_names'][(string)$S1] === 'M7 Station 1', json_encode($pdOwner[$key]['spot_names']));
$db->prepare_query("UPDATE collaborators SET disabled = true WHERE strabo_project_id = $1", array((string)$P1));
$pdDisabled = Fieldbook::projectData($strabo, $tree, $COLLAB);
check('a disabled collaborator row no longer opens "collaborators" memos (the legacy one drops; their own two stay as author)', $ids($pdDisabled) === array('r1-anyone', 'r2-collab', 'r4-collab-only', 'r6-old-privacy'), json_encode($ids($pdDisabled)));
$db->prepare_query("UPDATE collaborators SET disabled = false WHERE strabo_project_id = $1", array((string)$P1));
check('projectData without a project id in the tree returns nothing', Fieldbook::projectData($strabo, array(array('owner' => $OWNER, 'project_id' => '', 'dsids' => array(), 'dataset_names' => array(), 'spot_map' => array())), $OWNER) === array());

// ------------------------------------------------------------------ 2. model: tags
$m = FieldbookModel::build($json['features'], $tags, $notes, $tree, $meta, $pdOwner);
$p = $m->projects[0];
$byName = array(); foreach ($p['tags'] as $t) $byName[$t['name']] = $t;
check('project tags: all four listed, geologic unit first then by name', array_keys($byName) === array('Basalt of Nowhere', 'Bedding of note', 'Elsewhere only', 'Field checked'), json_encode(array_keys($byName)));
check('tag on a spot in the book: 1 in book, 0 outside', count($byName['Field checked']['inBook']) === 1 && $byName['Field checked']['inBook'][0]['name'] === 'M7 Station 1' && $byName['Field checked']['outside'] === 0);
check('tag on no spot: nothing in book, nothing outside', $byName['Basalt of Nowhere']['inBook'] === array() && $byName['Basalt of Nowhere']['outside'] === 0);
check('tag on a spot outside the book: 0 in book, 1 outside', $byName['Elsewhere only']['inBook'] === array() && $byName['Elsewhere only']['outside'] === 1);
check('sub-feature tag: counted on its spot, 1 feature', count($byName['Bedding of note']['inBook']) === 1 && $byName['Bedding of note']['features'] === 1);
$rowsOf = function ($t) { $o = array(); foreach ($t['rows'] as $r) $o[$r['k']] = $r['v']; return $o; };
$fr = $rowsOf($byName['Field checked']);
check('tag fields kept (documentation type, notes, color); spots/id/name/type not repeated', $fr['Documentation type'] === 'Observation timing' && $fr['Notes'] === 'Checked in the field' && $fr['Color'] === '#FF0000' && !isset($fr['Spots']) && !isset($fr['Id']), json_encode($fr));
check('counts: 1 geologic unit + 3 tags (counted apart, colleague 2026-09-09), 5 memos, 1 hidden', $m->counts['units'] === 1 && $m->counts['tags'] === 3 && $m->counts['memos'] === 5 && $m->counts['hiddenMemos'] === 1, json_encode($m->counts));
check('summary Tags table lists every project tag with its in-book count (0 allowed)', isset($m->summary['tags']['Elsewhere only']) && $m->summary['tags']['Elsewhere only']['count'] === 0 && $m->summary['tags']['Field checked']['count'] === 1 && $m->summary['tags']['Bedding of note']['count'] === 1, json_encode(array_map(function ($t) { return $t['count']; }, $m->summary['tags'])));
check('summary Geologic units table lists the unattached unit with 0', isset($m->summary['units']['Basalt of Nowhere']) && $m->summary['units']['Basalt of Nowhere']['count'] === 0);
$s1 = null; foreach ($p['datasets'][0]['days'][0]['spots'] as $s) if ($s['id'] === (string)$S1) $s1 = $s;
$s1tags = array(); foreach ($s1['tags'] as $t) $s1tags[$t['name']] = $t;
check('spot block: spot tag and sub-feature tag both on Station 1', isset($s1tags['Field checked']) && isset($s1tags['Bedding of note']), json_encode(array_keys($s1tags)));
check('sub-feature tag names the feature: "Bedding (orientation)"', $s1tags['Bedding of note']['on'] === array('Bedding (orientation)'), json_encode($s1tags['Bedding of note']['on']));
check('spot block: no tag from outside the spot', !isset($s1tags['Elsewhere only']) && !isset($s1tags['Basalt of Nowhere']));
$sc = array(); FieldbookModel::blockScalars($s1, $sc);
check('blockScalars includes the feature label (completeness)', in_array('Bedding (orientation)', $sc, true));
check('featureLabel falls back to the bare id', FieldbookModel::featureLabel(array('a' => array('id' => 1)), 42) === 'Feature 42');

// ------------------------------------------------------------------ 3. model: memos
$memos = $p['memos'];
$subjects = array_map(function ($x) { return $x['subject']; }, $memos);
check('memos in creation order', $subjects === array('Old privacy key memo', 'Legacy memo', 'Collaborators memo', 'Anyone memo', 'Owner private memo'), json_encode($subjects));
$r1 = $memos[3];
check('memo: type, audience, author, dates', $r1['type'] === 'Summary' && $r1['audience'] === 'Anyone' && $r1['author'] === 'Memo Owner' && $r1['created'] === 'July 16, 2024 03:20 UTC' && $r1['updated'] === 'July 17, 2024 07:06 UTC', json_encode(array($r1['type'], $r1['audience'], $r1['author'], $r1['created'], $r1['updated'])));
check('memo: cited spots named, in-book flag right for the one outside', count($r1['spots']) === 2 && $r1['spots'][0] === array('id' => (string)$S1, 'name' => 'M7 Station 1', 'inBook' => true) && $r1['spots'][1] === array('id' => (string)$S9, 'name' => 'M7 Far Station', 'inBook' => false), json_encode($r1['spots']));
check('memo: tags by name', $r1['tags'] === array('Field checked', 'Bedding of note'), json_encode($r1['tags']));
check('memo: image block + caption', count($r1['images']) === 1 && $r1['images'][0]['id'] === (string)$IMG_M && $r1['images'][0]['caption'] === 'memo photo');
check('memo: comments with name, text, date', count($r1['comments']) === 2 && $r1['comments'][0]['name'] === 'Cora Collaborator' && $r1['comments'][0]['text'] === 'Agreed.' && $r1['comments'][0]['date'] === 'July 16, 2024 17:13 UTC', json_encode($r1['comments']));
$r2 = $memos[2];
check('memo by the collaborator: author name, audience Collaborators', $r2['author'] === 'Cora Collaborator' && $r2['audience'] === 'Collaborators');
$r5 = $memos[1];
$r5rows = $rowsOf($r5);
check('legacy memo: audience shown as Collaborators, extra field kept, unchanged date shows once', $r5['audience'] === 'Collaborators' && $r5rows['Custom note'] === 'kept as a field' && $r5['updated'] === '' && $r5['type'] === '', json_encode(array($r5['audience'], $r5rows, $r5['updated'])));
check('memo image counted in the book photos and listed in the image summary under "Memo: …"', $m->counts['images'] === 1 && count($m->summary['images']) === 1 && $m->summary['images'][0]['spot'] === 'Memo: Anyone memo', json_encode($m->summary['images']));
check('imageIds includes the memo image', in_array((string)$IMG_M, $m->imageIds(), true));
check('hidden memos note for the colophon', count($m->notes) === 1 && strpos($m->notes[0], '1 memo is not shown') !== false, json_encode($m->notes));
check('filename lookup resolves the memo image by Image.filename', Fieldbook::imageFilenames($strabo, array($IMG_M)) === array((string)$IMG_M => (string)$IMG_M_FILE));
$m0 = FieldbookModel::build($json['features'], $tags, $notes, $tree, $meta);
check('build without project data: no tags/memos sections, counts zero, spot tags unchanged', $m0->projects[0]['tags'] === array() && $m0->projects[0]['memos'] === array() && $m0->counts['memos'] === 0 && $m0->counts['tags'] === 0 && $m0->notes === array());

// ------------------------------------------------------------------ 4. door + PDF (owner reader on the web door)
function capture_run($strabo, $get, $dir, $reader = null, $progress = null) {
	rmrf($dir); mkdir($dir, 0775, true);
	$out = new straboOutputClass($strabo, $get); $out->captureDir = $dir;
	if ($reader !== null) $out->readerUserpkey = $reader;
	if ($progress) $out->progress = $progress;
	ob_start(); $out->fieldbookOut(); $stray = ob_get_clean();
	return array($out->captured, $stray);
}
function pdf_pages($bytes) { return preg_match_all('#/Type /Page(?!s)#', $bytes); }
$stages = array();
list($cap, $stray) = capture_run($strabo, $GET, "$TMP/owner", null, function ($stage, $done, $total, $note) use (&$stages) { $stages[] = array($stage, $note); });
check('web door (reader = $strabo user = owner): one PDF, no stray output', count($cap) === 1 && $stray === '', $stray);
$pdf = file_get_contents($cap[0]['path']);
check('PDF has Tags + Memos bookmarks and the visible memo subjects', strpos($pdf, u16('Tags and geologic units')) !== false && strpos($pdf, 'Geologic units') !== false && strpos($pdf, u16('Memos')) !== false && strpos($pdf, u16('Anyone memo')) !== false && strpos($pdf, u16('Owner private memo')) !== false && strpos($pdf, u16('Collaborators memo')) !== false);
check('PDF omits the hidden memo (collaborator only_me)', strpos($pdf, u16('Collaborator private memo')) === false);
check('PDF pages >= 4', pdf_pages($pdf) >= 4, pdf_pages($pdf));
$notesSeen = array_map(function ($s) { return $s[1]; }, $stages);
check('progress: gather note counts memos; build notes for Tags and geologic units (4) and Memos (5)', in_array('Tags and geologic units (4)', $notesSeen, true) && in_array('Memos (5)', $notesSeen, true) && count(array_filter($notesSeen, function ($n) { return strpos($n, '5 memos') !== false; })) === 1, json_encode($notesSeen));

// ------------------------------------------------------------------ 5. worker reader identity (readerUserpkey overrides the $strabo user)
list($cap2) = capture_run($strabo, $GET + array('book_tree' => array(array('owner' => $OWNER, 'project_id' => (string)$P1, 'project_name' => 'Memo Fixture Project', 'dsids' => array((string)$DS_A), 'dataset_names' => array((string)$DS_A => 'Book Dataset'), 'spot_map' => array((string)$S1 => array('ds' => (string)$DS_A, 'name' => 'M7 Station 1'), (string)$S2 => array('ds' => (string)$DS_A, 'name' => 'M7 Station 2'))))), "$TMP/stranger", $STRANGER);
$pdf2 = file_get_contents($cap2[0]['path']);
check('worker door as a stranger (readerUserpkey): only "anyone" memos in the PDF', strpos($pdf2, u16('Anyone memo')) !== false && strpos($pdf2, u16('Old privacy key memo')) !== false && strpos($pdf2, u16('Owner private memo')) === false && strpos($pdf2, u16('Collaborators memo')) === false && strpos($pdf2, u16('Legacy memo')) === false);
list($cap3) = capture_run($strabo, $GET, "$TMP/collab", $COLLAB);
$pdf3 = file_get_contents($cap3[0]['path']);
check('worker door as the collaborator: their private memo in, the owner\'s out', strpos($pdf3, u16('Collaborator private memo')) !== false && strpos($pdf3, u16('Owner private memo')) === false);
check('Tags section present even when the reader is a stranger (tags have no audience)', strpos($pdf2, u16('Tags')) !== false && strpos($pdf2, u16('Basalt of Nowhere')) === false);   // tags are not bookmarked, sections are

// ------------------------------------------------------------------ 6. a project with no tags and no reports: nothing added
$neodb->query("MATCH (p:Project {id: $P1, userpkey: $OWNER}) SET p.json_tags = '', p.json_reports = ''");
$pdNone = Fieldbook::projectData($strabo, $tree, $OWNER);
$mNone = FieldbookModel::build($json['features'], '', $notes, $tree, $meta, $pdNone);
check('empty json_tags / json_reports: no sections, no notes', $pdNone[$key]['tags'] === array() && $pdNone[$key]['reports'] === array() && $mNone->projects[0]['tags'] === array() && $mNone->projects[0]['memos'] === array() && $mNone->notes === array());
list($cap4) = capture_run($strabo, $GET, "$TMP/none");
$pdf4 = file_get_contents($cap4[0]['path']);
check('PDF without project data has no Memos bookmark', strpos($pdf4, u16('Memos')) === false && count($cap4) === 1);

cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
