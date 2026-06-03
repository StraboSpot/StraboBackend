<?php
/**
 * File: run.php
 * Description: Phase 1 migration orchestrator. Reads source-row streams from
 *              extract_field / extract_micro / extract_experimental and
 *              writes into strabosamples.* per the §10 plan:
 *
 *                Pass 1: insert/merge sample rows in priority order
 *                        (Field > Micro > Experimental), preserving each
 *                        subsystem's data in *_data JSONB and logging
 *                        spine-column conflicts.
 *                Phase D: upsert one sample_subsystem_links row per
 *                        source row.
 *                Phase E: caller runs verify.php after --apply (separate
 *                        script for atomicity).
 *
 * Idempotency: a second --apply against unchanged sources writes zero
 * sample/link/child rows (we compare-then-replace for children, ON CONFLICT
 * DO NOTHING for links, and skip spine UPDATEs when the merged row is
 * byte-identical to the existing one). One migration_log row is still
 * appended per source row with action='skipped_duplicate'.
 *
 * Usage (always inside the strabo-php container):
 *   docker exec strabo-php php /srv/app/www/samplesdb/migration/run.php \
 *     [--dry-run]           default if neither --dry-run nor --apply is given
 *     [--apply]             commit DB writes
 *     [--source=field|micro|experimental]  scope to one source
 *     [--run-id=<uuid>]     use a fixed run_id (default: minted)
 *     [--limit=N]           cap per-source row count (testing)
 *     [--help]
 *
 * @package    StraboSamples Migration
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// Locate the web root so includes resolve regardless of CWD.
$webroot = realpath(__DIR__ . '/../..');
chdir($webroot);

require_once($webroot . '/includes/config.inc.php');
require_once($webroot . '/db.php');           // $db
require_once($webroot . '/neodb.php');        // $neodb
require_once($webroot . '/includes/UUID.php');

require_once(__DIR__ . '/extract_field.php');
require_once(__DIR__ . '/extract_micro.php');
require_once(__DIR__ . '/extract_experimental.php');
require_once(__DIR__ . '/populate_links.php');

// ---------------------------------------------------------------------------
// CLI args
// ---------------------------------------------------------------------------

$opts = migration_parse_argv($argv);
if (!empty($opts['help'])) {
    migration_print_help();
    exit(0);
}

$apply  = !empty($opts['apply']);
$dryRun = !$apply;  // default = dry-run
$limit  = isset($opts['limit']) ? (int)$opts['limit'] : null;
$only   = isset($opts['source']) ? strtolower($opts['source']) : null;
if ($only !== null && !in_array($only, array('field','micro','experimental'), true)) {
    fwrite(STDERR, "ERROR: --source must be one of: field, micro, experimental\n");
    exit(2);
}
$runId = isset($opts['run-id']) ? $opts['run-id'] : UUID::v4();

$mode = $dryRun ? 'DRY-RUN' : 'APPLY';
$sources = array('field', 'micro', 'experimental');
if ($only !== null) $sources = array($only);

echo "==============================================================\n";
echo " StraboSamples Phase 1 migration  ($mode)\n";
echo "   run_id : $runId\n";
echo "   sources: " . implode(', ', $sources) . "\n";
if ($limit !== null) {
    echo "   limit  : $limit rows per source\n";
}
echo "==============================================================\n\n";

// ---------------------------------------------------------------------------
// Extraction + Pass 1 + Phase D, per source, in priority order.
// ---------------------------------------------------------------------------

$summary = array();
foreach ($sources as $src) {
    $t0 = microtime(true);
    echo "--- $src --- extracting...\n";
    $rows = migration_extract($src, $db, $neodb, array('limit' => $limit, 'limit_spots' => $limit));
    $t1 = microtime(true);
    echo "    extracted " . count($rows) . " row(s) in " . sprintf('%.2fs', $t1-$t0) . "\n";

    $srcSummary = array(
        'extracted' => count($rows),
        'created' => 0, 'merged' => 0, 'skipped_duplicate' => 0,
        'conflict_logged' => 0, 'skipped_orphan' => 0,
        'links_inserted' => 0, 'links_existing' => 0,
        'children_inserted' => 0, 'children_replaced' => 0, 'children_unchanged' => 0,
    );

    $i = 0;
    foreach ($rows as $row) {
        $i++;
        $res = migration_process_row($db, $row, $runId, $dryRun);
        $srcSummary[$res['action']]++;
        if (!empty($res['conflicts'])) {
            $srcSummary['conflict_logged']++;
        }

        $linkRes = migration_upsert_link($db, $row, $dryRun);
        if ($linkRes === 'inserted')      $srcSummary['links_inserted']++;
        elseif ($linkRes === 'exists')    $srcSummary['links_existing']++;
        // dry_run counts implicitly as not-yet-inserted

        $childRes = migration_sync_children($db, $row, $dryRun);
        $srcSummary[$childRes]++;

        if ($i % 1000 === 0) {
            echo "    progress: $i / " . count($rows) . "\n";
        }
    }

    $t2 = microtime(true);
    echo "    upserted in " . sprintf('%.2fs', $t2-$t1) . "\n";
    echo "    summary: ";
    foreach ($srcSummary as $k => $v) {
        if ($v) echo "$k=$v ";
    }
    echo "\n\n";

    $summary[$src] = $srcSummary;
}

// ---------------------------------------------------------------------------
// Tail summary
// ---------------------------------------------------------------------------

echo "==============================================================\n";
echo " DONE ($mode)\n";
foreach ($summary as $src => $s) {
    echo "  $src:\n";
    foreach ($s as $k => $v) {
        echo "    $k = $v\n";
    }
}
if ($dryRun) {
    echo "\nNo DB writes were performed (dry-run). Re-run with --apply to commit.\n";
} else {
    $totalSamples = (int)$db->get_var("SELECT COUNT(*) FROM strabosamples.samples");
    $totalLinks   = (int)$db->get_var("SELECT COUNT(*) FROM strabosamples.sample_subsystem_links");
    $thisRunLog   = (int)$db->get_var_prepared(
        "SELECT COUNT(*) FROM strabosamples.samples_migration_log WHERE run_id = \$1",
        array($runId)
    );
    echo "\n";
    echo "Post-run totals:\n";
    echo "  strabosamples.samples                : $totalSamples\n";
    echo "  strabosamples.sample_subsystem_links : $totalLinks\n";
    echo "  samples_migration_log rows this run  : $thisRunLog\n";
    echo "\nNext: run verify.php to confirm parity, no orphans, and reference resolution.\n";
}
echo "==============================================================\n";

exit(0);


// ===========================================================================
// Functions
// ===========================================================================

function migration_parse_argv($argv) {
    $opts = array();
    for ($i = 1; $i < count($argv); $i++) {
        $a = $argv[$i];
        if ($a === '--help' || $a === '-h') {
            $opts['help'] = true;
        } else if ($a === '--apply')   {
            $opts['apply']  = true;
        } else if ($a === '--dry-run') {
            // explicit; default anyway — set apply=false to be safe
            $opts['apply']  = false;
        } else if (strpos($a, '--source=') === 0) {
            $opts['source'] = substr($a, 9);
        } else if (strpos($a, '--run-id=') === 0) {
            $opts['run-id'] = substr($a, 9);
        } else if (strpos($a, '--limit=') === 0) {
            $opts['limit']  = (int)substr($a, 8);
        } else {
            fwrite(STDERR, "Unknown arg: $a\n");
            exit(2);
        }
    }
    return $opts;
}

function migration_print_help() {
    echo "Usage: php run.php [--dry-run|--apply] [--source=field|micro|experimental]\n";
    echo "                   [--run-id=<uuid>] [--limit=N]\n\n";
    echo "  --dry-run   default. Print extraction + would-be-action counts; no writes.\n";
    echo "  --apply     commit writes to strabosamples.* + migration_log.\n";
    echo "  --source=X  only run that subsystem (default: all three in priority order).\n";
    echo "  --run-id=U  reuse a specific run_id (default: minted v4 UUID).\n";
    echo "  --limit=N   cap source-row count per source (testing).\n";
}

/**
 * Dispatch to the appropriate extractor.
 */
function migration_extract($src, $db, $neodb, $opts) {
    if ($src === 'field')        return migration_extract_field($neodb, $opts);
    if ($src === 'micro')        return migration_extract_micro($db, $opts);
    if ($src === 'experimental') return migration_extract_experimental($db, $opts);
    return array();
}

/**
 * Merge one source row into strabosamples.samples, log the action.
 *
 * Returns ['action' => 'created'|'merged'|'skipped_duplicate'|'skipped_orphan',
 *          'conflicts' => array|null]
 */
function migration_process_row($db, $row, $runId, $dryRun) {

    $sampleId = $row['sample_id'];
    $userpkey = $row['sample_userpkey'];

    if ($sampleId === '' || $userpkey <= 0) {
        migration_log_action($db, $runId, $row, null, null, 'skipped_orphan',
            null, 'missing sample_id or userpkey', $dryRun);
        return array('action' => 'skipped_orphan', 'conflicts' => null);
    }

    $existing = $db->get_row_prepared("
        SELECT id, userpkey, name, igsn, description, notes,
               latitude, longitude, display_sample_type, display_sample_purpose,
               EXTRACT(EPOCH FROM created_at)::bigint  AS created_at_epoch,
               EXTRACT(EPOCH FROM modified_at)::bigint AS modified_at_epoch,
               created_by, modified_by,
               field_data, micro_data, experimental_data
        FROM strabosamples.samples
        WHERE id = \$1 AND userpkey = \$2
    ", array($sampleId, $userpkey));

    if (!$existing) {
        // INSERT — this source is first to touch the sample.
        $created    = $row['created_at']  !== null ? (int)$row['created_at']  : time();
        $modified   = $row['modified_at'] !== null ? (int)$row['modified_at'] : $created;
        $createdBy  = (int)$row['created_by'];

        $dataKey = migration_data_column_for_source($row['source']);
        $fieldData = $dataKey === 'field_data'        ? json_encode($row['subsystem_data']) : null;
        $microData = $dataKey === 'micro_data'        ? json_encode($row['subsystem_data']) : null;
        $expData   = $dataKey === 'experimental_data' ? json_encode($row['subsystem_data']) : null;

        if (!$dryRun) {
            $db->prepare_query("
                INSERT INTO strabosamples.samples
                    (id, userpkey, name, igsn, description, notes,
                     latitude, longitude,
                     display_sample_type, display_sample_purpose,
                     created_at, created_by, modified_at, modified_by,
                     field_data, micro_data, experimental_data)
                VALUES (\$1, \$2, \$3, \$4, \$5, \$6,
                        \$7, \$8,
                        \$9, \$10,
                        TO_TIMESTAMP(\$11), \$12, TO_TIMESTAMP(\$13), \$14,
                        \$15::jsonb, \$16::jsonb, \$17::jsonb)
            ", array(
                $sampleId, $userpkey,
                $row['name'], $row['igsn'], $row['description'], $row['notes'],
                $row['latitude'], $row['longitude'],
                $row['display_sample_type'], $row['display_sample_purpose'],
                $created, $createdBy, $modified, $createdBy,
                $fieldData, $microData, $expData,
            ));
        }
        migration_log_action($db, $runId, $row, $sampleId, $userpkey,
            'created', null, null, $dryRun);
        return array('action' => 'created', 'conflicts' => null);
    }

    // EXISTING — this is a lower-priority source merging in (or a re-run).
    // Spine cols are preserved; *_data is overwritten for THIS subsystem;
    // dates roll MIN(created) / MAX(modified); conflicts on spine cols
    // are logged when both sides are non-null and differ.

    $dataKey = migration_data_column_for_source($row['source']);
    $existingJsonb = isset($existing->{$dataKey}) ? $existing->{$dataKey} : null;
    $newJsonbStr   = json_encode($row['subsystem_data']);

    $existingCreated  = isset($existing->created_at_epoch)  ? (int)$existing->created_at_epoch  : null;
    $existingModified = isset($existing->modified_at_epoch) ? (int)$existing->modified_at_epoch : null;
    $srcCreated  = $row['created_at']  !== null ? (int)$row['created_at']  : null;
    $srcModified = $row['modified_at'] !== null ? (int)$row['modified_at'] : null;

    $newCreated  = migration_min_ts($existingCreated, $srcCreated);
    $newModified = migration_max_ts($existingModified, $srcModified);
    $newCreatedBy = (int)$existing->created_by;
    if ($srcCreated !== null && ($existingCreated === null || $srcCreated < $existingCreated)) {
        $newCreatedBy = (int)$row['created_by'];
    }

    // Conflicts: lower-priority source's spine values disagree with existing
    // non-null spine values. (Field is loaded first, so when Micro or Exp
    // hits here, Field's values "win" without overwriting.)
    $conflicts = array();
    foreach (array('name','igsn','description','notes','latitude','longitude',
                   'display_sample_type','display_sample_purpose') as $col) {
        $ex = $existing->{$col};
        $nu = $row[$col];
        $exEmpty = ($ex === null || $ex === '');
        $nuEmpty = ($nu === null || $nu === '');
        if (!$exEmpty && !$nuEmpty && (string)$ex !== (string)$nu) {
            $conflicts[$col] = array(
                'kept'    => $ex,
                'dropped' => $nu,
                'from'    => $row['source'],
            );
        }
    }

    // Determine whether this is a true no-op (re-run with identical state).
    $unchangedJsonb = ($existingJsonb !== null)
        && (migration_canonical_json($existingJsonb) === migration_canonical_json($newJsonbStr));
    $unchangedTs    = ($existingCreated === $newCreated)
                   && ($existingModified === $newModified)
                   && ((int)$existing->created_by === $newCreatedBy);
    $isNoop = $unchangedJsonb && $unchangedTs && empty($conflicts);

    if ($isNoop) {
        migration_log_action($db, $runId, $row, $sampleId, $userpkey,
            'skipped_duplicate', null, null, $dryRun);
        return array('action' => 'skipped_duplicate', 'conflicts' => null);
    }

    if (!$dryRun) {
        // UPDATE only the subsystem JSONB column, the timestamps, and
        // created_by. Spine cols are NEVER overwritten on merge (§10.4).
        $db->prepare_query("
            UPDATE strabosamples.samples SET
                {$dataKey} = \$1::jsonb,
                created_at  = TO_TIMESTAMP(\$2),
                modified_at = TO_TIMESTAMP(\$3),
                created_by  = \$4
            WHERE id = \$5 AND userpkey = \$6
        ", array(
            $newJsonbStr,
            $newCreated,
            $newModified,
            $newCreatedBy,
            $sampleId, $userpkey,
        ));
    }

    $action = empty($conflicts) ? 'merged' : 'conflict_logged';
    migration_log_action($db, $runId, $row, $sampleId, $userpkey,
        $action, $conflicts ?: null, null, $dryRun);

    return array('action' => 'merged', 'conflicts' => $conflicts ?: null);
}

/**
 * Sync child rows (composition / parameters / documents) for a sample.
 * Compare-then-replace so identical content yields zero writes on re-run.
 *
 * Returns one of:
 *   'children_inserted' — first-time insert of any child rows
 *   'children_replaced' — existing children differ from incoming; deleted+inserted
 *   'children_unchanged' — no incoming children, OR incoming matches existing
 */
function migration_sync_children($db, $row, $dryRun) {
    $comp = $row['composition'];
    $parm = $row['parameters'];
    $docs = $row['documents'];

    // If this source brings no children, treat as unchanged (we never
    // delete on behalf of a source that has nothing to say). Field/Micro
    // always fall through here.
    if (empty($comp) && empty($parm) && empty($docs)) {
        return 'children_unchanged';
    }

    $sampleId = $row['sample_id'];
    $userpkey = $row['sample_userpkey'];

    $exComp = migration_existing_children($db, 'sample_composition', $sampleId, $userpkey,
        array('mineral','other_mineral','fraction','unit','grainsize','ordering'));
    $exParm = migration_existing_children($db, 'sample_parameters',  $sampleId, $userpkey,
        array('control','other_control','value','unit','prefix','note','ordering'));
    $exDocs = migration_existing_children($db, 'sample_documents',   $sampleId, $userpkey,
        array('uuid','type','other_type','format','other_format','path','document_id','original_filename','description','ordering'));

    $compEqual = migration_children_equal($exComp, $comp);
    $parmEqual = migration_children_equal($exParm, $parm);
    $docsEqual = migration_children_equal($exDocs, $docs);

    if ($compEqual && $parmEqual && $docsEqual) {
        return 'children_unchanged';
    }

    if (!$dryRun) {
        if (!$compEqual) {
            $db->prepare_query(
                "DELETE FROM strabosamples.sample_composition WHERE sample_id = \$1 AND sample_userpkey = \$2",
                array($sampleId, $userpkey)
            );
            foreach ($comp as $c) {
                $db->prepare_query("
                    INSERT INTO strabosamples.sample_composition
                        (sample_id, sample_userpkey, mineral, other_mineral, fraction, unit, grainsize, ordering)
                    VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7, \$8)
                ", array($sampleId, $userpkey,
                    (string)$c['mineral'], $c['other_mineral'], $c['fraction'], $c['unit'], $c['grainsize'], (int)$c['ordering']));
            }
        }
        if (!$parmEqual) {
            $db->prepare_query(
                "DELETE FROM strabosamples.sample_parameters WHERE sample_id = \$1 AND sample_userpkey = \$2",
                array($sampleId, $userpkey)
            );
            foreach ($parm as $p) {
                $db->prepare_query("
                    INSERT INTO strabosamples.sample_parameters
                        (sample_id, sample_userpkey, control, other_control, value, unit, prefix, note, ordering)
                    VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7, \$8, \$9)
                ", array($sampleId, $userpkey,
                    (string)$p['control'], $p['other_control'], $p['value'], $p['unit'], $p['prefix'], $p['note'], (int)$p['ordering']));
            }
        }
        if (!$docsEqual) {
            $db->prepare_query(
                "DELETE FROM strabosamples.sample_documents WHERE sample_id = \$1 AND sample_userpkey = \$2",
                array($sampleId, $userpkey)
            );
            foreach ($docs as $d) {
                $db->prepare_query("
                    INSERT INTO strabosamples.sample_documents
                        (sample_id, sample_userpkey, uuid, type, other_type, format, other_format,
                         path, document_id, original_filename, description, ordering)
                    VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7, \$8, \$9, \$10, \$11, \$12)
                ", array($sampleId, $userpkey,
                    (string)$d['uuid'], $d['type'], $d['other_type'], $d['format'], $d['other_format'],
                    $d['path'], $d['document_id'], $d['original_filename'], $d['description'], (int)$d['ordering']));
            }
        }
    }

    return (empty($exComp) && empty($exParm) && empty($exDocs))
        ? 'children_inserted' : 'children_replaced';
}

function migration_existing_children($db, $table, $sampleId, $userpkey, $cols) {
    $colList = implode(',', $cols);
    $rows = $db->get_results_prepared("
        SELECT $colList FROM strabosamples.$table
        WHERE sample_id = \$1 AND sample_userpkey = \$2
        ORDER BY ordering, pkey
    ", array($sampleId, $userpkey));
    if (!$rows) return array();
    $out = array();
    foreach ($rows as $r) {
        $entry = array();
        foreach ($cols as $c) {
            $entry[$c] = isset($r->{$c}) ? $r->{$c} : null;
        }
        $out[] = $entry;
    }
    return $out;
}

function migration_children_equal($a, $b) {
    if (count($a) !== count($b)) return false;
    for ($i = 0; $i < count($a); $i++) {
        // Compare field-by-field with loose stringification — Postgres
        // returns everything as strings; incoming may be ints.
        foreach ($a[$i] as $k => $v) {
            $ai = ($v === null || $v === '') ? '' : (string)$v;
            $bv = isset($b[$i][$k]) ? $b[$i][$k] : null;
            $bi = ($bv === null || $bv === '') ? '' : (string)$bv;
            if ($ai !== $bi) return false;
        }
    }
    return true;
}

function migration_data_column_for_source($src) {
    if ($src === 'field')        return 'field_data';
    if ($src === 'micro')        return 'micro_data';
    if ($src === 'experimental') return 'experimental_data';
    return null;
}

function migration_min_ts($a, $b) {
    if ($a === null) return $b;
    if ($b === null) return $a;
    return ($a < $b) ? $a : $b;
}
function migration_max_ts($a, $b) {
    if ($a === null) return $b;
    if ($b === null) return $a;
    return ($a > $b) ? $a : $b;
}

/**
 * Reduce a JSON string to a canonical (key-sorted, null-stripped) form for
 * cheap byte-equality on the merge no-op check.
 */
function migration_canonical_json($v) {
    if ($v === null) return null;
    $decoded = is_string($v) ? json_decode($v, true) : (array)$v;
    if (!is_array($decoded)) return json_encode($decoded);
    migration_canonicalize($decoded);
    return json_encode($decoded);
}
function migration_canonicalize(&$arr) {
    if (!is_array($arr)) return;
    ksort($arr);
    foreach ($arr as &$v) {
        if (is_array($v)) migration_canonicalize($v);
    }
}

/**
 * Append one samples_migration_log row.
 */
function migration_log_action($db, $runId, $row, $resultSampleId, $resultUserpkey,
                              $action, $conflicts, $notes, $dryRun) {
    if ($dryRun) return;  // log is for real runs only
    $db->prepare_query("
        INSERT INTO strabosamples.samples_migration_log
            (run_id, source_subsystem, source_id, source_userpkey,
             result_sample_id, result_userpkey, action, conflicts, notes)
        VALUES (\$1, \$2, \$3, \$4, \$5, \$6, \$7, \$8::jsonb, \$9)
    ", array(
        $runId,
        $row['source'],
        $row['source_label'],     // human-readable source pointer
        $row['sample_userpkey'],
        $resultSampleId,
        $resultUserpkey,
        $action,
        $conflicts !== null ? json_encode($conflicts) : null,
        $notes,
    ));
}
