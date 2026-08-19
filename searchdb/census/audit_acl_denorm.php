<?php
/**
 * File: audit_acl_denorm.php
 * Description: READ-ONLY audit that the ACL columns denormalized onto every
 *              strabosearch index row (project_ispublic, project_userpkey)
 *              match the source-of-truth public/private flags in each
 *              subsystem's home store. These two columns are the entire
 *              search-time access-control input (§5.5: project_ispublic OR
 *              project_userpkey = searcher, + collaborators EXISTS), so any
 *              drift here is a direct ACL hole (private data searchable) or
 *              a silent result gap (public data hidden).
 *
 *              Sources compared:
 *                field  — public.project.ispublic by (strabo_project_id, user_pkey)
 *                micro  — micro_projectmetadata.ispublic by (strabo_id, userpkey)
 *                exp    — straboexp.project.ispublic by (uuid, userpkey)
 *              Samples fan-out rows carry their HOST project's subsystem tag
 *              and ids, so the three joins above cover them too.
 *
 *              NULL source flags count as private (extractors emit false).
 *              Index rows whose source project no longer exists are reported
 *              separately (stale-row problem, not an ACL-flag problem).
 *
 *              Usage:
 *                docker exec strabo-php php /srv/app/www/searchdb/census/audit_acl_denorm.php
 *              Exit 0 = no mismatches; exit 1 = drift found.
 */

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once __DIR__ . '/_census_lib.php';

$mismatchTotal = 0;

function audit($db, $table, $subsystem, $joinSql, &$mismatchTotal) {
	$total = (int)$db->get_var(
		"SELECT count(*) FROM strabosearch.$table WHERE project_subsystem = '$subsystem'");

	$row = $db->get_row("
		SELECT count(*) FILTER (WHERE src.src_pkey IS NOT NULL
		                          AND h.project_ispublic IS DISTINCT FROM coalesce(src.src_public, false)) AS mismatched,
		       count(*) FILTER (WHERE src.src_pkey IS NULL) AS missing_source
		  FROM strabosearch.$table h
		  LEFT JOIN LATERAL ($joinSql) src ON TRUE
		 WHERE h.project_subsystem = '$subsystem'");

	// A failed query must read as audit failure, never as a clean zero.
	if (!$row || !isset($row->mismatched)) {
		line(sprintf("  %-22s %9d rows   QUERY FAILED — treat as drift", "$table/$subsystem:", $total));
		$mismatchTotal += 1;
		return;
	}

	$mismatched = (int)$row->mismatched;
	$missing = (int)$row->missing_source;
	line(sprintf("  %-22s %9d rows   %6d ACL MISMATCH   %6d missing-source",
		"$table/$subsystem:", $total, $mismatched, $missing));
	$mismatchTotal += $mismatched;
}

section('StraboSearch ACL denorm audit (project_ispublic vs source of truth)');

$joins = array(
	'field' => "SELECT p.project_pkey AS src_pkey, p.ispublic AS src_public
	              FROM public.project p
	             WHERE p.strabo_project_id = h.project_id
	               AND p.user_pkey = h.project_userpkey",
	'micro' => "SELECT m.id AS src_pkey, m.ispublic AS src_public
	              FROM strabomicro.micro_projectmetadata m
	             WHERE m.strabo_id = h.project_id
	               AND m.userpkey = h.project_userpkey",
	'exp'   => "SELECT e.pkey AS src_pkey, e.ispublic AS src_public
	              FROM straboexp.project e
	             WHERE e.uuid = h.project_id
	               AND e.userpkey = h.project_userpkey",
);

line();
line('item_hit:');
foreach ($joins as $subsystem => $joinSql) {
	audit($db, 'item_hit', $subsystem, $joinSql, $mismatchTotal);
}

line();
line('image_hit:');
foreach (array('field', 'micro') as $subsystem) {
	audit($db, 'image_hit', $subsystem, $joins[$subsystem], $mismatchTotal);
}

line();
if ($mismatchTotal === 0) {
	line('RESULT: NO ACL DRIFT — all index rows match their source ispublic flag.');
	exit(0);
}
line("RESULT: $mismatchTotal ROW(S) WITH ACL DRIFT — re-extract the affected slices.");
exit(1);
