<?php
/**
 * File: audit_cross_system_drift.php
 * Description: Post-cutover READ-ONLY drift auditor for the StraboSamples
 *              spine. Compares strabosamples.* against each subsystem's
 *              source of truth (Field = Neo4j Spot json_samples, Micro =
 *              strabomicro.micro_samplemetadata, Experimental =
 *              straboexp.sample) and reports divergence in both directions:
 *
 *                Coverage  — every source sample has a spine row + link;
 *                            every spine mirror still has a live source.
 *                Fidelity  — spine values match a fresh projection from the
 *                            source (same rules as the live sync helpers),
 *                            and the per-subsystem *_data JSONB matches the
 *                            source columns.
 *
 *              Findings are classified so expected divergence never reads
 *              as breakage:
 *
 *                DRIFT — real inconsistency (a sync hook didn't fire, a
 *                        stale link, a writeback failure). Non-zero exit.
 *                INFO  — divergence the design allows:
 *                          - samples_api (modal/tabular) spine edit on a
 *                            Micro/Exp-owned sample (no writeback by
 *                            design, §10.4 last-writer-wins);
 *                          - Field writeback vocab-translation skips
 *                            ("Glass" has no Field bucket);
 *                          - stale field_data JSONB after a modal edit
 *                            (writeback updates Neo4j, not the cache);
 *                          - samples_api edits to the *_data JSONB or
 *                            sub-array children newer than the last
 *                            subsystem upload.
 *
 *              Spine ownership follows StraboSamplesService::SOURCE_PRIORITY
 *              (Field > Micro > Experimental): spine values are only
 *              compared against the highest-priority subsystem that has
 *              *_data on the row. Projection rules intentionally mirror
 *              db/lib/sample_sync.php, microdb/lib/sample_sync.php,
 *              experimental/lib/sample_sync.php and the migration
 *              extractors — if those change, change this file too.
 *
 *              Intended cadence: T+1 day and T+1 week after the Phase 1
 *              cutover (see phase1_migration_prod_runbook.md closing
 *              section), then ad hoc as a health check. Pairs with the
 *              rawcache table (~1 week of request forensics) to diagnose
 *              any DRIFT found.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/samplesdb/audit/audit_cross_system_drift.php \
 *     [--sample N] [--since DAYS] [--user PKEY] [--json] [--verbose]
 *
 *   --sample N   Cap for Neo4j-bound Field link probes. Default 200,
 *                0 = audit every Field link (minutes on prod-scale data).
 *   --since D    Field source→spine coverage scan window: Neo4j Spots
 *                modified in the last D days. Default 30, 0 = skip the scan.
 *   --user P     Restrict every check to one sample_userpkey (targeted
 *                prod checks; also used by the smoke test).
 *   --json       Emit a machine-readable JSON report on stdout after the
 *                human-readable one.
 *   --verbose    List every finding, not just the first 10 per group.
 *
 * Exit codes: 0 = no DRIFT (INFO findings allowed), 1 = DRIFT found,
 *             2 = execution error.
 *
 * @package    StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$webroot = realpath(__DIR__ . '/../..');
chdir($webroot);

require_once($webroot . '/includes/config.inc.php');
require_once($webroot . '/db.php');
require_once($webroot . '/neodb.php');
require_once($webroot . '/samplesdb/lib/field_geom.php');

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

$opt = array('sample' => 200, 'since' => 30, 'user' => null, 'json' => false, 'verbose' => false);
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--sample':  $opt['sample'] = (int)(isset($args[++$i]) ? $args[$i] : 0); break;
        case '--since':   $opt['since']  = (int)(isset($args[++$i]) ? $args[$i] : 0); break;
        case '--user':    $opt['user']   = (int)(isset($args[++$i]) ? $args[$i] : 0); break;
        case '--json':    $opt['json']   = true; break;
        case '--verbose': $opt['verbose'] = true; break;
        case '--help': case '-h':
            echo "Usage: php audit_cross_system_drift.php [--sample N] [--since DAYS] [--user PKEY] [--json] [--verbose]\n";
            echo "Read-only cross-system drift audit for the StraboSamples spine.\n";
            echo "Exit 0 = clean, 1 = DRIFT found, 2 = error. See file header for details.\n";
            exit(0);
        default:
            fwrite(STDERR, "Unknown option: {$args[$i]} (see --help)\n");
            exit(2);
    }
}

// ---------------------------------------------------------------------------
// Finding collection
// ---------------------------------------------------------------------------

$FINDINGS = array();   // each: {section, class: DRIFT|INFO, kind, sample_id, userpkey, detail}

function finding($section, $class, $kind, $sampleId, $userpkey, $detail) {
    global $FINDINGS;
    $FINDINGS[] = array(
        'section'   => $section,
        'class'     => $class,
        'kind'      => $kind,
        'sample_id' => (string)$sampleId,
        'userpkey'  => (int)$userpkey,
        'detail'    => $detail,
    );
}

// ---------------------------------------------------------------------------
// Comparison helpers. '' and NULL compare equal everywhere: the service's
// NULL-vs-'' normalization is a known cosmetic wrinkle (see the
// PROJECT_STATUS parking lot) and flagging it would bury real drift.
// ---------------------------------------------------------------------------

function norm_str($v) {
    if ($v === null) return null;
    if (is_bool($v)) $v = $v ? '1' : '0';
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function eq_str($a, $b) {
    return norm_str($a) === norm_str($b);
}

function eq_float($a, $b, $eps = 1e-7) {
    $an = ($a === null || $a === '' || !is_numeric($a)) ? null : (float)$a;
    $bn = ($b === null || $b === '' || !is_numeric($b)) ? null : (float)$b;
    if ($an === null && $bn === null) return true;
    if ($an === null || $bn === null) return false;
    return abs($an - $bn) < $eps;
}

/** Compare two scalar values, numeric-aware then string-normalized. */
function eq_val($a, $b) {
    if (is_numeric($a) && is_numeric($b)) return eq_float($a, $b);
    return eq_str($a, $b);
}

/** Recursive key-sorted canonicalization for JSONB-vs-source comparison. */
function canon($v) {
    if (is_object($v)) $v = (array)$v;
    if (is_array($v)) {
        $isList = (array_keys($v) === range(0, count($v) - 1));
        $out = array();
        foreach ($v as $k => $item) $out[$k] = canon($item);
        if (!$isList) ksort($out);
        return $out;
    }
    return $v;
}

/** Diff two assoc arrays; returns the list of keys whose values differ. */
function diff_keys($a, $b) {
    $a = (array)canon($a); $b = (array)canon($b);
    $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
    $diff = array();
    foreach ($keys as $k) {
        $av = array_key_exists($k, $a) ? $a[$k] : null;
        $bv = array_key_exists($k, $b) ? $b[$k] : null;
        if (is_array($av) || is_array($bv)) {
            if (json_encode($av) !== json_encode($bv)) $diff[] = $k;
        } elseif (!eq_val($av, $bv)) {
            $diff[] = $k;
        }
    }
    return $diff;
}

function decode_jsonb($txt) {
    if ($txt === null || $txt === '') return null;
    $d = json_decode($txt, true);
    return is_array($d) ? $d : null;
}

// ---------------------------------------------------------------------------
// Changelog interrogation — who last wrote the spine / a JSONB col / the
// sub-array children. Used to separate design-allowed divergence (INFO)
// from real drift.
// ---------------------------------------------------------------------------

$SPINE_FIELDS = array('name', 'igsn', 'description', 'notes', 'latitude', 'longitude',
                      'display_sample_type', 'display_sample_purpose');

function recent_changelog($db, $sampleId, $userpkey, $limit = 40) {
    $rows = $db->get_results_prepared(
        "SELECT change_type, source_subsystem, changes::text AS ch
           FROM strabosamples.sample_changelog
          WHERE sample_id=$1 AND sample_userpkey=$2
          ORDER BY changed_at DESC, pkey DESC LIMIT $3",
        array($sampleId, (int)$userpkey, (int)$limit)
    );
    return is_array($rows) ? $rows : array();
}

/**
 * Source of the newest changelog row that actually wrote spine columns.
 * Subsystem upserts carry changes.spine_written (false = priority-skipped);
 * samples_api 'update' rows list the changed spine columns directly.
 * Returns 'field'|'micro'|'experimental'|'samples_api'|'migration'|null.
 */
function last_spine_writer($db, $sampleId, $userpkey) {
    global $SPINE_FIELDS;
    foreach (recent_changelog($db, $sampleId, $userpkey) as $r) {
        if (!in_array($r->change_type, array('create', 'update'), true)) continue;
        $ch = decode_jsonb($r->ch);
        $src = $r->source_subsystem;
        if ($src === 'samples_api' || $src === null) {
            if ($r->change_type === 'create') return 'samples_api';
            if (is_array($ch)) {
                foreach ($SPINE_FIELDS as $f) {
                    if (array_key_exists($f, $ch)) return 'samples_api';
                }
            }
            continue;   // custom_data-only / JSONB-only edit — didn't touch spine
        }
        // Subsystem or migration row: spine_written=false means the write
        // was priority-skipped. Missing flag (older rows) counts as written.
        if (is_array($ch) && array_key_exists('spine_written', $ch) && $ch['spine_written'] === false) {
            continue;
        }
        return $src;
    }
    return null;
}

/** Source of the newest changelog row that wrote the given *_data JSONB col. */
function last_jsonb_writer($db, $sampleId, $userpkey, $col) {
    $subsystem = str_replace('_data', '', $col);
    foreach (recent_changelog($db, $sampleId, $userpkey) as $r) {
        if (!in_array($r->change_type, array('create', 'update'), true)) continue;
        $ch = decode_jsonb($r->ch);
        if (is_array($ch) && array_key_exists($col, $ch)) {
            return $r->source_subsystem === null ? 'samples_api' : $r->source_subsystem;
        }
        // A subsystem's own create/update always rewrites its column even
        // though 'create' rows don't itemize it.
        if ($r->source_subsystem === $subsystem || $r->source_subsystem === 'migration') {
            return $r->source_subsystem;
        }
    }
    return null;
}

/** True when a samples_api children edit is newer than the last exp upload. */
function children_edited_via_api($db, $sampleId, $userpkey) {
    foreach (recent_changelog($db, $sampleId, $userpkey) as $r) {
        if (in_array($r->change_type, array('composition_change', 'parameters_change', 'documents_change'), true)
            && ($r->source_subsystem === 'samples_api' || $r->source_subsystem === null)) {
            return true;
        }
        if (in_array($r->change_type, array('create', 'update'), true)
            && in_array($r->source_subsystem, array('experimental', 'migration'), true)) {
            return false;   // subsystem write is newer than any api children edit
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Source → spine projections. These MUST mirror the live sync helpers.
// ---------------------------------------------------------------------------

/** Mirrors microdb/lib/sample_sync.php + migration extract_micro.php. */
function project_micro_spine($r) {
    $displayType = $r->materialtype !== null ? (string)$r->materialtype : null;
    if ($displayType === 'other' && !empty($r->othermaterialtype)) {
        $displayType = (string)$r->othermaterialtype;
    }
    return array(
        'name'                   => !empty($r->sampleid) ? (string)$r->sampleid
                                    : (!empty($r->label) ? (string)$r->label : null),
        'igsn'                   => null,
        'description'            => !empty($r->sampledescription) ? (string)$r->sampledescription : null,
        'notes'                  => !empty($r->samplenotes) ? (string)$r->samplenotes : null,
        'latitude'               => ($r->latitude !== null && $r->latitude !== '' && is_numeric($r->latitude)) ? (float)$r->latitude : null,
        'longitude'              => ($r->longitude !== null && $r->longitude !== '' && is_numeric($r->longitude)) ? (float)$r->longitude : null,
        'display_sample_type'    => $displayType,
        'display_sample_purpose' => $r->mainsamplingpurpose !== null ? (string)$r->mainsamplingpurpose : null,
    );
}

/** Mirrors experimental/lib/sample_sync.php's spine projection. */
function project_exp_spine($r) {
    $name = norm_str($r->name);
    if ($name === null) $name = norm_str($r->id);
    return array(
        'name'                   => $name,
        'igsn'                   => norm_str($r->igsn),
        'description'            => norm_str($r->description),
        'notes'                  => null,
        'latitude'               => ($r->provenance_loc_latitude !== null && $r->provenance_loc_latitude !== '' && is_numeric($r->provenance_loc_latitude)) ? (float)$r->provenance_loc_latitude : null,
        'longitude'              => ($r->provenance_loc_longitude !== null && $r->provenance_loc_longitude !== '' && is_numeric($r->provenance_loc_longitude)) ? (float)$r->provenance_loc_longitude : null,
        'display_sample_type'    => norm_str($r->material_type),
        'display_sample_purpose' => null,
    );
}

/** Mirrors db/lib/sample_sync.php::_field_sample_sync_emit_one. */
function project_field_spine($entry, $spotProps) {
    $entry = (array)$entry;
    $material = isset($entry['material_type']) ? (string)$entry['material_type'] : null;
    if ($material === 'other' && !empty($entry['other_material_type'])) {
        $material = (string)$entry['other_material_type'];
    }
    list($lat, $lng) = samples_field_extract_geom_from_spot($spotProps);
    return array(
        'name'                   => isset($entry['sample_id_name']) ? (string)$entry['sample_id_name'] : null,
        'igsn'                   => isset($entry['Sample_IGSN']) ? (string)$entry['Sample_IGSN'] : null,
        'description'            => isset($entry['sample_description']) ? (string)$entry['sample_description'] : null,
        'notes'                  => isset($entry['sample_notes']) ? (string)$entry['sample_notes'] : null,
        'latitude'               => $lat,
        'longitude'              => $lng,
        'display_sample_type'    => $material,
        'display_sample_purpose' => isset($entry['main_sampling_purpose']) ? (string)$entry['main_sampling_purpose'] : null,
    );
}

/** Highest-priority subsystem with *_data on the spine row (its spine owner). */
function spine_owner($sampleRow) {
    if ($sampleRow->field_data !== null)        return 'field';
    if ($sampleRow->micro_data !== null)        return 'micro';
    if ($sampleRow->experimental_data !== null) return 'experimental';
    return null;
}

/**
 * True when the projected display value is one the post-migration cleanup
 * (samplesdb/cleanup/null_garbage_spine.php) deliberately NULLs on the
 * spine while the subsystem source keeps it verbatim: purely-numeric or
 * empty display_sample_type; the hand-picked junk display_sample_purpose
 * strings. A NULL spine value against such a projection is expected.
 */
function is_cleanup_nulled($field, $projected) {
    $p = norm_str($projected);
    if ($p === null) return false;
    if ($field === 'display_sample_type') {
        return (bool)preg_match('/^[0-9]+(\.[0-9]+)?$/', $p);
    }
    if ($field === 'display_sample_purpose') {
        return in_array($p, array('bar', 'asdfbefgvb', 'test sample purpose'), true);
    }
    return false;
}

/** Compare stored spine vs a projection; returns list of differing columns. */
function spine_diff($stored, $projected) {
    global $SPINE_FIELDS;
    $diff = array();
    foreach ($SPINE_FIELDS as $f) {
        $sv = isset($stored->$f) ? $stored->$f : null;
        $pv = isset($projected[$f]) ? $projected[$f] : null;
        $ok = ($f === 'latitude' || $f === 'longitude') ? eq_float($sv, $pv) : eq_str($sv, $pv);
        if (!$ok && norm_str($sv) === null && is_cleanup_nulled($f, $pv)) $ok = true;
        if (!$ok) $diff[] = $f;
    }
    return $diff;
}

// Spine columns a samples_api edit can change (lat/lng excluded for
// Field-linked samples upstream) — used to classify manual-edit divergence.
$API_EDITABLE_SPINE = array('name', 'igsn', 'description', 'notes',
                            'display_sample_type', 'display_sample_purpose');

// Field sample-object keys the writeback can touch; a field_data-vs-Neo4j
// diff confined to these keys after a samples_api edit is the known
// stale-cache case, not drift.
$FIELD_WRITEBACK_KEYS = array('sample_id_name', 'Sample_IGSN', 'sample_description',
                              'sample_notes', 'material_type', 'main_sampling_purpose');

function is_subset(array $keys, array $allowed) {
    foreach ($keys as $k) {
        if (!in_array($k, $allowed, true)) return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

$SECTION_STATS = array();   // section => {checked: N, drift: N, info: N}

function section_start($title) {
    echo "\n--------------------------------------------------------------\n";
    echo " $title\n";
    echo "--------------------------------------------------------------\n";
}

function section_finish($section, $checked) {
    global $FINDINGS, $SECTION_STATS, $opt;
    $drift = 0; $info = 0; $rows = array();
    foreach ($FINDINGS as $f) {
        if ($f['section'] !== $section) continue;
        if ($f['class'] === 'DRIFT') $drift++; else $info++;
        $rows[] = $f;
    }
    $SECTION_STATS[$section] = array('checked' => $checked, 'drift' => $drift, 'info' => $info);
    echo "  checked: $checked   DRIFT: $drift   INFO: $info\n";
    $limit = $opt['verbose'] ? PHP_INT_MAX : 10;
    foreach (array_slice($rows, 0, $limit) as $f) {
        printf("    [%s] %-28s sample=%s user=%d  %s\n",
            $f['class'], $f['kind'], $f['sample_id'], $f['userpkey'],
            is_string($f['detail']) ? $f['detail'] : json_encode($f['detail']));
    }
    if (count($rows) > $limit) {
        echo "    ... " . (count($rows) - $limit) . " more (re-run with --verbose)\n";
    }
}

$userFilterSqlSamples = '';   // for queries aliasing strabosamples.samples as s
$userFilterParamsNote = '';
if ($opt['user'] !== null) {
    $userFilterParamsNote = " (restricted to userpkey {$opt['user']})";
}

echo "==============================================================\n";
echo " StraboSamples cross-system drift audit$userFilterParamsNote\n";
echo " sample cap: " . ($opt['sample'] ?: 'unlimited')
   . "   field scan window: " . ($opt['since'] ? "{$opt['since']}d" : 'skipped')
   . "\n";
echo "==============================================================\n";

// ===========================================================================
// SECTION 1 — MICRO coverage (source → spine) + stale mirrors (spine → source)
// ===========================================================================

section_start('[1/6] Micro coverage');
$checked = 0;

$userAnd = $opt['user'] !== null ? ' AND pm.userpkey = ' . (int)$opt['user'] : '';

// 1a — every Micro source sample has a spine row and a micro link.
$rows = $db->get_results("
    SELECT sm.strabo_id AS sample_id, pm.userpkey, pm.id AS project_internal_id,
           s.id AS spine_id,
           l.pkey AS link_pkey
      FROM strabomicro.micro_samplemetadata sm
      JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
      JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
      LEFT JOIN strabosamples.samples s
             ON s.id = sm.strabo_id AND s.userpkey = pm.userpkey
      LEFT JOIN strabosamples.sample_subsystem_links l
             ON l.sample_id = sm.strabo_id AND l.sample_userpkey = pm.userpkey
            AND l.subsystem = 'micro' AND l.reference_id = pm.id::text
     WHERE sm.strabo_id IS NOT NULL AND sm.strabo_id <> ''$userAnd
");
if (is_array($rows)) {
    foreach ($rows as $r) {
        $checked++;
        if ($r->spine_id === null) {
            finding('micro_coverage', 'DRIFT', 'missing_spine_row', $r->sample_id, $r->userpkey,
                "micro source sample has no strabosamples row (project {$r->project_internal_id})");
        } elseif ($r->link_pkey === null) {
            finding('micro_coverage', 'DRIFT', 'missing_micro_link', $r->sample_id, $r->userpkey,
                "spine row exists but no micro link to project {$r->project_internal_id}");
        }
    }
}

// 1b — spine rows carrying micro_data whose Micro source is gone.
$userAndS = $opt['user'] !== null ? ' AND s.userpkey = ' . (int)$opt['user'] : '';
$rows = $db->get_results("
    SELECT s.id AS sample_id, s.userpkey
      FROM strabosamples.samples s
     WHERE s.micro_data IS NOT NULL$userAndS
       AND NOT EXISTS (
           SELECT 1
             FROM strabomicro.micro_samplemetadata sm
             JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
             JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
            WHERE sm.strabo_id = s.id AND pm.userpkey = s.userpkey)
");
if (is_array($rows)) {
    foreach ($rows as $r) {
        $checked++;
        finding('micro_coverage', 'DRIFT', 'stale_micro_mirror', $r->sample_id, $r->userpkey,
            'spine row has micro_data but no Micro source sample exists (missed delete sync?)');
    }
}

// 1c — micro links whose referenced project no longer exists.
$rows = $db->get_results("
    SELECT l.sample_id, l.sample_userpkey, l.reference_id
      FROM strabosamples.sample_subsystem_links l
     WHERE l.subsystem = 'micro'" .
     ($opt['user'] !== null ? ' AND l.sample_userpkey = ' . (int)$opt['user'] : '') . "
       AND NOT EXISTS (SELECT 1 FROM strabomicro.micro_projectmetadata pm
                        WHERE pm.id::text = l.reference_id)
");
if (is_array($rows)) {
    foreach ($rows as $r) {
        $checked++;
        finding('micro_coverage', 'DRIFT', 'dangling_micro_link', $r->sample_id, $r->sample_userpkey,
            "micro link references project {$r->reference_id} which no longer exists");
    }
}
section_finish('micro_coverage', $checked);

// ===========================================================================
// SECTION 2 — MICRO value fidelity (spine + micro_data vs source columns)
//
// A sample can be hosted by SEVERAL Micro projects (many-to-many, §5.5) but
// the row carries ONE micro_data / spine — the last-synced host's version.
// So the stored values are compared against EVERY hosting source row and a
// mismatch only counts when none of them match.
// ===========================================================================

section_start('[2/6] Micro value fidelity');
$checked = 0;

$rows = $db->get_results("
    SELECT sm.strabo_id AS sample_id, pm.userpkey, pm.id AS project_internal_id,
           sm.label, sm.sampleid, sm.mainsamplingpurpose, sm.sampledescription,
           sm.materialtype, sm.othermaterialtype, sm.samplenotes,
           sm.longitude, sm.latitude,
           s.name, s.igsn, s.description, s.notes,
           s.latitude AS s_latitude, s.longitude AS s_longitude,
           s.display_sample_type, s.display_sample_purpose,
           s.field_data::text AS field_data, s.micro_data::text AS micro_data,
           s.experimental_data::text AS experimental_data
      FROM strabomicro.micro_samplemetadata sm
      JOIN strabomicro.micro_datasetmetadata dm ON dm.id = sm.dataset_id
      JOIN strabomicro.micro_projectmetadata pm ON pm.id = dm.project_id
      JOIN strabosamples.samples s ON s.id = sm.strabo_id AND s.userpkey = pm.userpkey
     WHERE sm.strabo_id IS NOT NULL AND sm.strabo_id <> ''$userAnd
     ORDER BY sm.strabo_id, pm.userpkey
");

// Group the hosting source rows per (sample_id, userpkey).
$groups = array();
if (is_array($rows)) {
    foreach ($rows as $r) {
        $groups[$r->sample_id . '|' . $r->userpkey][] = $r;
    }
}
foreach ($groups as $hosts) {
    $checked++;
    $r0 = $hosts[0];   // spine columns identical across the group

    // micro_data JSONB must match at least one hosting source row (it's
    // written verbatim by whichever host synced last) unless a samples_api
    // JSONB edit came later.
    $md = decode_jsonb($r0->micro_data);
    $bestDiff = null;
    foreach ($hosts as $r) {
        $dataDiff = array();
        if ($md === null) {
            $dataDiff[] = 'micro_data(null)';
        } else {
            foreach (array('label', 'sampleid', 'sampledescription', 'samplenotes',
                           'materialtype', 'othermaterialtype', 'mainsamplingpurpose') as $col) {
                if (!eq_val(isset($r->$col) ? $r->$col : null, isset($md[$col]) ? $md[$col] : null)) $dataDiff[] = $col;
            }
            foreach (array('latitude', 'longitude') as $col) {
                if (!eq_float(isset($r->$col) ? $r->$col : null, isset($md[$col]) ? $md[$col] : null)) $dataDiff[] = $col;
            }
        }
        if (empty($dataDiff)) { $bestDiff = array(); break; }
        if ($bestDiff === null || count($dataDiff) < count($bestDiff)) $bestDiff = $dataDiff;
    }
    if (!empty($bestDiff)) {
        $writer = last_jsonb_writer($db, $r0->sample_id, $r0->userpkey, 'micro_data');
        $class = ($writer === 'samples_api') ? 'INFO' : 'DRIFT';
        $kind  = ($writer === 'samples_api') ? 'micro_data_api_edited' : 'micro_data_mismatch';
        finding('micro_fidelity', $class, $kind, $r0->sample_id, $r0->userpkey,
            'micro_data matches none of ' . count($hosts) . ' hosting project(s); closest differs on: '
            . implode(', ', $bestDiff) . " (last jsonb writer: " . ($writer ?: 'unknown') . ")");
    }

    // Spine fidelity — only when Micro owns the spine (no field_data).
    // Same any-host rule.
    if ($r0->field_data === null && $r0->micro_data !== null) {
        $stored = (object)array(
            'name' => $r0->name, 'igsn' => $r0->igsn, 'description' => $r0->description,
            'notes' => $r0->notes, 'latitude' => $r0->s_latitude, 'longitude' => $r0->s_longitude,
            'display_sample_type' => $r0->display_sample_type,
            'display_sample_purpose' => $r0->display_sample_purpose,
        );
        $bestDiff = null;
        foreach ($hosts as $r) {
            $diff = spine_diff($stored, project_micro_spine($r));
            if (empty($diff)) { $bestDiff = array(); break; }
            if ($bestDiff === null || count($diff) < count($bestDiff)) $bestDiff = $diff;
        }
        if (!empty($bestDiff)) {
            $writer = last_spine_writer($db, $r0->sample_id, $r0->userpkey);
            if ($writer === 'samples_api') {
                // Manual edit on a Micro-owned sample: no writeback to
                // Micro by design — divergence persists until the next
                // Micro upload re-mirrors (§10.4 last-writer-wins).
                finding('micro_fidelity', 'INFO', 'manual_edit_no_writeback', $r0->sample_id, $r0->userpkey,
                    'spine differs from Micro source on: ' . implode(', ', $bestDiff) . ' (samples_api edit, by design)');
            } else {
                finding('micro_fidelity', 'DRIFT', 'spine_mismatch', $r0->sample_id, $r0->userpkey,
                    'spine matches none of ' . count($hosts) . ' hosting project(s); closest differs on: '
                    . implode(', ', $bestDiff) . " (last spine writer: " . ($writer ?: 'unknown') . ")");
            }
        }
    }
}
section_finish('micro_fidelity', $checked);

// ===========================================================================
// SECTION 3 — EXPERIMENTAL coverage + stale mirrors
// ===========================================================================

section_start('[3/6] Experimental coverage');
$checked = 0;

$userAndE = $opt['user'] !== null ? ' AND es.userpkey = ' . (int)$opt['user'] : '';

// 3a — every Exp source sample has a spine row and an experimental link.
$rows = $db->get_results("
    SELECT es.strabo_id AS sample_id, es.userpkey, es.experiment_pkey,
           s.id AS spine_id, l.pkey AS link_pkey
      FROM straboexp.sample es
      LEFT JOIN strabosamples.samples s
             ON s.id = es.strabo_id::text AND s.userpkey = es.userpkey
      LEFT JOIN strabosamples.sample_subsystem_links l
             ON l.sample_id = es.strabo_id::text AND l.sample_userpkey = es.userpkey
            AND l.subsystem = 'experimental' AND l.reference_id = es.experiment_pkey::text
     WHERE es.strabo_id IS NOT NULL$userAndE
");
if (is_array($rows)) {
    foreach ($rows as $r) {
        $checked++;
        if ($r->spine_id === null) {
            finding('exp_coverage', 'DRIFT', 'missing_spine_row', $r->sample_id, $r->userpkey,
                "exp source sample has no strabosamples row (experiment {$r->experiment_pkey})");
        } elseif ($r->link_pkey === null) {
            finding('exp_coverage', 'DRIFT', 'missing_exp_link', $r->sample_id, $r->userpkey,
                "spine row exists but no experimental link to experiment {$r->experiment_pkey}");
        }
    }
}

// 3b — spine rows carrying experimental_data whose Exp source is gone.
$rows = $db->get_results("
    SELECT s.id AS sample_id, s.userpkey
      FROM strabosamples.samples s
     WHERE s.experimental_data IS NOT NULL$userAndS
       AND NOT EXISTS (SELECT 1 FROM straboexp.sample es
                        WHERE es.strabo_id::text = s.id AND es.userpkey = s.userpkey)
");
if (is_array($rows)) {
    foreach ($rows as $r) {
        $checked++;
        finding('exp_coverage', 'DRIFT', 'stale_exp_mirror', $r->sample_id, $r->userpkey,
            'spine row has experimental_data but no straboexp.sample exists (missed delete sync?)');
    }
}

// 3c — experimental links whose referenced experiment no longer exists.
$rows = $db->get_results("
    SELECT l.sample_id, l.sample_userpkey, l.reference_id
      FROM strabosamples.sample_subsystem_links l
     WHERE l.subsystem = 'experimental'" .
     ($opt['user'] !== null ? ' AND l.sample_userpkey = ' . (int)$opt['user'] : '') . "
       AND NOT EXISTS (SELECT 1 FROM straboexp.experiment e
                        WHERE e.pkey::text = l.reference_id)
");
if (is_array($rows)) {
    foreach ($rows as $r) {
        $checked++;
        finding('exp_coverage', 'DRIFT', 'dangling_exp_link', $r->sample_id, $r->sample_userpkey,
            "experimental link references experiment {$r->reference_id} which no longer exists");
    }
}
section_finish('exp_coverage', $checked);

// ===========================================================================
// SECTION 4 — EXPERIMENTAL value fidelity (spine, experimental_data, children)
// ===========================================================================

section_start('[4/6] Experimental value fidelity');
$checked = 0;

$rows = $db->get_results("
    SELECT es.strabo_id AS sample_id, es.userpkey, es.pkey AS exp_sample_pkey,
           es.id, es.name AS e_name, es.igsn AS e_igsn, es.description AS e_description,
           es.material_type, es.provenance_loc_latitude, es.provenance_loc_longitude,
           s.name, s.igsn, s.description, s.notes,
           s.latitude AS s_latitude, s.longitude AS s_longitude,
           s.display_sample_type, s.display_sample_purpose,
           s.field_data::text AS field_data, s.micro_data::text AS micro_data,
           s.experimental_data::text AS experimental_data,
           (SELECT count(*) FROM straboexp.sample_composition c
             WHERE c.sample_pkey = es.pkey AND coalesce(c.mineral,'') <> '') AS src_comp,
           (SELECT count(*) FROM straboexp.sample_parameter p
             WHERE p.sample_pkey = es.pkey AND coalesce(p.control,'') <> '') AS src_param,
           (SELECT count(*) FROM straboexp.document d
             WHERE d.sample_pkey = es.pkey
               AND (coalesce(d.uuid,'') <> '' OR coalesce(d.path,'') <> '')) AS src_doc,
           (SELECT count(*) FROM strabosamples.sample_composition c
             WHERE c.sample_id = es.strabo_id::text AND c.sample_userpkey = es.userpkey) AS sp_comp,
           (SELECT count(*) FROM strabosamples.sample_parameters p
             WHERE p.sample_id = es.strabo_id::text AND p.sample_userpkey = es.userpkey) AS sp_param,
           (SELECT count(*) FROM strabosamples.sample_documents d
             WHERE d.sample_id = es.strabo_id::text AND d.sample_userpkey = es.userpkey) AS sp_doc
      FROM straboexp.sample es
      JOIN strabosamples.samples s ON s.id = es.strabo_id::text AND s.userpkey = es.userpkey
     WHERE es.strabo_id IS NOT NULL$userAndE
");
if (is_array($rows)) {
    foreach ($rows as $r) {
        $checked++;

        // experimental_data JSONB vs source columns.
        $ed = decode_jsonb($r->experimental_data);
        $dataDiff = array();
        if ($ed === null) {
            $dataDiff[] = 'experimental_data(null)';
        } else {
            $map = array('id' => 'id', 'name' => 'e_name', 'igsn' => 'e_igsn',
                         'description' => 'e_description', 'material_type' => 'material_type',
                         'provenance_loc_latitude' => 'provenance_loc_latitude',
                         'provenance_loc_longitude' => 'provenance_loc_longitude');
            foreach ($map as $jsonKey => $col) {
                $src = isset($r->$col) ? $r->$col : null;
                $mir = isset($ed[$jsonKey]) ? $ed[$jsonKey] : null;
                if (!eq_val($src, $mir)) $dataDiff[] = $jsonKey;
            }
        }
        if (!empty($dataDiff)) {
            $writer = last_jsonb_writer($db, $r->sample_id, $r->userpkey, 'experimental_data');
            $class = ($writer === 'samples_api') ? 'INFO' : 'DRIFT';
            $kind  = ($writer === 'samples_api') ? 'exp_data_api_edited' : 'exp_data_mismatch';
            finding('exp_fidelity', $class, $kind, $r->sample_id, $r->userpkey,
                'experimental_data vs source differs on: ' . implode(', ', $dataDiff) . " (last jsonb writer: " . ($writer ?: 'unknown') . ")");
        }

        // Spine fidelity — only when Experimental owns the spine.
        if ($r->field_data === null && $r->micro_data === null && $r->experimental_data !== null) {
            $stored = (object)array(
                'name' => $r->name, 'igsn' => $r->igsn, 'description' => $r->description,
                'notes' => $r->notes, 'latitude' => $r->s_latitude, 'longitude' => $r->s_longitude,
                'display_sample_type' => $r->display_sample_type,
                'display_sample_purpose' => $r->display_sample_purpose,
            );
            $proj = project_exp_spine((object)array(
                'name' => $r->e_name, 'igsn' => $r->e_igsn, 'id' => $r->id,
                'description' => $r->e_description, 'material_type' => $r->material_type,
                'provenance_loc_latitude' => $r->provenance_loc_latitude,
                'provenance_loc_longitude' => $r->provenance_loc_longitude,
            ));
            $diff = spine_diff($stored, $proj);
            if (!empty($diff)) {
                $writer = last_spine_writer($db, $r->sample_id, $r->userpkey);
                if ($writer === 'samples_api') {
                    finding('exp_fidelity', 'INFO', 'manual_edit_no_writeback', $r->sample_id, $r->userpkey,
                        'spine differs from Exp source on: ' . implode(', ', $diff) . ' (samples_api edit, by design)');
                } else {
                    finding('exp_fidelity', 'DRIFT', 'spine_mismatch', $r->sample_id, $r->userpkey,
                        'spine differs from Exp source on: ' . implode(', ', $diff) . " (last spine writer: " . ($writer ?: 'unknown') . ")");
                }
            }
        }

        // Children counts (composition / parameters / documents).
        $cDiff = array();
        if ((int)$r->src_comp  !== (int)$r->sp_comp)  $cDiff[] = "composition {$r->src_comp}→{$r->sp_comp}";
        if ((int)$r->src_param !== (int)$r->sp_param) $cDiff[] = "parameters {$r->src_param}→{$r->sp_param}";
        if ((int)$r->src_doc   !== (int)$r->sp_doc)   $cDiff[] = "documents {$r->src_doc}→{$r->sp_doc}";
        if (!empty($cDiff)) {
            if (children_edited_via_api($db, $r->sample_id, $r->userpkey)) {
                finding('exp_fidelity', 'INFO', 'children_api_edited', $r->sample_id, $r->userpkey,
                    'child row counts differ (' . implode('; ', $cDiff) . ') — samples_api children edit is newer than the last Exp save');
            } else {
                finding('exp_fidelity', 'DRIFT', 'children_count_mismatch', $r->sample_id, $r->userpkey,
                    'child row counts differ (source→spine): ' . implode('; ', $cDiff));
            }
        }
    }
}
section_finish('exp_fidelity', $checked);

// ===========================================================================
// SECTION 5 — FIELD link fidelity (sampled Neo4j probes)
// ===========================================================================

section_start('[5/6] Field link fidelity' . ($opt['sample'] ? " (random sample of {$opt['sample']})" : ' (ALL links)'));
$checked = 0;

$limitSql = $opt['sample'] > 0 ? ' ORDER BY random() LIMIT ' . (int)$opt['sample'] : '';
$links = $db->get_results("
    SELECT l.sample_id, l.sample_userpkey, l.reference_id, l.reference_userpkey,
           l.reference_metadata::text AS rm,
           s.name, s.igsn, s.description, s.notes,
           s.latitude AS s_latitude, s.longitude AS s_longitude,
           s.display_sample_type, s.display_sample_purpose,
           s.field_data::text AS field_data
      FROM strabosamples.sample_subsystem_links l
      JOIN strabosamples.samples s ON s.id = l.sample_id AND s.userpkey = l.sample_userpkey
     WHERE l.subsystem = 'field'" .
     ($opt['user'] !== null ? ' AND l.sample_userpkey = ' . (int)$opt['user'] : '') .
     $limitSql
);
if (is_array($links)) {
    foreach ($links as $l) {
        $checked++;
        $sampleId = (string)$l->sample_id;
        $upk      = (int)$l->sample_userpkey;

        if (!ctype_digit((string)$l->reference_id)) {
            finding('field_fidelity', 'DRIFT', 'non_numeric_spot_ref', $sampleId, $upk,
                "field link reference_id '{$l->reference_id}' is not a numeric spot id");
            continue;
        }
        $spotId  = (int)$l->reference_id;
        $spotUpk = (int)$l->reference_userpkey;

        $rec = $neodb->getRecord("MATCH (s:Spot {id:$spotId, userpkey:$spotUpk}) RETURN s LIMIT 1");
        if (!$rec) {
            finding('field_fidelity', 'DRIFT', 'stale_field_link', $sampleId, $upk,
                "linked Spot $spotId no longer exists in Neo4j (missed delete sync?)");
            continue;
        }
        $props = $rec->get('s')->values();
        if (!is_array($props)) $props = array();

        $raw = isset($props['json_samples']) ? $props['json_samples'] : null;
        $samples = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($samples)) {
            finding('field_fidelity', 'DRIFT', 'missing_source_entry', $sampleId, $upk,
                "linked Spot $spotId has no json_samples");
            continue;
        }

        $meta   = decode_jsonb($l->rm);
        $isRich = is_array($meta) && !empty($meta['rich']);
        $entry  = null;
        if ($isRich) {
            $entry = isset($samples[0]) ? (array)$samples[0] : null;
        } else {
            foreach ($samples as $e) {
                $e = (array)$e;
                if (isset($e['id']) && (string)$e['id'] === $sampleId) { $entry = $e; break; }
            }
        }
        if ($entry === null) {
            finding('field_fidelity', 'DRIFT', 'missing_source_entry', $sampleId, $upk,
                "sample not found in Spot $spotId json_samples (missed re-sync after edit?)");
            continue;
        }

        if ($l->field_data === null) {
            finding('field_fidelity', 'DRIFT', 'field_data_missing', $sampleId, $upk,
                "field link exists but field_data JSONB is NULL");
        }

        // A legacy sample can sit in the json_samples of SEVERAL holder
        // spots (one field link each), but the row carries ONE spine /
        // field_data — the last-synced holder's version. Mismatches
        // against this link's holder are re-checked against the sample's
        // other holders (lazily) and only count when none match.
        $holders = array(array('spot' => $spotId, 'entry' => $entry, 'props' => $props));
        $holdersLoaded = false;
        $loadOtherHolders = function () use (&$holders, &$holdersLoaded, $db, $neodb, $sampleId, $upk, $spotId) {
            if ($holdersLoaded) return;
            $holdersLoaded = true;
            $others = $db->get_results_prepared(
                "SELECT reference_id, reference_userpkey, reference_metadata::text AS rm
                   FROM strabosamples.sample_subsystem_links
                  WHERE sample_id=$1 AND sample_userpkey=$2 AND subsystem='field'
                    AND reference_id <> $3",
                array($sampleId, $upk, (string)$spotId)
            );
            if (!is_array($others)) return;
            foreach ($others as $o) {
                if (!ctype_digit((string)$o->reference_id)) continue;
                $oSpot = (int)$o->reference_id;
                $oUpk  = (int)$o->reference_userpkey;
                $rec = $neodb->getRecord("MATCH (s:Spot {id:$oSpot, userpkey:$oUpk}) RETURN s LIMIT 1");
                if (!$rec) continue;
                $p = $rec->get('s')->values();
                if (!is_array($p)) continue;
                $raw = isset($p['json_samples']) ? $p['json_samples'] : null;
                $arr = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
                if (!is_array($arr)) continue;
                $m = decode_jsonb($o->rm);
                $e = null;
                if (is_array($m) && !empty($m['rich'])) {
                    $e = isset($arr[0]) ? (array)$arr[0] : null;
                } else {
                    foreach ($arr as $cand) {
                        $cand = (array)$cand;
                        if (isset($cand['id']) && (string)$cand['id'] === $sampleId) { $e = $cand; break; }
                    }
                }
                if ($e !== null) $holders[] = array('spot' => $oSpot, 'entry' => $e, 'props' => $p);
            }
        };

        // Spine fidelity. Field owns the spine whenever field_data is set;
        // the writeback keeps spine and Neo4j converged even for modal
        // edits, so a mismatch here is drift UNLESS it's confined to
        // vocab-translation territory after a samples_api edit.
        $stored = (object)array(
            'name' => $l->name, 'igsn' => $l->igsn, 'description' => $l->description,
            'notes' => $l->notes, 'latitude' => $l->s_latitude, 'longitude' => $l->s_longitude,
            'display_sample_type' => $l->display_sample_type,
            'display_sample_purpose' => $l->display_sample_purpose,
        );
        $diff = spine_diff($stored, project_field_spine($entry, $props));
        if (!empty($diff)) {
            $loadOtherHolders();
            $bestDiff = $diff;
            foreach (array_slice($holders, 1) as $h) {
                $d = spine_diff($stored, project_field_spine($h['entry'], $h['props']));
                if (empty($d)) { $bestDiff = array(); break; }
                if (count($d) < count($bestDiff)) $bestDiff = $d;
            }
            $diff = $bestDiff;
        }
        if (!empty($diff)) {
            $writer = last_spine_writer($db, $sampleId, $upk);
            if ($writer === 'samples_api' && is_subset($diff, $API_EDITABLE_SPINE)) {
                // Writeback ran but the vocab translator may have skipped or
                // bucketed enum values ("Glass" has no Field bucket) — the
                // changelog's writeback_translation rows carry the detail.
                finding('field_fidelity', 'INFO', 'writeback_translation_gap', $sampleId, $upk,
                    'spine differs from Spot ' . $spotId . ' on: ' . implode(', ', $diff) .
                    ' after a samples_api edit (check writeback_translation changelog rows)');
            } else {
                finding('field_fidelity', 'DRIFT', 'spine_mismatch', $sampleId, $upk,
                    'spine matches none of ' . count($holders) . ' holder spot(s); closest differs on: ' . implode(', ', $diff) .
                    " (last spine writer: " . ($writer ?: 'unknown') . ")");
            }
        }

        // field_data cache vs the live Neo4j entry. The writeback updates
        // Neo4j but not field_data, so a diff confined to writeback keys
        // after a samples_api edit is the known stale-cache case.
        $fd = decode_jsonb($l->field_data);
        if ($fd !== null) {
            $dDiff = diff_keys($fd, $entry);
            if (!empty($dDiff)) {
                $loadOtherHolders();
                foreach (array_slice($holders, 1) as $h) {
                    $d = diff_keys($fd, $h['entry']);
                    if (empty($d)) { $dDiff = array(); break; }
                    if (count($d) < count($dDiff)) $dDiff = $d;
                }
            }
            if (!empty($dDiff)) {
                $writer = last_spine_writer($db, $sampleId, $upk);
                if ($writer === 'samples_api' && is_subset($dDiff, $FIELD_WRITEBACK_KEYS)) {
                    finding('field_fidelity', 'INFO', 'stale_field_data_cache', $sampleId, $upk,
                        'field_data lags Neo4j on: ' . implode(', ', $dDiff) . ' (modal edit writes back to Neo4j only, by design)');
                } else {
                    finding('field_fidelity', 'DRIFT', 'field_data_mismatch', $sampleId, $upk,
                        'field_data matches none of ' . count($holders) . ' holder spot(s); closest differs on: ' . implode(', ', $dDiff));
                }
            }
        }
    }
}
section_finish('field_fidelity', $checked);

// ===========================================================================
// SECTION 6 — FIELD coverage: recently-modified Neo4j Spots → spine
// ===========================================================================

section_start('[6/6] Field coverage' . ($opt['since'] ? " (Spots modified in the last {$opt['since']}d)" : ' — SKIPPED (--since 0)'));
$checked = 0;

if ($opt['since'] > 0) {
    $cutS  = time() - $opt['since'] * 86400;
    $cutMs = $cutS * 1000;
    // modified_timestamp is written in ms by current code; accept legacy
    // seconds-format values too (anything below 1e11 is seconds). Some old
    // Spots store the value as a STRING — toInt() normalizes both forms
    // (and nulls out unparsable values, which the comparison then filters).
    $userCy = $opt['user'] !== null ? ' AND s.userpkey = ' . (int)$opt['user'] : '';
    $records = $neodb->query("
        MATCH (s:Spot)
        WHERE s.json_samples IS NOT NULL AND s.json_samples <> ''
          AND (toInt(s.modified_timestamp) >= $cutMs
               OR (toInt(s.modified_timestamp) < 100000000000 AND toInt(s.modified_timestamp) >= $cutS))$userCy
        RETURN s.id AS sid, s.userpkey AS upk, s.isSample AS isS, s.json_samples AS js
    ");
    if (is_array($records)) {
        foreach ($records as $rec) {
            $spotId = $rec->value('sid');
            $upk    = (int)$rec->value('upk');
            $isS    = $rec->value('isS');
            $isRich = ($isS === 1 || $isS === true || $isS === '1' || $isS === 'true');
            $samples = json_decode((string)$rec->value('js'), true);
            if (!is_array($samples) || empty($samples)) continue;

            // Expected mirrors from this spot: rich → samples[0] only;
            // legacy → every entry that is not a promoted-then-stub.
            $expected = array();
            if ($isRich) {
                $e = (array)$samples[0];
                if (isset($e['id']) && (string)$e['id'] !== '') $expected[] = (string)$e['id'];
            } else {
                foreach ($samples as $e) {
                    $e = (array)$e;
                    if (isset($e['id']) && (string)$e['id'] !== '') $expected[] = (string)$e['id'];
                }
            }

            foreach ($expected as $sampleId) {
                $checked++;
                $row = $db->get_row_prepared(
                    "SELECT s.id,
                            EXISTS (SELECT 1 FROM strabosamples.sample_subsystem_links l
                                     WHERE l.sample_id = s.id AND l.sample_userpkey = s.userpkey
                                       AND l.subsystem = 'field') AS has_field_link
                       FROM strabosamples.samples s
                      WHERE s.id = $1 AND s.userpkey = $2",
                    array($sampleId, $upk)
                );
                if ($row !== null && $row->has_field_link !== 'f' && $row->has_field_link !== false) {
                    continue;   // mirrored + linked — the common case
                }
                // Not mirrored (or unlinked). For legacy holder entries this
                // may be a stub pointing at a rich sample-spot — the rich
                // spot owns the mirror, so probe before flagging.
                if (!$isRich && ctype_digit($sampleId)) {
                    $stub = $neodb->get_var(
                        "MATCH (r:Spot {id:" . (int)$sampleId . ", userpkey:$upk, isSample:1}) RETURN r.id LIMIT 1"
                    );
                    if (!empty($stub) && (int)$stub !== (int)$spotId) {
                        continue;   // stub entry; mirror belongs to the rich spot
                    }
                }
                if ($row === null) {
                    finding('field_coverage', 'DRIFT', 'missing_spine_row', $sampleId, $upk,
                        "sample in Spot $spotId json_samples has no strabosamples row (sync hook didn't fire?)");
                } else {
                    finding('field_coverage', 'DRIFT', 'missing_field_link', $sampleId, $upk,
                        "spine row exists but has no field link (source Spot $spotId)");
                }
            }
        }
    }
}
section_finish('field_coverage', $checked);

// ===========================================================================
// Summary
// ===========================================================================

$totalDrift = 0; $totalInfo = 0; $totalChecked = 0;
foreach ($SECTION_STATS as $s) {
    $totalDrift += $s['drift']; $totalInfo += $s['info']; $totalChecked += $s['checked'];
}

echo "\n==============================================================\n";
echo " SUMMARY\n";
echo "==============================================================\n";
printf(" %-18s %10s %8s %8s\n", 'section', 'checked', 'DRIFT', 'INFO');
foreach ($SECTION_STATS as $name => $s) {
    printf(" %-18s %10d %8d %8d\n", $name, $s['checked'], $s['drift'], $s['info']);
}
printf(" %-18s %10d %8d %8d\n", 'TOTAL', $totalChecked, $totalDrift, $totalInfo);
echo "\n";
if ($totalDrift === 0) {
    echo "RESULT: NO DRIFT" . ($totalInfo ? " ($totalInfo INFO finding(s) — expected divergence, see above)" : "") . "\n";
} else {
    echo "RESULT: $totalDrift DRIFT finding(s) — investigate (rawcache holds ~1 week of request forensics)\n";
}

if ($opt['json']) {
    echo "\n---JSON---\n";
    echo json_encode(array(
        'options'  => $opt,
        'sections' => $SECTION_STATS,
        'findings' => $FINDINGS,
        'drift'    => $totalDrift,
        'info'     => $totalInfo,
    )) . "\n";
}

exit($totalDrift === 0 ? 0 : 1);
