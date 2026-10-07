<?php
/**
 * File: VoiceretryController.php
 * Description: Voice Stations: send a failed station back to the stage that failed.
 *              Logic in voicestations/lib/VsService.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once dirname(dirname(__DIR__)) . '/voicestations/lib/bootstrap.php';

class VoiceretryController extends MyController
{
	/** POST /db/voiceretry/{station_uuid} */
	public function postAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			return voicestations_service($strabo)->retry(VsHttp::uuidFromUrl($request));
		});
	}
}
