<?php
/**
 * File: straboversionsclass.php
 * Description: Business logic for the read-only StraboField Versions API.
 *
 * Version snapshots are written by createVersion() in db/strabospotclass.php:
 * metadata rows in the public.versions table (pkey, projectid, datecreated,
 * uuid, userpkey, projectname, spotcount, datasetcount) and the full project
 * JSON gzip-compressed on disk at /srv/app/www/versions/{uuid}. This class
 * only READS both stores; it never creates, activates, or deletes versions.
 *
 * Every method is scoped to the authenticated userpkey. Unknown, foreign,
 * and malformed version uuids are all reported identically (not found) so
 * the API does not leak which uuids exist.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class StraboVersions {

	private $db;
	private $userpkey;
	private $versionsdir = "/srv/app/www/versions";

	public function __construct($db, $userpkey){
		$this->db = $db;
		$this->userpkey = (int)$userpkey;
	}

	//Endpoint 1: distinct list of the user's versioned projects
	public function getVersionedProjects(){

		$rows = $this->db->get_results_prepared(
			"SELECT projectid,
					max(projectname) FILTER (WHERE projectname <> '') AS projectname,
					count(*)::int AS versioncount,
					min(datecreated) AS firstversion,
					max(datecreated) AS lastversion
			 FROM versions
			 WHERE userpkey = $1
			 GROUP BY projectid
			 ORDER BY max(datecreated) DESC",
			array($this->userpkey)
		);

		$projects = array();
		if(count((array)$rows) > 0){
			foreach($rows as $row){
				$projects[] = array(
					'projectid'    => $row->projectid,
					'projectname'  => $row->projectname,
					'versioncount' => (int)$row->versioncount,
					'firstversion' => $row->firstversion,
					'lastversion'  => $row->lastversion
				);
			}
		}

		return array('projects' => $projects);
	}

	//Endpoint 2: all version metadata rows for one of the user's projects
	public function getProjectVersions($projectid){

		$projectid = trim((string)$projectid);
		if($projectid === ""){
			header("Bad Request", true, 400);
			return array('Error' => "No project id supplied. Usage: /versionsdb/projectversions/{projectid}");
		}

		$rows = $this->db->get_results_prepared(
			"SELECT pkey, projectid, datecreated, uuid, userpkey,
					projectname, spotcount, datasetcount
			 FROM versions
			 WHERE projectid = $1 AND userpkey = $2
			 ORDER BY pkey ASC",
			array($projectid, $this->userpkey)
		);

		if(count((array)$rows) == 0){
			header("Not Found", true, 404);
			return array('Error' => "No versions found for project $projectid.");
		}

		$versions = array();
		foreach($rows as $row){
			$versions[] = array(
				'pkey'         => (int)$row->pkey,
				'projectid'    => $row->projectid,
				'datecreated'  => $row->datecreated,
				'uuid'         => $row->uuid,
				'userpkey'     => (int)$row->userpkey,
				'projectname'  => $row->projectname,
				'spotcount'    => ($row->spotcount === null ? null : (int)$row->spotcount),
				'datasetcount' => ($row->datasetcount === null ? null : (int)$row->datasetcount)
			);
		}

		return array('projectid' => $projectid, 'versions' => $versions);
	}

	//Endpoint 3: stream one version's snapshot JSON and exit.
	//Returns an error array (for the JsonView) only when the version
	//cannot be served; on success this method never returns.
	public function outputVersionJson($uuid){

		$uuid = strtolower(trim((string)$uuid));

		//strict format gate BEFORE the uuid goes anywhere near a query or path
		if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)){
			header("Not Found", true, 404);
			return array('Error' => "Version not found.");
		}

		$row = $this->db->get_row_prepared(
			"SELECT uuid FROM versions WHERE uuid = $1 AND userpkey = $2",
			array($uuid, $this->userpkey)
		);

		if($this->db->num_rows == 0){
			header("Not Found", true, 404);
			return array('Error' => "Version not found.");
		}

		//build the path from the DB row, never from raw client input
		$path = $this->versionsdir . "/" . $row->uuid;

		if(!file_exists($path)){
			header("Not Found", true, 404);
			return array('Error' => "Version data not found.");
		}

		//Snapshots are stored gzip-compressed. If the client accepts gzip,
		//pass the stored bytes through untouched; otherwise decode.
		$acceptsGzip = isset($_SERVER['HTTP_ACCEPT_ENCODING'])
			&& stripos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false;

		header('Content-Type: application/json; charset=utf8');

		if($acceptsGzip){
			header('Content-Encoding: gzip');
			header('Content-Length: ' . filesize($path));
			readfile($path);
			exit();
		}

		$json = gzdecode(file_get_contents($path));
		if($json === false){
			header("Internal Server Error", true, 500);
			return array('Error' => "Version data could not be read.");
		}

		header('Content-Length: ' . strlen($json));
		echo $json;
		exit();
	}

}
