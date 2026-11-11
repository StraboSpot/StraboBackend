<?php
/**
 * File: includes/fieldcamp/FieldCampCopy.php
 * Description: Field Camp Wizard: copy a student's StraboField projects into
 *              the camp owner's account. During the camp this is how an
 *              instructor sees each student's work (on the website and in the
 *              app, as "Project (username)"); afterwards it keeps the work
 *              when the camp accounts are removed.
 *
 *              A copy can be refreshed: copying an account again brings each
 *              copy up to date with what the student has uploaded since. The
 *              refreshed copy keeps its project, dataset and tag ids
 *              (fieldcamp_copy.id_map), so it stays the same project in the
 *              instructor's account and app. A project that has not changed
 *              since its copy is left alone.
 *
 *              Every student of a shared-package camp holds the SAME project,
 *              dataset and tag ids (the package is one zip for all devices),
 *              and an account can hold only one project per id. So each copy
 *              gets NEW project, dataset and tag ids. Spot, image and sample
 *              ids are made by the app on each device, are already unique per
 *              student, and stay as they are (so tag -> spot links survive).
 *
 *              Reading and loading reuse the site's version code path
 *              (StraboSpot::createVersion / switchVersion): the project is
 *              read as its owner, then loaded with insertProject,
 *              insertDataset and insertSpot as the new owner, so the PG
 *              mirror, sample sync and search index are filled the usual way.
 *              The student's own data is only read, never changed.
 *
 *              Copies are snapshots. An account that is still active can
 *              upload while it is copied; the next refresh picks that up.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class FieldCampCopy
{
	private $db;
	private $neodb;
	private $webroot;
	private $lastId = 0;

	public function __construct($db, $neodb)
	{
		$this->db = $db;
		$this->neodb = $neodb;
		$this->webroot = dirname(__DIR__, 2);
		if (!class_exists('StraboSpot', false)) require_once $this->webroot . '/db/strabospotclass.php';
		if (!class_exists('UUID', false)) require_once $this->webroot . '/includes/UUID.php';
		if (!class_exists('geoPHP', false)) require_once $this->webroot . '/includes/geophp/geoPHP.inc';
	}

	/** The projects an account holds on the server: [[id, name]]. */
	public function projectsOf($userpkey)
	{
		$out = array();
		$rows = $this->neodb->get_results("match (p:Project) where p.userpkey = " . (int)$userpkey
			. " return distinct p.id as id, p.desc_project_name as name order by p.id");
		if ($rows) {
			foreach ($rows as $r) {
				$id = (string)$r->get('id');
				if ($id === '') continue;
				$name = (string)$r->get('name');
				$out[] = array('id' => $id, 'name' => $name === '' ? '(unnamed project)' : $name);
			}
		}
		return $out;
	}

	/** Project counts per account for a camp's roster: [userpkey => count]. */
	public function projectCounts(array $userpkeys)
	{
		$out = array();
		$userpkeys = array_values(array_filter(array_map('intval', $userpkeys)));
		if (!$userpkeys) return $out;
		$rows = $this->neodb->get_results("match (p:Project) where p.userpkey in [" . implode(',', $userpkeys)
			. "] return p.userpkey as pk, count(distinct p.id) as n");
		if ($rows) foreach ($rows as $r) $out[(int)$r->get('pk')] = (int)$r->get('n');
		return $out;
	}

	/** Copies already made for a camp: [source userpkey => number of projects copied]. */
	public function copiedCounts($camppkey)
	{
		$out = array();
		$rows = $this->db->get_results_prepared(
			"select source_user_pkey, count(*) as n from fieldcamp_copy where fieldcamp_pkey = $1 group by source_user_pkey",
			array((int)$camppkey));
		if ($rows) foreach ($rows as $r) $out[(int)$r->source_user_pkey] = (int)$r->n;
		return $out;
	}

	/**
	 * The state of a project on the server as one short string: it changes
	 * whenever the student uploads something new (project, datasets, spots or
	 * photo files). Compared with fieldcamp_copy.source_stamp to tell whether a
	 * copy is up to date.
	 */
	public function sourceStamp($userpkey, $projectId)
	{
		if (!preg_match('/^\d+$/', (string)$projectId)) return '';
		$rows = $this->neodb->get_results("
			match (p:Project) where p.userpkey = " . (int)$userpkey . " and p.id = $projectId
			optional match (p)-[:HAS_DATASET]->(d:Dataset)
			optional match (d)-[:HAS_SPOT]->(s:Spot)
			optional match (s)-[:HAS_IMAGE]->(i:Image)
			return max(p.modified_timestamp) as pm, max(p.uploaddate) as pu, count(distinct d) as nd, max(d.modified_timestamp) as dm,
				count(distinct s) as ns, max(s.modified_timestamp) as sm, sum(distinct s.id % 1000003) as sx,
				count(distinct case when i.filename is null or i.filename = '' then null else i end) as ni
			");
		if (!$rows) return '';
		$r = $rows[0];
		$parts = array();
		foreach (array('pm', 'pu', 'nd', 'dm', 'ns', 'sm', 'sx', 'ni') as $k) $parts[] = (string)$r->get($k);
		return implode('.', $parts);
	}

	/**
	 * What each account of a camp holds on the server, and whether the camp owner has it: used before an
	 * account or a camp is removed, so work is not lost by mistake.
	 *
	 * @param array $accounts rows of FieldCamp::listAccounts()
	 * @return array [userpkey => ['username', 'projects', 'spots', 'photos',
	 *                'uncopied' => projects with spots whose copy is missing or older than the student's last upload]]
	 */
	public function holdings($camp, $accounts)
	{
		require_once __DIR__ . '/FieldCampRoster.php';
		$roster = FieldCampRoster::forCamp($this->neodb, $camp, $accounts);
		$copies = $this->copyRows($camp->pkey, (int)$camp->owner_user_pkey);
		$out = array();
		foreach ($roster['rows'] as $r) {
			$h = array('username' => $r['username'], 'projects' => count($r['projects']), 'spots' => 0, 'photos' => 0, 'uncopied' => 0);
			foreach ($r['projects'] as $p) {
				$h['spots'] += $p['spots']; $h['photos'] += $p['photos'];
				if ($p['spots'] <= 0) continue;   // an empty project is not work to lose
				$row = $copies[$r['user_pkey'] . ':' . $p['id']] ?? null;
				if (!$row || (string)$row->source_stamp === '' || (string)$row->source_stamp !== $this->sourceStamp($r['user_pkey'], $p['id'])) $h['uncopied']++;
			}
			$out[(int)$r['user_pkey']] = $h;
		}
		return $out;
	}

	/**
	 * Per source project of a camp: the copy row, keyed "userpkey:project id".
	 */
	public function copyRows($camppkey, $targetUserpkey)
	{
		$out = array();
		$rows = $this->db->get_results_prepared(
			"select * from fieldcamp_copy where fieldcamp_pkey = $1 and target_user_pkey = $2",
			array((int)$camppkey, (int)$targetUserpkey));
		if ($rows) foreach ($rows as $r) $out[(int)$r->source_user_pkey . ':' . $r->source_project_id] = $r;
		return $out;
	}

	/**
	 * Copy every project of one camp account into the camp owner's account, or
	 * bring the copies made earlier up to date. A project that has not changed
	 * since its copy is skipped, so this is safe (and cheap) to run again.
	 *
	 * @param object $camp    fieldcamp row
	 * @param object $account fieldcamp_account row joined with users (FieldCamp::listAccounts)
	 * @return array ['error' => string, 'copied' => [[name, datasets, spots, images, missing_images, updated]], 'skipped' => int]
	 */
	public function copyAccount($camp, $account)
	{
		$out = array('error' => '', 'copied' => array(), 'skipped' => 0);
		$from = (int)$account->user_pkey;
		$to = (int)$camp->owner_user_pkey;

		if ((int)$account->fieldcamp_pkey !== (int)$camp->pkey) {
			$out['error'] = "That account does not belong to this camp.";
			return $out;
		}

		foreach ($this->projectsOf($from) as $p) {
			$stamp = $this->sourceStamp($from, $p['id']);
			$row = $this->db->get_row_prepared("
				select * from fieldcamp_copy
				where source_user_pkey = $1 and source_project_id = $2 and target_user_pkey = $3
				", array($from, $p['id'], $to));
			// The copy must still be there: an instructor may have deleted it from their account.
			$have = $row && $this->exists($to, 'Project', $row->target_project_id);
			if ($have && (string)$row->source_stamp === $stamp && $stamp !== '') { $out['skipped']++; continue; }

			$reuse = array();
			if ($row) {
				$reuse = json_decode((string)$row->id_map, true);
				if (!is_array($reuse)) $reuse = array((string)$p['id'] => (int)$row->target_project_id);
			}

			try {
				$result = $this->copyProject($from, $to, $p['id'], $account->username, $reuse, $have ? $row->target_project_id : null);
			} catch (\Throwable $e) {
				error_log("FieldCampCopy camp " . (int)$camp->pkey . " account $from project " . $p['id'] . ": " . $e->getMessage());
				// A failed refresh has taken the earlier copy out; the record goes with it, so the next run starts clean.
				if ($row) $this->db->prepare_query("delete from fieldcamp_copy where pkey = $1", array((int)$row->pkey));
				$out['error'] = "The project \"" . $p['name'] . "\" of " . $account->username
					. " could not be copied. Nothing of it was kept in your account; the student's project is untouched.";
				return $out;
			}

			if ($row) {
				$this->db->prepare_query("
					update fieldcamp_copy set fieldcamp_pkey = $2, target_project_id = $3, project_name = $4, dataset_count = $5,
						spot_count = $6, image_count = $7, id_map = $8, source_stamp = $9, updated_date = now()
					where pkey = $1
					", array((int)$row->pkey, (int)$camp->pkey, (string)$result['project_id'], $result['name'], $result['datasets'],
						$result['spots'], $result['images'], json_encode($result['id_map']), $stamp));
			} else {
				$this->db->prepare_query("
					insert into fieldcamp_copy (fieldcamp_pkey, source_user_pkey, source_project_id, target_user_pkey,
						target_project_id, project_name, dataset_count, spot_count, image_count, id_map, source_stamp, updated_date)
					values ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, now())
					", array((int)$camp->pkey, $from, $p['id'], $to, (string)$result['project_id'], $result['name'],
						$result['datasets'], $result['spots'], $result['images'], json_encode($result['id_map']), $stamp));
			}
			unset($result['id_map']);
			$result['updated'] = (bool)$row;
			$out['copied'][] = $result;
		}
		return $out;
	}

	/**
	 * Take a copy out of an account: the site's own project delete (it knows
	 * every kind of node a spot can own), then the version that delete saves
	 * first, which nobody needs for a copy.
	 *
	 * @return array image id => file name in dbimages of the photos the copy had (the delete leaves the files)
	 */
	private function removeCopy($dst, $to, $projectId)
	{
		$files = array();
		$images = $dst->getProjectImages($projectId);
		if (!empty($images)) {
			foreach ($images as $image) {
				$i = (object)$image->get('i')->values();
				if (isset($i->id) && isset($i->filename) && preg_match('/^\d+$/', (string)$i->filename)) $files[(string)$i->id] = (string)$i->filename;
			}
		}
		$dst->deleteProject($projectId);
		$rows = $this->db->get_results_prepared(
			"select uuid from versions where projectid = $1 and userpkey = $2", array((string)$projectId, $to));
		if ($rows) {
			foreach ($rows as $r) {
				if (preg_match('/^[0-9a-f-]{36}$/i', (string)$r->uuid)) @unlink($this->webroot . "/versions/" . $r->uuid);
			}
		}
		$this->db->prepare_query("delete from versions where projectid = $1 and userpkey = $2", array((string)$projectId, $to));
		return $files;
	}

	/**
	 * Copy one project from one account to another under project, dataset and
	 * tag ids of its own. Throws when anything goes wrong, after removing what
	 * was loaded so far from the target account.
	 *
	 * @param array $reuse       ids an earlier copy of this project used (source id => copy id, tags as "tag:<id>");
	 *                           kept, so a refreshed copy is the same project
	 * @param string|null $replace the earlier copy's project id in the target account, removed before loading
	 * @return array ['project_id', 'name', 'datasets', 'spots', 'images', 'missing_images', 'id_map']
	 */
	public function copyProject($from, $to, $projectId, $label, $reuse = array(), $replace = null)
	{
		$from = (int)$from; $to = (int)$to;
		if (!preg_match('/^\d+$/', (string)$projectId)) throw new Exception("bad project id");
		if ($from === $to) throw new Exception("source and target are the same account");

		$src = new StraboSpot($this->neodb, $from, $this->db);
		$src->setuuid(new UUID());
		$dst = new StraboSpot($this->neodb, $to, $this->db);
		$dst->setuuid(new UUID());

		// ---- read, the way createVersion does ---------------------------------
		$project = $src->getProject($projectId);
		if (!$project || !isset($project->id)) throw new Exception("project $projectId not found for user $from");
		$datasets = $src->getProjectDatasets($projectId);
		$datasets = $datasets['datasets'] ?? array();
		foreach ($datasets as $n => $d) {
			$spots = $src->getDatasetSpots($d['id']);
			$datasets[$n]['versionspots'] = $spots ? $spots : array('features' => array());
		}
		$project = json_decode(json_encode($project));
		$datasets = json_decode(json_encode($datasets));

		$imageFiles = array();   // image id => file name in dbimages
		$images = $src->getProjectImages($projectId);
		if (!empty($images)) {
			foreach ($images as $image) {
				$i = (object)$image->get('i')->values();
				if (isset($i->id) && isset($i->filename) && (string)$i->filename !== '') {
					$imageFiles[(string)$i->id] = (string)$i->filename;
				}
			}
		}

		// ---- new ids -----------------------------------------------------------
		// The earlier copy goes first: its ids are used again, and an account holds one project per id.
		$oldFiles = array();   // image id => file the earlier copy already holds
		if ($replace !== null && preg_match('/^\d+$/', (string)$replace)) {
			$oldFiles = $this->removeCopy($dst, $to, $replace);
		}

		$idMap = array();   // old id (string) => new id (int), project + datasets
		$tagMap = array();  // "tag:<old id>" => new id
		$newProjectId = isset($reuse[(string)$project->id]) ? (int)$reuse[(string)$project->id] : $this->newId($to, 'Project');
		$idMap[(string)$project->id] = $newProjectId;
		foreach ($datasets as $d) {
			$idMap[(string)$d->id] = isset($reuse[(string)$d->id]) ? (int)$reuse[(string)$d->id] : $this->newId($to, 'Dataset');
		}

		unset($project->self);
		$this->remapIds($project, $idMap);
		$project->id = $newProjectId;
		if (isset($project->tags) && is_array($project->tags)) {
			foreach ($project->tags as $tag) {
				if (is_object($tag) && isset($tag->id)) {
					// Tag ids are UUIDs (app 2.32) or, from older versions, numbers stored as numbers or strings; keep the kind.
					$key = 'tag:' . $tag->id;
					if (isset($reuse[$key])) {
						$new = $reuse[$key];
					} elseif (is_string($tag->id) && !preg_match('/^\d+$/', $tag->id)) {
						$new = (new UUID())->v4();
					} else {
						$new = $this->newId(0, null);
						if (is_string($tag->id)) $new = (string)$new;
					}
					$tagMap[$key] = $new;
					$tag->id = $new;
				}
			}
		}
		$name = isset($project->description->project_name) ? (string)$project->description->project_name : '';
		$name = trim(($name === '' ? 'Project' : $name) . " (" . $label . ")");
		if (!isset($project->description) || !is_object($project->description)) $project->description = new stdClass();
		$project->description->project_name = $name;

		// ---- load, the way switchVersion does ---------------------------------
		require_once $this->webroot . '/db/lib/search_sync.php';
		$counts = array('datasets' => 0, 'spots' => 0, 'images' => 0, 'missing_images' => 0);
		$madeFiles = array();
		try {
			$dst->insertProject(json_encode($project));
			if (!$this->exists($to, 'Project', $newProjectId)) throw new Exception("project was not saved");

			foreach ($datasets as $dataset) {
				$spots = isset($dataset->versionspots->features) ? $dataset->versionspots->features : array();
				$spots = $dst->fixIncomingBasemaps($spots);
				unset($dataset->versionspots);
				unset($dataset->self);
				$datasetId = $idMap[(string)$dataset->id];
				$dataset->id = $datasetId;

				$dst->insertDataset(json_encode($dataset), null, $to);
				$dst->addDatasetToProject($newProjectId, $datasetId, "HAS_DATASET");
				$dst->setSampleSyncContext($newProjectId, $datasetId);
				field_search_sync_suppress();
				try {
					foreach ($spots as $spot) {
						unset($spot->properties->self);
						if (!empty($spot->properties->images)) {
							foreach ($spot->properties->images as $i) {
								unset($i->self);
								$imageId = (string)$i->id;
								$source = isset($imageFiles[$imageId]) ? $this->webroot . "/dbimages/" . $imageFiles[$imageId] : '';
								if ($source === '' || !is_file($source)) {
									// Never uploaded by the student: the spot keeps its image record, without a file.
									$counts['missing_images']++;
									continue;
								}
								if ($this->exists($to, 'Image', $imageId)) continue;   // same photo on two spots
								if (isset($oldFiles[$imageId]) && is_file($this->webroot . "/dbimages/" . $oldFiles[$imageId])) {
									// The earlier copy has this photo's file already: use it again.
									$filename = $oldFiles[$imageId];
									unset($oldFiles[$imageId]);
								} else {
									$filename = $this->db->get_var("select nextval('image_seq')");
									if (!copy($source, $this->webroot . "/dbimages/$filename")) throw new Exception("image $imageId could not be copied");
								}
								$madeFiles[] = $filename;
								$node = clone $i;
								$node->userpkey = $to;
								$node->origfilename = "image.jpeg";
								$node->imagesha1 = sha1_file($source);
								$node->filename = "$filename";
								$this->neodb->createNode(json_encode($node), "Image");
								$counts['images']++;
							}
						}
						$spotId = $spot->properties->id;
						$dst->insertSpot(json_encode($spot));
						$dst->addSpotToDataset($datasetId, $spotId);
						$counts['spots']++;
					}
				} finally {
					$dst->clearSampleSyncContext();
					field_search_sync_resume();
				}
				$dst->setDatasetCenter($datasetId);
				$dst->setProjectCenter($newProjectId);
				$dst->buildPgDataset($datasetId);
				field_search_sync_dataset($this->db, $this->neodb, $datasetId, $to);
				$counts['datasets']++;
				$this->afterDataset($datasetId);
			}
		} catch (\Throwable $e) {
			// Take the half-loaded copy out of the target account again, with the site's own
			// project delete (it knows every kind of node a spot can own). That delete first
			// saves a version of what it removes; a version of a failed copy is of no use to
			// anyone, so it is dropped again. The id is new, so only this copy can match.
			try {
				$this->removeCopy($dst, $to, $newProjectId);
			} catch (\Throwable $ignored) { }
			foreach ($madeFiles as $f) @unlink($this->webroot . "/dbimages/$f");
			foreach ($oldFiles as $f) @unlink($this->webroot . "/dbimages/$f");
			throw $e;
		}
		// Photos the earlier copy had and the student's project no longer has.
		foreach ($oldFiles as $f) @unlink($this->webroot . "/dbimages/$f");

		return array('project_id' => $newProjectId, 'name' => $name) + $counts + array('id_map' => $idMap + $tagMap);
	}

	/** Called after each dataset of a copy is loaded. Does nothing; tests override it to fail mid-copy. */
	protected function afterDataset($datasetId)
	{
	}

	// -------------------------------------------------------------------

	/**
	 * A new id in the app's format (milliseconds x 10, 14 digits), never issued
	 * twice by this object and, when a label is given, not used by a node of
	 * that kind in the target account.
	 */
	private function newId($userpkey, $label)
	{
		do {
			$id = max((int)floor(microtime(true) * 10000), $this->lastId + 1);
			$this->lastId = $id;
		} while ($label !== null && $this->exists($userpkey, $label, $id));
		return $id;
	}

	private function exists($userpkey, $label, $id)
	{
		if (!preg_match('/^\d+$/', (string)$id)) return false;
		return (int)$this->neodb->get_var("match (n:$label) where n.id = $id and n.userpkey = " . (int)$userpkey . " return count(n)") > 0;
	}

	/**
	 * Replace every value in the project that equals an old project or dataset
	 * id (for example a preference naming the active dataset) with the new id.
	 * Tag -> spot lists are left alone: spot ids do not change.
	 */
	private function remapIds(&$node, array $idMap)
	{
		if (is_object($node)) {
			foreach ($node as $key => &$value) {
				if ($key === 'spots' || $key === 'features') continue;
				$this->remapIds($value, $idMap);
			}
			unset($value);
		} elseif (is_array($node)) {
			foreach ($node as &$value) $this->remapIds($value, $idMap);
			unset($value);
		} elseif ((is_int($node) || is_string($node)) && isset($idMap[(string)$node])) {
			$node = is_string($node) ? (string)$idMap[(string)$node] : $idMap[(string)$node];
		}
	}
}
