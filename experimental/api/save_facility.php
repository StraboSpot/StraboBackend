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

// Extract fields (prepared statements will handle escaping)
$name = trim($input->name ?? '');
$type = trim($input->type ?? '');
$institute = trim($input->institute ?? '');
$department = trim($input->department ?? '');
$facility_website = trim($input->website ?? '');
$facility_desc = trim($input->description ?? '');
$facility_id = trim($input->id ?? '');

// Address fields
$street = trim($input->address->street ?? '');
$building = trim($input->address->building ?? '');
$city = trim($input->address->city ?? '');
$state = trim($input->address->state ?? '');
$country = trim($input->address->country ?? '');
$postcode = trim($input->address->postcode ?? '');
$latitude = trim($input->address->latitude ?? '');
$longitude = trim($input->address->longitude ?? '');

// Contact fields
$contact_firstname = trim($input->contact->firstname ?? '');
$contact_lastname = trim($input->contact->lastname ?? '');
$contact_affil = trim($input->contact->affiliation ?? '');
$contact_email = trim($input->contact->email ?? '');
$contact_phone = trim($input->contact->phone ?? '');
$contact_website = trim($input->contact->website ?? '');
$contact_id = trim($input->contact->id ?? '');

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
    $storejson_str = json_encode($storejson, JSON_PRETTY_PRINT);

    $db->prepare_query("
        UPDATE apprepo.facility SET
            created_timestamp = $1,
            modified_timestamp = $2,
            institute = $3,
            department = $4,
            name = $5,
            type = $6,
            facility_id = $7,
            facility_website = $8,
            facility_desc = $9,
            street = $10,
            building = $11,
            postcode = $12,
            city = $13,
            state = $14,
            country = $15,
            latitude = $16,
            longitude = $17,
            contact_firstname = $18,
            contact_lastname = $19,
            contact_affil = $20,
            contact_email = $21,
            contact_phone = $22,
            contact_website = $23,
            contact_id = $24,
            json = $25
        WHERE pkey = $26
    ", array(
        $created_timestamp, $modified_timestamp,
        $institute, $department, $name, $type, $facility_id, $facility_website, $facility_desc,
        $street, $building, $postcode, $city, $state, $country, $latitude, $longitude,
        $contact_firstname, $contact_lastname, $contact_affil, $contact_email, $contact_phone, $contact_website, $contact_id,
        $storejson_str, $facility_pkey
    ));

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
    $storejson_str = json_encode($storejson, JSON_PRETTY_PRINT);

    $db->prepare_query("
        INSERT INTO apprepo.facility (
            pkey, uuid, created_timestamp, modified_timestamp,
            institute, department, name, type, facility_id, facility_website, facility_desc,
            street, building, postcode, city, state, country, latitude, longitude,
            contact_firstname, contact_lastname, contact_affil, contact_email, contact_phone, contact_website, contact_id,
            json
        ) VALUES (
            $1, $2, $3, $4,
            $5, $6, $7, $8, $9, $10, $11,
            $12, $13, $14, $15, $16, $17, $18, $19,
            $20, $21, $22, $23, $24, $25, $26,
            $27
        )
    ", array(
        $facility_pkey, $uuid, $created_timestamp, $modified_timestamp,
        $institute, $department, $name, $type, $facility_id, $facility_website, $facility_desc,
        $street, $building, $postcode, $city, $state, $country, $latitude, $longitude,
        $contact_firstname, $contact_lastname, $contact_affil, $contact_email, $contact_phone, $contact_website, $contact_id,
        $storejson_str
    ));

    // Add current user as facility PI
    $db->prepare_query("INSERT INTO apprepo.facility_users (facility_pkey, users_pkey) VALUES ($1, $2)", array($facility_pkey, $userpkey));

    // Return created facility
    $result = new stdClass();
    $result->pkey = (int)$facility_pkey;
    $result->name = $input->name;
    $result->uuid = $uuid;
    $result->success = true;
    $result->message = 'Facility created successfully';
}

echo json_encode($result, JSON_PRETTY_PRINT);
