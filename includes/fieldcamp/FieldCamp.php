<?php
/**
 * File: includes/fieldcamp/FieldCamp.php
 * Description: Field Camp Wizard service (stages 1 and 2): who may use the
 *              wizard, camp records, the dedicated camp accounts (create from a
 *              username template, deactivate, reactivate, remove) and the
 *              accounts that may see a camp's student work.
 *
 *              A "camp account" is a users row with a fieldcamp_account row.
 *              Camp accounts cannot change their password or email and cannot
 *              delete themselves (see isCampAccount() and its callers), because
 *              a whole camp shares one default password and the instructor owns
 *              the accounts. That password is kept, encrypted, so the
 *              instructor can look it up (campPassword()); there is on purpose
 *              no way to change it afterwards, because a device that has to
 *              sign in again loses the work it has not uploaded.
 *
 *              Tables: sql/fieldcamp.sql. Design: docs/Field_Camp_Builder/DESIGN.md.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class FieldCamp
{
	/** The one account that manages the instructor list. */
	const ADMIN_USERPKEY = 3;

	/** Most accounts one camp may hold. */
	const MAX_ACCOUNTS = 100;

	const MIN_PASSWORD_LENGTH = 4;   // 6 until 2026-11-10 (Jason: camps want short passwords)

	/** Shortest fixed part of a username template (what comes before {n}). */
	const MIN_TEMPLATE_PREFIX = 3;

	const DELIVERIES = array('shared', 'per_student');

	private static $tablesChecked = null;

	// -------------------------------------------------------------------
	// Access
	// -------------------------------------------------------------------

	/**
	 * True once sql/fieldcamp.sql has been applied. Lets the code ship before
	 * the tables exist: every gate then simply answers "no".
	 */
	public static function installed($db)
	{
		if (self::$tablesChecked === null) {
			$found = $db->get_var_prepared("select to_regclass('public.fieldcamp_account') is not null", array());
			self::$tablesChecked = ($found === true || $found === 't');
		}
		return self::$tablesChecked;
	}

	public static function isAdmin($userpkey)
	{
		return (int)$userpkey === self::ADMIN_USERPKEY;
	}

	/** May this account open the Field Camp Wizard? */
	public static function canUse($db, $userpkey)
	{
		$userpkey = (int)$userpkey;
		if ($userpkey <= 0 || !$db || !self::installed($db)) return false;
		if (self::isAdmin($userpkey)) return true;
		return (int)$db->get_var_prepared(
			"select count(*) from fieldcamp_instructors where user_pkey = $1", array($userpkey)) > 0;
	}

	/** Is this users row a dedicated camp account (in any state)? */
	public static function isCampAccount($db, $userpkey)
	{
		$userpkey = (int)$userpkey;
		if ($userpkey <= 0 || !$db || !self::installed($db)) return false;
		return (int)$db->get_var_prepared(
			"select count(*) from fieldcamp_account where user_pkey = $1", array($userpkey)) > 0;
	}

	/** The owner of a camp, or the admin, may manage it. */
	public static function canManage($camp, $userpkey)
	{
		if (!$camp) return false;
		return (int)$camp->owner_user_pkey === (int)$userpkey || self::isAdmin($userpkey);
	}

	// -------------------------------------------------------------------
	// Form tokens
	// -------------------------------------------------------------------

	public static function token()
	{
		if (empty($_SESSION['fieldcamp_csrf'])) {
			$_SESSION['fieldcamp_csrf'] = bin2hex(random_bytes(16));
		}
		return $_SESSION['fieldcamp_csrf'];
	}

	public static function tokenOk($given)
	{
		return is_string($given) && !empty($_SESSION['fieldcamp_csrf'])
			&& hash_equals($_SESSION['fieldcamp_csrf'], $given);
	}

	// -------------------------------------------------------------------
	// Instructor list (admin)
	// -------------------------------------------------------------------

	public static function listInstructors($db)
	{
		$rows = $db->get_results_prepared("
			select fi.pkey, fi.user_pkey, fi.note, fi.created_date,
				u.email, u.firstname, u.lastname,
				(select count(*) from fieldcamp fc where fc.owner_user_pkey = fi.user_pkey) as campcount
			from fieldcamp_instructors fi
			join users u on u.pkey = fi.user_pkey
			order by u.email
			", array());
		return $rows ? $rows : array();
	}

	/** @return string '' on success, else what is wrong */
	public static function addInstructor($db, $userpkey, $note, $addedBy)
	{
		$userpkey = (int)$userpkey;
		$row = $db->get_row_prepared("select pkey, active, deleted from users where pkey = $1", array($userpkey));
		if (!$row) return "That account does not exist.";
		if (self::truthy($row->deleted) || !self::truthy($row->active)) return "That account is not active.";
		if (self::isCampAccount($db, $userpkey)) return "A field camp account cannot be an instructor.";
		$have = (int)$db->get_var_prepared(
			"select count(*) from fieldcamp_instructors where user_pkey = $1", array($userpkey));
		if ($have > 0) return "That account is already an instructor.";
		$db->prepare_query(
			"insert into fieldcamp_instructors (user_pkey, note, added_by) values ($1, $2, $3)",
			array($userpkey, trim((string)$note), (int)$addedBy));
		return '';
	}

	/** Removing an instructor only closes the wizard for them; their camps and accounts stay. */
	public static function removeInstructor($db, $pkey)
	{
		$db->prepare_query("delete from fieldcamp_instructors where pkey = $1", array((int)$pkey));
	}

	// -------------------------------------------------------------------
	// Camps
	// -------------------------------------------------------------------

	private static $deleteColumnChecked = null;

	/** True once fieldcamp.deleted_date exists (sql/fieldcamp.sql, 2026-11-10); without it camps cannot be deleted. */
	public static function canDeleteCamps($db)
	{
		if (self::$deleteColumnChecked === null) {
			$n = (int)$db->get_var_prepared("select count(*) from information_schema.columns
				where table_name = 'fieldcamp' and column_name = 'deleted_date'", array());
			self::$deleteColumnChecked = ($n > 0);
		}
		return self::$deleteColumnChecked;
	}

	/** A camp by its key. A deleted camp is "not there" for everything except the admin's restore. */
	public static function getCamp($db, $pkey, $includeDeleted = false)
	{
		$live = (!$includeDeleted && self::canDeleteCamps($db)) ? " and deleted_date is null" : "";
		return $db->get_row_prepared("select * from fieldcamp where pkey = $1" . $live, array((int)$pkey));
	}

	/** Deleted camps, newest first, for the admin's restore list. */
	public static function listDeletedCamps($db)
	{
		if (!self::canDeleteCamps($db)) return array();
		$rows = $db->get_results_prepared("
			select fc.*, (select email from users where pkey = fc.owner_user_pkey) as owner_email,
				(select count(*) from fieldcamp_account fa where fa.fieldcamp_pkey = fc.pkey and fa.removed_date = fc.deleted_date) as accountcount
			from fieldcamp fc where fc.deleted_date is not null order by fc.deleted_date desc", array());
		return $rows ? $rows : array();
	}

	/**
	 * Delete a camp. Soft: the row is kept and hidden, every account of the camp is removed the way
	 * removeAccount() does (closed, data kept), the built tiles are thrown away (they can be built again).
	 * Copies of student work in the owner's account are the owner's projects and stay.
	 *
	 * @return string '' when done, else why not
	 */
	public static function deleteCamp($db, $neodb, $camp, $byUserpkey)
	{
		if (!self::canDeleteCamps($db)) return "Field camps cannot be deleted on this server yet.";
		require_once __DIR__ . '/FieldCampTiles.php';
		$tiles = FieldCampTiles::installed($db);
		if ($tiles && FieldCampTiles::isBuilding($camp)) return "The offline maps of this camp are being built. Delete the camp when the build is done.";
		$when = $db->get_var_prepared("select now()", array());
		foreach (self::listAccounts($db, $camp->pkey) as $a) self::removeAccount($db, $neodb, $camp, (int)$a->user_pkey, $when);
		if ($tiles) {
			FieldCampTiles::clearBuild($db, $camp);
			$dir = FieldCampTiles::dir($camp);
			if ($dir !== null && is_dir($dir) && preg_match('~/fieldcamp_files/[a-f0-9]{32}$~', $dir)) {
				foreach ((array)glob("$dir/*") as $f) { if (is_file($f)) @unlink($f); }
				@rmdir($dir);
			}
		}
		$db->prepare_query("update fieldcamp set deleted_date = $2, deleted_by = $3, modified_date = now() where pkey = $1",
			array((int)$camp->pkey, $when, (int)$byUserpkey));
		return '';
	}

	/**
	 * Bring a deleted camp back (admin). The accounts removed with it come back deactivated; the instructor
	 * reactivates them on the Accounts step. The offline maps have to be built again.
	 *
	 * @return int|null how many accounts came back, null when there is no such deleted camp
	 */
	public static function restoreCamp($db, $camppkey)
	{
		$camp = self::getCamp($db, $camppkey, true);
		if (!$camp || !self::canDeleteCamps($db) || (string)$camp->deleted_date === '') return null;
		$rows = $db->get_results_prepared("select user_pkey from fieldcamp_account where fieldcamp_pkey = $1 and removed_date = $2",
			array((int)$camp->pkey, $camp->deleted_date));
		$n = 0;
		foreach (($rows ? $rows : array()) as $r) { if (self::restoreAccount($db, $camp, (int)$r->user_pkey)) $n++; }
		$db->prepare_query("update fieldcamp set deleted_date = null, deleted_by = null, modified_date = now() where pkey = $1", array((int)$camp->pkey));
		return $n;
	}

	/** The camps an account sees on the wizard's first page (the admin sees every camp). */
	public static function listCamps($db, $userpkey)
	{
		$where = self::isAdmin($userpkey) ? "true" : "fc.owner_user_pkey = $1";
		if (self::canDeleteCamps($db)) $where .= " and fc.deleted_date is null";
		$params = self::isAdmin($userpkey) ? array() : array((int)$userpkey);
		$rows = $db->get_results_prepared("
			select fc.*,
				(select email from users where pkey = fc.owner_user_pkey) as owner_email,
				(select count(*) from fieldcamp_account fa
					where fa.fieldcamp_pkey = fc.pkey and fa.removed_date is null) as accountcount,
				(select count(*) from fieldcamp_account fa
					where fa.fieldcamp_pkey = fc.pkey and fa.removed_date is null
					and fa.deactivated_date is not null) as inactivecount
			from fieldcamp fc
			where $where
			order by fc.camp_year desc, fc.created_date desc
			", $params);
		return $rows ? $rows : array();
	}

	/**
	 * Check the Basics step.
	 * @return array [clean values, list of problems]
	 */
	public static function validateBasics($in)
	{
		$errors = array();
		$name = trim((string)($in['camp_name'] ?? ''));
		$project = trim((string)($in['project_name'] ?? ''));
		$year = trim((string)($in['camp_year'] ?? ''));
		$start = trim((string)($in['start_date'] ?? ''));
		$end = trim((string)($in['end_date'] ?? ''));

		if ($name === '') $errors[] = "Enter a name for the field camp.";
		elseif (mb_strlen($name) > 120) $errors[] = "The field camp name is too long (120 characters at most).";

		if ($project === '') $errors[] = "Enter the project name students will see in StraboField.";
		elseif (mb_strlen($project) > 120) $errors[] = "The project name is too long (120 characters at most).";

		if (!preg_match('/^\d{4}$/', $year) || (int)$year < 2000 || (int)$year > 2100) {
			$errors[] = "Enter the year of the camp (four digits).";
		}

		foreach (array('start' => $start, 'end' => $end) as $label => $value) {
			if ($value !== '' && !self::isDate($value)) $errors[] = "The $label date is not a valid date.";
		}
		if ($start !== '' && $end !== '' && self::isDate($start) && self::isDate($end) && $end < $start) {
			$errors[] = "The end date is before the start date.";
		}

		$clean = array(
			'camp_name' => $name,
			'project_name' => $project,
			'camp_year' => (int)$year,
			'start_date' => $start === '' ? null : $start,
			'end_date' => $end === '' ? null : $end,
		);
		return array($clean, $errors);
	}

	/** @return int the new camp's pkey */
	public static function createCamp($db, $ownerpkey, $clean)
	{
		$pkey = (int)$db->get_var_prepared("select nextval('fieldcamp_pkey_seq')", array());
		$db->prepare_query("
			insert into fieldcamp (pkey, owner_user_pkey, camp_name, camp_year, start_date, end_date, project_name)
			values ($1, $2, $3, $4, $5, $6, $7)
			", array($pkey, (int)$ownerpkey, $clean['camp_name'], $clean['camp_year'],
				$clean['start_date'], $clean['end_date'], $clean['project_name']));
		return $pkey;
	}

	public static function updateBasics($db, $camppkey, $clean)
	{
		$db->prepare_query("
			update fieldcamp set camp_name = $2, camp_year = $3, start_date = $4, end_date = $5,
				project_name = $6, modified_date = now()
			where pkey = $1
			", array((int)$camppkey, $clean['camp_name'], $clean['camp_year'],
				$clean['start_date'], $clean['end_date'], $clean['project_name']));
	}

	/**
	 * Check the Area step (WGS84 degrees).
	 * @return array [clean values, list of problems]
	 */
	public static function validateArea($in)
	{
		$errors = array();
		$v = array();
		foreach (array('west', 'south', 'east', 'north') as $k) {
			$raw = trim((string)($in[$k] ?? ''));
			if ($raw === '' || !is_numeric($raw)) {
				return array(null, array("Draw the study area on the map first."));
			}
			$v[$k] = round((float)$raw, 6);
		}
		if ($v['west'] < -180 || $v['east'] > 180 || $v['south'] < -85 || $v['north'] > 85) {
			$errors[] = "The area reaches past the edge of the map. Draw a smaller study area.";
		}
		if ($v['west'] >= $v['east'] || $v['south'] >= $v['north']) {
			$errors[] = "The area is empty. Draw the study area again.";
		}
		return array($v, $errors);
	}

	public static function saveArea($db, $camppkey, $v)
	{
		$db->prepare_query("
			update fieldcamp set bbox_west = $2, bbox_south = $3, bbox_east = $4, bbox_north = $5,
				modified_date = now()
			where pkey = $1
			", array((int)$camppkey, $v['west'], $v['south'], $v['east'], $v['north']));
	}

	/** Width and height of a camp's area in km (at its middle latitude), or null when no area is saved. */
	public static function areaSizeKm($camp)
	{
		if (!$camp || $camp->bbox_west === null || $camp->bbox_west === '') return null;
		$midlat = ((float)$camp->bbox_south + (float)$camp->bbox_north) / 2;
		$kmPerDegLat = 111.32;
		$kmPerDegLon = 111.32 * cos(deg2rad($midlat));
		return array(
			'width' => ((float)$camp->bbox_east - (float)$camp->bbox_west) * $kmPerDegLon,
			'height' => ((float)$camp->bbox_north - (float)$camp->bbox_south) * $kmPerDegLat,
		);
	}

	// -------------------------------------------------------------------
	// Camp accounts
	// -------------------------------------------------------------------

	/**
	 * Normalize and check a username template such as "ku_camp26_{n}".
	 * @return array [template in its stored form, problem or '']
	 */
	public static function validateTemplate($template)
	{
		$t = strtolower(trim((string)$template));
		if ($t === '') return array($t, "Enter a username template, for example ku_camp26_{n}.");
		if (substr_count($t, '{n}') !== 1) {
			return array($t, "The template needs {n} exactly once; that is where the account number goes.");
		}
		if (!preg_match('/^[a-z0-9._-]*\{n\}[a-z0-9._-]*$/', $t)) {
			return array($t, "Use only letters, digits, dots, dashes and underscores around {n}.");
		}
		$prefix = substr($t, 0, strpos($t, '{n}'));
		if (strlen($prefix) < self::MIN_TEMPLATE_PREFIX) {
			return array($t, "Start the template with at least " . self::MIN_TEMPLATE_PREFIX
				. " characters that name your camp, for example ku_camp26_{n}.");
		}
		if (strlen($t) > 60) return array($t, "The template is too long (60 characters at most).");
		return array($t, '');
	}

	public static function usernameFor($template, $n)
	{
		return str_replace('{n}', (string)(int)$n, $template);
	}

	/** Camp accounts that still exist (not removed), in template order. */
	public static function listAccounts($db, $camppkey, $includeRemoved = false)
	{
		$extra = $includeRemoved ? "" : "and fa.removed_date is null";
		$rows = $db->get_results_prepared("
			select fa.*, u.active, u.deleted
			from fieldcamp_account fa
			join users u on u.pkey = fa.user_pkey
			where fa.fieldcamp_pkey = $1 $extra
			order by fa.seq
			", array((int)$camppkey));
		return $rows ? $rows : array();
	}

	/**
	 * What "create $count accounts" would do for this camp, without doing it.
	 * A camp that already has accounts keeps its template and continues the numbering.
	 *
	 * @return array ['error' => string, 'template' => string, 'names' => [[seq, username, taken]], 'taken' => int]
	 */
	public static function planAccounts($db, $camp, $template, $count)
	{
		$out = array('error' => '', 'template' => '', 'names' => array(), 'taken' => 0);

		$row = $db->get_row_prepared("
			select count(*) as total, coalesce(max(seq), 0) as lastseq,
				count(*) filter (where removed_date is null) as live
			from fieldcamp_account where fieldcamp_pkey = $1
			", array((int)$camp->pkey));
		$lastseq = (int)$row->lastseq;
		$live = (int)$row->live;

		// Once a camp has accounts its template is fixed, so the numbering stays one series.
		if ((int)$row->total > 0 && (string)$camp->username_template !== '') {
			$template = $camp->username_template;
		}
		list($template, $problem) = self::validateTemplate($template);
		$out['template'] = $template;
		if ($problem !== '') { $out['error'] = $problem; return $out; }

		$count = (int)$count;
		if ($count < 1) { $out['error'] = "Enter how many accounts to create."; return $out; }
		if ($live + $count > self::MAX_ACCOUNTS) {
			$room = max(0, self::MAX_ACCOUNTS - $live);
			$out['error'] = "A camp can hold " . self::MAX_ACCOUNTS . " accounts. This camp has $live, so "
				. ($room > 0 ? "at most $room more can be added." : "no more can be added.");
			return $out;
		}

		for ($n = $lastseq + 1; $n <= $lastseq + $count; $n++) {
			$username = self::usernameFor($template, $n);
			$taken = (int)$db->get_var_prepared("select count(*) from users where email = $1", array($username)) > 0;
			if ($taken) $out['taken']++;
			$out['names'][] = array('seq' => $n, 'username' => $username, 'taken' => $taken);
		}
		return $out;
	}

	/**
	 * Create $count accounts for a camp, all with the same password. All or
	 * nothing: one taken username refuses the whole batch.
	 *
	 * @return array ['error' => string, 'created' => [usernames]]
	 */
	public static function createAccounts($db, $neodb, $camp, $template, $count, $password)
	{
		$out = array('error' => '', 'created' => array());

		$password = (string)$password;
		if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
			$out['error'] = "The password needs at least " . self::MIN_PASSWORD_LENGTH . " characters.";
			return $out;
		}
		if ($password !== trim($password)) {
			$out['error'] = "The password cannot start or end with a space.";
			return $out;
		}

		$plan = self::planAccounts($db, $camp, $template, $count);
		if ($plan['error'] !== '') { $out['error'] = $plan['error']; return $out; }
		if ($plan['taken'] > 0) {
			$names = array();
			foreach ($plan['names'] as $p) { if ($p['taken']) $names[] = $p['username']; }
			$out['error'] = "These usernames are already in use: " . implode(", ", array_slice($names, 0, 10))
				. (count($names) > 10 ? " and " . (count($names) - 10) . " more" : "")
				. ". Change the template (for example add the year) and try again.";
			return $out;
		}

		$madePkeys = array();
		$db->query("BEGIN");
		try {
			foreach ($plan['names'] as $p) {
				$username = $p['username'];
				$userpkey = (int)$db->get_var_prepared("select nextval('users_pkey_seq')", array());
				if ($userpkey <= 0) throw new Exception("no user pkey");

				$db->prepare_query("
					insert into users (pkey, firstname, lastname, password, hash, email, active)
					values ($1, $2, $3, crypt($4, gen_salt('md5')), $5, $6, true)
					", array($userpkey, $username, $username, $password, self::randomHash(), $username));
				$db->prepare_query("
					insert into fieldcamp_account (fieldcamp_pkey, user_pkey, username, seq)
					values ($1, $2, $3, $4)
					", array((int)$camp->pkey, $userpkey, $username, (int)$p['seq']));

				$check = (int)$db->get_var_prepared(
					"select count(*) from fieldcamp_account where user_pkey = $1", array($userpkey));
				if ($check !== 1) throw new Exception("account row for $username was not saved");

				// The Field API expects every account to have its :User node.
				$madePkeys[] = $userpkey;
				$neodb->createNode(json_encode(array(
					'userpkey' => $userpkey,
					'firstname' => $username,
					'lastname' => $username,
					'email' => $username,
				)), "User");

				$out['created'][] = $username;
			}
			$db->prepare_query(
				"update fieldcamp set username_template = $2, modified_date = now() where pkey = $1",
				array((int)$camp->pkey, $plan['template']));
			$db->query("COMMIT");
			self::keepPassword($db, (int)$camp->pkey, $password);
		} catch (\Throwable $e) {
			$db->query("ROLLBACK");
			foreach ($madePkeys as $pk) {
				try { $neodb->query("match (u:User) where u.userpkey = " . (int)$pk . " detach delete u"); }
				catch (\Throwable $ignored) { }
			}
			error_log("FieldCamp::createAccounts camp " . (int)$camp->pkey . ": " . $e->getMessage());
			$out['created'] = array();
			$out['error'] = "The accounts could not be created. Nothing was changed; please try again.";
		}
		return $out;
	}

	// -------------------------------------------------------------------
	// The camp password, kept for the instructor
	// -------------------------------------------------------------------

	/** The key the camp passwords are encrypted with: derived from a secret of config.inc.php, never stored. */
	private static function passwordKey()
	{
		global $jwtsecret;
		if (!isset($jwtsecret) || !is_string($jwtsecret) || strlen($jwtsecret) < 16) return null;
		return hash('sha256', 'fieldcamp-account-password|' . $jwtsecret, true);
	}

	/** A password as stored in fieldcamp.account_password_enc (AES-256-GCM), or null when it cannot be kept. */
	public static function sealPassword($plain)
	{
		$key = self::passwordKey();
		if ($key === null || !function_exists('openssl_encrypt')) return null;
		$iv = random_bytes(12);
		$tag = '';
		$cipher = openssl_encrypt((string)$plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
		if ($cipher === false) return null;
		return base64_encode($iv . $tag . $cipher);
	}

	/** The reverse of sealPassword(); null when nothing is stored or it cannot be read (for example after a key change). */
	public static function openPassword($sealed)
	{
		$key = self::passwordKey();
		$raw = base64_decode((string)$sealed, true);
		if ($key === null || $raw === false || strlen($raw) < 29) return null;
		$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
		return $plain === false ? null : $plain;
	}

	/** The password the camp's accounts share, or null when it was never kept. */
	public static function campPassword($camp)
	{
		if (!$camp || !isset($camp->account_password_enc) || (string)$camp->account_password_enc === '') return null;
		return self::openPassword($camp->account_password_enc);
	}

	/** Keep the camp's password (called when accounts are created with it, or when it has just been proven right). */
	public static function keepPassword($db, $camppkey, $password)
	{
		$sealed = self::sealPassword($password);
		if ($sealed === null) return;
		$has = (int)$db->get_var_prepared("select count(*) from information_schema.columns
			where table_name = 'fieldcamp' and column_name = 'account_password_enc'", array());
		if ($has > 0) {
			$db->prepare_query("update fieldcamp set account_password_enc = $2 where pkey = $1", array((int)$camppkey, $sealed));
		}
	}

	/**
	 * Close (or reopen) sign-in for a camp's accounts. Reversible; no data is touched.
	 * Removed accounts stay closed.
	 *
	 * @param array|null $userpkeys only these accounts of the camp, or null for all
	 * @return int how many accounts changed
	 */
	public static function setAccountsActive($db, $camp, $active, $userpkeys = null)
	{
		$changed = 0;
		foreach (self::listAccounts($db, $camp->pkey) as $a) {
			$pk = (int)$a->user_pkey;
			if ($userpkeys !== null && !in_array($pk, array_map('intval', $userpkeys), true)) continue;
			$isActive = self::truthy($a->active);
			if ($isActive === (bool)$active) continue;

			if ($active) {
				$db->prepare_query("update users set active = true where pkey = $1 and deleted = false", array($pk));
				$db->prepare_query("update fieldcamp_account set deactivated_date = null where user_pkey = $1", array($pk));
			} else {
				$db->prepare_query("update users set active = false where pkey = $1", array($pk));
				$db->prepare_query("update fieldcamp_account set deactivated_date = now() where user_pkey = $1", array($pk));
				// A signed-in app keeps working on its token for up to two hours otherwise.
				$db->prepare_query("delete from apptokens where email = $1", array($a->username));
				$db->prepare_query("delete from jwts where user_pkey = $1", array($pk));
			}
			$changed++;
		}
		return $changed;
	}

	/**
	 * Remove a camp's accounts the way a user deleting their own account does:
	 * the account is marked deleted and its projects go private. The data stays
	 * on the server. Only accounts that were deactivated first are removed.
	 *
	 * @return int how many accounts were removed
	 */
	public static function removeAccounts($db, $neodb, $camp)
	{
		$removed = 0;
		foreach (self::listAccounts($db, $camp->pkey) as $a) {
			if (self::truthy($a->active)) continue;   // deactivate first
			if (self::removeAccount($db, $neodb, $camp, (int)$a->user_pkey)) $removed++;
		}
		return $removed;
	}

	/**
	 * Remove one account of a camp, active or not: sign-in is closed and its tokens go (so its device is
	 * signed out), then the account is marked deleted the way a user deleting their own account is. The
	 * data stays on the server and the username stays taken; restoreAccount() undoes it.
	 *
	 * @param string|null $when the removal time to record (deleteCamp passes the camp's), default now
	 * @return bool false when the account is not a live account of this camp
	 */
	public static function removeAccount($db, $neodb, $camp, $userpkey, $when = null)
	{
		$userpkey = (int)$userpkey;
		$account = null;
		foreach (self::listAccounts($db, $camp->pkey) as $a) { if ((int)$a->user_pkey === $userpkey) $account = $a; }
		if (!$account) return false;
		if (!class_exists('StraboSpot', false)) {
			require_once __DIR__ . '/../../db/strabospotclass.php';
		}
		if (self::truthy($account->active)) self::setAccountsActive($db, $camp, false, array($userpkey));
		$strabo = new StraboSpot($neodb, $userpkey, $db);
		$strabo->deleteUserAccount();
		$db->prepare_query("update fieldcamp_account set removed_date = coalesce($2::timestamptz, now()) where user_pkey = $1 and fieldcamp_pkey = $3",
			array($userpkey, $when, (int)$camp->pkey));
		return true;
	}

	/** Removed accounts of a camp (for the admin's restore list on the Accounts step). */
	public static function listRemovedAccounts($db, $camppkey)
	{
		$out = array();
		foreach (self::listAccounts($db, $camppkey, true) as $a) { if ((string)$a->removed_date !== '') $out[] = $a; }
		return $out;
	}

	/**
	 * Bring a removed account back (admin): no longer deleted, still deactivated, so the instructor decides
	 * when it may sign in again. Its data never left the server.
	 */
	public static function restoreAccount($db, $camp, $userpkey)
	{
		$userpkey = (int)$userpkey;
		$row = $db->get_row_prepared("select pkey from fieldcamp_account where fieldcamp_pkey = $1 and user_pkey = $2 and removed_date is not null",
			array((int)$camp->pkey, $userpkey));
		if (!$row) return false;
		$db->prepare_query("update users set deleted = false, active = false where pkey = $1", array($userpkey));
		$db->prepare_query("update fieldcamp_account set removed_date = null, deactivated_date = coalesce(deactivated_date, now()) where pkey = $1",
			array((int)$row->pkey));
		return true;
	}

	// -------------------------------------------------------------------
	// Viewers: existing accounts that may see the camp's student work
	// -------------------------------------------------------------------

	public static function listViewers($db, $camppkey)
	{
		$rows = $db->get_results_prepared("
			select fv.pkey, fv.user_pkey, u.email, u.firstname, u.lastname
			from fieldcamp_viewer fv
			join users u on u.pkey = fv.user_pkey
			where fv.fieldcamp_pkey = $1
			order by u.email
			", array((int)$camppkey));
		return $rows ? $rows : array();
	}

	/** @return string '' on success, else what is wrong */
	public static function addViewer($db, $camp, $email)
	{
		$email = strtolower(trim((string)$email));
		if ($email === '') return "Enter the email address of an existing StraboSpot account.";
		$row = $db->get_row_prepared(
			"select pkey from users where email = $1 and active = true and deleted = false", array($email));
		if (!$row) return "No active StraboSpot account uses the address $email.";
		$pk = (int)$row->pkey;
		if (self::isCampAccount($db, $pk)) return "A field camp account cannot be an instructor.";
		if ($pk === (int)$camp->owner_user_pkey) return "The owner of the camp already has access.";
		$have = (int)$db->get_var_prepared(
			"select count(*) from fieldcamp_viewer where fieldcamp_pkey = $1 and user_pkey = $2",
			array((int)$camp->pkey, $pk));
		if ($have > 0) return "$email is already on the list.";
		$db->prepare_query(
			"insert into fieldcamp_viewer (fieldcamp_pkey, user_pkey) values ($1, $2)",
			array((int)$camp->pkey, $pk));
		return '';
	}

	public static function removeViewer($db, $camp, $viewerpkey)
	{
		$db->prepare_query(
			"delete from fieldcamp_viewer where pkey = $1 and fieldcamp_pkey = $2",
			array((int)$viewerpkey, (int)$camp->pkey));
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/** PostgreSQL booleans arrive as true/false or 't'/'f' depending on the call. */
	public static function truthy($v)
	{
		return $v === true || $v === 't' || $v === 1 || $v === '1';
	}

	private static function isDate($s)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
		return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}

	/** The users.hash value (validation and reset links), same shape as registration's. */
	private static function randomHash()
	{
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$s = '';
		for ($i = 0; $i < 21; $i++) $s .= $chars[random_int(0, 61)];
		return $s;
	}
}
