<?php
/**
 * File: ownersearch.php
 * Description: Search interface for querying and filtering data
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();

//$_SESSION[userpkey] => 3;

if($_SESSION['userpkey']!=""){
	$userpkey = $_SESSION['userpkey'];
}else{
	$userpkey = 0;
}

include("../includes/config.inc.php");
include("../db.php");


$term = strtolower($_GET['term']);

$out = [];

if($term != ""){

	$rows = $db->get_results_prepared("
								select
								users.pkey,
								firstname,
								lastname
								from
								users, project
								where
								(project.ispublic = true or project.user_pkey = %d)
								and users.pkey = project.user_pkey
								and lower(lastname)||', '||lower(firstname) like %s
								and users.active=TRUE
								group by users.pkey, firstname, lastname order by lastname, firstname


							", [$userpkey, '%'.$term.'%']);

	$srows = $db->get_results_prepared("
								select
								pkey, firstname, lastname
								from
								users where lower(lastname)||', '||lower(firstname) like %s and active=TRUE order by lastname, firstname


							", ['%'.$term.'%']);



	foreach($rows as $row){
		$thisuser = [];
		$lastname = $row->lastname;
		$firstname = $row->firstname;
		$pkey = $row->pkey;
		$thisuser['id'] = $pkey;
		$thisuser['label'] = "$lastname, $firstname";
		$thisuser['value'] = "$lastname, $firstname";
		$out[] = $thisuser;
	}
}

header('Content-Type: application/json');
$out = json_encode($out, JSON_PRETTY_PRINT);
echo $out;





?>