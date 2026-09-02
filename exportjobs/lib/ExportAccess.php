<?php
/**
 * File: exportjobs/lib/ExportAccess.php
 * Description: Export Builder access resolver (design §11, decision D2).
 *              Answers "may this session export this Field project?" from
 *              three independent facts, in order: the session user owns
 *              the project; the session user holds an accepted, enabled
 *              collaborator row for (project, owner); or the project is
 *              public. Built for scope 3 (public) even though the v1 picker
 *              only offers the first two, so a hand-crafted recipe can never
 *              reach a private stranger project.
 *
 *              Strabo project ids are NOT unique across accounts, so every
 *              check keys on the (project_id, owner_pkey) pair.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

class ExportAccess
{
	const OWNER = 'owner';
	const COLLABORATOR = 'collaborator';
	const PUBLIC_PROJECT = 'public';

	/**
	 * @return string|null  one of the class constants, or null = no access
	 */
	public static function level($db, $sessionUserpkey, $projectId, $ownerPkey)
	{
		$me = (int)$sessionUserpkey;
		$owner = (int)$ownerPkey;
		$pid = (string)$projectId;
		if ($pid === '' || $owner <= 0) return null;

		if ($me > 0 && $me === $owner) return self::OWNER;

		if ($me > 0) {
			$c = $db->get_var_prepared(
				"SELECT 1 FROM collaborators
				  WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2
				    AND collaborator_user_pkey = $3 AND accepted = TRUE AND disabled = FALSE
				  LIMIT 1",
				array($pid, $owner, $me));
			if ($c) return self::COLLABORATOR;
		}

		$p = $db->get_var_prepared(
			"SELECT 1 FROM project WHERE strabo_project_id = $1 AND user_pkey = $2 AND ispublic = TRUE LIMIT 1",
			array($pid, $owner));
		if ($p) return self::PUBLIC_PROJECT;

		return null;
	}

	public static function canExport($db, $sessionUserpkey, $projectId, $ownerPkey)
	{
		return self::level($db, $sessionUserpkey, $projectId, $ownerPkey) !== null;
	}
}
