<?php
/**
 * File: StraboSearchService.php
 * Description: StraboSearch service layer behind /searchdb/ and
 *              /searchdb/jwt/. Owns endpoint orchestration: DSL
 *              normalization + validation (SearchQueryBuilder does the
 *              SQL), pathway dispatch + counterpart badge counts
 *              (§5.4.1), the initial-state facet feed, per-facet vocab
 *              feeds, and saved-search CRUD (§6.6).
 *
 *              PG-only — search never touches Neo4j at query time
 *              (§3.1: index, don't crawl).
 *
 * @package    StraboSpot Web Site — StraboSearch
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once(__DIR__ . '/SearchQueryBuilder.php');

class StraboSearchService
{
    private $db;
    private $userpkey;   // 0 = anonymous (§5.5.3)
    private $qb;

    public function __construct($db, $userpkey)
    {
        $this->db = $db;
        $this->userpkey = (int)$userpkey;
        $this->qb = new SearchQueryBuilder($db, $this->userpkey);
    }

    public function isAnonymous()
    {
        return $this->userpkey <= 0;
    }

    /** stdClass-laden request parameters → pure assoc arrays. */
    private static function assoc($v)
    {
        return json_decode(json_encode($v), true);
    }

    // ═══════════════════════════════════════════════════════════════════
    // POST /searchdb/search
    // ═══════════════════════════════════════════════════════════════════

    public function runSearch($params)
    {
        $dsl = $this->qb->validate(self::assoc($params));

        if ($dsl['pathway'] === 'both') {
            $proj = $this->projectsResponse($dsl);
            $img  = $this->imagesResponse($dsl);
            // Each block already carries the other side's total.
            return array(
                'pathway'  => 'both',
                'projects' => $proj,
                'images'   => $img,
            );
        }
        return ($dsl['pathway'] === 'images')
            ? $this->imagesResponse($dsl)
            : $this->projectsResponse($dsl);
    }

    private function projectsResponse($dsl)
    {
        $r = $this->qb->runProjectsQuery($dsl);
        return array(
            'pathway'           => 'projects',
            'total'             => $r['total'],
            'counterpart_total' => $this->qb->countImages($dsl),
            'page'              => $dsl['page'],
            'page_size'         => $dsl['page_size'],
            'sort'              => $r['sort'],
            'subsystem_summary' => $r['subsystem_summary'],
            'results'           => $r['results'],
            'facet_counts'      => $r['facet_counts'],
        );
    }

    private function imagesResponse($dsl)
    {
        $r = $this->qb->runImagesQuery($dsl);
        return array(
            'pathway'           => 'images',
            'total'             => $r['total'],
            'counterpart_total' => $this->qb->countProjects($dsl),
            'page'              => $dsl['page'],
            'page_size'         => $dsl['page_size'],
            'sort'              => $r['sort'],
            'results'           => $r['results'],
            'facet_counts'      => $r['facet_counts'],
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // GET /searchdb/facets — empty-search initial state (§5.4.3)
    // ═══════════════════════════════════════════════════════════════════

    /** criterion id → subsystem gate for the ?subsystems= filter. */
    private static $criterionSubsystem = array(
        'F' => 'field', 'M' => 'micro', 'E' => 'exp',
    );

    public function initialFacetCounts($subsystems)
    {
        $this->db->last_error = '';
        $rows = $this->db->get_results_prepared(
            "SELECT criterion_id, value, count FROM strabosearch.vocab_facet_counts
             ORDER BY criterion_id, count DESC", array());
        if ($this->db->last_error) {
            throw new SearchDslError('Facet feed failed — is vocab_facet_counts populated?');
        }

        $out = array();
        foreach ((array)$rows as $r) {
            $cid = $r->criterion_id;
            if ($subsystems !== null) {
                $prefix = substr($cid, 0, 1);
                if (isset(self::$criterionSubsystem[$prefix])
                    && !in_array(self::$criterionSubsystem[$prefix], $subsystems, true)) {
                    continue;
                }
            }
            if (!isset($out[$cid])) $out[$cid] = array();
            $out[$cid][$r->value] = (int)$r->count;
        }
        return array('facets' => $out);
    }

    // ═══════════════════════════════════════════════════════════════════
    // GET /searchdb/vocab/{facet}
    // ═══════════════════════════════════════════════════════════════════

    public function vocab($facet)
    {
        switch ($facet) {
            case 'rock_type':
                $rows = $this->db->get_results_prepared(
                    "SELECT path, parent_path, depth FROM strabosearch.vocab_rock_type
                     ORDER BY path", array());
                $out = array();
                foreach ((array)$rows as $r) {
                    $out[] = array('path' => $r->path, 'parent_path' => $r->parent_path,
                                   'depth' => (int)$r->depth);
                }
                return array('facet' => $facet, 'values' => $out);

            case 'tag_type':
                $rows = $this->db->get_results_prepared(
                    "SELECT raw_value, display_label FROM strabosearch.vocab_tag_type
                     WHERE subsystem = 'field' ORDER BY display_label", array());
                $out = array();
                foreach ((array)$rows as $r) {
                    $out[] = array('value' => $r->raw_value, 'label' => $r->display_label);
                }
                return array('facet' => $facet, 'values' => $out);

            case 'image_type':
                $rows = $this->db->get_results_prepared(
                    "SELECT DISTINCT unified_value FROM strabosearch.vocab_image_type
                     ORDER BY unified_value", array());
                $out = array();
                foreach ((array)$rows as $r) $out[] = $r->unified_value;
                return array('facet' => $facet, 'values' => $out);

            case 'owner':
                return $this->ownerVocab();

            default:
                // Observed-value facets ride the pre-aggregated counts
                // table under their criterion id.
                $map = array(
                    'sample_type'      => 'U7T', 'sample_purpose' => 'U7P',
                    'feature_type'     => 'F5',  'met_facies'     => 'F8',
                    'trace_type'       => 'F9',  'mineral'        => 'M1',
                    'mineral_method'   => 'M2',  'instrument_type' => 'M3',
                    'detector_type'    => 'M4',  'apparatus_type' => 'E1',
                    'daq_sensor_type'  => 'E2',  'measurement_type' => 'E3',
                );
                if (!isset($map[$facet])) {
                    throw new SearchDslError("Unknown vocab facet '$facet'.");
                }
                $rows = $this->db->get_results_prepared(
                    "SELECT value, count FROM strabosearch.vocab_facet_counts
                     WHERE criterion_id = $1 ORDER BY count DESC, value", array($map[$facet]));
                $out = array();
                foreach ((array)$rows as $r) {
                    $out[] = array('value' => $r->value, 'count' => (int)$r->count);
                }
                return array('facet' => $facet, 'values' => $out);
        }
    }

    /**
     * U4 owner dropdown (§4.3): owners the searcher can see — self,
     * public-project owners, and owners of projects shared with them.
     * Index-only distinct over the denormalized owner column.
     */
    private function ownerVocab()
    {
        if ($this->isAnonymous()) {
            $sql = "SELECT DISTINCT project_userpkey FROM strabosearch.item_hit
                    WHERE project_ispublic = TRUE";
            $params = array();
        } else {
            $sql = "SELECT DISTINCT project_userpkey FROM strabosearch.item_hit
                    WHERE project_ispublic = TRUE OR project_userpkey = $1
                    UNION
                    SELECT project_owner_user_pkey FROM collaborators
                    WHERE collaborator_user_pkey = $1 AND accepted = TRUE AND disabled = FALSE";
            $params = array($this->userpkey);
        }
        $this->db->last_error = '';
        $rows = $this->db->get_results_prepared(
            "SELECT u.pkey, trim(coalesce(u.firstname, '') || ' ' || coalesce(u.lastname, '')) AS name
             FROM users u WHERE u.pkey IN ($sql) AND u.deleted = FALSE
             ORDER BY lower(trim(coalesce(u.firstname, '') || ' ' || coalesce(u.lastname, '')))", $params);
        if ($this->db->last_error) {
            throw new SearchDslError('Owner vocab failed.');
        }
        $out = array();
        foreach ((array)$rows as $r) {
            $out[] = array('pkey' => (int)$r->pkey, 'name' => $r->name);
        }
        return array('facet' => 'owner', 'values' => $out);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Saved searches (§6.6) — login required (controllers gate anonymous)
    // ═══════════════════════════════════════════════════════════════════

    public function listSavedSearches()
    {
        $rows = $this->db->get_results_prepared(
            "SELECT saved_search_pkey, search_name, dsl_json, created_at, modified_at
             FROM strabosearch.saved_search WHERE user_pkey = $1
             ORDER BY lower(search_name)", array($this->userpkey));
        $out = array();
        foreach ((array)$rows as $r) {
            $out[] = array(
                'saved_search_pkey' => (int)$r->saved_search_pkey,
                'search_name'       => $r->search_name,
                'dsl'               => json_decode($r->dsl_json, true),
                'created_at'        => $r->created_at,
                'modified_at'       => $r->modified_at,
            );
        }
        return array('saved' => $out);
    }

    public function createSavedSearch($params)
    {
        $p = self::assoc($params);
        $name = isset($p['search_name']) ? trim((string)$p['search_name']) : '';
        if ($name === '' || strlen($name) > 200) {
            throw new SearchDslError('search_name is required (≤200 chars).');
        }
        if (!isset($p['dsl']) || !is_array($p['dsl'])) {
            throw new SearchDslError('dsl object is required.');
        }
        // Persist only a DSL that validates — a saved search must replay.
        $dsl = $this->qb->validate($p['dsl']);

        // No INSERT ... RETURNING — the db wrapper's prepare_query classes
        // the statement as an insert and never captures returned rows.
        $this->db->last_error = '';
        $this->db->prepare_query(
            "INSERT INTO strabosearch.saved_search (user_pkey, search_name, dsl_json)
             VALUES ($1, $2, $3)
             ON CONFLICT (user_pkey, search_name)
             DO UPDATE SET dsl_json = EXCLUDED.dsl_json, modified_at = now()",
            array($this->userpkey, $name, json_encode($dsl)));
        if ($this->db->last_error) {
            throw new SearchDslError('Could not save search.');
        }
        $pkey = $this->db->get_var_prepared(
            "SELECT saved_search_pkey FROM strabosearch.saved_search
             WHERE user_pkey = $1 AND search_name = $2", array($this->userpkey, $name));
        return array('saved_search_pkey' => (int)$pkey, 'search_name' => $name);
    }

    public function updateSavedSearch($pkey, $params)
    {
        $p = self::assoc($params);
        $pkey = (int)$pkey;

        $existing = $this->db->get_row_prepared(
            "SELECT saved_search_pkey FROM strabosearch.saved_search
             WHERE saved_search_pkey = $1 AND user_pkey = $2",
            array($pkey, $this->userpkey));
        if (!$existing) {
            header("Not Found", true, 404);
            return array('Error' => 'No such saved search.');
        }

        $sets = array();
        $args = array();
        if (isset($p['search_name'])) {
            $name = trim((string)$p['search_name']);
            if ($name === '' || strlen($name) > 200) {
                throw new SearchDslError('search_name must be 1–200 chars.');
            }
            $args[] = $name;
            $sets[] = 'search_name = $' . count($args);
        }
        if (isset($p['dsl'])) {
            if (!is_array($p['dsl'])) throw new SearchDslError('dsl must be an object.');
            $dsl = $this->qb->validate($p['dsl']);
            $args[] = json_encode($dsl);
            $sets[] = 'dsl_json = $' . count($args);
        }
        if (!$sets) {
            throw new SearchDslError('Nothing to update — provide search_name and/or dsl.');
        }
        $args[] = $pkey;
        $pkeyPh = '$' . count($args);
        $args[] = $this->userpkey;
        $upkPh = '$' . count($args);

        $this->db->last_error = '';
        $this->db->prepare_query(
            "UPDATE strabosearch.saved_search SET " . implode(', ', $sets) . ", modified_at = now()
             WHERE saved_search_pkey = $pkeyPh AND user_pkey = $upkPh", $args);
        if ($this->db->last_error) {
            // Unique (user_pkey, search_name) collision is the plausible case.
            throw new SearchDslError('Could not update — is the name already in use?');
        }
        return array('updated' => $pkey);
    }

    public function deleteSavedSearch($pkey)
    {
        $pkey = (int)$pkey;
        $this->db->prepare_query(
            "DELETE FROM strabosearch.saved_search
             WHERE saved_search_pkey = $1 AND user_pkey = $2",
            array($pkey, $this->userpkey));
        if ((int)$this->db->rows_affected < 1) {
            header("Not Found", true, 404);
            return array('Error' => 'No such saved search.');
        }
        return array('deleted' => $pkey);
    }
}
