<?php
/**
 * File: VoicebatchController.php
 * Description: Voice Stations: batch progress, and every station's results once done.
 *              Logic in voicestations/lib/VsService.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once dirname(dirname(__DIR__)) . '/voicestations/lib/bootstrap.php';

class VoicebatchController extends MyController
{
	/** GET /db/voicebatch/{batch_uuid} */
	public function getAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			return voicestations_service($strabo)->batch(VsHttp::uuidFromUrl($request));
		});
	}
}
