<?php
/**
 * File: search_sync.php
 * Description: StraboSearch §5.3 live-sync hooks for the StraboMicro write
 *              paths. Thin wrappers around StraboSearchSync, mirroring the
 *              microdb/lib/sample_sync.php pattern (require_once at the
 *              call site, function_exists-guarded free functions — the
 *              jwtmicrodb class copy requires this SAME file via a
 *              relative path, so the wrappers exist exactly once).
 *
 * Micro's write model is whole-project replace (insertProject /
 * insertProjectWithoutFile → deleteProject + loadProjectJSON re-insert),
 * so the hook granularity is the project slice:
 *   - end of insertProject / insertProjectWithoutFile → rebuild the slice
 *   - top of deleteProject → drop the slice (placement matches the sample
 *     hook; identity comes from the arguments, not the doomed rows)
 * The insert flows call deleteProject FIRST — the drop-then-rebuild
 * sequence is naturally idempotent.
 *
 * ispublic togglers outside the class (micro_project_public.php, Field's
 * deleteUserAccount sweep) use the meta hooks — cheap denorm UPDATE, no
 * re-extract.
 *
 * @package    StraboMicro
 * @copyright  2026 StraboSpot
 */

require_once __DIR__ . '/../../searchdb/sync/StraboSearchSync.php';

if (!function_exists('micro_search_sync_project')) {

function micro_search_sync_project($db, $projectMetadataId, $straboProjectId, $userpkey) {
	return StraboSearchSync::syncMicroProject($db, $projectMetadataId, $straboProjectId, $userpkey);
}

function micro_search_sync_remove_project($db, $straboProjectId, $userpkey) {
	return StraboSearchSync::removeMicroProject($db, $straboProjectId, $userpkey);
}

function micro_search_sync_ispublic($db, $straboProjectId, $userpkey, $ispublic) {
	return StraboSearchSync::touchProjectMeta($db, 'micro', $straboProjectId, (int)$userpkey,
		null, (bool)$ispublic);
}

/** deleteUserAccount sweep: every micro project of the user goes private. */
function micro_search_sync_user_all_private($db, $userpkey) {
	return StraboSearchSync::touchProjectMeta($db, 'micro', null, (int)$userpkey,
		null, false);
}

}
