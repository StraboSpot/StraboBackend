<?php
/**
 * File: VoiceconfirmController.php
 * Description: Voice Stations: the confirm or discard record for one station.
 *              Logic in voicestations/lib/VsService.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once dirname(dirname(__DIR__)) . '/voicestations/lib/bootstrap.php';

class VoiceconfirmController extends MyController
{
	/** POST /db/voiceconfirm/{station_uuid} (JSON body) */
	public function postAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			return voicestations_service($strabo)->confirm(VsHttp::uuidFromUrl($request));
		});
	}
}
