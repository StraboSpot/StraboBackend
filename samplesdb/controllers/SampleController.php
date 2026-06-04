<?php
/**
 * File: SampleController.php
 * Description: /samplesdb/sample/{id} — CRUD on a single sample.
 *
 *              GET    — implemented (read full sample, owner or accepted
 *                       collaborator only). 404 for both "not found" and
 *                       "no access" — defensive, doesn't leak existence
 *                       of samples belonging to other users.
 *              POST   — stub (501); direct creation lands in a later
 *                       sub-branch alongside the §10.4 conflict rules.
 *              PUT    — stub (501); spine + JSONB update lands with the
 *                       Field writeback wiring (§9.1).
 *              DELETE — stub (501); owner-only delete with §7 cascade
 *                       semantics lands in samples/api-collab.
 *
 *              URL shapes (per design §8.1):
 *                GET  /samplesdb/sample/{id}
 *                GET  /samplesdb/sample/{id}?owner={owner_pkey}
 *
 *              For collaborator-on access, `?owner` specifies the sample
 *              owner's pkey; absent, the caller is assumed to be the
 *              owner.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class SampleController extends MyController
{
    public function getAction($request)
    {
        $id = isset($request->url_elements[2]) ? $request->url_elements[2] : null;
        if ($id === null || $id === '') {
            header("Bad Request", true, 400);
            return array("Error" => "Missing sample id.");
        }

        $ownerPkey = isset($request->parameters['owner'])
            ? (int)$request->parameters['owner']
            : null;

        $sample = $this->svc->getSample($id, $ownerPkey);
        if ($sample === null) {
            header("Not Found", true, 404);
            return array("Error" => "Sample not found.");
        }
        return $sample;
    }

    public function postAction($request)
    {
        header("Not Implemented", true, 501);
        return array("Error" => "POST /samplesdb/sample not yet implemented.");
    }

    public function putAction($request)
    {
        header("Not Implemented", true, 501);
        return array("Error" => "PUT /samplesdb/sample/{id} not yet implemented.");
    }

    public function deleteAction($request)
    {
        header("Not Implemented", true, 501);
        return array("Error" => "DELETE /samplesdb/sample/{id} not yet implemented.");
    }
}
