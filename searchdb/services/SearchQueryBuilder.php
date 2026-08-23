<?php
/**
 * File: SearchQueryBuilder.php
 * Description: StraboSearch §4.4 query-DSL → SQL translation. The single
 *              place that knows the criteria catalog (§4.2), the
 *              per-family operator semantics (§4.3), the composition
 *              rules (§4.4: AND across rows, OR within a row, per-row
 *              NOT), and the §5.5 access-control clause. Everything is
 *              emitted as prepared-statement SQL — user values ONLY ever
 *              travel through $1..$n parameters (the 0.1 autopsy's
 *              carry-forward DON'T: the legacy fullsearch interpolated
 *              constraint values raw and was pre-auth injectable).
 *
 *              DSL value shapes accepted per criterion (documented in
 *              DESIGN_PROPOSAL.md §5.4.1 as-built):
 *
 *                U1  string                          keyword (websearch syntax:
 *                                                    "quoted phrases", -exclusion)
 *                U2  {"bbox":[w,s,e,n]} | GeoJSON    Polygon / MultiPolygon
 *                U3  {"year":2024} | {"min":"Y-m-d","max":"Y-m-d"}  (either bound)
 *                U4  int | [int,...]                 owner pkey(s)
 *                U5  "text" | {"text":..,"exact":b}  sample name/id, prefix default
 *                U6  "text" | {"text":..,"exact":b}  IGSN, prefix default
 *                U7  {"sample_type":[..],"sample_purpose":[..]}   (either key)
 *                U9  ["orientation"|"samples"|"images"|"microstructure"|"strat",..]
 *                U10 string                          tag name keyword/prefix
 *                F1..F4 {"min":n,"max":n}            strike/trend wrap when min>max
 *                F5  [..]   F6 ["planar"|"linear",..]   F7 [paths..]  F8/F9 [..]
 *                F10 int                             shapegeology gid
 *                F11 [..]                            tag types
 *                M1/M2 [..]  M3/M4 [..]  E1/E2/E3 [..]
 *                I1  [..]    I2 bool    I3 string
 *
 *              U8 (subsystem filter) is the DSL's top-level `subsystems`
 *              array, not a criterion row. `op` on a criterion row is
 *              advisory — the semantic family fixes the operator; the one
 *              honored variant is op:"exact" on U5/U6 (else value.exact).
 *
 * @package    StraboSpot Web Site — StraboSearch
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class SearchDslError extends Exception
{
}

class SearchQueryBuilder
{
    /** Criteria catalog: id → [family, applies-to-item, applies-to-image]. */
    private static $catalog = array(
        'U1'  => array('fulltext',       true,  true),
        'U2'  => array('spatial',        true,  true),
        'U3'  => array('daterange',      true,  true),
        'U4'  => array('owner',          true,  true),
        'U5'  => array('sample_ident',   true,  true),
        'U6'  => array('igsn',           true,  true),
        'U7'  => array('sample_vocab',   true,  true),
        'U9'  => array('hasflags',       true,  true),
        'U10' => array('tagname',        true,  true),
        'F1'  => array('orange',         true,  true),   // strike
        'F2'  => array('orange',         true,  true),   // dip
        'F3'  => array('orange',         true,  true),   // trend
        'F4'  => array('orange',         true,  true),   // plunge
        'F5'  => array('vocab_array',    true,  true),
        'F6'  => array('planar_linear',  true,  true),
        'F7'  => array('rock_type',      true,  true),
        'F8'  => array('vocab_array',    true,  true),
        'F9'  => array('vocab_array',    true,  true),
        'F10' => array('province',       true,  true),
        'F11' => array('vocab_array',    true,  true),
        'M1'  => array('vocab_array',    true,  true),
        'M2'  => array('vocab_array',    true,  true),
        'M3'  => array('vocab_scalar',   true,  true),
        'M4'  => array('vocab_scalar',   true,  true),
        'E1'  => array('vocab_scalar',   true,  false),  // exp has no images
        'E2'  => array('vocab_scalar',   true,  false),
        'E3'  => array('vocab_scalar',   true,  false),
        'I1'  => array('image_type',     false, true),   // image-side; projects
        'I2'  => array('annotated',      false, true),   //   pathway applies them
        'I3'  => array('imagetext',      false, true),   //   via the image EXISTS
    );

    /** Column targets that differ per family/criterion. */
    private static $orangeCols = array(
        'F1' => array('orientation_strike', true),   // [column, wraparound]
        'F2' => array('orientation_dip',    false),
        'F3' => array('orientation_trend',  true),
        'F4' => array('orientation_plunge', false),
    );
    private static $vocabArrayCols = array(
        'F5'  => 'orientation_features',
        'F8'  => 'met_facies',
        'F9'  => 'trace_types',
        'F11' => 'tag_types',
        'M1'  => 'minerals',
        'M2'  => 'mineral_methods',
    );
    private static $vocabScalarCols = array(
        'M3' => 'instrument_type',
        'M4' => 'detector_type',
        'E1' => 'apparatus_type',
        'E2' => 'daq_sensor_type',
        'E3' => 'measurement_type',
    );

    private $db;
    private $userpkey;      // 0 = anonymous (§5.5.3)
    private $params;        // $1..$n values for the query being built

    public function __construct($db, $userpkey)
    {
        $this->db = $db;
        $this->userpkey = (int)$userpkey;
    }

    // ═══════════════════════════════════════════════════════════════════
    // DSL validation / normalization
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Validate + normalize an incoming DSL structure (assoc arrays; the
     * caller json-normalizes stdClass first). Throws SearchDslError with
     * a client-safe message. Returns the normalized DSL.
     */
    public function validate($dsl)
    {
        if (!is_array($dsl)) {
            throw new SearchDslError('Request body must be a JSON object.');
        }
        $out = array();

        $subsAll = array('field', 'micro', 'exp', 'samples');
        $subs = isset($dsl['subsystems']) ? $dsl['subsystems'] : $subsAll;
        if (!is_array($subs) || $subs === array()) $subs = $subsAll;
        $subs = array_values(array_unique(array_map('strval', $subs)));
        foreach ($subs as $s) {
            if (!in_array($s, $subsAll, true)) {
                throw new SearchDslError("Unknown subsystem '$s'.");
            }
        }
        $out['subsystems'] = $subs;

        $pathway = isset($dsl['pathway']) ? (string)$dsl['pathway'] : 'projects';
        if (!in_array($pathway, array('projects', 'images', 'both'), true)) {
            throw new SearchDslError("pathway must be 'projects', 'images' or 'both'.");
        }
        $out['pathway'] = $pathway;

        $criteria = isset($dsl['criteria']) ? $dsl['criteria'] : array();
        if (!is_array($criteria)) {
            throw new SearchDslError('criteria must be an array.');
        }
        if (count($criteria) > 25) {
            throw new SearchDslError('Too many criteria rows (max 25).');
        }
        $out['criteria'] = array();
        foreach (array_values($criteria) as $i => $c) {
            if (!is_array($c) || !isset($c['id'])) {
                throw new SearchDslError("criteria[$i] must be an object with an 'id'.");
            }
            $id = strtoupper((string)$c['id']);
            if (!isset(self::$catalog[$id])) {
                throw new SearchDslError("criteria[$i]: unknown criterion id '$id'.");
            }
            $norm = array(
                'id'    => $id,
                'value' => isset($c['value']) ? $c['value'] : null,
                'not'   => !empty($c['not']),
                'op'    => isset($c['op']) ? strtolower((string)$c['op']) : null,
            );
            $this->validateValue($i, $norm);
            $out['criteria'][] = $norm;
        }

        $page = isset($dsl['page']) ? (int)$dsl['page'] : 0;
        if ($page < 0) $page = 0;
        $out['page'] = $page;

        $ps = isset($dsl['page_size']) ? (int)$dsl['page_size'] : 25;
        if ($ps < 1)   $ps = 25;
        if ($ps > 100) $ps = 100;
        $out['page_size'] = $ps;

        $sort = isset($dsl['sort']) ? (string)$dsl['sort'] : null;
        if ($sort !== null && $sort !== ''
            && !in_array($sort, array('relevance', 'modified_desc', 'name_asc', 'owner_asc'), true)) {
            throw new SearchDslError("Unknown sort '$sort'.");
        }
        $out['sort'] = ($sort === '') ? null : $sort;

        return $out;
    }

    private function validateValue($i, &$c)
    {
        $fam = self::$catalog[$c['id']][0];
        $v = $c['value'];
        $err = function ($msg) use ($i, $c) {
            throw new SearchDslError("criteria[$i] ({$c['id']}): $msg");
        };

        switch ($fam) {
            case 'fulltext':
            case 'imagetext':
            case 'tagname':
                if (!is_string($v) || trim($v) === '') $err('value must be a non-empty string.');
                $c['value'] = trim($v);
                break;

            case 'spatial':
                if (is_array($v) && isset($v['bbox'])) {
                    $b = $v['bbox'];
                    if (!is_array($b) || count($b) !== 4) $err('bbox must be [w, s, e, n].');
                    foreach ($b as $n) if (!is_numeric($n)) $err('bbox values must be numeric.');
                    $c['value'] = array('bbox' => array_map('floatval', array_values($b)));
                } elseif (is_array($v) && isset($v['type'])
                          && in_array($v['type'], array('Polygon', 'MultiPolygon'), true)
                          && isset($v['coordinates']) && is_array($v['coordinates'])) {
                    // Re-encoded verbatim into ST_GeomFromGeoJSON as a bound
                    // parameter — PostGIS does the deep validation.
                    $c['value'] = array('geojson' => json_encode(array(
                        'type' => $v['type'], 'coordinates' => $v['coordinates'])));
                } else {
                    $err('value must be {"bbox":[w,s,e,n]} or a GeoJSON Polygon/MultiPolygon.');
                }
                break;

            case 'daterange':
                if (!is_array($v)) $err('value must be an object.');
                $min = null; $max = null;
                if (isset($v['year'])) {
                    if (!preg_match('/^\d{4}$/', (string)$v['year'])) $err('year must be YYYY.');
                    $min = $v['year'] . '-01-01';
                    $max = $v['year'] . '-12-31';
                } else {
                    foreach (array('min', 'max') as $k) {
                        if (isset($v[$k]) && $v[$k] !== '' && $v[$k] !== null) {
                            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v[$k])) {
                                $err("$k must be YYYY-MM-DD.");
                            }
                            ${$k} = (string)$v[$k];
                        }
                    }
                }
                if ($min === null && $max === null) $err('provide year, min or max.');
                $c['value'] = array('min' => $min, 'max' => $max);
                break;

            case 'owner':
                $list = is_array($v) ? $v : array($v);
                if ($list === array()) $err('provide at least one owner pkey.');
                foreach ($list as $p) if (!is_numeric($p)) $err('owner pkeys must be numeric.');
                $c['value'] = array_values(array_unique(array_map('intval', $list)));
                break;

            case 'sample_ident':
            case 'igsn':
                $text  = is_array($v) ? (isset($v['text']) ? $v['text'] : '') : $v;
                $exact = (is_array($v) && !empty($v['exact'])) || $c['op'] === 'exact';
                if (!is_string($text) || trim($text) === '') $err('value must carry non-empty text.');
                $c['value'] = array('text' => trim($text), 'exact' => $exact);
                break;

            case 'sample_vocab':
                if (!is_array($v)) $err('value must be an object.');
                $norm = array();
                foreach (array('sample_type', 'sample_purpose') as $k) {
                    if (isset($v[$k])) {
                        if (!is_array($v[$k]) || $v[$k] === array()) $err("$k must be a non-empty array.");
                        $norm[$k] = array_values(array_map('strval', $v[$k]));
                    }
                }
                if ($norm === array()) $err('provide sample_type and/or sample_purpose.');
                $c['value'] = $norm;
                break;

            case 'hasflags':
                $flags = is_array($v) ? $v : array($v);
                $known = array('orientation', 'samples', 'images', 'microstructure', 'strat');
                if ($flags === array()) $err('provide at least one flag.');
                foreach ($flags as $f) {
                    if (!in_array($f, $known, true)) $err("unknown flag '$f'.");
                }
                $c['value'] = array_values(array_unique($flags));
                break;

            case 'orange':
                if (!is_array($v)) $err('value must be {"min":n,"max":n}.');
                $min = isset($v['min']) && $v['min'] !== '' && $v['min'] !== null ? $v['min'] : null;
                $max = isset($v['max']) && $v['max'] !== '' && $v['max'] !== null ? $v['max'] : null;
                if ($min === null && $max === null) $err('provide min and/or max.');
                foreach (array($min, $max) as $n) {
                    if ($n !== null && !is_numeric($n)) $err('min/max must be numeric.');
                }
                $c['value'] = array(
                    'min' => $min === null ? null : (float)$min,
                    'max' => $max === null ? null : (float)$max);
                break;

            case 'planar_linear':
                $list = is_array($v) ? $v : array($v);
                foreach ($list as $x) {
                    if (!in_array($x, array('planar', 'linear'), true)) {
                        $err("values must be 'planar' or 'linear'.");
                    }
                }
                if ($list === array()) $err('provide at least one value.');
                $c['value'] = array_values(array_unique($list));
                break;

            case 'vocab_array':
            case 'vocab_scalar':
            case 'rock_type':
            case 'image_type':
                $list = is_array($v) ? $v : array($v);
                if ($list === array()) $err('provide at least one value.');
                foreach ($list as $x) {
                    if (!is_string($x) && !is_numeric($x)) $err('values must be strings.');
                }
                $c['value'] = array_values(array_unique(array_map('strval', $list)));
                break;

            case 'province':
                if (!is_numeric($v)) $err('value must be a shapegeology gid.');
                $c['value'] = (int)$v;
                break;

            case 'annotated':
                $c['value'] = !empty($v);
                break;

            default:
                $err('unhandled family — internal registry error.');
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Param helpers
    // ═══════════════════════════════════════════════════════════════════

    private function resetParams()
    {
        $this->params = array();
    }

    private function bind($v)
    {
        $this->params[] = $v;
        return '$' . count($this->params);
    }

    /**
     * Run the built query with the accumulated params. last_error is
     * STICKY on the db wrapper (flush() never clears it) — clear it first
     * so the post-run check only sees THIS query's outcome. Throws unless
     * $bestEffort (facet recounts degrade gracefully; a search never
     * silently returns partial rows).
     */
    private function execPrepared($sql, $bestEffort = false)
    {
        $this->db->last_error = '';
        $rows = $this->db->get_results_prepared($sql, $this->params);
        if ($this->db->last_error) {
            if ($bestEffort) return null;
            error_log('[strabosearch-api] query failed: ' . $this->db->last_error);
            throw new SearchDslError('Search failed — malformed criterion value or internal error.');
        }
        return (array)$rows;
    }

    /** PG array-literal string from a PHP list (bound as ::text[] etc.). */
    private static function pgArrayLiteral($list)
    {
        $out = array();
        foreach ($list as $v) {
            $out[] = '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$v) . '"';
        }
        return '{' . implode(',', $out) . '}';
    }

    /** Escape LIKE/ILIKE wildcards inside a user-supplied prefix. */
    private static function likeEscape($s)
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $s);
    }

    // ═══════════════════════════════════════════════════════════════════
    // WHERE assembly
    // ═══════════════════════════════════════════════════════════════════

    /** §5.5 ACL clause for any hit-table alias. */
    private function aclClause($alias)
    {
        if ($this->userpkey <= 0) {
            return "$alias.project_ispublic = TRUE";
        }
        $me = $this->bind($this->userpkey);
        return "($alias.project_ispublic = TRUE"
            . " OR $alias.project_userpkey = $me::int"
            . " OR EXISTS (SELECT 1 FROM collaborators c"
            . "   WHERE c.strabo_project_id = $alias.project_id"
            . "     AND c.project_owner_user_pkey = $alias.project_userpkey"
            . "     AND c.collaborator_user_pkey = $me::int"
            . "     AND c.accepted = TRUE AND c.disabled = FALSE))";
    }

    /** Top-level U8 subsystem filter. */
    private function subsystemClause($alias, $dsl, $isImage)
    {
        $subs = $dsl['subsystems'];
        if (count($subs) === 4) return null;   // unconstrained

        $parts = array();
        $plain = array_values(array_diff($subs, array('samples')));
        if ($isImage) {
            // image_hit only holds field + micro rows.
            $plain = array_values(array_intersect($plain, array('field', 'micro')));
            if ($plain) {
                $parts[] = "$alias.project_subsystem = ANY(" . $this->bind(self::pgArrayLiteral($plain)) . "::text[])";
            }
            if (in_array('samples', $subs, true)) {
                // §4.2 U8="Samples" on the Images pathway: images whose
                // parent chain crosses the sample spine.
                $parts[] = "$alias.parent_sample_id IS NOT NULL";
            }
        } else {
            if ($plain) {
                $parts[] = "$alias.project_subsystem = ANY(" . $this->bind(self::pgArrayLiteral($plain)) . "::text[])";
            }
            if (in_array('samples', $subs, true)) {
                // §4.2: U8="Samples" matches any sample-spine fan-out row
                // regardless of host subsystem.
                $parts[] = "$alias.item_type = 'sample'";
            }
        }
        if (!$parts) {
            // e.g. subsystems=["exp"] on the Images pathway — nothing can match.
            return 'FALSE';
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Full WHERE for item_hit (alias ih). Returns
     *   array($whereSql, $hasTextCriterion)
     * $skipIdx: criterion index excluded (facet-count self-exclusion).
     */
    private function itemWhere($dsl, $skipIdx = null)
    {
        $conds = array($this->aclClause('ih'));
        if (($s = $this->subsystemClause('ih', $dsl, false)) !== null) $conds[] = $s;

        $hasText = false;
        $imageSide = array();
        foreach ($dsl['criteria'] as $i => $c) {
            if ($i === $skipIdx) continue;
            list(, $onItem, $onImage) = self::$catalog[$c['id']];
            if (!$onItem) { $imageSide[] = $c; continue; }
            $pred = $this->criterionPredicate('ih', $c, false);
            if (in_array(self::$catalog[$c['id']][0], array('fulltext'), true)) $hasText = true;
            $conds[] = $this->wrapNot($pred, $c['not']);
        }

        // Image-side criteria on the Projects pathway (§4.2 pathway tag I):
        // the project must have ≥1 image row matching the FULL image-side
        // WHERE (its own I-criteria plus every inherited criterion), so all
        // downstream aggregates stay consistent with the filter.
        if ($imageSide) {
            $imgW = $this->imageWhereSql($dsl, $skipIdx);
            $conds[] = "EXISTS (SELECT 1 FROM strabosearch.image_hit im"
                . " WHERE im.project_id = ih.project_id"
                . "   AND im.project_userpkey = ih.project_userpkey"
                . "   AND $imgW)";
        }

        return array(implode("\n  AND ", $conds), $hasText);
    }

    /** Full WHERE for image_hit (alias im), as a string. */
    private function imageWhereSql($dsl, $skipIdx = null)
    {
        list($w) = $this->imageWhere($dsl, $skipIdx);
        return "(" . str_replace("\n", ' ', $w) . ")";
    }

    /**
     * Full WHERE for image_hit (alias im). Returns
     *   array($whereSql, $hasTextCriterion)
     */
    private function imageWhere($dsl, $skipIdx = null)
    {
        $conds = array($this->aclClause('im'));
        if (($s = $this->subsystemClause('im', $dsl, true)) !== null) $conds[] = $s;

        $hasText = false;
        foreach ($dsl['criteria'] as $i => $c) {
            if ($i === $skipIdx) continue;
            list($fam, , $onImage) = self::$catalog[$c['id']];
            if (!$onImage) {
                // Not expressible on any image row (E1–E3): the criterion
                // can never be satisfied on this pathway.
                $conds[] = $this->wrapNot('FALSE', $c['not']);
                continue;
            }
            $pred = $this->criterionPredicate('im', $c, true);
            if (in_array($fam, array('fulltext', 'imagetext'), true)) $hasText = true;
            $conds[] = $this->wrapNot($pred, $c['not']);
        }

        return array(implode("\n  AND ", $conds), $hasText);
    }

    private function wrapNot($pred, $not)
    {
        if (!$not) return "($pred)";
        // NULL-valued facets count as "does not match" → they DO match the
        // negation (complement semantics, documented §5.4.1 as-built).
        return "(NOT COALESCE(($pred), FALSE))";
    }

    /**
     * EXISTS against the image row's immediate-parent item row (Field:
     * parent spot; Micro: the micrograph item itself). $extra is ANDed
     * inside, referencing alias pi.
     */
    private function parentItemExists($alias, $extra)
    {
        return "EXISTS (SELECT 1 FROM strabosearch.item_hit pi"
            . " WHERE pi.project_id = $alias.project_id"
            . "   AND pi.project_userpkey = $alias.project_userpkey"
            . "   AND ((pi.item_type = 'spot' AND $alias.image_subsystem = 'field'"
            . "         AND pi.item_id = $alias.parent_spot_id)"
            . "     OR (pi.item_type = 'micrograph' AND $alias.image_subsystem = 'micro'"
            . "         AND pi.item_id = $alias.image_id))"
            . "   AND ($extra))";
    }

    /** One criterion row → SQL predicate over $alias. */
    private function criterionPredicate($alias, $c, $isImage)
    {
        $fam = self::$catalog[$c['id']][0];
        $v = $c['value'];

        switch ($fam) {
            case 'fulltext': {
                $q = $this->bind($v);
                if (!$isImage) {
                    return "$alias.searchtext_tsv @@ websearch_to_tsquery('english', $q)";
                }
                // Images inherit U1 through the parent chain (§4.2 note 1):
                // own text + own tags + the parent item's searchtext.
                return "($alias.imagetext_tsv @@ websearch_to_tsquery('english', $q)"
                    . " OR $alias.tag_text_tsv @@ websearch_to_tsquery('english', $q)"
                    . " OR " . $this->parentItemExists($alias,
                        "pi.searchtext_tsv @@ websearch_to_tsquery('english', $q)") . ")";
            }

            case 'imagetext': {
                $q = $this->bind($v);
                return "$alias.imagetext_tsv @@ websearch_to_tsquery('english', $q)";
            }

            case 'tagname': {
                $q = $this->bind($v);
                $p = $this->bind(self::likeEscape($v) . '%');
                return "($alias.tag_text_tsv @@ websearch_to_tsquery('english', $q)"
                    . " OR EXISTS (SELECT 1 FROM unnest(coalesce($alias.tag_names, '{}'::text[])) tn"
                    . "            WHERE tn ILIKE $p))";
            }

            case 'spatial': {
                if (isset($v['bbox'])) {
                    $b = $v['bbox'];
                    $geom = "ST_MakeEnvelope(" . $this->bind($b[0]) . "::float8, " . $this->bind($b[1]) . "::float8, "
                        . $this->bind($b[2]) . "::float8, " . $this->bind($b[3]) . "::float8, 4326)";
                } else {
                    $geom = "ST_SetSRID(ST_GeomFromGeoJSON(" . $this->bind($v['geojson']) . "), 4326)";
                }
                return "ST_Within($alias.location, $geom)";
            }

            case 'province': {
                $gid = $this->bind($v);
                return "ST_Contains((SELECT ST_SetSRID(sg.the_geom, 4326) FROM shapegeology sg"
                    . " WHERE sg.gid = $gid::int), $alias.location)";
            }

            case 'daterange': {
                $parts = array();
                if ($v['min'] !== null) $parts[] = "$alias.date_value >= " . $this->bind($v['min']) . "::date";
                if ($v['max'] !== null) $parts[] = "$alias.date_value <= " . $this->bind($v['max']) . "::date";
                return implode(' AND ', $parts);
            }

            case 'owner': {
                return "$alias.project_userpkey = ANY(" . $this->bind('{' . implode(',', $v) . '}') . "::int[])";
            }

            case 'sample_ident': {
                if (!$isImage) {
                    if ($v['exact']) {
                        $t = $this->bind($v['text']);
                        return "(lower($alias.sample_id) = lower($t) OR lower($alias.sample_name) = lower($t))";
                    }
                    $p = $this->bind(self::likeEscape($v['text']) . '%');
                    return "($alias.sample_id ILIKE $p OR $alias.sample_name ILIKE $p)";
                }
                return $this->imageSampleExists($alias, $v, 'ident');
            }

            case 'igsn': {
                if (!$isImage) {
                    if ($v['exact']) {
                        return "lower($alias.igsn) = lower(" . $this->bind($v['text']) . ")";
                    }
                    return "$alias.igsn ILIKE " . $this->bind(self::likeEscape($v['text']) . '%');
                }
                return $this->imageSampleExists($alias, $v, 'igsn');
            }

            case 'sample_vocab': {
                if (!$isImage) {
                    $parts = array();
                    if (isset($v['sample_type'])) {
                        $parts[] = "$alias.display_sample_type = ANY("
                            . $this->bind(self::pgArrayLiteral($v['sample_type'])) . "::text[])";
                    }
                    if (isset($v['sample_purpose'])) {
                        $parts[] = "$alias.display_sample_purpose = ANY("
                            . $this->bind(self::pgArrayLiteral($v['sample_purpose'])) . "::text[])";
                    }
                    return implode(' AND ', $parts);
                }
                return $this->imageSampleExists($alias, $v, 'vocab');
            }

            case 'hasflags': {
                if (!$isImage) {
                    $parts = array();
                    foreach ($v as $f) $parts[] = "$alias.has_" . $f . " = TRUE";  // vetted enum
                    return implode(' AND ', $parts);
                }
                $parts = array();
                foreach ($v as $f) $parts[] = "pi.has_" . $f . " = TRUE";
                return $this->parentItemExists($alias, implode(' AND ', $parts));
            }

            case 'orange': {
                list($col, $wraps) = self::$orangeCols[$c['id']];
                $min = $v['min']; $max = $v['max'];
                if ($min !== null && $max !== null) {
                    if ($wraps && $min > $max) {
                        $inner = "e >= " . $this->bind($min) . "::numeric OR e <= " . $this->bind($max) . "::numeric";
                    } else {
                        $inner = "e >= " . $this->bind(min($min, $max)) . "::numeric AND e <= " . $this->bind(max($min, $max)) . "::numeric";
                    }
                } elseif ($min !== null) {
                    $inner = "e >= " . $this->bind($min) . "::numeric";
                } else {
                    $inner = "e <= " . $this->bind($max) . "::numeric";
                }
                return "EXISTS (SELECT 1 FROM unnest(coalesce($alias.$col, '{}'::numeric[])) e WHERE $inner)";
            }

            case 'planar_linear': {
                $lits = array();
                foreach ($v as $x) $lits[] = ($x === 'planar') ? 't' : 'f';
                return "$alias.orientation_planar && " . $this->bind('{' . implode(',', array_unique($lits)) . '}') . "::boolean[]";
            }

            case 'vocab_array': {
                $col = self::$vocabArrayCols[$c['id']];
                return "$alias.$col && " . $this->bind(self::pgArrayLiteral($v)) . "::text[]";
            }

            case 'vocab_scalar': {
                $col = self::$vocabScalarCols[$c['id']];
                return "$alias.$col = ANY(" . $this->bind(self::pgArrayLiteral($v)) . "::text[])";
            }

            case 'rock_type': {
                // §5.1.5 strategy (c): expand each selected path to itself +
                // all vocab descendants, then a GIN-indexable array overlap.
                $expanded = $this->expandRockTypePaths($v);
                return "$alias.rock_types && " . $this->bind(self::pgArrayLiteral($expanded)) . "::text[]";
            }

            case 'image_type': {
                return "$alias.image_type = ANY(" . $this->bind(self::pgArrayLiteral($v)) . "::text[])";
            }

            case 'annotated': {
                return $v ? "$alias.annotated = TRUE" : "COALESCE($alias.annotated, FALSE) = FALSE";
            }
        }
        throw new SearchDslError("Internal: no predicate builder for family '$fam'.");
    }

    /**
     * U5/U6/U7 on the Images pathway: the sample facets are not stamped
     * onto image_hit — route through the parent sample's spine row(s) in
     * item_hit via parent_sample_id (§4.2 note 3: sample criteria route
     * through the spine).
     */
    private function imageSampleExists($alias, $v, $mode)
    {
        $inner = array(
            "si.item_type = 'sample'",
            "si.sample_id = $alias.parent_sample_id",
            "si.item_userpkey = $alias.image_userpkey",
        );
        if ($mode === 'ident') {
            if ($v['exact']) {
                $t = $this->bind($v['text']);
                $inner[] = "(lower(si.sample_id) = lower($t) OR lower(si.sample_name) = lower($t))";
            } else {
                $p = $this->bind(self::likeEscape($v['text']) . '%');
                $inner[] = "(si.sample_id ILIKE $p OR si.sample_name ILIKE $p)";
            }
        } elseif ($mode === 'igsn') {
            if ($v['exact']) {
                $inner[] = "lower(si.igsn) = lower(" . $this->bind($v['text']) . ")";
            } else {
                $inner[] = "si.igsn ILIKE " . $this->bind(self::likeEscape($v['text']) . '%');
            }
        } else { // vocab
            if (isset($v['sample_type'])) {
                $inner[] = "si.display_sample_type = ANY("
                    . $this->bind(self::pgArrayLiteral($v['sample_type'])) . "::text[])";
            }
            if (isset($v['sample_purpose'])) {
                $inner[] = "si.display_sample_purpose = ANY("
                    . $this->bind(self::pgArrayLiteral($v['sample_purpose'])) . "::text[])";
            }
        }
        return "($alias.parent_sample_id IS NOT NULL AND EXISTS ("
            . "SELECT 1 FROM strabosearch.item_hit si WHERE " . implode(' AND ', $inner) . "))";
    }

    /** F7: selected paths → self + all colon-descendants via vocab_rock_type. */
    private function expandRockTypePaths($paths)
    {
        $expanded = array();
        foreach ($paths as $p) {
            $expanded[$p] = true;
            $rows = $this->db->get_results_prepared(
                "SELECT path FROM strabosearch.vocab_rock_type"
                . " WHERE path = $1 OR path LIKE $2",
                array($p, self::likeEscape($p) . ':%'));
            foreach ((array)$rows as $r) $expanded[$r->path] = true;
        }
        return array_keys($expanded);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Sort
    // ═══════════════════════════════════════════════════════════════════

    /**
     * §5.4.2 smart default + ORDER BY resolution. Returns
     * array($effectiveSort, $orderBySql). $rankExpr non-null when a rank
     * column is available in the surrounding query.
     */
    private function resolveSort($requested, $hasText, $rankCol, $nameCol, $ownerCol, $modCol)
    {
        $sort = $requested;
        if ($sort === null) $sort = $hasText ? 'relevance' : 'modified_desc';
        if ($sort === 'relevance' && !$hasText) $sort = 'modified_desc';

        switch ($sort) {
            case 'relevance':
                $order = "$rankCol DESC NULLS LAST, $modCol DESC NULLS LAST";
                break;
            case 'name_asc':
                $order = "lower($nameCol) ASC NULLS LAST";
                break;
            case 'owner_asc':
                $order = "lower($ownerCol) ASC NULLS LAST";
                break;
            case 'modified_desc':
            default:
                $sort = 'modified_desc';
                $order = "$modCol DESC NULLS LAST";
        }
        return array($sort, $order);
    }

    /** The tsquery param for the first active text criterion (rank source). */
    private function textQueryValue($dsl, $isImage)
    {
        foreach ($dsl['criteria'] as $c) {
            $fam = self::$catalog[$c['id']][0];
            if ($fam === 'fulltext') return $c['value'];
            if ($isImage && $fam === 'imagetext') return $c['value'];
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Projects pathway (§5.4.1)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ONE statement per search (Phase 5.D): the paged project groups, the
     * §6.5.2 subsystem summary, and the Images counterpart badge all ride
     * a single scan of item_hit (matched CTE, materialized) plus a single
     * scan of image_hit (imgsum CTE — its per-subsystem counts sum to the
     * counterpart total). The pre-5.D shape re-ran the full WHERE as four
     * separate statements, which doubled every search and made the
     * GIN-unfriendly shapes (NOT text, browse-all) pay the big scan twice.
     * Results come back as one json payload row.
     */
    public function runProjectsQuery($dsl)
    {
        $this->resetParams();
        list($where, $hasText) = $this->itemWhere($dsl);

        $rankSel = 'NULL::real AS rank';
        $textVal = $this->textQueryValue($dsl, false);
        if ($hasText && $textVal !== null) {
            $rankSel = "ts_rank(ih.searchtext_tsv, websearch_to_tsquery('english', "
                . $this->bind($textVal) . ")) AS rank";
        }

        // Sort must target the owner EXPRESSION, not the output alias —
        // ORDER BY lower(alias) is invalid inside an expression.
        $ownerExpr = "trim(coalesce(u.firstname, '') || ' ' || coalesce(u.lastname, ''))";
        list($sortUsed, $orderBy) = $this->resolveSort(
            $dsl['sort'], $hasText, 'g.rank', 'g.project_name', $ownerExpr, 'g.last_modified');

        $limit  = $this->bind($dsl['page_size']);
        $offset = $this->bind($dsl['page'] * $dsl['page_size']);

        // Image-side WHERE (full DSL). The string is reused in two places
        // (imgsum + per-project c_image) — same param slots, bound once.
        $imgW = $this->imageWhereSql($dsl);

        $sql = "
WITH matched AS (
  SELECT ih.project_id, ih.project_userpkey, ih.project_subsystem,
         ih.project_name, ih.project_ispublic, ih.item_type, ih.date_value,
         ih.location, ih.source_modified, ih.dataset_ids, $rankSel
  FROM strabosearch.item_hit ih
  WHERE $where
),
groups AS (
  SELECT project_id, project_userpkey, project_subsystem,
         max(project_name)                                  AS project_name,
         bool_or(project_ispublic)                          AS project_ispublic,
         min(date_value)                                    AS date_min,
         max(date_value)                                    AS date_max,
         max(source_modified)                               AS last_modified,
         ST_Centroid(ST_Collect(location))                  AS centroid,
         max(rank)                                          AS rank,
         count(*) FILTER (WHERE item_type = 'spot')         AS c_spot,
         count(*) FILTER (WHERE item_type = 'sample')       AS c_sample,
         count(*) FILTER (WHERE item_type = 'experiment')   AS c_experiment,
         count(*) FILTER (WHERE item_type = 'micrograph')   AS c_micrograph
  FROM matched
  GROUP BY 1, 2, 3
),
dsc AS (
  -- One unnest pass, two granularities: per-project dataset counts for
  -- the page rows (glevel 0) + per-subsystem totals for the summary
  -- (glevel 1). Same count(DISTINCT d) semantics as the pre-5.D queries.
  SELECT project_id, project_userpkey, project_subsystem,
         count(DISTINCT d) AS c_dataset,
         array_agg(DISTINCT d) AS ds_ids,
         GROUPING(project_id) AS glevel
  FROM matched CROSS JOIN LATERAL unnest(dataset_ids) AS d
  GROUP BY GROUPING SETS ((project_id, project_userpkey, project_subsystem),
                          (project_subsystem))
),
imgsum AS (
  SELECT im.image_subsystem AS subsystem, count(*) AS n
  FROM strabosearch.image_hit im
  WHERE $imgW
  GROUP BY 1
),
page AS (
  SELECT g.project_id, g.project_userpkey, g.project_subsystem,
         g.project_name, g.project_ispublic,
         g.date_min::text AS date_min, g.date_max::text AS date_max,
         g.last_modified::text AS last_modified,
         ST_Y(g.centroid) AS centroid_lat, ST_X(g.centroid) AS centroid_lng,
         g.c_spot, g.c_sample, g.c_experiment, g.c_micrograph,
         coalesce(ds.c_dataset, 0) AS c_dataset,
         coalesce(ds.ds_ids, '{}'::text[]) AS dataset_ids,
         $ownerExpr AS owner_name,
         (SELECT count(*) FROM strabosearch.image_hit im
           WHERE im.project_id = g.project_id
             AND im.project_userpkey = g.project_userpkey
             AND $imgW) AS c_image
  FROM groups g
  LEFT JOIN dsc ds ON ds.glevel = 0
       AND ds.project_id = g.project_id
       AND ds.project_userpkey = g.project_userpkey
       AND ds.project_subsystem = g.project_subsystem
  LEFT JOIN users u ON u.pkey = g.project_userpkey
  ORDER BY $orderBy
  LIMIT $limit::int OFFSET $offset::int
),
sumitems AS (
  SELECT project_subsystem AS subsystem,
         count(*)          AS n_project,
         sum(c_spot)       AS n_spot,
         sum(c_sample)     AS n_sample,
         sum(c_experiment) AS n_experiment,
         sum(c_micrograph) AS n_micrograph
  FROM groups GROUP BY 1
)
SELECT json_build_object(
  'total',       (SELECT count(*) FROM groups),
  'page',        coalesce((SELECT json_agg(row_to_json(p)) FROM page p), '[]'::json),
  'items',       coalesce((SELECT json_agg(row_to_json(s)) FROM sumitems s), '[]'::json),
  'datasets',    coalesce((SELECT json_agg(json_build_object(
                     'subsystem', d.project_subsystem, 'n', d.c_dataset))
                     FROM dsc d WHERE d.glevel = 1), '[]'::json),
  'images',      coalesce((SELECT json_agg(row_to_json(i)) FROM imgsum i), '[]'::json),
  'counterpart', (SELECT coalesce(sum(n), 0) FROM imgsum)
)::text AS payload";

        $rows = $this->execPrepared($sql);
        $payload = $rows ? json_decode($rows[0]->payload, true) : null;
        if (!is_array($payload)) {
            throw new SearchDslError('Search failed — malformed result payload.');
        }

        $results = array();
        foreach ($payload['page'] as $p) {
            $results[] = array(
                'project_id'        => $p['project_id'],
                'project_userpkey'  => (int)$p['project_userpkey'],
                'project_subsystem' => $p['project_subsystem'],
                'project_name'      => $p['project_name'],
                'project_ispublic'  => (bool)$p['project_ispublic'],
                'owner_name'        => $p['owner_name'],
                'dataset_ids'       => is_array($p['dataset_ids']) ? $p['dataset_ids'] : array(),
                'date_range'        => array($p['date_min'], $p['date_max']),
                'last_modified'     => $p['last_modified'],
                'location_centroid' => ($p['centroid_lat'] !== null)
                    ? array('lat' => (float)$p['centroid_lat'], 'lng' => (float)$p['centroid_lng'])
                    : null,
                'match_counts'      => array(
                    'dataset'    => (int)$p['c_dataset'],
                    'spot'       => (int)$p['c_spot'],
                    'sample'     => (int)$p['c_sample'],
                    'experiment' => (int)$p['c_experiment'],
                    'micrograph' => (int)$p['c_micrograph'],
                    'image'      => (int)$p['c_image'],
                ),
            );
        }

        // Subsystem summary, same shape as the pre-5.D three-way UNION:
        // keys appear only when their count is non-zero (project always is).
        $summary = array();
        foreach ($payload['items'] as $s) {
            $ss = $s['subsystem'];
            if (!isset($summary[$ss])) $summary[$ss] = array();
            $summary[$ss]['project'] = (int)$s['n_project'];
            foreach (array('spot', 'sample', 'experiment', 'micrograph') as $t) {
                if ((int)$s['n_' . $t] > 0) $summary[$ss][$t] = (int)$s['n_' . $t];
            }
        }
        foreach ($payload['datasets'] as $d) {
            if ((int)$d['n'] > 0) $summary[$d['subsystem']]['dataset'] = (int)$d['n'];
        }
        foreach ($payload['images'] as $i) {
            if (!isset($summary[$i['subsystem']])) $summary[$i['subsystem']] = array();
            $summary[$i['subsystem']]['image'] = (int)$i['n'];
        }

        // ---- facet counts (§5.4.3) — per-criterion self-exclusion means
        // these cannot share the matched scan; still separate statements.
        $facets = $this->facetCounts($dsl, false);

        return array(
            'total'   => (int)$payload['total'],
            'results' => $results,
            'subsystem_summary' => $summary,
            'counterpart_total' => (int)$payload['counterpart'],
            'facet_counts' => $facets,
            'sort'    => $sortUsed,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Images pathway
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ONE statement per search (Phase 5.D, mirroring runProjectsQuery):
     * the paged image rows + total + the Projects counterpart badge in a
     * single round trip. The q CTE materializes only the columns the page
     * needs (the old SELECT im.* dragged both tsvectors through the
     * materialization).
     */
    public function runImagesQuery($dsl)
    {
        $this->resetParams();
        list($where, $hasText) = $this->imageWhere($dsl);

        $rankSel = 'NULL::real AS rank';
        $textVal = $this->textQueryValue($dsl, true);
        if ($hasText && $textVal !== null) {
            $rankSel = "ts_rank(coalesce(im.imagetext_tsv, ''::tsvector), websearch_to_tsquery('english', "
                . $this->bind($textVal) . ")) AS rank";
        }

        $ownerExpr = "trim(coalesce(u.firstname, '') || ' ' || coalesce(u.lastname, ''))";
        list($sortUsed, $orderBy) = $this->resolveSort(
            $dsl['sort'], $hasText, 'q.rank', 'q.title', $ownerExpr, 'q.source_modified');

        $limit  = $this->bind($dsl['page_size']);
        $offset = $this->bind($dsl['page'] * $dsl['page_size']);

        // Counterpart: item-side WHERE, full DSL, same param list.
        list($cpWhere) = $this->itemWhere($dsl);

        $sql = "
WITH q AS (
  SELECT im.image_hit_pkey, im.image_id, im.image_subsystem, im.image_userpkey,
         im.image_type, im.annotated, im.title, im.caption, im.filename,
         im.parent_spot_id, im.parent_sample_id,
         im.project_id, im.project_userpkey, im.project_subsystem,
         im.date_value, im.location, im.source_modified, $rankSel
  FROM strabosearch.image_hit im
  WHERE $where
),
page AS (
  SELECT q.image_hit_pkey,
         q.image_id, q.image_subsystem, q.image_userpkey, q.image_type,
         q.annotated, q.title, q.caption, q.filename,
         q.parent_spot_id, q.parent_sample_id,
         q.project_id, q.project_userpkey, q.project_subsystem,
         q.date_value::text AS date_value,
         ST_Y(q.location) AS lat, ST_X(q.location) AS lng,
         $ownerExpr AS owner_name,
         (SELECT max(pi.project_name) FROM strabosearch.item_hit pi
           WHERE pi.project_id = q.project_id
             AND pi.project_userpkey = q.project_userpkey
             AND pi.project_subsystem = q.project_subsystem) AS project_name
  FROM q
  LEFT JOIN users u ON u.pkey = q.project_userpkey
  ORDER BY $orderBy
  LIMIT $limit::int OFFSET $offset::int
)
SELECT json_build_object(
  'total',       (SELECT count(*) FROM q),
  'page',        coalesce((SELECT json_agg(row_to_json(p)) FROM page p), '[]'::json),
  'counterpart', (SELECT count(DISTINCT (ih.project_id, ih.project_userpkey, ih.project_subsystem))
                  FROM strabosearch.item_hit ih WHERE $cpWhere)
)::text AS payload";

        $rows = $this->execPrepared($sql);
        $payload = $rows ? json_decode($rows[0]->payload, true) : null;
        if (!is_array($payload)) {
            throw new SearchDslError('Search failed — malformed result payload.');
        }

        $results = array();
        foreach ($payload['page'] as $p) {
            $results[] = array(
                'image_hit_pkey'    => (int)$p['image_hit_pkey'],
                'image_id'          => $p['image_id'],
                'image_subsystem'   => $p['image_subsystem'],
                'image_type'        => $p['image_type'],
                'annotated'         => ($p['annotated'] === null) ? null : (bool)$p['annotated'],
                'title'             => $p['title'],
                'caption'           => $p['caption'],
                'filename'          => $p['filename'],
                'parent_spot_id'    => $p['parent_spot_id'],
                'parent_sample_id'  => $p['parent_sample_id'],
                'project_id'        => $p['project_id'],
                'project_userpkey'  => (int)$p['project_userpkey'],
                'project_subsystem' => $p['project_subsystem'],
                'project_name'      => $p['project_name'],
                'owner_name'        => $p['owner_name'],
                'date_value'        => $p['date_value'],
                'location'          => ($p['lat'] !== null)
                    ? array('lat' => (float)$p['lat'], 'lng' => (float)$p['lng']) : null,
            );
        }

        $facets = $this->facetCounts($dsl, true);

        return array(
            'total'   => (int)$payload['total'],
            'results' => $results,
            'counterpart_total' => (int)$payload['counterpart'],
            'facet_counts' => $facets,
            'sort'    => $sortUsed,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Geo pathway (Globe View M2 — GlobeView_Design_Proposal.md §4)
    // ═══════════════════════════════════════════════════════════════════

    /** Point mode engages at or below this many located rows in view. */
    const GEO_POINT_LIMIT = 1500;
    /** Bin rows returned per request, largest counts first. */
    const GEO_BIN_LIMIT = 5000;
    /** Server-side grid columns across the requested viewport. */
    const GEO_GRID_COLS = 48;

    /**
     * Validate + normalize the geo block of a search_geo request:
     *   {bbox: [w, s, e, n], include_counter?: bool}
     * bbox is the camera viewport; west > east means the viewport crosses
     * the antimeridian. Missing/oversized bbox degrades to the whole world.
     */
    public function validateGeo($geo)
    {
        $out = array(
            'bbox' => array(-180.0, -90.0, 180.0, 90.0),
            'include_counter' => false,
        );
        if (!is_array($geo)) return $out;

        if (isset($geo['bbox'])) {
            $b = $geo['bbox'];
            if (!is_array($b) || count($b) !== 4) {
                throw new SearchDslError('geo.bbox must be [w, s, e, n].');
            }
            foreach ($b as $n) {
                if (!is_numeric($n)) throw new SearchDslError('geo.bbox values must be numeric.');
            }
            $w = max(-180.0, min(180.0, (float)$b[0]));
            $s = max(-90.0,  min(90.0,  (float)$b[1]));
            $e = max(-180.0, min(180.0, (float)$b[2]));
            $n = max(-90.0,  min(90.0,  (float)$b[3]));
            if ($s > $n) { $t = $s; $s = $n; $n = $t; }
            $out['bbox'] = array($w, $s, $e, $n);
        }
        $out['include_counter'] = !empty($geo['include_counter']);
        return $out;
    }

    /** Viewport width in degrees, antimeridian-aware. */
    private static function geoBboxWidth($bbox)
    {
        list($w, , $e) = array($bbox[0], $bbox[1], $bbox[2]);
        return ($e >= $w) ? ($e - $w) : ((180.0 - $w) + ($e + 180.0));
    }

    /**
     * Viewport envelope predicate over $alias.location. A west > east bbox
     * (antimeridian crossing) splits into two OR'd envelopes — the grid
     * keys stay absolute so bins never straddle the seam.
     */
    private function geoEnvelopeClause($alias, $bbox)
    {
        list($w, $s, $e, $n) = $bbox;
        $env = function ($w1, $e1) use ($alias, $s, $n) {
            return "$alias.location && ST_MakeEnvelope("
                . $this->bind($w1) . "::float8, " . $this->bind($s) . "::float8, "
                . $this->bind($e1) . "::float8, " . $this->bind($n) . "::float8, 4326)";
        };
        if ($e >= $w) return '(' . $env($w, $e) . ')';
        return '(' . $env($w, 180.0) . ' OR ' . $env(-180.0, $e) . ')';
    }

    /**
     * Globe results for one viewport (§4 GlobeView proposal). Runs the DSL
     * WHERE (itemWhere — the same ACL + criteria the list view uses) over
     * located rows in the viewport:
     *   1. grid-bin aggregate (avg-centroid: 3.7x cheaper than
     *      ST_Centroid(ST_Collect()) on the match-all world view, 2026-08-23
     *      dev spike) — cell size = viewport width / GEO_GRID_COLS;
     *   2. if the viewport's located total ≤ GEO_POINT_LIMIT, a second
     *      cheap query swaps bins for real item points (project routing +
     *      popup-card fields).
     * The location counter (globally matched vs located, no bbox) is
     * computed only when geo.include_counter is set — the client asks once
     * per search, not on every camera move.
     */
    public function runGeoQuery($dsl, $geo)
    {
        $bbox = $geo['bbox'];
        // Cell size rides a power-of-two ladder anchored at 7.5° (7.5/2^k):
        // a raw width/48 target re-bins on EVERY zoom step, so bins visibly
        // reshuffle ("blink", Jason 2026-08-23 pt3); quantized cells keep
        // the grid identical across small zoom changes and let the client
        // skip redundant refetches.
        $raw = self::geoBboxWidth($bbox) / self::GEO_GRID_COLS;
        if ($raw <= 0) $raw = 0.0002;
        $k = (int)ceil(log(7.5 / $raw, 2));
        if ($k < 0)  $k = 0;
        if ($k > 15) $k = 15;   // floor ≈ 0.000229° (~25m cells)
        $cell = 7.5 / pow(2, $k);

        // ---- pass 1: bins --------------------------------------------------
        $this->resetParams();
        list($where) = $this->itemWhere($dsl);
        $env = $this->geoEnvelopeClause('ih', $bbox);
        $c = $this->bind($cell);

        $sql = "
SELECT floor(ST_X(ih.location) / $c::float8)::int AS cx,
       floor(ST_Y(ih.location) / $c::float8)::int AS cy,
       count(*)             AS n,
       avg(ST_X(ih.location)) AS lng,
       avg(ST_Y(ih.location)) AS lat
FROM strabosearch.item_hit ih
WHERE $where
  AND ih.location IS NOT NULL
  AND $env
GROUP BY 1, 2
ORDER BY n DESC
LIMIT " . self::GEO_BIN_LIMIT;

        $rows = $this->execPrepared($sql);
        $inView = 0;
        foreach ($rows as $r) $inView += (int)$r->n;

        $out = array(
            'cell_deg'        => $cell,
            'in_view_located' => $inView,
        );

        if ($inView === 0) {
            $out['mode'] = 'points';
            $out['features'] = array();
        } elseif ($inView <= self::GEO_POINT_LIMIT) {
            // ---- pass 2: real points (small by construction) ---------------
            $this->resetParams();
            list($where) = $this->itemWhere($dsl);
            $env = $this->geoEnvelopeClause('ih', $bbox);
            $sql = "
SELECT ih.item_type, ih.item_id, ih.sample_name,
       ih.project_id, ih.project_userpkey, ih.project_subsystem,
       ih.project_name, ih.project_ispublic, ih.dataset_ids,
       ST_X(ih.location) AS lng, ST_Y(ih.location) AS lat,
       trim(coalesce(u.firstname, '') || ' ' || coalesce(u.lastname, '')) AS owner_name
FROM strabosearch.item_hit ih
LEFT JOIN users u ON u.pkey = ih.project_userpkey
WHERE $where
  AND ih.location IS NOT NULL
  AND $env
ORDER BY ih.item_hit_pkey
LIMIT " . self::GEO_POINT_LIMIT;

            $pts = array();
            foreach ($this->execPrepared($sql) as $r) {
                $pts[] = array(
                    'item_type'         => $r->item_type,
                    'item_id'           => $r->item_id,
                    'sample_name'       => $r->sample_name,
                    'project_id'        => $r->project_id,
                    'project_userpkey'  => (int)$r->project_userpkey,
                    'project_subsystem' => $r->project_subsystem,
                    'project_name'      => $r->project_name,
                    'project_ispublic'  => (bool)self::pgBool($r->project_ispublic),
                    'dataset_ids'       => self::pgTextArray($r->dataset_ids),
                    'owner_name'        => $r->owner_name,
                    'lng'               => (float)$r->lng,
                    'lat'               => (float)$r->lat,
                );
            }
            $out['mode'] = 'points';
            $out['features'] = $pts;
        } else {
            $bins = array();
            foreach ($rows as $r) {
                $bins[] = array(
                    'cx'  => (int)$r->cx,
                    'cy'  => (int)$r->cy,
                    'n'   => (int)$r->n,
                    'lng' => (float)$r->lng,
                    'lat' => (float)$r->lat,
                );
            }
            $out['mode'] = 'bins';
            $out['features'] = $bins;
            $out['capped'] = (count($bins) === self::GEO_BIN_LIMIT);
        }

        // ---- global location counter (once per search) ---------------------
        if ($geo['include_counter']) {
            $this->resetParams();
            list($where) = $this->itemWhere($dsl);
            // located = renderable locations only: a residue of source rows
            // carries out-of-range coordinates (UTM-looking values in
            // lon/lat, 1,597 public rows on dev 2026-08-23) that no
            // viewport can ever show — counting them would overpromise.
            $rows = $this->execPrepared(
                "SELECT count(*) AS total,
                        count(*) FILTER (WHERE ih.location IS NOT NULL
                          AND ih.location && ST_MakeEnvelope(-180, -90, 180, 90, 4326)) AS located
                 FROM strabosearch.item_hit ih WHERE $where");
            $out['counter'] = array(
                'total'   => $rows ? (int)$rows[0]->total : 0,
                'located' => $rows ? (int)$rows[0]->located : 0,
            );
        }

        return $out;
    }

    /** PG driver booleans arrive as 't'/'f' strings. */
    private static function pgBool($v)
    {
        return $v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1;
    }

    /** Parse a PG text[] literal ({a,b,"c d"}) into a PHP list. */
    private static function pgTextArray($lit)
    {
        if ($lit === null || $lit === '' || $lit === '{}') return array();
        $out = array();
        // Simple state machine — values are project-controlled dataset ids
        // (no embedded braces), quotes/backslashes still honored.
        $body = substr((string)$lit, 1, -1);
        $cur = '';
        $inq = false;
        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $ch = $body[$i];
            if ($inq) {
                if ($ch === '\\' && $i + 1 < $len) { $cur .= $body[++$i]; }
                elseif ($ch === '"') { $inq = false; }
                else { $cur .= $ch; }
            } elseif ($ch === '"') {
                $inq = true;
            } elseif ($ch === ',') {
                $out[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        if ($cur !== '' || $out) $out[] = $cur;
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Facet counts (§5.4.3)
    // ═══════════════════════════════════════════════════════════════════

    /** Facet families that can be recounted for the dropdown refresh. */
    private static $facetables = array(
        'vocab_array'  => true, 'vocab_scalar' => true, 'rock_type' => true,
        'image_type'   => true, 'sample_vocab' => false, // U7 = two columns; skipped in v1 recount
    );

    private function facetCounts($dsl, $isImage)
    {
        $out = array();
        foreach ($dsl['criteria'] as $i => $c) {
            $fam = self::$catalog[$c['id']][0];
            if (empty(self::$facetables[$fam])) continue;

            // Column + table this facet lives on, per pathway.
            $onImagePath = self::$catalog[$c['id']][2];
            if ($isImage && !$onImagePath) continue;
            if (!$isImage && $fam === 'image_type') {
                // I1 recount runs against image_hit even for Projects mode.
                $isThisImage = true;
            } else {
                $isThisImage = $isImage;
            }

            $this->resetParams();
            if ($isThisImage) {
                list($where) = $this->imageWhere($dsl, $i);
                $from = "strabosearch.image_hit im";
                $alias = 'im';
            } else {
                list($where) = $this->itemWhere($dsl, $i);
                $from = "strabosearch.item_hit ih";
                $alias = 'ih';
            }

            if ($fam === 'vocab_array' || $fam === 'rock_type') {
                $col = ($fam === 'rock_type') ? 'rock_types' : self::$vocabArrayCols[$c['id']];
                $sql = "SELECT v AS value, count(*) AS n FROM $from
                        CROSS JOIN LATERAL unnest($alias.$col) AS v
                        WHERE $where GROUP BY 1 ORDER BY 2 DESC LIMIT 200";
            } else {
                $col = ($fam === 'image_type') ? 'image_type' : self::$vocabScalarCols[$c['id']];
                $sql = "SELECT $alias.$col AS value, count(*) AS n FROM $from
                        WHERE $where AND $alias.$col IS NOT NULL
                        GROUP BY 1 ORDER BY 2 DESC LIMIT 200";
            }
            $rows = $this->execPrepared($sql, true);
            if ($rows === null) continue;   // facet recount is best-effort
            $counts = array();
            foreach ((array)$rows as $r) $counts[$r->value] = (int)$r->n;
            $out[$c['id']] = $counts;
        }
        return $out;
    }
}
