<?php
/**
 * File: verify_extended.php
 * Description: Phase A extended verifier for the Phase 1 migration. Runs
 *              after verify.php to catch the classes of bug verify.php
 *              cannot:
 *
 *                1. Schema integrity — every spine column has the expected
 *                   type and the FK + CHECK constraints exist with the
 *                   expected delete behaviour. Catches the case where the
 *                   schema apply ran against an older DDL that's drifted
 *                   from samplesdb/schema/strabosamples.sql, or where a FK
 *                   got dropped mid-migration debugging.
 *                2. Dangling-FK probe — LEFT JOIN scans across every child
 *                   table that references samples (changelog, collaborators,
 *                   composition, documents, parameters, subsystem_links)
 *                   plus the self-FK on samples.parent_sample_id. Each
 *                   should be zero by the FK constraints; non-zero means
 *                   the FK has been disabled or never applied.
 *                3. Vocab-value sanity — every non-null display_sample_type
 *                   / display_sample_purpose is either a canonical vocab
 *                   value, a known free-text survivor, or fails as a
 *                   recognized junk pattern. Catches the case where the
 *                   spine_cleanup tool hasn't been run, or where a new
 *                   junk pattern leaked in through some other path.
 *                4. Sample/link cross-reconciliation — link table size and
 *                   distribution, per-subsystem counts, no duplicates,
 *                   samples-without-a-link counted (modal-only is OK).
 *
 *              Exits non-zero on any failure so this can be wired to CI
 *              and run as Step 6.5 of the prod runbook.
 *
 * Usage:
 *   docker exec strabo-php php /srv/app/www/samplesdb/migration/verify_extended.php
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

$webroot = realpath(__DIR__ . '/../..');
chdir($webroot);

require_once($webroot . '/includes/config.inc.php');
require_once($webroot . '/db.php');
require_once($webroot . '/samplesdb/lib/vocab.php');

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--help' || $a === '-h') {
        echo "Usage: php verify_extended.php\n";
        echo "\n";
        echo "  Runs after verify.php as Step 6.5 of the cutover runbook.\n";
        echo "  Exits 0 on success, 1 on any failure.\n";
        exit(0);
    }
}

$failures = array();
$results  = array();

echo "==============================================================\n";
echo " StraboSamples Phase 1 EXTENDED verification\n";
echo "==============================================================\n\n";

// ---------------------------------------------------------------------------
// Check 1 — schema integrity
// ---------------------------------------------------------------------------

echo "[1/4] Schema integrity ...\n";

$expectedColumns = array(
    'samples'                => array(
        'id'                     => 'text',
        'userpkey'               => 'integer',
        'name'                   => 'text',
        'igsn'                   => 'text',
        'description'            => 'text',
        'notes'                  => 'text',
        'latitude'               => 'double precision',
        'longitude'              => 'double precision',
        'display_sample_type'    => 'text',
        'display_sample_purpose' => 'text',
        'parent_sample_id'       => 'text',
        'parent_userpkey'        => 'integer',
        'created_at'             => 'timestamp with time zone',
        'created_by'             => 'integer',
        'modified_at'            => 'timestamp with time zone',
        'modified_by'            => 'integer',
        'field_data'             => 'jsonb',
        'micro_data'             => 'jsonb',
        'experimental_data'      => 'jsonb',
        'custom_data'            => 'jsonb',
    ),
    'sample_subsystem_links' => array(
        'sample_id'          => 'text',
        'sample_userpkey'    => 'integer',
        'subsystem'          => 'text',
        'reference_id'       => 'text',
        'reference_userpkey' => 'integer',
        'reference_metadata' => 'jsonb',
    ),
    'sample_changelog' => array(
        'sample_id'        => 'text',
        'sample_userpkey'  => 'integer',
        'changed_by'       => 'integer',
        'changed_at'       => 'timestamp with time zone',
        'change_type'      => 'text',
        'source_subsystem' => 'text',
        'changes'          => 'jsonb',
    ),
);

$columnMismatches = array();
foreach ($expectedColumns as $tbl => $cols) {
    foreach ($cols as $col => $expectedType) {
        $actual = $db->get_var_prepared(
            "SELECT data_type
               FROM information_schema.columns
              WHERE table_schema = 'strabosamples'
                AND table_name   = $1
                AND column_name  = $2",
            array($tbl, $col)
        );
        if ($actual === null || $actual === false) {
            $columnMismatches[] = "$tbl.$col: MISSING";
        } else if ((string)$actual !== $expectedType) {
            $columnMismatches[] = "$tbl.$col: expected '$expectedType', got '$actual'";
        }
    }
}
echo '    columns checked: ' . array_sum(array_map('count', $expectedColumns)) . "\n";
echo '    mismatches: ' . count($columnMismatches) . "\n";

$expectedConstraints = array(
    // (constraint_name, table, type, expected_action)
    array('samples_pkey',                          'samples',                'PRIMARY KEY', null),
    array('samples_parent_pair_chk',               'samples',                'CHECK',       null),
    array('samples_parent_sample_id_fkey',         'samples',                'FOREIGN KEY', 'SET NULL'),
    array('sample_changelog_sample_id_fkey',       'sample_changelog',       'FOREIGN KEY', 'CASCADE'),
    array('sample_collaborators_sample_id_fkey',   'sample_collaborators',   'FOREIGN KEY', 'CASCADE'),
    array('sample_composition_sample_id_fkey',     'sample_composition',     'FOREIGN KEY', 'CASCADE'),
    array('sample_documents_sample_id_fkey',       'sample_documents',       'FOREIGN KEY', 'CASCADE'),
    array('sample_parameters_sample_id_fkey',      'sample_parameters',      'FOREIGN KEY', 'CASCADE'),
    array('sample_subsystem_links_sample_id_fkey', 'sample_subsystem_links', 'FOREIGN KEY', 'CASCADE'),
    array('sample_subsystem_links_subsystem_check','sample_subsystem_links', 'CHECK',       null),
);

$constraintMismatches = array();
foreach ($expectedConstraints as $row) {
    list($name, $tbl, $type, $expectedAction) = $row;
    $hit = $db->get_row_prepared(
        "SELECT tc.constraint_type, rc.delete_rule
           FROM information_schema.table_constraints tc
      LEFT JOIN information_schema.referential_constraints rc
             ON rc.constraint_schema = tc.constraint_schema
            AND rc.constraint_name   = tc.constraint_name
          WHERE tc.constraint_schema = 'strabosamples'
            AND tc.table_name        = $1
            AND tc.constraint_name   = $2",
        array($tbl, $name)
    );
    if (!$hit) {
        $constraintMismatches[] = "$tbl.$name: MISSING";
        continue;
    }
    if ((string)$hit->constraint_type !== $type) {
        $constraintMismatches[] = "$tbl.$name: expected $type, got " . $hit->constraint_type;
        continue;
    }
    if ($expectedAction !== null && (string)$hit->delete_rule !== $expectedAction) {
        $constraintMismatches[] = "$tbl.$name: expected ON DELETE $expectedAction, got " . $hit->delete_rule;
    }
}
echo '    constraints checked: ' . count($expectedConstraints) . "\n";
echo '    mismatches: ' . count($constraintMismatches) . "\n";

if (empty($columnMismatches) && empty($constraintMismatches)) {
    echo "    OK\n";
    $results['schema_integrity'] = 'ok';
} else {
    echo "    FAIL\n";
    foreach ($columnMismatches as $m)     echo "      column: $m\n";
    foreach ($constraintMismatches as $m) echo "      constraint: $m\n";
    $results['schema_integrity'] = 'fail';
    $failures[] = 'schema_integrity: ' . count($columnMismatches) . ' column + ' . count($constraintMismatches) . ' constraint mismatch(es)';
}

// ---------------------------------------------------------------------------
// Check 2 — dangling FK probe across every child table + self-FK
// ---------------------------------------------------------------------------

echo "\n[2/4] Dangling FK probe ...\n";

$danglingChecks = array(
    'sample_changelog'        => "SELECT COUNT(*) FROM strabosamples.sample_changelog c
                                    LEFT JOIN strabosamples.samples s
                                      ON s.id = c.sample_id AND s.userpkey = c.sample_userpkey
                                   WHERE s.id IS NULL",
    'sample_collaborators'    => "SELECT COUNT(*) FROM strabosamples.sample_collaborators c
                                    LEFT JOIN strabosamples.samples s
                                      ON s.id = c.sample_id AND s.userpkey = c.sample_userpkey
                                   WHERE s.id IS NULL",
    'sample_composition'      => "SELECT COUNT(*) FROM strabosamples.sample_composition c
                                    LEFT JOIN strabosamples.samples s
                                      ON s.id = c.sample_id AND s.userpkey = c.sample_userpkey
                                   WHERE s.id IS NULL",
    'sample_documents'        => "SELECT COUNT(*) FROM strabosamples.sample_documents d
                                    LEFT JOIN strabosamples.samples s
                                      ON s.id = d.sample_id AND s.userpkey = d.sample_userpkey
                                   WHERE s.id IS NULL",
    'sample_parameters'       => "SELECT COUNT(*) FROM strabosamples.sample_parameters p
                                    LEFT JOIN strabosamples.samples s
                                      ON s.id = p.sample_id AND s.userpkey = p.sample_userpkey
                                   WHERE s.id IS NULL",
    'sample_subsystem_links'  => "SELECT COUNT(*) FROM strabosamples.sample_subsystem_links l
                                    LEFT JOIN strabosamples.samples s
                                      ON s.id = l.sample_id AND s.userpkey = l.sample_userpkey
                                   WHERE s.id IS NULL",
    'samples.parent_self_fk'  => "SELECT COUNT(*) FROM strabosamples.samples c
                                    LEFT JOIN strabosamples.samples p
                                      ON p.id = c.parent_sample_id AND p.userpkey = c.parent_userpkey
                                   WHERE c.parent_sample_id IS NOT NULL AND p.id IS NULL",
);

$totalDangling = 0;
foreach ($danglingChecks as $label => $sql) {
    $n = (int)$db->get_var($sql);
    $totalDangling += $n;
    echo "    $label: $n\n";
}
if ($totalDangling === 0) {
    echo "    OK\n";
    $results['dangling_fk'] = 'ok';
} else {
    echo "    FAIL — $totalDangling dangling row(s) across child tables.\n";
    $results['dangling_fk'] = 'fail';
    $failures[] = "dangling_fk: $totalDangling rows pointing at missing samples";
}

// ---------------------------------------------------------------------------
// Check 3 — vocab-value sanity
// ---------------------------------------------------------------------------

echo "\n[3/4] Vocab-value sanity ...\n";

$materialVocab = array();
foreach (samples_vocab_material_types() as $group => $values) {
    foreach ($values as $v => $_) $materialVocab[$v] = true;
}
$purposeVocab = array();
foreach (samples_vocab_sample_purposes() as $v => $_) $purposeVocab[$v] = true;

$knownJunkPurposes = array('bar', 'asdfbefgvb', 'test sample purpose');

$typeCats = array('vocab' => 0, 'free_text' => 0, 'junk' => 0);
$rows = $db->get_results(
    "SELECT display_sample_type, COUNT(*) AS n
       FROM strabosamples.samples
      WHERE display_sample_type IS NOT NULL
      GROUP BY display_sample_type"
);
$rows = $rows ?: array();
$junkTypeSamples = array();
foreach ($rows as $r) {
    $v = (string)$r->display_sample_type;
    $n = (int)$r->n;
    if (isset($materialVocab[$v])) {
        $typeCats['vocab'] += $n;
    } else if ($v === '' || trim($v) === '' || $v === VOCAB_OTHER_SENTINEL
               || preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $v)) {
        $typeCats['junk'] += $n;
        $junkTypeSamples[] = "'$v' ($n row" . ($n === 1 ? '' : 's') . ')';
    } else {
        $typeCats['free_text'] += $n;
    }
}
echo '    display_sample_type: ' . $typeCats['vocab'] . ' vocab / '
   . $typeCats['free_text'] . ' free-text / ' . $typeCats['junk'] . " junk\n";

$purposeCats = array('vocab' => 0, 'free_text' => 0, 'junk' => 0);
$rows = $db->get_results(
    "SELECT display_sample_purpose, COUNT(*) AS n
       FROM strabosamples.samples
      WHERE display_sample_purpose IS NOT NULL
      GROUP BY display_sample_purpose"
);
$rows = $rows ?: array();
$junkPurposeSamples = array();
foreach ($rows as $r) {
    $v = (string)$r->display_sample_purpose;
    $n = (int)$r->n;
    if (isset($purposeVocab[$v])) {
        $purposeCats['vocab'] += $n;
    } else if ($v === '' || trim($v) === '' || $v === VOCAB_OTHER_SENTINEL
               || in_array(strtolower($v), $knownJunkPurposes, true)
               || preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $v)) {
        $purposeCats['junk'] += $n;
        $junkPurposeSamples[] = "'$v' ($n row" . ($n === 1 ? '' : 's') . ')';
    } else {
        $purposeCats['free_text'] += $n;
    }
}
echo '    display_sample_purpose: ' . $purposeCats['vocab'] . ' vocab / '
   . $purposeCats['free_text'] . ' free-text / ' . $purposeCats['junk'] . " junk\n";

if ($typeCats['junk'] === 0 && $purposeCats['junk'] === 0) {
    echo "    OK\n";
    $results['vocab_sanity'] = 'ok';
} else {
    echo "    FAIL — junk values present (run samplesdb/cleanup/null_garbage_spine.php).\n";
    foreach ($junkTypeSamples as $s)    echo "      type junk: $s\n";
    foreach ($junkPurposeSamples as $s) echo "      purpose junk: $s\n";
    $results['vocab_sanity'] = 'fail';
    $failures[] = 'vocab_sanity: ' . ($typeCats['junk'] + $purposeCats['junk']) . ' junk spine value(s)';
}

// ---------------------------------------------------------------------------
// Check 4 — sample/link cross-reconciliation
// ---------------------------------------------------------------------------

echo "\n[4/4] Sample/link cross-reconciliation ...\n";

$totalSamples       = (int)$db->get_var("SELECT COUNT(*) FROM strabosamples.samples");
$totalLinks         = (int)$db->get_var("SELECT COUNT(*) FROM strabosamples.sample_subsystem_links");
$samplesWithLink    = (int)$db->get_var(
    "SELECT COUNT(DISTINCT (sample_id, sample_userpkey))
       FROM strabosamples.sample_subsystem_links"
);
$samplesWithoutLink = (int)$db->get_var(
    "SELECT COUNT(*) FROM strabosamples.samples s
      WHERE NOT EXISTS (
            SELECT 1 FROM strabosamples.sample_subsystem_links l
             WHERE l.sample_id = s.id AND l.sample_userpkey = s.userpkey)"
);
$linkByDist = $db->get_results(
    "SELECT subsystem, COUNT(*) AS n
       FROM strabosamples.sample_subsystem_links
      GROUP BY subsystem ORDER BY subsystem"
) ?: array();

echo "    samples total:         $totalSamples\n";
echo "    samples with ≥1 link:  $samplesWithLink\n";
echo "    samples with 0 links:  $samplesWithoutLink (modal-only — OK if nonzero)\n";
echo "    links total:           $totalLinks\n";
foreach ($linkByDist as $r) {
    echo "      $r->subsystem links: $r->n\n";
}

$dupes = (int)$db->get_var(
    "SELECT COALESCE(SUM(c - 1), 0) FROM (
       SELECT COUNT(*) AS c
         FROM strabosamples.sample_subsystem_links
        GROUP BY sample_id, sample_userpkey, subsystem,
                 reference_id, reference_userpkey
       HAVING COUNT(*) > 1
     ) sub"
);
echo "    duplicate-tuple link rows: $dupes\n";

$reconcileFailures = array();
if ($samplesWithLink + $samplesWithoutLink !== $totalSamples) {
    $reconcileFailures[] = "samples accounting: $samplesWithLink + $samplesWithoutLink != $totalSamples";
}
if ($dupes !== 0) {
    $reconcileFailures[] = "$dupes duplicate-tuple link rows (unique constraint violated)";
}

if (empty($reconcileFailures)) {
    echo "    OK\n";
    $results['cross_reconciliation'] = 'ok';
} else {
    echo "    FAIL\n";
    foreach ($reconcileFailures as $f) echo "      $f\n";
    $results['cross_reconciliation'] = 'fail';
    $failures[] = 'cross_reconciliation: ' . implode(' / ', $reconcileFailures);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n==============================================================\n";
if (empty($failures)) {
    echo " VERIFY EXTENDED: PASS\n";
    foreach ($results as $k => $v) echo "   $k: $v\n";
    echo "==============================================================\n";
    exit(0);
}
echo " VERIFY EXTENDED: FAIL\n";
foreach ($failures as $f) echo "   - $f\n";
echo "==============================================================\n";
exit(1);
