<?php
/**
 * File: SampleController.php
 * Description: /samplesdb/sample/{id}[/sub-resource[/...]] — CRUD on a
 *              single sample plus the §8.2 collaboration sub-resources.
 *
 *              Sub-resource routing dispatches on $request->url_elements[3]:
 *                empty            — CRUD on the sample itself
 *                collaborators    — list (GET) / invite (POST)
 *                collaborator/{p} — change level (PUT) / remove (DELETE)
 *                collaboration/{accept|deny} — accept / deny (POST)
 *
 *              URL conventions (per design §8.1, §8.2):
 *                GET    /samplesdb/sample/{id}                              read sample
 *                GET    /samplesdb/sample/{id}?owner={pkey}                 read collab-on sample
 *                POST   /samplesdb/sample                                   create (501 stub)
 *                PUT    /samplesdb/sample/{id}                              update (501 stub)
 *                DELETE /samplesdb/sample/{id}                              delete (501 stub)
 *                GET    /samplesdb/sample/{id}/collaborators
 *                POST   /samplesdb/sample/{id}/collaborators       body: { emails: [...], permission_level: 'edit'|'readonly' }
 *                PUT    /samplesdb/sample/{id}/collaborator/{pkey} body: { permission_level: 'edit'|'readonly' }
 *                DELETE /samplesdb/sample/{id}/collaborator/{pkey}
 *                POST   /samplesdb/sample/{id}/collaboration/accept body: { uuid: '...' }
 *                POST   /samplesdb/sample/{id}/collaboration/deny   body: { uuid: '...' }
 *
 *              Returning 404 for "not found" and "no access" is intentional
 *              (defensive — doesn't leak existence of other users' samples).
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
        $id = $this->extractSampleId($request);
        if ($id === null) {
            return $this->badRequest("Missing sample id.");
        }

        $sub = isset($request->url_elements[3]) ? $request->url_elements[3] : '';
        $ownerPkey = $this->extractOwnerParam($request);

        switch ($sub) {
            case '':
                $sample = $this->svc->getSample($id, $ownerPkey);
                if ($sample === null) {
                    return $this->notFound("Sample not found.");
                }
                return $sample;

            case 'collaborators':
                $effectiveOwner = $ownerPkey !== null ? $ownerPkey : $this->svc->getUserpkey();
                $list = $this->svc->listCollaborators($id, $effectiveOwner);
                if ($list === null) {
                    return $this->notFound("Sample not found.");
                }
                return array('collaborators' => $list, 'count' => count($list));

            default:
                return $this->badRequest($sub . " not supported.");
        }
    }

    public function postAction($request)
    {
        $id = $this->extractSampleId($request);
        $sub = isset($request->url_elements[3]) ? $request->url_elements[3] : '';

        if ($sub === '' && $id === null) {
            // POST /samplesdb/sample — create
            $input = (array)$request->parameters;
            return $this->handleMutationResult($this->svc->createSample($input));
        }

        if ($id === null) {
            return $this->badRequest("Missing sample id.");
        }

        $params = (array)$request->parameters;

        switch ($sub) {
            case 'collaborators':
                $emails = isset($params['emails']) ? (array)$params['emails'] : array();
                $level  = isset($params['permission_level']) ? $params['permission_level'] : null;
                $ownerPkey = $this->extractOwnerParam($request);
                $effectiveOwner = $ownerPkey !== null ? $ownerPkey : $this->svc->getUserpkey();
                $res = $this->svc->inviteCollaborators($id, $effectiveOwner, $emails, $level);
                return $this->handleMutationResult($res);

            case 'collaboration':
                $action = isset($request->url_elements[4]) ? $request->url_elements[4] : '';
                $uuid   = isset($params['uuid']) ? $params['uuid'] : null;
                if ($uuid === null || $uuid === '') {
                    return $this->badRequest("Missing uuid.");
                }
                if ($action === 'accept') {
                    return $this->handleMutationResult($this->svc->acceptInvitation($id, $uuid));
                }
                if ($action === 'deny') {
                    return $this->handleMutationResult($this->svc->denyInvitation($id, $uuid));
                }
                return $this->badRequest("Unknown collaboration action: " . $action);

            default:
                return $this->badRequest($sub . " not supported.");
        }
    }

    public function putAction($request)
    {
        $id = $this->extractSampleId($request);
        if ($id === null) {
            return $this->badRequest("Missing sample id.");
        }
        $sub = isset($request->url_elements[3]) ? $request->url_elements[3] : '';
        $params = (array)$request->parameters;

        switch ($sub) {
            case '':
                // Filter URL-level keys out of the input payload.
                unset($params['owner'], $params['apiformat']);
                $ownerPkey = $this->extractOwnerParam($request);
                $effectiveOwner = $ownerPkey !== null ? $ownerPkey : $this->svc->getUserpkey();
                return $this->handleMutationResult(
                    $this->svc->updateSample($id, $effectiveOwner, $params)
                );

            case 'collaborator':
                $pkey  = isset($request->url_elements[4]) ? (int)$request->url_elements[4] : 0;
                $level = isset($params['permission_level']) ? $params['permission_level'] : null;
                if ($pkey <= 0) {
                    return $this->badRequest("Missing collaborator pkey.");
                }
                $ownerPkey = $this->extractOwnerParam($request);
                $effectiveOwner = $ownerPkey !== null ? $ownerPkey : $this->svc->getUserpkey();
                $res = $this->svc->updateCollaboratorLevel($id, $effectiveOwner, $pkey, $level);
                return $this->handleMutationResult($res);

            default:
                return $this->badRequest($sub . " not supported.");
        }
    }

    public function deleteAction($request)
    {
        $id = $this->extractSampleId($request);
        if ($id === null) {
            return $this->badRequest("Missing sample id.");
        }
        $sub = isset($request->url_elements[3]) ? $request->url_elements[3] : '';

        switch ($sub) {
            case '':
                $ownerPkey = $this->extractOwnerParam($request);
                $effectiveOwner = $ownerPkey !== null ? $ownerPkey : $this->svc->getUserpkey();
                return $this->handleMutationResult(
                    $this->svc->deleteSample($id, $effectiveOwner)
                );

            case 'collaborator':
                $pkey = isset($request->url_elements[4]) ? (int)$request->url_elements[4] : 0;
                if ($pkey <= 0) {
                    return $this->badRequest("Missing collaborator pkey.");
                }
                $ownerPkey = $this->extractOwnerParam($request);
                $effectiveOwner = $ownerPkey !== null ? $ownerPkey : $this->svc->getUserpkey();
                $res = $this->svc->removeCollaborator($id, $effectiveOwner, $pkey);
                return $this->handleMutationResult($res);

            default:
                return $this->badRequest($sub . " not supported.");
        }
    }

    // ---- helpers ----

    protected function extractSampleId($request)
    {
        if (!isset($request->url_elements[2]) || $request->url_elements[2] === '') {
            return null;
        }
        return $request->url_elements[2];
    }

    protected function extractOwnerParam($request)
    {
        return isset($request->parameters['owner'])
            ? (int)$request->parameters['owner']
            : null;
    }

    protected function badRequest($msg)
    {
        header("Bad Request", true, 400);
        return array("Error" => $msg);
    }

    protected function notFound($msg)
    {
        header("Not Found", true, 404);
        return array("Error" => $msg);
    }

    /**
     * Translate a service mutation result into an HTTP response. Service
     * returns ['ok' => bool, 'error' => string|null, ...]. We map the error
     * code → HTTP code so controllers don't repeat the mapping.
     */
    protected function handleMutationResult($res)
    {
        if (!is_array($res)) {
            header("Internal Server Error", true, 500);
            return array("Error" => "Unexpected service result.");
        }
        if (!empty($res['ok'])) {
            return $res;
        }
        $err = isset($res['error']) ? $res['error'] : 'unknown_error';
        switch ($err) {
            case 'not_found':
            case 'grant_not_found':
            case 'invitation_not_found':
            case 'parent_not_accessible':
                header("Not Found", true, 404);
                break;
            case 'forbidden':
                header("Forbidden", true, 403);
                break;
            case 'not_authenticated':
                header("Unauthorized", true, 401);
                break;
            case 'invalid_permission_level':
            case 'no_emails':
            case 'no_writable_fields':
            case 'parent_pair_required':
            case 'already_accepted':
                header("Bad Request", true, 400);
                break;
            case 'duplicate_id':
            case 'duplicate_id_conflict':
            case 'field_link_read_only':
                header("Conflict", true, 409);
                break;
            default:
                header("Bad Request", true, 400);
        }
        return array("Error" => $err);
    }
}
