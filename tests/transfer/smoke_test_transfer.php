<?php
/**
 * File: tests/transfer/smoke_test_transfer.php
 * Description: Smoke suite for the StraboField project ownership transfer
 *              service (includes/transfer/ProjectTransfer.php, design
 *              docs/ProjectTransfer_Design.md). Headless: drives the service
 *              directly, no pages, no mail.
 *
 *              Fixtures (all under the 9459 numeric prefix, disjoint from
 *              every other suite): six accounts on @test.strabospot.org, one
 *              project with two datasets, spots with images, orientations, a
 *              legacy Tag node + IS_TAGGED edge, spine samples (rich + legacy
 *              with a parent link, composition, seeded collaborators), an
 *              accepted collaborator who stays, the RECIPIENT as an accepted
 *              readonly collaborator (with a mirror copy row), a version,
 *              verlog, vprojects, a merge pref, a DOI, and a live search
 *              index slice.
 *
 *              Coverage:
 *                A. request: neutral message always; unknown / self /
 *                   pending-exists; refused audit rows carry the reason.
 *                B. eligibility negatives: deleted, inactive, no :User node,
 *                   same-id project, same-id version, sample id clash.
 *                C. accept + full store audit (verify clean + per-store
 *                   assertions), DOI stays, collaborator outcomes, sample
 *                   changelog rows, parent pointer, search slice.
 *                D. D4 guard: old owner refused with the transfer date,
 *                   recipient allowed; execute on a done row is a no-op.
 *                E. reverse (admin): clean in the other direction, the
 *                   recipient's collaborator row restored, the admin row gone,
 *                   tombstone flips, a fresh transfer back is allowed again.
 *                F. failure injection at step 4: row failed + step 3 +
 *                   applied, retry completes, verify clean.
 *                G. cancel / decline / expire.
 *
 *              Run inside the container:
 *                docker exec strabo-php php /srv/app/www/tests/transfer/smoke_test_transfer.php
 *
 * @package    StraboSpot Tests
 */

chdir('/srv/app/www');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';
require_once 'includes/config.inc.php';
require_once 'db.php';
require_once 'neodb.php';
require_once 'includes/transfer/ProjectTransfer.php';
require_once 'db/services/CollaborationAuth.php';
require_once 'searchdb/sync/StraboSearchSync.php';
require_once 'samplesdb/services/StraboSamplesService.php';

$OWNER = 94591; $RECIP = 94592; $COLLAB = 94593; $DELETED = 94594; $INACTIVE = 94595; $OTHER = 94596; $NONODE = 94597;
$ALL_USERS = array($OWNER, $RECIP, $COLLAB, $DELETED, $INACTIVE, $OTHER, $NONODE);
$P = 945911001; $DS1 = 945912001; $DS2 = 945912002;
$S1 = 945913001; $S2 = 945913002; $S3 = 945913003;   // S1 rich sample-spot, S2 has legacy samples, S3 in DS2
$IMG1 = 945914001; $IMG2 = 945914002;
$ORI1 = 945915001; $ORI1A = 945915002;
$TAG1 = 945916001;
$SMP_RICH = (string)$S1; $SMP_LEG = 'smp-94591-legacy'; $SMP_LEG2 = 'smp-94591-legacy2';
$ADMIN = 3;

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
	global $pass, $fail;
	if ($cond) { $pass++; echo "  PASS  $name\n"; }
	else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  [" . substr(is_string($detail) ? $detail : json_encode($detail), 0, 600) . "]" : '') . "\n"; }
}
function section($t) { echo "\n== $t\n"; }
function pgv($sql, $params = array()) { global $db; return $db->get_var_prepared($sql, $params); }
function pgn($sql, $params = array()) { return (int)pgv($sql, $params); }

function cleanup() {
	global $db, $neodb, $ALL_USERS, $P, $TAG1;
	foreach ($ALL_USERS as $u) {
		$neodb->query("MATCH (u:User {userpkey: $u})-[:HAS_PROJECT]->(p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot) OPTIONAL MATCH (s)-[*1..2]->(x) WHERE NOT x:Spot AND NOT x:Dataset AND NOT x:Project AND NOT x:User DETACH DELETE x, s");
		$neodb->query("MATCH (u:User {userpkey: $u})-[:HAS_PROJECT]->(p:Project) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) DETACH DELETE d, p");
		// property-only leftovers (the suite creates projects with the edge, but be thorough)
		$neodb->query("MATCH (p:Project {userpkey: $u}) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d:Dataset) OPTIONAL MATCH (d)-[:HAS_SPOT]->(s:Spot) DETACH DELETE s, d, p");
		$neodb->query("MATCH (u:User {userpkey: $u}) DETACH DELETE u");
	}
	$neodb->query("MATCH (t:Tag {id: $TAG1}) DETACH DELETE t");
	$in = implode(',', $ALL_USERS);
	$db->query("DELETE FROM project_transfers WHERE from_user_pkey IN ($in) OR to_user_pkey IN ($in)");
	$db->query("DELETE FROM collaborators WHERE project_owner_user_pkey IN ($in) OR collaborator_user_pkey IN ($in)");
	$db->query("DELETE FROM project WHERE user_pkey IN ($in)");
	$db->query("DELETE FROM versions WHERE userpkey IN ($in)");
	$db->query("DELETE FROM verlog WHERE userpkey IN ($in)");
	$db->query("DELETE FROM vprojects WHERE userpkey IN ($in)");
	$db->query("DELETE FROM project_merge_prefs WHERE project_owner_user_pkey IN ($in)");
	$db->query("DELETE FROM dois WHERE user_pkey IN ($in)");
	$db->query("DELETE FROM strabosamples.samples WHERE userpkey IN ($in)");
	$db->query("DELETE FROM strabosearch.item_hit WHERE item_userpkey IN ($in) OR project_userpkey IN ($in)");
	$db->query("DELETE FROM strabosearch.image_hit WHERE image_userpkey IN ($in) OR project_userpkey IN ($in)");
	$db->query("DELETE FROM users WHERE pkey IN ($in)");
}

/** Build the whole fixture world with $owner owning the project and $recip as a readonly collaborator. */
function seed($owner, $recip) {
	global $db, $neodb, $COLLAB, $DELETED, $INACTIVE, $OTHER, $NONODE, $P, $DS1, $DS2, $S1, $S2, $S3, $IMG1, $IMG2, $ORI1, $ORI1A, $TAG1, $SMP_RICH, $SMP_LEG, $SMP_LEG2, $ALL_USERS;
	$names = array($owner => 'Olive', $recip => 'Rex', $COLLAB => 'Cody', $DELETED => 'Del', $INACTIVE => 'Ina', $OTHER => 'Otto', $NONODE => 'Nona');
	foreach ($ALL_USERS as $u) {
		$db->prepare_query("INSERT INTO users (pkey, firstname, lastname, email, password, hash, active, deleted) VALUES ($1, $2, 'Fixture', $3, 'x', 'x', $4, $5)",
			array($u, $names[$u], strtolower($names[$u]) . "-$u@test.strabospot.org", $u === $INACTIVE ? 'f' : 't', $u === $DELETED ? 't' : 'f'));
	}
	foreach (array($owner, $recip, $COLLAB, $OTHER, $DELETED) as $u) {
		$neodb->query("CREATE (u:User {userpkey: $u, email: '" . strtolower($names[$u]) . "-$u@test.strabospot.org'})");
	}
	$tags = json_encode(array(array('id' => 9459170001, 'name' => 'Granite', 'type' => 'geologic_unit', 'spots' => array($S1, $S2))));
	$tagsEsc = str_replace("'", "\\'", $tags);
	$neodb->query("MATCH (u:User {userpkey: $owner}) CREATE (p:Project {id: $P, userpkey: $owner, desc_project_name: 'Transfer Fixture', modified_timestamp: 1725000000000, json_tags: '$tagsEsc', centroid: 'POINT (-118.25 34.05)'}) CREATE (u)-[:HAS_PROJECT]->(p)");
	foreach (array($DS1 => 'Day One', $DS2 => 'Day Two') as $did => $dn) {
		$neodb->query("MATCH (p:Project {id: $P, userpkey: $owner}) CREATE (d:Dataset {id: $did, userpkey: $owner, name: '$dn', created_by: $owner}) CREATE (p)-[:HAS_DATASET]->(d)");
	}
	$js1 = str_replace("'", "\\'", json_encode(array(array('id' => $SMP_RICH, 'sample_id_name' => 'RICH-1', 'material_type' => 'intact_rock'))));
	$js2 = str_replace("'", "\\'", json_encode(array(array('id' => $SMP_LEG, 'sample_id_name' => 'LEG-1'), array('id' => $SMP_LEG2, 'sample_id_name' => 'LEG-2'))));
	$neodb->query("MATCH (d:Dataset {id: $DS1, userpkey: $owner}) CREATE (s:Spot {id: $S1, userpkey: $owner, name: 'Rich Sample Spot', wkt: 'POINT (-118.25 34.05)', modified_timestamp: 1725000001000, isSample: true, json_samples: '$js1'}) CREATE (d)-[:HAS_SPOT]->(s)");
	$neodb->query("MATCH (d:Dataset {id: $DS1, userpkey: $owner}) CREATE (s:Spot {id: $S2, userpkey: $owner, name: 'Legacy Samples Spot', wkt: 'POINT (-118.26 34.06)', modified_timestamp: 1725000002000, json_samples: '$js2'}) CREATE (d)-[:HAS_SPOT]->(s)");
	$neodb->query("MATCH (d:Dataset {id: $DS2, userpkey: $owner}) CREATE (s:Spot {id: $S3, userpkey: $owner, name: 'Day Two Spot', wkt: 'POINT (-118.27 34.07)', modified_timestamp: 1725000003000}) CREATE (d)-[:HAS_SPOT]->(s)");
	$neodb->query("MATCH (s:Spot {id: $S1, userpkey: $owner}) CREATE (i:Image {id: $IMG1, userpkey: $owner, created_by: $owner, image_type: 'photo', title: 'Outcrop', filename: '$IMG1', width: 64, height: 48}) CREATE (s)-[:HAS_IMAGE]->(i)");
	$neodb->query("MATCH (s:Spot {id: $S3, userpkey: $owner}) CREATE (i:Image {id: $IMG2, userpkey: $owner, created_by: $owner, image_type: 'photo', title: 'Second', filename: '$IMG2', width: 64, height: 48}) CREATE (s)-[:HAS_IMAGE]->(i)");
	$neodb->query("MATCH (s:Spot {id: $S1, userpkey: $owner}) CREATE (o:Orientation {id: $ORI1, userpkey: $owner, type: 'planar_orientation', strike: 45, dip: 30}) CREATE (s)-[:HAS_ORIENTATION]->(o) CREATE (a:Orientation {id: $ORI1A, userpkey: $owner, type: 'linear_orientation', trend: 120, plunge: 10}) CREATE (o)-[:HAS_ASSOCIATED_ORIENTATION]->(a)");
	// legacy Tag node hung off the project + an IS_TAGGED edge with owner-bearing properties
	$neodb->query("MATCH (p:Project {id: $P, userpkey: $owner}) CREATE (t:Tag {id: $TAG1, userpkey: $owner, name: 'Legacy Tag'}) CREATE (p)-[:HAS_TAG]->(t)");
	$neodb->query("MATCH (s:Spot {id: $S2, userpkey: $owner}), (t:Tag {id: $TAG1}) CREATE (s)-[:IS_TAGGED {projectid: $P, datasetid: $DS1, userpkey: $owner}]->(t)");

	// PG mirror: owner rows (project -> dataset -> spot -> image/sample/rock_type) + a RECIPIENT copy row
	$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Transfer Fixture', $2, FALSE)", array($owner, (string)$P));
	$ppk = pgn("SELECT project_pkey FROM project WHERE strabo_project_id = $1 AND user_pkey = $2", array((string)$P, $owner));
	foreach (array($DS1 => array($S1, $S2), $DS2 => array($S3)) as $did => $spots) {
		$db->prepare_query("INSERT INTO dataset (project_pkey, user_pkey, dataset_name, strabo_dataset_id) VALUES ($1, $2, 'ds', $3)", array($ppk, $owner, (string)$did));
		$dpk = pgn("SELECT dataset_pkey FROM dataset WHERE strabo_dataset_id = $1 AND user_pkey = $2", array((string)$did, $owner));
		foreach ($spots as $sid) {
			$db->prepare_query("INSERT INTO spot (dataset_pkey, project_pkey, user_pkey, strabo_spot_id, date_created) VALUES ($1, $2, $3, $4, current_date)", array($dpk, $ppk, $owner, (string)$sid));
			$spk = pgn("SELECT spot_pkey FROM spot WHERE strabo_spot_id = $1 AND user_pkey = $2", array((string)$sid, $owner));
			$db->prepare_query("INSERT INTO image (spot_pkey, dataset_pkey, project_pkey, user_pkey, strabo_image_id) VALUES ($1, $2, $3, $4, $5)", array($spk, $dpk, $ppk, $owner, "img-$sid"));
			$db->prepare_query("INSERT INTO sample (spot_pkey, dataset_pkey, project_pkey, user_pkey, strabo_sample_id) VALUES ($1, $2, $3, $4, $5)", array($spk, $dpk, $ppk, $owner, "smp-$sid"));
			$db->prepare_query("INSERT INTO rock_type (spot_pkey, dataset_pkey, project_pkey, user_pkey, strabo_rock_type) VALUES ($1, $2, $3, $4, 'granite')", array($spk, $dpk, $ppk, $owner));
		}
	}
	$db->prepare_query("INSERT INTO project (user_pkey, project_name, strabo_project_id, ispublic) VALUES ($1, 'Transfer Fixture (copy)', $2, FALSE)", array($recip, (string)$P));

	// collaborators: COLLAB stays (edit), RECIP is a readonly collaborator about to become owner
	$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid, created_date, accepted_date) VALUES ($1, $2, $3, 'edit', TRUE, FALSE, 'tf-collab', now(), now())", array((string)$P, $owner, $COLLAB));
	$db->prepare_query("INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, disabled, uuid, created_date, accepted_date) VALUES ($1, $2, $3, 'readonly', TRUE, FALSE, 'tf-recip', now(), now())", array((string)$P, $owner, $recip));

	// versions / verlog / vprojects / merge pref / DOI
	$db->prepare_query("INSERT INTO versions (projectid, datecreated, uuid, userpkey, projectname, spotcount, datasetcount) VALUES ($1, now(), '9459a000-0000-4000-8000-000000000001', $2, 'Transfer Fixture', 3, 2)", array((string)$P, $owner));
	$db->prepare_query("INSERT INTO verlog (userpkey, dateactivated, projectid, uuid) VALUES ($1, now(), $2, '9459a000-0000-4000-8000-000000000001')", array($owner, (string)$P));
	$db->prepare_query("INSERT INTO vprojects (projectid, userpkey) VALUES ($1, $2)", array((string)$P, $owner));
	$db->prepare_query("INSERT INTO project_merge_prefs (strabo_project_id, project_owner_user_pkey, union_tags, note) VALUES ($1, $2, TRUE, 'fixture')", array((string)$P, $owner));
	$db->prepare_query("INSERT INTO dois (doi, uuid, strabo_project_id, user_pkey, date_created, project_name, doi_type) VALUES ('10.9459/fixture', '9459d000-0000-4000-8000-000000000001', $1, $2, now(), 'Transfer Fixture', 'field')", array((string)$P, $owner));

	// strabosamples spine through the real service (link rows, seeded collaborators, changelog)
	$svc = new StraboSamplesService($db, $neodb);
	$svc->setUserpkey($owner);
	$seed = array($recip => 'readonly', $COLLAB => 'edit');
	$mk = function ($sid, $spot, $name) use ($svc, $owner, $P, $DS1, $seed) {
		return $svc->upsertSample('field', $sid, $owner,
			array('name' => $name, 'latitude' => 34.05, 'longitude' => -118.25, 'display_sample_type' => 'intact_rock'),
			array('sample_id_name' => $name),
			array('reference_id' => (string)$spot, 'reference_userpkey' => $owner, 'reference_metadata' => array('project_id' => (string)$P, 'dataset_id' => (string)$DS1, 'rich' => $sid === (string)$spot)),
			array(), array('autoSeedCollaborators' => $seed));
	};
	$mk($SMP_RICH, $S1, 'RICH-1'); $mk($SMP_LEG, $S2, 'LEG-1'); $mk($SMP_LEG2, $S2, 'LEG-2');
	$db->prepare_query("UPDATE strabosamples.samples SET parent_sample_id = $1, parent_userpkey = $2 WHERE id = $3 AND userpkey = $2", array($SMP_RICH, $owner, $SMP_LEG));
	$db->prepare_query("INSERT INTO strabosamples.sample_composition (sample_id, sample_userpkey, mineral, fraction, unit) VALUES ($1, $2, 'quartz', '30', 'percent')", array($SMP_RICH, $owner));
	$db->prepare_query("INSERT INTO strabosamples.sample_parameters (sample_id, sample_userpkey, control, value) VALUES ($1, $2, 'temperature', '650')", array($SMP_LEG, $owner));

	// search index slice
	StraboSearchSync::syncFieldDataset($db, $neodb, $DS1, $owner);
	StraboSearchSync::syncFieldDataset($db, $neodb, $DS2, $owner);
	foreach (array($SMP_RICH, $SMP_LEG, $SMP_LEG2) as $sid) StraboSearchSync::touchSample($db, $sid, $owner);
}

/** Per-store snapshot for assertions beyond verify(). */
function snap($upk) {
	global $db, $neodb, $P, $TAG1;
	$o = array();
	$o['neo_nodes'] = (int)$neodb->get_var("MATCH (p:Project {id: $P}) OPTIONAL MATCH (p)-[:HAS_DATASET]->(d) OPTIONAL MATCH (d)-[:HAS_SPOT]->(s) OPTIONAL MATCH (s)-[*1..2]->(x) WHERE NOT x:Spot AND NOT x:Dataset AND NOT x:Project AND NOT x:User WITH collect(DISTINCT p) + collect(DISTINCT d) + collect(DISTINCT s) + collect(DISTINCT x) AS ns UNWIND ns AS n WITH n WHERE n.userpkey = $upk RETURN count(n)");
	$o['tag_node'] = (int)$neodb->get_var("MATCH (t:Tag {id: $TAG1}) WHERE t.userpkey = $upk RETURN count(t)");
	$o['edge_owner'] = (int)$neodb->get_var("MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project {id: $P}) RETURN count(p)");
	$o['is_tagged'] = (int)$neodb->get_var("MATCH ()-[r:IS_TAGGED {projectid: $P, userpkey: $upk}]->() RETURN count(r)");
	$o['created_by_owner'] = (int)$neodb->get_var("MATCH (d:Dataset) WHERE d.id IN [945912001, 945912002] AND d.created_by = $upk RETURN count(d)");
	$o['pg_project'] = pgn("SELECT count(*) FROM project WHERE strabo_project_id = $1 AND user_pkey = $2", array((string)$P, $upk));
	$o['pg_spot'] = pgn("SELECT count(*) FROM spot s JOIN project p ON p.project_pkey = s.project_pkey WHERE p.strabo_project_id = $1 AND s.user_pkey = $2", array((string)$P, $upk));
	$o['pg_children'] = pgn("SELECT (SELECT count(*) FROM image WHERE user_pkey = $1) + (SELECT count(*) FROM sample WHERE user_pkey = $1) + (SELECT count(*) FROM rock_type WHERE user_pkey = $1) + (SELECT count(*) FROM dataset WHERE user_pkey = $1)", array($upk));
	$o['collab_owner_rows'] = pgn("SELECT count(*) FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array((string)$P, $upk));
	$o['collab_as_member'] = pgv("SELECT collaboration_level FROM collaborators WHERE strabo_project_id = $1 AND collaborator_user_pkey = $2 AND accepted AND NOT disabled LIMIT 1", array((string)$P, $upk));
	$o['versions'] = pgn("SELECT count(*) FROM versions WHERE projectid = $1 AND userpkey = $2", array((string)$P, $upk));
	$o['verlog'] = pgn("SELECT count(*) FROM verlog WHERE projectid = $1 AND userpkey = $2", array((string)$P, $upk));
	$o['vprojects'] = pgn("SELECT count(*) FROM vprojects WHERE projectid = $1 AND userpkey = $2", array((string)$P, $upk));
	$o['merge_prefs'] = pgn("SELECT count(*) FROM project_merge_prefs WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2", array((string)$P, $upk));
	$o['dois'] = pgn("SELECT count(*) FROM dois WHERE strabo_project_id = $1 AND user_pkey = $2", array((string)$P, $upk));
	$o['samples'] = pgn("SELECT count(*) FROM strabosamples.samples WHERE userpkey = $1", array($upk));
	$o['sample_children'] = pgn("SELECT (SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey = $1) + (SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE sample_userpkey = $1) + (SELECT count(*) FROM strabosamples.sample_collaborators WHERE sample_userpkey = $1) + (SELECT count(*) FROM strabosamples.sample_composition WHERE sample_userpkey = $1) + (SELECT count(*) FROM strabosamples.sample_parameters WHERE sample_userpkey = $1)", array($upk));
	$o['sample_links_ref'] = pgn("SELECT count(*) FROM strabosamples.sample_subsystem_links WHERE subsystem = 'field' AND reference_userpkey = $1", array($upk));
	$o['search_items'] = pgn("SELECT count(*) FROM strabosearch.item_hit WHERE project_subsystem = 'field' AND project_id = $1 AND project_userpkey = $2", array((string)$P, $upk));
	$o['search_images'] = pgn("SELECT count(*) FROM strabosearch.image_hit WHERE image_subsystem = 'field' AND project_id = $1 AND project_userpkey = $2", array((string)$P, $upk));
	$o['search_samples'] = pgn("SELECT count(*) FROM strabosearch.item_hit WHERE item_type = 'sample' AND item_userpkey = $1", array($upk));
	return $o;
}

function fromZero($s) {
	$bad = array();
	foreach ($s as $k => $v) {
		if ($k === 'dois' || $k === 'collab_as_member' || $k === 'created_by_owner') continue;
		if ((int)$v !== 0) $bad[$k] = $v;
	}
	return $bad;
}

class BoomTransfer extends ProjectTransfer {
	public $boom = true;
	protected function stepMirror($pid, $to, array &$summary) {
		if ($this->boom) { $this->boom = false; throw new RuntimeException('injected failure before the mirror refresh'); }
		parent::stepMirror($pid, $to, $summary);
	}
}

echo "Project transfer smoke suite\n";
cleanup();
seed($OWNER, $RECIP);
$svc = new ProjectTransfer($db, $neodb);
$auth = new CollaborationAuth($db, $neodb);
$emailOf = function ($u) use ($db) { return $db->get_var_prepared("SELECT email FROM users WHERE pkey = $1", array($u)); };

// ---------------------------------------------------------------- baseline
section('Fixture baseline');
$b = snap($OWNER);
check('owner holds the Neo4j subtree (project + 2 datasets + 3 spots + 2 images + 2 orientations + tag)', $b['neo_nodes'] === 11, $b['neo_nodes']);
check('owner holds the HAS_PROJECT edge', $b['edge_owner'] === 1);
check('owner has the IS_TAGGED edge property', $b['is_tagged'] === 1);
check('owner PG mirror: 1 project row, 3 spots', $b['pg_project'] === 1 && $b['pg_spot'] === 3, json_encode($b));
check('recipient holds a mirror copy row', snap($RECIP)['pg_project'] === 1);
check('owner has 2 collaborator rows (COLLAB edit, RECIP readonly)', $b['collab_owner_rows'] === 2);
check('owner has 3 spine samples with children', $b['samples'] === 3 && $b['sample_children'] > 6, json_encode(array($b['samples'], $b['sample_children'])));
check('search slice indexed for owner (items + samples)', $b['search_items'] >= 3 && $b['search_samples'] === 3, json_encode(array($b['search_items'], $b['search_samples'])));
check('search images indexed for owner', $b['search_images'] === 2, $b['search_images']);

// ---------------------------------------------------------------- A. request
section('A. request');
$r = $svc->request($P, $OWNER, 'nobody-94590@test.strabospot.org', true);
check('unknown address: ok with the neutral message, no mail', $r['ok'] && $r['mail'] === false && strpos($r['message'], 'If a StraboSpot account exists') === 0, json_encode($r));
check('unknown address: refused audit row carries the reason', $r['row'] && $r['row']->status === 'refused' && strpos($svc->summaryOf($r['row'])['reason'], 'No active StraboSpot account') === 0, $r['row'] ? $r['row']->summary : 'no row');
$r = $svc->request($P, $OWNER, $emailOf($OWNER), true);
check('self: neutral message, refused row, reason = same account', $r['ok'] && !$r['mail'] && $r['row']->status === 'refused' && stripos($svc->summaryOf($r['row'])['reason'], 'already owns') !== false, json_encode($r['row']));
$r = $svc->request($P, $OWNER, 'not an email', true);
check('malformed email: not ok, no row', !$r['ok'] && $r['row'] === null);
$r = $svc->request($P, $COLLAB, $emailOf($RECIP), true);
check('a collaborator cannot initiate (owner only)', !$r['ok'] && $r['reason'] === 'not owner');
$r = $svc->request($P, $OWNER, strtoupper($emailOf($RECIP)), true);
check('valid recipient (case-insensitive email): pending row + mail flag', $r['ok'] && $r['mail'] === true && $r['row']->status === 'pending' && (int)$r['row']->to_user_pkey === $RECIP, json_encode($r['row']));
$pending = $r['row'];
check('pending row snapshots the project name and counts', $pending->project_name === 'Transfer Fixture' && $svc->summaryOf($pending)['counts_at_request']['spots'] === 3, $pending->summary);
check('pending row expires in 7 days', abs(strtotime($pending->expires_date) - time() - 7 * 86400) < 120);
$r = $svc->request($P, $OWNER, $emailOf($COLLAB), true);
check('second request while one is pending: not ok', !$r['ok'] && $r['reason'] === 'pending exists');
check('listOutgoing(owner) shows it with the recipient email', count($svc->listOutgoing($OWNER)) === 1 && $svc->listOutgoing($OWNER)[0]->to_email === $emailOf($RECIP));
check('listIncoming(recipient) shows it with the owner name', count($svc->listIncoming($RECIP)) === 1 && $svc->listIncoming($RECIP)[0]->from_firstname === 'Olive');
check('listIncoming(collaborator) is empty', count($svc->listIncoming($COLLAB)) === 0);

// ---------------------------------------------------------------- B. eligibility negatives
section('B. eligibility');
$e = $svc->checkEligibility($P, $OWNER, $DELETED); check('deleted account refused', !$e['ok'] && stripos($e['reason'], 'not an active') !== false, $e['reason']);
$e = $svc->checkEligibility($P, $OWNER, $INACTIVE); check('inactive account refused', !$e['ok'] && stripos($e['reason'], 'not an active') !== false, $e['reason']);
$e = $svc->checkEligibility($P, $OWNER, $NONODE); check('account without a :User node refused', !$e['ok'] && stripos($e['reason'], 'never signed in') !== false, $e['reason']);
$e = $svc->checkEligibility($P, $OWNER, $OWNER); check('self refused', !$e['ok']);
$e = $svc->checkEligibility($P, $COLLAB, $RECIP); check('non-owner "from" refused', !$e['ok'] && stripos($e['reason'], 'does not own') !== false, $e['reason']);
check('resolveRecipient hides deleted / inactive / node-less accounts', $svc->resolveRecipient($emailOf($DELETED)) === null && $svc->resolveRecipient($emailOf($INACTIVE)) === null && $svc->resolveRecipient($emailOf($NONODE)) === null);
check('resolveRecipient finds the recipient', (int)$svc->resolveRecipient($emailOf($RECIP))->pkey === $RECIP);
// same-id project on the receiving side
$neodb->query("MATCH (u:User {userpkey: $OTHER}) CREATE (p:Project {id: $P, userpkey: $OTHER, desc_project_name: 'Otto same id'}) CREATE (u)-[:HAS_PROJECT]->(p)");
$e = $svc->checkEligibility($P, $OWNER, $OTHER); check('recipient with a same-id live project refused', !$e['ok'] && stripos($e['reason'], 'already has a project') !== false, $e['reason']);
$neodb->query("MATCH (u:User {userpkey: $OTHER})-[:HAS_PROJECT]->(p:Project {id: $P}) DETACH DELETE p");
$db->prepare_query("INSERT INTO versions (projectid, datecreated, uuid, userpkey, projectname, spotcount, datasetcount) VALUES ($1, now(), '9459a000-0000-4000-8000-000000000002', $2, 'Otto version', 0, 0)", array((string)$P, $OTHER));
$e = $svc->checkEligibility($P, $OWNER, $OTHER); check('recipient with a same-id version refused', !$e['ok'] && stripos($e['reason'], 'saved version') !== false, $e['reason']);
$db->prepare_query("DELETE FROM versions WHERE userpkey = $1", array($OTHER));
$e = $svc->checkEligibility($P, $OWNER, $OTHER); check('clean third party is eligible (pending exists is skipped only at acceptance)', !$e['ok'] && stripos($e['reason'], 'already pending') !== false, $e['reason']);
$e = $svc->checkEligibility($P, $OWNER, $OTHER, array('skip_pending' => true)); check('...and eligible with skip_pending', $e['ok'], $e['reason']);
// sample id clash
$db->prepare_query("INSERT INTO strabosamples.samples (id, userpkey, name, created_by, modified_by) VALUES ($1, $2, 'clash', $2, $2)", array($SMP_LEG, $RECIP));
$e = $svc->checkEligibility($P, $OWNER, $RECIP, array('skip_pending' => true)); check('recipient holding a same-id sample refused', !$e['ok'] && stripos($e['reason'], 'sample') !== false, $e['reason']);
$db->prepare_query("DELETE FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($SMP_LEG, $RECIP));
$e = $svc->checkEligibility($P, $OWNER, $RECIP, array('skip_pending' => true)); check('recipient eligible again', $e['ok'], $e['reason']);

// ---------------------------------------------------------------- C. accept
section('C. accept');
$r = $svc->accept($pending->uuid, $COLLAB);
check('accept by the wrong account refused, row still pending', !$r['ok'] && $svc->getByUuid($pending->uuid)->status === 'pending', $r['reason']);
$r = $svc->accept('no-such-uuid', $RECIP);
check('accept of an unknown uuid refused', !$r['ok']);
$t0 = microtime(true);
$r = $svc->accept($pending->uuid, $RECIP);
$dt = round(microtime(true) - $t0, 2);
check("accept by the recipient succeeds ({$dt}s)", $r['ok'] === true, $r['error']);
$done = $r['row'];
check('row accepted, step 5, applied, completed_date set, decided_by = recipient', $done->status === 'accepted' && (int)$done->step === 5 && $done->applied === 't' && $done->completed_date !== null && (int)$done->decided_by_pkey === $RECIP, json_encode($done));
$v = $svc->verify($done);
check('verify(): clean', $v['clean'] === true, json_encode($v));
check('verify(): edge owner is the recipient only', $v['edge_owners'] === array($RECIP), json_encode($v['edge_owners']));
$o = snap($OWNER); $n = snap($RECIP);
check('old owner holds nothing (except the DOI)', fromZero($o) === array(), json_encode(fromZero($o)));
check('DOI stayed with the minter', $o['dois'] === 1 && $n['dois'] === 0);
check('recipient holds the whole Neo4j subtree', $n['neo_nodes'] === 11 && $n['tag_node'] === 1 && $n['edge_owner'] === 1, json_encode($n));
check('IS_TAGGED edge property rewritten', $n['is_tagged'] === 1 && $o['is_tagged'] === 0);
check('Dataset.created_by still names the creator (old owner)', $o['created_by_owner'] === 2);
check('PG mirror: exactly one project row for the recipient (copy removed), 3 spots, children moved', $n['pg_project'] === 1 && $n['pg_spot'] === 3 && $n['pg_children'] === 11, json_encode($n));
check('mirror keyword vector carries the new owner name', pgn("SELECT count(*) FROM project WHERE strabo_project_id = $1 AND user_pkey = $2 AND keywords @@ plainto_tsquery('Rex')", array((string)$P, $RECIP)) === 1);
check('collaborators: COLLAB row now owned by recipient, recipient\'s own row gone', $n['collab_owner_rows'] === 2 && pgv("SELECT collaboration_level FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3", array((string)$P, $RECIP, $COLLAB)) === 'edit' && pgn("SELECT count(*) FROM collaborators WHERE collaborator_user_pkey = $1", array($RECIP)) === 0, json_encode($n));
check('old owner stays on as accepted admin collaborator', pgv("SELECT collaboration_level FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3 AND accepted AND NOT disabled", array((string)$P, $RECIP, $OWNER)) === 'admin');
check('versions / verlog / vprojects / merge pref moved', $n['versions'] === 1 && $n['verlog'] === 1 && $n['vprojects'] === 1 && $n['merge_prefs'] === 1, json_encode($n));
check('spine: 3 samples now under the recipient with all children', $n['samples'] === 3 && $n['sample_children'] === $b['sample_children'] + 3 /* transfer changelog rows */ + 3 /* old owner as collaborator */ && $o['samples'] === 0, json_encode(array($n['samples'], $n['sample_children'], $b['sample_children'])));
check('spine: field links point at the recipient as reference owner', $n['sample_links_ref'] === 3 && $o['sample_links_ref'] === 0);
check('spine: parent pointer followed the parent', pgv("SELECT parent_userpkey FROM strabosamples.samples WHERE id = $1 AND userpkey = $2", array($SMP_LEG, $RECIP)) == $RECIP);
check('spine: composition + parameters rows moved', pgn("SELECT count(*) FROM strabosamples.sample_composition WHERE sample_userpkey = $1", array($RECIP)) === 1 && pgn("SELECT count(*) FROM strabosamples.sample_parameters WHERE sample_userpkey = $1", array($RECIP)) === 1);
check('spine: one ownership_transfer changelog row per sample, by the recipient, naming the transfer', pgn("SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey = $1 AND change_type = 'ownership_transfer' AND changed_by = $1 AND changes->>'transfer_uuid' = $2", array($RECIP, $done->uuid)) === 3);
check('spine: recipient\'s own collaborator rows ended, COLLAB kept, old owner added (edit)', pgn("SELECT count(*) FROM strabosamples.sample_collaborators WHERE sample_userpkey = $1 AND collaborator_pkey = $1 AND removed_at IS NULL", array($RECIP)) === 0 && pgn("SELECT count(*) FROM strabosamples.sample_collaborators WHERE sample_userpkey = $1 AND collaborator_pkey = $2 AND removed_at IS NULL", array($RECIP, $COLLAB)) === 3 && pgn("SELECT count(*) FROM strabosamples.sample_collaborators WHERE sample_userpkey = $1 AND collaborator_pkey = $2 AND removed_at IS NULL AND permission_level = 'edit'", array($RECIP, $OWNER)) === 3);
check('search: slice re-extracted under the recipient (items, images, samples), none left for the old owner', $n['search_items'] >= 3 && $n['search_images'] === 2 && $n['search_samples'] === 3 && $o['search_items'] === 0 && $o['search_images'] === 0 && $o['search_samples'] === 0, json_encode(array($n, $o)));
$sum = $svc->summaryOf($done);
check('audit summary: before/after counts, neo + mirror + samples + search sections', isset($sum['before'], $sum['after'], $sum['neo'], $sum['mirror'], $sum['search']) && $sum['neo']['spots'] === 3 && $sum['samples'] === 3 && $sum['after']['clean'] === true, json_encode(array_keys($sum)));
check('old pending request list empty on both sides', count($svc->listOutgoing($OWNER)) === 0 && count($svc->listIncoming($RECIP)) === 0);

// ---------------------------------------------------------------- D. guard + idempotency
section('D. upload guard');
$g = $auth->canUploadProjectAsOwner((string)$P, $OWNER);
check('old owner uploading the project id as owner is refused with the transfer date', !$g['allowed'] && strpos($g['reason'], 'was transferred to another StraboSpot account on ' . date('F j, Y')) !== false, $g['reason']);
$g = $auth->canUploadProjectAsOwner((string)$P, $RECIP);
check('recipient may upload it (their old collaborator row is gone)', $g['allowed'] === true, $g['reason']);
$g = $auth->canUploadProjectAsOwner((string)$P, $OTHER);
check('unrelated account unaffected', $g['allowed'] === true);
check('transferredAway() names the row', (int)ProjectTransfer::transferredAway($db, (string)$P, $OWNER)->pkey === (int)$done->pkey && ProjectTransfer::transferredAway($db, (string)$P, $RECIP) === null);
$r = $svc->execute($done, $RECIP);
check('execute() on a completed row is a no-op', !$r['ok'] && strpos($r['error'], 'nothing to execute') !== false, $r['error']);
$r = $svc->request($P, $RECIP, $emailOf($OWNER), false);
check('new owner may offer it straight back (voluntary round trip allowed)', $r['ok'] && $r['mail'] === true, $r['row'] ? $r['row']->summary : json_encode($r));
$svc->cancel($r['row']->uuid, $RECIP);

// ---------------------------------------------------------------- E. reverse
section('E. reverse (admin)');
$r = $svc->reverse($done->pkey + 100000, $ADMIN);
check('reverse of an unknown row refused', !$r['ok']);
$r = $svc->reverse($done->pkey, $ADMIN);
check('reverse succeeds', $r['ok'] === true, json_encode(array($r['reason'], $r['error'])));
$rev = $r['row'];
check('reversal row: kind reversal, parties swapped, accepted, requested by admin', $rev->kind === 'reversal' && (int)$rev->from_user_pkey === $RECIP && (int)$rev->to_user_pkey === $OWNER && $rev->status === 'accepted' && (int)$rev->requested_by_pkey === $ADMIN, json_encode($rev));
$orig = $svc->getByPkey($done->pkey);
check('original row stamped reversed + tombstone cleared', $orig->reversed_date !== null && (int)$orig->reversed_by_pkey === $ADMIN && $orig->tombstone_cleared_date !== null);
$v = $svc->verify($rev);
check('verify(reversal): clean', $v['clean'] === true, json_encode($v));
$o = snap($OWNER); $n = snap($RECIP);
check('owner holds everything again (11 nodes, edge, mirror, versions, samples, search)', $o['neo_nodes'] === 11 && $o['edge_owner'] === 1 && $o['pg_project'] === 1 && $o['pg_spot'] === 3 && $o['versions'] === 1 && $o['samples'] === 3 && $o['search_items'] >= 3 && $o['search_samples'] === 3, json_encode($o));
check('recipient holds nothing', fromZero($n) === array(), json_encode(fromZero($n)));
check('recipient\'s original readonly collaborator row restored', pgv("SELECT collaboration_level FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3 AND accepted AND NOT disabled", array((string)$P, $OWNER, $RECIP)) === 'readonly');
check('old owner\'s admin row removed, COLLAB row back under owner', pgn("SELECT count(*) FROM collaborators WHERE collaborator_user_pkey = $1", array($OWNER)) === 0 && pgv("SELECT collaboration_level FROM collaborators WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2 AND collaborator_user_pkey = $3", array((string)$P, $OWNER, $COLLAB)) === 'edit');
check('DOI untouched by the reversal', $o['dois'] === 1);
check('spine: 6 ownership_transfer changelog rows in total (3 out, 3 back) now under the owner', pgn("SELECT count(*) FROM strabosamples.sample_changelog WHERE sample_userpkey = $1 AND change_type = 'ownership_transfer'", array($OWNER)) === 6);
$g = $auth->canUploadProjectAsOwner((string)$P, $RECIP);
check('guard now blocks the recipient', !$g['allowed'] && strpos($g['reason'], 'was transferred') !== false, $g['reason']);
$g = $auth->canUploadProjectAsOwner((string)$P, $OWNER);
check('guard lets the owner upload again', $g['allowed'] === true, $g['reason']);
$r = $svc->reverse($done->pkey, $ADMIN);
check('reversing the same row twice refused', !$r['ok'], $r['reason']);

// ---------------------------------------------------------------- F. failure injection + resume
section('F. failure injection');
$boom = new BoomTransfer($db, $neodb);
$r = $boom->request($P, $OWNER, $emailOf($RECIP), false);
check('fresh request after the round trip is allowed (recipient tombstone cleared by the reversal)', $r['ok'] && $r['mail'] === true, json_encode($r));
$r = $boom->accept($r['row']->uuid, $RECIP);
check('injected failure at step 4 reported', !$r['ok'] && strpos($r['error'], 'injected failure') !== false, $r['error']);
$failed = $r['row'];
check('row failed, step 3 (PG committed), applied, error + failed_step recorded', $failed->status === 'failed' && (int)$failed->step === 3 && $failed->applied === 't' && $boom->summaryOf($failed)['failed_step'] === 4 && strpos($boom->summaryOf($failed)['error'], 'injected') !== false, json_encode($failed));
$g = $auth->canUploadProjectAsOwner((string)$P, $OWNER);
check('tombstone already protects the half-moved project', !$g['allowed']);
$v = $boom->verify($failed);
check('verify(failed): not clean yet, but Neo4j + PG stores already show the recipient', $v['clean'] === false && $v['stores']['neo4j_nodes']['from'] === 0 && $v['stores']['pg_project']['to'] === 1, json_encode($v['stores']));
$r = $boom->execute($boom->getByPkey($failed->pkey), $RECIP);
check('retry resumes from step 4 and completes', $r['ok'] === true && $r['row']->status === 'accepted' && (int)$r['row']->step === 5, $r['error']);
check('no error left on the row', !isset($boom->summaryOf($r['row'])['error']));
$v = $boom->verify($r['row']);
check('verify(after retry): clean', $v['clean'] === true, json_encode($v));
check('keep_as_collaborator = false: old owner is NOT a collaborator', pgn("SELECT count(*) FROM collaborators WHERE strabo_project_id = $1 AND collaborator_user_pkey = $2", array((string)$P, $OWNER)) === 0);
check('keep = false: old owner not added on the samples either', pgn("SELECT count(*) FROM strabosamples.sample_collaborators WHERE sample_userpkey = $1 AND collaborator_pkey = $2 AND removed_at IS NULL", array($RECIP, $OWNER)) === 0);

// ---------------------------------------------------------------- G. cancel / decline / expire
section('G. cancel / decline / expire');
$r = $svc->request($P, $RECIP, $emailOf($OWNER), true);
check('recipient (now owner) can request a transfer back to the original owner', $r['ok'] && $r['mail'], $r['row'] ? $r['row']->summary : json_encode($r));
$u1 = $r['row']->uuid;
$c = $svc->cancel($u1, $OWNER); check('cancel by a non-owner refused', !$c['ok']);
$c = $svc->cancel($u1, $RECIP); check('cancel by the owner ok, row cancelled', $c['ok'] && $c['row']->status === 'cancelled' && (int)$c['row']->decided_by_pkey === $RECIP);
$c = $svc->cancel($u1, $RECIP); check('cancel twice refused', !$c['ok']);
$r = $svc->request($P, $RECIP, $emailOf($OWNER), true); $u2 = $r['row']->uuid;
$d = $svc->decline($u2, $COLLAB); check('decline by a third party refused', !$d['ok']);
$d = $svc->decline($u2, $OWNER); check('decline by the recipient ok', $d['ok'] && $d['row']->status === 'declined');
$a = $svc->accept($u2, $OWNER); check('accept after decline refused', !$a['ok']);
$r = $svc->request($P, $RECIP, $emailOf($OWNER), true); $u3 = $r['row']->uuid;
$db->prepare_query("UPDATE project_transfers SET expires_date = now() - interval '1 hour' WHERE uuid = $1", array($u3));
check('expired request no longer listed (lazy expiry)', count($svc->listIncoming($OWNER)) === 0 && $svc->getByUuid($u3)->status === 'expired');
$a = $svc->accept($u3, $OWNER); check('accept of an expired request refused', !$a['ok'] && stripos($a['reason'], 'no longer pending') !== false, $a['reason']);
check('project still with the recipient after all of that', snap($RECIP)['edge_owner'] === 1 && snap($OWNER)['edge_owner'] === 0);

// ---------------------------------------------------------------- done
cleanup();
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
