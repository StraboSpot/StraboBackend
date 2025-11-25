<?php

/*
******************************************************************
StraboSpot REST API - JWT Logout
Author: Jason Ash (jasonash@ku.edu)
Description: This codebase allows end-users to communicate with
			 the StraboSpot Database.
******************************************************************
*/

/*
{
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdHJhYm9zcG90Lm9yZyIsImF1ZCI6InN0cmFib3Nwb3QiLCJpYXQiOjE3NjQwOTkyOTYsImV4cCI6MTc2NDEwMjg5Niwic3ViIjoiMyIsImVtYWlsIjoiamFzb25hc2hAa3UuZWR1IiwibmFtZSI6Ikphc29uIEFzaCJ9.HsR_TXREF5c6rykNn4wdUM6EC67Tk_NoeDr-lHTsJtk",
  "refresh_token": "12a40aad13798b90e76edeb033be295cecbf0a785c645cf082d3b76e76a5d957",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "pkey": "3",
    "email": "jasonash@ku.edu",
    "name": "Jason Ash"
  }
}
*/

//Initialize Databases
include_once "../includes/config.inc.php";
include_once "../db.php";

include_once '../jwtauth/middleware.php';

//Authenticate via JWT - Todo Class this out for better workflow.
$user = authenticate();

/*
Array
(
    [iss] => strabospot.org
    [aud] => strabospot
    [iat] => 1764096250
    [exp] => 1764099850
    [sub] => 3
    [email] => jasonash@ku.edu
    [name] => Jason Ash
)
*/

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