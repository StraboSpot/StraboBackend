<?php
/**
 * File: SampleContext.php
 * Description: Per-request authorization state for one (sample_id, owner_pkey)
 *              tuple, populated once by SampleCollabAuth::getSampleContext().
 *              Mirrors ProjectContext (the projects-level analog) but
 *              simplified: samples have no halted state, no inherited
 *              collaboration, no dataset-level wrinkles.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class SampleContext
{
    /** @var int The user making the request */
    public $requestingUser;

    /** @var string The sample id */
    public $sampleId;

    /** @var int The sample owner's pkey */
    public $ownerPkey;

    /** @var string Permission level: 'owner', 'edit', 'readonly', 'none' */
    public $permissionLevel = 'none';

    /** @var bool Whether the sample row actually exists */
    public $exists = false;

    /** @var int|null pkey of the active grant when requesting user is a collaborator */
    public $collaborationGrantPkey = null;

    public function isOwner()
    {
        return $this->permissionLevel === 'owner';
    }

    public function canRead()
    {
        return $this->permissionLevel !== 'none' && $this->exists;
    }

    public function canEdit()
    {
        return $this->exists && in_array($this->permissionLevel, array('owner', 'edit'), true);
    }

    /** Owner-only operations: add/remove collaborators, change levels, delete sample. */
    public function canManage()
    {
        return $this->isOwner() && $this->exists;
    }
}
