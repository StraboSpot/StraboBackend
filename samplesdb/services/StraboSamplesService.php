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


class StraboSamplesService
{
    protected $db;
    protected $neodb;
    protected $userpkey;
    protected $uuid;

    public function __construct($db, $neodb)
    {
        $this->db = $db;
        $this->neodb = $neodb;
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
     * Returns true if the caller (userpkey) can read the sample identified
     * by ($id, $ownerPkey). Read access = owner OR accepted, non-removed
     * collaborator grant (edit or readonly). Per design §7.1.
     */
    public function canRead($id, $ownerPkey)
    {
        $ownerPkey = (int)$ownerPkey;
        if ($ownerPkey === $this->userpkey) {
            // Caller owns this sample — read access is implicit. Confirm
            // the row exists.
            $exists = $this->db->get_var_prepared(
                "SELECT 1 FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
                array($id, $ownerPkey)
            );
            return $exists ? true : false;
        }

        $grant = $this->db->get_var_prepared(
            "SELECT 1 FROM strabosamples.sample_collaborators
              WHERE sample_id=$1 AND sample_userpkey=$2
                AND collaborator_pkey=$3
                AND accepted = TRUE AND removed_at IS NULL
              LIMIT 1",
            array($id, $ownerPkey, $this->userpkey)
        );
        return $grant ? true : false;
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
                    'accepted'          => (bool)$r->accepted,
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
}
