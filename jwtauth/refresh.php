<?php
/*
******************************************************************
StraboSpot REST API - JWT Token Refresh
Author: Jason Ash (jasonash@ku.edu)
Description: This codebase allows end-users to communicate with
			 the StraboSpot Database.
******************************************************************
*/
include_once "../includes/config.inc.php";
include("../db.php");
include_once('../includes/jwt/quick-jwt.php');
$qjt = new QuickJWT();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['refresh_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_token', 'message' => 'Refresh token required']);
    exit;
}

$refreshToken = $input['refresh_token'];

$tokenData = (array) $db->get_row_prepared(
	"SELECT rt.*, u.pkey, u.email, u.firstname, u.lastname
    FROM refresh_tokens rt
    JOIN users u ON rt.userpkey = u.pkey
    WHERE rt.token = $1 AND rt.revoked = FALSE",
	array($refreshToken)
);

if (!$tokenData) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token', 'message' => 'Invalid refresh token']);
    exit;
}

// Check if token is expired
if (strtotime($tokenData['expires_at']) < time()) {
    // Clean up expired token
    $db->prepare_query("DELETE FROM refresh_tokens WHERE token = $1", array($refreshToken));

    http_response_code(401);
    echo json_encode(['error' => 'token_expired', 'message' => 'Refresh token has expired']);
    exit;
}

// Token is valid! Generate new access token
$issuedAt = time();
$expirationTime = $issuedAt + ACCESS_TOKEN_EXPIRY;

$payload = [
    'iss' => JWT_ISSUER,
    'aud' => JWT_AUDIENCE,
    'iat' => $issuedAt,
    'exp' => $expirationTime,
    'sub' => (string)$tokenData['userpkey'],
    'email' => $tokenData['email'],
    'name' => $tokenData['firstname']." ".$tokenData['lastname'],
];

$newAccessToken = $qjt->sign($payload, JWT_SECRET);

// Update last_used_at timestamp
$db->prepare_query("UPDATE refresh_tokens SET last_used_at = NOW() WHERE token = $1", array($refreshToken));

// Return new access token
http_response_code(200);
echo json_encode([
    'access_token' => $newAccessToken,
    'token_type' => 'Bearer',
    'expires_in' => ACCESS_TOKEN_EXPIRY,
]);
?>