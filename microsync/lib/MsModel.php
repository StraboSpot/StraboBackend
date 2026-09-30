<?php
/**
 * File: MsModel.php
 * Description: Entity rules for the StraboMicro sync store: entity types,
 *              their parent types, the child collections that are separate
 *              entities (never part of a body), per-user fields (never
 *              synced), and dotted-path field updates.
 *
 *              Spec: StraboMicro2 docs/specs/collaboration-workflow-spec-v3.md
 *              §3.3 (entities), §4.2 (lists are atomic), §7.1 (per-user).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

/** A change that breaks a structural rule; becomes status "invalid". */
class MsInvalid extends Exception {
	public $reason;

	public function __construct($reason, $message) {
		parent::__construct($message);
		$this->reason = $reason;
	}
}

class MsModel {

	/** Entity type => the type of its structural parent (null: none). */
	public static $PARENT = array(
		'project'    => null,
		'dataset'    => 'project',
		'sample'     => 'dataset',
		'micrograph' => 'sample',
		'spot'       => 'micrograph',
		'tag'        => 'project',
		'group'      => 'project',
		'preset'     => 'project',
		'point_count' => 'micrograph', // point-counts/<id>.json in the app, not project.json
	);

	/**
	 * Entity type => child collection key => child entity type. These keys
	 * hold separate entities and never appear in a body. Id lists with the
	 * same names on other types (group.micrographs, micrograph.tags) are
	 * ordinary fields.
	 */
	public static $CHILD_KEYS = array(
		'project'    => array('datasets' => 'dataset', 'tags' => 'tag', 'groups' => 'group', 'presets' => 'preset'),
		'dataset'    => array('samples' => 'sample'),
		'sample'     => array('micrographs' => 'micrograph'),
		'micrograph' => array('spots' => 'spot'),
		'spot'       => array(),
		'tag'        => array(),
		'group'      => array(),
		'preset'     => array(),
		'point_count' => array(),
	);

	/**
	 * Body fields that mirror the entity's identity or parent and so can
	 * never be set through a field update.
	 */
	public static $FIXED_FIELDS = array(
		'point_count' => array('micrographId'),
	);

	/** Per-user fields: stay in the local project.json, dropped here. */
	public static function perUserFields($type) {
		if ($type === 'project') {
			return array('presetKeyBindings', 'grainAnalysisSpotFilter', 'grainAnalysisSelectedSpotIds');
		}
		return array('isExpanded', 'isSpotExpanded');
	}

	public static function isType($type) {
		return is_string($type) && array_key_exists($type, self::$PARENT);
	}

	public static function isId($id) {
		return is_string($id) && $id !== '' && strlen($id) <= 200 && !preg_match('/[\x00-\x1f\x7f]/', $id);
	}

	public static function key($type, $id) {
		return $type . ':' . $id;
	}

	/**
	 * Validate a create body: an object, no child collections, id matching
	 * the entity id (filled in when absent). Per-user fields are dropped.
	 */
	public static function cleanBody($type, $id, $body, $parentId = null) {
		if (!is_object($body)) {
			throw new MsInvalid('schema', 'body must be a JSON object');
		}
		foreach (self::$CHILD_KEYS[$type] as $childKey => $childType) {
			if (property_exists($body, $childKey)) {
				throw new MsInvalid('schema', "a $type body must not contain \"$childKey\"; $childType entities are pushed separately");
			}
		}
		foreach (self::perUserFields($type) as $f) {
			unset($body->$f);
		}
		if (property_exists($body, 'id') && $body->id !== $id) {
			throw new MsInvalid('schema', 'body.id does not match the entity id');
		}
		$body->id = $id;
		if ($type === 'point_count') {
			if (property_exists($body, 'micrographId') && $body->micrographId !== $parentId) {
				throw new MsInvalid('schema', 'body.micrographId does not match the parent micrograph');
			}
			$body->micrographId = $parentId;
		}
		return $body;
	}

	/**
	 * Apply {"a.b": value} updates to a copy of $body. Each value replaces
	 * the whole value at its path (lists are atomic); null removes the key.
	 * Returns array(newBody, changedPaths). Per-user paths are ignored.
	 */
	public static function applyFields($type, $body, $fields) {
		if (!is_object($fields)) {
			throw new MsInvalid('schema', 'fields must be a JSON object');
		}
		$new = json_decode(json_encode($body));
		$changed = array();
		$perUser = self::perUserFields($type);
		foreach (get_object_vars($fields) as $path => $value) {
			if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/', $path)) {
				throw new MsInvalid('schema', "bad field path \"$path\"");
			}
			$parts = explode('.', $path);
			$top = $parts[0];
			if ($top === 'id') {
				throw new MsInvalid('schema', 'the id field cannot be changed');
			}
			if (isset(self::$FIXED_FIELDS[$type]) && in_array($top, self::$FIXED_FIELDS[$type], true)) {
				throw new MsInvalid('schema', "the $top field cannot be changed");
			}
			if (array_key_exists($top, self::$CHILD_KEYS[$type])) {
				throw new MsInvalid('schema', "\"$top\" holds separate entities and cannot be set as a field");
			}
			if (in_array($top, $perUser, true)) {
				continue;
			}
			$node = $new;
			$last = count($parts) - 1;
			for ($i = 0; $i < $last; $i++) {
				$p = $parts[$i];
				if (!property_exists($node, $p) || $node->$p === null) {
					if ($value === null) {
						continue 2; // removing under a missing parent: nothing to do
					}
					$node->$p = new stdClass();
				} elseif (!is_object($node->$p)) {
					throw new MsInvalid('schema', "\"$path\" goes through a value that is not an object");
				}
				$node = $node->$p;
			}
			$leaf = $parts[$last];
			if ($value === null) {
				unset($node->$leaf);
			} else {
				$node->$leaf = $value;
			}
			$changed[] = $path;
		}
		return array($new, $changed);
	}

	/**
	 * Validate a childOrder object for $type: known child keys only, each an
	 * array of id strings. Returns it as an associative array.
	 */
	public static function checkChildOrder($type, $order) {
		if (!is_object($order)) {
			throw new MsInvalid('schema', 'childOrder must be a JSON object');
		}
		$out = array();
		foreach (get_object_vars($order) as $k => $ids) {
			if (!array_key_exists($k, self::$CHILD_KEYS[$type])) {
				throw new MsInvalid('schema', "a $type has no child collection \"$k\"");
			}
			if (!is_array($ids)) {
				throw new MsInvalid('schema', "childOrder.$k must be an array of ids");
			}
			$list = array();
			foreach ($ids as $cid) {
				if (!self::isId($cid)) {
					throw new MsInvalid('schema', "childOrder.$k contains a bad id");
				}
				$list[] = $cid;
			}
			$out[$k] = array_values(array_unique($list));
		}
		return $out;
	}

	/**
	 * Effective child order (v3 §4.3): stored ids that are live children
	 * of that type, then any other live children in creation order.
	 * $stored: array key => ids; $children: array childType => ids in
	 * creation order. Returns array key => ids for every child key.
	 */
	public static function normalizeChildOrder($type, $stored, $children) {
		$out = array();
		foreach (self::$CHILD_KEYS[$type] as $k => $childType) {
			$live = isset($children[$childType]) ? $children[$childType] : array();
			$liveSet = array_flip($live);
			$list = array();
			$seen = array();
			if (isset($stored[$k]) && is_array($stored[$k])) {
				foreach ($stored[$k] as $cid) {
					if (isset($liveSet[$cid]) && !isset($seen[$cid])) {
						$list[] = $cid;
						$seen[$cid] = true;
					}
				}
			}
			foreach ($live as $cid) {
				if (!isset($seen[$cid])) {
					$list[] = $cid;
					$seen[$cid] = true;
				}
			}
			$out[$k] = $list;
		}
		return $out;
	}
}
