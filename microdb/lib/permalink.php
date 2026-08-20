<?php
/**
 * File: permalink.php
 * Description: Upload-stable landing-page slugs for StraboMicro projects.
 *
 * Every StraboMicro upload deletes the old micro_projectmetadata row and
 * mints a fresh serial id, so pkey-based landing URLs (?p= / ?id=) die on
 * re-upload. A permalink slug maps to the project's stable identity
 * (strabo_id, userpkey) in micro_permalinks and is resolved to the CURRENT
 * metadata row on every request. Slugs are minted lazily (get-or-create)
 * wherever a landing link is rendered or visited; the mapping row is never
 * touched by the upload/delete cycle.
 *
 * Access control stays with the callers: resolution here is identity only,
 * and every landing surface keeps its (ispublic OR owner) gate. The slug is
 * deliberately NOT the sharekey: sharekey is a bearer capability for
 * downloading the project file via the desktop app and has its own
 * lifecycle; the permalink grants nothing beyond a name.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Same unambiguous alphabet as StraboMicro::getRandString (no i/l/o/0/1/5),
// but 8 chars instead of 6: these appear in public URLs, so make blind
// enumeration pointless. 29^8 is roughly 500 billion.
function micro_permalink_rand_slug() {
	$chars = array('a','b','c','d','e','f','g','h','j','k','m','n','p','q','r','t','u','v','w','x','y','z','2','3','4','6','7','8','9');
	$slug = '';
	for ($x = 0; $x < 8; $x++) {
		$slug .= $chars[mt_rand(0, count($chars) - 1)];
	}
	return $slug;
}

// Read-only lookup: slug for an existing mapping, or null.
function micro_permalink_lookup($db, $strabo_id, $userpkey) {
	$slug = $db->get_var_prepared(
		"SELECT permakey FROM micro_permalinks WHERE strabo_id = $1 AND userpkey = $2",
		array($strabo_id, (int)$userpkey));
	return ($slug != "") ? $slug : null;
}

// Get-or-create the slug for a project identity. Returns null only on
// repeated slug collisions or DB failure -- callers fall back to legacy
// pkey URLs, never break the page.
// NOTE: micro_permalinks has no serial column, so the INSERT must go
// through prepare_query (the wrapper's query() chases every INSERT with a
// bare SELECT lastval(), which aborts inside a transaction on tables
// without a sequence).
function micro_permalink_get_or_create($db, $strabo_id, $userpkey) {
	$strabo_id = (string)$strabo_id;
	$userpkey = (int)$userpkey;
	if ($strabo_id === '' || $userpkey <= 0) return null;

	$slug = micro_permalink_lookup($db, $strabo_id, $userpkey);
	if ($slug !== null) return $slug;

	for ($attempt = 0; $attempt < 5; $attempt++) {
		$candidate = micro_permalink_rand_slug();
		// ON CONFLICT on the identity pair makes concurrent minting safe:
		// whoever loses the race re-reads the winner's slug below.
		$db->prepare_query(
			"INSERT INTO micro_permalinks (permakey, strabo_id, userpkey)
			 VALUES ($1, $2, $3)
			 ON CONFLICT (strabo_id, userpkey) DO NOTHING",
			array($candidate, $strabo_id, $userpkey));

		$slug = micro_permalink_lookup($db, $strabo_id, $userpkey);
		if ($slug !== null) return $slug;
		// null here means the INSERT itself failed (permakey collision or
		// error); try a fresh candidate.
	}
	return null;
}

// Resolve a slug to the project's CURRENT micro_projectmetadata row, or
// null when the slug is unknown or the project has no live row right now
// (deleted, or mid re-upload). NO access check here -- callers must apply
// their own (ispublic OR owner) gate exactly as they do for pkey lookups.
function micro_permalink_resolve($db, $permakey) {
	$permakey = (string)$permakey;
	if ($permakey === '' || !preg_match('/^[a-z0-9]{1,20}$/', $permakey)) return null;

	$row = $db->get_row_prepared(
		"SELECT m.* FROM micro_permalinks pl
		 JOIN micro_projectmetadata m
		   ON m.strabo_id = pl.strabo_id AND m.userpkey = pl.userpkey
		 WHERE pl.permakey = $1",
		array($permakey));
	return ($row && $row->id != "") ? $row : null;
}

// Canonical shareable URL for a project row (tier-agnostic front door).
// Returns the permalink form when a slug can be minted, else the legacy
// pkey form so link generators degrade gracefully.
function micro_permalink_landing_url($db, $project_row) {
	$slug = micro_permalink_get_or_create($db, $project_row->strabo_id, (int)$project_row->userpkey);
	if ($slug !== null) return "/microproject?m=" . $slug;
	return "/microproject?id=" . (int)$project_row->id;
}
