<?php
/**
 * File: VoicestationController.php
 * Description: Voice Stations: upload one station (details JSON + audio).
 *              Logic in voicestations/lib/VsService.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once dirname(dirname(__DIR__)) . '/voicestations/lib/bootstrap.php';

class VoicestationController extends MyController
{
	/** POST /db/voicestation (multipart details + audio) */
	public function postAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			return voicestations_service($strabo)->upload();
		});
	}
}
