<?php
/**
 * File: ProjectVersionsController.php
 * Description: GET /versionsdb/projectversions/{projectid} — all version
 *              metadata rows (public.versions table) for one of the
 *              authenticated user's projects.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class ProjectVersionsController extends MyController
{
	public function getAction($request) {

		$projectid = "";
		if(isset($request->url_elements[2])){
			$projectid = $request->url_elements[2];
		}

		return $this->sv->getProjectVersions($projectid);

	}
}
