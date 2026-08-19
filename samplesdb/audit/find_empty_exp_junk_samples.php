<?php
/**
 * File: find_empty_exp_junk_samples.php
 * Description: READ-ONLY detector for junk samples created by the
 *              empty-sample-section bug (fixed 2026-07-31, develop @
 *              d015793): saving a Vue experiment with NO sample metadata
 *              entered minted a strabo_id into the empty sample object and
 *              materialized an all-empty straboexp.sample row plus a
 *              nameless strabosamples spine row (bare UUID in My Samples).
 *
 *              Bug window: 2026-07-29 (create-path strabo_id pre-mint
 *              shipped) → the fix's deploy. Pre-cutover saves made junk
 *              straboexp.sample rows only; post-cutover saves also made
 *              spine junk.
 *
 *              A hit = an experiment whose stored JSON sample object holds
 *              no keys besides strabo_id, but which still has a spine
 *              sample with no name. HEAL each hit by re-saving the
 *              experiment in the Vue UI (a no-op "Save Changes" on the
 *              fixed code deletes the empty rows, removes the spine entry,
 *              and strips the stale strabo_id from the JSON).
 *
 *              Usage:
 *                docker exec strabo-php php \
 *                  /srv/app/www/samplesdb/audit/find_empty_exp_junk_samples.php
 *                (on prod: php /path/to/www/samplesdb/audit/... as usual)
 */

require_once __DIR__ . '/../../includes/config.inc.php';
require_once __DIR__ . '/../../db.php';

$rows = $db->get_results("
    SELECT e.pkey AS experiment_pkey,
           e.id AS experiment_id,
           e.userpkey,
           u.email,
           s.id AS spine_id,
           e.modified_timestamp
      FROM straboexp.experiment e
      JOIN strabosamples.sample_subsystem_links l
        ON l.subsystem = 'experimental'
       AND l.reference_id = e.pkey::text
      JOIN strabosamples.samples s
        ON s.id = l.sample_id AND s.userpkey = l.sample_userpkey
      LEFT JOIN users u ON u.pkey = e.userpkey
     WHERE s.name IS NULL
       AND jsonb_typeof(e.json::jsonb -> 'sample') = 'object'
       AND (SELECT count(*)
              FROM jsonb_object_keys(e.json::jsonb -> 'sample') k
             WHERE k <> 'strabo_id') = 0
     ORDER BY e.modified_timestamp DESC
");

// Also catch pre-cutover junk: empty straboexp.sample rows whose experiment
// JSON carries no sample content (no spine row required).
$orphanRows = $db->get_results("
    SELECT sm.pkey AS sample_pkey,
           sm.experiment_pkey,
           sm.strabo_id,
           e.id AS experiment_id,
           e.userpkey
      FROM straboexp.sample sm
      JOIN straboexp.experiment e ON e.pkey = sm.experiment_pkey
     WHERE COALESCE(sm.name, '') = '' AND COALESCE(sm.id, '') = ''
       AND COALESCE(sm.igsn, '') = '' AND COALESCE(sm.description, '') = ''
       AND COALESCE(sm.material_type, '') = '' AND COALESCE(sm.material_name, '') = ''
       AND jsonb_typeof(e.json::jsonb -> 'sample') = 'object'
       AND (SELECT count(*)
              FROM jsonb_object_keys(e.json::jsonb -> 'sample') k
             WHERE k <> 'strabo_id') = 0
       AND NOT EXISTS (SELECT 1 FROM straboexp.sample_composition c WHERE c.sample_pkey = sm.pkey)
       AND NOT EXISTS (SELECT 1 FROM straboexp.sample_parameter  p WHERE p.sample_pkey = sm.pkey)
       AND NOT EXISTS (SELECT 1 FROM straboexp.document          d WHERE d.sample_pkey = sm.pkey)
");

$n = is_array($rows) ? count($rows) : 0;
echo "== junk spine samples (nameless, experiment sample JSON empty): $n ==\n";
if ($n) {
    foreach ($rows as $r) {
        echo "  exp pkey={$r->experiment_pkey}  id=\"{$r->experiment_id}\"  user={$r->userpkey} ({$r->email})  spine={$r->spine_id}  modified={$r->modified_timestamp}\n";
    }
}

$m = is_array($orphanRows) ? count($orphanRows) : 0;
echo "== empty straboexp.sample rows w/ contentless experiment JSON: $m ==\n";
if ($m) {
    foreach ($orphanRows as $r) {
        echo "  sample pkey={$r->sample_pkey}  exp pkey={$r->experiment_pkey}  id=\"{$r->experiment_id}\"  user={$r->userpkey}  strabo_id={$r->strabo_id}\n";
    }
}

if ($n + $m === 0) {
    echo "\nCLEAN — no junk samples from the empty-sample bug.\n";
    exit(0);
}
echo "\nHEAL: re-save each listed experiment in the Vue UI (no-op Save Changes on the\n"
   . "fixed code removes the junk rows + spine entry and cleans the stored JSON).\n";
exit(1);
