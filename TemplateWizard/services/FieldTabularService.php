<?php
/**
 * File: FieldTabularService.php
 * Description: Tabular (XLSX/CSV/grid) import + export for StraboField
 *              spots — the engine behind the Template Wizard. Users design
 *              and save custom column templates, then move spot data both
 *              directions through them.
 *
 *              Design decisions (agreed with Jason 2026-07-03, full log in
 *              docs/TemplateWizard_PRD.md):
 *                - LONG row format: one row per (spot x instance); the spot
 *                  key (strabo_internal_id, else spot name) is repeated on
 *                  every row and grouping is BY KEY, never row adjacency —
 *                  Excel sorting cannot shatter a spot.
 *                - Dataset-scoped: import targets one project + one new or
 *                  existing dataset; export takes one dataset.
 *                - Full upsert via locked strabo_internal_id. Blank = create,
 *                  own id = update via READ-MERGE-WRITE (fields outside the
 *                  template are preserved), foreign/unknown id or id outside
 *                  the target dataset = hard error.
 *                - Multi-instance groups (orientations, samples, other
 *                  features): the file's instances REPLACE the group when
 *                  the template-covered projection differs; projection-equal
 *                  groups are left untouched (round-trip == all-noop).
 *                  Samples merge by sample_id_name to preserve uncovered
 *                  element fields + ids (spine identity).
 *                - Geometry: creates are Points from lat/lng; every spot
 *                  exports (non-points show centroid + locked geometry_type);
 *                  lat/lng edits on non-point / pixel-basemap spots hard-error.
 *                - Vocab: storage values + display labels case-insensitively;
 *                  unknown values resolve at review (map / keep via other_*
 *                  companion / free text). Numeric constraints hard-error.
 *                - Atomicity: plan-clean gate + compensating rollback. Neo4j
 *                  writes can't share a PG transaction, so every run journals
 *                  minted ids + prior JSON of updated spots (field_tabular_runs)
 *                  BEFORE writing; mid-run failure deletes created spots and
 *                  restores updated ones. Journal rows are kept for forensics.
 *                - Owner-only in both directions. No deletes via upload.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class FieldTabularService
{
    const MAX_ROWS          = 10000;
    const MAX_CELL_CHARS    = 10000;
    const MAX_CUSTOM_KEYS   = 50;
    const STATE_TTL_SECONDS = 86400;
    const FLOAT_EPSILON     = 1e-7;
    const SPEC_VERSION      = 1;

    /** Review-screen resolution sentinels. */
    const RESOLUTION_FREE_TEXT = '__freetext__';
    const RESOLUTION_OTHER     = '__other__';

    /** orientation_type column value <=> stored element type. */
    protected static $OTYPES = array(
        'planar'       => 'planar_orientation',
        'linear'       => 'linear_orientation',
        'tabular_zone' => 'tabular_orientation',
    );

    protected $db;
    protected $neodb;
    protected $strabo;     // StraboSpot instance (already carrying userpkey)
    protected $userpkey;

    protected static $catalog = null;
    protected static $headerIndex = null;   // canonical header => [group, name]

    public function __construct($db, $neodb, $strabo)
    {
        $this->db     = $db;
        $this->neodb  = $neodb;
        $this->strabo = $strabo;
    }

    public function setUserpkey($pkey)
    {
        $this->userpkey = (int)$pkey;
    }

    // ========================================================================
    // Catalog
    // ========================================================================

    public static function catalog()
    {
        if (self::$catalog === null) {
            $raw = file_get_contents(__DIR__ . '/../schema/catalog.json');
            self::$catalog = json_decode($raw, true);
            if (!is_array(self::$catalog)) {
                throw new Exception('TemplateWizard catalog.json missing or unparseable — run schema/build_catalog.php');
            }
        }
        return self::$catalog;
    }

    /**
     * Section metadata for the color-coded band row (Jason 2026-07-04):
     * generated sheets + the designer grid draw a merged, colored section
     * label above each contiguous run of same-group columns. Fill/font are
     * ARGB for PHPExcel; the designer maps the same keys to CSS classes.
     */
    public static function sectionMeta()
    {
        $cat = self::catalog();
        $label = function ($g, $fallback) use ($cat) {
            return isset($cat['groups'][$g]['label']) ? $cat['groups'][$g]['label'] : $fallback;
        };
        return array(
            'system'         => array('label' => 'StraboSpot',                          'fill' => 'FFCFCFCF', 'font' => 'FF3B3B3B'),
            'spot'           => array('label' => $label('spot', 'Spot'),                'fill' => 'FFBDD7EE', 'font' => 'FF1F3864'),
            'orientation'    => array('label' => $label('orientation', 'Orientations'), 'fill' => 'FFF7C7C0', 'font' => 'FF7B241C'),
            'geologic_unit'  => array('label' => $label('geologic_unit', 'Geologic Unit'), 'fill' => 'FFC6E0B4', 'font' => 'FF375623'),
            'trace'          => array('label' => $label('trace', 'Trace'),              'fill' => 'FFFFE0A3', 'font' => 'FF7F6000'),
            'other_features' => array('label' => $label('other_features', 'Other Features'), 'fill' => 'FFD9C2E9', 'font' => 'FF4A235A'),
            'sample'         => array('label' => $label('sample', 'Samples'),           'fill' => 'FFB7DEDA', 'font' => 'FF145A54'),
            'custom'         => array('label' => 'Custom',                               'fill' => 'FFEDEDED', 'font' => 'FF555555'),
        );
    }

    /**
     * Section key per header position: contiguous same-section runs become
     * one merged band cell. Headers absent from defs (export-injected
     * geometry_type) resolve as system; unrecognized headers as custom.
     * @return array of {start, end, section} runs (0-based inclusive)
     */
    public static function sectionRuns($headers, $defs)
    {
        $byHeader = array();
        foreach ($defs as $d) { $byHeader[$d['header']] = $d; }
        $systemHeaders = array('strabo_internal_id' => true, 'geometry_type' => true,
                               'orientation_type' => true, 'orientation_role' => true);
        $sections = array();
        foreach ($headers as $h) {
            if (isset($byHeader[$h])) {
                $d = $byHeader[$h];
                if ($d['kind'] === 'system') {
                    // orientation_type/role sit inside the orientation section
                    $sections[] = in_array($d['key'], array('orientation_type', 'orientation_role'))
                        ? 'orientation' : 'system';
                } elseif ($d['kind'] === 'field') {
                    $sections[] = $d['group'];
                } else {
                    $sections[] = 'custom';
                }
            } elseif (isset($systemHeaders[$h])) {
                $sections[] = in_array($h, array('orientation_type', 'orientation_role'))
                    ? 'orientation' : 'system';
            } else {
                $sections[] = 'custom';
            }
        }
        $runs = array();
        foreach ($sections as $i => $s) {
            if (count($runs) && $runs[count($runs) - 1]['section'] === $s
                && $runs[count($runs) - 1]['end'] === $i - 1) {
                $runs[count($runs) - 1]['end'] = $i;
            } else {
                $runs[] = array('start' => $i, 'end' => $i, 'section' => $s);
            }
        }
        return $runs;
    }

    /** Field definition from the catalog, or null. */
    public static function fieldDef($group, $name)
    {
        $cat = self::catalog();
        if (!isset($cat['groups'][$group])) { return null; }
        foreach ($cat['groups'][$group]['fields'] as $f) {
            if ($f['name'] === $name) { return $f; }
        }
        return null;
    }

    /**
     * Canonical header for (group, field): the bare field name when only one
     * group claims it (priority order below picks the winner), otherwise
     * group-prefixed. Deterministic across templates so files stay portable.
     */
    public static function headerIndex()
    {
        if (self::$headerIndex !== null) { return self::$headerIndex; }
        $cat = self::catalog();
        $priority = array('spot', 'orientation', 'geologic_unit', 'trace', 'other_features', 'sample');
        $claims = array();  // bare name => first-priority group
        foreach ($priority as $g) {
            if (!isset($cat['groups'][$g])) { continue; }
            foreach ($cat['groups'][$g]['fields'] as $f) {
                if (!isset($claims[$f['name']])) { $claims[$f['name']] = $g; }
            }
        }
        $index = array();   // canonical header => [group, name]
        foreach ($cat['groups'] as $g => $def) {
            foreach ($def['fields'] as $f) {
                $bare = self::canonicalizeHeaderStatic($f['name']);
                $canonical = ($claims[$f['name']] === $g) ? $bare
                           : self::canonicalizeHeaderStatic($g . '_' . $f['name']);
                $index[$canonical] = array($g, $f['name']);
                // Label alias (only when unambiguous).
                $labelCanon = self::canonicalizeHeaderStatic($f['label']);
                if ($labelCanon !== '' && !isset($index[$labelCanon])) {
                    $index[$labelCanon] = array($g, $f['name']);
                }
                // Group-prefixed form always accepted.
                $index[self::canonicalizeHeaderStatic($g . '_' . $f['name'])] = array($g, $f['name']);
            }
        }
        self::$headerIndex = $index;
        return $index;
    }

    /** Display header for a (group, name) pair — bare when unambiguous. */
    public static function displayHeader($group, $name)
    {
        foreach (self::headerIndex() as $canon => $gn) {
            if ($gn[0] === $group && $gn[1] === $name) {
                return $canon;   // first hit is the shortest (bare or prefixed)
            }
        }
        return $group . '_' . $name;
    }

    // ========================================================================
    // Template specs
    // ========================================================================

    /** The seeded "Basic" starter template spec. */
    public static function defaultSpec()
    {
        $cols = array(
            array('kind' => 'system', 'key' => 'strabo_internal_id'),
            array('kind' => 'field', 'group' => 'spot', 'name' => 'name'),
            array('kind' => 'field', 'group' => 'spot', 'name' => 'latitude'),
            array('kind' => 'field', 'group' => 'spot', 'name' => 'longitude'),
            array('kind' => 'field', 'group' => 'spot', 'name' => 'altitude'),
            array('kind' => 'field', 'group' => 'spot', 'name' => 'date'),
            array('kind' => 'field', 'group' => 'spot', 'name' => 'notes'),
            array('kind' => 'system', 'key' => 'orientation_type'),
            array('kind' => 'field', 'group' => 'orientation', 'name' => 'feature_type'),
            array('kind' => 'field', 'group' => 'orientation', 'name' => 'strike'),
            array('kind' => 'field', 'group' => 'orientation', 'name' => 'dip'),
            array('kind' => 'field', 'group' => 'orientation', 'name' => 'dip_direction'),
            array('kind' => 'field', 'group' => 'orientation', 'name' => 'trend'),
            array('kind' => 'field', 'group' => 'orientation', 'name' => 'plunge'),
            array('kind' => 'field', 'group' => 'orientation', 'name' => 'quality'),
        );
        return array('spec_version' => self::SPEC_VERSION, 'layout' => 'long', 'columns' => $cols);
    }

    /**
     * Structural validation + normalization of a template spec.
     * @return array {ok, spec} | {ok:false, message}
     */
    public function validateSpec($spec)
    {
        if (!is_array($spec) || !isset($spec['columns']) || !is_array($spec['columns'])) {
            return array('ok' => false, 'message' => 'Template spec must contain a columns list.');
        }
        if (count($spec['columns']) > 200) {
            return array('ok' => false, 'message' => 'Template exceeds 200 columns.');
        }
        $seen = array();
        $out  = array();
        $hasOrient = false; $hasOType = false;
        foreach ($spec['columns'] as $col) {
            if (!is_array($col) || !isset($col['kind'])) {
                return array('ok' => false, 'message' => 'Malformed column entry.');
            }
            if ($col['kind'] === 'system') {
                if (!in_array($col['key'], array('strabo_internal_id', 'geometry_type', 'orientation_type', 'orientation_role'))) {
                    return array('ok' => false, 'message' => "Unknown system column '{$col['key']}'.");
                }
                $sig = 'system:' . $col['key'];
                if ($col['key'] === 'orientation_type') { $hasOType = true; }
                $out[] = array('kind' => 'system', 'key' => $col['key']);
            } elseif ($col['kind'] === 'field') {
                $def = self::fieldDef(isset($col['group']) ? $col['group'] : '', isset($col['name']) ? $col['name'] : '');
                if ($def === null) {
                    return array('ok' => false, 'message' => "Unknown catalog field '{$col['group']}.{$col['name']}'.");
                }
                if ($col['group'] === 'orientation') { $hasOrient = true; }
                $sig = 'field:' . $col['group'] . '.' . $col['name'];
                $entry = array('kind' => 'field', 'group' => $col['group'], 'name' => $col['name']);
                if (isset($col['header']) && trim((string)$col['header']) !== '') {
                    $entry['header'] = trim((string)$col['header']);
                }
                $out[] = $entry;
            } elseif ($col['kind'] === 'custom') {
                $h = isset($col['header']) ? trim((string)$col['header']) : '';
                if ($h === '') {
                    return array('ok' => false, 'message' => 'Custom columns need a header.');
                }
                $sig = 'custom:' . mb_strtolower($h);
                $out[] = array('kind' => 'custom', 'header' => $h);
            } else {
                return array('ok' => false, 'message' => "Unknown column kind '{$col['kind']}'.");
            }
            if (isset($seen[$sig])) {
                return array('ok' => false, 'message' => 'Duplicate column: ' . $sig);
            }
            $seen[$sig] = true;
        }
        // System columns the pipeline depends on.
        if (!isset($seen['system:strabo_internal_id'])) {
            array_unshift($out, array('kind' => 'system', 'key' => 'strabo_internal_id'));
        }
        // geometry_type is NOT auto-injected (Jason 2026-07-04): it is export
        // context only (the server never reads it on upload — the non-point
        // lat/lng guard checks the stored geometry). It materializes on
        // export when the dataset actually contains non-point spots, or when
        // a template opts in via the designer picker.
        if ($hasOrient && !$hasOType) {
            // orientation columns are meaningless without the discriminator.
            // orientation_role is NOT auto-injected (Jason 2026-07-03): it only
            // materializes when a template opts in or an export actually
            // contains associated orientations — most sheets never see it.
            $insertAt = 2;
            foreach ($out as $i => $c) {
                if ($c['kind'] === 'field' && $c['group'] === 'orientation') { $insertAt = $i; break; }
            }
            array_splice($out, $insertAt, 0, array(
                array('kind' => 'system', 'key' => 'orientation_type'),
            ));
        }
        return array('ok' => true, 'spec' => array(
            'spec_version' => self::SPEC_VERSION,
            'layout'       => 'long',
            'columns'      => $out,
        ));
    }

    /** Ordered column descriptors for a validated spec. */
    public function columnDefs($spec)
    {
        $defs = array();
        foreach ($spec['columns'] as $col) {
            if ($col['kind'] === 'system') {
                $defs[] = array('kind' => 'system', 'key' => $col['key'], 'header' => $col['key']);
            } elseif ($col['kind'] === 'field') {
                $def = self::fieldDef($col['group'], $col['name']);
                $defs[] = array(
                    'kind'   => 'field',
                    'group'  => $col['group'],
                    'name'   => $col['name'],
                    'header' => isset($col['header']) ? $col['header'] : self::displayHeader($col['group'], $col['name']),
                    'def'    => $def,
                );
            } else {
                $defs[] = array('kind' => 'custom', 'header' => $col['header']);
            }
        }
        return $defs;
    }

    // ------------------------------------------------------------ CRUD

    public function listTemplates()
    {
        $rows = $this->db->get_results_prepared(
            "SELECT pkey, name, spec::text AS spec, modified_at
               FROM field_templates
              WHERE userpkey = $1 AND NOT deleted
              ORDER BY lower(name)",
            array($this->userpkey)
        );
        return is_array($rows) ? $rows : array();
    }

    public function getTemplate($pkey)
    {
        $row = $this->db->get_row_prepared(
            "SELECT pkey, name, spec::text AS spec
               FROM field_templates
              WHERE pkey = $1 AND userpkey = $2 AND NOT deleted",
            array((int)$pkey, $this->userpkey)
        );
        if (!$row) { return null; }
        $spec = json_decode($row->spec, true);
        return array('pkey' => (int)$row->pkey, 'name' => $row->name, 'spec' => $spec);
    }

    /** @return array {ok, pkey} | {ok:false, message} */
    public function saveTemplate($name, $spec, $pkey = null)
    {
        $name = trim((string)$name);
        if ($name === '' || mb_strlen($name) > 255) {
            return array('ok' => false, 'message' => 'Template name is required (max 255 chars).');
        }
        $v = $this->validateSpec($spec);
        if (empty($v['ok'])) { return $v; }
        $specJson = json_encode($v['spec']);

        $existing = $this->db->get_var_prepared(
            "SELECT pkey FROM field_templates
              WHERE userpkey = $1 AND lower(name) = lower($2) AND NOT deleted",
            array($this->userpkey, $name)
        );
        if ($pkey !== null) {
            $pkey = (int)$pkey;
            if ($existing !== null && (int)$existing !== $pkey) {
                return array('ok' => false, 'message' => "You already have a template named '$name'.");
            }
            $owned = $this->db->get_var_prepared(
                "SELECT pkey FROM field_templates WHERE pkey = $1 AND userpkey = $2 AND NOT deleted",
                array($pkey, $this->userpkey)
            );
            if ($owned === null) {
                return array('ok' => false, 'message' => 'Template not found.');
            }
            $this->db->get_var_prepared(
                "UPDATE field_templates SET name = $1, spec = $2::jsonb, modified_at = now()
                  WHERE pkey = $3 AND userpkey = $4 RETURNING pkey",
                array($name, $specJson, $pkey, $this->userpkey)
            );
            return array('ok' => true, 'pkey' => $pkey);
        }
        if ($existing !== null) {
            return array('ok' => false, 'message' => "You already have a template named '$name'.");
        }
        // NOTE: the prepared-statement layer discards RETURNING rows for
        // INSERTs (rows_affected only) — read the id back via currval on the
        // same connection instead.
        $this->db->get_var_prepared(
            "INSERT INTO field_templates (userpkey, name, spec) VALUES ($1, $2, $3::jsonb)",
            array($this->userpkey, $name, $specJson)
        );
        $newPkey = $this->db->get_var_prepared("SELECT currval('field_templates_pkey_seq')", array());
        return array('ok' => true, 'pkey' => (int)$newPkey);
    }

    public function deleteTemplate($pkey)
    {
        $this->db->get_var_prepared(
            "UPDATE field_templates SET deleted = true, modified_at = now()
              WHERE pkey = $1 AND userpkey = $2 RETURNING pkey",
            array((int)$pkey, $this->userpkey)
        );
        return array('ok' => true);
    }

    // ========================================================================
    // Parse — file/grid to raw rows
    // ========================================================================

    /**
     * Parse an uploaded .xlsx/.xls/.csv. When the workbook carries an
     * embedded template spec (hidden _template sheet) it drives header
     * mapping; otherwise $spec (the user's chosen template) plus the global
     * header index resolve columns. Unknown headers become custom columns.
     */
    public function parseUpload($path, $clientFilename, $spec = null)
    {
        $ext = strtolower(pathinfo($clientFilename, PATHINFO_EXTENSION));
        if (!in_array($ext, array('xlsx', 'xls', 'csv'))) {
            return array('ok' => false, 'error' => 'unsupported_format',
                         'message' => "Unsupported file type '.$ext' — upload .xlsx or .csv.");
        }
        $embedded = null;
        if ($ext === 'csv') {
            $grid = $this->readCsvGrid($path);
        } else {
            $grid = $this->readExcelGrid($path);
            if (!isset($grid['error']) && isset($grid['embedded_spec'])) {
                $embedded = $grid['embedded_spec'];
            }
        }
        if (isset($grid['error'])) {
            return array('ok' => false, 'error' => $grid['error'], 'message' => $grid['message']);
        }
        $useSpec = null;
        if ($embedded !== null) {
            $v = $this->validateSpec($embedded);
            if (!empty($v['ok'])) { $useSpec = $v['spec']; }
        }
        if ($useSpec === null && $spec !== null) {
            $v = $this->validateSpec($spec);
            if (!empty($v['ok'])) { $useSpec = $v['spec']; }
        }
        $parsed = $this->gridToParsed($grid['grid'], $useSpec);
        if (!empty($parsed['ok'])) {
            $parsed['embedded_spec'] = ($embedded !== null && $useSpec !== null) ? $useSpec : null;
        }
        return $parsed;
    }

    /** Parse grid data POSTed from the designer (array of arrays, row 0 = headers). */
    public function parseGrid($gridRows, $spec)
    {
        if (!is_array($gridRows)) {
            return array('ok' => false, 'error' => 'bad_grid', 'message' => 'Malformed grid payload.');
        }
        $v = $this->validateSpec($spec);
        return $this->gridToParsed($gridRows, !empty($v['ok']) ? $v['spec'] : null);
    }

    /**
     * Shared grid interpreter. Maps headers to columns, extracts rows.
     * Row record: {n, id, key_name, values: {"group.name": raw}, otype, orole,
     *              custom: {header: raw}}  (raw = trimmed string | null)
     */
    protected function gridToParsed($grid, $spec)
    {
        if (count($grid) > self::MAX_ROWS + 1) {
            return array('ok' => false, 'error' => 'too_many_rows',
                         'message' => 'File exceeds the ' . self::MAX_ROWS . '-row limit.');
        }
        // Template-declared headers take precedence over the global index.
        $specHeaderMap = array();   // canonical header => column descriptor
        if ($spec !== null) {
            foreach ($this->columnDefs($spec) as $cd) {
                $specHeaderMap[$this->canonicalizeHeader($cd['header'])] = $cd;
                if ($cd['kind'] === 'system') {
                    $specHeaderMap[$this->canonicalizeHeader($cd['key'])] = $cd;
                }
            }
        }
        $globalIndex = self::headerIndex();
        $systemKeys = array(
            'strabo_internal_id' => 'id',
            'internal_id'        => 'id',
            'geometry_type'      => 'geometry_type',
            'orientation_type'   => 'otype',
            'orientation_role'   => 'orole',
        );

        // Header row = the best-scoring of the first few non-empty rows
        // (count of cells that resolve to a known header). Generated files
        // carry a merged section-band row ABOVE the headers ("Spot",
        // "Orientations"...) whose labels resolve to nothing, and hand-made
        // files sometimes lead with a title line — both must lose to the
        // real header row. Band-less files: the first non-empty row wins
        // outright, preserving the original behavior.
        $headerRowIdx = null;
        $bestScore = -1;
        $scanned = 0;
        foreach ($grid as $i => $row) {
            if ($this->rowIsEmpty($row)) { continue; }
            $score = 0;
            foreach ($row as $cell) {
                $canon = $this->canonicalizeHeader($this->cellToString($cell));
                if ($canon === '') { continue; }
                if (isset($specHeaderMap[$canon]) || isset($systemKeys[$canon]) || isset($globalIndex[$canon])) {
                    $score++;
                }
            }
            if ($score > $bestScore) { $bestScore = $score; $headerRowIdx = $i; }
            if (++$scanned >= 5) { break; }
        }
        if ($headerRowIdx === null) {
            return array('ok' => false, 'error' => 'empty_file', 'message' => 'The file contains no data.');
        }

        $colMap = array();          // col idx => {type:'id'|'geom'|'otype'|'orole'|'field'|'custom', group?, name?, header}
        $customHeaders = array();
        $seen = array();
        foreach ($grid[$headerRowIdx] as $col => $rawHeader) {
            $header = trim((string)$rawHeader);
            if ($header === '') { continue; }
            $canon = $this->canonicalizeHeader($header);
            $desc = null;
            $systemDescTypes = array('strabo_internal_id' => 'id', 'geometry_type' => 'geom',
                                     'orientation_type' => 'otype', 'orientation_role' => 'orole');
            if (isset($specHeaderMap[$canon])) {
                $cd = $specHeaderMap[$canon];
                if ($cd['kind'] === 'system') {
                    $desc = array('type' => $systemDescTypes[$cd['key']]);
                } elseif ($cd['kind'] === 'field') {
                    $desc = array('type' => 'field', 'group' => $cd['group'], 'name' => $cd['name']);
                } else {
                    $desc = array('type' => 'custom', 'header' => $cd['header']);
                }
            } elseif (isset($systemKeys[$canon])) {
                $key = $systemKeys[$canon];
                $desc = array('type' => ($key === 'geometry_type') ? 'geom' : $key);
            } elseif (isset($globalIndex[$canon])) {
                $desc = array('type' => 'field', 'group' => $globalIndex[$canon][0], 'name' => $globalIndex[$canon][1]);
            } else {
                $desc = array('type' => 'custom', 'header' => $header);
            }
            $sig = ($desc['type'] === 'field') ? 'f:' . $desc['group'] . '.' . $desc['name']
                 : (($desc['type'] === 'custom') ? 'c:' . mb_strtolower($desc['header']) : 's:' . $desc['type']);
            if (isset($seen[$sig])) {
                return array('ok' => false, 'error' => 'duplicate_column',
                             'message' => "Columns '{$seen[$sig]}' and '$header' both map to the same field.");
            }
            $seen[$sig] = $header;
            $desc['header'] = $header;
            $colMap[$col] = $desc;
            if ($desc['type'] === 'custom') { $customHeaders[] = $desc['header']; }
        }

        $hasField = false;
        foreach ($colMap as $d) {
            if (in_array($d['type'], array('field', 'id'))) { $hasField = true; break; }
        }
        if (!$hasField) {
            return array('ok' => false, 'error' => 'no_recognized_columns',
                         'message' => 'No recognized StraboField columns found in the header row. Download a template for the expected format.');
        }

        $fieldsPresent = array();   // "group.name" => true
        foreach ($colMap as $d) {
            if ($d['type'] === 'field') { $fieldsPresent[$d['group'] . '.' . $d['name']] = true; }
        }

        $rows = array();
        $n = count($grid);
        for ($i = $headerRowIdx + 1; $i < $n; $i++) {
            if ($this->rowIsEmpty($grid[$i])) { continue; }
            $rec = array('n' => $i + 1, 'id' => null, 'otype' => null, 'orole' => null,
                         'values' => array(), 'custom' => array());
            foreach ($colMap as $col => $d) {
                $val = $this->cellToString(isset($grid[$i][$col]) ? $grid[$i][$col] : null);
                switch ($d['type']) {
                    case 'id':     $rec['id'] = $val; break;
                    case 'geom':   break;   // export context, ignored on upload
                    case 'otype':  $rec['otype'] = $val; break;
                    case 'orole':  $rec['orole'] = $val; break;
                    case 'field':  $rec['values'][$d['group'] . '.' . $d['name']] = $val; break;
                    case 'custom': $rec['custom'][$d['header']] = $val; break;
                }
            }
            $rows[] = $rec;
        }

        return array(
            'ok'             => true,
            'fields_present' => array_keys($fieldsPresent),
            'custom_headers' => $customHeaders,
            'rows'           => $rows,
            'spec'           => $spec,
        );
    }

    protected function readExcelGrid($path)
    {
        $this->requirePhpExcel();
        try {
            $reader = PHPExcel_IOFactory::createReaderForFile($path);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            $wb = $reader->load($path);
        } catch (Exception $e) {
            return array('error' => 'parse_failed',
                         'message' => 'Could not read the spreadsheet: ' . $e->getMessage());
        }
        $out = array();
        $tpl = $wb->getSheetByName('_template');
        if ($tpl !== null) {
            $specRaw = (string)$tpl->getCell('A1')->getValue();
            $spec = json_decode($specRaw, true);
            if (is_array($spec)) { $out['embedded_spec'] = $spec; }
        }
        $sheet = $wb->getSheetByName('Data');
        if ($sheet === null) { $sheet = $wb->getSheet(0); }
        $out['grid'] = $sheet->toArray(null, true, false, false);
        $wb->disconnectWorksheets();
        unset($wb);
        return $out;
    }

    protected function readCsvGrid($path)
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return array('error' => 'parse_failed', 'message' => 'Could not read the uploaded file.');
        }
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") { $content = substr($content, 3); }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $content);
            if ($converted !== false) { $content = $converted; }
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $grid = array();
        while (($row = fgetcsv($fh)) !== false) { $grid[] = $row; }
        fclose($fh);
        return array('grid' => $grid);
    }

    protected function requirePhpExcel()
    {
        if (!class_exists('PHPExcel')) {
            require_once dirname(dirname(__DIR__)) . '/PHPExcel.php';
        }
    }

    protected function canonicalizeHeader($header)
    {
        return self::canonicalizeHeaderStatic($header);
    }

    protected static function canonicalizeHeaderStatic($header)
    {
        $h = preg_replace('/\(.*?\)/', '', (string)$header);
        $h = strtolower(trim($h));
        $h = preg_replace('/[\s\-]+/', '_', $h);
        $h = preg_replace('/[^a-z0-9_]/', '', $h);
        return trim($h, '_');
    }

    protected function rowIsEmpty($row)
    {
        if (!is_array($row)) { return true; }
        foreach ($row as $cell) {
            if ($cell !== null && trim((string)$cell) !== '') { return false; }
        }
        return true;
    }

    protected function cellToString($cell)
    {
        if ($cell === null) { return null; }
        if (is_float($cell)) {
            $s = rtrim(rtrim(number_format($cell, 10, '.', ''), '0'), '.');
        } else {
            $s = trim((string)$cell);
        }
        return ($s === '') ? null : $s;
    }

    // ========================================================================
    // State files
    // ========================================================================

    // Flat per-token files in the sticky system temp dir (never a shared
    // subdirectory — a root-owned subdir silently locks out the Apache uid;
    // lesson from the samples E2E).
    protected function statePath($token)
    {
        return sys_get_temp_dir() . '/strabo_fieldtab_' . $token . '.json';
    }

    public function saveState($payload)
    {
        $this->purgeStaleStates();
        $token = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
        $state = array(
            'userpkey' => $this->userpkey,
            'created'  => time(),
            'payload'  => $payload,
        );
        $path = $this->statePath($token);
        if (@file_put_contents($path, json_encode($state)) === false) {
            return null;
        }
        @chmod($path, 0600);
        return $token;
    }

    public function loadState($token)
    {
        if (!preg_match('/^[0-9a-f]{32}$/', (string)$token)) { return null; }
        $file = $this->statePath($token);
        if (!is_file($file)) { return null; }
        $state = json_decode(file_get_contents($file), true);
        if (!is_array($state)) { return null; }
        if ((int)$state['userpkey'] !== (int)$this->userpkey) { return null; }
        if (time() - (int)$state['created'] > self::STATE_TTL_SECONDS) {
            @unlink($file);
            return null;
        }
        return $state['payload'];
    }

    public function discardState($token)
    {
        if (preg_match('/^[0-9a-f]{32}$/', (string)$token)) {
            @unlink($this->statePath($token));
        }
    }

    protected function purgeStaleStates()
    {
        $files = @glob(sys_get_temp_dir() . '/strabo_fieldtab_*.json');
        if (!is_array($files)) { return; }
        $cutoff = time() - self::STATE_TTL_SECONDS;
        foreach ($files as $f) {
            if (@filemtime($f) < $cutoff) { @unlink($f); }
        }
    }

    // ========================================================================
    // Target (project / dataset) helpers — owner-only
    // ========================================================================

    public function myProjects()
    {
        $records = $this->neodb->get_results(
            "MATCH (p:Project) WHERE p.userpkey = {$this->userpkey}
             RETURN p.id AS id, coalesce(p.desc_project_name, p.projectname) AS name
             ORDER BY name");
        $out = array();
        if (is_array($records)) {
            foreach ($records as $r) {
                $out[] = array('id' => (string)$r->value('id'), 'name' => (string)$r->value('name'));
            }
        }
        return $out;
    }

    public function projectDatasets($projectId)
    {
        $projectId = (int)$projectId;
        $records = $this->neodb->get_results(
            "MATCH (p:Project {id: $projectId, userpkey: {$this->userpkey}})-[:HAS_DATASET]->(d:Dataset)
             RETURN d.id AS id, d.name AS name ORDER BY name");
        $out = array();
        if (is_array($records)) {
            foreach ($records as $r) {
                $out[] = array('id' => (string)$r->value('id'), 'name' => (string)$r->value('name'));
            }
        }
        return $out;
    }

    protected function ownsProject($projectId)
    {
        $projectId = (int)$projectId;
        $v = $this->neodb->get_var(
            "MATCH (p:Project {id: $projectId, userpkey: {$this->userpkey}}) RETURN count(p)");
        return ((int)$v) > 0;
    }

    protected function datasetInProject($projectId, $datasetId)
    {
        $projectId = (int)$projectId;
        $datasetId = (int)$datasetId;
        $v = $this->neodb->get_var(
            "MATCH (p:Project {id: $projectId, userpkey: {$this->userpkey}})-[:HAS_DATASET]->(d:Dataset {id: $datasetId, userpkey: {$this->userpkey}}) RETURN count(d)");
        return ((int)$v) > 0;
    }

    public function ownsDataset($datasetId)
    {
        $datasetId = (int)$datasetId;
        $v = $this->neodb->get_var(
            "MATCH (d:Dataset {id: $datasetId, userpkey: {$this->userpkey}}) RETURN count(d)");
        return ((int)$v) > 0;
    }

    // ========================================================================
    // Plan — group, validate, diff
    // ========================================================================

    /**
     * @param array $parsed       gridToParsed()/parseUpload() result
     * @param array $target       {project_id, dataset_id|null, dataset_name|null}
     * @param array $resolutions  {vocab: {"group.name": {raw => canonical|'__other__'|'__freetext__'}},
     *                             custom_columns: {header => 'import'|'ignore'}}
     */
    public function plan($parsed, $target, $resolutions = array())
    {
        $vocabRes  = isset($resolutions['vocab']) && is_array($resolutions['vocab']) ? $resolutions['vocab'] : array();
        $customRes = isset($resolutions['custom_columns']) && is_array($resolutions['custom_columns']) ? $resolutions['custom_columns'] : array();

        $hardErrors = array();
        $softVocab  = array();   // "group.name" => raw => {count, rows, suggestion, other_target}
        $softCustom = array();
        $warnings   = array();

        // ---- target validation ----
        $projectId = isset($target['project_id']) ? (int)$target['project_id'] : 0;
        $datasetId = !empty($target['dataset_id']) ? (int)$target['dataset_id'] : null;
        $newDatasetName = isset($target['dataset_name']) ? trim((string)$target['dataset_name']) : '';
        if ($projectId <= 0 || !$this->ownsProject($projectId)) {
            $hardErrors[] = array('row' => 0, 'column' => '', 'code' => 'bad_project',
                                  'message' => 'Target project not found (projects you own only).');
        } elseif ($datasetId !== null) {
            if (!$this->datasetInProject($projectId, $datasetId)) {
                $hardErrors[] = array('row' => 0, 'column' => '', 'code' => 'bad_dataset',
                                      'message' => 'Target dataset not found in that project.');
            }
        } elseif ($newDatasetName === '') {
            $hardErrors[] = array('row' => 0, 'column' => '', 'code' => 'bad_dataset',
                                  'message' => 'Pick an existing dataset or name a new one.');
        }

        // ---- custom column decisions (samples pattern: provisional import) ----
        // Custom columns DECLARED in the template spec are part of the saved
        // design — they auto-decide as 'import' (no review friction; keeps the
        // export→reimport round trip a clean all-noop plan).
        $specCustom = array();
        if (isset($parsed['spec']['columns'])) {
            foreach ($parsed['spec']['columns'] as $col) {
                if (isset($col['kind']) && $col['kind'] === 'custom') {
                    $specCustom[$col['header']] = true;
                }
            }
        }
        $importCustom = array();
        foreach ($parsed['custom_headers'] as $header) {
            $decision = isset($customRes[$header]) ? $customRes[$header] : null;
            if (isset($specCustom[$header]) && $decision !== 'ignore') {
                $decision = 'import';
            }
            if ($decision === 'import') {
                $importCustom[$header] = true;
            } elseif ($decision !== 'ignore') {
                $softCustom[] = $header;
                $importCustom[$header] = true;
            }
        }

        // ---- group rows by key ----
        $groups = array();       // key => {id|null, name|null, rows: [rec]}
        $order  = array();
        foreach ($parsed['rows'] as $rec) {
            foreach (array_merge($rec['values'], $rec['custom']) as $k => $v) {
                if ($v !== null && mb_strlen($v) > self::MAX_CELL_CHARS) {
                    $hardErrors[] = array('row' => $rec['n'], 'column' => $k, 'code' => 'cell_too_long',
                                          'message' => 'Value exceeds ' . self::MAX_CELL_CHARS . ' characters.');
                }
            }
            $rowName = isset($rec['values']['spot.name']) ? $rec['values']['spot.name'] : null;
            if ($rec['id'] !== null) {
                if (!preg_match('/^\d{1,18}$/', $rec['id'])) {
                    $hardErrors[] = array('row' => $rec['n'], 'column' => 'strabo_internal_id', 'code' => 'bad_id',
                                          'message' => "'{$rec['id']}' is not a valid StraboSpot id.");
                    continue;
                }
                $key = 'id:' . $rec['id'];
            } elseif ($rowName !== null) {
                $key = 'name:' . mb_strtolower($rowName);
            } else {
                $hardErrors[] = array('row' => $rec['n'], 'column' => 'spot_name', 'code' => 'no_key',
                                      'message' => 'Row has neither an internal id nor a spot name — nothing to group it by.');
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = array('id' => $rec['id'], 'name' => $rowName, 'rows' => array());
                $order[] = $key;
            }
            if ($groups[$key]['name'] === null && $rowName !== null) {
                $groups[$key]['name'] = $rowName;
            }
            $groups[$key]['rows'][] = $rec;
        }

        // A blank-id row whose name matches an update group is almost
        // certainly a stray continuation row that lost its id.
        $idGroupNames = array();
        foreach ($groups as $key => $g) {
            if ($g['id'] !== null && $g['name'] !== null) {
                $idGroupNames[mb_strtolower($g['name'])] = $g['id'];
            }
        }
        foreach ($groups as $key => $g) {
            if ($g['id'] === null && isset($idGroupNames[mb_strtolower((string)$g['name'])])) {
                $hardErrors[] = array('row' => $g['rows'][0]['n'], 'column' => 'strabo_internal_id', 'code' => 'ambiguous_key',
                                      'message' => "Rows named '{$g['name']}' appear both with and without an internal id. Give every row of that spot the same id (or a different name for a new spot).");
            }
        }

        // ---- fetch current nodes for all referenced ids ----
        $ids = array();
        foreach ($groups as $g) {
            if ($g['id'] !== null) { $ids[] = (int)$g['id']; }
        }
        $current = $this->fetchSpotNodes($ids);
        $inDataset = ($datasetId !== null) ? $this->spotIdsInDataset($datasetId, $ids) : array();

        $fieldsPresent = array();
        foreach ($parsed['fields_present'] as $f) { $fieldsPresent[$f] = true; }

        $planRows = array();
        $counts = array('create' => 0, 'update' => 0, 'noop' => 0,
                        'orientations' => 0, 'samples' => 0, 'other_features' => 0);

        foreach ($order as $key) {
            $g = $groups[$key];
            $n0 = $g['rows'][0]['n'];
            $isUpdate = ($g['id'] !== null);
            $cur = null;

            if ($isUpdate) {
                if (!isset($current[(int)$g['id']])) {
                    $hardErrors[] = array('row' => $n0, 'column' => 'strabo_internal_id', 'code' => 'unknown_id',
                                          'message' => "Internal id '{$g['id']}' does not match any of your spots. Leave the id blank to create a new spot.");
                    continue;
                }
                $cur = $current[(int)$g['id']];
                if ($datasetId !== null && !isset($inDataset[(int)$g['id']])) {
                    $hardErrors[] = array('row' => $n0, 'column' => 'strabo_internal_id', 'code' => 'wrong_dataset',
                                          'message' => "Spot '{$g['id']}' is not in the target dataset. Pick the dataset the export came from.");
                    continue;
                }
                if ($datasetId === null) {
                    // update rows can't target a brand-new dataset
                    $hardErrors[] = array('row' => $n0, 'column' => 'strabo_internal_id', 'code' => 'update_into_new',
                                          'message' => 'Rows with an internal id update existing spots — they need an existing target dataset, not a new one.');
                    continue;
                }
            }

            $res = $this->assembleGroup($g, $fieldsPresent, $importCustom, $isUpdate, $cur,
                                        $vocabRes, $softVocab, $hardErrors, $warnings);
            if ($res === null) { continue; }   // hard errors already recorded

            $counts['orientations']   += count($res['orientations']);
            $counts['samples']        += count($res['samples']);
            $counts['other_features'] += count($res['other_features']);

            if (!$isUpdate) {
                if (!array_key_exists('name', $res['spot_set']) || $res['spot_set']['name'] === null) {
                    $hardErrors[] = array('row' => $n0, 'column' => 'spot_name', 'code' => 'name_required',
                                          'message' => 'New spots need a Spot Name.');
                    continue;
                }
                if ($res['lat'] === null || $res['lng'] === null) {
                    $hardErrors[] = array('row' => $n0, 'column' => 'latitude', 'code' => 'coords_required',
                                          'message' => 'New spots need both latitude and longitude.');
                    continue;
                }
                $planRows[] = array(
                    'n' => $n0, 'action' => 'create', 'key' => $key,
                    'name' => $res['spot_set']['name'],
                    'set' => $res['spot_set'], 'geo_unit' => $res['geo_unit'], 'trace' => $res['trace'],
                    'orientations' => $res['orientations'], 'samples' => $res['samples'],
                    'other_features' => $res['other_features'], 'custom' => $res['custom'],
                    'lat' => $res['lat'], 'lng' => $res['lng'],
                );
                $counts['create']++;
                continue;
            }

            // ---- UPDATE: diff against the current node ----
            $diff = $this->diffAgainstCurrent($res, $cur, $fieldsPresent, $g, $hardErrors, $warnings);
            if ($diff === null) { continue; }

            if (empty($diff['changed'])) {
                $planRows[] = array('n' => $n0, 'action' => 'noop', 'key' => $key, 'id' => (int)$g['id'],
                                    'name' => $cur['name']);
                $counts['noop']++;
            } else {
                $planRows[] = array_merge(
                    array('n' => $n0, 'action' => 'update', 'key' => $key, 'id' => (int)$g['id'],
                          'name' => ($res['spot_set'] !== null && isset($res['spot_set']['name']) && $res['spot_set']['name'] !== null)
                                     ? $res['spot_set']['name'] : $cur['name']),
                    $diff['overlay']
                );
                $counts['update']++;
            }
        }

        $clean = empty($hardErrors) && empty($softVocab) && empty($softCustom);

        return array(
            'ok'             => true,
            'clean'          => $clean,
            'counts'         => $counts,
            'rows'           => $planRows,
            'hard_errors'    => $hardErrors,
            'soft_vocab'     => $softVocab,
            'soft_custom'    => $softCustom,
            'warnings'       => $warnings,
            'custom_headers' => $parsed['custom_headers'],
            'spec'           => isset($parsed['spec']) ? $parsed['spec'] : null,
            'target'         => array('project_id' => $projectId, 'dataset_id' => $datasetId,
                                      'dataset_name' => $newDatasetName),
        );
    }

    /**
     * Assemble one spot group: spot-level values (first-row rule +
     * contradiction check), instance lists, custom map. Returns null when
     * the group produced hard errors that make assembly meaningless.
     */
    protected function assembleGroup($g, $fieldsPresent, $importCustom, $isUpdate, $cur,
                                     $vocabRes, &$softVocab, &$hardErrors, &$warnings)
    {
        $cat = self::catalog();
        $spotLevelGroups = array('spot', 'geologic_unit', 'trace');
        $bad = false;

        // ---- spot-level fields: distinct non-blank values must agree ----
        $spotVals = array();   // "group.name" => value|null(clear)
        foreach ($fieldsPresent as $gf => $_) {
            list($grp, $name) = explode('.', $gf, 2);
            if (!in_array($grp, $spotLevelGroups)) { continue; }
            $distinct = array();
            $firstRow = null;
            foreach ($g['rows'] as $rec) {
                $v = isset($rec['values'][$gf]) ? $rec['values'][$gf] : null;
                if ($v !== null) {
                    if ($firstRow === null) { $firstRow = $rec['n']; }
                    $distinct[$v] = true;
                }
            }
            if (count($distinct) > 1) {
                $hardErrors[] = array('row' => $firstRow, 'column' => $gf, 'code' => 'contradiction',
                                      'message' => "Rows of spot '" . ($g['name'] !== null ? $g['name'] : $g['id']) . "' give different values for " . self::displayHeader($grp, $name) . ' (' . implode(' / ', array_slice(array_keys($distinct), 0, 3)) . ').');
                $bad = true;
                continue;
            }
            $spotVals[$gf] = count($distinct) ? key($distinct) : null;
        }

        // ---- typed + vocab-resolved spot-level values ----
        $spotSet = array();  // spot core fields
        $geoUnit = array();  // geologic_unit fields
        $trace   = array();  // trace fields
        $lat = null; $lng = null; $latPresent = false; $lngPresent = false;
        foreach ($spotVals as $gf => $raw) {
            list($grp, $name) = explode('.', $gf, 2);
            $def = self::fieldDef($grp, $name);
            $val = $raw;
            if ($raw !== null) {
                $val = $this->typeAndVocab($raw, $grp, $name, $def, null, $isUpdate, $cur,
                                           $vocabRes, $softVocab, $hardErrors, $g['rows'][0]['n'], $bad);
                if ($val === array()) { continue; }   // vocab pending — provisional raw
            }
            if ($grp === 'spot') {
                if ($name === 'latitude')  { $latPresent = true; $lat = ($raw === null) ? null : $val; continue; }
                if ($name === 'longitude') { $lngPresent = true; $lng = ($raw === null) ? null : $val; continue; }
                $spotSet[$name] = $val;
            } elseif ($grp === 'geologic_unit') {
                $geoUnit[$name] = $val;
            } else {
                $trace[$name] = $val;
            }
        }

        // Companion writes from '__other__' resolutions land as extra keys.
        // (typeAndVocab stores them via $this->pendingCompanions.)
        foreach ($this->pendingCompanions as $pc) {
            if ($pc['group'] === 'spot')               { $spotSet[$pc['name']] = $pc['value']; }
            elseif ($pc['group'] === 'geologic_unit')  { $geoUnit[$pc['name']] = $pc['value']; }
            elseif ($pc['group'] === 'trace')          { $trace[$pc['name']]   = $pc['value']; }
        }
        $this->pendingCompanions = array();

        // lat/lng pairing (creates checked later; updates: both-or-neither when set)
        if (($latPresent || $lngPresent)) {
            $latBlank = $latPresent && $lat === null;
            $lngBlank = $lngPresent && $lng === null;
            if (($lat !== null) !== ($lng !== null) && !($latBlank && $lngBlank)) {
                $hardErrors[] = array('row' => $g['rows'][0]['n'], 'column' => 'latitude', 'code' => 'coord_pair',
                                      'message' => 'Latitude and longitude must be provided together.');
                $bad = true;
            }
        }

        // ---- instances ----
        $orientations = array(); $samples = array(); $others = array();
        $orientCols = array(); $sampleCols = array(); $otherCols = array();
        foreach ($fieldsPresent as $gf => $_) {
            list($grp, ) = explode('.', $gf, 2);
            if ($grp === 'orientation')      { $orientCols[] = $gf; }
            elseif ($grp === 'sample')       { $sampleCols[] = $gf; }
            elseif ($grp === 'other_features') { $otherCols[] = $gf; }
        }

        $lastPrimary = null;
        foreach ($g['rows'] as $rec) {
            // orientation payload?
            $oPayload = array();
            foreach ($orientCols as $gf) {
                if (isset($rec['values'][$gf]) && $rec['values'][$gf] !== null) {
                    $oPayload[substr($gf, strlen('orientation.'))] = $rec['values'][$gf];
                }
            }
            if (!empty($oPayload) || $rec['otype'] !== null) {
                $otShort = $this->resolveOtypeShort($rec['otype']);
                if ($otShort === null) {
                    $hardErrors[] = array('row' => $rec['n'], 'column' => 'orientation_type', 'code' => 'bad_otype',
                                          'message' => ($rec['otype'] === null)
                                              ? 'Rows with orientation values need an orientation_type (planar / linear / tabular_zone).'
                                              : "'{$rec['otype']}' is not a valid orientation_type (planar / linear / tabular_zone).");
                    $bad = true;
                } else {
                    $role = $this->resolveRole($rec['orole']);
                    if ($role === null) {
                        $hardErrors[] = array('row' => $rec['n'], 'column' => 'orientation_role', 'code' => 'bad_role',
                                              'message' => "'{$rec['orole']}' is not a valid orientation_role (primary / associated).");
                        $bad = true;
                    } else {
                        $el = array('type' => self::$OTYPES[$otShort]);
                        foreach ($oPayload as $name => $raw) {
                            $def = self::fieldDef('orientation', $name);
                            if ($def !== null && isset($def['applies_to']) && !in_array($otShort, $def['applies_to'])) {
                                $hardErrors[] = array('row' => $rec['n'], 'column' => 'orientation.' . $name, 'code' => 'inapplicable',
                                                      'message' => self::displayHeader('orientation', $name) . " does not apply to $otShort orientations.");
                                $bad = true;
                                continue;
                            }
                            $val = $this->typeAndVocab($raw, 'orientation', $name, $def, $otShort, $isUpdate, $cur,
                                                       $vocabRes, $softVocab, $hardErrors, $rec['n'], $bad);
                            if ($val === array()) { continue; }
                            $el[$name] = $val;
                        }
                        foreach ($this->pendingCompanions as $pc) { $el[$pc['name']] = $pc['value']; }
                        $this->pendingCompanions = array();

                        if ($role === 'associated') {
                            if ($lastPrimary === null) {
                                $hardErrors[] = array('row' => $rec['n'], 'column' => 'orientation_role', 'code' => 'orphan_associated',
                                                      'message' => 'Associated orientation has no primary orientation row above it (within the same spot).');
                                $bad = true;
                            } else {
                                $orientations[$lastPrimary]['associated_orientation'][] = $el;
                            }
                        } else {
                            $orientations[] = $el;
                            $lastPrimary = count($orientations) - 1;
                        }
                    }
                }
            }

            // sample payload?
            $sPayload = array();
            foreach ($sampleCols as $gf) {
                if (isset($rec['values'][$gf]) && $rec['values'][$gf] !== null) {
                    $sPayload[substr($gf, strlen('sample.'))] = $rec['values'][$gf];
                }
            }
            if (!empty($sPayload)) {
                $el = array();
                foreach ($sPayload as $name => $raw) {
                    $def = self::fieldDef('sample', $name);
                    $val = $this->typeAndVocab($raw, 'sample', $name, $def, null, $isUpdate, $cur,
                                               $vocabRes, $softVocab, $hardErrors, $rec['n'], $bad);
                    if ($val === array()) { continue; }
                    $el[$name] = $val;
                }
                foreach ($this->pendingCompanions as $pc) { $el[$pc['name']] = $pc['value']; }
                $this->pendingCompanions = array();
                $samples[] = $el;
            }

            // other_features payload?
            $fPayload = array();
            foreach ($otherCols as $gf) {
                if (isset($rec['values'][$gf]) && $rec['values'][$gf] !== null) {
                    $fPayload[substr($gf, strlen('other_features.'))] = $rec['values'][$gf];
                }
            }
            if (!empty($fPayload)) {
                $others[] = $fPayload;
            }
        }

        // ---- custom columns: contradiction rule like spot fields ----
        $custom = array();
        foreach ($importCustom as $header => $_) {
            $distinct = array();
            $present = false;
            foreach ($g['rows'] as $rec) {
                if (!array_key_exists($header, $rec['custom'])) { continue; }
                $present = true;
                if ($rec['custom'][$header] !== null) { $distinct[$rec['custom'][$header]] = true; }
            }
            if (!$present) { continue; }
            if (count($distinct) > 1) {
                $hardErrors[] = array('row' => $g['rows'][0]['n'], 'column' => $header, 'code' => 'contradiction',
                                      'message' => "Rows of spot '" . ($g['name'] !== null ? $g['name'] : $g['id']) . "' give different values for custom column '$header'.");
                $bad = true;
                continue;
            }
            $custom[$header] = count($distinct) ? key($distinct) : null;
        }
        if (count($custom) > self::MAX_CUSTOM_KEYS) {
            $hardErrors[] = array('row' => $g['rows'][0]['n'], 'column' => '', 'code' => 'too_many_custom',
                                  'message' => 'More than ' . self::MAX_CUSTOM_KEYS . ' custom fields on one spot.');
            $bad = true;
        }

        if ($bad) { return null; }

        return array(
            'spot_set'       => $spotSet,
            'geo_unit'       => $geoUnit,
            'trace'          => $trace,
            'orientations'   => $orientations,
            'samples'        => $samples,
            'other_features' => $others,
            'custom'         => $custom,
            'lat'            => $lat,
            'lng'            => $lng,
            'lat_present'    => $latPresent,
            'lng_present'    => $lngPresent,
        );
    }

    /** Companion field values produced by '__other__' vocab resolutions. */
    protected $pendingCompanions = array();

    /**
     * Type-coerce + vocab-resolve one raw cell. Returns the typed value;
     * returns array() (empty array sentinel) when the value is a pending
     * soft-vocab issue (row keeps the raw value provisionally).
     */
    protected function typeAndVocab($raw, $group, $name, $def, $otShort, $isUpdate, $cur,
                                    $vocabRes, &$softVocab, &$hardErrors, $rowN, &$bad)
    {
        if ($def === null) { return $raw; }
        $type = isset($def['type']) ? $def['type'] : 'text';

        if ($type === 'integer' || $type === 'decimal') {
            $num = $this->parseNumeric($raw);
            if ($num === null) {
                $hardErrors[] = array('row' => $rowN, 'column' => "$group.$name", 'code' => 'not_numeric',
                                      'message' => "'" . $raw . "' is not a number (" . self::displayHeader($group, $name) . ').');
                $bad = true;
                return $raw;
            }
            if (isset($def['constraint'])) {
                $c = $def['constraint'];
                if ((isset($c['min']) && $num < $c['min']) || (isset($c['max']) && $num > $c['max'])) {
                    $hardErrors[] = array('row' => $rowN, 'column' => "$group.$name", 'code' => 'out_of_range',
                                          'message' => self::displayHeader($group, $name) . " must be between {$c['min']} and {$c['max']} (got $raw).");
                    $bad = true;
                    return $raw;
                }
            }
            return ($type === 'integer') ? (int)round($num) : $num;
        }

        if (($type === 'select_one' || $type === 'select_multiple')) {
            $vocab = $this->vocabFor($def, $otShort);
            if (empty($vocab)) { return $raw; }

            if ($type === 'select_multiple') {
                $tokens = preg_split('/[;,]/', $raw);
                $outTokens = array();
                foreach ($tokens as $tok) {
                    $tok = trim($tok);
                    if ($tok === '') { continue; }
                    $r = $this->resolveVocabToken($tok, $vocab, $group, $name, $isUpdate, $cur,
                                                  $vocabRes, $softVocab, $rowN);
                    if ($r === null) { return array(); }   // pending
                    $outTokens[] = $r;
                }
                return $outTokens;
            }

            $r = $this->resolveVocabToken($raw, $vocab, $group, $name, $isUpdate, $cur,
                                          $vocabRes, $softVocab, $rowN);
            if ($r === null) { return array(); }
            return $r;
        }

        return $raw;
    }

    /** Vocab list for a def, honoring per-orientation-type vocab. */
    protected function vocabFor($def, $otShort)
    {
        if ($otShort !== null && isset($def['vocab_by_type'][$otShort])) {
            return $def['vocab_by_type'][$otShort];
        }
        return isset($def['vocab']) ? $def['vocab'] : array();
    }

    /**
     * Resolve one vocab token. Returns the storage value, the raw string
     * (free-text/'other' pathways), or null when pending review.
     */
    protected function resolveVocabToken($raw, $vocab, $group, $name, $isUpdate, $cur,
                                         $vocabRes, &$softVocab, $rowN)
    {
        $lower = mb_strtolower($raw);
        foreach ($vocab as $v) {
            if (mb_strtolower($v['value']) === $lower || mb_strtolower($v['label']) === $lower) {
                return $v['value'];
            }
        }
        // Round-trip pass-through: value already stored verbatim on this spot.
        if ($isUpdate && $cur !== null && $this->currentContainsVerbatim($cur, $group, $name, $raw)) {
            return $raw;
        }
        $gf = "$group.$name";
        $resKey = isset($vocabRes[$gf][$raw]) ? $vocabRes[$gf][$raw] : null;
        if ($resKey === self::RESOLUTION_FREE_TEXT) {
            return $raw;
        }
        if ($resKey === self::RESOLUTION_OTHER) {
            $companion = $this->companionFieldFor($group, $name);
            if ($companion !== null) {
                $this->pendingCompanions[] = array('group' => $group, 'name' => $companion, 'value' => $raw);
                return 'other';
            }
            return $raw;   // no companion — degrade to free text
        }
        if ($resKey !== null) {
            foreach ($vocab as $v) {
                if ($v['value'] === $resKey || mb_strtolower($v['label']) === mb_strtolower($resKey)) {
                    return $v['value'];
                }
            }
        }
        if (!isset($softVocab[$gf][$raw])) {
            $softVocab[$gf][$raw] = array(
                'count' => 0, 'rows' => array(),
                'label'      => self::displayHeader($group, $name),
                'suggestion' => $this->suggestVocab($raw, $vocab),
                'has_other'  => $this->companionFieldFor($group, $name) !== null,
            );
        }
        $softVocab[$gf][$raw]['count']++;
        $softVocab[$gf][$raw]['rows'][] = $rowN;
        return null;
    }

    /** Field the '__other__' resolution writes the literal into, or null. */
    protected function companionFieldFor($group, $name)
    {
        if (self::fieldDef($group, 'other_' . $name) !== null) { return 'other_' . $name; }
        if ($group === 'orientation' && $name === 'feature_type'
            && self::fieldDef('orientation', 'other_feature') !== null) {
            return 'other_feature';
        }
        return null;
    }

    /** Does the current node already contain this verbatim value for group.name? */
    protected function currentContainsVerbatim($cur, $group, $name, $raw)
    {
        if ($group === 'spot') {
            return isset($cur['scalars'][$name]) && (string)$cur['scalars'][$name] === $raw;
        }
        if ($group === 'geologic_unit' || $group === 'trace') {
            $obj = $cur['groups'][$group === 'geologic_unit' ? 'geologic_unit' : 'trace'];
            if (is_array($obj) && isset($obj[$name])) {
                $v = $obj[$name];
                if (is_array($v)) { return in_array($raw, array_map('strval', $v), true); }
                return (string)$v === $raw;
            }
            return false;
        }
        $listKey = ($group === 'orientation') ? 'orientation_data'
                 : (($group === 'sample') ? 'samples' : 'other_features');
        $list = $cur['groups'][$listKey];
        if (!is_array($list)) { return false; }
        foreach ($list as $el) {
            $el = (array)$el;
            if (isset($el[$name])) {
                $v = $el[$name];
                if (is_array($v) && in_array($raw, array_map('strval', $v), true)) { return true; }
                if (!is_array($v) && (string)$v === $raw) { return true; }
            }
            if (isset($el['associated_orientation']) && is_array($el['associated_orientation'])) {
                foreach ($el['associated_orientation'] as $child) {
                    $child = (array)$child;
                    if (isset($child[$name]) && !is_array($child[$name]) && (string)$child[$name] === $raw) { return true; }
                }
            }
        }
        return false;
    }

    protected function resolveOtypeShort($raw)
    {
        if ($raw === null) { return null; }
        $t = self::canonicalizeHeaderStatic($raw);
        if (isset(self::$OTYPES[$t])) { return $t; }
        foreach (self::$OTYPES as $short => $full) {
            if ($t === $full || $t === str_replace('_orientation', '', $full)) { return $short; }
        }
        if ($t === 'tabular' || $t === 'tabular_zone_orientation') { return 'tabular_zone'; }
        return null;
    }

    protected function resolveRole($raw)
    {
        if ($raw === null) { return 'primary'; }
        $t = self::canonicalizeHeaderStatic($raw);
        if (in_array($t, array('primary', 'top', 'main'))) { return 'primary'; }
        if (in_array($t, array('associated', 'assoc', 'child'))) { return 'associated'; }
        return null;
    }

    protected function suggestVocab($raw, array $vocab)
    {
        $best = null; $bestDist = 4;
        $rawLower = mb_strtolower($raw);
        foreach ($vocab as $v) {
            foreach (array($v['label'], $v['value']) as $cand) {
                $d = levenshtein($rawLower, mb_strtolower($cand));
                if ($d < $bestDist) { $bestDist = $d; $best = $v['label']; }
            }
        }
        return $best;
    }

    protected function parseNumeric($s)
    {
        $s = trim((string)$s);
        if (preg_match('/^-?\d+,\d+$/', $s)) { $s = str_replace(',', '.', $s); }
        if (!is_numeric($s)) { return null; }
        return (float)$s;
    }

    // ------------------------------------------------------------------
    // Current-node access
    // ------------------------------------------------------------------

    /**
     * Bulk-fetch raw Spot nodes by id (owner-scoped). Returns
     * id => {scalars, groups (decoded), raw (all node props verbatim),
     *        name, wkt, origwkt, geometrytype, image_basemap}
     */
    protected function fetchSpotNodes(array $ids)
    {
        $out = array();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) { return $out; }
        foreach (array_chunk($ids, 200) as $chunk) {
            $list = implode(',', $chunk);
            $records = $this->neodb->get_results(
                "MATCH (s:Spot) WHERE s.id IN [$list] AND s.userpkey = {$this->userpkey} RETURN s");
            if (!is_array($records)) { continue; }
            foreach ($records as $record) {
                $props = $record->get('s')->values();
                $out[(int)$props['id']] = $this->nodeToCurrent($props);
            }
        }
        return $out;
    }

    protected function nodeToCurrent($props)
    {
        $groups = array();
        foreach (array('orientation_data', 'samples', 'other_features', 'geologic_unit', 'trace', 'custom_fields') as $k) {
            $rawv = isset($props['json_' . $k]) ? $props['json_' . $k] : (isset($props[$k]) ? $props[$k] : null);
            $decoded = null;
            if ($rawv !== null && $rawv !== '') {
                $decoded = is_string($rawv) ? json_decode($rawv, true) : json_decode(json_encode($rawv), true);
            }
            $groups[$k] = $decoded;
        }
        $scalars = array();
        foreach (array('name', 'date', 'time', 'notes', 'altitude', 'gps_accuracy', 'spot_radius') as $k) {
            $scalars[$k] = isset($props[$k]) ? $props[$k] : null;
        }
        return array(
            'raw'           => $props,
            'scalars'       => $scalars,
            'groups'        => $groups,
            'name'          => isset($props['name']) ? $props['name'] : null,
            'wkt'           => isset($props['wkt']) ? $props['wkt'] : null,
            'origwkt'       => isset($props['origwkt']) ? $props['origwkt'] : null,
            'geometrytype'  => isset($props['geometrytype']) ? $props['geometrytype'] : null,
            'image_basemap' => isset($props['image_basemap']) ? $props['image_basemap'] : null,
        );
    }

    /** Which of $ids have a HAS_SPOT edge from the given (owned) dataset. */
    protected function spotIdsInDataset($datasetId, array $ids)
    {
        $out = array();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) { return $out; }
        $datasetId = (int)$datasetId;
        foreach (array_chunk($ids, 200) as $chunk) {
            $list = implode(',', $chunk);
            $records = $this->neodb->get_results(
                "MATCH (d:Dataset {id: $datasetId, userpkey: {$this->userpkey}})-[:HAS_SPOT]->(s:Spot)
                 WHERE s.id IN [$list] RETURN s.id AS id");
            if (!is_array($records)) { continue; }
            foreach ($records as $r) {
                $out[(int)$r->value('id')] = true;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Diff (updates)
    // ------------------------------------------------------------------

    /**
     * Compare assembled group data against the current node. Returns
     * {changed: bool, overlay: {...}} — overlay is what commit applies:
     *   set:      spot scalar field => value|null(clear)
     *   geo_unit / trace: field => value|null  (merged into the object)
     *   groups:   orientation_data|samples|other_features => full new list
     *             (only present when the group is being replaced/cleared)
     *   custom:   header => value|null
     *   geom:     {lat, lng} when the point moved
     */
    protected function diffAgainstCurrent($res, $cur, $fieldsPresent, $g, &$hardErrors, &$warnings)
    {
        $n0 = $g['rows'][0]['n'];
        $overlay = array('set' => array(), 'geo_unit' => array(), 'trace' => array(),
                         'groups' => array(), 'custom' => array(), 'geom' => null);
        $changed = false;

        // name blank-clear guard
        if (array_key_exists('name', $res['spot_set']) && $res['spot_set']['name'] === null) {
            $hardErrors[] = array('row' => $n0, 'column' => 'spot_name', 'code' => 'name_required',
                                  'message' => 'Spot Name cannot be blanked. Remove the column to leave names untouched.');
            return null;
        }

        // ---- spot scalars ----
        foreach ($res['spot_set'] as $name => $val) {
            $curVal = isset($cur['scalars'][$name]) ? $cur['scalars'][$name] : null;
            if ($this->valuesDiffer($val, $curVal)) {
                $overlay['set'][$name] = $val;
                $changed = true;
            }
        }

        // ---- geologic_unit / trace (object merges) ----
        foreach (array('geo_unit' => 'geologic_unit', 'trace' => 'trace') as $resKey => $groupKey) {
            $curObj = is_array($cur['groups'][$groupKey]) ? $cur['groups'][$groupKey] : array();
            foreach ($res[$resKey] as $name => $val) {
                $curVal = isset($curObj[$name]) ? $curObj[$name] : null;
                if ($this->valuesDiffer($val, $curVal)) {
                    $overlay[$resKey][$name] = $val;
                    $changed = true;
                }
            }
        }

        // ---- multi-instance groups: projection compare ----
        $orientFields = $sampleFields = $otherFields = array();
        foreach ($fieldsPresent as $gf => $_) {
            list($grp, $name) = explode('.', $gf, 2);
            if ($grp === 'orientation') { $orientFields[] = $name; }
            elseif ($grp === 'sample') { $sampleFields[] = $name; }
            elseif ($grp === 'other_features') { $otherFields[] = $name; }
        }

        if (!empty($orientFields)) {
            $curList = is_array($cur['groups']['orientation_data']) ? $cur['groups']['orientation_data'] : array();
            $fileProj = $this->projectOrientations($res['orientations'], $orientFields);
            $curProj  = $this->projectOrientations($curList, $orientFields);
            if ($fileProj !== $curProj) {
                if (empty($res['orientations']) && !empty($curList)) {
                    $warnings[] = array('row' => $n0, 'column' => 'orientation_type',
                                        'message' => 'Removes all ' . count($curList) . " orientations from spot '" . ($res['spot_set'] ? reset($res['spot_set']) : $cur['name']) . "'.");
                }
                $overlay['groups']['orientation_data'] = $res['orientations'];
                $changed = true;
            }
        }

        if (!empty($sampleFields)) {
            $curList = is_array($cur['groups']['samples']) ? $cur['groups']['samples'] : array();
            $fileProj = $this->projectElements($res['samples'], $sampleFields);
            $curProj  = $this->projectElements($curList, $sampleFields);
            if ($fileProj !== $curProj) {
                if (empty($res['samples']) && !empty($curList)) {
                    $warnings[] = array('row' => $n0, 'column' => 'sample',
                                        'message' => 'Removes all ' . count($curList) . ' samples from this spot.');
                }
                // merge-by-sample_id_name: preserve uncovered fields + element ids
                $overlay['groups']['samples'] = $this->mergeSamples($res['samples'], $curList, $sampleFields);
                $changed = true;
            }
        }

        if (!empty($otherFields)) {
            $curList = is_array($cur['groups']['other_features']) ? $cur['groups']['other_features'] : array();
            $fileProj = $this->projectElements($res['other_features'], $otherFields);
            $curProj  = $this->projectElements($curList, $otherFields);
            if ($fileProj !== $curProj) {
                $overlay['groups']['other_features'] = $res['other_features'];
                $changed = true;
            }
        }

        // ---- custom fields ----
        if (!empty($res['custom'])) {
            $curCustom = is_array($cur['groups']['custom_fields']) ? $cur['groups']['custom_fields'] : array();
            foreach ($res['custom'] as $k => $v) {
                $curVal = isset($curCustom[$k]) ? $curCustom[$k] : null;
                if ($this->valuesDiffer($v, $curVal)) {
                    $overlay['custom'][$k] = $v;
                    $changed = true;
                }
            }
            $projected = $curCustom;
            foreach ($overlay['custom'] as $k => $v) {
                if ($v === null) { unset($projected[$k]); } else { $projected[$k] = $v; }
            }
            if (count($projected) > self::MAX_CUSTOM_KEYS) {
                $hardErrors[] = array('row' => $n0, 'column' => '', 'code' => 'too_many_custom',
                                      'message' => 'More than ' . self::MAX_CUSTOM_KEYS . ' custom fields on one spot.');
                return null;
            }
        }

        // ---- geometry ----
        // Blank lat/lng cells on an update leave geometry UNTOUCHED (geometry
        // cannot be cleared; blanks are how partial files leave it alone).
        // Filled-in values compare against the current geometry's centroid —
        // for Points that's the point itself; for lines/polygons it matches
        // what export wrote, so an unedited round trip is a noop.
        if ($res['lat'] !== null && $res['lng'] !== null) {
            $curPt = $this->centroidOfWkt($cur['wkt']);
            $moved = ($curPt === null)
                  || abs($curPt['lat'] - $res['lat']) > self::FLOAT_EPSILON
                  || abs($curPt['lng'] - $res['lng']) > self::FLOAT_EPSILON;
            if ($moved) {
                $isPlainPoint = ($cur['geometrytype'] === 'Point')
                             && ((string)$cur['wkt'] === (string)$cur['origwkt'])
                             && ($cur['image_basemap'] === null || $cur['image_basemap'] === '');
                if (!$isPlainPoint) {
                    $gtype = $cur['geometrytype'] !== null ? $cur['geometrytype'] : 'non-point';
                    $hardErrors[] = array('row' => $n0, 'column' => 'latitude', 'code' => 'geometry_read_only',
                                          'message' => "This spot's geometry is $gtype — its location cannot be edited from a spreadsheet (attributes still can be).");
                    return null;
                }
                $overlay['geom'] = array('lat' => $res['lat'], 'lng' => $res['lng']);
                $changed = true;
            }
        }

        return array('changed' => $changed, 'overlay' => $overlay);
    }

    /** Loose scalar comparison: numeric epsilon, string exact, null-aware. */
    protected function valuesDiffer($new, $curVal)
    {
        $curNull = ($curVal === null || $curVal === '');
        if ($new === null) { return !$curNull; }
        if ($curNull) { return true; }
        if (is_array($new)) {
            $curArr = is_array($curVal) ? $curVal : (is_string($curVal) ? json_decode($curVal, true) : null);
            if (!is_array($curArr)) { return true; }
            return array_map('strval', $new) !== array_map('strval', $curArr);
        }
        if ((is_int($new) || is_float($new)) && is_numeric($curVal)) {
            return abs((float)$new - (float)$curVal) > self::FLOAT_EPSILON;
        }
        return (string)$new !== (string)$curVal;
    }

    /** Template-covered projection of orientation elements (incl. associated). */
    protected function projectOrientations($list, array $fields)
    {
        $out = array();
        foreach ((array)$list as $el) {
            $el = (array)$el;
            $proj = $this->projectOne($el, $fields);
            $proj['_type'] = isset($el['type']) ? (string)$el['type'] : '';
            $proj['_assoc'] = array();
            if (isset($el['associated_orientation']) && is_array($el['associated_orientation'])) {
                foreach ($el['associated_orientation'] as $child) {
                    $child = (array)$child;
                    $cp = $this->projectOne($child, $fields);
                    $cp['_type'] = isset($child['type']) ? (string)$child['type'] : '';
                    $proj['_assoc'][] = $cp;
                }
            }
            $out[] = $proj;
        }
        return $out;
    }

    protected function projectElements($list, array $fields)
    {
        $out = array();
        foreach ((array)$list as $el) {
            $out[] = $this->projectOne((array)$el, $fields);
        }
        return $out;
    }

    protected function projectOne(array $el, array $fields)
    {
        $proj = array();
        foreach ($fields as $f) {
            if (!isset($el[$f]) || $el[$f] === '' || $el[$f] === null) {
                $proj[$f] = null;
            } elseif (is_array($el[$f])) {
                $vals = array_map('strval', $el[$f]);
                sort($vals);
                $proj[$f] = $vals;
            } else {
                $proj[$f] = (string)$el[$f];
            }
        }
        return $proj;
    }

    /**
     * Replacement sample list that preserves uncovered fields + ids of
     * current elements matched by sample_id_name (spine identity).
     */
    protected function mergeSamples(array $fileSamples, array $curList, array $coveredFields)
    {
        $curByName = array();
        foreach ($curList as $el) {
            $el = (array)$el;
            if (isset($el['sample_id_name']) && $el['sample_id_name'] !== '') {
                $curByName[mb_strtolower((string)$el['sample_id_name'])][] = $el;
            }
        }
        $out = array();
        foreach ($fileSamples as $fs) {
            $key = isset($fs['sample_id_name']) ? mb_strtolower((string)$fs['sample_id_name']) : null;
            if ($key !== null && !empty($curByName[$key])) {
                $base = array_shift($curByName[$key]);
                foreach ($coveredFields as $f) {
                    if (array_key_exists($f, $fs)) { $base[$f] = $fs[$f]; }
                    elseif (in_array($f, $coveredFields) && !isset($fs[$f])) { unset($base[$f]); }
                }
                $out[] = $base;
            } else {
                $fs['id'] = $this->mintElementId();
                $out[] = $fs;
            }
        }
        return $out;
    }

    /** Centroid coords of any WKT geometry (a Point is its own centroid). */
    protected function centroidOfWkt($wkt)
    {
        if ($wkt === null || $wkt === '') { return null; }
        if (preg_match('/^\s*POINT\s*\(\s*(-?[\d.]+)[\s,]+(-?[\d.]+)/i', (string)$wkt, $m)) {
            return array('lng' => (float)$m[1], 'lat' => (float)$m[2]);
        }
        try {
            $g = geoPHP::load((string)$wkt, 'wkt');
            $c = $g->centroid();
            if ($c) { return array('lng' => (float)$c->x(), 'lat' => (float)$c->y()); }
        } catch (Exception $e) { /* fall through */ }
        return null;
    }

    protected $elementIdCounter = 0;

    protected function mintElementId()
    {
        // time-based, unique within a run — the app's ms-epoch id style
        return (int)(round(microtime(true) * 1000) + (++$this->elementIdCounter));
    }

    protected function mintSpotId()
    {
        // time().rand convention shared with load_shapefile.php getid()
        usleep(1000);
        return (int)(time() . rand(1111, 9999));
    }

    // ========================================================================
    // Commit — plan-clean gate + journaled compensating rollback
    // ========================================================================

    /**
     * Execute a CLEAN plan. Neo4j writes cannot share a PG transaction, so:
     *   Phase A builds every write payload up front (creates: full Features
     *           with minted ids; updates: prior Feature + overlaid Feature);
     *   Phase B journals the whole run (field_tabular_runs) BEFORE writing;
     *   Phase C writes through insertSpot/addSpotToDataset with the sample
     *           sync context set; any failure triggers compensating rollback
     *           (delete created spots, restore updated ones from their prior
     *           JSON, drop a newly created dataset).
     *
     * @return array {ok, run_id, created, updated, noop, dataset_id, minted}
     *               | {ok:false, error, message, rolled_back, run_id}
     */
    public function commit($plan)
    {
        if (empty($plan['clean'])) {
            return array('ok' => false, 'error' => 'plan_not_clean',
                         'message' => 'The plan has unresolved issues.', 'rolled_back' => false);
        }
        $target    = $plan['target'];
        $projectId = (int)$target['project_id'];
        $datasetId = !empty($target['dataset_id']) ? (int)$target['dataset_id'] : null;
        $newDataset = ($datasetId === null);

        // ---- Phase A: build all payloads ----
        $writes = array();   // [{action, spot_id, feature, prior?}]
        $nowMs = (int)round(microtime(true) * 1000);
        foreach ($plan['rows'] as $row) {
            if ($row['action'] === 'noop') { continue; }
            if ($row['action'] === 'create') {
                $spotId = $this->mintSpotId();
                $writes[] = array(
                    'action'  => 'create',
                    'spot_id' => $spotId,
                    'feature' => $this->buildCreateFeature($row, $spotId, $nowMs),
                );
            } else {
                $spotId = (int)$row['id'];
                $node = $this->fetchSpotNodeWithImages($spotId);
                if ($node === null) {
                    return array('ok' => false, 'error' => 'spot_vanished', 'rolled_back' => false,
                                 'message' => "Spot $spotId disappeared between review and commit. Re-upload the file.");
                }
                $prior = $this->buildFeatureFromNode($node['props'], $node['images']);
                $new   = $this->applyOverlay($prior, $row, $nowMs);
                $writes[] = array(
                    'action'  => 'update',
                    'spot_id' => $spotId,
                    'feature' => $new,
                    'prior'   => $prior,
                );
            }
        }

        // ---- Phase B: journal before any write ----
        $journalRows = array();
        foreach ($writes as $w) {
            $jr = array('action' => $w['action'], 'spot_id' => $w['spot_id']);
            if ($w['action'] === 'update') { $jr['prior'] = $w['prior']; }
            $journalRows[] = $jr;
        }
        $inserted = $this->db->get_var_prepared(
            "INSERT INTO field_tabular_runs
                    (userpkey, project_id, dataset_id, dataset_new, template, plan_counts, rows, status)
             VALUES ($1, $2, $3, $4, $5::jsonb, $6::jsonb, $7::jsonb, 'started')",
            array($this->userpkey, (string)$projectId,
                  $datasetId !== null ? (string)$datasetId : '',
                  $newDataset ? 't' : 'f',
                  json_encode(isset($plan['spec']) ? $plan['spec'] : null),
                  json_encode($plan['counts']),
                  json_encode($journalRows))
        );
        // RETURNING rows are discarded for INSERTs by the prepared layer —
        // currval on the same connection is the reliable read-back.
        $runId = $this->db->get_var_prepared("SELECT currval('field_tabular_runs_pkey_seq')", array());
        if ($runId === null) {
            return array('ok' => false, 'error' => 'journal_failed', 'rolled_back' => false,
                         'message' => 'Could not record the import journal — nothing was written.');
        }
        $runId = (int)$runId;

        // ---- Phase C: writes ----
        $createdDone = array();   // spot ids
        $updatedDone = array();   // spot id => prior feature
        $datasetCreated = false;
        $failure = null;

        try {
            if ($newDataset) {
                $datasetId = $this->mintSpotId();
                $dsJson = json_encode(array(
                    'userpkey'           => $this->userpkey,
                    'id'                 => $datasetId,
                    'name'               => $target['dataset_name'],
                    'datecreated'        => time(),
                    'datasettype'        => 'tabular',
                    'modified_timestamp' => $nowMs,
                ));
                $dsNeoId = $this->neodb->createNode($dsJson, 'Dataset');
                if (!$dsNeoId) { throw new Exception('Dataset creation failed.'); }
                $projNeoId = $this->strabo->straboIDToID($projectId, 'Project');
                if (!$projNeoId) { throw new Exception('Target project node not found.'); }
                $this->neodb->addRelationship($projNeoId, $dsNeoId, 'HAS_DATASET');
                $datasetCreated = true;
                $this->db->get_var_prepared(
                    "UPDATE field_tabular_runs SET dataset_id = $1 WHERE pkey = $2 RETURNING pkey",
                    array((string)$datasetId, $runId));
            }

            // Sample-bearing rows mirror into strabosamples.* with full context
            // (Phase I lesson: set BEFORE the loop, not per spot).
            $this->strabo->setSampleSyncContext((string)$projectId, (string)$datasetId);

            // StraboSearch live-sync (§5.3.4): suppress per-spot touches for
            // the import loop; batch sync fires on the success path below.
            // The rollback path relies on per-item hooks instead (resume
            // happens before it runs).
            require_once __DIR__ . '/../../db/lib/search_sync.php';
            field_search_sync_suppress();

            foreach ($writes as $w) {
                $result = $this->strabo->insertSpot(json_encode($w['feature']));
                if (!$this->insertSucceeded($result, $w['spot_id'])) {
                    throw new Exception(($w['action'] === 'create' ? 'Create' : 'Update')
                        . " of spot {$w['spot_id']} failed"
                        . $this->describeInsertError($result));
                }
                if ($w['action'] === 'create') {
                    // The spot exists as soon as insertSpot succeeds — record it
                    // BEFORE the link attempt so a link failure still gets it
                    // rolled back. Pre-resolve both node ids: passing an empty
                    // id into addRelationship produces malformed Cypher, and a
                    // Bolt protocol error wedges the connection for every
                    // subsequent query (observed against Neo4j 3.0).
                    $createdDone[] = $w['spot_id'];
                    $dsNeo = $this->strabo->straboIDToID($datasetId, 'Dataset');
                    $spNeo = $this->strabo->straboIDToID($w['spot_id'], 'Spot');
                    if ($dsNeo === '' || $dsNeo === null || $spNeo === '' || $spNeo === null) {
                        throw new Exception("Could not link spot {$w['spot_id']} to the dataset (dataset or spot node missing).");
                    }
                    $rel = $this->neodb->addRelationship($dsNeo, $spNeo, 'HAS_SPOT', 'Dataset', 'Spot');
                    if (!$rel) {
                        throw new Exception("Could not link spot {$w['spot_id']} to the dataset.");
                    }
                } else {
                    $updatedDone[$w['spot_id']] = $w['prior'];
                }
            }

            // PG search mirror for a new dataset (needs spots present).
            if ($datasetCreated && !empty($createdDone)) {
                $this->strabo->buildPgDataset($datasetId);
            }
        } catch (Exception $e) {
            $failure = $e->getMessage();
        }
        $this->strabo->clearSampleSyncContext();
        field_search_sync_resume();

        if ($failure === null) {
            $minted = array();
            foreach ($writes as $w) {
                if ($w['action'] === 'create') { $minted[] = $w['spot_id']; }
            }
            $this->db->get_var_prepared(
                "UPDATE field_tabular_runs SET status = 'committed', finished_at = now() WHERE pkey = $1 RETURNING pkey",
                array($runId));

            // StraboSearch live-sync (§5.3.4): end-of-import batch sync.
            field_search_sync_dataset($this->db, $this->neodb, $datasetId, $this->userpkey);

            return array(
                'ok' => true, 'run_id' => $runId,
                'created' => count($createdDone),
                'updated' => count($updatedDone),
                'noop'    => $plan['counts']['noop'],
                'dataset_id' => $datasetId,
                'dataset_created' => $datasetCreated,
                'minted' => $minted,
            );
        }

        // ---- compensating rollback ----
        $rollbackErrors = array();
        foreach (array_reverse($createdDone) as $sid) {
            try {
                $this->strabo->deleteSingleSpot($sid);
            } catch (Exception $e) {
                $rollbackErrors[] = "delete $sid: " . $e->getMessage();
            }
        }
        foreach ($updatedDone as $sid => $prior) {
            try {
                // Fresh timestamp so the restore wins insertSpot's conflict gate.
                $prior['properties']['modified_timestamp'] = (int)round(microtime(true) * 1000);
                $result = $this->strabo->insertSpot(json_encode($prior));
                if (!$this->insertSucceeded($result, $sid)) {
                    $rollbackErrors[] = "restore $sid failed" . $this->describeInsertError($result);
                }
            } catch (Exception $e) {
                $rollbackErrors[] = "restore $sid: " . $e->getMessage();
            }
        }
        if ($datasetCreated) {
            try {
                $this->neodb->query("MATCH (d:Dataset {id: $datasetId, userpkey: {$this->userpkey}}) DETACH DELETE d");
                $this->db->get_var_prepared(
                    "DELETE FROM dataset WHERE user_pkey = $1 AND strabo_dataset_id = $2 RETURNING strabo_dataset_id",
                    array($this->userpkey, (string)$datasetId));
            } catch (Exception $e) {
                $rollbackErrors[] = 'dataset cleanup: ' . $e->getMessage();
            }
        }

        $status = empty($rollbackErrors) ? 'rolled_back' : 'rollback_failed';
        $errText = $failure . (empty($rollbackErrors) ? '' : ' | ROLLBACK: ' . implode('; ', $rollbackErrors));
        $this->db->get_var_prepared(
            "UPDATE field_tabular_runs SET status = $1, error = $2, finished_at = now() WHERE pkey = $3 RETURNING pkey",
            array($status, $errText, $runId));

        return array(
            'ok' => false, 'error' => 'commit_failed', 'run_id' => $runId,
            'rolled_back' => empty($rollbackErrors),
            'message' => empty($rollbackErrors)
                ? "Nothing was imported. Cause: $failure"
                : "Import failed AND rollback hit errors — contact support with run #$runId. Cause: $failure",
        );
    }

    protected function insertSucceeded($result, $spotId)
    {
        if (is_object($result) && isset($result->properties) && !isset($result->Error)) {
            return ((int)$result->properties->id) === (int)$spotId;
        }
        return false;
    }

    protected function describeInsertError($result)
    {
        if (is_array($result) && isset($result['Error'])) { return ': ' . $result['Error']; }
        if (is_object($result) && isset($result->Error)) { return ': ' . $result->Error; }
        return '.';
    }

    /** Fetch one spot node + its image nodes (owner-scoped). */
    protected function fetchSpotNodeWithImages($spotId)
    {
        $spotId = (int)$spotId;
        $records = $this->neodb->get_results(
            "MATCH (s:Spot {id: $spotId, userpkey: {$this->userpkey}})
             OPTIONAL MATCH (s)-[:HAS_IMAGE]->(i:Image)
             WITH s, collect(i) AS i RETURN s, i");
        if (!is_array($records) || !count($records)) { return null; }
        $record = $records[0];
        $props = $record->get('s')->values();
        $images = array();
        foreach ($record->get('i') as $imageNode) {
            $images[] = $imageNode->values();
        }
        return array('props' => $props, 'images' => $images);
    }

    /**
     * Rebuild a full insertSpot-ready Feature (assoc array) from raw node
     * props + image node props. json_* groups decode to their bare names so
     * loadAdditionalNodes/loadImages recreate every child node; wkt/origwkt
     * ride verbatim in properties so geometry never drifts (pixel-basemap
     * spots keep their distinct wkt vs origwkt).
     */
    protected function buildFeatureFromNode($props, $images)
    {
        $properties = array();
        foreach ($props as $key => $value) {
            if ($key === 'bbox' || $key === 'gtype') { continue; }   // insertSpot preserves these itself
            if (strpos($key, 'json_') === 0) {
                $bare = substr($key, 5);
                $decoded = is_string($value) ? json_decode($value, true) : null;
                $properties[$bare] = ($decoded !== null) ? $decoded : $value;
            } else {
                $properties[$key] = $value;
            }
        }
        if (!empty($images)) {
            $properties['images'] = array_values($images);
        }
        $geometry = null;
        if (isset($props['wkt']) && $props['wkt'] !== '') {
            try {
                $g = geoPHP::load($props['wkt'], 'wkt');
                $geometry = json_decode($g->out('json'), true);
            } catch (Exception $e) {
                $geometry = null;
            }
        }
        return array('type' => 'Feature', 'geometry' => $geometry, 'properties' => $properties);
    }

    /** Apply an update overlay (from diffAgainstCurrent) to a prior Feature. */
    protected function applyOverlay($prior, $row, $nowMs)
    {
        $feature = json_decode(json_encode($prior), true);   // deep copy
        $p = &$feature['properties'];

        foreach ($row['set'] as $name => $val) {
            if ($val === null) { unset($p[$name]); }
            else { $p[$name] = $val; }
        }

        foreach (array('geo_unit' => 'geologic_unit', 'trace' => 'trace') as $resKey => $propKey) {
            if (empty($row[$resKey])) { continue; }
            $obj = array();
            if (isset($p[$propKey])) {
                $obj = is_string($p[$propKey]) ? json_decode($p[$propKey], true) : (array)$p[$propKey];
                if (!is_array($obj)) { $obj = array(); }
            }
            foreach ($row[$resKey] as $name => $val) {
                if ($val === null) { unset($obj[$name]); }
                else { $obj[$name] = $val; }
            }
            if (empty($obj)) { unset($p[$propKey]); }
            else { $p[$propKey] = $obj; }
        }

        if (!empty($row['groups'])) {
            foreach ($row['groups'] as $groupKey => $list) {
                $list = $this->mintInstanceIds($list);
                if (empty($list)) { unset($p[$groupKey]); }
                else { $p[$groupKey] = $list; }
            }
        }

        if (!empty($row['custom'])) {
            $custom = array();
            if (isset($p['custom_fields'])) {
                $custom = is_string($p['custom_fields']) ? json_decode($p['custom_fields'], true) : (array)$p['custom_fields'];
                if (!is_array($custom)) { $custom = array(); }
            }
            foreach ($row['custom'] as $k => $v) {
                if ($v === null) { unset($custom[$k]); }
                else { $custom[$k] = $v; }
            }
            if (empty($custom)) { unset($p['custom_fields']); }
            else { $p['custom_fields'] = $custom; }
        }

        if (!empty($row['geom'])) {
            $wkt = 'POINT(' . $row['geom']['lng'] . ' ' . $row['geom']['lat'] . ')';
            $p['wkt'] = $wkt;
            $p['origwkt'] = $wkt;
            $feature['geometry'] = array('type' => 'Point',
                'coordinates' => array($row['geom']['lng'], $row['geom']['lat']));
        }

        $p['modified_timestamp'] = $nowMs;
        return $feature;
    }

    /** Build a brand-new Feature from a create plan row. */
    protected function buildCreateFeature($row, $spotId, $nowMs)
    {
        $p = array('id' => $spotId);
        foreach ($row['set'] as $name => $val) {
            if ($val !== null) { $p[$name] = $val; }
        }
        if (!isset($p['date']) || $p['date'] === null) { $p['date'] = date('c'); }
        if (!isset($p['time']) || $p['time'] === null) { $p['time'] = date('c'); }
        $p['modified_timestamp'] = $nowMs;

        foreach (array('geo_unit' => 'geologic_unit', 'trace' => 'trace') as $resKey => $propKey) {
            $obj = array();
            foreach ($row[$resKey] as $name => $val) {
                if ($val !== null) { $obj[$name] = $val; }
            }
            if (!empty($obj)) { $p[$propKey] = $obj; }
        }
        if (!empty($row['orientations'])) {
            $p['orientation_data'] = $this->mintInstanceIds($row['orientations']);
        }
        if (!empty($row['samples'])) {
            $p['samples'] = $this->mintInstanceIds($row['samples']);
        }
        if (!empty($row['other_features'])) {
            $p['other_features'] = $this->mintInstanceIds($row['other_features']);
        }
        $custom = array();
        foreach ($row['custom'] as $k => $v) {
            if ($v !== null) { $custom[$k] = $v; }
        }
        if (!empty($custom)) { $p['custom_fields'] = $custom; }

        $wkt = 'POINT(' . $row['lng'] . ' ' . $row['lat'] . ')';
        $p['wkt'] = $wkt;
        $p['origwkt'] = $wkt;

        return array(
            'type' => 'Feature',
            'geometry' => array('type' => 'Point', 'coordinates' => array($row['lng'], $row['lat'])),
            'properties' => $p,
        );
    }

    /** Ensure every instance element (and associated child) carries an id. */
    protected function mintInstanceIds($list)
    {
        $out = array();
        foreach ((array)$list as $el) {
            $el = (array)$el;
            if (!isset($el['id']) || $el['id'] === '' || $el['id'] === null) {
                $el['id'] = $this->mintElementId();
            }
            if (isset($el['associated_orientation']) && is_array($el['associated_orientation'])) {
                $el['associated_orientation'] = $this->mintInstanceIds($el['associated_orientation']);
            }
            $out[] = $el;
        }
        return $out;
    }

    // ========================================================================
    // Export — dataset through a template, long format
    // ========================================================================

    /**
     * @return array {ok, headers, rows, dataset_name} | {ok:false, message}
     * Rows are assoc header => string. strabo_internal_id + spot name repeat
     * on every row; other spot-level cells appear on the first row only.
     */
    public function exportLong($datasetId, $spec)
    {
        $datasetId = (int)$datasetId;
        if (!$this->ownsDataset($datasetId)) {
            return array('ok' => false, 'message' => 'Dataset not found (datasets you own only).');
        }
        $v = $this->validateSpec($spec);
        if (empty($v['ok'])) { return $v; }
        $spec = $v['spec'];
        $defs = $this->columnDefs($spec);

        $dsName = $this->neodb->get_var(
            "MATCH (d:Dataset {id: $datasetId, userpkey: {$this->userpkey}}) RETURN d.name");

        $fc = $this->strabo->getDatasetSpots($datasetId);
        $features = array();
        if (is_array($fc) && isset($fc['features'])) {
            $features = json_decode(json_encode($fc['features']), true);
        }

        // geometry_type only materializes when the data needs it: inject the
        // column (after strabo_internal_id) when the dataset carries any
        // non-point spots and the template didn't opt in explicitly. It is
        // export context — the warning that a row's lat/lng is a centroid,
        // not an editable location; the server ignores it on upload.
        $hasGeomCol = false; $idIdx = null;
        foreach ($defs as $i => $d) {
            if ($d['kind'] === 'system' && $d['key'] === 'geometry_type') { $hasGeomCol = true; }
            if ($d['kind'] === 'system' && $d['key'] === 'strabo_internal_id') { $idIdx = $i; }
        }
        if (!$hasGeomCol) {
            $hasNonPoint = false;
            foreach ($features as $f) {
                if (isset($f['geometry']['type']) && $f['geometry']['type'] !== 'Point') {
                    $hasNonPoint = true; break;
                }
            }
            if ($hasNonPoint) {
                array_splice($defs, ($idIdx !== null ? $idIdx + 1 : 0), 0, array(
                    array('kind' => 'system', 'key' => 'geometry_type', 'header' => 'geometry_type'),
                ));
            }
        }

        // orientation_role only materializes when the data needs it: inject
        // the column (after orientation_type) when the dataset carries any
        // associated orientations and the template didn't opt in explicitly.
        $hasRoleCol = false; $otypeIdx = null;
        foreach ($defs as $i => $d) {
            if ($d['kind'] === 'system' && $d['key'] === 'orientation_role') { $hasRoleCol = true; }
            if ($d['kind'] === 'system' && $d['key'] === 'orientation_type') { $otypeIdx = $i; }
        }
        if (!$hasRoleCol && $otypeIdx !== null) {
            $hasAssoc = false;
            foreach ($features as $f) {
                $list = isset($f['properties']['orientation_data']) ? $f['properties']['orientation_data'] : null;
                if (!is_array($list)) { continue; }
                foreach ($list as $el) {
                    if (!empty($el['associated_orientation'])) { $hasAssoc = true; break 2; }
                }
            }
            if ($hasAssoc) {
                array_splice($defs, $otypeIdx + 1, 0, array(
                    array('kind' => 'system', 'key' => 'orientation_role', 'header' => 'orientation_role'),
                ));
            }
        }

        $headers = array();
        foreach ($defs as $d) { $headers[] = $d['header']; }

        $rows = array();
        foreach ($features as $f) {
            $props = isset($f['properties']) ? $f['properties'] : array();
            $geom  = isset($f['geometry']) ? $f['geometry'] : null;
            $spotId = isset($props['id']) ? (string)$props['id'] : '';
            $name   = isset($props['name']) ? (string)$props['name'] : '';

            // centroid lat/lng
            $lat = ''; $lng = '';
            if (is_array($geom) && isset($geom['type'])) {
                if ($geom['type'] === 'Point' && isset($geom['coordinates'][1])) {
                    $lng = (string)$geom['coordinates'][0];
                    $lat = (string)$geom['coordinates'][1];
                } else {
                    try {
                        $g = geoPHP::load(json_encode($geom), 'json');
                        $c = $g->centroid();
                        if ($c) { $lng = (string)$c->x(); $lat = (string)$c->y(); }
                    } catch (Exception $e) { /* leave blank */ }
                }
            }

            // spot-level cells (first row only, except id + name)
            $spotCells = array();
            foreach ($defs as $d) {
                if ($d['kind'] === 'system') {
                    if ($d['key'] === 'strabo_internal_id') { $spotCells[$d['header']] = $spotId; }
                    elseif ($d['key'] === 'geometry_type') {
                        $spotCells[$d['header']] = is_array($geom) && isset($geom['type']) ? $geom['type'] : '';
                    }
                    continue;
                }
                if ($d['kind'] === 'custom') {
                    $custom = isset($props['custom_fields']) && is_array($props['custom_fields']) ? $props['custom_fields'] : array();
                    $spotCells[$d['header']] = isset($custom[$d['header']]) ? $this->stringifyCell($custom[$d['header']]) : '';
                    continue;
                }
                if ($d['group'] === 'spot') {
                    if ($d['name'] === 'latitude')  { $spotCells[$d['header']] = $lat; continue; }
                    if ($d['name'] === 'longitude') { $spotCells[$d['header']] = $lng; continue; }
                    $spotCells[$d['header']] = isset($props[$d['name']]) ? $this->stringifyCell($props[$d['name']]) : '';
                } elseif ($d['group'] === 'geologic_unit' || $d['group'] === 'trace') {
                    $obj = isset($props[$d['group']]) && is_array($props[$d['group']]) ? $props[$d['group']] : array();
                    $spotCells[$d['header']] = isset($obj[$d['name']]) ? $this->stringifyCell($obj[$d['name']]) : '';
                }
                // instance-group columns are filled per payload row below
            }

            // instance payload rows
            $payloads = array();
            $orientList = isset($props['orientation_data']) && is_array($props['orientation_data']) ? $props['orientation_data'] : array();
            foreach ($orientList as $el) {
                $payloads[] = array('kind' => 'orientation', 'el' => $el, 'role' => '');
                if (isset($el['associated_orientation']) && is_array($el['associated_orientation'])) {
                    foreach ($el['associated_orientation'] as $child) {
                        $payloads[] = array('kind' => 'orientation', 'el' => $child, 'role' => 'associated');
                    }
                }
            }
            $sampleList = isset($props['samples']) && is_array($props['samples']) ? $props['samples'] : array();
            foreach ($sampleList as $el) {
                $payloads[] = array('kind' => 'sample', 'el' => $el);
            }
            $otherList = isset($props['other_features']) && is_array($props['other_features']) ? $props['other_features'] : array();
            foreach ($otherList as $el) {
                $payloads[] = array('kind' => 'other_features', 'el' => $el);
            }

            $emit = max(1, count($payloads));
            for ($i = 0; $i < $emit; $i++) {
                $row = array();
                foreach ($defs as $d) {
                    $h = $d['header'];
                    if ($d['kind'] === 'system') {
                        if ($d['key'] === 'strabo_internal_id') { $row[$h] = $spotId; continue; }
                        if ($d['key'] === 'geometry_type') { $row[$h] = ($i === 0) ? $spotCells[$h] : ''; continue; }
                        // orientation_type / orientation_role below
                        $row[$h] = '';
                        continue;
                    }
                    if ($d['kind'] === 'field' && $d['group'] === 'spot' && $d['name'] === 'name') {
                        $row[$h] = $name;   // repeated every row: the fallback grouping key
                        continue;
                    }
                    if ($d['kind'] === 'custom'
                        || ($d['kind'] === 'field' && in_array($d['group'], array('spot', 'geologic_unit', 'trace')))) {
                        $row[$h] = ($i === 0) ? $spotCells[$h] : '';
                        continue;
                    }
                    $row[$h] = '';
                }
                if (isset($payloads[$i])) {
                    $pl = $payloads[$i];
                    $el = (array)$pl['el'];
                    foreach ($defs as $d) {
                        if ($d['kind'] === 'system') {
                            if ($d['key'] === 'orientation_type' && $pl['kind'] === 'orientation') {
                                $row[$d['header']] = $this->shortOtype(isset($el['type']) ? $el['type'] : '');
                            } elseif ($d['key'] === 'orientation_role' && $pl['kind'] === 'orientation') {
                                $row[$d['header']] = $pl['role'];
                            }
                            continue;
                        }
                        if ($d['kind'] !== 'field') { continue; }
                        if (($pl['kind'] === 'orientation' && $d['group'] === 'orientation')
                            || ($pl['kind'] === 'sample' && $d['group'] === 'sample')
                            || ($pl['kind'] === 'other_features' && $d['group'] === 'other_features')) {
                            $row[$d['header']] = isset($el[$d['name']]) ? $this->stringifyCell($el[$d['name']]) : '';
                        }
                    }
                }
                $rows[] = $row;
            }
        }

        return array('ok' => true, 'headers' => $headers, 'rows' => $rows,
                     'dataset_name' => (string)$dsName, 'spec' => $spec);
    }

    protected function shortOtype($full)
    {
        foreach (self::$OTYPES as $short => $f) {
            if ($f === $full) { return $short; }
        }
        return $full;
    }

    protected function stringifyCell($v)
    {
        if ($v === null) { return ''; }
        if (is_array($v)) {
            $flat = array();
            foreach ($v as $item) {
                $flat[] = is_scalar($item) ? (string)$item : json_encode($item);
            }
            return implode('; ', $flat);
        }
        if (is_bool($v)) { return $v ? 'true' : 'false'; }
        return (string)$v;
    }

    // ========================================================================
    // Workbook / CSV builders
    // ========================================================================

    /**
     * XLSX for a template download or a populated export.
     * @param array $export exportLong() result shape (headers, rows, spec)
     */
    public function buildWorkbook($export, $isTemplate, $templateName = '')
    {
        $this->requirePhpExcel();
        $spec = $export['spec'];
        $defs = $this->columnDefs($spec);
        $headers = $export['headers'];
        $rows = $export['rows'];

        $wb = new PHPExcel();
        $wb->getProperties()->setCreator('strabospot.org')
            ->setLastModifiedBy('strabospot.org')
            ->setTitle($isTemplate ? 'StraboField Import Template' : 'StraboField Export')
            ->setDescription('StraboField tabular ' . ($isTemplate ? 'template' : 'export')
                . ($templateName !== '' ? " — template: $templateName" : ''));

        $wb->getDefaultStyle()->getProtection()
           ->setLocked(PHPExcel_Style_Protection::PROTECTION_UNPROTECTED);

        // ---- Data sheet ----
        // Row 1 = merged color-coded section band ("Spot" / "Orientations"...),
        // row 2 = headers, data from row 3 (Jason 2026-07-04 — disambiguates
        // headers like feature_type/quality by the section they belong to).
        $data = $wb->getActiveSheet();
        $data->setTitle('Data');
        $sectionMeta = self::sectionMeta();
        foreach (self::sectionRuns($headers, $defs) as $run) {
            $meta = $sectionMeta[$run['section']];
            $startL = PHPExcel_Cell::stringFromColumnIndex($run['start']);
            $endL   = PHPExcel_Cell::stringFromColumnIndex($run['end']);
            $data->setCellValue($startL . '1', $meta['label']);
            if ($run['end'] > $run['start']) {
                $data->mergeCells($startL . '1:' . $endL . '1');
            }
            $style = $data->getStyle($startL . '1:' . $endL . '1');
            $style->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)
                  ->getStartColor()->setARGB($meta['fill']);
            $style->getFont()->setBold(true)->getColor()->setARGB($meta['font']);
            $style->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        }
        foreach ($headers as $i => $h) {
            $data->getCellByColumnAndRow($i, 2)->setValue($h);
        }
        $lastCol = PHPExcel_Cell::stringFromColumnIndex(count($headers) - 1);
        $data->getStyle('A2:' . $lastCol . '2')->getFont()->setBold(true);
        $data->freezePane('A3');
        $idCol = array_search('strabo_internal_id', $headers);
        $data->getComment(PHPExcel_Cell::stringFromColumnIndex($idCol === false ? 0 : $idCol) . '2')
             ->getText()->createTextRun(
            'Internal StraboSpot id — do not edit. Repeat it on every row of the same spot. Leave blank on new spots.');

        $rowNum = 3;
        foreach ($rows as $r) {
            foreach ($headers as $i => $h) {
                $val = isset($r[$h]) ? (string)$r[$h] : '';
                if ($val !== '') {
                    $data->getCellByColumnAndRow($i, $rowNum)
                         ->setValueExplicit($val, PHPExcel_Cell_DataType::TYPE_STRING);
                }
            }
            $rowNum++;
        }
        foreach (range(0, count($headers) - 1) as $i) {
            $data->getColumnDimension(PHPExcel_Cell::stringFromColumnIndex($i))->setWidth(18);
        }

        // Lock band + header + id + geometry_type; leave everything else
        // editable. Lock by HEADER position — exportLong may inject
        // geometry_type into headers without it being in the template spec
        // (data-driven materialization), so def indices can't be trusted as
        // column indices. The id/geometry_type columns lock ALL THE WAY DOWN
        // (same row extent as the vocab dropdowns), not just the data rows:
        // users never legitimately type in them — blank id on a new row means
        // create, and a hand-entered or shifted id is the wrong-spot-update /
        // duplicate-create bug class (Jason 2026-07-04; previously blank
        // templates locked nothing and rows below the data were open). The
        // server re-validates every id regardless — this is the advisory
        // first fence, same posture as the samples tabular workbooks.
        $validationRows = max(count($rows) + 200, 500);
        $lockRanges = array('A1:' . $lastCol . '2');
        $lockedHeaders = array('geometry_type' => true);
        foreach ($defs as $d) {
            if ($d['kind'] === 'system' && in_array($d['key'], array('strabo_internal_id', 'geometry_type'))) {
                $lockedHeaders[$d['header']] = true;
            }
        }
        foreach ($headers as $i => $h) {
            if (isset($lockedHeaders[$h])) {
                $colL = PHPExcel_Cell::stringFromColumnIndex($i);
                $lockRanges[] = $colL . '3:' . $colL . (2 + $validationRows);
            }
        }
        foreach ($lockRanges as $range) {
            $data->getStyle($range)->getProtection()
                 ->setLocked(PHPExcel_Style_Protection::PROTECTION_PROTECTED);
        }
        $data->getProtection()->setSheet(true);

        // ---- Vocabulary sheet + dropdowns ----
        $vocab = $wb->createSheet();
        $vocab->setTitle('Vocabulary');
        $vCol = 0;
        $vocabRanges = array();   // header => range
        $vocabColumns = array();
        // exportLong may inject orientation_role into headers without it being
        // in the template spec (data-driven materialization) — cover it here.
        if (in_array('orientation_role', $headers)) {
            $haveRoleDef = false;
            foreach ($defs as $d) {
                if ($d['kind'] === 'system' && $d['key'] === 'orientation_role') { $haveRoleDef = true; }
            }
            if (!$haveRoleDef) {
                $vocabColumns[] = array('header' => 'orientation_role', 'label' => 'Orientation Role',
                                        'values' => array('primary', 'associated'));
            }
        }
        foreach ($defs as $d) {
            if ($d['kind'] === 'system' && $d['key'] === 'orientation_type') {
                $vocabColumns[] = array('header' => $d['header'], 'label' => 'Orientation Type',
                                        'values' => array_keys(self::$OTYPES));
            } elseif ($d['kind'] === 'system' && $d['key'] === 'orientation_role') {
                $vocabColumns[] = array('header' => $d['header'], 'label' => 'Orientation Role',
                                        'values' => array('primary', 'associated'));
            } elseif ($d['kind'] === 'field' && isset($d['def']['vocab']) && count($d['def']['vocab'])) {
                $labels = array();
                foreach ($d['def']['vocab'] as $vv) { $labels[] = $vv['label']; }
                $vocabColumns[] = array('header' => $d['header'],
                                        'label' => self::displayHeader($d['group'], $d['name']),
                                        'values' => $labels);
            }
        }
        foreach ($vocabColumns as $vc) {
            $colL = PHPExcel_Cell::stringFromColumnIndex($vCol);
            $vocab->setCellValue($colL . '1', $vc['label']);
            $vocab->getStyle($colL . '1')->getFont()->setBold(true);
            $vr = 2;
            foreach ($vc['values'] as $val) {
                $vocab->setCellValue($colL . $vr++, $val);
            }
            $vocab->getColumnDimension($colL)->setWidth(30);
            $vocabRanges[$vc['header']] = 'Vocabulary!$' . $colL . '$2:$' . $colL . '$' . ($vr - 1);
            $vCol += 2;
        }
        foreach ($vocabRanges as $header => $range) {
            $colIdx = array_search($header, $headers);
            if ($colIdx === false) { continue; }
            $colL = PHPExcel_Cell::stringFromColumnIndex($colIdx);
            for ($r2 = 3; $r2 <= $validationRows + 2; $r2++) {
                $dv = $data->getCell($colL . $r2)->getDataValidation();
                $dv->setType(PHPExcel_Cell_DataValidation::TYPE_LIST)
                   ->setErrorStyle(PHPExcel_Cell_DataValidation::STYLE_WARNING)
                   ->setAllowBlank(true)
                   ->setShowDropDown(true)
                   ->setShowErrorMessage(true)
                   ->setErrorTitle('Not a standard value')
                   ->setError('Not a standard StraboField value. You can keep it — you will be asked how to import it.')
                   ->setFormula1($range);
            }
        }

        // ---- Instructions sheet ----
        $instr = $wb->createSheet();
        $instr->setTitle('Instructions');
        $lines = array(
            'StraboField tabular ' . ($isTemplate ? 'import template' : 'export')
                . ($templateName !== '' ? " — template: $templateName" : ''),
            '',
            'LONG FORMAT — one row per measurement/sample/feature',
            'A spot with 3 orientations takes 3 rows. Repeat the spot name (and the',
            'strabo_internal_id when present) on EVERY row of the same spot — rows are',
            'grouped by that key, so sorting the sheet never breaks anything.',
            'Spot-level values (coordinates, date, notes...) are read from the first',
            'row of each spot; leave them blank on the other rows (repeats are fine,',
            'contradictions are flagged).',
            '',
            'ORIENTATIONS',
            'orientation_type: planar / linear / tabular_zone — required on any row',
            'carrying orientation values. orientation_role: leave blank for a normal',
            '(primary) measurement; "associated" attaches the row to the primary',
            'measurement above it (keep associated rows directly beneath their primary).',
            '',
            'CREATING vs UPDATING',
            'Blank strabo_internal_id = new spot (name, latitude, longitude required).',
            'A filled-in id = update that spot; export this dataset first and edit the',
            'file — ids must belong to the dataset you upload into. Fields not in this',
            'template are never touched. A blank cell in a column that IS here clears',
            'that field; measurement rows replace the spot\'s full measurement list.',
            '',
            'THINGS TO KNOW',
            '- The colored top row only labels column sections (Spot, Orientations...);',
            '  it is locked and ignored on upload.',
            '- Line/polygon spots export their centroid; their location cannot be',
            '  edited from a spreadsheet (attributes can).',
            '- Dropdowns are suggestions — free text is allowed and reviewed on import.',
            '- Unknown columns import as custom fields after you confirm them.',
            '- Rows are never deleted by an upload.',
            '- Imports are all-or-nothing: any error means nothing is saved.',
            '- To sort in Excel, unprotect the sheet first (Review > Unprotect Sheet,',
            '  no password). Ids are re-validated on upload regardless.',
            '- The locked id column is enforced by Excel and LibreOffice. Some apps',
            '  (Apple Numbers among them) ignore spreadsheet protection — do not type',
            '  in strabo_internal_id there; the wizard rejects invalid ids at review.',
            '- The hidden _template sheet carries your template design; upload this',
            '  file and the wizard recognizes it automatically.',
        );
        $ir = 1;
        foreach ($lines as $line) { $instr->setCellValue('A' . $ir++, $line); }
        $instr->getColumnDimension('A')->setWidth(95);
        $instr->getStyle('A1')->getFont()->setBold(true);

        // ---- hidden _template sheet (embedded spec) ----
        $tpl = $wb->createSheet();
        $tpl->setTitle('_template');
        $tpl->setCellValueExplicit('A1', json_encode($spec), PHPExcel_Cell_DataType::TYPE_STRING);
        $tpl->setCellValueExplicit('A2', $templateName, PHPExcel_Cell_DataType::TYPE_STRING);
        $tpl->setSheetState(PHPExcel_Worksheet::SHEETSTATE_HIDDEN);

        $wb->setActiveSheetIndex(0);
        return $wb;
    }

    /** CSV (UTF-8 BOM). No embedded spec — XLSX is the primary format. */
    public function buildCsv($export)
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $export['headers']);
        foreach ($export['rows'] as $r) {
            $line = array();
            foreach ($export['headers'] as $h) {
                $line[] = isset($r[$h]) ? (string)$r[$h] : '';
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $csv;
    }
}
