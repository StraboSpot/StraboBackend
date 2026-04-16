<?php
/**
 * CollaborationAuth - Centralized authorization for collaboration
 *
 * This is the single source of truth for all collaboration permission checks.
 * All controllers should use this service rather than implementing their own
 * authorization logic.
 *
 * IMPORTANT: Project IDs are NOT unique. Always use (project_id, owner_pkey).
 */

require_once(__DIR__ . '/ProjectContext.php');

class CollaborationAuth {

    private $db;      // PostgreSQL connection
    private $neodb;   // Neo4j connection

    public function __construct($db, $neodb) {
        $this->db = $db;
        $this->neodb = $neodb;
    }

    /**
     * Get complete authorization context for a project
     *
     * @param int $userpkey - The requesting user's pkey
     * @param string $projectId - The project ID
     * @return ProjectContext
     */
    public function getProjectContext(int $userpkey, string $projectId): ProjectContext {
        $context = new ProjectContext();
        $context->requestingUser = $userpkey;
        $context->projectId = $projectId;

        // Check if user owns a project with this ID
        // NOTE: Project IDs are not unique - must check userpkey too
        $ownerCheck = $this->neodb->get_var(
            "MATCH (p:Project) WHERE p.id = $projectId AND p.userpkey = $userpkey RETURN p.userpkey"
        );

        if ($ownerCheck) {
            // User is the owner
            $context->effectiveOwner = $userpkey;
            $context->permissionLevel = 'owner';
            $context->hasCollaborators = $this->hasAnyCollaborators($projectId, $userpkey);
            $context->isHalted = $context->hasCollaborators && $this->isCollaborationHalted($projectId, $userpkey);
            return $context;
        }

        // Check if user is a collaborator on someone else's project
        // Include disabled (halted) collaborators - they still have readonly access
        $collab = $this->db->get_row_prepared(
            "SELECT * FROM collaborators
             WHERE strabo_project_id = $1
             AND collaborator_user_pkey = $2
             AND accepted = true",
            array($projectId, $userpkey)
        );

        if ($collab) {
            $context->effectiveOwner = (int)$collab->project_owner_user_pkey;
            $context->collaborationId = (int)$collab->pkey;
            $context->hasCollaborators = true;

            // Check if this specific collaborator is disabled (halted)
            $isDisabled = ($collab->disabled === true || $collab->disabled === 't');

            if ($isDisabled) {
                // Halted collaborators have readonly access
                $context->permissionLevel = 'readonly';
                $context->isHalted = true;
            } else {
                $context->permissionLevel = $collab->collaboration_level; // 'edit' or 'readonly'
                $context->isHalted = $this->isCollaborationHalted($projectId, (int)$collab->project_owner_user_pkey);

                // If project collaboration is halted (all collaborators disabled),
                // this collaborator becomes readonly
                if ($context->isHalted && $context->permissionLevel === 'edit') {
                    $context->permissionLevel = 'readonly';
                }
            }

            return $context;
        }

        // No access
        $context->effectiveOwner = null;
        $context->permissionLevel = 'none';
        $context->isHalted = false;
        return $context;
    }

    /**
     * Check if user can edit a specific dataset
     *
     * No collaboration on project: owner can edit any dataset
     * During active collaboration: only creator can edit
     * When halted: only owner can edit
     *
     * @param ProjectContext $context - The project context
     * @param int $datasetCreatedBy - The userpkey of who created the dataset
     * @return bool
     */
    public function canEditDataset(ProjectContext $context, int $datasetCreatedBy): bool {
        if ($context->permissionLevel === 'none') {
            return false;
        }

        if ($context->permissionLevel === 'readonly') {
            return false;
        }

        if ($context->isHalted) {
            // When halted, only owner can edit
            return $context->permissionLevel === 'owner';
        }

        // If no collaboration exists on this project, owner can edit any dataset.
        // Preserves pre-collaboration behavior where a user could upload a shared
        // project file to their own account and edit it freely.
        if (!$context->hasCollaborators) {
            return true;
        }

        // During active collaboration, only creator can edit
        return $datasetCreatedBy === $context->requestingUser;
    }

    /**
     * Check if user can edit project metadata (name, description, preferences)
     *
     * @param ProjectContext $context
     * @return bool
     */
    public function canEditProjectMetadata(ProjectContext $context): bool {
        return $context->permissionLevel === 'owner';
    }

    /**
     * Check if user can edit project combinable data (tags, reports)
     *
     * @param ProjectContext $context
     * @return bool
     */
    public function canEditProjectCombinableData(ProjectContext $context): bool {
        if ($context->isHalted) {
            return $context->permissionLevel === 'owner';
        }
        return in_array($context->permissionLevel, ['owner', 'edit']);
    }

    /**
     * Check if user can create new datasets in project
     *
     * @param ProjectContext $context
     * @return bool
     */
    public function canCreateDataset(ProjectContext $context): bool {
        if ($context->isHalted) {
            return $context->permissionLevel === 'owner';
        }
        return in_array($context->permissionLevel, ['owner', 'edit']);
    }

    /**
     * Check if user can upload images
     *
     * @param ProjectContext $context
     * @return bool
     */
    public function canUploadImage(ProjectContext $context): bool {
        return $this->canCreateDataset($context);
    }

    /**
     * Check if user can read project data
     *
     * @param ProjectContext $context
     * @return bool
     */
    public function canRead(ProjectContext $context): bool {
        return $context->permissionLevel !== 'none';
    }

    /**
     * Check if any collaborator rows exist for a project (accepted or not, enabled or not)
     *
     * @param string $projectId
     * @param int $ownerPkey
     * @return bool
     */
    private function hasAnyCollaborators(string $projectId, int $ownerPkey): bool {
        $count = $this->db->get_var_prepared(
            "SELECT COUNT(*) FROM collaborators
             WHERE strabo_project_id = $1 AND project_owner_user_pkey = $2",
            array($projectId, $ownerPkey)
        );
        return $count > 0;
    }

    /**
     * Check if collaboration is halted for a project
     *
     * Collaboration is considered "halted" when:
     * - Collaborators exist (have been invited at some point)
     * - But all collaborators are currently disabled
     *
     * @param string $projectId
     * @param int $ownerPkey
     * @return bool
     */
    private function isCollaborationHalted(string $projectId, int $ownerPkey): bool {
        if (!$this->hasAnyCollaborators($projectId, $ownerPkey)) {
            return false;
        }

        // Check if all are disabled
        $enabledCount = $this->db->get_var_prepared(
            "SELECT COUNT(*) FROM collaborators
             WHERE strabo_project_id = $1
             AND project_owner_user_pkey = $2
             AND disabled = false",
            array($projectId, $ownerPkey)
        );

        return $enabledCount == 0;
    }

    // =========================================================================
    // DUPLICATE PREVENTION VALIDATION
    // =========================================================================

    /**
     * Check if a user can be invited to collaborate on a project
     *
     * Prevents collisions where invitee already has a project with the same ID.
     * Must check both active projects (Neo4j) and restorable versions (PostgreSQL).
     *
     * @param string $projectId - The project ID to collaborate on
     * @param int $inviteeUserpkey - The user being invited
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    public function canInviteUser(string $projectId, int $inviteeUserpkey): array {
        // Check Neo4j for active project
        $hasActiveProject = $this->neodb->get_var(
            "MATCH (p:Project) WHERE p.id = $projectId AND p.userpkey = $inviteeUserpkey RETURN count(p)"
        );

        if ($hasActiveProject > 0) {
            return [
                'allowed' => false,
                'reason' => "Cannot invite user: they already own a project with ID $projectId"
            ];
        }

        // Check versions table for restorable project
        $hasVersionedProject = $this->db->get_var_prepared(
            "SELECT COUNT(*) FROM vprojects WHERE projectid = $1 AND userpkey = $2",
            array($projectId, $inviteeUserpkey)
        );

        if ($hasVersionedProject > 0) {
            return [
                'allowed' => false,
                'reason' => "Cannot invite user: they have a restorable version of project $projectId"
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Check if a user can accept an invitation to collaborate
     *
     * Re-validates at acceptance time because state may have changed since invitation.
     * User may have created their own project with the same ID after being invited.
     *
     * @param string $projectId - The project ID to collaborate on
     * @param int $acceptingUserpkey - The user accepting the invitation
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    public function canAcceptInvitation(string $projectId, int $acceptingUserpkey): array {
        // Same checks as invitation time - state may have changed
        return $this->canInviteUser($projectId, $acceptingUserpkey);
    }

    /**
     * Check if a user can upload/create a project with this ID
     *
     * Prevents former collaborators from uploading their own version of a
     * project they collaborated on. Once you've been a collaborator on a
     * project ID (even if halted), you cannot create your own project with
     * that same ID.
     *
     * @param string $projectId - The project ID being uploaded
     * @param int $userpkey - The user uploading
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    public function canUploadProjectAsOwner(string $projectId, int $userpkey): array {
        // Check if user has EVER been a collaborator on this project ID
        // This includes: active, halted, disabled, accepted, or not accepted
        $wasCollaborator = $this->db->get_var_prepared(
            "SELECT COUNT(*) FROM collaborators
             WHERE strabo_project_id = $1
             AND collaborator_user_pkey = $2",
            array($projectId, $userpkey)
        );

        if ($wasCollaborator > 0) {
            return [
                'allowed' => false,
                'reason' => "Cannot upload project: you have collaborated on a project with ID $projectId"
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Check if a user can restore a versioned project
     *
     * Prevents restoring a project if user is already collaborating on a project
     * with the same ID (would create a collision).
     *
     * @param string $projectId - The project ID being restored
     * @param int $userpkey - The user restoring
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    public function canRestoreVersion(string $projectId, int $userpkey): array {
        // Check if user is collaborating on any project with this ID
        $isCollaborating = $this->db->get_var_prepared(
            "SELECT COUNT(*) FROM collaborators
             WHERE strabo_project_id = $1
             AND collaborator_user_pkey = $2
             AND accepted = true
             AND disabled = false",
            array($projectId, $userpkey)
        );

        if ($isCollaborating > 0) {
            return [
                'allowed' => false,
                'reason' => "Cannot restore project: you are collaborating on a project with ID $projectId"
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    // =========================================================================
    // DATASET CONTEXT LOOKUPS
    // =========================================================================

    /**
     * Get authorization context for a dataset
     *
     * Finds the project containing the dataset and returns the project context.
     * This is needed for dataset-based operations where we don't have project ID upfront.
     *
     * @param int $userpkey - The requesting user's pkey
     * @param string $datasetId - The dataset ID
     * @return ProjectContext|null - Returns null if dataset not found or not linked to a project
     */
    public function getDatasetContext(int $userpkey, string $datasetId): ?ProjectContext {
        // Project IDs are not unique across users - multiple users may have
        // datasets with the same ID. Prefer the requesting user's own copy first,
        // then fall back to any project containing the dataset (for collaborator lookups).
        $records = $this->neodb->query(
            "MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset {id:$datasetId})
             WHERE p.userpkey = $userpkey
             RETURN p.id as projectId, p.userpkey as ownerPkey, d.created_by as createdBy"
        );

        if (count($records) === 0) {
            $records = $this->neodb->query(
                "MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset {id:$datasetId})
                 RETURN p.id as projectId, p.userpkey as ownerPkey, d.created_by as createdBy"
            );
        }

        if (count($records) === 0) {
            // Dataset not found or not linked to a project
            return null;
        }

        $result = $records[0];
        $projectId = $result->get('projectId');
        $ownerPkey = (int)$result->get('ownerPkey');

        // Now get the full project context
        $context = $this->getProjectContext($userpkey, $projectId);

        // Add dataset-specific info
        $context->datasetId = $datasetId;
        $context->datasetCreatedBy = $result->get('createdBy') ? (int)$result->get('createdBy') : $ownerPkey;

        return $context;
    }

    /**
     * Get the project ID and owner for a dataset (no auth check, just lookup)
     *
     * @param string $datasetId - The dataset ID
     * @return array|null - ['projectId' => string, 'ownerPkey' => int] or null if not found
     */
    public function getDatasetOwnerInfo(string $datasetId): ?array {
        $records = $this->neodb->query(
            "MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset {id:$datasetId})
             RETURN p.id as projectId, p.userpkey as ownerPkey"
        );

        if (count($records) === 0) {
            return null;
        }

        $result = $records[0];
        return [
            'projectId' => $result->get('projectId'),
            'ownerPkey' => (int)$result->get('ownerPkey')
        ];
    }
}
