<?php
/**
 * File: VoiceaudioController.php
 * Description: Voice Stations: stream a station's original audio to its owner.
 *              Logic in voicestations/lib/VsService.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once dirname(dirname(__DIR__)) . '/voicestations/lib/bootstrap.php';

class VoiceaudioController extends MyController
{
	/** GET /db/voiceaudio/{station_uuid} */
	public function getAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			voicestations_service($strabo)->streamAudio(VsHttp::uuidFromUrl($request));
		});
	}
}
