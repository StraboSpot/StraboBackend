<?php
/**
 * File: middleware.php
 * Description: JWT Auth Middleware for Concise Code
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
include_once "../includes/config.inc.php";
include_once "../db.php";
include_once "../includes/jwt/quick-jwt.php";
$qjt = new QuickJWT();

/**
 * Verify JWT token from Authorization header
 * Returns decoded token payload or sends 401 error and exits
 */
function verifyJWT() {
	
	global $qjt;
	global $db;
	
    // Get Authorization header
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'missing_token', 'message' => 'No authorization header provided']);
        exit;
    }

    // Check Bearer token format
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_format', 'message' => 'Invalid authorization header format']);
        exit;
    }

    $token = $matches[1];
    
    //Verify the token
    $isValidSigned = $qjt->validate(JWT_SECRET, $token);
    if($isValidSigned){
    	
    	$decoded = $qjt->decode($token);
    	
    	//Verify expiration
    	$expireTime = $decoded['exp'];
    	
    	if(time() > $expireTime){
    	
			// Token has expired
			http_response_code(401);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'token_expired', 'message' => 'Access token has expired']);
			exit;

    	}else{
    		
    		// Verify Audience
    		if($decoded['aud'] == JWT_AUDIENCE) {
    		
				// Verify User
				$userpkey = $decoded['sub'];
				$urow = $db->get_row_prepared("select * from users where pkey = $1", [$userpkey]);
				
				if(!$urow->pkey){
	
					// User Not Found
					http_response_code(401);
					header('Content-Type: application/json');
					echo json_encode(['error' => 'user_not_found', 'message' => 'User Not Found']);
					exit;
	
				}else{
				
					return $decoded;
				
				}
			}else{
			
				// Incorrect Audience
				http_response_code(401);
				header('Content-Type: application/json');
				echo json_encode(['error' => 'incorrect_audience', 'message' => 'Incorrect Audience']);
				exit;
			
			}
    		
    	}

    	exit();
    
    }else{
		// Invalid Token Signature
		http_response_code(401);
		header('Content-Type: application/json');
		echo json_encode(['error' => 'invalid_token_signature', 'message' => 'Invalid Token Signature']);
		exit;
    }

    exit();

}

/**
 * Unified auth: Try JWT first, fallback to Basic Auth (during migration)
 */
function authenticate() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'missing_auth', 'message' => 'No authorization provided']);
        exit;
    }

    // Try JWT first
    if (stripos($authHeader, 'Bearer') === 0) {
        return verifyJWT();
    }

    //Send content type if not sent yet.
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_auth', 'message' => 'Invalid authentication method']);
    exit();
}
?>