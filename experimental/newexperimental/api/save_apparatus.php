<?php
/**
 * File: save_apparatus.php
 * Description: Creates or updates an apparatus in the apparatus repository
 *
 * POST body (JSON):
 *   name        - Apparatus name (required)
 *   type        - Apparatus type (required)
 *   location    - Location (optional)
 *   id          - Apparatus ID (optional)
 *   description - Description (optional)
 *   features    - Array of features (optional)
 *   parameters  - Array of parameters (optional)
 *   documents   - Array of documents (optional)
 *   facility_pkey - Parent facility pkey (required for new apparatus)
 *   pkey        - Apparatus pkey (optional - if provided, updates existing apparatus)
 *
 * Returns the saved apparatus data on success.
 */

// Change to root directory for proper include path resolution
chdir('../..');

session_start();

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
    $_SESSION['loggedin'] = "no";
}
$_SESSION['LAST_ACTIVITY'] = time();

header('Content-type: application/json');

// Require login
if ($_SESSION['loggedin'] != "yes") {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$userpkey = $_SESSION['userpkey'];

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), false);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// Validate required fields
$errors = [];
if (empty($input->name) || trim($input->name) === '') {
    $errors[] = 'Apparatus Name is required';
}
if (empty($input->type) || trim($input->type) === '') {
    $errors[] = 'Apparatus Type is required';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['error' => implode('. ', $errors)]);
    exit;
}

include_once("adminkeys.php");
include("prepare_connections.php");
include_once("includes/UUID.php");

$is_admin = in_array($userpkey, $admin_pkeys);
$uuid_gen = new UUID();

// Extract fields with pg_escape_string
$name = pg_escape_string(trim($input->name ?? ''));
$type = pg_escape_string(trim($input->type ?? ''));
$location = pg_escape_string(trim($input->location ?? ''));
$apparatus_id = pg_escape_string(trim($input->id ?? ''));
$description = pg_escape_string(trim($input->description ?? ''));

// Features as semicolon-separated string
$features = '';
if (!empty($input->features) && is_array($input->features)) {
    $features = pg_escape_string(implode('; ', $input->features));
}

// Check if updating existing apparatus
if (!empty($input->pkey)) {
    $apparatus_pkey = (int)$input->pkey;

    // Verify permission (admin or facility PI)
    if ($is_admin) {
        $row = $db->get_row_prepared(
            "SELECT * FROM apprepo.apparatus WHERE pkey = $1",
            array($apparatus_pkey)
        );
    } else {
        $row = $db->get_row_prepared(
            "SELECT * FROM apprepo.apparatus WHERE pkey = $1 AND facility_pkey IN (SELECT facility_pkey FROM apprepo.facility_users WHERE users_pkey = $2)",
            array($apparatus_pkey, $userpkey)
        );
    }

    if (empty($row->pkey)) {
        http_response_code(404);
        echo json_encode(['error' => 'Apparatus not found or access denied']);
        exit;
    }

    $facility_pkey = $row->facility_pkey;

    // Update apparatus
    $modified_timestamp = time();
    $created_timestamp = (int) $row->created_timestamp;

    // Build JSON for storage
    $storejson = new stdClass();
    $storejson->name = $input->name;
    $storejson->type = $input->type;
    $storejson->location = $input->location ?? '';
    $storejson->id = $input->id ?? '';
    $storejson->description = $input->description ?? '';
    $storejson->features = $input->features ?? [];
    $storejson->parameters = $input->parameters ?? [];
    $storejson->documents = $input->documents ?? [];
    $storejson->uuid = $row->uuid;
    $storejson->created_timestamp = $created_timestamp;
    $storejson->modified_timestamp = $modified_timestamp;
    $storejson_str = pg_escape_string(json_encode($storejson, JSON_PRETTY_PRINT));

    $db->query("
        UPDATE apprepo.apparatus SET
            created_timestamp = $created_timestamp,
            modified_timestamp = $modified_timestamp,
            name = '$name',
            type = '$type',
            location = '$location',
            apparatus_id = '$apparatus_id',
            description = '$description',
            json = '$storejson_str'
        WHERE pkey = $apparatus_pkey
    ");

    // Update parameters - delete existing and re-insert
    $db->query("DELETE FROM apprepo.apparatus_parameter WHERE apparatus_pkey = $apparatus_pkey");

    if (!empty($input->parameters) && is_array($input->parameters)) {
        foreach ($input->parameters as $par) {
            $param_pkey = $db->get_var("SELECT nextval('apprepo.parameter_pkey_seq')");
            $par_type = pg_escape_string($par->type ?? '');
            $par_min = pg_escape_string($par->min ?? '');
            $par_max = pg_escape_string($par->max ?? '');
            $par_unit = pg_escape_string($par->unit ?? '');
            $par_prefix = pg_escape_string($par->prefix ?? '');
            $par_note = pg_escape_string($par->note ?? '');
            $par_created = time();
            $par_modified = time();

            $db->query("
                INSERT INTO apprepo.apparatus_parameter
                VALUES ($param_pkey, $apparatus_pkey, $userpkey, $par_created, $par_modified,
                        '$par_type', '$par_min', '$par_max', '$par_unit', '$par_prefix', '$par_note')
            ");
        }
    }

    // Return updated apparatus
    $result = new stdClass();
    $result->pkey = $apparatus_pkey;
    $result->name = $input->name;
    $result->uuid = $row->uuid;
    $result->success = true;
    $result->message = 'Apparatus updated successfully';

} else {
    // Create new apparatus - facility_pkey is required
    if (empty($input->facility_pkey)) {
        http_response_code(400);
        echo json_encode(['error' => 'facility_pkey is required for new apparatus']);
        exit;
    }

    $facility_pkey = (int)$input->facility_pkey;

    // Verify permission to add apparatus to this facility
    if (!$is_admin) {
        $pi_count = $db->get_var_prepared(
            "SELECT count(*) FROM apprepo.facility_users WHERE users_pkey = $1 AND facility_pkey = $2",
            array($userpkey, $facility_pkey)
        );
        if ($pi_count == 0) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to add apparatus to this facility']);
            exit;
        }
    }

    // Create new apparatus
    $apparatus_pkey = $db->get_var("SELECT nextval('apprepo.apparatus_pkey_seq')");
    $uuid = $uuid_gen->v4();
    $created_timestamp = time();
    $modified_timestamp = $created_timestamp;

    // Build JSON for storage
    $storejson = new stdClass();
    $storejson->name = $input->name;
    $storejson->type = $input->type;
    $storejson->location = $input->location ?? '';
    $storejson->id = $input->id ?? '';
    $storejson->description = $input->description ?? '';
    $storejson->features = $input->features ?? [];
    $storejson->parameters = $input->parameters ?? [];
    $storejson->documents = $input->documents ?? [];
    $storejson->uuid = $uuid;
    $storejson->created_timestamp = $created_timestamp;
    $storejson->modified_timestamp = $modified_timestamp;
    $storejson_str = pg_escape_string(json_encode($storejson, JSON_PRETTY_PRINT));

    $db->query("
        INSERT INTO apprepo.apparatus (
            pkey, facility_pkey, userpkey, uuid, created_timestamp, modified_timestamp,
            name, type, location, apparatus_id, description, json
        ) VALUES (
            $apparatus_pkey, $facility_pkey, $userpkey, '$uuid', $created_timestamp, $modified_timestamp,
            '$name', '$type', '$location', '$apparatus_id', '$description', '$storejson_str'
        )
    ");

    // Insert parameters
    if (!empty($input->parameters) && is_array($input->parameters)) {
        foreach ($input->parameters as $par) {
            $param_pkey = $db->get_var("SELECT nextval('apprepo.parameter_pkey_seq')");
            $par_type = pg_escape_string($par->type ?? '');
            $par_min = pg_escape_string($par->min ?? '');
            $par_max = pg_escape_string($par->max ?? '');
            $par_unit = pg_escape_string($par->unit ?? '');
            $par_prefix = pg_escape_string($par->prefix ?? '');
            $par_note = pg_escape_string($par->note ?? '');
            $par_created = time();
            $par_modified = time();

            $db->query("
                INSERT INTO apprepo.apparatus_parameter
                VALUES ($param_pkey, $apparatus_pkey, $userpkey, $par_created, $par_modified,
                        '$par_type', '$par_min', '$par_max', '$par_unit', '$par_prefix', '$par_note')
            ");
        }
    }

    // Return created apparatus
    $result = new stdClass();
    $result->pkey = (int)$apparatus_pkey;
    $result->name = $input->name;
    $result->uuid = $uuid;
    $result->facility_pkey = $facility_pkey;
    $result->success = true;
    $result->message = 'Apparatus created successfully';
}

echo json_encode($result, JSON_PRETTY_PRINT);
