<?php
/**
 * File: VersionController.php
 * Description: GET /versionsdb/version/{uuid} — the full snapshot JSON for
 *              one version. On success the handler streams the payload
 *              directly (gzip passthrough when the client accepts it) and
 *              exits; only error results come back through the JsonView.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class VersionController extends MyController
{
	public function getAction($request) {

		$uuid = "";
		if(isset($request->url_elements[2])){
			$uuid = $request->url_elements[2];
		}

		return $this->sv->outputVersionJson($uuid);

	}
}
