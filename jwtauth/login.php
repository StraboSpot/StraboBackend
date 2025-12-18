<?php
/**
 * File: login.php
 * Description: User login and authentication via JWT
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once "../includes/config.inc.php";
include("../db.php");
include_once('../includes/jwt/quick-jwt.php');
$qjt = new QuickJWT();

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed', 'message' => 'Only POST requests allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['email']) || !isset($input['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_fields', 'message' => 'Email and password required']);
    exit;
}

$email = trim($input['email']);
$password = $input['password'];

// Validate credentials using prepared statement
$row = $db->get_row_prepared(
	"SELECT * FROM users WHERE email = $1 AND crypt($2, password) = password AND active = TRUE AND deleted = FALSE",
	array($email, $password)
);


if (!$row->pkey) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_credentials', 'message' => 'Invalid email or password']);
    exit;
}

// User authenticated successfully!

// 1. Generate JWT Access Token
$issuedAt = time();
$expirationTime = $issuedAt + ACCESS_TOKEN_EXPIRY;

$payload = [
    'iss' => JWT_ISSUER,           // Issuer
    'aud' => JWT_AUDIENCE,         // Audience
    'iat' => $issuedAt,            // Issued at
    'exp' => $expirationTime,      // Expiration time (THIS IS KEY!)
    'sub' => (string)$row->pkey,  // Subject (user ID)
    'email' => $row->email,
    'name' => $row->firstname." ".$row->lastname,
];

$accessToken = $qjt->sign($payload, JWT_SECRET);

// 2. Generate Refresh Token (random string, not JWT)
$refreshToken = bin2hex(random_bytes(32)); // 64-character random string

// 3. Store refresh token in database
$refreshExpiresAt = date('Y-m-d H:i:s', time() + REFRESH_TOKEN_EXPIRY);
$ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$db->prepare_query("INSERT INTO refresh_tokens (token, userpkey, expires_at, ip_address, user_agent)
    VALUES ($1, $2, $3, $4, $5);",array($refreshToken, $row->pkey, $refreshExpiresAt, $ipAddress, $userAgent));


/*
$db->query("INSERT INTO refresh_tokens (token, userpkey, expires_at, ip_address, user_agent)
    VALUES ('$refreshToken', $row->pkey, '$refreshExpiresAt', '$ipAddress', 'user_agent');");
*/

// 4. Return tokens to client
http_response_code(200);
echo json_encode([
    'access_token' => $accessToken,
    'refresh_token' => $refreshToken,
    'token_type' => 'Bearer',
    'expires_in' => ACCESS_TOKEN_EXPIRY, // Seconds until expiration
    'user' => [
        'pkey' => $row->pkey,
        'email' => $row->email,
        'name' => $row->firstname." ".$row->lastname,
    ],
]);
?>