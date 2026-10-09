<?php
/**
 * File: VoiceconsentController.php
 * Description: Voice Stations: the trial consent text and the tester's
 *              agreement to it (step 6). Logic in voicestations/lib/VsService.php.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once dirname(dirname(__DIR__)) . '/voicestations/lib/bootstrap.php';

class VoiceconsentController extends MyController
{
	/** GET /db/voiceconsent: current text + when this account agreed (or null) */
	public function getAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			if (isset($request->url_elements[2]) && trim($request->url_elements[2]) !== '') {
				throw VsHttp::notFound();
			}
			return voicestations_service($strabo)->getConsent();
		});
	}

	/** POST /db/voiceconsent {"version": N, "app_version", "device_model"} */
	public function postAction($request) {
		$strabo = $this->strabo;
		VsHttp::run(function () use ($strabo, $request) {
			if (isset($request->url_elements[2]) && trim($request->url_elements[2]) !== '') {
				throw VsHttp::notFound();
			}
			return voicestations_service($strabo)->acceptConsent();
		});
	}
}
