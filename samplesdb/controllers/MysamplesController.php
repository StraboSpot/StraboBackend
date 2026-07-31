<?php
/**
 * File: MysamplesController.php
 * Description: GET /samplesdb/mysamples — list the authenticated user's
 *              samples (own + accepted, non-removed collaborator-on).
 *              Spine columns only; full per-sample detail is on
 *              /samplesdb/sample/{id}.
 *
 *              Pagination, type/purpose/subsystem/search/sort filters are
 *              deferred per design §16 item 3. The baseline shape is
 *              `{ "samples": [...], "count": N }` so the wrapper has a
 *              place to grow into without breaking clients.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class MysamplesController extends MyController
{
    public function getAction($request)
    {
        $samples = $this->svc->listMySamples();
        return array(
            'samples' => $samples,
            'count'   => count($samples),
        );
    }
}
