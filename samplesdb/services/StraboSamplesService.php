<?php
/**
 * File: StraboSamplesService.php
 * Description: StraboSamples business-logic service. Shared by /samplesdb/
 *              (Basic auth) and /samplesjwtdb/ (JWT auth), and — in later
 *              phases — by /db/, /microdb/, /expdb/ via direct method
 *              calls (no HTTP round-trip, per design §8.6).
 *
 *              Constructor takes BOTH database handles. The PostgreSQL
 *              handle drives all strabosamples.* reads/writes. The Neo4j
 *              handle is reserved for the §9.1 Field writeback path
 *              (a spine edit on a Field-linked sample pushes back into
 *              the linked Spot's samples[]). For Phase 2 api-core's
 *              read-only endpoints, $neodb is held but unused.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


require_once __DIR__ . '/SampleCollabAuth.php';
require_once __DIR__ . '/../../includes/UUID.php';


class StraboSamplesService
{
    protected $db;
    protected $neodb;
    protected $userpkey;
    protected $uuid;

    /** @var SampleCollabAuth */
    protected $auth;

    public function __construct($db, $neodb)
    {
        $this->db = $db;
        $this->neodb = $neodb;
        $this->auth = new SampleCollabAuth($db);
    }

    public function setUserpkey($pkey)
    {
        $this->userpkey = (int)$pkey;
    }

    public function getUserpkey()
    {
        return $this->userpkey;
    }

    public function setUuid($uuid)
    {
        $this->uuid = $uuid;
    }

    /**
     * Returns the SampleContext for ($sampleId, $ownerPkey) from the caller's
     * perspective. Resolves permission_level via SampleCollabAuth. Per §7.1.
     */
    public function getContext($sampleId, $ownerPkey)
    {
        return $this->auth->getSampleContext($this->userpkey, $sampleId, $ownerPkey);
    }

    /** Read access: owner OR accepted, non-removed grant (edit/readonly). */
    public function canRead($id, $ownerPkey)
    {
        return $this->auth->getSampleContext($this->userpkey, $id, $ownerPkey)->canRead();
    }

    /** Edit access: owner OR accepted edit grant. */
    public function canEdit($id, $ownerPkey)
    {
        return $this->auth->getSampleContext($this->userpkey, $id, $ownerPkey)->canEdit();
    }

    /** Manage access: owner only. */
    public function canManage($id, $ownerPkey)
    {
        return $this->auth->getSampleContext($this->userpkey, $id, $ownerPkey)->canManage();
    }

    /**
     * List samples visible to the authenticated user: own + accepted,
     * non-removed collaborator grants. Lightweight (spine columns only).
     * §16 item 3 (pagination defaults) is deferred to a later sub-branch.
     *
     * @param array $filters Reserved for type/purpose/subsystem/search/sort
     *                       filters per design §8.1. Ignored in api-core
     *                       baseline; the unfiltered list is enough to
     *                       wire up and demo.
     * @return array of associative arrays, one per sample
     */
    public function listMySamples($filters = array())
    {
        $rows = $this->db->get_results_prepared(
            "SELECT s.id, s.userpkey, s.name, s.igsn, s.description, s.notes,
                    s.latitude, s.longitude,
                    s.display_sample_type, s.display_sample_purpose,
                    s.parent_sample_id, s.parent_userpkey,
                    s.created_at, s.created_by, s.modified_at, s.modified_by
               FROM strabosamples.samples s
              WHERE s.userpkey = $1
                 OR EXISTS (
                      SELECT 1 FROM strabosamples.sample_collaborators c
                       WHERE c.sample_id = s.id
                         AND c.sample_userpkey = s.userpkey
                         AND c.collaborator_pkey = $1
                         AND c.accepted = TRUE
                         AND c.removed_at IS NULL
                    )
              ORDER BY s.modified_at DESC",
            array($this->userpkey)
        );

        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = $this->normalizeSpineRow($r);
            }
        }
        return $out;
    }

    /**
     * Get one full sample: spine + per-subsystem JSONB + child tables
     * (composition/parameters/documents) + cross-system links + collaborators
     * + parent + children. Returns null if the sample doesn't exist or the
     * caller has no read access.
     *
     * Wire-format is the api-core baseline; §16 item 1 (exact response
     * shape) iterates over the next few sub-branches.
     *
     * @param string $id        The sample id.
     * @param int|null $ownerPkey Owner pkey when accessing a collaborator-on
     *                            sample. Defaults to the caller.
     * @return array|null
     */
    public function getSample($id, $ownerPkey = null)
    {
        if ($ownerPkey === null) {
            $ownerPkey = $this->userpkey;
        }
        $ownerPkey = (int)$ownerPkey;

        if (!$this->canRead($id, $ownerPkey)) {
            return null;
        }

        $row = $this->db->get_row_prepared(
            "SELECT s.id, s.userpkey, s.name, s.igsn, s.description, s.notes,
                    s.latitude, s.longitude,
                    s.display_sample_type, s.display_sample_purpose,
                    s.parent_sample_id, s.parent_userpkey,
                    s.field_data, s.micro_data, s.experimental_data,
                    s.created_at, s.created_by, s.modified_at, s.modified_by
               FROM strabosamples.samples s
              WHERE s.id = $1 AND s.userpkey = $2",
            array($id, $ownerPkey)
        );
        if (!$row) {
            return null;
        }

        $sample = $this->normalizeSpineRow($row);

        // Per-subsystem JSONB
        $sample['field_data']        = $this->decodeJson($row->field_data);
        $sample['micro_data']        = $this->decodeJson($row->micro_data);
        $sample['experimental_data'] = $this->decodeJson($row->experimental_data);

        // Child tables
        $sample['composition']      = $this->fetchComposition($id, $ownerPkey);
        $sample['parameters']       = $this->fetchParameters($id, $ownerPkey);
        $sample['documents']        = $this->fetchDocuments($id, $ownerPkey);

        // Cross-system + collab + family
        $sample['subsystem_links']  = $this->fetchSubsystemLinks($id, $ownerPkey);
        $sample['collaborators']    = $this->fetchCollaborators($id, $ownerPkey);
        $sample['parent']           = $this->fetchParent($row->parent_sample_id, $row->parent_userpkey);
        $sample['children']         = $this->fetchChildren($id, $ownerPkey);

        return $sample;
    }

    // ---- internal helpers ----

    protected function normalizeSpineRow($row)
    {
        if (!$row) {
            return null;
        }
        return array(
            'id'                     => $row->id,
            'userpkey'               => (int)$row->userpkey,
            'name'                   => $row->name,
            'igsn'                   => $row->igsn,
            'description'            => $row->description,
            'notes'                  => $row->notes,
            'latitude'               => $row->latitude === null ? null : (float)$row->latitude,
            'longitude'              => $row->longitude === null ? null : (float)$row->longitude,
            'display_sample_type'    => $row->display_sample_type,
            'display_sample_purpose' => $row->display_sample_purpose,
            'parent_sample_id'       => $row->parent_sample_id,
            'parent_userpkey'        => $row->parent_userpkey === null ? null : (int)$row->parent_userpkey,
            'created_at'             => $row->created_at,
            'created_by'             => (int)$row->created_by,
            'modified_at'            => $row->modified_at,
            'modified_by'            => (int)$row->modified_by,
        );
    }

    protected function decodeJson($val)
    {
        if ($val === null || $val === '') {
            return null;
        }
        $decoded = json_decode($val, true);
        return $decoded === null ? null : $decoded;
    }

    protected function fetchComposition($id, $ownerPkey)
    {
        $rows = $this->db->get_results_prepared(
            "SELECT mineral, other_mineral, fraction, unit, grainsize, ordering
               FROM strabosamples.sample_composition
              WHERE sample_id=$1 AND sample_userpkey=$2
              ORDER BY ordering, pkey",
            array($id, $ownerPkey)
        );
        return $this->objectsToArrays($rows);
    }

    protected function fetchParameters($id, $ownerPkey)
    {
        $rows = $this->db->get_results_prepared(
            "SELECT control, other_control, value, unit, prefix, note, ordering
               FROM strabosamples.sample_parameters
              WHERE sample_id=$1 AND sample_userpkey=$2
              ORDER BY ordering, pkey",
            array($id, $ownerPkey)
        );
        return $this->objectsToArrays($rows);
    }

    protected function fetchDocuments($id, $ownerPkey)
    {
        $rows = $this->db->get_results_prepared(
            "SELECT uuid, type, other_type, format, other_format, path,
                    document_id, original_filename, description, ordering
               FROM strabosamples.sample_documents
              WHERE sample_id=$1 AND sample_userpkey=$2
              ORDER BY ordering, pkey",
            array($id, $ownerPkey)
        );
        return $this->objectsToArrays($rows);
    }

    protected function fetchSubsystemLinks($id, $ownerPkey)
    {
        $rows = $this->db->get_results_prepared(
            "SELECT subsystem, reference_id, reference_userpkey, reference_metadata,
                    created_at, modified_at
               FROM strabosamples.sample_subsystem_links
              WHERE sample_id=$1 AND sample_userpkey=$2
              ORDER BY subsystem, reference_id",
            array($id, $ownerPkey)
        );
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = array(
                    'subsystem'          => $r->subsystem,
                    'reference_id'       => $r->reference_id,
                    'reference_userpkey' => (int)$r->reference_userpkey,
                    'reference_metadata' => $this->decodeJson($r->reference_metadata),
                    'created_at'         => $r->created_at,
                    'modified_at'        => $r->modified_at,
                );
            }
        }
        return $out;
    }

    protected function fetchCollaborators($id, $ownerPkey)
    {
        // Owner sees all (accepted + pending). Collaborators see accepted only.
        $isOwner = ((int)$ownerPkey === $this->userpkey);
        $sql = "SELECT collaborator_pkey, permission_level, accepted, accepted_at,
                       added_by, added_at, removed_at
                  FROM strabosamples.sample_collaborators
                 WHERE sample_id=$1 AND sample_userpkey=$2
                   AND removed_at IS NULL";
        if (!$isOwner) {
            $sql .= " AND accepted = TRUE";
        }
        $sql .= " ORDER BY added_at";
        $rows = $this->db->get_results_prepared($sql, array($id, $ownerPkey));
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = array(
                    'collaborator_pkey' => (int)$r->collaborator_pkey,
                    'permission_level'  => $r->permission_level,
                    'accepted'          => $this->pgBool($r->accepted),
                    'accepted_at'       => $r->accepted_at,
                    'added_by'          => (int)$r->added_by,
                    'added_at'          => $r->added_at,
                );
            }
        }
        return $out;
    }

    protected function fetchParent($parentId, $parentUserpkey)
    {
        if ($parentId === null || $parentUserpkey === null) {
            return null;
        }
        $row = $this->db->get_row_prepared(
            "SELECT id, userpkey, name, display_sample_type, display_sample_purpose
               FROM strabosamples.samples
              WHERE id=$1 AND userpkey=$2",
            array($parentId, (int)$parentUserpkey)
        );
        if (!$row) {
            return null;
        }
        return array(
            'id'                     => $row->id,
            'userpkey'               => (int)$row->userpkey,
            'name'                   => $row->name,
            'display_sample_type'    => $row->display_sample_type,
            'display_sample_purpose' => $row->display_sample_purpose,
        );
    }

    protected function fetchChildren($id, $ownerPkey)
    {
        $rows = $this->db->get_results_prepared(
            "SELECT id, userpkey, name, display_sample_type, display_sample_purpose
               FROM strabosamples.samples
              WHERE parent_sample_id=$1 AND parent_userpkey=$2
              ORDER BY created_at",
            array($id, $ownerPkey)
        );
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = array(
                    'id'                     => $r->id,
                    'userpkey'               => (int)$r->userpkey,
                    'name'                   => $r->name,
                    'display_sample_type'    => $r->display_sample_type,
                    'display_sample_purpose' => $r->display_sample_purpose,
                );
            }
        }
        return $out;
    }

    protected function objectsToArrays($rows)
    {
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = (array)$r;
            }
        }
        return $out;
    }

    /**
     * Convert a Postgres BOOLEAN value to PHP bool. pg_fetch_object returns
     * boolean columns as the string 't' or 'f', and PHP's `(bool)` cast on
     * a non-empty string is always true — so `(bool)'f'` is `true`. This
     * helper handles both the string and the native-bool forms.
     */
    protected function pgBool($val)
    {
        if (is_bool($val)) return $val;
        if ($val === 't' || $val === 'true' || $val === '1' || $val === 1) return true;
        return false;
    }

    // ============================================================
    // Collaboration (design §7, §8.2)
    // ============================================================

    /**
     * Owner sees all active grants (accepted + pending). Edit/readonly
     * collaborators see accepted grants only. Strangers get null.
     *
     * @return array|null
     */
    public function listCollaborators($sampleId, $ownerPkey)
    {
        $ctx = $this->auth->getSampleContext($this->userpkey, $sampleId, (int)$ownerPkey);
        if (!$ctx->canRead()) {
            return null;
        }

        $sql = "SELECT pkey, collaborator_pkey, permission_level,
                       uuid, accepted, accepted_at,
                       added_by, added_at
                  FROM strabosamples.sample_collaborators
                 WHERE sample_id=$1 AND sample_userpkey=$2
                   AND removed_at IS NULL";
        if (!$ctx->isOwner()) {
            $sql .= " AND accepted = TRUE";
        }
        $sql .= " ORDER BY added_at";

        $rows = $this->db->get_results_prepared($sql, array($sampleId, (int)$ownerPkey));
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $entry = array(
                    'pkey'             => (int)$r->pkey,
                    'collaborator_pkey' => (int)$r->collaborator_pkey,
                    'permission_level' => $r->permission_level,
                    'accepted'         => $this->pgBool($r->accepted),
                    'accepted_at'      => $r->accepted_at,
                    'added_by'         => (int)$r->added_by,
                    'added_at'         => $r->added_at,
                );
                // Owner needs the uuid (to share with the invitee out-of-band);
                // collaborators don't.
                if ($ctx->isOwner()) {
                    $entry['uuid'] = $r->uuid;
                }
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Invite one or more users by email. Owner-only. Per §7.3:
     *   - new email + no prior grant         → insert pending row + fresh uuid
     *   - email with soft-removed grant      → clear removed_at, set level, fresh uuid
     *   - email with active grant            → no-op (returned as 'already_active')
     *   - email is the sample owner          → no-op (returned as 'is_owner')
     *   - email not found / deleted user     → 'unknown'
     *
     * Returns ['ok' => bool, 'error' => ?, 'results' => [{email, status, ...}]].
     */
    public function inviteCollaborators($sampleId, $ownerPkey, array $emails, $permissionLevel)
    {
        $ownerPkey = (int)$ownerPkey;
        $ctx = $this->auth->getSampleContext($this->userpkey, $sampleId, $ownerPkey);
        if (!$ctx->exists) {
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canManage()) {
            return array('ok' => false, 'error' => 'forbidden');
        }
        if (!in_array($permissionLevel, array('edit', 'readonly'), true)) {
            return array('ok' => false, 'error' => 'invalid_permission_level');
        }
        if (empty($emails)) {
            return array('ok' => false, 'error' => 'no_emails');
        }

        $results = array();
        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            if ($email === '') {
                continue;
            }

            // Resolve email → pkey
            $row = $this->db->get_row_prepared(
                "SELECT pkey FROM users WHERE email=$1 AND deleted = FALSE LIMIT 1",
                array($email)
            );
            if (!$row) {
                $results[] = array('email' => $email, 'status' => 'unknown');
                continue;
            }
            $inviteePkey = (int)$row->pkey;

            if ($inviteePkey === $ownerPkey) {
                $results[] = array('email' => $email, 'status' => 'is_owner');
                continue;
            }

            // Check for any existing grant (active or soft-removed)
            $existing = $this->db->get_row_prepared(
                "SELECT pkey, accepted, removed_at, permission_level
                   FROM strabosamples.sample_collaborators
                  WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3
                  ORDER BY pkey DESC LIMIT 1",
                array($sampleId, $ownerPkey, $inviteePkey)
            );

            if ($existing && $existing->removed_at === null) {
                // Active grant — no-op
                $results[] = array(
                    'email'             => $email,
                    'status'            => 'already_active',
                    'collaborator_pkey' => $inviteePkey,
                );
                continue;
            }

            $newUuid = UUID::v4();

            if ($existing && $existing->removed_at !== null) {
                // Re-enable a soft-removed grant. Per §7.3 we clear removed_at,
                // refresh uuid, and reset to pending so the invitee can accept again.
                $this->db->prepare_query(
                    "UPDATE strabosamples.sample_collaborators
                        SET removed_at = NULL,
                            permission_level = $1,
                            uuid = $2,
                            accepted = FALSE,
                            accepted_at = NULL,
                            added_by = $3,
                            added_at = now()
                      WHERE pkey = $4",
                    array($permissionLevel, $newUuid, $this->userpkey, (int)$existing->pkey)
                );
                $results[] = array(
                    'email'             => $email,
                    'status'            => 're_enabled',
                    'collaborator_pkey' => $inviteePkey,
                    'uuid'              => $newUuid,
                );
                continue;
            }

            // Fresh insert
            $this->db->prepare_query(
                "INSERT INTO strabosamples.sample_collaborators
                   (sample_id, sample_userpkey, collaborator_pkey, permission_level,
                    uuid, accepted, added_by)
                 VALUES ($1, $2, $3, $4, $5, FALSE, $6)",
                array($sampleId, $ownerPkey, $inviteePkey, $permissionLevel, $newUuid, $this->userpkey)
            );
            $results[] = array(
                'email'             => $email,
                'status'            => 'invited',
                'collaborator_pkey' => $inviteePkey,
                'uuid'              => $newUuid,
            );
        }

        return array('ok' => true, 'results' => $results);
    }

    /** Change a collaborator's permission level. Owner-only. */
    public function updateCollaboratorLevel($sampleId, $ownerPkey, $collaboratorPkey, $newLevel)
    {
        $ownerPkey = (int)$ownerPkey;
        $collaboratorPkey = (int)$collaboratorPkey;
        $ctx = $this->auth->getSampleContext($this->userpkey, $sampleId, $ownerPkey);
        if (!$ctx->exists) {
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canManage()) {
            return array('ok' => false, 'error' => 'forbidden');
        }
        if (!in_array($newLevel, array('edit', 'readonly'), true)) {
            return array('ok' => false, 'error' => 'invalid_permission_level');
        }

        $exists = $this->db->get_var_prepared(
            "SELECT 1 FROM strabosamples.sample_collaborators
              WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3
                AND removed_at IS NULL LIMIT 1",
            array($sampleId, $ownerPkey, $collaboratorPkey)
        );
        if (!$exists) {
            return array('ok' => false, 'error' => 'grant_not_found');
        }

        $this->db->prepare_query(
            "UPDATE strabosamples.sample_collaborators
                SET permission_level = $1
              WHERE sample_id=$2 AND sample_userpkey=$3 AND collaborator_pkey=$4
                AND removed_at IS NULL",
            array($newLevel, $sampleId, $ownerPkey, $collaboratorPkey)
        );
        return array('ok' => true);
    }

    /** Soft-remove a collaborator grant. Owner-only. */
    public function removeCollaborator($sampleId, $ownerPkey, $collaboratorPkey)
    {
        $ownerPkey = (int)$ownerPkey;
        $collaboratorPkey = (int)$collaboratorPkey;
        $ctx = $this->auth->getSampleContext($this->userpkey, $sampleId, $ownerPkey);
        if (!$ctx->exists) {
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canManage()) {
            return array('ok' => false, 'error' => 'forbidden');
        }

        $exists = $this->db->get_var_prepared(
            "SELECT 1 FROM strabosamples.sample_collaborators
              WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3
                AND removed_at IS NULL LIMIT 1",
            array($sampleId, $ownerPkey, $collaboratorPkey)
        );
        if (!$exists) {
            return array('ok' => false, 'error' => 'grant_not_found');
        }

        $this->db->prepare_query(
            "UPDATE strabosamples.sample_collaborators
                SET removed_at = now()
              WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3
                AND removed_at IS NULL",
            array($sampleId, $ownerPkey, $collaboratorPkey)
        );
        return array('ok' => true);
    }

    /**
     * Caller's pending sample invitations (accepted=false, removed_at IS NULL).
     * Joined with samples for owner/name display.
     */
    public function listMyInvitations()
    {
        $rows = $this->db->get_results_prepared(
            "SELECT c.pkey, c.sample_id, c.sample_userpkey, c.permission_level,
                    c.uuid, c.added_by, c.added_at,
                    s.name AS sample_name, s.display_sample_type
               FROM strabosamples.sample_collaborators c
               JOIN strabosamples.samples s
                 ON s.id = c.sample_id AND s.userpkey = c.sample_userpkey
              WHERE c.collaborator_pkey = $1
                AND c.accepted = FALSE
                AND c.removed_at IS NULL
              ORDER BY c.added_at DESC",
            array($this->userpkey)
        );
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = array(
                    'pkey'                 => (int)$r->pkey,
                    'sample_id'            => $r->sample_id,
                    'sample_userpkey'      => (int)$r->sample_userpkey,
                    'sample_name'          => $r->sample_name,
                    'display_sample_type'  => $r->display_sample_type,
                    'permission_level'     => $r->permission_level,
                    'uuid'                 => $r->uuid,
                    'added_by'             => (int)$r->added_by,
                    'added_at'             => $r->added_at,
                );
            }
        }
        return $out;
    }

    /**
     * Accept a pending invite. Invitee-only (the caller). §7.3 duplicate-id
     * guard: refuse if the invitee already owns a sample with the same id.
     *
     * The owner pkey is derived from the grant row, so callers only need
     * the sample id (URL-level) and the per-invite uuid (out-of-band /
     * from listMyInvitations).
     */
    public function acceptInvitation($sampleId, $uuid)
    {
        $grant = $this->db->get_row_prepared(
            "SELECT pkey, sample_userpkey, accepted
               FROM strabosamples.sample_collaborators
              WHERE sample_id=$1 AND collaborator_pkey=$2
                AND uuid=$3 AND removed_at IS NULL LIMIT 1",
            array($sampleId, $this->userpkey, $uuid)
        );
        if (!$grant) {
            return array('ok' => false, 'error' => 'invitation_not_found');
        }
        if ($this->pgBool($grant->accepted)) {
            return array('ok' => false, 'error' => 'already_accepted');
        }
        if ($this->auth->hasDuplicateIdConflict($this->userpkey, $sampleId)) {
            return array('ok' => false, 'error' => 'duplicate_id_conflict');
        }

        $this->db->prepare_query(
            "UPDATE strabosamples.sample_collaborators
                SET accepted = TRUE, accepted_at = now()
              WHERE pkey = $1",
            array((int)$grant->pkey)
        );
        return array('ok' => true, 'sample_userpkey' => (int)$grant->sample_userpkey);
    }

    /** Deny a pending invite. Invitee-only. Soft-removes the row. */
    public function denyInvitation($sampleId, $uuid)
    {
        $grant = $this->db->get_row_prepared(
            "SELECT pkey, sample_userpkey, accepted
               FROM strabosamples.sample_collaborators
              WHERE sample_id=$1 AND collaborator_pkey=$2
                AND uuid=$3 AND removed_at IS NULL LIMIT 1",
            array($sampleId, $this->userpkey, $uuid)
        );
        if (!$grant) {
            return array('ok' => false, 'error' => 'invitation_not_found');
        }
        if ($this->pgBool($grant->accepted)) {
            return array('ok' => false, 'error' => 'already_accepted');
        }

        $this->db->prepare_query(
            "UPDATE strabosamples.sample_collaborators
                SET removed_at = now()
              WHERE pkey = $1",
            array((int)$grant->pkey)
        );
        return array('ok' => true, 'sample_userpkey' => (int)$grant->sample_userpkey);
    }

    /**
     * §7.3.1 auto-seed. Called by subsystem upload paths (Field/Micro/Exp)
     * when creating a sample from inside a collaborative project — pre-accepts
     * the project's collaborators as sample collaborators at the matching
     * permission level. Skips the owner and already-active grants. No caller
     * in api-collab; landing here so the future integration sub-branches just
     * call this method instead of re-implementing.
     *
     * @param int[] $collaboratorPkeys
     * @param string $permissionLevel 'edit' or 'readonly'
     * @return int number of rows inserted
     */
    public function autoSeedProjectCollaborators($sampleId, $ownerPkey, array $collaboratorPkeys, $permissionLevel)
    {
        $ownerPkey = (int)$ownerPkey;
        if (!in_array($permissionLevel, array('edit', 'readonly'), true)) {
            return 0;
        }
        $inserted = 0;
        foreach ($collaboratorPkeys as $pkey) {
            $pkey = (int)$pkey;
            if ($pkey === $ownerPkey || $pkey <= 0) {
                continue;
            }
            $exists = $this->db->get_var_prepared(
                "SELECT 1 FROM strabosamples.sample_collaborators
                  WHERE sample_id=$1 AND sample_userpkey=$2 AND collaborator_pkey=$3
                    AND removed_at IS NULL LIMIT 1",
                array($sampleId, $ownerPkey, $pkey)
            );
            if ($exists) {
                continue;
            }
            $newUuid = UUID::v4();
            $this->db->prepare_query(
                "INSERT INTO strabosamples.sample_collaborators
                   (sample_id, sample_userpkey, collaborator_pkey, permission_level,
                    uuid, accepted, accepted_at, added_by)
                 VALUES ($1, $2, $3, $4, $5, TRUE, now(), $6)",
                array($sampleId, $ownerPkey, $pkey, $permissionLevel, $newUuid, $this->userpkey)
            );
            $inserted++;
        }
        return $inserted;
    }

    // ============================================================
    // Write operations (design §8.1)
    //
    // Hard delete is the chosen semantic for v1 (§16 item 5 was open;
    // resolving here). DELETE removes the row; CASCADE on the child FKs
    // wipes children/collabs/links/changelog with it. Soft delete can be
    // added later via a `deleted_at` column without breaking these
    // endpoints (callers just keep using DELETE).
    // ============================================================

    /**
     * Fields a client can write via createSample / updateSample. Each field
     * maps to a column in strabosamples.samples. id and userpkey are
     * managed by the service (PK is the caller for direct creates;
     * read-only on update). parent_sample_id / parent_userpkey are managed
     * via the /sample/{id}/parent sub-resource (samples/api-parent).
     */
    private static $WRITABLE_SPINE_FIELDS = array(
        'name', 'igsn', 'description', 'notes',
        'latitude', 'longitude',
        'display_sample_type', 'display_sample_purpose',
    );
    private static $WRITABLE_JSONB_FIELDS = array(
        'field_data', 'micro_data', 'experimental_data',
    );

    /**
     * Create a new sample owned by the caller. The id may be supplied
     * (legacy/grandfathered case) or minted (v4 UUID). userpkey is always
     * the caller — direct API creation makes the caller both owner and
     * creator.
     *
     * @param array $input  See $WRITABLE_SPINE_FIELDS + $WRITABLE_JSONB_FIELDS.
     *                      May also include 'id', 'parent_sample_id',
     *                      'parent_userpkey'.
     * @return array ['ok' => true, 'sample' => assembled] on success;
     *               ['ok' => false, 'error' => code] on failure.
     */
    public function createSample(array $input)
    {
        if ($this->userpkey === null || $this->userpkey <= 0) {
            return array('ok' => false, 'error' => 'not_authenticated');
        }

        $id = isset($input['id']) && $input['id'] !== '' ? (string)$input['id'] : UUID::v4();
        $ownerPkey = (int)$this->userpkey;

        // PK collision guard.
        $exists = $this->db->get_var_prepared(
            "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($id, $ownerPkey)
        );
        if ($exists) {
            return array('ok' => false, 'error' => 'duplicate_id');
        }

        // Parent validation (both-or-neither; caller must have read access).
        $parentSampleId = isset($input['parent_sample_id']) && $input['parent_sample_id'] !== '' ? (string)$input['parent_sample_id'] : null;
        $parentUserpkey = isset($input['parent_userpkey']) && $input['parent_userpkey'] !== '' ? (int)$input['parent_userpkey'] : null;
        if (($parentSampleId === null) !== ($parentUserpkey === null)) {
            return array('ok' => false, 'error' => 'parent_pair_required');
        }
        if ($parentSampleId !== null) {
            if (!$this->canRead($parentSampleId, $parentUserpkey)) {
                return array('ok' => false, 'error' => 'parent_not_accessible');
            }
        }

        // Build column + value lists.
        $cols   = array('id', 'userpkey', 'created_by', 'modified_by');
        $params = array($id, $ownerPkey, $ownerPkey, $ownerPkey);
        $idx    = 5;
        $placeholders = array('$1', '$2', '$3', '$4');

        foreach (self::$WRITABLE_SPINE_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $cols[]         = $f;
                $params[]       = $input[$f];
                $placeholders[] = '$' . $idx++;
            }
        }
        foreach (self::$WRITABLE_JSONB_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $cols[]         = $f;
                $params[]       = $this->encodeJsonOrNull($input[$f]);
                $placeholders[] = '$' . $idx++;
            }
        }
        if ($parentSampleId !== null) {
            $cols[]   = 'parent_sample_id';   $params[] = $parentSampleId;
            $placeholders[] = '$' . $idx++;
            $cols[]   = 'parent_userpkey';    $params[] = $parentUserpkey;
            $placeholders[] = '$' . $idx++;
        }

        $sql = "INSERT INTO strabosamples.samples (" . implode(', ', $cols) . ")
                VALUES (" . implode(', ', $placeholders) . ")";
        $this->db->prepare_query($sql, $params);

        // Changelog
        $initial = array();
        foreach (self::$WRITABLE_SPINE_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $initial[$f] = $input[$f];
            }
        }
        foreach (self::$WRITABLE_JSONB_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $initial[$f] = $input[$f];
            }
        }
        if ($parentSampleId !== null) {
            $initial['parent_sample_id'] = $parentSampleId;
            $initial['parent_userpkey']  = $parentUserpkey;
        }
        $this->logChange($id, $ownerPkey, 'create', array('created' => $initial));

        return array('ok' => true, 'sample' => $this->getSample($id, $ownerPkey));
    }

    /**
     * Update a sample's spine and/or per-subsystem JSONB. Partial update —
     * only fields present in $input are touched. Permission gate: caller
     * must have canEdit (owner or accepted edit collaborator).
     *
     * Field-linked samples reject latitude/longitude updates with
     * 'field_link_read_only' per design §6.1 (lat/lng is spot geometry,
     * owned by Neo4j). Until samples/field-integration wires up the
     * writeback, the safest behavior is to refuse those changes
     * rather than let strabosamples and Neo4j drift.
     */
    public function updateSample($id, $ownerPkey, array $input)
    {
        $ownerPkey = (int)$ownerPkey;
        $ctx = $this->auth->getSampleContext($this->userpkey, $id, $ownerPkey);
        if (!$ctx->exists) {
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canEdit()) {
            return array('ok' => false, 'error' => 'forbidden');
        }

        // Filter to writable fields actually present.
        $spineUpdates = array();
        foreach (self::$WRITABLE_SPINE_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $spineUpdates[$f] = $input[$f];
            }
        }
        $jsonbUpdates = array();
        foreach (self::$WRITABLE_JSONB_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $jsonbUpdates[$f] = $input[$f];
            }
        }
        if (empty($spineUpdates) && empty($jsonbUpdates)) {
            return array('ok' => false, 'error' => 'no_writable_fields');
        }

        // Field-link lat/lng read-only guard.
        if (array_key_exists('latitude', $spineUpdates) || array_key_exists('longitude', $spineUpdates)) {
            $hasFieldLink = $this->db->get_var_prepared(
                "SELECT 1 FROM strabosamples.sample_subsystem_links
                  WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field' LIMIT 1",
                array($id, $ownerPkey)
            );
            if ($hasFieldLink) {
                return array('ok' => false, 'error' => 'field_link_read_only');
            }
        }

        // Capture old values for changelog (only the fields we're about to change).
        $oldValuesNeeded = array_merge(array_keys($spineUpdates), array_keys($jsonbUpdates));
        $oldRow = $this->db->get_row_prepared(
            "SELECT " . implode(', ', $oldValuesNeeded) . "
               FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($id, $ownerPkey)
        );

        // Build SET clause.
        $setParts = array();
        $params   = array();
        $idx      = 1;
        foreach ($spineUpdates as $f => $v) {
            $setParts[] = "$f = \$$idx";  $params[] = $v;  $idx++;
        }
        foreach ($jsonbUpdates as $f => $v) {
            $setParts[] = "$f = \$$idx";  $params[] = $this->encodeJsonOrNull($v);  $idx++;
        }
        $setParts[] = "modified_at = now()";
        $setParts[] = "modified_by = \$$idx";  $params[] = $this->userpkey;  $idx++;

        $whereId        = '$' . $idx++;
        $whereUserpkey  = '$' . $idx++;
        $params[] = $id;
        $params[] = $ownerPkey;

        $sql = "UPDATE strabosamples.samples
                   SET " . implode(', ', $setParts) . "
                 WHERE id=$whereId AND userpkey=$whereUserpkey";
        $this->db->prepare_query($sql, $params);

        // Build changelog diff.
        $changes = array();
        foreach ($spineUpdates as $f => $v) {
            $old = ($oldRow !== null && isset($oldRow->$f)) ? $oldRow->$f : null;
            if ($old !== $v) {
                $changes[$f] = array('old' => $old, 'new' => $v);
            }
        }
        foreach ($jsonbUpdates as $f => $v) {
            // JSONB diffs would be noisy; log presence + new value only.
            $changes[$f] = array('updated' => true);
        }
        if (!empty($changes)) {
            $this->logChange($id, $ownerPkey, 'update', $changes);
        }

        return array('ok' => true, 'sample' => $this->getSample($id, $ownerPkey));
    }

    /**
     * Hard-delete a sample. Owner-only. Cascades children/collabs/links/
     * changelog/composition/parameters/documents via FK CASCADE.
     *
     * Note: the changelog row for the delete itself would be cascade-
     * deleted in the same transaction, so it's not appended. Audit of the
     * deletion is delegated to higher-level operational logging.
     */
    public function deleteSample($id, $ownerPkey)
    {
        $ownerPkey = (int)$ownerPkey;
        $ctx = $this->auth->getSampleContext($this->userpkey, $id, $ownerPkey);
        if (!$ctx->exists) {
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canManage()) {
            return array('ok' => false, 'error' => 'forbidden');
        }
        $this->db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($id, $ownerPkey)
        );
        return array('ok' => true);
    }

    /**
     * Append a row to strabosamples.sample_changelog. Source subsystem is
     * always 'samples_api' when invoked through the StraboSamples API path.
     * Subsystem integration sub-branches will call this with their own
     * 'field' / 'micro' / 'experimental' source labels.
     */
    protected function logChange($sampleId, $ownerPkey, $changeType, $changes, $sourceSubsystem = 'samples_api')
    {
        $this->db->prepare_query(
            "INSERT INTO strabosamples.sample_changelog
               (sample_id, sample_userpkey, changed_by, change_type, source_subsystem, changes)
             VALUES ($1, $2, $3, $4, $5, $6)",
            array(
                $sampleId,
                (int)$ownerPkey,
                (int)$this->userpkey,
                $changeType,
                $sourceSubsystem,
                $changes === null ? null : json_encode($changes),
            )
        );
    }

    protected function encodeJsonOrNull($val)
    {
        if ($val === null) return null;
        if (is_string($val)) {
            // Caller already encoded — trust it. (Lets clients pass already-
            // serialized JSONB when convenient.)
            return $val;
        }
        return json_encode($val);
    }
}
