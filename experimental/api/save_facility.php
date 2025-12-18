<?php
/**
 * File: save_facility.php
 * Description: Creates or updates a facility in the apparatus repository
 *
 * POST body (JSON):
 *   name        - Facility name (required)
 *   type        - Facility type (required)
 *   institute   - Institute name (required)
 *   department  - Department (optional)
 *   website     - Facility website (optional)
 *   description - Facility description (optional)
 *   address     - Address object with street, building, city, state, country, postcode, latitude, longitude
 *   contact     - Contact object with firstname, lastname, affiliation, email, phone, website, id
 *   pkey        - Facility pkey (optional - if provided, updates existing facility)
 *
 * Returns the saved facility data on success.
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
    $errors[] = 'Facility Name is required';
}
if (empty($input->type) || trim($input->type) === '') {
    $errors[] = 'Facility Type is required';
}
if (empty($input->institute) || trim($input->institute) === '') {
    $errors[] = 'Institute Name is required';
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
$institute = pg_escape_string(trim($input->institute ?? ''));
$department = pg_escape_string(trim($input->department ?? ''));
$facility_website = pg_escape_string(trim($input->website ?? ''));
$facility_desc = pg_escape_string(trim($input->description ?? ''));
$facility_id = pg_escape_string(trim($input->id ?? ''));

// Address fields
$street = pg_escape_string(trim($input->address->street ?? ''));
$building = pg_escape_string(trim($input->address->building ?? ''));
$city = pg_escape_string(trim($input->address->city ?? ''));
$state = pg_escape_string(trim($input->address->state ?? ''));
$country = pg_escape_string(trim($input->address->country ?? ''));
$postcode = pg_escape_string(trim($input->address->postcode ?? ''));
$latitude = pg_escape_string(trim($input->address->latitude ?? ''));
$longitude = pg_escape_string(trim($input->address->longitude ?? ''));

// Contact fields
$contact_firstname = pg_escape_string(trim($input->contact->firstname ?? ''));
$contact_lastname = pg_escape_string(trim($input->contact->lastname ?? ''));
$contact_affil = pg_escape_string(trim($input->contact->affiliation ?? ''));
$contact_email = pg_escape_string(trim($input->contact->email ?? ''));
$contact_phone = pg_escape_string(trim($input->contact->phone ?? ''));
$contact_website = pg_escape_string(trim($input->contact->website ?? ''));
$contact_id = pg_escape_string(trim($input->contact->id ?? ''));

// Check if updating existing facility
if (!empty($input->pkey)) {
    $facility_pkey = (int)$input->pkey;

    // Verify permission (admin or facility PI)
    if ($is_admin) {
        $row = $db->get_row_prepared(
            "SELECT * FROM apprepo.facility WHERE pkey = $1",
            array($facility_pkey)
        );
    } else {
        $row = $db->get_row_prepared(
            "SELECT * FROM apprepo.facility WHERE pkey = $1 AND pkey IN (SELECT facility_pkey FROM apprepo.facility_users WHERE users_pkey = $2)",
            array($facility_pkey, $userpkey)
        );
    }

    if (empty($row->pkey)) {
        http_response_code(404);
        echo json_encode(['error' => 'Facility not found or access denied']);
        exit;
    }

    // Update facility
    $modified_timestamp = time();
    $created_timestamp = (int) $row->created_timestamp;

    // Build JSON for storage
    $storejson = new stdClass();
    $storejson->name = $input->name;
    $storejson->type = $input->type;
    $storejson->institute = $input->institute;
    $storejson->department = $input->department ?? '';
    $storejson->website = $input->website ?? '';
    $storejson->description = $input->description ?? '';
    $storejson->id = $input->id ?? '';
    $storejson->address = $input->address ?? new stdClass();
    $storejson->contact = $input->contact ?? new stdClass();
    $storejson->uuid = $row->uuid;
    $storejson->created_timestamp = $created_timestamp;
    $storejson->modified_timestamp = $modified_timestamp;
    $storejson_str = pg_escape_string(json_encode($storejson, JSON_PRETTY_PRINT));

    $db->query("
        UPDATE apprepo.facility SET
            created_timestamp = $created_timestamp,
            modified_timestamp = $modified_timestamp,
            institute = '$institute',
            department = '$department',
            name = '$name',
            type = '$type',
            facility_id = '$facility_id',
            facility_website = '$facility_website',
            facility_desc = '$facility_desc',
            street = '$street',
            building = '$building',
            postcode = '$postcode',
            city = '$city',
            state = '$state',
            country = '$country',
            latitude = '$latitude',
            longitude = '$longitude',
            contact_firstname = '$contact_firstname',
            contact_lastname = '$contact_lastname',
            contact_affil = '$contact_affil',
            contact_email = '$contact_email',
            contact_phone = '$contact_phone',
            contact_website = '$contact_website',
            contact_id = '$contact_id',
            json = '$storejson_str'
        WHERE pkey = $facility_pkey
    ");

    // Return updated facility
    $result = new stdClass();
    $result->pkey = $facility_pkey;
    $result->name = $input->name;
    $result->uuid = $row->uuid;
    $result->success = true;
    $result->message = 'Facility updated successfully';

} else {
    // Create new facility
    $facility_pkey = $db->get_var("SELECT nextval('apprepo.facility_pkey_seq')");
    $uuid = $uuid_gen->v4();
    $created_timestamp = time();
    $modified_timestamp = $created_timestamp;

    // Build JSON for storage
    $storejson = new stdClass();
    $storejson->name = $input->name;
    $storejson->type = $input->type;
    $storejson->institute = $input->institute;
    $storejson->department = $input->department ?? '';
    $storejson->website = $input->website ?? '';
    $storejson->description = $input->description ?? '';
    $storejson->id = $input->id ?? '';
    $storejson->address = $input->address ?? new stdClass();
    $storejson->contact = $input->contact ?? new stdClass();
    $storejson->uuid = $uuid;
    $storejson->created_timestamp = $created_timestamp;
    $storejson->modified_timestamp = $modified_timestamp;
    $storejson_str = pg_escape_string(json_encode($storejson, JSON_PRETTY_PRINT));

    $db->query("
        INSERT INTO apprepo.facility (
            pkey, uuid, created_timestamp, modified_timestamp,
            institute, department, name, type, facility_id, facility_website, facility_desc,
            street, building, postcode, city, state, country, latitude, longitude,
            contact_firstname, contact_lastname, contact_affil, contact_email, contact_phone, contact_website, contact_id,
            json
        ) VALUES (
            $facility_pkey, '$uuid', $created_timestamp, $modified_timestamp,
            '$institute', '$department', '$name', '$type', '$facility_id', '$facility_website', '$facility_desc',
            '$street', '$building', '$postcode', '$city', '$state', '$country', '$latitude', '$longitude',
            '$contact_firstname', '$contact_lastname', '$contact_affil', '$contact_email', '$contact_phone', '$contact_website', '$contact_id',
            '$storejson_str'
        )
    ");

    // Add current user as facility PI
    $db->query("INSERT INTO apprepo.facility_users (facility_pkey, users_pkey) VALUES ($facility_pkey, $userpkey)");

    // Return created facility
    $result = new stdClass();
    $result->pkey = (int)$facility_pkey;
    $result->name = $input->name;
    $result->uuid = $uuid;
    $result->success = true;
    $result->message = 'Facility created successfully';
}

echo json_encode($result, JSON_PRETTY_PRINT);
