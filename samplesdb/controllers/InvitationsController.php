<?php
/**
 * File: InvitationsController.php
 * Description: GET /samplesdb/invitations — list the caller's pending
 *              sample invitations (accepted=false, removed_at IS NULL),
 *              joined with the sample row for owner/name display.
 *
 *              Per design §7.3: pending invites confer no access; this
 *              endpoint is how an invitee finds the uuid to accept or
 *              deny via /samplesdb/sample/{id}/collaboration/{accept|deny}.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class InvitationsController extends MyController
{
    public function getAction($request)
    {
        $invites = $this->svc->listMyInvitations();
        return array(
            'invitations' => $invites,
            'count'       => count($invites),
        );
    }
}
