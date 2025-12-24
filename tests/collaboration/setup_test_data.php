<?php
/**
 * Collaboration Testing: Setup Test Data
 *
 * This script creates test users, projects, datasets, and spots for testing
 * the collaboration feature. Run this ONCE before running tests.
 *
 * Usage: php setup_test_data.php
 *
 * @package StraboSpot Tests
 */

// Change to www directory for includes
chdir(__DIR__ . '/../../');

require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

echo "=== Collaboration Test Data Setup ===\n\n";

// Test user credentials (using MD5 crypt for compatibility)
$testPassword = 'testpass123';
$hashedPassword = crypt($testPassword, '$1$' . substr(md5(uniqid(rand(), true)), 0, 8));

$testUsers = [
    [
        'email' => 'owner@test.strabospot.org',
        'firstname' => 'Test',
        'lastname' => 'Owner',
        'password' => $hashedPassword
    ],
    [
        'email' => 'editor@test.strabospot.org',
        'firstname' => 'Test',
        'lastname' => 'Editor',
        'password' => $hashedPassword
    ],
    [
        'email' => 'readonly@test.strabospot.org',
        'firstname' => 'Test',
        'lastname' => 'Readonly',
        'password' => $hashedPassword
    ],
    [
        'email' => 'outsider@test.strabospot.org',
        'firstname' => 'Test',
        'lastname' => 'Outsider',
        'password' => $hashedPassword
    ]
];

// Clean up existing test data
echo "1. Cleaning up existing test data...\n";

// Delete test collaborators (numeric IDs in 9999999900+ range)
$db->query("DELETE FROM collaborators WHERE strabo_project_id LIKE '999999990%'");
echo "   - Deleted test collaborators\n";

// Delete test users
$db->query("DELETE FROM users WHERE email LIKE '%@test.strabospot.org'");
echo "   - Deleted test users\n";

// Delete test projects and datasets from Neo4j (using numeric ID ranges)
$neodb->query("MATCH (p:Project) WHERE p.id >= 9999999900 AND p.id < 10000000000 DETACH DELETE p");
$neodb->query("MATCH (d:Dataset) WHERE d.id >= 8888888800 AND d.id < 8888888900 DETACH DELETE d");
$neodb->query("MATCH (s:Spot) WHERE s.id >= 7777777700 AND s.id < 7777777800 DETACH DELETE s");
echo "   - Deleted test projects, datasets, spots from Neo4j\n";

// Create test users
echo "\n2. Creating test users...\n";
$userPkeys = [];

foreach ($testUsers as $user) {
    // Generate a hash for the user (required field)
    $hash = md5(uniqid(rand(), true));

    $db->prepare_query(
        "INSERT INTO users (email, password, firstname, lastname, hash, active, deleted)
         VALUES ($1, $2, $3, $4, $5, true, false)",
        [$user['email'], $user['password'], $user['firstname'], $user['lastname'], $hash]
    );

    $pkey = $db->get_var_prepared(
        "SELECT pkey FROM users WHERE email = $1",
        [$user['email']]
    );

    $userPkeys[$user['email']] = (int)$pkey;
    echo "   - Created user: {$user['email']} (pkey: $pkey)\n";
}

$ownerPkey = $userPkeys['owner@test.strabospot.org'];
$editorPkey = $userPkeys['editor@test.strabospot.org'];
$readonlyPkey = $userPkeys['readonly@test.strabospot.org'];
$outsiderPkey = $userPkeys['outsider@test.strabospot.org'];

// Create test projects in Neo4j
echo "\n3. Creating test projects in Neo4j...\n";

$timestamp = time() * 1000; // JavaScript timestamp format

// Use numeric project IDs (matching production format)
$projectSoloId = 9999999901;
$projectCollabId = 9999999902;
$projectHaltedId = 9999999903;

// Project 1: Owner's project (no collaboration)
$neodb->query("
    CREATE (p:Project {
        id: $projectSoloId,
        desc_project_name: 'Test Solo Project',
        userpkey: $ownerPkey,
        modified_timestamp: $timestamp
    })
");
echo "   - Created project $projectSoloId (owner only)\n";

// Project 2: Active collaboration project
$neodb->query("
    CREATE (p:Project {
        id: $projectCollabId,
        desc_project_name: 'Test Collaboration Project',
        userpkey: $ownerPkey,
        modified_timestamp: $timestamp
    })
");
echo "   - Created project $projectCollabId (for collaboration testing)\n";

// Project 3: Halted collaboration project
$neodb->query("
    CREATE (p:Project {
        id: $projectHaltedId,
        desc_project_name: 'Test Halted Project',
        userpkey: $ownerPkey,
        modified_timestamp: $timestamp
    })
");
echo "   - Created project $projectHaltedId (halted collaboration)\n";

// Create datasets
echo "\n4. Creating test datasets...\n";

// Numeric dataset IDs matching production format
$datasetSolo1 = 8888888801;
$datasetCollabOwner = 8888888802;
$datasetCollabEditor = 8888888803;
$datasetHaltedOwner = 8888888804;
$datasetHaltedEditor = 8888888805;

// Dataset owned by owner in solo project
$neodb->query("
    MATCH (p:Project {id: $projectSoloId})
    CREATE (d:Dataset {
        id: $datasetSolo1,
        name: 'Owner Dataset Solo',
        userpkey: $ownerPkey,
        created_by: $ownerPkey,
        modified_timestamp: $timestamp
    })
    CREATE (p)-[:HAS_DATASET]->(d)
");
echo "   - Created dataset $datasetSolo1 (solo project)\n";

// Datasets in collaboration project
$neodb->query("
    MATCH (p:Project {id: $projectCollabId})
    CREATE (d:Dataset {
        id: $datasetCollabOwner,
        name: 'Owner Dataset Collab',
        userpkey: $ownerPkey,
        created_by: $ownerPkey,
        modified_timestamp: $timestamp
    })
    CREATE (p)-[:HAS_DATASET]->(d)
");
echo "   - Created dataset $datasetCollabOwner (owned by owner)\n";

$neodb->query("
    MATCH (p:Project {id: $projectCollabId})
    CREATE (d:Dataset {
        id: $datasetCollabEditor,
        name: 'Editor Dataset Collab',
        userpkey: $ownerPkey,
        created_by: $editorPkey,
        collaboratorpkey: $editorPkey,
        modified_timestamp: $timestamp
    })
    CREATE (p)-[:HAS_DATASET]->(d)
");
echo "   - Created dataset $datasetCollabEditor (created by editor, with collaboratorpkey)\n";

// Datasets in halted project
$neodb->query("
    MATCH (p:Project {id: $projectHaltedId})
    CREATE (d:Dataset {
        id: $datasetHaltedOwner,
        name: 'Owner Dataset Halted',
        userpkey: $ownerPkey,
        created_by: $ownerPkey,
        modified_timestamp: $timestamp
    })
    CREATE (p)-[:HAS_DATASET]->(d)
");
echo "   - Created dataset $datasetHaltedOwner (halted owner)\n";

$neodb->query("
    MATCH (p:Project {id: $projectHaltedId})
    CREATE (d:Dataset {
        id: $datasetHaltedEditor,
        name: 'Editor Dataset Halted',
        userpkey: $ownerPkey,
        created_by: $editorPkey,
        modified_timestamp: $timestamp
    })
    CREATE (p)-[:HAS_DATASET]->(d)
");
echo "   - Created dataset $datasetHaltedEditor (halted editor)\n";

// Create spots
echo "\n5. Creating test spots...\n";

// Numeric spot IDs
$spotSolo1 = 7777777701;
$spotCollabOwner1 = 7777777702;
$spotCollabEditor1 = 7777777703;
$spotHaltedOwner1 = 7777777704;
$spotHaltedEditor1 = 7777777705;

$spotData = [
    [$spotSolo1, $datasetSolo1, $ownerPkey],
    [$spotCollabOwner1, $datasetCollabOwner, $ownerPkey],
    [$spotCollabEditor1, $datasetCollabEditor, $editorPkey],
    [$spotHaltedOwner1, $datasetHaltedOwner, $ownerPkey],
    [$spotHaltedEditor1, $datasetHaltedEditor, $editorPkey],
];

foreach ($spotData as [$spotId, $datasetId, $createdBy]) {
    $neodb->query("
        MATCH (d:Dataset {id: $datasetId})
        CREATE (s:Spot {
            id: $spotId,
            userpkey: $ownerPkey,
            created_by: $createdBy,
            modified_timestamp: $timestamp
        })
        CREATE (d)-[:CONTAINS_SPOT]->(s)
    ");
    echo "   - Created spot $spotId\n";
}

// Create collaborator relationships
echo "\n6. Setting up collaborator relationships...\n";

// Active collaboration: editor with edit permissions
$uuid1 = bin2hex(random_bytes(16));
$db->prepare_query(
    "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, uuid, disabled)
     VALUES ($1, $2, $3, $4, true, $5, false)",
    [(string)$projectCollabId, $ownerPkey, $editorPkey, 'edit', $uuid1]
);
echo "   - Added editor as 'edit' collaborator on project $projectCollabId\n";

// Active collaboration: readonly with readonly permissions
$uuid2 = bin2hex(random_bytes(16));
$db->prepare_query(
    "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, uuid, disabled)
     VALUES ($1, $2, $3, $4, true, $5, false)",
    [(string)$projectCollabId, $ownerPkey, $readonlyPkey, 'readonly', $uuid2]
);
echo "   - Added readonly as 'readonly' collaborator on project $projectCollabId\n";

// Halted collaboration: editor was collaborator but now disabled
$uuid3 = bin2hex(random_bytes(16));
$db->prepare_query(
    "INSERT INTO collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey, collaboration_level, accepted, uuid, disabled)
     VALUES ($1, $2, $3, $4, true, $5, true)",
    [(string)$projectHaltedId, $ownerPkey, $editorPkey, 'edit', $uuid3]
);
echo "   - Added editor as disabled collaborator on project $projectHaltedId (halted)\n";

echo "\n=== Test Data Setup Complete ===\n";
echo "\nTest Credentials:\n";
echo "  Password for all test users: $testPassword\n";
echo "\nTest Users:\n";
foreach ($userPkeys as $email => $pkey) {
    echo "  - $email (pkey: $pkey)\n";
}
echo "\nTest Projects (IDs):\n";
echo "  - $projectSoloId: Owner-only project (no collaboration)\n";
echo "  - $projectCollabId: Active collaboration with editor + readonly\n";
echo "  - $projectHaltedId: Halted collaboration (all disabled)\n";
echo "\nTest Datasets (IDs):\n";
echo "  - $datasetSolo1: Solo project dataset\n";
echo "  - $datasetCollabOwner: Collab project - owner's dataset\n";
echo "  - $datasetCollabEditor: Collab project - editor's dataset\n";
echo "\n";
