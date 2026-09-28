<?php
/**
 * File: includes/sesar/SesarDb.php
 * Description: Small PostgreSQL helpers shared by the SESAR classes: the
 *              per-sample advisory lock (one namespace for mint, pull, push
 *              and deactivation, so no two of them overlap on one sample)
 *              and the text[] literal for ANY($n::text[]) parameters.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class SesarDb
{
	const LOCK_NS = 7271;

	/**
	 * Try to take the lock for ($userpkey, $key); never waits. $key is a
	 * sample id, 'igsn:<IGSN>' or 'reg:<pkey>'. Held by the database session
	 * until unlock() (or the end of the request).
	 * @return bool false when another request holds it
	 */
	public static function lock($db, $userpkey, $key)
	{
		return $db->get_var_prepared("SELECT pg_try_advisory_lock($1, hashtext($2))",
			array(self::LOCK_NS, (int)$userpkey . ':' . $key)) === 't';
	}

	public static function unlock($db, $userpkey, $key)
	{
		$db->get_var_prepared("SELECT pg_advisory_unlock($1, hashtext($2))", array(self::LOCK_NS, (int)$userpkey . ':' . $key));
	}

	/** PostgreSQL text[] literal for a prepared-statement parameter. */
	public static function pgTextArray(array $vals)
	{
		return '{' . implode(',', array_map(function ($v) {
			return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$v) . '"';
		}, $vals)) . '}';
	}
}
