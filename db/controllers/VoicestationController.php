<?php
/**
 * File: VoicestationController.php
 * Description: Voice Stations: upload one station (details JSON + audio),
 *              and the app's access check at login.
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
	/** GET /db/voicestation: may this account use Voice Stations? (403 not_available if not) */
	public function getAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			if (isset($request->url_elements[2]) && trim($request->url_elements[2]) !== '') {
				throw VsHttp::notFound();
			}
			return voicestations_service($strabo)->access();
		});
	}

	/** POST /db/voicestation (multipart details + audio) */
	public function postAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			return voicestations_service($strabo)->upload();
		});
	}
}
