<?php
/**
 * File: ProjectDeletionsController.php
 * Description: Explicit deletes of a project's memos (reports) and tags.
 *
 * A project upload keeps every memo and tag the upload leaves out (one account
 * on several devices looks like a solo user, and a replace let a stale device
 * wipe what the others made), so leaving an item out never deletes it. A client
 * that deleted items says so here:
 *
 *   POST /db/projectDeletions/{project id}
 *   {"deletions": [{"type": "report", "id": "...", "deleted_at": 1763673000000},
 *                  {"type": "tag", "id": 17636..., "deleted_at": ...}]}
 *
 * The items are recorded (sql/field_deletions.sql) and taken out of the project
 * at once (a version is saved first, as for any project upload). From then on
 * every upload that still carries them drops them, unless that copy of a memo
 * was edited after the delete. A memo can be deleted only by its author (or,
 * for a memo with no author, by the project owner), as memo edits are.
 *
 *   GET /db/projectDeletions/{project id}  lists the recorded deletes.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class ProjectDeletionsController extends MyController
{
	const MAX_ITEMS = 1000;

	public function getAction($request) {
		$projectid = isset($request->url_elements[2]) ? $request->url_elements[2] : '';
		if(!preg_match('/^[0-9]{1,20}$/', (string)$projectid)){
			header("Bad Request", true, 400);
			return ["Error" => "Project ID must be provided."];
		}
		$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectid);
		if (!$context->canRead()) {
			return $this->notFound("Project not found");
		}
		$del = $this->strabo->getFieldDeletions($projectid, $context->effectiveOwner);
		$out = [];
		foreach($del as $type => $items){
			foreach($items as $id => $at){
				$out[] = ['type' => $type, 'id' => (string)$id, 'deleted_at' => $at];
			}
		}
		return ['deletions' => $out];
	}

	public function postAction($request) {
		$projectid = isset($request->url_elements[2]) ? $request->url_elements[2] : '';
		if(!preg_match('/^[0-9]{1,20}$/', (string)$projectid)){
			header("Bad Request", true, 400);
			return ["Error" => "Project ID must be provided."];
		}

		$upload = $request->parameters;
		$raw = isset($upload['deletions']) ? $upload['deletions'] : null;
		if(!is_array($raw) || count($raw) == 0){
			header("Bad Request", true, 400);
			return ["Error" => "No deletions sent."];
		}
		if(count($raw) > self::MAX_ITEMS){
			header("Bad Request", true, 400);
			return ["Error" => "Too many deletions in one request (".self::MAX_ITEMS." at most)."];
		}

		$context = $this->auth->getProjectContext($this->strabo->userpkey, $projectid);
		if (!$context->canRead()) {
			return $this->notFound("Project not found");
		}
		if ($context->permissionLevel === 'readonly') {
			return $this->forbidden("You don't have edit permission on this project");
		}

		$uploaderPkey = (int)$this->strabo->userpkey;
		$ownerPkey = (int)$context->effectiveOwner;
		$now = (int)round(microtime(true) * 1000);

		// Clean the list: known types, ids as text, a delete time in ms.
		$items = [];
		foreach($raw as $d){
			$d = (array)$d;
			$type = isset($d['type']) ? (string)$d['type'] : '';
			$id = isset($d['id']) && (is_string($d['id']) || is_int($d['id']) || is_float($d['id'])) ? (string)$d['id'] : '';
			if(is_float($d['id'] ?? null)) $id = sprintf('%.0f', $d['id']);
			if(($type !== 'report' && $type !== 'tag') || $id === '' || strlen($id) > 64){
				header("Bad Request", true, 400);
				return ["Error" => "Each deletion needs a type (report or tag) and an id."];
			}
			$at = isset($d['deleted_at']) && is_numeric($d['deleted_at']) ? (int)$d['deleted_at'] : 0;
			if($at <= 0) $at = $now;
			$items[] = ['type' => $type, 'id' => $id, 'deleted_at' => $at];
		}

		// The project as stored (under its owner).
		if ($ownerPkey !== $uploaderPkey) $this->strabo->setuserpkey($ownerPkey);
		$project = $this->strabo->getProject($projectid);
		if ($ownerPkey !== $uploaderPkey) $this->strabo->setuserpkey($uploaderPkey);

		// A memo is its author's to delete, as it is to edit (mergeReports).
		$authors = [];
		foreach((isset($project->reports) && is_array($project->reports) ? $project->reports : []) as $r){
			if(isset($r->id)) $authors[(string)$r->id] = isset($r->straboUserId) && $r->straboUserId !== '' ? (int)$r->straboUserId : $ownerPkey;
		}
		$accepted = [];
		$refused = [];
		foreach($items as $item){
			if($item['type'] === 'report' && isset($authors[$item['id']]) && $authors[$item['id']] !== $uploaderPkey){
				$refused[] = ['type' => 'report', 'id' => $item['id'], 'reason' => 'Only the memo\'s author can delete it.'];
				continue;
			}
			$accepted[] = $item;
		}

		$this->strabo->recordFieldDeletions($projectid, $ownerPkey, $uploaderPkey, $accepted);

		// Take them out now, through the normal project save (version first),
		// so the website and the next download no longer show them.
		$removed = 0;
		if(count($accepted) > 0 && isset($project->id)){
			$ids = ['report' => [], 'tag' => []];
			foreach($accepted as $a) $ids[$a['type']][$a['id']] = true;
			foreach(['reports' => 'report', 'tags' => 'tag'] as $key => $type){
				foreach((isset($project->$key) && is_array($project->$key) ? $project->$key : []) as $x){
					if(isset($x->id) && isset($ids[$type][(string)$x->id])) $removed++;
				}
			}
			if($removed > 0){
				$isCollaborativeEdit = ($context->permissionLevel === 'edit' && !$context->isOwner());
				if ($ownerPkey !== $uploaderPkey) $this->strabo->setuserpkey($ownerPkey);
				$this->strabo->createVersion($projectid);
				foreach(['isOwner', 'isReadOnly', 'self'] as $k) unset($project->$k);
				$this->strabo->insertProject(json_encode($project), $projectid, $isCollaborativeEdit, $ownerPkey, $uploaderPkey);
				if ($ownerPkey !== $uploaderPkey) $this->strabo->setuserpkey($uploaderPkey);
			}
		}

		return [
			'recorded' => count($accepted),
			'removed' => $removed,
			'refused' => $refused,
		];
	}

	public function deleteAction($request) {
		header("Bad Request", true, 400);
		return ["Error" => "Bad Request."];
	}

	public function putAction($request) {
		header("Bad Request", true, 400);
		return ["Error" => "Bad Request."];
	}
}
