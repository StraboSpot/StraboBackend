<?php
/**
 * File: sesar_pull.php
 * Description: JSON endpoint behind "Pull from SESAR" (Phase 5: D5). POST,
 *              JSON body {action, ...}; session-authenticated and gated by
 *              SesarAccess::canUse. Bulk runs are driven by the browser one
 *              sample (or IGSN) per call, as minting (D4); SesarPull enforces
 *              every rule again.
 *
 *              Actions:
 *                preview      {sample_id}                     -> {ok, preview}
 *                apply        {sample_id, mode, accept[], seen{}, parent}
 *                                                             -> {ok, result}
 *                create_plan  {igsns: [...]}                  -> {ok, plan}
 *                create       {igsn}                          -> {ok, result}
 *                import_page  {page, search}                  -> {ok, page}
 *                batch_matches {}                             -> {ok, matches}
 *                batch_link   {sample_id, igsn}               -> {ok, result}
 *              (Phase 8 B2: IGSNs from SESAR batch uploads whose Other
 *              Name(s) carry "StraboSpot <sample id>".)
 *              Errors: {ok:false, error, message, fields}
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/includes/sesar/SesarEndpoint.php';
$userpkey = SesarEndpoint::user();
$input = SesarEndpoint::json();

include_once __DIR__ . '/includes/config.inc.php';
include __DIR__ . '/db.php';
include __DIR__ . '/neodb.php';
require_once __DIR__ . '/includes/sesar/SesarPull.php';
SesarEndpoint::gate($userpkey);

$client = new SesarClient();
$pull = new SesarPull($db, $client, new SesarConnection($db, $client), new SesarSampleView($db, $neodb), $neodb);
$str = SesarEndpoint::reader($input);

try {
	switch ($input['action']) {
		case 'preview':
			SesarEndpoint::out(200, array('ok' => true, 'preview' => $pull->preview($userpkey, $str('sample_id'))));
			break;
		case 'apply':
			$res = $pull->apply($userpkey, $str('sample_id'), array(
				'mode'   => $str('mode'),
				'accept' => isset($input['accept']) && is_array($input['accept']) ? array_values(array_filter($input['accept'], 'is_string')) : array(),
				'seen'   => isset($input['seen']) && is_array($input['seen']) ? $input['seen'] : array(),
				'parent' => !empty($input['parent']),
			));
			SesarEndpoint::out(200, array('ok' => true, 'result' => $res));
			break;
		case 'create_plan':
			$igsns = isset($input['igsns']) && is_array($input['igsns']) ? array_values(array_filter($input['igsns'], 'is_scalar')) : array();
			if (empty($igsns)) SesarEndpoint::out(400, array('ok' => false, 'error' => 'validation', 'message' => 'Enter at least one IGSN.'));
			SesarEndpoint::out(200, array('ok' => true, 'plan' => $pull->createPlan($userpkey, $igsns)));
			break;
		case 'create':
			SesarEndpoint::out(200, array('ok' => true, 'result' => $pull->createOne($userpkey, $str('igsn'))));
			break;
		case 'unlink':
			SesarEndpoint::out(200, array('ok' => true, 'result' => $pull->unlink($userpkey, $str('sample_id'))));
			break;
		case 'import_page':
			SesarEndpoint::out(200, array('ok' => true, 'page' => $pull->importPage($userpkey, max(1, (int)$str('page')), $str('search'))));
			break;
		case 'batch_matches':
			SesarEndpoint::out(200, array('ok' => true, 'matches' => $pull->batchMatches($userpkey)));
			break;
		case 'batch_link':
			SesarEndpoint::out(200, array('ok' => true, 'result' => $pull->batchLink($userpkey, $str('sample_id'), $str('igsn'))));
			break;
		default:
			SesarEndpoint::out(400, array('ok' => false, 'error' => 'unknown_action', 'message' => 'Unknown action.'));
	}
} catch (SesarError $e) {
	SesarEndpoint::fail(SesarEndpoint::timeout($e), array(403, 404, 409, 410));   // the sandbox list can hit its 60 s gateway limit
}
