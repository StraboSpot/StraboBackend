<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }   // CLI only
// Spots the Template Wizard created with an INTEGER name / date / notes (PHP array-key
// coercion, fixed 2026-09-18). Walks every import run's minted ids from the journal.
// Dry run by default; --apply rewrites each integer property with toString().
// docker exec strabo-php php /srv/app/www/TemplateWizard/tools/repair_numeric_names.php [--apply]
chdir('/srv/app/www'); require 'includes/config.inc.php'; require 'db.php'; require 'neodb.php';
$apply = in_array('--apply', $argv);
$runs = $db->get_results("SELECT pkey, userpkey, rows::text AS rows FROM field_tabular_runs WHERE status = 'committed' ORDER BY pkey");
$found = 0; $fixed = 0; $checked = 0;
foreach ((array)$runs as $run) {
    $rows = json_decode($run->rows, true); if (!is_array($rows)) { continue; }
    $ids = array();
    foreach ($rows as $w) { if (isset($w['spot_id']) && $w['action'] === 'create') { $ids[] = (int)$w['spot_id']; } }
    if (!$ids) { continue; }
    $u = (int)$run->userpkey;
    foreach (array_chunk($ids, 200) as $chunk) {
        $recs = $neodb->get_results("MATCH (s:Spot {userpkey: $u}) WHERE s.id IN [" . implode(',', $chunk) . "] RETURN s.id AS id, s.name AS name, s.date AS date, s.notes AS notes");
        foreach ((array)$recs as $r) {
            $checked++;
            foreach (array('name', 'date', 'notes') as $k) {
                $v = $r->value($k);
                if (is_int($v) || is_float($v)) {
                    $found++;
                    echo "run {$run->pkey} user $u spot " . $r->value('id') . " $k=" . json_encode($v) . " (" . gettype($v) . ")\n";
                    if ($apply) {
                        $neodb->query("MATCH (s:Spot {id: " . (int)$r->value('id') . ", userpkey: $u}) SET s.$k = toString(s.$k)");
                        $fixed++;
                    }
                }
            }
        }
    }
}
echo "checked $checked wizard-created spots; integer-typed properties found: $found" . ($apply ? "; fixed: $fixed" : " (dry run; add --apply to rewrite)") . "\n";
