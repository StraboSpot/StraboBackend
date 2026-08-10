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
                    s.custom_data,
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
        $sample['custom_data']       = $this->decodeJson($row->custom_data);

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
        if (!$ctx->canRead()) {
            // Existence-hiding: a caller without read access gets the same
            // not_found as a missing sample on every verb, so mutations can't
            // be used to probe which (id, owner) pairs exist. Readable-but-
            // not-writable callers still get the informative 'forbidden'.
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
        if (!$ctx->canRead()) {
            // Existence-hiding: a caller without read access gets the same
            // not_found as a missing sample on every verb, so mutations can't
            // be used to probe which (id, owner) pairs exist. Readable-but-
            // not-writable callers still get the informative 'forbidden'.
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
        if (!$ctx->canRead()) {
            // Existence-hiding: a caller without read access gets the same
            // not_found as a missing sample on every verb, so mutations can't
            // be used to probe which (id, owner) pairs exist. Readable-but-
            // not-writable callers still get the informative 'forbidden'.
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
        'field_data', 'micro_data', 'experimental_data', 'custom_data',
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
     * 'field_link_read_only' per design §6.1 — lat/lng is the Spot's
     * geometry, owned by Neo4j. The samples/field-writeback path pushes
     * the OTHER spine fields (name, igsn, description, notes,
     * display_sample_type, display_sample_purpose) back into the linked
     * Spot's `samples[]` JSON via writeBackFieldSpot() after the spine
     * UPDATE succeeds.
     */
    public function updateSample($id, $ownerPkey, array $input)
    {
        $ownerPkey = (int)$ownerPkey;
        $ctx = $this->auth->getSampleContext($this->userpkey, $id, $ownerPkey);
        if (!$ctx->canRead()) {
            // Existence-hiding: a caller without read access gets the same
            // not_found as a missing sample on every verb, so mutations can't
            // be used to probe which (id, owner) pairs exist. Readable-but-
            // not-writable callers still get the informative 'forbidden'.
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

        // Field writeback (samples/field-writeback): push spine edits back
        // into the linked Neo4j Spot's samples[] JSON. No-op when no Field
        // link exists, when only JSONB fields were updated, or when the
        // linked spot can't be located (drift logged as a no-op).
        if (!empty($spineUpdates)) {
            $this->writeBackFieldSpot($id, $ownerPkey, $spineUpdates);
        }

        // Micro server-PDF cache invalidation (samples/micro-server-pdf):
        // flip pdf_dirty=TRUE on any micro_projectmetadata row whose
        // project hosts a copy of this sample, so the next download via
        // microdb/strabomicroclass.php lazily regenerates the PDF via
        // microdb/lib/MicroProjectPDF.php with the fresh spine values.
        // No-op when there's no Micro link and when no spine fields
        // actually changed.
        if (!empty($spineUpdates)) {
            $this->markMicroProjectPdfsDirty($id, $ownerPkey);
        }

        // StraboSearch live-sync (§5.3): recompute the sample's fan-out
        // rows. Unconditional — JSONB-only updates (custom_data) feed the
        // searchtext bag too. Transaction-neutral: joins the tabular
        // import's outer transaction when one is open.
        require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';
        StraboSearchSync::touchSample($this->db, $id, $ownerPkey);

        return array('ok' => true, 'sample' => $this->getSample($id, $ownerPkey));
    }

    /**
     * Mark every Micro project whose JSON references this sample as
     * pdf_dirty=TRUE, so the next Micro download triggers a server-side
     * MicroProjectPDF regeneration. Best-effort; swallows DB errors
     * (the canonical write — strabosamples spine — has already succeeded
     * and shouldn't be lost on a flag-flip failure).
     */
    protected function markMicroProjectPdfsDirty($sampleId, $ownerPkey)
    {
        try {
            // sample_subsystem_links.reference_id for Micro = the
            // project's internal pkey (set by micro_sample_sync). Flip both
            // derived-artifact dirty flags on every such row: pdf_dirty gates
            // the server-PDF regen, files_dirty gates the static
            // project.json/zip regen (micro_regenerate_files_if_dirty). Both
            // are SET by the same edit; they clear independently on their own
            // lazy-regen paths. The link table is small per sample (a Micro
            // sample appears in 1 project today; the schema doesn't preclude N).
            $this->db->prepare_query(
                "UPDATE micro_projectmetadata
                    SET pdf_dirty = TRUE, files_dirty = TRUE
                  WHERE id IN (
                    SELECT CAST(reference_id AS INTEGER)
                      FROM strabosamples.sample_subsystem_links
                     WHERE sample_id = $1
                       AND sample_userpkey = $2
                       AND subsystem = 'micro'
                       AND reference_id ~ '^[0-9]+$'
                  )",
                array($sampleId, (int)$ownerPkey)
            );
        } catch (\Throwable $e) {
            // Swallowed by design — see method docblock.
        }
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
        if (!$ctx->canRead()) {
            // Existence-hiding: a caller without read access gets the same
            // not_found as a missing sample on every verb, so mutations can't
            // be used to probe which (id, owner) pairs exist. Readable-but-
            // not-writable callers still get the informative 'forbidden'.
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canManage()) {
            return array('ok' => false, 'error' => 'forbidden');
        }
        $this->db->prepare_query(
            "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($id, $ownerPkey)
        );

        // StraboSearch live-sync (§5.3): drop every fan-out row of the sample.
        require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';
        StraboSearchSync::removeSample($this->db, $id, $ownerPkey);

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

    // ============================================================
    // Parent / child (design §4.3, §7.5, §8.3)
    //
    // Single-parent tree. Brand-new capability — not back-filled at
    // migration. Cross-user parent/child is allowed; if access to the
    // parent is later revoked, the link "soft-nulls" in the response
    // (orphaned marker) while the stored pointer is preserved.
    // ============================================================

    /** Cap parent-chain walks at this depth in detectCycle. */
    const PARENT_CHAIN_MAX_DEPTH = 100;

    /**
     * Get the parent of a sample. Three response shapes:
     *   - sample not readable / not found            → null (controller → 404)
     *   - sample has no parent                        → ['parent' => null]
     *   - parent readable                             → ['parent' => assembledParent]
     *   - parent not readable (revoked) / missing     → ['parent' => ['orphaned' => true, ...]]
     *
     * The orphaned shape per §7.5: the stored pointer is preserved even
     * when read access drops, so the UI can show "this had a parent" with
     * an orphaned indicator.
     */
    public function getParent($sampleId, $ownerPkey)
    {
        $ownerPkey = (int)$ownerPkey;
        if (!$this->canRead($sampleId, $ownerPkey)) {
            return null;
        }
        $row = $this->db->get_row_prepared(
            "SELECT parent_sample_id, parent_userpkey
               FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sampleId, $ownerPkey)
        );
        if (!$row || $row->parent_sample_id === null) {
            return array('parent' => null);
        }
        $parentId      = $row->parent_sample_id;
        $parentOwner   = (int)$row->parent_userpkey;
        if ($this->canRead($parentId, $parentOwner)) {
            return array('parent' => $this->getSample($parentId, $parentOwner));
        }
        return array('parent' => array(
            'orphaned'         => true,
            'parent_sample_id' => $parentId,
            'parent_userpkey'  => $parentOwner,
        ));
    }

    /**
     * List children of a sample (those whose parent pointer matches).
     * Light spine info only — id, userpkey, name, display columns.
     */
    public function getChildren($sampleId, $ownerPkey)
    {
        $ownerPkey = (int)$ownerPkey;
        if (!$this->canRead($sampleId, $ownerPkey)) {
            return null;
        }
        $rows = $this->db->get_results_prepared(
            "SELECT id, userpkey, name, display_sample_type, display_sample_purpose
               FROM strabosamples.samples
              WHERE parent_sample_id=$1 AND parent_userpkey=$2
              ORDER BY created_at",
            array($sampleId, $ownerPkey)
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
        return array('children' => $out, 'count' => count($out));
    }

    /**
     * One-hop family snapshot for the Sample Overview family-tree widget
     * (design §12.2.1). Light spine shape only; the widget renders nodes
     * on the focus sample, its parent, and its direct children.
     *
     * Orphaned parent (caller can read focus but not the parent) returns
     * the §7.5 orphaned shape — the widget should still render the edge
     * with an "inaccessible" marker rather than hiding lineage entirely.
     *
     * Returns null when the caller has no read access to the focus.
     */
    public function getFamily($sampleId, $ownerPkey = null)
    {
        if ($ownerPkey === null) {
            $ownerPkey = $this->userpkey;
        }
        $ownerPkey = (int)$ownerPkey;

        if (!$this->canRead($sampleId, $ownerPkey)) {
            return null;
        }

        $row = $this->db->get_row_prepared(
            "SELECT id, userpkey, name, display_sample_type, display_sample_purpose,
                    parent_sample_id, parent_userpkey
               FROM strabosamples.samples
              WHERE id=$1 AND userpkey=$2",
            array($sampleId, $ownerPkey)
        );
        if (!$row) {
            return null;
        }

        $focus = array(
            'id'                     => $row->id,
            'userpkey'               => (int)$row->userpkey,
            'name'                   => $row->name,
            'display_sample_type'    => $row->display_sample_type,
            'display_sample_purpose' => $row->display_sample_purpose,
        );

        $parent = null;
        if ($row->parent_sample_id !== null && $row->parent_userpkey !== null) {
            $parentId      = $row->parent_sample_id;
            $parentUserpkey = (int)$row->parent_userpkey;
            if ($this->canRead($parentId, $parentUserpkey)) {
                $parent = $this->fetchParent($parentId, $parentUserpkey);
            } else {
                $parent = array(
                    'orphaned'         => true,
                    'parent_sample_id' => $parentId,
                    'parent_userpkey'  => $parentUserpkey,
                );
            }
        }

        $children = $this->fetchChildren($sampleId, $ownerPkey);

        return array(
            'focus'    => $focus,
            'parent'   => $parent,
            'children' => $children,
        );
    }

    /**
     * Set or change a sample's parent. canEdit on the child sample;
     * canRead on the proposed parent (§7.5). Cycle detection walks up
     * from the proposed parent; rejects 'cycle_detected' if the chain
     * reaches the child itself (or hits PARENT_CHAIN_MAX_DEPTH, which
     * should never occur on clean data but is a defensive bound).
     */
    public function setParent($sampleId, $ownerPkey, $parentSampleId, $parentUserpkey)
    {
        $ownerPkey      = (int)$ownerPkey;
        $parentUserpkey = (int)$parentUserpkey;

        $ctx = $this->auth->getSampleContext($this->userpkey, $sampleId, $ownerPkey);
        if (!$ctx->canRead()) {
            // Existence-hiding: a caller without read access gets the same
            // not_found as a missing sample on every verb, so mutations can't
            // be used to probe which (id, owner) pairs exist. Readable-but-
            // not-writable callers still get the informative 'forbidden'.
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canEdit()) {
            return array('ok' => false, 'error' => 'forbidden');
        }
        if ($parentSampleId === null || $parentSampleId === '' || $parentUserpkey <= 0) {
            return array('ok' => false, 'error' => 'parent_pair_required');
        }
        if (!$this->canRead($parentSampleId, $parentUserpkey)) {
            return array('ok' => false, 'error' => 'parent_not_accessible');
        }
        if ($this->detectCycle($sampleId, $ownerPkey, $parentSampleId, $parentUserpkey)) {
            return array('ok' => false, 'error' => 'cycle_detected');
        }

        // Capture old parent for changelog.
        $old = $this->db->get_row_prepared(
            "SELECT parent_sample_id, parent_userpkey FROM strabosamples.samples
              WHERE id=$1 AND userpkey=$2",
            array($sampleId, $ownerPkey)
        );

        $this->db->prepare_query(
            "UPDATE strabosamples.samples
                SET parent_sample_id = $1,
                    parent_userpkey  = $2,
                    modified_at      = now(),
                    modified_by      = $3
              WHERE id=$4 AND userpkey=$5",
            array($parentSampleId, $parentUserpkey, $this->userpkey, $sampleId, $ownerPkey)
        );

        $this->logChange($sampleId, $ownerPkey, 'parent_set', array(
            'parent' => array(
                'old' => array(
                    'parent_sample_id' => $old ? $old->parent_sample_id : null,
                    'parent_userpkey'  => $old && $old->parent_userpkey !== null ? (int)$old->parent_userpkey : null,
                ),
                'new' => array(
                    'parent_sample_id' => $parentSampleId,
                    'parent_userpkey'  => $parentUserpkey,
                ),
            ),
        ));

        return array('ok' => true);
    }

    /**
     * Clear a sample's parent pointer. canEdit on the sample. No-op if
     * the sample had no parent (still returns ok). Logs to changelog
     * when the cleared pointer was non-null.
     */
    public function clearParent($sampleId, $ownerPkey)
    {
        $ownerPkey = (int)$ownerPkey;

        $ctx = $this->auth->getSampleContext($this->userpkey, $sampleId, $ownerPkey);
        if (!$ctx->canRead()) {
            // Existence-hiding: a caller without read access gets the same
            // not_found as a missing sample on every verb, so mutations can't
            // be used to probe which (id, owner) pairs exist. Readable-but-
            // not-writable callers still get the informative 'forbidden'.
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canEdit()) {
            return array('ok' => false, 'error' => 'forbidden');
        }

        $old = $this->db->get_row_prepared(
            "SELECT parent_sample_id, parent_userpkey FROM strabosamples.samples
              WHERE id=$1 AND userpkey=$2",
            array($sampleId, $ownerPkey)
        );

        $this->db->prepare_query(
            "UPDATE strabosamples.samples
                SET parent_sample_id = NULL,
                    parent_userpkey  = NULL,
                    modified_at      = now(),
                    modified_by      = $1
              WHERE id=$2 AND userpkey=$3",
            array($this->userpkey, $sampleId, $ownerPkey)
        );

        if ($old && $old->parent_sample_id !== null) {
            $this->logChange($sampleId, $ownerPkey, 'parent_clear', array(
                'parent' => array(
                    'old' => array(
                        'parent_sample_id' => $old->parent_sample_id,
                        'parent_userpkey'  => $old->parent_userpkey !== null ? (int)$old->parent_userpkey : null,
                    ),
                    'new' => null,
                ),
            ));
        }

        return array('ok' => true);
    }

    /**
     * Walks the parent chain from a proposed-parent (id, userpkey) upward,
     * returning true if the chain reaches ($childId, $childUserpkey) (or
     * exceeds the depth cap). Self-parent is a cycle of length 1.
     */
    protected function detectCycle($childId, $childUserpkey, $proposedParentId, $proposedParentUserpkey)
    {
        if ($proposedParentId === $childId && (int)$proposedParentUserpkey === (int)$childUserpkey) {
            return true;
        }
        $curId   = $proposedParentId;
        $curUpk  = (int)$proposedParentUserpkey;
        for ($depth = 0; $depth < self::PARENT_CHAIN_MAX_DEPTH; $depth++) {
            $row = $this->db->get_row_prepared(
                "SELECT parent_sample_id, parent_userpkey
                   FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                array($curId, $curUpk)
            );
            if (!$row || $row->parent_sample_id === null) {
                return false;  // reached a root before hitting child
            }
            if ($row->parent_sample_id === $childId && (int)$row->parent_userpkey === (int)$childUserpkey) {
                return true;
            }
            $curId  = $row->parent_sample_id;
            $curUpk = (int)$row->parent_userpkey;
        }
        // Hit depth cap — treat as cycle to be safe.
        return true;
    }

    // ============================================================
    // Sub-arrays (design §8.4, §16 item 2)
    //
    // Resolves §16 item 2: PUT semantics are full-replace, matching the
    // existing Experimental upload conventions. Delta semantics can be
    // added later under a different endpoint shape (e.g., PATCH) without
    // breaking the replace path.
    // ============================================================

    /** Per-table config: which columns to read/write and the required field. */
    private static $SUB_ARRAY_TABLES = array(
        'composition' => array(
            'table'    => 'strabosamples.sample_composition',
            'required' => 'mineral',
            'cols'     => array('mineral', 'other_mineral', 'fraction', 'unit', 'grainsize', 'ordering'),
            'log_type' => 'composition_change',
        ),
        'parameters' => array(
            'table'    => 'strabosamples.sample_parameters',
            'required' => 'control',
            'cols'     => array('control', 'other_control', 'value', 'unit', 'prefix', 'note', 'ordering'),
            'log_type' => 'parameters_change',
        ),
        'documents' => array(
            'table'    => 'strabosamples.sample_documents',
            'required' => 'uuid',
            'cols'     => array('uuid', 'type', 'other_type', 'format', 'other_format', 'path', 'document_id', 'original_filename', 'description', 'ordering'),
            'log_type' => 'documents_change',
        ),
    );

    public function listComposition($sampleId, $ownerPkey) { return $this->listSubArray('composition', $sampleId, $ownerPkey); }
    public function listParameters($sampleId, $ownerPkey)  { return $this->listSubArray('parameters',  $sampleId, $ownerPkey); }
    public function listDocuments($sampleId, $ownerPkey)   { return $this->listSubArray('documents',   $sampleId, $ownerPkey); }

    public function replaceComposition($sampleId, $ownerPkey, array $items) { return $this->replaceSubArray('composition', $sampleId, $ownerPkey, $items); }
    public function replaceParameters($sampleId, $ownerPkey, array $items)  { return $this->replaceSubArray('parameters',  $sampleId, $ownerPkey, $items); }
    public function replaceDocuments($sampleId, $ownerPkey, array $items)   { return $this->replaceSubArray('documents',   $sampleId, $ownerPkey, $items); }

    /**
     * Read the sub-array. canRead gate. Returns:
     *   - null on no read access / not found
     *   - ['<resource>' => [...], 'count' => N]
     */
    protected function listSubArray($resource, $sampleId, $ownerPkey)
    {
        $ownerPkey = (int)$ownerPkey;
        if (!$this->canRead($sampleId, $ownerPkey)) {
            return null;
        }
        $items = $this->fetchSubArrayRows($resource, $sampleId, $ownerPkey);
        return array($resource => $items, 'count' => count($items));
    }

    /**
     * Full-replace the sub-array in a single transaction. canEdit gate.
     * Items are validated (required field per table) before any write so
     * partial replacements aren't possible. `ordering` is taken from each
     * item if present, otherwise from its zero-based index in the input.
     */
    protected function replaceSubArray($resource, $sampleId, $ownerPkey, array $items)
    {
        $ownerPkey = (int)$ownerPkey;
        $cfg = self::$SUB_ARRAY_TABLES[$resource];

        $ctx = $this->auth->getSampleContext($this->userpkey, $sampleId, $ownerPkey);
        if (!$ctx->canRead()) {
            // Existence-hiding: a caller without read access gets the same
            // not_found as a missing sample on every verb, so mutations can't
            // be used to probe which (id, owner) pairs exist. Readable-but-
            // not-writable callers still get the informative 'forbidden'.
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!$ctx->canEdit()) {
            return array('ok' => false, 'error' => 'forbidden');
        }

        // Normalize + validate up front so any partial-write is impossible.
        $normalized = array();
        foreach ($items as $i => $item) {
            $item = (array)$item;
            $required = $item[$cfg['required']] ?? null;
            if ($required === null || $required === '') {
                return array(
                    'ok'    => false,
                    'error' => 'missing_required_field',
                    'detail' => array('index' => $i, 'field' => $cfg['required']),
                );
            }
            if (!array_key_exists('ordering', $item) || $item['ordering'] === null) {
                $item['ordering'] = $i;
            }
            $normalized[] = $item;
        }

        // Snapshot old counts for the changelog.
        $oldCount = (int)$this->db->get_var_prepared(
            "SELECT count(*) FROM {$cfg['table']} WHERE sample_id=$1 AND sample_userpkey=$2",
            array($sampleId, $ownerPkey)
        );

        // Transactional DELETE + INSERTs.
        $this->db->query("BEGIN");
        $this->db->prepare_query(
            "DELETE FROM {$cfg['table']} WHERE sample_id=$1 AND sample_userpkey=$2",
            array($sampleId, $ownerPkey)
        );
        $cols    = $cfg['cols'];
        $colList = 'sample_id, sample_userpkey, ' . implode(', ', $cols);
        // Build "$1, $2, $3, ..., $N" placeholder list once.
        $totalParamsPerRow = 2 + count($cols);  // sample_id, sample_userpkey, + each col
        foreach ($normalized as $item) {
            $params = array($sampleId, $ownerPkey);
            foreach ($cols as $c) {
                $params[] = array_key_exists($c, $item) ? $item[$c] : null;
            }
            $placeholders = array();
            for ($p = 1; $p <= $totalParamsPerRow; $p++) { $placeholders[] = '$' . $p; }
            $this->db->prepare_query(
                "INSERT INTO {$cfg['table']} ($colList) VALUES (" . implode(', ', $placeholders) . ")",
                $params
            );
        }

        // Bump sample's modified_at/modified_by — the row contents changed
        // semantically even if no column on the spine row did.
        $this->db->prepare_query(
            "UPDATE strabosamples.samples SET modified_at = now(), modified_by = $1
              WHERE id=$2 AND userpkey=$3",
            array($this->userpkey, $sampleId, $ownerPkey)
        );

        $this->db->query("COMMIT");

        // Changelog. Diff shape mirrors the spine 'update' diff:
        // {resource: {old_count, new_count}}.
        $this->logChange($sampleId, $ownerPkey, $cfg['log_type'], array(
            $resource => array(
                'old_count' => $oldCount,
                'new_count' => count($normalized),
            ),
        ));

        return array(
            'ok'       => true,
            $resource  => $this->fetchSubArrayRows($resource, $sampleId, $ownerPkey),
            'count'    => count($normalized),
        );
    }

    /**
     * Underlying row fetch — delegates to the per-resource protected helper
     * that's already used by getSample. Keeps the read shape consistent
     * between the assembled-sample view and the sub-resource endpoint.
     */
    protected function fetchSubArrayRows($resource, $sampleId, $ownerPkey)
    {
        switch ($resource) {
            case 'composition': return $this->fetchComposition($sampleId, $ownerPkey);
            case 'parameters':  return $this->fetchParameters($sampleId, $ownerPkey);
            case 'documents':   return $this->fetchDocuments($sampleId, $ownerPkey);
        }
        return array();
    }

    // ============================================================
    // Changelog (design §8.5, §16 item 3)
    //
    // Resolves §16 item 3 pagination defaults: limit defaults to 50,
    // clamped to [1, 500]; offset defaults to 0 (no negative). Ordering
    // is newest first (changed_at DESC, pkey DESC as a tiebreaker for
    // same-timestamp writes within a single second). Revisitable when
    // a UI consumer's actual scroll patterns are known.
    // ============================================================

    const CHANGELOG_DEFAULT_LIMIT = 50;
    const CHANGELOG_MAX_LIMIT     = 500;

    /**
     * Paginated changelog for one sample. canRead gate — collaborators
     * (edit or readonly) see the same audit history the owner does.
     *
     * @return array|null  null on no read access / not found.
     *                     Otherwise: {changelog: [...], count, total, limit, offset}
     */
    public function listChangelog($sampleId, $ownerPkey, $limit = null, $offset = null)
    {
        $ownerPkey = (int)$ownerPkey;
        if (!$this->canRead($sampleId, $ownerPkey)) {
            return null;
        }

        // Sanitize pagination.
        $limit  = (int)($limit  ?? self::CHANGELOG_DEFAULT_LIMIT);
        $offset = (int)($offset ?? 0);
        if ($limit  < 1)                              $limit  = self::CHANGELOG_DEFAULT_LIMIT;
        if ($limit  > self::CHANGELOG_MAX_LIMIT)      $limit  = self::CHANGELOG_MAX_LIMIT;
        if ($offset < 0)                              $offset = 0;

        $total = (int)$this->db->get_var_prepared(
            "SELECT count(*) FROM strabosamples.sample_changelog
              WHERE sample_id=$1 AND sample_userpkey=$2",
            array($sampleId, $ownerPkey)
        );

        $rows = $this->db->get_results_prepared(
            "SELECT pkey, changed_by, changed_at, change_type, source_subsystem,
                    changes::text AS changes_text
               FROM strabosamples.sample_changelog
              WHERE sample_id=$1 AND sample_userpkey=$2
              ORDER BY changed_at DESC, pkey DESC
              LIMIT $3 OFFSET $4",
            array($sampleId, $ownerPkey, $limit, $offset)
        );

        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = array(
                    'pkey'             => (int)$r->pkey,
                    'changed_by'       => (int)$r->changed_by,
                    'changed_at'       => $r->changed_at,
                    'change_type'      => $r->change_type,
                    'source_subsystem' => $r->source_subsystem,
                    'changes'          => $r->changes_text === null ? null : json_decode($r->changes_text, true),
                );
            }
        }

        return array(
            'changelog' => $out,
            'count'     => count($out),
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
        );
    }

    // ============================================================
    // Subsystem integration (design §8.6, §10.4)
    //
    // upsertSample is the server-internal entry point invoked by Field /
    // Micro / Experimental upload paths. It is auth-free — the caller has
    // already validated the actor via the subsystem's own auth path, and
    // the sample is being written under the project owner's pkey per
    // §7.3.1. The user-facing /samplesdb/ endpoints go through
    // createSample/updateSample with the canEdit gate; only this method
    // skips the gate.
    //
    // Runtime application of §10.4 spine-priority rules:
    //   - On a NEW row, the source writes the spine and its *_data JSONB.
    //   - On an EXISTING row, the per-source JSONB is always overwritten;
    //     spine columns are overwritten only when no HIGHER-priority
    //     source has already touched the row.
    //   - Priority order: Field > Micro > Experimental.
    //   - When spine is skipped, the new values are still preserved
    //     verbatim in the *_data JSONB so they aren't lost.
    // ============================================================

    const SOURCE_PRIORITY = array('field' => 0, 'micro' => 1, 'experimental' => 2);

    /**
     * Upsert a sample on behalf of a subsystem (Experimental for now;
     * Micro and Field follow). Internal-facing, no canEdit/canManage gate.
     *
     * Required: source ('field'/'micro'/'experimental'), id, owner_pkey.
     * Spine fields (name, igsn, description, notes, latitude, longitude,
     * display_sample_type, display_sample_purpose) and subsystem_data are
     * the projected source values. reference describes the source's link
     * (reference_id + reference_userpkey + reference_metadata). children
     * is an array with optional composition/parameters/documents arrays.
     * opts may include 'autoSeedCollaborators' (array of pkeys to seed as
     * accepted edit collaborators on first creation only).
     *
     * The actor (modified_by / changed_by) is $this->userpkey; falls back
     * to $ownerPkey when unset (server-internal callers can either
     * setUserpkey beforehand or rely on owner-attribution).
     *
     * @return array {ok, sample_id, created (bool), source}
     */
    public function upsertSample($source, $sampleId, $ownerPkey, array $spineFields, $subsystemData, array $reference, array $children = array(), array $opts = array())
    {
        if (!isset(self::SOURCE_PRIORITY[$source])) {
            return array('ok' => false, 'error' => 'invalid_source');
        }
        $ownerPkey = (int)$ownerPkey;
        $actorPkey = ($this->userpkey !== null && $this->userpkey > 0) ? (int)$this->userpkey : $ownerPkey;
        $jsonbCol  = $source . '_data';   // field_data, micro_data, experimental_data

        // Existence + higher-priority-source check.
        $existing = $this->db->get_row_prepared(
            "SELECT id, field_data, micro_data, experimental_data
               FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sampleId, $ownerPkey)
        );
        $created = ($existing === null);

        // Decide spine application per §10.4.
        $writeSpine = true;
        if ($existing !== null) {
            foreach (self::SOURCE_PRIORITY as $otherSource => $priority) {
                if ($priority >= self::SOURCE_PRIORITY[$source]) continue;
                $otherCol = $otherSource . '_data';
                if ($existing->$otherCol !== null) {
                    $writeSpine = false;
                    break;
                }
            }
        }

        $this->db->query("BEGIN");

        $spineDiff = array();
        if ($created) {
            $this->upsertSample_insertNew($source, $sampleId, $ownerPkey, $actorPkey, $spineFields, $subsystemData, $jsonbCol);
        } else {
            $spineDiff = $this->upsertSample_updateExisting($sampleId, $ownerPkey, $actorPkey, $spineFields, $subsystemData, $jsonbCol, $writeSpine);
        }

        // Children — replace per-resource only when this source supplied them.
        if (array_key_exists('composition', $children)) {
            $this->upsertSample_replaceChildren('composition', $sampleId, $ownerPkey, $children['composition']);
        }
        if (array_key_exists('parameters', $children)) {
            $this->upsertSample_replaceChildren('parameters', $sampleId, $ownerPkey, $children['parameters']);
        }
        if (array_key_exists('documents', $children)) {
            $this->upsertSample_replaceChildren('documents', $sampleId, $ownerPkey, $children['documents']);
        }

        // Subsystem link — UPSERT on the table's UNIQUE (sample_id, sample_userpkey,
        // subsystem, reference_id, reference_userpkey) tuple.
        $refId    = isset($reference['reference_id']) ? (string)$reference['reference_id'] : '';
        $refUpk   = isset($reference['reference_userpkey']) ? (int)$reference['reference_userpkey'] : $ownerPkey;
        $refMeta  = isset($reference['reference_metadata']) ? $reference['reference_metadata'] : null;
        if ($refId !== '') {
            $this->db->prepare_query(
                "INSERT INTO strabosamples.sample_subsystem_links
                   (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey, reference_metadata)
                 VALUES ($1, $2, $3, $4, $5, $6)
                 ON CONFLICT (sample_id, sample_userpkey, subsystem, reference_id, reference_userpkey)
                 DO UPDATE SET reference_metadata = EXCLUDED.reference_metadata,
                               modified_at = now()",
                array($sampleId, $ownerPkey, $source, $refId, $refUpk, $this->encodeJsonOrNull($refMeta))
            );
        }

        $this->db->query("COMMIT");

        // StraboSearch live-sync (§5.3): recompute the sample's fan-out rows
        // from the just-committed spine + links. Runs for every subsystem
        // caller (Field/Micro/Exp sample mirrors all land here).
        require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';
        StraboSearchSync::touchSample($this->db, $sampleId, $ownerPkey);

        // Changelog (outside the transaction — failure here doesn't roll back the upsert).
        if ($created) {
            $this->logChange($sampleId, $ownerPkey, 'create', array(
                'source' => $source,
                'spine_written' => $writeSpine,
            ), $source);

            // Auto-seed project collaborators on first creation only (§7.3.1).
            $seedList = isset($opts['autoSeedCollaborators']) ? (array)$opts['autoSeedCollaborators'] : array();
            $seedLevel = isset($opts['autoSeedLevel']) && in_array($opts['autoSeedLevel'], array('edit', 'readonly'), true)
                ? $opts['autoSeedLevel'] : 'edit';
            if (!empty($seedList)) {
                $this->autoSeedProjectCollaborators($sampleId, $ownerPkey, $seedList, $seedLevel);
            }
        } else {
            $updatePayload = array(
                'source' => $source,
                'spine_written' => $writeSpine,
                $jsonbCol => array('updated' => true),
            );
            if (!empty($spineDiff)) {
                $updatePayload['spine_diff'] = $spineDiff;
            }
            $this->logChange($sampleId, $ownerPkey, 'update', $updatePayload, $source);
        }

        return array(
            'ok'        => true,
            'sample_id' => $sampleId,
            'created'   => $created,
            'source'    => $source,
        );
    }

    /**
     * Counterpart to upsertSample for the empty-sample case: the subsystem
     * deleted its sample data and we should mirror the removal into
     * strabosamples — but only when no HIGHER-priority source still
     * references the row, and we always clear THIS source's JSONB + links +
     * children regardless.
     */
    public function removeSubsystemSample($source, $sampleId, $ownerPkey)
    {
        if (!isset(self::SOURCE_PRIORITY[$source])) {
            return array('ok' => false, 'error' => 'invalid_source');
        }
        $ownerPkey = (int)$ownerPkey;

        $existing = $this->db->get_row_prepared(
            "SELECT field_data, micro_data, experimental_data
               FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sampleId, $ownerPkey)
        );
        if ($existing === null) {
            return array('ok' => true, 'removed' => false);
        }

        $this->db->query("BEGIN");

        // Drop this source's link rows + child rows.
        $this->db->prepare_query(
            "DELETE FROM strabosamples.sample_subsystem_links
              WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem=$3",
            array($sampleId, $ownerPkey, $source)
        );

        // Check if any other source still has data for this row.
        $otherSourceHasData = false;
        foreach (self::SOURCE_PRIORITY as $other => $_) {
            if ($other === $source) continue;
            $col = $other . '_data';
            if ($existing->$col !== null) { $otherSourceHasData = true; break; }
        }
        $otherLinkRemains = (bool)$this->db->get_var_prepared(
            "SELECT 1 FROM strabosamples.sample_subsystem_links
              WHERE sample_id=$1 AND sample_userpkey=$2 LIMIT 1",
            array($sampleId, $ownerPkey)
        );

        if (!$otherSourceHasData && !$otherLinkRemains) {
            // Nothing else cares — drop the whole sample (CASCADE handles children).
            $this->db->prepare_query(
                "DELETE FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                array($sampleId, $ownerPkey)
            );
            $this->db->query("COMMIT");

            // StraboSearch live-sync (§5.3): whole sample gone.
            require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';
            StraboSearchSync::removeSample($this->db, $sampleId, $ownerPkey);

            return array('ok' => true, 'removed' => true);
        }

        // Other sources still in play — null out THIS source's JSONB column
        // and the per-source children (composition/parameters/documents are
        // shared across sources today; we don't currently track origin per
        // child row, so we leave the existing rows alone unless this branch
        // is rewritten). For Experimental, the children correspond to the
        // experimental upload; leaving them in place is the safe choice.
        $jsonbCol = $source . '_data';
        $this->db->prepare_query(
            "UPDATE strabosamples.samples SET {$jsonbCol} = NULL, modified_at = now() WHERE id=$1 AND userpkey=$2",
            array($sampleId, $ownerPkey)
        );

        $this->db->query("COMMIT");

        // StraboSearch live-sync (§5.3): recompute from the remaining links —
        // this source's fan-out rows drop, other sources' rows survive.
        require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';
        StraboSearchSync::touchSample($this->db, $sampleId, $ownerPkey);

        return array('ok' => true, 'removed' => false);
    }

    // ---- upsertSample internals ----

    protected function upsertSample_insertNew($source, $sampleId, $ownerPkey, $actorPkey, array $spineFields, $subsystemData, $jsonbCol)
    {
        $cols   = array('id', 'userpkey', 'created_by', 'modified_by', $jsonbCol);
        $params = array($sampleId, $ownerPkey, $ownerPkey, $actorPkey, $this->encodeJsonOrNull($subsystemData));
        foreach (self::$WRITABLE_SPINE_FIELDS as $f) {
            if (array_key_exists($f, $spineFields)) {
                $cols[]   = $f;
                $params[] = $spineFields[$f];
            }
        }
        $placeholders = array();
        for ($i = 1; $i <= count($params); $i++) { $placeholders[] = '$' . $i; }
        $this->db->prepare_query(
            "INSERT INTO strabosamples.samples (" . implode(', ', $cols) . ")
             VALUES (" . implode(', ', $placeholders) . ")",
            $params
        );
    }

    protected function upsertSample_updateExisting($sampleId, $ownerPkey, $actorPkey, array $spineFields, $subsystemData, $jsonbCol, $writeSpine)
    {
        // When spine is being written, snapshot the prior values of the spine
        // fields the source is supplying so the caller can build a {old,new}
        // diff for the changelog. Matches updateSample's pattern at the modal
        // path, so subsystem-upload audit rows carry the same shape as user
        // edits.
        $spineDiff   = array();
        $diffTargets = array();
        if ($writeSpine) {
            foreach (self::$WRITABLE_SPINE_FIELDS as $f) {
                if (array_key_exists($f, $spineFields)) {
                    $diffTargets[] = $f;
                }
            }
        }
        $oldRow = null;
        if (!empty($diffTargets)) {
            $oldRow = $this->db->get_row_prepared(
                "SELECT " . implode(', ', $diffTargets) . "
                   FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                array($sampleId, $ownerPkey)
            );
        }

        $setParts = array();
        $params   = array();
        $idx      = 1;
        // Per-source JSONB is always overwritten.
        $setParts[] = "$jsonbCol = \$$idx"; $params[] = $this->encodeJsonOrNull($subsystemData); $idx++;
        if ($writeSpine) {
            foreach (self::$WRITABLE_SPINE_FIELDS as $f) {
                if (array_key_exists($f, $spineFields)) {
                    $setParts[] = "$f = \$$idx"; $params[] = $spineFields[$f]; $idx++;
                }
            }
        }
        $setParts[] = "modified_at = now()";
        $setParts[] = "modified_by = \$$idx"; $params[] = $actorPkey; $idx++;
        $whereId  = '$' . $idx++; $params[] = $sampleId;
        $whereUpk = '$' . $idx++; $params[] = $ownerPkey;
        $this->db->prepare_query(
            "UPDATE strabosamples.samples SET " . implode(', ', $setParts) .
            " WHERE id=$whereId AND userpkey=$whereUpk",
            $params
        );

        if ($oldRow !== null) {
            foreach ($diffTargets as $f) {
                $old = isset($oldRow->$f) ? $oldRow->$f : null;
                $new = $spineFields[$f];
                if ($old !== $new) {
                    $spineDiff[$f] = array('old' => $old, 'new' => $new);
                }
            }
        }
        return $spineDiff;
    }

    protected function upsertSample_replaceChildren($resource, $sampleId, $ownerPkey, $items)
    {
        $cfg = self::$SUB_ARRAY_TABLES[$resource];
        $items = is_array($items) ? $items : array();

        $this->db->prepare_query(
            "DELETE FROM {$cfg['table']} WHERE sample_id=$1 AND sample_userpkey=$2",
            array($sampleId, $ownerPkey)
        );
        if (empty($items)) return;

        $cols    = $cfg['cols'];
        $colList = 'sample_id, sample_userpkey, ' . implode(', ', $cols);
        $totalParamsPerRow = 2 + count($cols);
        $placeholders = array();
        for ($p = 1; $p <= $totalParamsPerRow; $p++) { $placeholders[] = '$' . $p; }
        $placeholdersStr = implode(', ', $placeholders);

        foreach ($items as $i => $item) {
            $item = (array)$item;
            $required = $item[$cfg['required']] ?? null;
            if ($required === null || $required === '') {
                continue;  // Skip rows missing the required field rather than
                           // abort the whole upsert (the user-facing PUT
                           // endpoint validates up front; this path is best-
                           // effort for migrated/legacy data shapes).
            }
            if (!array_key_exists('ordering', $item) || $item['ordering'] === null) {
                $item['ordering'] = $i;
            }
            $params = array($sampleId, $ownerPkey);
            foreach ($cols as $c) {
                $params[] = array_key_exists($c, $item) ? $item[$c] : null;
            }
            $this->db->prepare_query(
                "INSERT INTO {$cfg['table']} ($colList) VALUES ($placeholdersStr)",
                $params
            );
        }
    }

    // ---- field writeback (samples/field-writeback) ----

    /**
     * Inverse of the field-integration spine projection: maps strabosamples
     * spine column names back to the StraboField sample-object key names.
     * Keys absent from this map are not pushed back. latitude/longitude are
     * NOT writable for Field-linked samples (rejected upstream — lat/lng is
     * the Spot's geometry, not a sample property).
     */
    private static $FIELD_SPINE_INVERSE = array(
        'name'                   => 'sample_id_name',
        'igsn'                   => 'Sample_IGSN',
        'description'            => 'sample_description',
        'notes'                  => 'sample_notes',
        'display_sample_type'    => 'material_type',
        'display_sample_purpose' => 'main_sampling_purpose',
    );

    /**
     * Push spine edits from a StraboSamples API update back into the linked
     * Neo4j Spot's `samples[]` JSON-string property (§9.1 writeback).
     *
     * Rich link (reference_metadata.rich === true): the holder spot IS the
     * sample-spot — samples[0] gets the inverse-mapped fields, and the spot's
     * own `name` + `modified_timestamp` are bumped so Field clients see the
     * change on next sync.
     *
     * Legacy link (rich === false / absent): the sample lives inline in a
     * parent spot's samples[]. The matching entry (by id) gets the inverse-
     * mapped fields. spot.name is the parent spot's name and is left alone;
     * only modified_timestamp bumps.
     *
     * When the link points at a spot that's no longer in Neo4j, or the
     * samples[] payload doesn't contain a matching entry, the helper returns
     * without touching anything and returns null — the spine update in
     * strabosamples still stands. This is a known v1 gap (drift).
     *
     * material_type vs other_material_type: v1 sets material_type = the
     * display value verbatim. Any pre-existing other_material_type field is
     * left in place but no longer load-bearing (the migration's fall-through
     * rule kicks in only when material_type === 'other').
     *
     * @return array|null  array('ok'=>true,'wrote'=>bool,'reference_id'=>string,'rich'=>bool)
     *                     on writeback attempt, or null when there is no
     *                     Field link to write to (caller treats both as
     *                     success — non-Field-linked samples are no-ops).
     */
    protected function writeBackFieldSpot($sampleId, $ownerPkey, array $spineUpdates)
    {
        if ($this->neodb === null) return null;

        // Run enum-bound spine columns through the cross-vocab translator
        // before pushing them into Field. Experimental "Igneous Rock" /
        // "Soil" / "Mineral" bucket to Field enum values; "Glass" / "Ice"
        // / etc. have no Field bucket and are dropped from the push; free
        // text from the modal's "Other" escape is also dropped. Skipped
        // keys produce a 'writeback_translation' changelog note appended
        // after the Cypher write so the user (and any future audit
        // tooling) can see which keys didn't make it.
        require_once __DIR__ . '/../lib/vocab_translate.php';

        $pushable          = array();
        $translationNotes  = array();
        foreach (self::$FIELD_SPINE_INVERSE as $col => $fieldKey) {
            if (!array_key_exists($col, $spineUpdates)) continue;
            $value = $spineUpdates[$col];
            $tr    = samples_vocab_translate_to_field($col, $value);
            if ($tr['status'] === 'passthrough') {
                $pushable[$fieldKey] = $tr['value'];
            } elseif ($tr['status'] === 'translated') {
                $pushable[$fieldKey] = $tr['value'];
                $translationNotes[]  = array(
                    'spine_column' => $col,
                    'field_key'    => $fieldKey,
                    'original'     => $tr['from'],
                    'translated'   => $tr['value'],
                );
            } else {
                $translationNotes[] = array(
                    'spine_column' => $col,
                    'field_key'    => $fieldKey,
                    'skipped'      => $value,
                    'reason'       => $tr['status'] === 'no_mapping'
                                      ? (isset($tr['reason']) ? $tr['reason'] : 'no_mapping')
                                      : 'free_text',
                );
            }
        }
        if (empty($pushable)) {
            // Edge case: only translation skips, nothing left to push to
            // Field. Still log the notes — the user's modal edit was a
            // legitimate change to the spine, but Field can't represent
            // it. Logging the translation context here is the audit trail
            // for "you changed material_type but Field still shows
            // intact_rock because Glass has no Field bucket."
            if (!empty($translationNotes)) {
                $this->logChange($sampleId, $ownerPkey, 'writeback_translation',
                    array('skipped_only' => true, 'notes' => $translationNotes), 'samples_api');
            }
            return null;
        }

        // Look up the Field link. Only the first row matters — a sample id
        // resolves to exactly one Field source row (the migration + live
        // hook both write one Field link per sample).
        $link = $this->db->get_row_prepared(
            "SELECT reference_id, reference_userpkey, reference_metadata::text AS rm
               FROM strabosamples.sample_subsystem_links
              WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'
              LIMIT 1",
            array($sampleId, (int)$ownerPkey)
        );
        if ($link === null) return null;

        $referenceId       = (string)$link->reference_id;
        $referenceUserpkey = (int)$link->reference_userpkey;
        $meta = ($link->rm !== null && $link->rm !== '') ? json_decode($link->rm, true) : array();
        $isRich = is_array($meta) && !empty($meta['rich']);

        if (!ctype_digit($referenceId)) return null;  // can't address a Spot without a numeric id
        $spotId = (int)$referenceId;

        $rec = $this->neodb->getRecord(
            "MATCH (s:Spot {id:$spotId, userpkey:$referenceUserpkey}) RETURN s LIMIT 1"
        );
        if (!$rec) return null;
        $props = $rec->get('s')->values();
        if (!is_array($props)) return null;

        $rawSamples = isset($props['json_samples']) ? $props['json_samples'] : null;
        if (!is_string($rawSamples) || $rawSamples === '') return null;
        $samples = json_decode($rawSamples, true);
        if (!is_array($samples) || empty($samples)) return null;

        // Locate the entry to mutate. Rich → index 0 (authoritative); legacy
        // → first entry whose id matches the sample id.
        $targetIdx = null;
        if ($isRich) {
            $targetIdx = 0;
        } else {
            foreach ($samples as $i => $entry) {
                $entry = (array)$entry;
                if (isset($entry['id']) && (string)$entry['id'] === (string)$sampleId) {
                    $targetIdx = $i;
                    break;
                }
            }
        }
        if ($targetIdx === null) return null;

        $entry = (array)$samples[$targetIdx];
        foreach ($pushable as $k => $v) {
            if ($v === null || $v === '') {
                // Drop the key on null/empty rather than leave a stale value.
                unset($entry[$k]);
            } else {
                $entry[$k] = $v;
            }
        }
        $samples[$targetIdx] = $entry;

        $newJsonSamples = json_encode($samples);

        // Build a minimal merge map for Cypher SET s += {...}. We update
        // json_samples + modified_timestamp always, plus spot.name for rich
        // when name changed.
        $nowMs = (int)round(microtime(true) * 1000);
        $merge = array(
            'json_samples'        => $newJsonSamples,
            'modified_timestamp'  => $nowMs,
        );
        if ($isRich && array_key_exists('sample_id_name', $pushable) && $pushable['sample_id_name'] !== null && $pushable['sample_id_name'] !== '') {
            $merge['name'] = (string)$pushable['sample_id_name'];
        }

        $cypherMap = $this->neodb->escape(json_encode($merge));
        $this->neodb->query(
            "MATCH (s:Spot {id:$spotId, userpkey:$referenceUserpkey}) SET s += $cypherMap RETURN id(s)"
        );

        // Rebuild the Postgres `spot` mirror for the dataset that holds this
        // Spot. The mirror powers search hit cards + the full-text vectors
        // and is normally rebuilt from buildPgDataset() in the 8 Field upload
        // controller paths; writeback was the gap. Without this call, a
        // Samples-app spine edit leaves spot.spotjson + spot.vectors stale
        // until the next subsystem upload.
        $this->rebuildFieldPgMirror($spotId, $referenceUserpkey, $meta);

        if (!empty($translationNotes)) {
            $this->logChange($sampleId, $ownerPkey, 'writeback_translation',
                array('notes' => $translationNotes), 'samples_api');
        }

        return array(
            'ok'                => true,
            'wrote'             => true,
            'reference_id'      => $referenceId,
            'rich'              => $isRich,
            'translation_notes' => $translationNotes,
        );
    }

    /**
     * Best-effort rebuild of the PG `spot` mirror for the dataset that holds
     * a writeback-touched Spot. Pulls dataset_id from reference_metadata
     * first (set by db/lib/sample_sync.php at first-upload time), falls back
     * to a Cypher lookup. Failures are swallowed: the 8 existing upload-path
     * callers of buildPgDataset() don't propagate errors either, and a stale
     * search row is preferable to a 500 on a successful writeback.
     */
    protected function rebuildFieldPgMirror($spotId, $referenceUserpkey, $meta)
    {
        $datasetId = null;
        if (is_array($meta) && isset($meta['dataset_id']) && $meta['dataset_id'] !== '' && $meta['dataset_id'] !== null) {
            $datasetId = (string)$meta['dataset_id'];
        }
        if ($datasetId === null) {
            $rec = $this->neodb->getRecord(
                "MATCH (d:Dataset)-[:HAS_SPOT]->(s:Spot {id:$spotId, userpkey:$referenceUserpkey}) RETURN d.id AS did LIMIT 1"
            );
            if ($rec) {
                $did = $rec->get('did');
                if ($did !== null && $did !== '') $datasetId = (string)$did;
            }
        }
        if ($datasetId === null || !ctype_digit($datasetId)) return;

        try {
            require_once __DIR__ . '/../../db/strabospotclass.php';
            // buildPgDataset uses geoPHP for WKT parsing. The legacy /db/
            // entry point loads geoPHP via prepare_connections.php, but the
            // samplesdb/ entry points don't — so pull it in defensively.
            if (!class_exists('geoPHP')) {
                require_once __DIR__ . '/../../includes/geophp/geoPHP.inc';
            }
            // userpkey arg is ignored when ownerPkey is passed explicitly to
            // buildPgDataset; pass 0 placeholder.
            $strabo = new StraboSpot($this->neodb, 0, $this->db);
            $strabo->buildPgDataset((int)$datasetId, (int)$referenceUserpkey);
        } catch (\Throwable $e) {
            // Intentionally swallowed — match the no-error-propagation behavior
            // of the existing upload-path callers.
        }
    }
}
