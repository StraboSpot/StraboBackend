<?php
/**
 * File: download_experiment.php
 * Description: Standalone download page for a single StraboExperimental experiment
 *
 * Query params:
 *   u - Experiment UUID (required)
 *
 * Access: Public experiments available to all; private experiments require login + ownership.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Change to root directory for proper include path resolution
chdir($_SERVER['DOCUMENT_ROOT']);

include("logincheck.php");
include("prepare_connections.php");
include("adminkeys.php");

// Validate UUID parameter
$experiment_uuid = isset($_GET['u']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['u']) : '';

if (empty($experiment_uuid)) {
    include("includes/mheader.php");
?>
    <div id="main" class="wrapper style1">
        <div class="container">
            <header class="major">
                <h2>Download Experiment</h2>
            </header>
            <section id="content">
                <div style="text-align:center;margin-bottom:500px;">
                    <p>No experiment specified.</p>
                    <a href="/my_experimental_data" class="button">&larr; Back to My Data</a>
                </div>
            </section>
            <div class="bottomSpacer"></div>
        </div>
    </div>
<?php
    include("includes/mfooter.php");
    exit;
}

$is_admin = in_array($userpkey, $admin_pkeys);

// Query experiment by UUID with access control
if ($is_admin) {
    $row = $db->get_row_prepared("
        SELECT
            e.pkey,
            e.project_pkey,
            e.id as experiment_id,
            e.uuid,
            e.json,
            e.userpkey as experiment_owner,
            p.name as project_name,
            p.uuid as project_uuid,
            p.ispublic
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.uuid = $1
    ", array($experiment_uuid));
} else {
    $row = $db->get_row_prepared("
        SELECT
            e.pkey,
            e.project_pkey,
            e.id as experiment_id,
            e.uuid,
            e.json,
            e.userpkey as experiment_owner,
            p.name as project_name,
            p.uuid as project_uuid,
            p.ispublic
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.uuid = $1 AND (e.userpkey = $2 OR p.ispublic = true)
    ", array($experiment_uuid, $userpkey));
}

if (empty($row->pkey)) {
    include("includes/mheader.php");
?>
    <div id="main" class="wrapper style1">
        <div class="container">
            <header class="major">
                <h2>Download Experiment</h2>
            </header>
            <section id="content">
                <div style="text-align:center;margin-bottom:500px;">
                    <p>Experiment not found or you do not have permission to access it.</p>
                    <a href="/my_experimental_data" class="button">&larr; Back to My Data</a>
                </div>
            </section>
            <div class="bottomSpacer"></div>
        </div>
    </div>
<?php
    include("includes/mfooter.php");
    exit;
}

// Parse experiment data for display
$experiment_id = $row->experiment_id ?: 'N/A';
$project_name = $row->project_name ?: 'N/A';
$json_data = !empty($row->json) ? json_decode($row->json) : null;

$apparatus_type = 'N/A';
$title = $experiment_id;
$description = '';

if ($json_data) {
    if (!empty($json_data->apparatus->type)) $apparatus_type = $json_data->apparatus->type;
    if (!empty($json_data->experiment->title)) $title = $json_data->experiment->title;
    if (!empty($json_data->experiment->description)) $description = $json_data->experiment->description;
}

include("includes/mheader.php");
?>

<a href="/my_experimental_data" class="button small" style="position:fixed;top:60px;left:20px;z-index:100;">&larr; Back to My Data</a>

<div id="main" class="wrapper style1">
    <div class="container">

        <header class="major">
            <h2>Download Experiment</h2>
        </header>

        <section id="content">
            <div style="max-width:600px;margin:0 auto;">

                <h3><?php echo htmlspecialchars($title); ?></h3>

                <table class="myDataTable">
                    <tbody>
                        <tr>
                            <td><strong>Experiment ID</strong></td>
                            <td><?php echo htmlspecialchars($experiment_id); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Project</strong></td>
                            <td><?php echo htmlspecialchars($project_name); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Apparatus Type</strong></td>
                            <td><?php echo htmlspecialchars($apparatus_type); ?></td>
                        </tr>
                        <?php if (!empty($description)) { ?>
                        <tr>
                            <td><strong>Description</strong></td>
                            <td><?php echo htmlspecialchars($description); ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>

                <h4 style="margin-top:2em;">Choose Download Format</h4>

                <ul class="actions">
                    <li><a href="/experimental/api/download_experiment.php?id=<?php echo (int)$row->pkey; ?>" class="button primary">Download JSON</a></li>
                    <li><a href="/experimental/api/download_pdf.php?id=<?php echo (int)$row->pkey; ?>" class="button">Download PDF</a></li>
                </ul>

            </div>
        </section>

        <div class="bottomSpacer"></div>

    </div>
</div>

<?php
include("includes/mfooter.php");
?>
