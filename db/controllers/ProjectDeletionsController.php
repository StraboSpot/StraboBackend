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
 *                  {"type": "tag", "id": 17636..., "deleted_at": ...},
 *                  {"type": "tag_spot", "id": "<tag id>/<spot id>", "deleted_at": ...}],
 *    "restored":  [{"type": "tag_spot", "id": "<tag id>/<spot id>"}]}
 *
 * tag_spot is a spot taken out of a tag or geologic unit (the upload merge
 * unions a tag's spots, so that never sticks either). "restored" forgets
 * earlier deletes the client has undone, such as a spot put back into a unit;
 * it is applied before the deletions. Either list may be empty, not both.
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
		$raw = isset($upload['deletions']) && is_array($upload['deletions']) ? $upload['deletions'] : [];
		$rawRestored = isset($upload['restored']) && is_array($upload['restored']) ? $upload['restored'] : [];
		if(count($raw) == 0 && count($rawRestored) == 0){
			header("Bad Request", true, 400);
			return ["Error" => "No deletions sent."];
		}
		if(count($raw) + count($rawRestored) > self::MAX_ITEMS){
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

		// Clean the lists: known types, ids as text, a delete time in ms.
		$items = [];
		$restored = [];
		foreach([[$raw, true], [$rawRestored, false]] as [$list, $isDelete]){
			foreach($list as $d){
				$item = $this->cleanItem($d, $now);
				if($item === null){
					header("Bad Request", true, 400);
					return ["Error" => "Each item needs a type (report, tag or tag_spot) and an id (tag_spot: \"<tag id>/<spot id>\")."];
				}
				if($isDelete) $items[] = $item;
				else $restored[] = $item;
			}
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

		$this->strabo->clearFieldDeletions($projectid, $ownerPkey, $restored);
		$this->strabo->recordFieldDeletions($projectid, $ownerPkey, $uploaderPkey, $accepted);

		// Take them out now, through the normal project save (version first),
		// so the website and the next download no longer show them.
		$removed = 0;
		if(count($accepted) > 0 && isset($project->id)){
			$ids = ['report' => [], 'tag' => [], 'tag_spot' => []];
			foreach($accepted as $a) $ids[$a['type']][$a['id']] = true;
			foreach(['reports' => 'report', 'tags' => 'tag'] as $key => $type){
				foreach((isset($project->$key) && is_array($project->$key) ? $project->$key : []) as $x){
					if(!isset($x->id)) continue;
					if(isset($ids[$type][(string)$x->id])){ $removed++; continue; }
					if($type === 'tag' && isset($x->spots) && is_array($x->spots)){
						foreach($x->spots as $sp){
							if(isset($ids['tag_spot'][(string)$x->id.'/'.(is_float($sp) ? sprintf('%.0f', $sp) : (string)$sp)])) $removed++;
						}
					}
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
			'restored' => count($restored),
			'removed' => $removed,
			'refused' => $refused,
		];
	}

	/** One list entry as ['type', 'id', 'deleted_at'], or null when it is not valid. */
	private function cleanItem($d, $now){
		$d = (array)$d;
		$type = isset($d['type']) ? (string)$d['type'] : '';
		$raw = $d['id'] ?? null;
		if(is_float($raw)) $id = sprintf('%.0f', $raw);
		else if(is_string($raw) || is_int($raw)) $id = (string)$raw;
		else $id = '';
		if(!in_array($type, ['report', 'tag', 'tag_spot'], true) || $id === '' || strlen($id) > 64) return null;
		if($type === 'tag_spot' && !preg_match('#^[^/]+/[^/]+$#', $id)) return null;
		$at = isset($d['deleted_at']) && is_numeric($d['deleted_at']) ? (int)$d['deleted_at'] : 0;
		if($at <= 0) $at = $now;
		return ['type' => $type, 'id' => $id, 'deleted_at' => $at];
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
