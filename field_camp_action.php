<?php
/**
 * File: field_camp_action.php
 * Description: Field Camp Wizard, every change (POST only, then a redirect back
 *              to the page that posted): create a camp, save Basics, save the
 *              study area, create accounts, deactivate / reactivate / remove
 *              them, add / remove instructors. Each request needs the form
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

}elseif($action === 'viewer_add'){

	$problem = FieldCamp::addViewer($db, $camp, (string)($_POST['email'] ?? ''));
	if($problem !== '') fc_back($here . "accounts", '', $problem, array('email' => (string)($_POST['email'] ?? '')));
	fc_back($here . "accounts", "Instructor added.");

}elseif($action === 'viewer_remove'){

	FieldCamp::removeViewer($db, $camp, (int)($_POST['viewer'] ?? 0));
	fc_back($here . "accounts", "Instructor removed.");

}

fc_back("/field_camp_wizard");
