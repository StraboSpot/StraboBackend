<?php
/**
 * File: SampleTabularService.php
 * Description: Tabular (XLSX/CSV) import + export for StraboSamples.
 *              Powers the my_samples "Download template / Export /
 *              Import" workflow: users fill out a spreadsheet of flat
 *              spine fields and upload it back; the server validates,
 *              gathers clarifications via a review step, and commits
 *              all-or-nothing.
 *
 *              Design decisions (agreed with Jason 2026-07-03):
 *                - Row identity: upsert via the exported internal id
 *                  (spine PK `id`). Blank id = create (UUID minted);
 *                  id unknown under the caller = hard error.
 *                - Flat spine columns only (no composition/parameters,
 *                  no parent columns in v1).
 *                - Unknown columns import as custom_data key/values
 *                  after user confirmation; lossless round-trip.
 *                - XLSX primary (Data + Instructions + Vocabulary
 *                  sheets, dropdown validation), CSV accepted on upload.
 *                - All-or-nothing commit in one PG transaction; soft
 *                  issues (vocab, custom columns) resolved interactively
 *                  before commit, hard errors block the whole file.
 *                - No deletes via upload. Owner-only in both directions.
 *
 *              All writes route through StraboSamplesService::createSample /
 *              updateSample so changelog, Field writeback, and Micro
 *              dirty-flags fire identically to UI edits. Note: Field
 *              writeback touches Neo4j inside the PG transaction and is
 *              not rolled back on failure — mitigated by full up-front
 *              validation (commit only runs on a clean plan), matching
 *              the writeback path's existing best-effort semantics.
 *
 *              Blank-cell semantics: a PRESENT column with a blank cell
 *              means "clear this field" (NULL); an ABSENT column means
 *              "leave this field untouched". Custom columns behave the
 *              same way per key.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/StraboSamplesService.php';
require_once __DIR__ . '/../lib/vocab.php';

class SampleTabularService
{
    const MAX_ROWS            = 20000;
    const MAX_CELL_CHARS      = 10000;
    const MAX_CUSTOM_KEYS     = 50;
    const STATE_TTL_SECONDS   = 86400;   // parsed-upload state files live 24h
    const FLOAT_EPSILON       = 1e-9;

    /** Review-screen resolution sentinel: keep an unknown vocab value as free text. */
    const RESOLUTION_FREE_TEXT = '__freetext__';

    protected $db;
    protected $neodb;
    protected $userpkey;

    /** @var StraboSamplesService */
    protected $samples;

    /**
     * Canonical template columns, in template order.
     * field === null marks export-only context columns (ignored on upload).
     */
    protected static $COLUMNS = array(
        'strabo_internal_id' => '_id',
        'sample_id'          => 'name',
        'igsn'               => 'igsn',
        'material_type'      => 'display_sample_type',
        'sampling_purpose'   => 'display_sample_purpose',
        'description'        => 'description',
        'notes'              => 'notes',
        'latitude'           => 'latitude',
        'longitude'          => 'longitude',
    );

    /** Accepted header aliases (canonicalized form => field). */
    protected static $ALIASES = array(
        'strabo_internal_id'   => '_id',
        'internal_id'          => '_id',
        'sample_id'            => 'name',
        'sample_name'          => 'name',
        'name'                 => 'name',
        'sample'               => 'name',
        'igsn'                 => 'igsn',
        'material_type'        => 'display_sample_type',
        'sample_type'          => 'display_sample_type',
        'material'             => 'display_sample_type',
        'sampling_purpose'     => 'display_sample_purpose',
        'sample_purpose'       => 'display_sample_purpose',
        'purpose'              => 'display_sample_purpose',
        'main_sampling_purpose'=> 'display_sample_purpose',
        'description'          => 'description',
        'sample_description'   => 'description',
        'notes'                => 'notes',
        'note'                 => 'notes',
        'sample_notes'         => 'notes',
        'latitude'             => 'latitude',
        'lat'                  => 'latitude',
        'longitude'            => 'longitude',
        'lon'                  => 'longitude',
        'lng'                  => 'longitude',
        'long'                 => 'longitude',
    );

    /** Export-only context headers silently dropped on upload. */
    protected static $IGNORED_HEADERS = array(
        'linked_systems', 'modified_at', 'created_at', 'modified', 'last_modified',
        // Export Builder sample-list context columns (read-only, never re-imported)
        'field_project', 'field_dataset', 'field_spot_id', 'field_spot_name',
    );

    public function __construct($db, $neodb)
    {
        $this->db      = $db;
        $this->neodb   = $neodb;
        $this->samples = new StraboSamplesService($db, $neodb);
    }

    public function setUserpkey($pkey)
    {
        $this->userpkey = (int)$pkey;
        $this->samples->setUserpkey($pkey);
    }

    // ========================================================================
    // Parse — file to raw rows
    // ========================================================================

    /**
     * Parse an uploaded .xlsx/.xls/.csv into raw row records.
     *
     * @param string $path            server path of the uploaded file
     * @param string $clientFilename  original filename (drives format pick)
     * @return array ok => bool; on success: header_map, custom_headers,
     *               ignored_headers, rows; on failure: error, message
     */
    public function parseUpload($path, $clientFilename)
    {
        $ext = strtolower(pathinfo($clientFilename, PATHINFO_EXTENSION));
        if (!in_array($ext, array('xlsx', 'xls', 'csv'))) {
            return array('ok' => false, 'error' => 'unsupported_format',
                         'message' => "Unsupported file type '.$ext' — upload .xlsx or .csv.");
        }

        if ($ext === 'csv') {
            $grid = $this->readCsvGrid($path);
        } else {
            $grid = $this->readExcelGrid($path);
        }
        if (isset($grid['error'])) {
            return array('ok' => false, 'error' => $grid['error'], 'message' => $grid['message']);
        }
        $grid = $grid['grid'];

        if (count($grid) > self::MAX_ROWS + 1) {
            return array('ok' => false, 'error' => 'too_many_rows',
                         'message' => 'File exceeds the ' . self::MAX_ROWS . '-row limit.');
        }

        // First non-empty row = header row.
        $headerRowIdx = null;
        foreach ($grid as $i => $row) {
            if (!$this->rowIsEmpty($row)) { $headerRowIdx = $i; break; }
        }
        if ($headerRowIdx === null) {
            return array('ok' => false, 'error' => 'empty_file', 'message' => 'The file contains no data.');
        }

        // Map headers to fields / custom / ignored.
        $headerMap      = array();   // column index => field
        $customHeaders  = array();   // column index => original header text
        $ignoredHeaders = array();
        $seenFields     = array();
        $seenCustom     = array();
        foreach ($grid[$headerRowIdx] as $col => $rawHeader) {
            $header = trim((string)$rawHeader);
            if ($header === '') { continue; }
            $canon = $this->canonicalizeHeader($header);
            if (in_array($canon, self::$IGNORED_HEADERS)) {
                $ignoredHeaders[] = $header;
                continue;
            }
            if (isset(self::$ALIASES[$canon])) {
                $field = self::$ALIASES[$canon];
                if (isset($seenFields[$field])) {
                    return array('ok' => false, 'error' => 'duplicate_column',
                                 'message' => "Columns '{$seenFields[$field]}' and '$header' both map to the same field.");
                }
                $seenFields[$field] = $header;
                $headerMap[$col] = $field;
            } else {
                if (isset($seenCustom[$header])) {
                    return array('ok' => false, 'error' => 'duplicate_column',
                                 'message' => "Custom column '$header' appears more than once.");
                }
                $seenCustom[$header] = true;
                $customHeaders[$col] = $header;
            }
        }
        if (empty($headerMap)) {
            return array('ok' => false, 'error' => 'no_recognized_columns',
                         'message' => 'No recognized StraboSpot columns found in the header row. Download the template for the expected format.');
        }

        // Data rows.
        $rows = array();
        $n = count($grid);
        for ($i = $headerRowIdx + 1; $i < $n; $i++) {
            if ($this->rowIsEmpty($grid[$i])) { continue; }
            $rec = array(
                'n'      => $i + 1,          // 1-based row number as the user sees it
                'id'     => null,
                'values' => array(),
                'custom' => array(),
            );
            foreach ($headerMap as $col => $field) {
                $val = $this->cellToString(isset($grid[$i][$col]) ? $grid[$i][$col] : null);
                if ($field === '_id') {
                    $rec['id'] = ($val === null) ? null : $val;
                } else {
                    $rec['values'][$field] = $val;
                }
            }
            foreach ($customHeaders as $col => $header) {
                $rec['custom'][$header] = $this->cellToString(isset($grid[$i][$col]) ? $grid[$i][$col] : null);
            }
            $rows[] = $rec;
        }

        return array(
            'ok'              => true,
            'fields_present'  => array_values($headerMap),
            'custom_headers'  => array_values($customHeaders),
            'ignored_headers' => $ignoredHeaders,
            'rows'            => $rows,
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
        // Prefer the template's "Data" sheet; fall back to the first sheet.
        $sheet = $wb->getSheetByName('Data');
        if ($sheet === null) {
            $sheet = $wb->getSheet(0);
        }
        $grid = $sheet->toArray(null, true, false, false);
        $wb->disconnectWorksheets();
        unset($wb);
        return array('grid' => $grid);
    }

    protected function readCsvGrid($path)
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return array('error' => 'parse_failed', 'message' => 'Could not read the uploaded file.');
        }
        // Strip UTF-8 BOM; fall back to Windows-1252 for non-UTF-8 exports.
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $content);
            if ($converted !== false) { $content = $converted; }
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $grid = array();
        while (($row = fgetcsv($fh)) !== false) {
            $grid[] = $row;
        }
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
        $h = preg_replace('/\(.*?\)/', '', $header);        // drop parentheticals
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

    /** Normalize any cell to trimmed string or NULL (blank). */
    protected function cellToString($cell)
    {
        if ($cell === null) { return null; }
        if (is_float($cell)) {
            // Avoid float noise like 34.200000000000003 from XLSX numerics.
            $s = rtrim(rtrim(number_format($cell, 10, '.', ''), '0'), '.');
        } else {
            $s = trim((string)$cell);
        }
        return ($s === '') ? null : $s;
    }

    // ========================================================================
    // State files — carry parsed rows between the review POSTs
    // ========================================================================

    /**
     * State files live FLAT in the system temp dir (one file per token),
     * NOT in a shared subdirectory: /tmp is sticky-writable for every
     * user, whereas a subdirectory created by one uid (e.g. a root CLI
     * test run) would silently lock out the Apache uid.
     */
    protected function statePath($token)
    {
        return sys_get_temp_dir() . '/strabo_tabular_' . $token . '.json';
    }

    /** @return string|null token, or null when the state could not be persisted */
    public function saveState($parsed, $clientFilename)
    {
        $this->purgeStaleStates();
        $token = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
        $state = array(
            'userpkey' => $this->userpkey,
            'filename' => $clientFilename,
            'created'  => time(),
            'parsed'   => $parsed,
        );
        $path = $this->statePath($token);
        if (@file_put_contents($path, json_encode($state)) === false) {
            return null;
        }
        @chmod($path, 0600);
        return $token;
    }

    /** @return array|null state, or null when missing/expired/foreign */
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
        return $state;
    }

    public function discardState($token)
    {
        if (preg_match('/^[0-9a-f]{32}$/', (string)$token)) {
            @unlink($this->statePath($token));
        }
    }

    protected function purgeStaleStates()
    {
        $files = @glob(sys_get_temp_dir() . '/strabo_tabular_*.json');
        if (!is_array($files)) { return; }
        clearstatcache();   // mtimes touched earlier in this request must not be served from the stat cache
        $cutoff = time() - self::STATE_TTL_SECONDS;
        foreach ($files as $f) {
            if (@filemtime($f) < $cutoff) { @unlink($f); }
        }
    }

    // ========================================================================
    // Plan — validate + diff parsed rows against the spine
    // ========================================================================

    /**
     * Build the commit plan: per-row actions, hard errors, soft issues.
     * Re-runnable — the review screen's resolutions feed back in here.
     *
     * @param array $parsed       parseUpload() result
     * @param array $resolutions  {
     *                  vocab: {display_sample_type: {raw => canonicalValue | '__freetext__'}, ...},
     *                  custom_columns: {header => 'import'|'ignore'}
     *                }
     * @return array plan — see docblock body
     */
    public function plan($parsed, $resolutions = array())
    {
        $vocabRes  = isset($resolutions['vocab']) && is_array($resolutions['vocab']) ? $resolutions['vocab'] : array();
        $customRes = isset($resolutions['custom_columns']) && is_array($resolutions['custom_columns']) ? $resolutions['custom_columns'] : array();

        $materialFlat = samples_vocab_material_flat();
        $purposeFlat  = samples_vocab_sample_purposes();

        $hardErrors   = array();  // {row, column, code, message}
        $softVocab    = array();  // column => raw => {count, rows, suggestion}
        $softCustom   = array();  // headers awaiting an import/ignore decision
        $warnings     = array();

        // Custom-column decisions. Undecided headers are still soft issues
        // (they keep plan.clean false, blocking commit until the user picks
        // import/ignore on the review screen) but count PROVISIONALLY as
        // imported — mirroring the review UI's default-checked "Import"
        // radio — so the create/update counts preview what confirming with
        // the defaults will actually do. Choosing "Ignore" re-plans without
        // the column and those diffs drop back out.
        $importCustom = array();  // header => true
        foreach ($parsed['custom_headers'] as $header) {
            $decision = isset($customRes[$header]) ? $customRes[$header] : null;
            if ($decision === 'import') {
                $importCustom[$header] = true;
            } elseif ($decision !== 'ignore') {
                $softCustom[] = $header;
                $importCustom[$header] = true;
            }
        }

        // Duplicate-id detection.
        $idRows = array();
        foreach ($parsed['rows'] as $rec) {
            if ($rec['id'] !== null) {
                $idRows[$rec['id']][] = $rec['n'];
            }
        }
        foreach ($idRows as $id => $ns) {
            if (count($ns) > 1) {
                foreach (array_slice($ns, 1) as $n) {
                    $hardErrors[] = array('row' => $n, 'column' => 'strabo_internal_id', 'code' => 'duplicate_id',
                                          'message' => "Internal id '$id' appears more than once (first at row {$ns[0]}).");
                }
            }
        }

        // Fetch current spine rows + field-link flags for all referenced ids.
        $current    = array();  // id => row object
        $fieldLinked = array(); // id => true
        $ids = array_keys($idRows);
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = array();
            foreach ($chunk as $k => $_) { $ph[] = '$' . ($k + 2); }
            $inList = implode(',', $ph);
            $params = array_merge(array($this->userpkey), $chunk);
            $rows = $this->db->get_results_prepared(
                "SELECT id, name, igsn, description, notes, latitude, longitude,
                        display_sample_type, display_sample_purpose, custom_data::text AS custom_data
                   FROM strabosamples.samples
                  WHERE userpkey = $1 AND id IN ($inList)",
                $params
            );
            if (is_array($rows)) {
                foreach ($rows as $r) { $current[$r->id] = $r; }
            }
            $links = $this->db->get_results_prepared(
                "SELECT DISTINCT sample_id
                   FROM strabosamples.sample_subsystem_links
                  WHERE sample_userpkey = $1 AND subsystem = 'field' AND sample_id IN ($inList)",
                $params
            );
            if (is_array($links)) {
                foreach ($links as $l) { $fieldLinked[$l->sample_id] = true; }
            }
        }

        $planRows = array();
        $counts   = array('create' => 0, 'update' => 0, 'noop' => 0);

        foreach ($parsed['rows'] as $rec) {
            $n      = $rec['n'];
            $rowErr = false;
            $isUpdate = ($rec['id'] !== null);
            $cur      = null;

            if ($isUpdate) {
                if (!isset($current[$rec['id']])) {
                    $hardErrors[] = array('row' => $n, 'column' => 'strabo_internal_id', 'code' => 'unknown_id',
                                          'message' => "Internal id '{$rec['id']}' does not match any of your samples. Leave the id blank to create a new sample.");
                    continue;
                }
                $cur = $current[$rec['id']];
            }

            // Cell-length sanity.
            foreach (array_merge($rec['values'], $rec['custom']) as $k => $v) {
                if ($v !== null && mb_strlen($v) > self::MAX_CELL_CHARS) {
                    $hardErrors[] = array('row' => $n, 'column' => $k, 'code' => 'cell_too_long',
                                          'message' => "Value exceeds " . self::MAX_CELL_CHARS . " characters.");
                    $rowErr = true;
                }
            }

            // Vocab normalization.
            $values = $rec['values'];
            foreach (array('display_sample_type' => $materialFlat, 'display_sample_purpose' => $purposeFlat) as $field => $flat) {
                if (!array_key_exists($field, $values) || $values[$field] === null) { continue; }
                $raw = $values[$field];
                $canonical = samples_vocab_resolve($raw, $flat);
                if ($canonical !== null) {
                    $values[$field] = $canonical;
                    continue;
                }
                // Round-trip pass-through: unchanged free-text on an update is not an issue.
                if ($isUpdate && $cur !== null && (string)$cur->$field === $raw) {
                    continue;
                }
                $resKey = isset($vocabRes[$field][$raw]) ? $vocabRes[$field][$raw] : null;
                if ($resKey === self::RESOLUTION_FREE_TEXT) {
                    // keep verbatim
                } elseif ($resKey !== null && (samples_vocab_resolve($resKey, $flat) !== null)) {
                    $values[$field] = samples_vocab_resolve($resKey, $flat);
                } else {
                    $col = ($field === 'display_sample_type') ? 'material_type' : 'sampling_purpose';
                    if (!isset($softVocab[$col][$raw])) {
                        $softVocab[$col][$raw] = array('count' => 0, 'rows' => array(),
                                                       'suggestion' => $this->suggestVocab($raw, $flat));
                    }
                    $softVocab[$col][$raw]['count']++;
                    $softVocab[$col][$raw]['rows'][] = $n;
                    // NOT a row error: the row keeps its raw value provisionally
                    // and still counts toward create/update on the review screen.
                    // plan.clean stays false while any soft issue is unresolved,
                    // so commit remains blocked until the user decides.
                }
            }

            // Latitude / longitude numeric + range.
            foreach (array('latitude' => 90, 'longitude' => 180) as $field => $limit) {
                if (!array_key_exists($field, $values) || $values[$field] === null) { continue; }
                $num = $this->parseNumeric($values[$field]);
                if ($num === null) {
                    $hardErrors[] = array('row' => $n, 'column' => $field, 'code' => 'not_numeric',
                                          'message' => "'{$values[$field]}' is not a number.");
                    $rowErr = true;
                } elseif ($num < -$limit || $num > $limit) {
                    $hardErrors[] = array('row' => $n, 'column' => $field, 'code' => 'out_of_range',
                                          'message' => ucfirst($field) . " must be between -$limit and $limit.");
                    $rowErr = true;
                } else {
                    $values[$field] = $num;
                }
            }

            // Final lat/lng pairing (after merging with current values for updates).
            $finalLat = array_key_exists('latitude', $values)  ? $values['latitude']
                      : ($cur !== null && $cur->latitude  !== null ? (float)$cur->latitude  : null);
            $finalLng = array_key_exists('longitude', $values) ? $values['longitude']
                      : ($cur !== null && $cur->longitude !== null ? (float)$cur->longitude : null);
            if (($finalLat === null) !== ($finalLng === null)) {
                $hardErrors[] = array('row' => $n, 'column' => 'latitude', 'code' => 'coord_pair',
                                      'message' => 'Latitude and longitude must be provided together (or both left blank).');
                $rowErr = true;
            }

            // IGSN sanity (warning only, per design).
            if (isset($values['igsn']) && $values['igsn'] !== null && preg_match('/\s/', $values['igsn'])) {
                $warnings[] = array('row' => $n, 'column' => 'igsn',
                                    'message' => "IGSN '{$values['igsn']}' contains whitespace — double-check it.");
            }

            // Custom values for this row (imported columns only).
            $custom = array();
            foreach ($rec['custom'] as $header => $v) {
                if (isset($importCustom[$header])) {
                    $custom[$header] = $v;   // null = clear this key
                }
            }

            if ($rowErr) { continue; }

            if (!$isUpdate) {
                // CREATE — require a sample name.
                $name = isset($values['name']) ? $values['name'] : null;
                if ($name === null) {
                    $hardErrors[] = array('row' => $n, 'column' => 'sample_id', 'code' => 'name_required',
                                          'message' => 'New samples need a Sample ID (name).');
                    continue;
                }
                $input = array();
                foreach ($values as $f => $v) { $input[$f] = $v; }
                $customClean = array();
                foreach ($custom as $k => $v) {
                    if ($v !== null) { $customClean[$k] = $v; }
                }
                if (count($customClean) > self::MAX_CUSTOM_KEYS) {
                    $hardErrors[] = array('row' => $n, 'column' => '', 'code' => 'too_many_custom',
                                          'message' => 'More than ' . self::MAX_CUSTOM_KEYS . ' custom fields on one sample.');
                    continue;
                }
                if (!empty($customClean)) { $input['custom_data'] = $customClean; }
                $planRows[] = array('n' => $n, 'action' => 'create', 'input' => $input);
                $counts['create']++;
                continue;
            }

            // UPDATE — a present-but-blank Sample ID would clear the name;
            // that's almost certainly accidental, so reject it explicitly.
            if (array_key_exists('name', $values) && $values['name'] === null) {
                $hardErrors[] = array('row' => $n, 'column' => 'sample_id', 'code' => 'name_required',
                                      'message' => 'Sample ID cannot be blank. Remove the whole column to leave names untouched.');
                continue;
            }

            // Diff against current; only changed fields go to the service.
            // $changes mirrors the diff for the review screen: template
            // header names + old/new display values (vocab as labels).
            $diff    = array();
            $changes = array();
            $headerOf = array_flip(self::$COLUMNS);   // field => template header
            foreach ($values as $f => $v) {
                $curVal = $cur->$f;
                if ($f === 'latitude' || $f === 'longitude') {
                    $curNum = ($curVal === null) ? null : (float)$curVal;
                    $same = ($v === null && $curNum === null)
                         || ($v !== null && $curNum !== null && abs($v - $curNum) < self::FLOAT_EPSILON);
                    if (!$same) {
                        $diff[$f] = $v;
                        $changes[] = array('column' => $headerOf[$f], 'old' => $curNum, 'new' => $v);
                    }
                } else {
                    $curStr = ($curVal === null || $curVal === '') ? null : (string)$curVal;
                    if ($curStr !== $v) {
                        $diff[$f] = $v;
                        $oldDisp = $curStr; $newDisp = $v;
                        if ($f === 'display_sample_type') {
                            $oldDisp = $this->displayVocab($curStr, $materialFlat);
                            $newDisp = $this->displayVocab($v, $materialFlat);
                        } elseif ($f === 'display_sample_purpose') {
                            $oldDisp = $this->displayVocab($curStr, $purposeFlat);
                            $newDisp = $this->displayVocab($v, $purposeFlat);
                        }
                        $changes[] = array('column' => $headerOf[$f], 'old' => $oldDisp, 'new' => $newDisp);
                    }
                }
            }

            // Field-linked samples: lat/lng belongs to the Spot (Neo4j).
            if (isset($fieldLinked[$rec['id']]) && (array_key_exists('latitude', $diff) || array_key_exists('longitude', $diff))) {
                $hardErrors[] = array('row' => $n, 'column' => 'latitude', 'code' => 'field_link_read_only',
                                      'message' => 'This sample is linked to a Field spot — its location is owned by the spot and cannot be changed here.');
                continue;
            }

            // Custom merge (present keys overwrite; blank clears; absent untouched).
            if (!empty($custom)) {
                $curCustom = ($cur->custom_data !== null && $cur->custom_data !== '')
                           ? json_decode($cur->custom_data, true) : array();
                if (!is_array($curCustom)) { $curCustom = array(); }
                $newCustom = $curCustom;
                foreach ($custom as $k => $v) {
                    if ($v === null) { unset($newCustom[$k]); }
                    else             { $newCustom[$k] = $v; }
                }
                if (count($newCustom) > self::MAX_CUSTOM_KEYS) {
                    $hardErrors[] = array('row' => $n, 'column' => '', 'code' => 'too_many_custom',
                                          'message' => 'More than ' . self::MAX_CUSTOM_KEYS . ' custom fields on one sample.');
                    continue;
                }
                if ($newCustom !== $curCustom) {
                    $diff['custom_data'] = empty($newCustom) ? null : $newCustom;
                    foreach ($custom as $k => $v) {
                        $old = array_key_exists($k, $curCustom) ? $curCustom[$k] : null;
                        if ($v === null && $old === null) { continue; }
                        if ($v !== null && $old !== null && is_scalar($old) && (string)$old === $v) { continue; }
                        if ($old !== null && !is_scalar($old)) { $old = json_encode($old); }
                        $changes[] = array('column' => $k, 'old' => $old, 'new' => $v);
                    }
                }
            }

            if (empty($diff)) {
                $planRows[] = array('n' => $n, 'action' => 'noop', 'id' => $rec['id']);
                $counts['noop']++;
            } else {
                $planRows[] = array('n' => $n, 'action' => 'update', 'id' => $rec['id'], 'input' => $diff,
                                    'sample_name' => $cur->name, 'changes' => $changes);
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
            'ignored_headers'=> $parsed['ignored_headers'],
        );
    }

    /** Nearest vocab label within levenshtein distance 3, else null. */
    protected function suggestVocab($raw, array $flat)
    {
        $best = null; $bestDist = 4;
        $rawLower = mb_strtolower($raw);
        foreach ($flat as $value => $label) {
            $d = levenshtein($rawLower, mb_strtolower($label));
            if ($d < $bestDist) { $bestDist = $d; $best = $label; }
        }
        return $best;
    }

    /** Parse a numeric cell; tolerates a single comma decimal ("12,5"). */
    protected function parseNumeric($s)
    {
        $s = trim((string)$s);
        if (preg_match('/^-?\d+,\d+$/', $s)) {
            $s = str_replace(',', '.', $s);
        }
        if (!is_numeric($s)) { return null; }
        return (float)$s;
    }

    // ========================================================================
    // Commit — all-or-nothing
    // ========================================================================

    /**
     * Execute a CLEAN plan inside one PG transaction. Every row routes
     * through StraboSamplesService so changelog + Field writeback + Micro
     * dirty-flags fire exactly as they do for UI edits.
     *
     * @return array {ok, created, updated, noop, minted: [{row, id}]} or
     *               {ok:false, error, row}
     */
    public function commit($plan)
    {
        if (empty($plan['clean'])) {
            return array('ok' => false, 'error' => 'plan_not_clean');
        }
        $minted = array();
        $counts = array('created' => 0, 'updated' => 0, 'noop' => 0);

        $this->db->query('BEGIN');
        foreach ($plan['rows'] as $row) {
            if ($row['action'] === 'noop') { $counts['noop']++; continue; }

            if ($row['action'] === 'create') {
                $res = $this->samples->createSample($row['input']);
                if (empty($res['ok'])) {
                    $this->db->query('ROLLBACK');
                    return array('ok' => false, 'error' => isset($res['error']) ? $res['error'] : 'create_failed',
                                 'row' => $row['n']);
                }
                $minted[] = array('row' => $row['n'], 'id' => $res['sample']['id']);
                $counts['created']++;
            } else {
                $res = $this->samples->updateSample($row['id'], $this->userpkey, $row['input']);
                if (empty($res['ok'])) {
                    $this->db->query('ROLLBACK');
                    return array('ok' => false, 'error' => isset($res['error']) ? $res['error'] : 'update_failed',
                                 'row' => $row['n']);
                }
                $counts['updated']++;
            }
        }
        $this->db->query('COMMIT');

        return array('ok' => true, 'created' => $counts['created'], 'updated' => $counts['updated'],
                     'noop' => $counts['noop'], 'minted' => $minted);
    }

    // ========================================================================
    // Export + template
    // ========================================================================

    /**
     * Owner's samples as export-ready associative rows + the union of
     * custom keys (alphabetical) for the extra columns.
     *
     * @return array {rows: [...], custom_keys: [...]}
     */
    public function exportRows()
    {
        $rows = $this->db->get_results_prepared(
            "SELECT id, name, igsn, description, notes, latitude, longitude,
                    display_sample_type, display_sample_purpose,
                    custom_data::text AS custom_data, modified_at
               FROM strabosamples.samples
              WHERE userpkey = $1
              ORDER BY lower(name) NULLS LAST, id",
            array($this->userpkey)
        );
        $linkRows = $this->db->get_results_prepared(
            "SELECT sample_id, subsystem, COUNT(*)::int AS n
               FROM strabosamples.sample_subsystem_links
              WHERE sample_userpkey = $1
              GROUP BY sample_id, subsystem",
            array($this->userpkey)
        );
        return $this->assembleExportRows($rows, $linkRows);
    }

    /**
     * Id-set variant for the Export Builder sample list (design §7.3):
     * only the given sample ids of ONE owner, same row shape as
     * exportRows(). Ids are spine ids (text). Unknown ids are ignored;
     * an empty set returns no rows without touching the database.
     *
     * @param  string[] $sampleIds
     * @param  int      $userpkey  sample owner (spine identity is (id, userpkey))
     * @return array {rows: [...], custom_keys: [...]}
     */
    public function exportRowsForIds(array $sampleIds, $userpkey)
    {
        $ids = array();
        foreach ($sampleIds as $id) { $id = trim((string)$id); if ($id !== '') { $ids[$id] = true; } }
        if (!$ids) { return array('rows' => array(), 'custom_keys' => array()); }
        $arr = self::pgTextArray(array_keys($ids));
        $rows = $this->db->get_results_prepared(
            "SELECT id, name, igsn, description, notes, latitude, longitude,
                    display_sample_type, display_sample_purpose,
                    custom_data::text AS custom_data, modified_at
               FROM strabosamples.samples
              WHERE userpkey = $1 AND id = ANY($2::text[])
              ORDER BY lower(name) NULLS LAST, id",
            array((int)$userpkey, $arr)
        );
        $linkRows = $this->db->get_results_prepared(
            "SELECT sample_id, subsystem, COUNT(*)::int AS n
               FROM strabosamples.sample_subsystem_links
              WHERE sample_userpkey = $1 AND sample_id = ANY($2::text[])
              GROUP BY sample_id, subsystem",
            array((int)$userpkey, $arr)
        );
        return $this->assembleExportRows($rows, $linkRows);
    }

    /** PHP string list -> PostgreSQL text[] literal for a $n::text[] parameter. */
    public static function pgTextArray(array $values)
    {
        $parts = array();
        foreach ($values as $v) {
            $parts[] = '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string)$v) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    /** Sample rows + per-sample link counts -> export rows + custom key union. */
    protected function assembleExportRows($rows, $linkRows)
    {
        if (!is_array($rows)) { $rows = array(); }
        $links = array();
        if (is_array($linkRows)) {
            foreach ($linkRows as $l) {
                $links[$l->sample_id][$l->subsystem] = (int)$l->n;
            }
        }

        $materialFlat = samples_vocab_material_flat();
        $purposeFlat  = samples_vocab_sample_purposes();

        $out = array();
        $customKeys = array();
        foreach ($rows as $r) {
            $custom = array();
            if ($r->custom_data !== null && $r->custom_data !== '') {
                $decoded = json_decode($r->custom_data, true);
                if (is_array($decoded)) { $custom = $decoded; }
            }
            foreach ($custom as $k => $_) { $customKeys[$k] = true; }

            $linkedBits = array();
            if (isset($links[$r->id])) {
                foreach ($links[$r->id] as $sub => $cnt) {
                    $linkedBits[] = ($cnt > 1) ? $sub . '×' . $cnt : $sub;
                }
            }

            $out[] = array(
                'strabo_internal_id' => $r->id,
                'sample_id'          => $r->name,
                'igsn'               => $r->igsn,
                'material_type'      => $this->displayVocab($r->display_sample_type, $materialFlat),
                'sampling_purpose'   => $this->displayVocab($r->display_sample_purpose, $purposeFlat),
                'description'        => $r->description,
                'notes'              => $r->notes,
                'latitude'           => $r->latitude,
                'longitude'          => $r->longitude,
                'linked_systems'     => implode(', ', $linkedBits),
                'modified_at'        => $r->modified_at,
                '_custom'            => $custom,
            );
        }
        $customKeys = array_keys($customKeys);
        sort($customKeys, SORT_NATURAL | SORT_FLAG_CASE);

        return array('rows' => $out, 'custom_keys' => $customKeys);
    }

    /** Storage value => display label when vocab-known, verbatim otherwise. */
    protected function displayVocab($value, array $flat)
    {
        if ($value === null) { return null; }
        return isset($flat[$value]) ? $flat[$value] : $value;
    }

    /** Header row for a data grid: standard + export context + custom keys. */
    protected function exportHeaders(array $customKeys, $isTemplate, array $contextCols = array())
    {
        $headers = array_keys(self::$COLUMNS);
        if (!$isTemplate) {
            $headers[] = 'linked_systems';
            $headers[] = 'modified_at';
            foreach ($contextCols as $c) { $headers[] = $c; }
            foreach ($customKeys as $k) { $headers[] = $k; }
        }
        return $headers;
    }

    /**
     * Build the XLSX workbook (template or populated export).
     * Returns a PHPExcel object; caller streams it with Writer_Excel2007.
     *
     * $contextCols: optional read-only export context headers (Export
     * Builder sample list: field_project, field_dataset, field_spot_id,
     * field_spot_name); values come from $row['_context'][$header]. They
     * sit after modified_at, are locked like it, and are ignored on import.
     */
    public function buildWorkbook($rows, array $customKeys, $isTemplate, array $contextCols = array())
    {
        $this->requirePhpExcel();
        $wb = new PHPExcel();
        $wb->getProperties()->setCreator('strabospot.org')
            ->setLastModifiedBy('strabospot.org')
            ->setTitle($isTemplate ? 'StraboSamples Import Template' : 'StraboSamples Export')
            ->setDescription('StraboSamples tabular ' . ($isTemplate ? 'template' : 'export'));

        // Workbook default = UNLOCKED cells. Sheet protection (enabled on the
        // Data sheet below) then only guards the cells we explicitly re-lock:
        // the exported internal ids, the header row, and the read-only export
        // context columns. Everything else — including brand-new rows and
        // columns — stays freely editable. No password: this is an
        // accidental-edit guard, not tamper-proofing (the server re-validates
        // every id on upload regardless; unknown ids hard-error at review).
        $wb->getDefaultStyle()->getProtection()
           ->setLocked(PHPExcel_Style_Protection::PROTECTION_UNPROTECTED);

        // ---- Data sheet ----
        $data = $wb->getActiveSheet();
        $data->setTitle('Data');
        $headers = $this->exportHeaders($customKeys, $isTemplate, $contextCols);
        foreach ($headers as $i => $h) {
            $cell = $data->getCellByColumnAndRow($i, 1);
            $cell->setValue($h);
        }
        $lastCol = PHPExcel_Cell::stringFromColumnIndex(count($headers) - 1);
        $data->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        $data->freezePane('A2');
        $data->getComment('A1')->getText()->createTextRun(
            'Internal StraboSpot id — do not edit. Leave blank on new rows to create a sample.');

        $rowNum = 2;
        foreach ($rows as $r) {
            $col = 0;
            foreach (array_keys(self::$COLUMNS) as $h) {
                $data->getCellByColumnAndRow($col++, $rowNum)->setValueExplicit(
                    $r[$h] === null ? '' : (string)$r[$h], PHPExcel_Cell_DataType::TYPE_STRING);
            }
            if (!$isTemplate) {
                $data->getCellByColumnAndRow($col++, $rowNum)->setValueExplicit((string)$r['linked_systems'], PHPExcel_Cell_DataType::TYPE_STRING);
                $data->getCellByColumnAndRow($col++, $rowNum)->setValueExplicit((string)$r['modified_at'], PHPExcel_Cell_DataType::TYPE_STRING);
                foreach ($contextCols as $c) {
                    $v = isset($r['_context'][$c]) ? (string)$r['_context'][$c] : '';
                    $data->getCellByColumnAndRow($col++, $rowNum)->setValueExplicit($v, PHPExcel_Cell_DataType::TYPE_STRING);
                }
                foreach ($customKeys as $k) {
                    $v = isset($r['_custom'][$k]) ? (string)$r['_custom'][$k] : '';
                    $data->getCellByColumnAndRow($col++, $rowNum)->setValueExplicit($v, PHPExcel_Cell_DataType::TYPE_STRING);
                }
            }
            $rowNum++;
        }

        foreach (range(0, count($headers) - 1) as $i) {
            $data->getColumnDimension(PHPExcel_Cell::stringFromColumnIndex($i))->setWidth(22);
        }

        // Re-lock the guarded cells (see default-style note above), then turn
        // sheet protection on. Locked = the written internal-id cells, the
        // header row, and the read-only context columns; empty id cells on
        // NEW rows stay unlocked (blank id = create; a deliberately pasted id
        // = update-by-id, both valid). Excel refuses to sort a range that
        // contains locked cells — the Instructions sheet tells users they can
        // simply unprotect (no password) if they need to restructure.
        $lastDataRow = $rowNum - 1;   // header row when there are no data rows
        $lockRanges  = array('A1:' . $lastCol . '1');            // header row
        if ($lastDataRow >= 2) {
            $lockRanges[] = 'A2:A' . $lastDataRow;               // internal ids
            foreach (array_merge(array('linked_systems', 'modified_at'), $contextCols) as $ctx) {
                $ctxIdx = array_search($ctx, $headers);
                if ($ctxIdx !== false) {
                    $ctxCol = PHPExcel_Cell::stringFromColumnIndex($ctxIdx);
                    $lockRanges[] = $ctxCol . '2:' . $ctxCol . $lastDataRow;
                }
            }
        }
        foreach ($lockRanges as $range) {
            $data->getStyle($range)->getProtection()
                 ->setLocked(PHPExcel_Style_Protection::PROTECTION_PROTECTED);
        }
        $data->getProtection()->setSheet(true);

        // ---- Vocabulary sheet ----
        $materialFlat = samples_vocab_material_flat();
        $purposeFlat  = samples_vocab_sample_purposes();
        $vocab = $wb->createSheet();
        $vocab->setTitle('Vocabulary');
        $vocab->setCellValue('A1', 'Material Type');
        $vocab->setCellValue('C1', 'Sampling Purpose');
        $vocab->getStyle('A1:C1')->getFont()->setBold(true);
        $vr = 2;
        foreach ($materialFlat as $value => $label) { $vocab->setCellValue('A' . $vr++, $label); }
        $materialEnd = $vr - 1;
        $vr = 2;
        foreach ($purposeFlat as $value => $label) { $vocab->setCellValue('C' . $vr++, $label); }
        $purposeEnd = $vr - 1;
        $vocab->getColumnDimension('A')->setWidth(28);
        $vocab->getColumnDimension('C')->setWidth(28);

        // ---- Dropdown validation on the vocab columns ----
        // WARNING style (not STOP): free-text values are legal — the vocab
        // is a controlled suggestion, and 'Other' free text is a supported
        // path both in the modal UI and on import.
        $validationRows = max(count($rows) + 200, 500);
        $vocabCols = array(
            'material_type'    => 'Vocabulary!$A$2:$A$' . $materialEnd,
            'sampling_purpose' => 'Vocabulary!$C$2:$C$' . $purposeEnd,
        );
        foreach ($vocabCols as $header => $range) {
            $colIdx = array_search($header, $headers);
            if ($colIdx === false) { continue; }
            $colLetter = PHPExcel_Cell::stringFromColumnIndex($colIdx);
            for ($r2 = 2; $r2 <= $validationRows + 1; $r2++) {
                $dv = $data->getCell($colLetter . $r2)->getDataValidation();
                $dv->setType(PHPExcel_Cell_DataValidation::TYPE_LIST)
                   ->setErrorStyle(PHPExcel_Cell_DataValidation::STYLE_WARNING)
                   ->setAllowBlank(true)
                   ->setShowDropDown(true)
                   ->setShowErrorMessage(true)
                   ->setErrorTitle('Not a standard value')
                   ->setError('This is not a standard StraboSpot value. It will import as free text — that is allowed.')
                   ->setFormula1($range);
            }
        }

        // ---- Instructions sheet ----
        $instr = $wb->createSheet();
        $instr->setTitle('Instructions');
        $lines = array(
            'StraboSamples tabular ' . ($isTemplate ? 'import template' : 'export'),
            '',
            'HOW TO USE',
            '1. Fill in one row per sample on the Data sheet.',
            '2. Leave strabo_internal_id BLANK for new samples — StraboSpot assigns it.',
            '3. To update existing samples, export your samples first and edit the file;',
            '   the strabo_internal_id column ties each row back to its sample. Do not edit it.',
            '4. Upload the file on the My Samples page (Import samples). You will see a',
            '   review of every change before anything is saved.',
            '',
            'COLUMNS',
            'sample_id            Your sample name/label (required for new samples).',
            'igsn                 International Generic Sample Number, if registered.',
            'material_type        Pick from the dropdown (see Vocabulary sheet), or type',
            '                     your own — free text is allowed.',
            'sampling_purpose     Same — dropdown or free text.',
            'description / notes  Free text.',
            'latitude/longitude   Decimal degrees (WGS84). Provide both or neither.',
            '                     Samples linked to a Field spot take their location from',
            '                     the spot — location edits for those are rejected.',
            '',
            'CUSTOM COLUMNS',
            'Any extra column you add becomes a custom field on the sample after you',
            'confirm it during import. Custom fields round-trip: they come back as',
            'columns the next time you export.',
            '',
            'THINGS TO KNOW',
            '- The strabo_internal_id column (and the read-only export columns) are',
            '  protected against accidental edits; new rows are fully editable and their',
            '  blank id means "create". If you need to sort or restructure the sheet,',
            '  unprotect it first (Review > Unprotect Sheet — there is no password);',
            '  StraboSpot validates every id on upload either way.',
            '- A blank cell in a column you include CLEARS that field. Remove a whole',
            '  column if you do not want to touch that field.',
            '- Rows are never deleted by an upload. Delete samples in the web interface.',
            '- Uploads are all-or-nothing: if any row has an error, nothing is saved',
            '  until you fix and re-upload.',
            '- CSV files are accepted too (UTF-8 recommended). The Vocabulary and',
            '  Instructions sheets are only advisory — only the Data sheet is read.',
        );
        $ir = 1;
        foreach ($lines as $line) { $instr->setCellValue('A' . $ir++, $line); }
        $instr->getColumnDimension('A')->setWidth(90);
        $instr->getStyle('A1')->getFont()->setBold(true);

        $wb->setActiveSheetIndex(0);
        return $wb;
    }

    /** CSV string (UTF-8 with BOM for Excel) for template or export. */
    public function buildCsv($rows, array $customKeys, $isTemplate, array $contextCols = array())
    {
        $headers = $this->exportHeaders($customKeys, $isTemplate, $contextCols);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers);
        foreach ($rows as $r) {
            $line = array();
            foreach (array_keys(self::$COLUMNS) as $h) {
                $line[] = ($r[$h] === null) ? '' : (string)$r[$h];
            }
            if (!$isTemplate) {
                $line[] = (string)$r['linked_systems'];
                $line[] = (string)$r['modified_at'];
                foreach ($contextCols as $c) {
                    $line[] = isset($r['_context'][$c]) ? (string)$r['_context'][$c] : '';
                }
                foreach ($customKeys as $k) {
                    $line[] = isset($r['_custom'][$k]) ? (string)$r['_custom'][$k] : '';
                }
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $csv;
    }
}
