<?php
/**
 * File: MsDb.php
 * Description: Thin exception-throwing layer over the shared pg connection
 *              for the /microsync/v1/ API.
 *
 *              Why not the $db wrapper directly: prepare_query() creates a
 *              new named prepared statement per call (thousands in one big
 *              push), query() chases INSERTs with lastval() (aborts
 *              transactions on no-serial tables), and last_error is sticky.
 *              Here every statement is an unnamed pg_query_params call and
 *              any error throws, so a failed push always rolls back.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class MsDbError extends Exception {}

class MsDb {

	private $conn;
	private $depth = 0;

	public function __construct($strabodb) {
		// The wrapper connects lazily on its first call.
		if (!isset($strabodb->dbh) || !$strabodb->dbh) {
			$strabodb->get_var('SELECT 1');
		}
		$this->conn = $strabodb->dbh;
		if (!$this->conn) {
			throw new MsDbError('No database connection');
		}
	}

	public function q($sql, $params = array()) {
		$res = @pg_query_params($this->conn, $sql, $params);
		if ($res === false) {
			throw new MsDbError(pg_last_error($this->conn));
		}
		return $res;
	}

	/** All rows as associative arrays (values are strings or null). */
	public function rows($sql, $params = array()) {
		$res = $this->q($sql, $params);
		$out = pg_fetch_all($res);
		pg_free_result($res);
		return $out === false ? array() : $out;
	}

	public function row($sql, $params = array()) {
		$res = $this->q($sql, $params);
		$out = pg_fetch_assoc($res);
		pg_free_result($res);
		return $out === false ? null : $out;
	}

	public function val($sql, $params = array()) {
		$row = $this->row($sql, $params);
		return $row === null ? null : reset($row);
	}

	public function begin() {
		$this->q('BEGIN');
		$this->depth = 1;
	}

	/** Read-only transaction that sees one consistent snapshot. */
	public function beginSnapshot() {
		$this->q('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
		$this->depth = 1;
	}

	public function commit() {
		$this->q('COMMIT');
		$this->depth = 0;
	}

	public function rollback() {
		if ($this->depth > 0) {
			@pg_query($this->conn, 'ROLLBACK');
			$this->depth = 0;
		}
	}

	public function inTransaction() {
		return $this->depth > 0;
	}

	public function savepoint($name) {
		$this->q("SAVEPOINT $name");
	}

	public function rollbackTo($name) {
		$this->q("ROLLBACK TO SAVEPOINT $name");
	}

	public function release($name) {
		$this->q("RELEASE SAVEPOINT $name");
	}

	/** Postgres boolean text ('t'/'f') to PHP bool. */
	public static function bool($v) {
		return $v === 't' || $v === true;
	}

	/**
	 * SQL expression rendering a timestamptz column as ISO 8601 UTC with
	 * milliseconds (Firefox rejects Postgres' own text format).
	 */
	public static function iso($col) {
		return "to_char($col AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"')";
	}
}
