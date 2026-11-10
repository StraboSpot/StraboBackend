<?php
/**
 * File: includes/fieldcamp/FieldCampCopy.php
 * Description: Field Camp Wizard: copy a student's StraboField projects into
 *              the camp owner's account, so the work is kept when the camp
 *              accounts are removed.
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
 *              An account must be deactivated before it is copied, so the
 *              copy cannot miss an upload that arrives halfway through.
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
	 * Copy every project of one camp account into the camp owner's account.
	 * Projects copied before are skipped, so this is safe to run again.
	 *
	 * @param object $camp    fieldcamp row
	 * @param object $account fieldcamp_account row joined with users (FieldCamp::listAccounts)
	 * @return array ['error' => string, 'copied' => [[name, datasets, spots, images, missing_images]], 'skipped' => int]
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
		if (FieldCamp::truthy($account->active)) {
			$out['error'] = "Deactivate " . $account->username . " first, so nothing is uploaded while it is copied.";
			return $out;
		}

		foreach ($this->projectsOf($from) as $p) {
			$done = (int)$this->db->get_var_prepared("
				select count(*) from fieldcamp_copy
				where source_user_pkey = $1 and source_project_id = $2 and target_user_pkey = $3
				", array($from, $p['id'], $to));
			if ($done > 0) { $out['skipped']++; continue; }

			try {
				$result = $this->copyProject($from, $to, $p['id'], $account->username);
			} catch (\Throwable $e) {
				error_log("FieldCampCopy camp " . (int)$camp->pkey . " account $from project " . $p['id'] . ": " . $e->getMessage());
				$out['error'] = "The project \"" . $p['name'] . "\" of " . $account->username
					. " could not be copied. Nothing of it was kept in your account; the student's project is untouched.";
				return $out;
			}

			$this->db->prepare_query("
				insert into fieldcamp_copy (fieldcamp_pkey, source_user_pkey, source_project_id, target_user_pkey,
					target_project_id, project_name, dataset_count, spot_count, image_count)
				values ($1, $2, $3, $4, $5, $6, $7, $8, $9)
				", array((int)$camp->pkey, $from, $p['id'], $to, (string)$result['project_id'], $result['name'],
					$result['datasets'], $result['spots'], $result['images']));
			$out['copied'][] = $result;
		}
		return $out;
	}

	/**
	 * Copy one project from one account to another under new project, dataset
	 * and tag ids. Throws when anything goes wrong, after removing what was
	 * loaded so far from the target account.
	 *
	 * @return array ['project_id', 'name', 'datasets', 'spots', 'images', 'missing_images']
	 */
	public function copyProject($from, $to, $projectId, $label)
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
		$idMap = array();   // old id (string) => new id (int), project + datasets
		$newProjectId = $this->newId($to, 'Project');
		$idMap[(string)$project->id] = $newProjectId;
		foreach ($datasets as $d) {
			$idMap[(string)$d->id] = $this->newId($to, 'Dataset');
		}

		unset($project->self);
		$this->remapIds($project, $idMap);
		$project->id = $newProjectId;
		if (isset($project->tags) && is_array($project->tags)) {
			foreach ($project->tags as $tag) {
				if (is_object($tag) && isset($tag->id)) {
					// Tag ids are stored as strings or numbers depending on the app version; keep the kind.
					$new = $this->newId(0, null);
					$tag->id = is_string($tag->id) ? (string)$new : $new;
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
								$filename = $this->db->get_var("select nextval('image_seq')");
								if (!copy($source, $this->webroot . "/dbimages/$filename")) throw new Exception("image $imageId could not be copied");
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
				$dst->deleteProject($newProjectId);
				$rows = $this->db->get_results_prepared(
					"select uuid from versions where projectid = $1 and userpkey = $2", array((string)$newProjectId, $to));
				if ($rows) {
					foreach ($rows as $r) {
						if (preg_match('/^[0-9a-f-]{36}$/i', (string)$r->uuid)) @unlink($this->webroot . "/versions/" . $r->uuid);
					}
				}
				$this->db->prepare_query(
					"delete from versions where projectid = $1 and userpkey = $2", array((string)$newProjectId, $to));
			} catch (\Throwable $ignored) { }
			foreach ($madeFiles as $f) @unlink($this->webroot . "/dbimages/$f");
			throw $e;
		}

		return array('project_id' => $newProjectId, 'name' => $name) + $counts;
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
