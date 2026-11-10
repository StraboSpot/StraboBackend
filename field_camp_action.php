<?php
/**
 * File: field_camp_action.php
 * Description: Field Camp Wizard, every change (POST only, then a redirect back
 *              to the page that posted): create a camp, save Basics, save the
 *              study area, look up the rock units of the study area at
 *              Macrostrat, save which units become tags, create accounts, deactivate / reactivate / remove
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

if(!FieldCamp::canUse($db, $userpkey)){
	header("HTTP/1.0 404 Not Found");
	exit("Not found.");
}

function fc_back($url, $ok = '', $err = array(), $form = array()){
	$_SESSION['fieldcamp_flash'] = array('ok' => $ok, 'err' => (array)$err, 'form' => $form);
	header("Location: $url");
	exit();
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
	fc_back($here . "basics", "Saved.");

}elseif($action === 'area'){

	list($v, $errors) = FieldCamp::validateArea($_POST);
	if($errors) fc_back($here . "area", '', $errors);
	FieldCamp::saveArea($db, $id, $v);
	fc_back($here . "area", "Study area saved.");

}elseif($action === 'units_fetch' || $action === 'units_save'){

	if(!FieldCampUnits::installed($db)) fc_back($here . "basics");

	if($action === 'units_fetch'){
		$bbox = FieldCampUnits::campBbox($camp);
		if($bbox === null) fc_back($here . "units", '', "Choose the study area first.");
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
	fc_back($here . "units", "Saved. $n rock unit" . ($n === 1 ? '' : 's') . " will become "
		. ($n === 1 ? 'a geologic unit tag' : 'geologic unit tags') . " in the project.");

}elseif($action === 'accounts_create'){

	$template = (string)($_POST['username_template'] ?? '');
	$count = (int)($_POST['count'] ?? 0);
	$password = (string)($_POST['password'] ?? '');
	$keep = array('username_template' => $template, 'count' => (string)($_POST['count'] ?? ''));

	// One password per camp: accounts added later must use the one already in place.
	$existing = FieldCamp::listAccounts($db, $id);
	if($existing){
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
	$n = FieldCamp::removeAccounts($db, $neodb, $camp);
	fc_back($here . "accounts", "$n account" . ($n === 1 ? '' : 's') . " removed.");

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
