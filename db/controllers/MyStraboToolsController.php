<?php
/**
 * File: MyStraboToolsController.php
 * Description: MyStraboToolsController class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class MyStraboToolsController extends MyController
{
	/**
	 * List the authenticated user's StraboTools analyses
	 *
	 * GET /db/mystrabotools -> { "analyses": [ ... ] }, newest first.
	 */
	public function getAction($request) {

		return $this->strabo->getMyToolsAnalyses();

	}

}
