<?php
/**
 * File: includes/fieldcamp/FieldCampRoster.php
 * Description: Field Camp Wizard, stage 5: the roster. For every account of a
 *              camp, what the student has uploaded to the server so far:
 *              each project with its last upload time and the number of
 *              datasets, spots and photos. Read-only; counts come straight
 *              from the students' own data in Neo4j.
 *
 *              In a shared-package camp every student's project carries the
 *              camp's project id (FieldCampPackage::ensureIds), so "the camp
 *              project" of an account is the one with that id; anything else
 *              an account holds is listed as another project.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class FieldCampRoster
{
	/**
	 * The Field Book of one student's project, read straight from the student's account (no copy needed):
	 * may $sessionUser have it, and which datasets is it? Allowed for whoever may manage the camp (its owner
	 * or the admin), for an account of that camp that has not been removed. The datasets are looked up under
	 * the account itself: every student's camp project has the same project and dataset ids, so an id alone
	 * does not say whose data is meant.
	 *
	 * @return array|null null = no such book for this user (answer as "not found"), else
	 *                    ['camp' => int, 'project' => string, 'run_as' => int (the account), 'label' => username,
	 *                     'dsids' => [string] (empty when the project is not on the server)]
	 */
	public static function book($db, $neodb, $sessionUser, $campId, $accountPkey, $projectId)
	{
		require_once __DIR__ . '/FieldCamp.php';
		$sessionUser = (int)$sessionUser; $accountPkey = (int)$accountPkey; $projectId = (string)$projectId;
		if ($sessionUser <= 0 || $accountPkey <= 0 || !preg_match('/^[0-9]{1,20}$/', $projectId)) return null;
		if (!FieldCamp::canUse($db, $sessionUser)) return null;
		$camp = FieldCamp::getCamp($db, (int)$campId);
		if (!$camp || !FieldCamp::canManage($camp, $sessionUser)) return null;
		$account = null;
		foreach (FieldCamp::listAccounts($db, $camp->pkey) as $a) if ((int)$a->user_pkey === $accountPkey) $account = $a;
		if (!$account) return null;
		$rows = $neodb->get_results("
			match (p:Project {id: $projectId})-[:HAS_DATASET]->(d:Dataset)
			where p.userpkey = $accountPkey and d.userpkey = $accountPkey
			return distinct d.id as id order by d.id");
		$dsids = array();
		foreach (($rows ? $rows : array()) as $r) $dsids[] = (string)$r->get('id');
		return array('camp' => (int)$camp->pkey, 'project' => $projectId, 'run_as' => $accountPkey,
			'label' => (string)$account->username, 'dsids' => $dsids);
	}

	/**
	 * @param array $accounts rows of FieldCamp::listAccounts()
	 * @return array ['rows' => [per account: 'username', 'user_pkey', 'active' (bool), 'projects' => [...]],
	 *                'uploaded' => int (accounts with the camp project, or any project when the camp has no id yet),
	 *                'total' => int, 'spots' => int, 'photos' => int]
	 *               A project: 'id', 'name', 'camp' (bool), 'uploaded' (unix time or null), 'datasets', 'spots',
	 *               'photos', 'photos_missing' (recorded in a spot but the file was never uploaded)
	 */
	public static function forCamp($neodb, $camp, $accounts)
	{
		$out = array('rows' => array(), 'uploaded' => 0, 'total' => count($accounts), 'spots' => 0, 'photos' => 0);
		$campProjectId = (string)($camp->project_id ?? '');
		$pks = array();
		foreach ($accounts as $a) $pks[] = (int)$a->user_pkey;

		$byUser = array();
		if ($pks) {
			$rows = $neodb->get_results("
				match (p:Project) where p.userpkey in [" . implode(',', $pks) . "]
				optional match (p)-[:HAS_DATASET]->(d:Dataset)
				optional match (d)-[:HAS_SPOT]->(s:Spot)
				optional match (s)-[:HAS_IMAGE]->(i:Image)
				return p.userpkey as pk, p.id as id, p.desc_project_name as name, p.uploaddate as up,
					max(d.uploaddate) as dup, count(distinct d) as nd, count(distinct s) as ns, count(distinct i) as ni,
					count(distinct case when i.filename is null or i.filename = '' then i end) as nmissing
				");
			foreach (($rows ? $rows : array()) as $r) {
				$id = (string)$r->get('id');
				$when = max((int)$r->get('up'), (int)$r->get('dup'));
				$byUser[(int)$r->get('pk')][] = array(
					'id' => $id,
					'name' => (string)$r->get('name'),
					'camp' => ($campProjectId !== '' && $id === $campProjectId),
					'uploaded' => $when > 0 ? $when : null,
					'datasets' => (int)$r->get('nd'),
					'spots' => (int)$r->get('ns'),
					'photos' => (int)$r->get('ni'),
					'photos_missing' => (int)$r->get('nmissing'),
				);
			}
		}

		foreach ($accounts as $a) {
			$pk = (int)$a->user_pkey;
			$projects = $byUser[$pk] ?? array();
			// The camp project first, then the others by name.
			usort($projects, function ($x, $y) {
				if ($x['camp'] !== $y['camp']) return $x['camp'] ? -1 : 1;
				return strcasecmp($x['name'], $y['name']);
			});
			$has = false;
			foreach ($projects as $p) {
				if ($p['camp'] || $campProjectId === '') $has = true;
				$out['spots'] += $p['spots'];
				$out['photos'] += $p['photos'];
			}
			if ($has) $out['uploaded']++;
			$out['rows'][] = array(
				'username' => (string)$a->username,
				'user_pkey' => $pk,
				'active' => ($a->active === true || $a->active === 't'),
				'projects' => $projects,
			);
		}
		return $out;
	}
}
