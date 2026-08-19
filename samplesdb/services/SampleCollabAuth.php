<?php
/**
 * File: SampleCollabAuth.php
 * Description: Authorization service for StraboSamples collaboration —
 *              sibling to the project-level CollaborationAuth.
 *              Owns the gates spelled out in design §7:
 *                - accepted, non-removed access (§7.1)
 *                - owner-only management (§7.2)
 *                - duplicate-id accept guard (§7.3)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


require_once __DIR__ . '/SampleContext.php';


class SampleCollabAuth
{
    /** @var object PostgreSQL handle */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Build a SampleContext for ($userpkey, $sampleId, $ownerPkey).
     * Resolves the requester's permission level via these rules (§7.1):
     *   owner    — requester == sample owner (and the sample row exists)
     *   edit     — accepted, non-removed grant with permission_level='edit'
     *   readonly — accepted, non-removed grant with permission_level='readonly'
     *   none     — anything else (including pending invites)
     *
     * @return SampleContext
     */
    public function getSampleContext($userpkey, $sampleId, $ownerPkey)
    {
        $userpkey = (int)$userpkey;
        $ownerPkey = (int)$ownerPkey;

        $ctx = new SampleContext();
        $ctx->requestingUser = $userpkey;
        $ctx->sampleId       = $sampleId;
        $ctx->ownerPkey      = $ownerPkey;

        $exists = $this->db->get_var_prepared(
            "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sampleId, $ownerPkey)
        );
        $ctx->exists = $exists ? true : false;

        if (!$ctx->exists) {
            // Defensive: a non-existent sample is 'none' regardless of who's asking.
            return $ctx;
        }

        if ($userpkey === $ownerPkey) {
            $ctx->permissionLevel = 'owner';
            return $ctx;
        }

        $grant = $this->db->get_row_prepared(
            "SELECT pkey, permission_level
               FROM strabosamples.sample_collaborators
              WHERE sample_id=$1 AND sample_userpkey=$2
                AND collaborator_pkey=$3
                AND accepted = TRUE AND removed_at IS NULL
              LIMIT 1",
            array($sampleId, $ownerPkey, $userpkey)
        );

        if ($grant) {
            $ctx->permissionLevel       = $grant->permission_level;
            $ctx->collaborationGrantPkey = (int)$grant->pkey;
        }

        return $ctx;
    }

    /**
     * §7.3 duplicate-id accept guard.
     * Block accepting an invite if the invitee already owns a sample with the
     * same id (it would otherwise collide on (id, userpkey) if the invitee
     * later acquired their own copy).
     */
    public function hasDuplicateIdConflict($inviteeUserpkey, $sampleId)
    {
        $hit = $this->db->get_var_prepared(
            "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sampleId, (int)$inviteeUserpkey)
        );
        return $hit ? true : false;
    }
}
