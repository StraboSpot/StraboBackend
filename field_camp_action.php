<?php
/**
 * File: field_camp_action.php
 * Description: Field Camp Wizard, every change (POST only, then a redirect back
 *              to the page that posted): create a camp, save Basics, save the
 *              study area, look up the rock units of the study area at
 *              Macrostrat, save which units become tags, save which My Maps
 *              become custom maps, save the offline map choice and start the
 *              tile build, create accounts, deactivate / reactivate / remove
 *              them, copy a deactivated account's projects to the camp owner
 *              (answers JSON), add / remove instructors. Each request needs the form
 *              token, an account on the instructor list, and (except create) a
 *              camp that account may manage.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . '/includes/fieldcamp/FieldCamp.php';
require_once __DIR__ . '/includes/fieldcamp/FieldCampUnits.php';
require_once __DIR__ . '/includes/fieldcamp/FieldCampPackage.php';
require_once __DIR__ . '/includes/fieldcamp/FieldCampTiles.php';

if(!FieldCamp::canUse($db, $userpkey)){
	header("HTTP/1.0 404 Not Found");
	exit("Not found.");
}

function fc_back($url, $ok = '', $err = array(), $form = array()){
	$_SESSION['fieldcamp_flash'] = array('ok' => $ok, 'err' => (array)$err, 'form' => $form);
	header("Location: $url");
	exit();
}

/**
 * After a successful save: open the step the page asked for (its Next button, or a step tab clicked with
 * unsaved changes), else go back to the step that posted.
 */
function fc_saved($here, $step, $ok = ''){
	$goto = (string)($_POST['goto'] ?? '');
	if(!in_array($goto, array('basics', 'area', 'maps', 'tiles', 'units', 'accounts', 'package', 'roster'), true)) $goto = $step;
	// What was saved is said only when the page stays; on the next step it would read as that step's news.
	fc_back($here . $goto, $goto === $step ? $ok : '');
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
	header("Location: /field_camp_wizard");
	exit();
}
if(!FieldCamp::tokenOk($_POST['csrf'] ?? null)){
	fc_back("/field_camp_wizard", '', "The page had expired. Please try again.");
}

$action = (string)($_POST['action'] ?? '');

// ---------------------------------------------------------------------------
// Create a camp
// ---------------------------------------------------------------------------
if($action === 'create'){
	list($clean, $errors) = FieldCamp::validateBasics($_POST);
	if($errors){
		fc_back("/field_camp?new=1", '', $errors, array_intersect_key($_POST,
			array_flip(array('camp_name', 'camp_year', 'start_date', 'end_date', 'project_name'))));
	}
	$pkey = FieldCamp::createCamp($db, $userpkey, $clean);
	fc_back("/field_camp?id=$pkey&step=area", "Field camp created. Next, choose the study area.");
}

// ---------------------------------------------------------------------------
// Bring a deleted camp back (admin only; a deleted camp is "not there" for everything below)
// ---------------------------------------------------------------------------
if($action === 'camp_restore'){
	if(!FieldCamp::isAdmin($userpkey)){
		header("HTTP/1.0 404 Not Found");
		exit("Not found.");
	}
	$n = FieldCamp::restoreCamp($db, (int)($_POST['id'] ?? 0));
	if($n === null) fc_back("/field_camp_wizard", '', "That field camp is not among the deleted ones.");
	fc_back("/field_camp_wizard", "Field camp restored" . ($n > 0 ? ", with $n account" . ($n === 1 ? '' : 's') . " (deactivated; reactivate them on the Accounts step)" : '')
		. ". Its offline maps have to be built again.");
}

// ---------------------------------------------------------------------------
// Everything else works on one camp
// ---------------------------------------------------------------------------
$camp = FieldCamp::getCamp($db, (int)($_POST['id'] ?? 0));
if(!$camp || !FieldCamp::canManage($camp, $userpkey)){
	header("HTTP/1.0 404 Not Found");
	exit("Not found.");
}
$id = (int)$camp->pkey;
$here = "/field_camp?id=$id&step=";

if($action === 'basics'){

	list($clean, $errors) = FieldCamp::validateBasics($_POST);
	if($errors){
		fc_back($here . "basics", '', $errors, array_intersect_key($_POST,
			array_flip(array('camp_name', 'camp_year', 'start_date', 'end_date', 'project_name'))));
	}
	FieldCamp::updateBasics($db, $id, $clean);
	fc_saved($here, "basics", "Saved.");

}elseif($action === 'area'){

	list($v, $errors) = FieldCamp::validateArea($_POST);
	if($errors) fc_back($here . "area", '', $errors);
	FieldCamp::saveArea($db, $id, $v);
	fc_saved($here, "area", "Study area saved.");

}elseif($action === 'maps_save'){

	if(!FieldCampPackage::installed($db)) fc_back($here . "basics");
	$rows = json_decode((string)($_POST['maps_json'] ?? ''), true);
	if(!is_array($rows)) fc_back($here . "maps", '', "The maps could not be read. Please try again.");
	$errors = FieldCampPackage::saveMaps($db, $camp, $rows);
	if($errors) fc_back($here . "maps", '', $errors, array('maps' => $rows));
	$n = count(FieldCampPackage::listCampMaps($db, $id));
	fc_saved($here, "maps", $n === 0 ? "Saved. No custom maps are in the project."
		: "Saved. $n custom map" . ($n === 1 ? ' is' : 's are') . " in the project.");

}elseif($action === 'tiles_build' || $action === 'tiles_save'){

	// tiles_save is what the step's Next button and the step tabs send (2026-11-10: no separate "build"):
	// it keeps the choice of maps and zoom and starts the build when the built tiles do not match it.
	// offline=0 switches the offline maps off and removes what was built. tiles_build always builds.
	if(!FieldCampPackage::installed($db) || !FieldCampTiles::installed($db)) fc_back($here . "basics");
	if(FieldCampTiles::isBuilding($camp)){
		if($action === 'tiles_save') fc_saved($here, "tiles");   // the running build keeps its own choice
		fc_back($here . "tiles", '', "The tiles for this camp are being built already.");
	}
	$layers = FieldCampTiles::layers(FieldCampPackage::customMapsForCamp($db, $camp));
	if((string)($_POST['offline'] ?? '1') === '0'){
		FieldCampTiles::saveChoice($db, $camp, (int)$camp->tiles_zoom, array());
		FieldCampTiles::clearBuild($db, $camp);
		fc_saved($here, "tiles", "Saved. The package will have no offline maps.");
	}
	$wanted = json_decode((string)($_POST['layers_json'] ?? ''), true);
	list($zoom, $ids, $errors) = FieldCampTiles::validateChoice($camp, $layers, (int)($_POST['zoom'] ?? 0), is_array($wanted) ? $wanted : array());
	if($errors) fc_back($here . "tiles", '', $errors);
	FieldCampTiles::saveChoice($db, $camp, $zoom, $ids);
	$camp = FieldCamp::getCamp($db, $id);
	if($action === 'tiles_save' && !FieldCampTiles::needsBuild($camp, $layers)) fc_saved($here, "tiles", "Saved. The offline maps are built and up to date.");
	$problem = FieldCampTiles::startBuild($db, $camp);
	if($problem !== '') fc_back($here . "tiles", '', $problem);
	if($action === 'tiles_save') fc_saved($here, "tiles");   // the progress panel on every step says the build is running
	fc_back($here . "tiles", "The build has started.");

}elseif($action === 'units_fetch' || $action === 'units_save'){

	if(!FieldCampUnits::installed($db)) fc_back($here . "basics");

	if($action === 'units_fetch'){
		$bbox = FieldCampUnits::campBbox($camp);
		if($bbox === null) fc_back($here . "units", '', "Choose the study area first.");
		// The page sends its list along: keep what was selected and typed there before the new lookup.
		$typed = json_decode((string)($_POST['units_json'] ?? ''), true);
		if(is_array($typed) && $typed) FieldCampUnits::saveSelection($db, $camp, $typed);
		$lookup = FieldCampUnits::lookup($bbox);
		if($lookup['error'] !== '') fc_back($here . "units", '', $lookup['error']);
		$saved = FieldCampUnits::save($db, $camp, $lookup);
		if($saved['error'] !== '') fc_back($here . "units", '', $saved['error']);
		$n = $saved['total']; $m = count($lookup['sources']);
		if($n === 0) fc_back($here . "units", "Macrostrat has no mapped rock units in this study area.");
		fc_back($here . "units", "$n rock unit" . ($n === 1 ? '' : 's') . " found on $m source map" . ($m === 1 ? '' : 's') . "."
			. ($saved['removed'] > 0 ? " " . $saved['removed'] . " unit" . ($saved['removed'] === 1 ? '' : 's')
				. " from the earlier lookup " . ($saved['removed'] === 1 ? 'is' : 'are') . " no longer in the area and "
				. ($saved['removed'] === 1 ? 'was' : 'were') . " dropped." : '')
			. " Select the ones to turn into tags, then save.");
	}

	$rows = json_decode((string)($_POST['units_json'] ?? ''), true);
	if(!is_array($rows)) fc_back($here . "units", '', "The rock units could not be read. Please try again.");
	$errors = FieldCampUnits::saveSelection($db, $camp, $rows);
	if($errors) fc_back($here . "units", '', $errors, array('units' => $rows));
	$n = count(FieldCampUnits::listUnits($db, $id, true));
	fc_saved($here, "units", "Saved. $n rock unit" . ($n === 1 ? '' : 's') . " will become "
		. ($n === 1 ? 'a geologic unit tag' : 'geologic unit tags') . " in the project.");

}elseif($action === 'accounts_create'){

	$template = (string)($_POST['username_template'] ?? '');
	$count = (int)($_POST['count'] ?? 0);
	$password = (string)($_POST['password'] ?? '');
	$keep = array('username_template' => $template, 'count' => (string)($_POST['count'] ?? ''));

	// One password per camp: accounts added later get the one already in place. When it is on record
	// the instructor is not asked for it; otherwise it must be typed, and is proven against an account.
	$existing = FieldCamp::listAccounts($db, $id);
	$known = $existing ? FieldCamp::campPassword($camp) : null;
	if($known !== null){
		$password = $known;
	}elseif($existing){
		$match = (int)$db->get_var_prepared(
			"select count(*) from users where pkey = $1 and crypt($2, password) = password",
			array((int)$existing[0]->user_pkey, $password));
		if($match === 0){
			fc_back($here . "accounts", '', "That is not the password this camp's accounts use. "
				. "Enter the same password so every account of the camp matches.", $keep);
		}
	}

	$result = FieldCamp::createAccounts($db, $neodb, $camp, $template, $count, $password);
	if($result['error'] !== '') fc_back($here . "accounts", '', $result['error'], $keep);

	$n = count($result['created']);
	fc_back($here . "accounts", "$n account" . ($n === 1 ? '' : 's') . " created: "
		. $result['created'][0] . ($n > 1 ? " to " . $result['created'][$n - 1] : '') . ".");

}elseif($action === 'accounts_deactivate'){

	$n = FieldCamp::setAccountsActive($db, $camp, false);
	fc_back($here . "accounts", "$n account" . ($n === 1 ? '' : 's') . " deactivated.");

}elseif($action === 'accounts_reactivate'){

	$n = FieldCamp::setAccountsActive($db, $camp, true);
	fc_back($here . "accounts", "$n account" . ($n === 1 ? '' : 's') . " reactivated.");

}elseif($action === 'accounts_remove'){

	if(trim((string)($_POST['confirm'] ?? '')) !== trim((string)$camp->camp_name)){
		fc_back($here . "accounts", '', "The name you typed does not match this camp. No accounts were removed.");
	}
	// Work not copied to the owner blocks the removal unless the instructor says it is not needed.
	require_once __DIR__ . '/includes/fieldcamp/FieldCampCopy.php';
	$going = array();
	foreach(FieldCamp::listAccounts($db, $id) as $a){ if(!FieldCamp::truthy($a->active)) $going[] = $a; }
	$uncopied = 0;
	foreach((new FieldCampCopy($db, $neodb))->holdings($camp, $going) as $h){ if($h['uncopied'] > 0) $uncopied++; }
	if($uncopied > 0 && empty($_POST['nocopy'])){
		fc_back($here . "accounts", '', "$uncopied of these accounts hold" . ($uncopied === 1 ? 's' : '') . " work that has not been copied. Copy it first, or select \"I do not need this work\". No accounts were removed.");
	}
	$n = FieldCamp::removeAccounts($db, $neodb, $camp);
	fc_back($here . "accounts", "$n account" . ($n === 1 ? '' : 's') . " removed.");

}elseif($action === 'account_remove'){

	// One account, active or not. Typed username; uncopied work blocks unless overridden.
	$pk = (int)($_POST['user'] ?? 0);
	$account = null;
	foreach(FieldCamp::listAccounts($db, $id) as $a){ if((int)$a->user_pkey === $pk) $account = $a; }
	if(!$account) fc_back($here . "accounts", '', "That account is not one of this camp's accounts.");
	$back = $here . "accounts&remove=$pk#fcRemove";
	if(trim((string)($_POST['confirm'] ?? '')) !== (string)$account->username){
		fc_back($back, '', "The username you typed does not match. The account was not removed.");
	}
	require_once __DIR__ . '/includes/fieldcamp/FieldCampCopy.php';
	$h = (new FieldCampCopy($db, $neodb))->holdings($camp, array($account));
	if(($h[$pk]['uncopied'] ?? 0) > 0 && empty($_POST['nocopy'])){
		fc_back($back, '', "This account holds work that has not been copied. Copy it first, or select \"I do not need this work\". The account was not removed.");
	}
	FieldCamp::removeAccount($db, $neodb, $camp, $pk);
	fc_back($here . "accounts", "The account " . $account->username . " was removed.");

}elseif($action === 'account_restore'){

	if(!FieldCamp::isAdmin($userpkey)){
		header("HTTP/1.0 404 Not Found");
		exit("Not found.");
	}
	$ok = FieldCamp::restoreAccount($db, $camp, (int)($_POST['user'] ?? 0));
	fc_back($here . "accounts", $ok ? "Account restored. It is deactivated; reactivate it when it should sign in again." : '',
		$ok ? array() : "That account is not among the removed ones.");

}elseif($action === 'camp_delete'){

	$back = $here . "basics&delete=1#fcDelete";
	if(trim((string)($_POST['confirm'] ?? '')) !== trim((string)$camp->camp_name)){
		fc_back($back, '', "The name you typed does not match this camp. Nothing was deleted.");
	}
	require_once __DIR__ . '/includes/fieldcamp/FieldCampCopy.php';
	$uncopied = 0;
	foreach((new FieldCampCopy($db, $neodb))->holdings($camp, FieldCamp::listAccounts($db, $id)) as $h){ if($h['uncopied'] > 0) $uncopied++; }
	if($uncopied > 0 && empty($_POST['nocopy'])){
		fc_back($back, '', "$uncopied account" . ($uncopied === 1 ? ' holds' : 's hold') . " work that has not been copied. Copy it first on the Student work step, or select \"I do not need this work\". Nothing was deleted.");
	}
	$problem = FieldCamp::deleteCamp($db, $neodb, $camp, $userpkey);
	if($problem !== '') fc_back($back, '', $problem);
	fc_back("/field_camp_wizard", "The field camp \"" . $camp->camp_name . "\" was deleted.");

}elseif($action === 'copy_account'){

	// One account per request (the page calls this once per account), answered as JSON.
	require_once __DIR__ . '/includes/fieldcamp/FieldCampCopy.php';
	set_time_limit(0);
	header('Content-Type: application/json');
	$want = (int)($_POST['user'] ?? 0);
	$account = null;
	foreach(FieldCamp::listAccounts($db, $id) as $a){
		if((int)$a->user_pkey === $want) $account = $a;
	}
	if(!$account){
		echo json_encode(array('error' => "That account does not belong to this camp.", 'copied' => array(), 'skipped' => 0));
		exit();
	}
	$copier = new FieldCampCopy($db, $neodb);
	$result = $copier->copyAccount($camp, $account);
	// The loaders it reuses set the API's own status headers (201 Created); this page answers 200.
	http_response_code(200);
	header('Content-Type: application/json');
	echo json_encode($result);
	exit();

}elseif($action === 'viewer_add'){

	$problem = FieldCamp::addViewer($db, $camp, (string)($_POST['email'] ?? ''));
	if($problem !== '') fc_back($here . "accounts", '', $problem, array('email' => (string)($_POST['email'] ?? '')));
	fc_back($here . "accounts", "Instructor added.");

}elseif($action === 'viewer_remove'){

	FieldCamp::removeViewer($db, $camp, (int)($_POST['viewer'] ?? 0));
	fc_back($here . "accounts", "Instructor removed.");

}

fc_back("/field_camp_wizard");
