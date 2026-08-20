<?php
/**
 * File: MyProjectsController.php
 * Description: GET /versionsdb/myprojects — distinct list of the
 *              authenticated user's versioned StraboField projects.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class MyProjectsController extends MyController
{
	public function getAction($request) {

		return $this->sv->getVersionedProjects();

	}
}
