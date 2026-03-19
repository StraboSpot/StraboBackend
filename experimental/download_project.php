<?php
/**
 * File: download_project.php
 * Description: Standalone download page for a StraboExperimental project
 *
 * Query params:
 *   u - Project UUID (required)
 *
 * Access: Public projects available to all; private projects require login + ownership.
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
$project_uuid = isset($_GET['u']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['u']) : '';

if (empty($project_uuid)) {
    include("includes/mheader.php");
?>
    <div id="main" class="wrapper style1">
        <div class="container">
            <header class="major">
                <h2>Download Project</h2>
            </header>
            <section id="content">
                <div style="text-align:center;margin-bottom:500px;">
                    <p>No project specified.</p>
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

// Query project by UUID with access control
if ($is_admin) {
    $row = $db->get_row_prepared("
        SELECT
            p.pkey,
            p.uuid,
            p.name,
            p.notes,
            p.ispublic,
            p.userpkey as project_owner,
            to_char(p.created_timestamp, 'Month DD, YYYY') as created_date,
            to_char(p.modified_timestamp, 'Month DD, YYYY') as modified_date
        FROM straboexp.project p
        WHERE p.uuid = $1
    ", array($project_uuid));
} else {
    $row = $db->get_row_prepared("
        SELECT
            p.pkey,
            p.uuid,
            p.name,
            p.notes,
            p.ispublic,
            p.userpkey as project_owner,
            to_char(p.created_timestamp, 'Month DD, YYYY') as created_date,
            to_char(p.modified_timestamp, 'Month DD, YYYY') as modified_date
        FROM straboexp.project p
        WHERE p.uuid = $1 AND (p.userpkey = $2 OR p.ispublic = true)
    ", array($project_uuid, $userpkey));
}

if (empty($row->pkey)) {
    include("includes/mheader.php");
?>
    <div id="main" class="wrapper style1">
        <div class="container">
            <header class="major">
                <h2>Download Project</h2>
            </header>
            <section id="content">
                <div style="text-align:center;margin-bottom:500px;">
                    <p>Project not found or you do not have permission to access it.</p>
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

// Get experiment count for display
$experiment_count = $db->get_var_prepared(
    "SELECT COUNT(*) FROM straboexp.experiment WHERE project_pkey = $1",
    array($row->pkey)
);

$project_name = $row->name ?: 'Untitled Project';

include("includes/mheader.php");
?>

<div id="main" class="wrapper style1">
    <div class="container">

        <header class="major">
            <h2>Download Project</h2>
        </header>

        <section id="content">
            <div style="max-width:600px;margin:0 auto;">

                <h3><?php echo htmlspecialchars($project_name); ?></h3>

                <table class="myDataTable">
                    <tbody>
                        <?php if (!empty($row->notes)) { ?>
                        <tr>
                            <td><strong>Description</strong></td>
                            <td><?php echo htmlspecialchars($row->notes); ?></td>
                        </tr>
                        <?php } ?>
                        <tr>
                            <td><strong>Experiments</strong></td>
                            <td><?php echo (int)$experiment_count; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Created</strong></td>
                            <td><?php echo htmlspecialchars($row->created_date); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Last Modified</strong></td>
                            <td><?php echo htmlspecialchars($row->modified_date); ?></td>
                        </tr>
                    </tbody>
                </table>

                <h4 style="margin-top:2em;">Choose Download Format</h4>

                <ul class="actions">
                    <li><a href="/experimental/api/download_project.php?id=<?php echo (int)$row->pkey; ?>" class="button primary">Download JSON</a></li>
                    <li><a href="/experimental/api/download_project_pdf.php?id=<?php echo (int)$row->pkey; ?>" class="button">Download PDF</a></li>
                </ul>

                <div style="margin-top:3em;">
                    <a href="/my_experimental_data" class="button small">&larr; Back to My Data</a>
                </div>

            </div>
        </section>

        <div class="bottomSpacer"></div>

    </div>
</div>

<?php
include("includes/mfooter.php");
?>
