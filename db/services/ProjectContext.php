<?php
/**
 * ProjectContext - Holds authorization state for a request
 *
 * This class contains all the information needed to make authorization
 * decisions for a specific project/user combination. It is populated
 * once per request by CollaborationAuth::getProjectContext().
 */
class ProjectContext {

    /** @var int The user making the request */
    public int $requestingUser;

    /** @var int|null The project owner's pkey (use for all data queries) */
    public ?int $effectiveOwner = null;

    /** @var string The project ID */
    public string $projectId;

    /** @var string Permission level: 'owner', 'edit', 'readonly', 'none' */
    public string $permissionLevel = 'none';

    /** @var bool Whether collaboration is halted */
    public bool $isHalted = false;

    /** @var int|null The collaboration record pkey (if collaborator) */
    public ?int $collaborationId = null;

    /**
     * Quick check: is user the owner?
     */
    public function isOwner(): bool {
        return $this->permissionLevel === 'owner';
    }

    /**
     * Quick check: can user read data?
     */
    public function canRead(): bool {
        return $this->permissionLevel !== 'none';
    }

    /**
     * Quick check: can user write/edit data?
     * Note: For datasets, use CollaborationAuth::canEditDataset() which
     * also checks if the user created the dataset.
     */
    public function canWrite(): bool {
        return in_array($this->permissionLevel, ['owner', 'edit']);
    }

    /**
     * Get permission info for API response
     */
    public function toApiResponse(): array {
        return [
            'isOwner' => $this->isOwner(),
            'isReadOnly' => !in_array($this->permissionLevel, ['owner', 'edit']) || $this->isHalted,
            'isCollaborative' => $this->collaborationId !== null || $this->isHalted,
        ];
    }
}
