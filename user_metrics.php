<?php
/**
 * File: user_metrics.php
 * Description: Generates user metrics report for StraboSpot
 *
 * This script gathers usage statistics from the Neo4j database (and PostgreSQL
 * users table where necessary) to produce a report suitable for sharing with
 * stakeholders.
 *
 * USAGE:
 *   Command line: php user_metrics.php [--format=text|json|csv] [--min-spots=N]
 *   Web browser:  Access directly (requires no authentication)
 *
 * OPTIONS:
 *   --format     Output format: text (default), json, or csv
 *   --min-spots  Minimum spots to consider a user/project "active" (default: 5)
 *
 * METRICS GATHERED:
 *   1. Total registered users (from PostgreSQL)
 *   2. Active users (users with at least one project)
 *   3. "Truly contributing" users (users with >= min-spots threshold)
 *   4. User tenure distribution (years on platform)
 *   5. Total projects
 *   6. "True" projects (projects with >= min-spots threshold)
 *   7. Total spots
 *   8. Users with public projects (proportion)
 *   9. Public projects (proportion)
 *   10. Publicly available spots (spots in public projects)
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

// Default minimum spots to consider a user/project as "truly active"
// This filters out users/projects that are just testing the system
$DEFAULT_MIN_SPOTS = 5;

// ============================================================================
// INITIALIZATION
// ============================================================================

// Parse command-line arguments if running from CLI
$isCli = (php_sapi_name() === 'cli');
$outputFormat = 'text';
$minSpots = $DEFAULT_MIN_SPOTS;

if ($isCli) {
    $options = getopt('', ['format:', 'min-spots:']);
    if (isset($options['format'])) {
        $outputFormat = $options['format'];
    }
    if (isset($options['min-spots'])) {
        $minSpots = (int)$options['min-spots'];
    }
} else {
    // Web request - check query parameters
    if (isset($_GET['format'])) {
        $outputFormat = $_GET['format'];
    }
    if (isset($_GET['min-spots'])) {
        $minSpots = (int)$_GET['min-spots'];
    }

    // Set appropriate content type for web
    if ($outputFormat === 'json') {
        header('Content-Type: application/json');
    } elseif ($outputFormat === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="strabospot_metrics.csv"');
    } else {
        header('Content-Type: text/plain');
    }
}

// Load database credentials and connections
require_once('includes/config.inc.php');
require_once('db.php');
require_once('neodb.php');

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Safely execute a Neo4j query and return the result
 *
 * @param object $neodb Neo4j database connection
 * @param string $query Cypher query to execute
 * @param string $description Human-readable description for error messages
 * @return mixed Query result or null on error
 */
function safeNeoQuery($neodb, $query, $description) {
    try {
        return $neodb->get_var($query);
    } catch (Exception $e) {
        error_log("Metrics Error ($description): " . $e->getMessage());
        return null;
    }
}

/**
 * Safely execute a Neo4j query that returns multiple records
 *
 * @param object $neodb Neo4j database connection
 * @param string $query Cypher query to execute
 * @param string $description Human-readable description for error messages
 * @return array Query results or empty array on error
 */
function safeNeoQueryMultiple($neodb, $query, $description) {
    try {
        return $neodb->get_results($query);
    } catch (Exception $e) {
        error_log("Metrics Error ($description): " . $e->getMessage());
        return [];
    }
}

/**
 * Format a percentage with specified precision
 *
 * @param float $value The numerator
 * @param float $total The denominator
 * @param int $precision Decimal places (default 1)
 * @return string Formatted percentage string
 */
function formatPercent($value, $total, $precision = 1) {
    if ($total == 0) return "0%";
    return round(($value / $total) * 100, $precision) . "%";
}

// ============================================================================
// DATA COLLECTION
// ============================================================================

$metrics = [];
$timestamp = date('Y-m-d H:i:s');

// Store configuration
$metrics['report_generated'] = $timestamp;
$metrics['min_spots_threshold'] = $minSpots;

// ----------------------------------------------------------------------------
// METRIC 1: Total Registered Users (from PostgreSQL)
// ----------------------------------------------------------------------------
// Query: Count all users who have active accounts (not deleted)
// Source: PostgreSQL users table
// Note: We exclude deleted accounts as they are no longer valid users
// ----------------------------------------------------------------------------
$query = "SELECT COUNT(*) FROM users WHERE deleted = false OR deleted IS NULL";
$metrics['total_registered_users'] = (int)$db->get_var($query);

// ----------------------------------------------------------------------------
// METRIC 2: Active Users (users with at least one project)
// ----------------------------------------------------------------------------
// Query: Count distinct userpkey values that have at least one Project node
// Source: Neo4j
// Rationale: A user is "active" if they've created any project at all
// ----------------------------------------------------------------------------
$query = "
    MATCH (p:Project)
    RETURN COUNT(DISTINCT p.userpkey) AS count
";
$metrics['users_with_projects'] = (int)safeNeoQuery($neodb, $query, 'users with projects');

// ----------------------------------------------------------------------------
// METRIC 3: "Truly Contributing" Users
// ----------------------------------------------------------------------------
// Query: Count users who have created at least $minSpots spots total
// Source: Neo4j
// Rationale: Users with very few spots may just be testing the system.
//            The threshold helps identify genuinely engaged users.
// ----------------------------------------------------------------------------
$query = "
    MATCH (s:Spot)
    WITH s.userpkey AS user, COUNT(s) AS spotCount
    WHERE spotCount >= $minSpots
    RETURN COUNT(user) AS count
";
$metrics['truly_contributing_users'] = (int)safeNeoQuery($neodb, $query, 'contributing users');

// ----------------------------------------------------------------------------
// METRIC 4: User Tenure Distribution (Years on Platform)
// ----------------------------------------------------------------------------
// Query: Get signup dates from PostgreSQL and calculate tenure
// Source: PostgreSQL users table
// Note: Only includes users with projects (from Neo4j userpkey list)
// ----------------------------------------------------------------------------
// First, get list of userpkeys that have projects
$projectUserQuery = "MATCH (p:Project) RETURN DISTINCT p.userpkey AS userpkey";
$projectUserRecords = safeNeoQueryMultiple($neodb, $projectUserQuery, 'project user list');

$userPkeys = [];
foreach ($projectUserRecords as $record) {
    $pkey = $record->value('userpkey');
    if ($pkey) {
        $userPkeys[] = (int)$pkey;
    }
}

// Calculate tenure distribution
$tenureDistribution = [
    'less_than_1_year' => 0,
    '1_to_2_years' => 0,
    '2_to_3_years' => 0,
    '3_to_5_years' => 0,
    'more_than_5_years' => 0
];

if (!empty($userPkeys)) {
    $pkeyList = implode(',', $userPkeys);
    $tenureQuery = "
        SELECT pkey, signup_date
        FROM users
        WHERE pkey IN ($pkeyList)
        AND (deleted = false OR deleted IS NULL)
        AND signup_date IS NOT NULL
    ";
    $tenureResults = $db->get_results($tenureQuery);

    $now = new DateTime();
    foreach ($tenureResults as $row) {
        if (!empty($row->signup_date)) {
            $signupDate = new DateTime($row->signup_date);
            $interval = $now->diff($signupDate);
            $years = $interval->y + ($interval->m / 12);

            if ($years < 1) {
                $tenureDistribution['less_than_1_year']++;
            } elseif ($years < 2) {
                $tenureDistribution['1_to_2_years']++;
            } elseif ($years < 3) {
                $tenureDistribution['2_to_3_years']++;
            } elseif ($years < 5) {
                $tenureDistribution['3_to_5_years']++;
            } else {
                $tenureDistribution['more_than_5_years']++;
            }
        }
    }
}
$metrics['user_tenure_distribution'] = $tenureDistribution;

// ----------------------------------------------------------------------------
// METRIC 5: Total Projects
// ----------------------------------------------------------------------------
// Query: Count all Project nodes in Neo4j
// Source: Neo4j
// ----------------------------------------------------------------------------
$query = "MATCH (p:Project) RETURN COUNT(p) AS count";
$metrics['total_projects'] = (int)safeNeoQuery($neodb, $query, 'total projects');

// ----------------------------------------------------------------------------
// METRIC 6: "True" Projects (with minimum spots)
// ----------------------------------------------------------------------------
// Query: Count projects that have at least $minSpots spots
// Source: Neo4j
// Rationale: Projects with very few spots may be test/demo projects
//
// Note: This query follows the relationship path:
//       (Project)-[:HAS_DATASET]->(Dataset)-[:HAS_SPOT]->(Spot)
// ----------------------------------------------------------------------------
$query = "
    MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
    WITH p, COUNT(s) AS spotCount
    WHERE spotCount >= $minSpots
    RETURN COUNT(p) AS count
";
$metrics['true_projects'] = (int)safeNeoQuery($neodb, $query, 'true projects');

// ----------------------------------------------------------------------------
// METRIC 7: Total Spots
// ----------------------------------------------------------------------------
// Query: Count all Spot nodes in Neo4j
// Source: Neo4j
// ----------------------------------------------------------------------------
$query = "MATCH (s:Spot) RETURN COUNT(s) AS count";
$metrics['total_spots'] = (int)safeNeoQuery($neodb, $query, 'total spots');

// ----------------------------------------------------------------------------
// METRIC 8: Users with Public Projects
// ----------------------------------------------------------------------------
// Query: Count distinct users who have at least one public project
// Source: Neo4j (using public property on Project nodes)
// Note: The 'public' property can be boolean true or string "true"
// ----------------------------------------------------------------------------
$query = "
    MATCH (p:Project)
    WHERE p.public = true OR p.public = 'true'
    RETURN COUNT(DISTINCT p.userpkey) AS count
";
$metrics['users_with_public_projects'] = (int)safeNeoQuery($neodb, $query, 'users with public projects');

// Calculate proportion (of users with projects)
$metrics['proportion_users_with_public_projects'] = formatPercent(
    $metrics['users_with_public_projects'],
    $metrics['users_with_projects']
);

// Also calculate for "truly contributing" users
$query = "
    MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
    WHERE p.public = true OR p.public = 'true'
    WITH p.userpkey AS user, COUNT(s) AS spotCount
    WHERE spotCount >= $minSpots
    RETURN COUNT(DISTINCT user) AS count
";
$usersWithPublicTrueProjects = (int)safeNeoQuery($neodb, $query, 'contributing users with public projects');
$metrics['contributing_users_with_public_projects'] = $usersWithPublicTrueProjects;
$metrics['proportion_contributing_users_with_public'] = formatPercent(
    $usersWithPublicTrueProjects,
    $metrics['truly_contributing_users']
);

// ----------------------------------------------------------------------------
// METRIC 9: Public Projects
// ----------------------------------------------------------------------------
// Query: Count projects where public = true
// Source: Neo4j
// ----------------------------------------------------------------------------
$query = "
    MATCH (p:Project)
    WHERE p.public = true OR p.public = 'true'
    RETURN COUNT(p) AS count
";
$metrics['public_projects'] = (int)safeNeoQuery($neodb, $query, 'public projects');

$metrics['proportion_projects_public'] = formatPercent(
    $metrics['public_projects'],
    $metrics['total_projects']
);

// Also for "true" projects
$query = "
    MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
    WHERE p.public = true OR p.public = 'true'
    WITH p, COUNT(s) AS spotCount
    WHERE spotCount >= $minSpots
    RETURN COUNT(p) AS count
";
$publicTrueProjects = (int)safeNeoQuery($neodb, $query, 'public true projects');
$metrics['public_true_projects'] = $publicTrueProjects;
$metrics['proportion_true_projects_public'] = formatPercent(
    $publicTrueProjects,
    $metrics['true_projects']
);

// ----------------------------------------------------------------------------
// METRIC 10: Publicly Available Spots
// ----------------------------------------------------------------------------
// Query: Count spots that belong to public projects
// Source: Neo4j
// Path: (Project)-[:HAS_DATASET]->(Dataset)-[:HAS_SPOT]->(Spot)
// ----------------------------------------------------------------------------
$query = "
    MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
    WHERE p.public = true OR p.public = 'true'
    RETURN COUNT(s) AS count
";
$metrics['public_spots'] = (int)safeNeoQuery($neodb, $query, 'public spots');

$metrics['proportion_spots_public'] = formatPercent(
    $metrics['public_spots'],
    $metrics['total_spots']
);

// ----------------------------------------------------------------------------
// ADDITIONAL CONTEXT: Activity Distribution
// ----------------------------------------------------------------------------
// Query: Distribution of spots per user (to understand engagement levels)
// Source: Neo4j
// ----------------------------------------------------------------------------
$query = "
    MATCH (s:Spot)
    WITH s.userpkey AS user, COUNT(s) AS spotCount
    RETURN
        COUNT(CASE WHEN spotCount >= 1 AND spotCount < 5 THEN 1 END) AS users_1_to_4_spots,
        COUNT(CASE WHEN spotCount >= 5 AND spotCount < 20 THEN 1 END) AS users_5_to_19_spots,
        COUNT(CASE WHEN spotCount >= 20 AND spotCount < 100 THEN 1 END) AS users_20_to_99_spots,
        COUNT(CASE WHEN spotCount >= 100 AND spotCount < 500 THEN 1 END) AS users_100_to_499_spots,
        COUNT(CASE WHEN spotCount >= 500 THEN 1 END) AS users_500_plus_spots
";
$activityRecords = safeNeoQueryMultiple($neodb, $query, 'activity distribution');

if (!empty($activityRecords)) {
    $record = $activityRecords[0];
    $metrics['user_activity_distribution'] = [
        '1_to_4_spots' => (int)$record->value('users_1_to_4_spots'),
        '5_to_19_spots' => (int)$record->value('users_5_to_19_spots'),
        '20_to_99_spots' => (int)$record->value('users_20_to_99_spots'),
        '100_to_499_spots' => (int)$record->value('users_100_to_499_spots'),
        '500_plus_spots' => (int)$record->value('users_500_plus_spots')
    ];
}

// ============================================================================
// OUTPUT
// ============================================================================

switch ($outputFormat) {
    case 'json':
        echo json_encode($metrics, JSON_PRETTY_PRINT);
        break;

    case 'csv':
        // Flatten metrics for CSV output
        echo "Metric,Value\n";
        echo "Report Generated," . $metrics['report_generated'] . "\n";
        echo "Min Spots Threshold," . $metrics['min_spots_threshold'] . "\n";
        echo "Total Registered Users," . $metrics['total_registered_users'] . "\n";
        echo "Users With Projects," . $metrics['users_with_projects'] . "\n";
        echo "Truly Contributing Users (>={$minSpots} spots)," . $metrics['truly_contributing_users'] . "\n";
        echo "Total Projects," . $metrics['total_projects'] . "\n";
        echo "True Projects (>={$minSpots} spots)," . $metrics['true_projects'] . "\n";
        echo "Total Spots," . $metrics['total_spots'] . "\n";
        echo "Users With Public Projects," . $metrics['users_with_public_projects'] . "\n";
        echo "Proportion Users With Public Projects," . $metrics['proportion_users_with_public_projects'] . "\n";
        echo "Public Projects," . $metrics['public_projects'] . "\n";
        echo "Proportion Projects Public," . $metrics['proportion_projects_public'] . "\n";
        echo "Public Spots," . $metrics['public_spots'] . "\n";
        echo "Proportion Spots Public," . $metrics['proportion_spots_public'] . "\n";

        // Tenure distribution
        foreach ($metrics['user_tenure_distribution'] as $key => $value) {
            echo "Tenure: " . str_replace('_', ' ', $key) . ",$value\n";
        }

        // Activity distribution
        if (isset($metrics['user_activity_distribution'])) {
            foreach ($metrics['user_activity_distribution'] as $key => $value) {
                echo "Activity: " . str_replace('_', ' ', $key) . ",$value\n";
            }
        }
        break;

    case 'text':
    default:
        $separator = str_repeat('=', 70);
        $subSeparator = str_repeat('-', 70);

        echo "\n$separator\n";
        echo "                    STRABOSPOT USER METRICS REPORT\n";
        echo "$separator\n";
        echo "Generated: {$metrics['report_generated']}\n";
        echo "Minimum spots threshold for 'active' designation: {$minSpots}\n";
        echo "$separator\n\n";

        // User Metrics
        echo "USER METRICS\n";
        echo "$subSeparator\n";
        echo sprintf("%-45s %10d\n", "Total registered users:", $metrics['total_registered_users']);
        echo sprintf("%-45s %10d\n", "Users with at least one project:", $metrics['users_with_projects']);
        echo sprintf("%-45s %10d\n", "Truly contributing users (>={$minSpots} spots):", $metrics['truly_contributing_users']);
        echo "\n";

        // User Tenure
        echo "USER TENURE (users with projects)\n";
        echo "$subSeparator\n";
        foreach ($metrics['user_tenure_distribution'] as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', $key)) . ':';
            echo sprintf("%-45s %10d\n", $label, $value);
        }
        echo "\n";

        // User Activity Distribution
        if (isset($metrics['user_activity_distribution'])) {
            echo "USER ACTIVITY DISTRIBUTION (spots per user)\n";
            echo "$subSeparator\n";
            foreach ($metrics['user_activity_distribution'] as $key => $value) {
                $label = ucfirst(str_replace('_', ' ', $key)) . ':';
                echo sprintf("%-45s %10d\n", $label, $value);
            }
            echo "\n";
        }

        // Project Metrics
        echo "PROJECT METRICS\n";
        echo "$subSeparator\n";
        echo sprintf("%-45s %10d\n", "Total projects:", $metrics['total_projects']);
        echo sprintf("%-45s %10d\n", "True projects (>={$minSpots} spots):", $metrics['true_projects']);
        echo "\n";

        // Spot Metrics
        echo "SPOT METRICS\n";
        echo "$subSeparator\n";
        echo sprintf("%-45s %10d\n", "Total spots:", $metrics['total_spots']);
        echo "\n";

        // Public Data Metrics
        echo "PUBLIC DATA METRICS\n";
        echo "$subSeparator\n";
        echo sprintf("%-45s %10d (%s)\n",
            "Users with public projects:",
            $metrics['users_with_public_projects'],
            $metrics['proportion_users_with_public_projects']
        );
        echo sprintf("%-45s %10d (%s)\n",
            "Contributing users with public projects:",
            $metrics['contributing_users_with_public_projects'],
            $metrics['proportion_contributing_users_with_public']
        );
        echo sprintf("%-45s %10d (%s)\n",
            "Public projects:",
            $metrics['public_projects'],
            $metrics['proportion_projects_public']
        );
        echo sprintf("%-45s %10d (%s)\n",
            "Public 'true' projects:",
            $metrics['public_true_projects'],
            $metrics['proportion_true_projects_public']
        );
        echo sprintf("%-45s %10d (%s)\n",
            "Publicly available spots:",
            $metrics['public_spots'],
            $metrics['proportion_spots_public']
        );

        echo "\n$separator\n";
        echo "                           END OF REPORT\n";
        echo "$separator\n\n";
        break;
}
