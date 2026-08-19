<?php
/**
 * File: update_keywords.php
 * Description: Populates the tsvector keywords column on straboexp.project
 *              for full-text search across the Strabo search interfaces.
 *
 * Requires: $db (PostgreSQL connection from prepare_connections.php)
 *
 * Usage:
 *   include_once("experimental/api/update_keywords.php");
 *   updateExpProjectKeywords($db, $project_pkey);
 */

/**
 * Recursively extract non-numeric, non-boolean string values from a decoded JSON structure.
 * Mirrors the legacy StraboExp::getKeywords() logic.
 */
function extractExpKeywords($var) {
    $out = '';
    $excluded = ['id', 'date', 'strat_section_id', 'modified_timestamp', 'self', 'time', 'image_type'];

    if (is_object($var)) {
        foreach ($var as $key => $value) {
            if (!in_array($key, $excluded) && $value !== '') {
                $out .= '*****' . extractExpKeywords($value);
            }
        }
    } elseif (is_array($var)) {
        if (array_keys($var) !== range(0, count($var) - 1)) {
            // Associative array
            foreach ($var as $key => $value) {
                if (!in_array($key, $excluded) && $value !== '') {
                    $out .= '*****' . extractExpKeywords($value);
                }
            }
        } else {
            // Indexed array
            foreach ($var as $v) {
                if ($v !== '') {
                    $out .= '*****' . extractExpKeywords($v);
                }
            }
        }
    } else {
        if (!is_numeric($var) && !is_bool($var) && $var !== '') {
            $out .= '*****' . $var;
        }
    }

    return $out;
}

/**
 * Regenerate the keywords tsvector for an experimental project.
 * Collects text from the project name, notes, owner name, and all experiment JSON.
 */
function updateExpProjectKeywords($db, $project_pkey) {
    $project_pkey = (int)$project_pkey;

    $prow = $db->get_row("SELECT * FROM straboexp.project WHERE pkey = $project_pkey");
    if (empty($prow->pkey)) return;

    $keywords = '*****' . $prow->name . '*****' . $prow->notes;

    $userpkey = (int)$prow->userpkey;
    $urow = $db->get_row("SELECT firstname, lastname FROM users WHERE pkey = $userpkey");
    if ($urow) {
        $keywords .= '*****' . $urow->lastname . '*****' . $urow->firstname;
    }

    $exprows = $db->get_results("SELECT json FROM straboexp.experiment WHERE project_pkey = $project_pkey");
    if ($exprows) {
        foreach ($exprows as $exprow) {
            $json = json_decode($exprow->json);
            if ($json) {
                $keywords .= extractExpKeywords($json);
            }
        }
    }

    $parts = explode('*****', $keywords);
    $distparts = [];
    foreach ($parts as $p) {
        if ($p !== '' && !in_array($p, $distparts)) {
            $distparts[] = $p;
        }
    }

    $finalwords = pg_escape_string(implode(' ', $distparts));
    $db->query("UPDATE straboexp.project SET keywords = to_tsvector('$finalwords') WHERE pkey = $project_pkey;");
}
