<?php
/*
******************************************************************
StraboSpot REST API - JWT Logout
Author: Jason Ash (jasonash@ku.edu)
Description: This codebase allows end-users to communicate with
			 the StraboSpot Database.
******************************************************************
*/

//Initialize Databases
include_once "../includes/config.inc.php";
include_once "../db.php";

include_once '../jwtauth/middleware.php';

//Authenticate via JWT - Todo Class this out for better workflow.
$user = authenticate();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// Verify JWT (user must be authenticated)
$user = verifyJWT();

$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['refresh_token'])) {
    // Revoke specific refresh token
	$db->prepare_query("UPDATE refresh_tokens SET revoked = TRUE WHERE token = $1 AND userpkey = $2;", array($input['refresh_token'], $user['sub']));
} else {
    // Revoke all refresh tokens for this user (logout all devices)
    $db->prepare_query("UPDATE refresh_tokens SET revoked = TRUE WHERE userpkey = $1;", array($user['sub']));
}

http_response_code(200);
echo json_encode(['message' => 'Successfully logged out']);
?>