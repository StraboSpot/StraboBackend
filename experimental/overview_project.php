<?php
/**
 * File: overview_project.php
 * Description: HTML overview page for a StraboExperimental project with all experiments
 *              Mirrors the project PDF layout in browser-viewable format.
 *
 * Query params:
 *   u    - Project UUID (required)
 *   from - If "mydata", shows a back button to /my_experimental_data
 *
 * Access: Public projects available to all (including anonymous visitors);
 * private projects require login + ownership.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

// Change to root directory for proper include path resolution
chdir($_SERVER['DOCUMENT_ROOT']);

include("softlogincheck.php");
include("prepare_connections.php");
include("adminkeys.php");

// Validate UUID parameter
$project_uuid = isset($_GET['u']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['u']) : '';
$from = isset($_GET['from']) ? $_GET['from'] : '';

if (empty($project_uuid)) {
    include("includes/mheader.php");
?>
    <div id="main" class="wrapper style1">
        <div class="container">
            <header class="major"><h2>Project Overview</h2></header>
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
        SELECT p.pkey, p.uuid, p.name, p.notes, p.ispublic, p.userpkey as project_owner,
               to_char(p.created_timestamp, 'Month DD, YYYY') as created_date,
               to_char(p.modified_timestamp, 'Month DD, YYYY') as modified_date
        FROM straboexp.project p
        WHERE p.uuid = $1
    ", array($project_uuid));
} else {
    $row = $db->get_row_prepared("
        SELECT p.pkey, p.uuid, p.name, p.notes, p.ispublic, p.userpkey as project_owner,
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
            <header class="major"><h2>Project Overview</h2></header>
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

// Get all experiments
$exp_rows = $db->get_results_prepared("
    SELECT e.pkey, e.userpkey, e.id as experiment_id, e.uuid, e.json
    FROM straboexp.experiment e
    WHERE e.project_pkey = $1
    ORDER BY e.modified_timestamp DESC
", array($row->pkey));

$project_name = $row->name ?: 'Untitled Project';
$project_pkey = (int)$row->pkey;

// Helper functions
function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

function render_labeled_field($label, $value) {
    if (empty($value) && $value !== '0') return;
    echo '<tr><td style="width:200px;font-weight:600;color:#6c757d;vertical-align:top;">' . h($label) . '</td>';
    echo '<td>' . h($value) . '</td></tr>';
}

function render_section_header($title) {
    echo '<div style="background:#dc3545;color:#fff;padding:8px 12px;margin:25px 0 10px 0;border-radius:3px;">';
    echo '<strong>' . strtoupper(h($title)) . '</strong></div>';
}

function render_section_subheader($title) {
    echo '<h4 style="border-bottom:1px solid #4b5563;padding-bottom:4px;margin:15px 0 8px 0;">' . h($title) . '</h4>';
}

/**
 * Render a single experiment's sections (reused for each experiment in the project)
 */
function render_experiment($json_data, $experiment_id, $project_name) {
    $exp = $json_data->experiment ?? null;
    $title = $experiment_id ?: 'Untitled Experiment';
    if ($exp && !empty($exp->title)) $title = $exp->title;

    // Title
    echo '<div style="text-align:center;margin-bottom:1.5em;">';
    echo '<h3>' . h($title) . '</h3>';
    echo '<p style="color:#6c757d;">Project: ' . h($project_name) . '</p>';
    if ($exp && !empty($exp->id)) echo '<p style="color:#6c757d;">ID: ' . h($exp->id) . '</p>';
    if ($exp) {
        $start = $exp->start_date ?? '';
        $end = $exp->end_date ?? '';
        if ($start || $end) {
            $ds = $start;
            if ($end && $end !== $start) $ds .= ' to ' . $end;
            echo '<p style="color:#6c757d;">' . h($ds) . '</p>';
        }
    }
    echo '</div>';

    if ($exp && !empty($exp->description)) echo '<p>' . h($exp->description) . '</p>';

    if ($exp && !empty($exp->features) && is_array($exp->features)) {
        render_section_subheader('Test Features');
        echo '<p>' . h(implode(', ', $exp->features)) . '</p>';
    }

    if ($exp && !empty($exp->author)) {
        $author = $exp->author;
        render_section_subheader('Author');
        $a_str = trim(($author->firstname ?? '') . ' ' . ($author->lastname ?? ''));
        if (!empty($author->affiliation)) $a_str .= ' (' . $author->affiliation . ')';
        echo '<p>' . h($a_str) . '</p>';
        if (!empty($author->email)) echo '<p>Email: ' . h($author->email) . '</p>';
    }

    // Sample
    if (!empty($json_data->sample)) {
        $sample = $json_data->sample;
        render_section_header('Sample');
        echo '<table class="myDataTable"><tbody>';
        render_labeled_field('Name', $sample->name ?? '');
        render_labeled_field('ID', $sample->id ?? '');
        render_labeled_field('IGSN', $sample->igsn ?? '');
        render_labeled_field('Description', $sample->description ?? '');
        echo '</tbody></table>';

        if (!empty($sample->material->material)) {
            $mat = $sample->material->material;
            render_section_subheader('Material');
            echo '<table class="myDataTable"><tbody>';
            render_labeled_field('Type', $mat->type ?? '');
            render_labeled_field('Name', $mat->name ?? '');
            render_labeled_field('State', $mat->state ?? '');
            render_labeled_field('Note', $mat->note ?? '');
            echo '</tbody></table>';
        }

        if (!empty($sample->material->composition) && is_array($sample->material->composition)) {
            render_section_subheader('Mineralogy');
            echo '<div class="table-wrapper"><table class="myDataTable">';
            echo '<thead><tr><th>Mineral</th><th>Fraction</th><th>Unit</th><th>Grain Size</th></tr></thead><tbody>';
            foreach ($sample->material->composition as $comp) {
                echo '<tr><td>' . h($comp->mineral ?? '') . '</td>';
                echo '<td>' . h($comp->fraction ?? '') . '</td>';
                echo '<td>' . h($comp->unit ?? '') . '</td>';
                echo '<td>' . h(($comp->grainsize ?? '') . ($comp->grainsize ? ' um' : '')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    // Facility & Apparatus
    if (!empty($json_data->facility) || !empty($json_data->apparatus)) {
        render_section_header('Facility & Apparatus');

        if (!empty($json_data->facility)) {
            $fac = $json_data->facility;
            render_section_subheader('Facility');
            echo '<table class="myDataTable"><tbody>';
            render_labeled_field('Name', $fac->name ?? '');
            render_labeled_field('Institute', $fac->institute ?? '');
            render_labeled_field('Department', $fac->department ?? '');
            render_labeled_field('Type', $fac->type ?? '');
            render_labeled_field('Description', $fac->description ?? '');
            echo '</tbody></table>';
        }

        if (!empty($json_data->apparatus)) {
            $app = $json_data->apparatus;
            render_section_subheader('Apparatus');
            echo '<table class="myDataTable"><tbody>';
            render_labeled_field('Name', $app->name ?? '');
            render_labeled_field('Type', $app->type ?? '');
            render_labeled_field('ID', $app->id ?? '');
            render_labeled_field('Location', $app->location ?? '');
            render_labeled_field('Description', $app->description ?? '');
            if (!empty($app->features) && is_array($app->features)) {
                render_labeled_field('Features', implode(', ', $app->features));
            }
            echo '</tbody></table>';

            if (!empty($app->parameters) && is_array($app->parameters)) {
                echo '<div class="table-wrapper"><table class="myDataTable">';
                echo '<thead><tr><th>Type</th><th>Min</th><th>Max</th><th>Unit</th><th>Note</th></tr></thead><tbody>';
                foreach ($app->parameters as $param) {
                    echo '<tr><td>' . h($param->type ?? '') . '</td>';
                    echo '<td>' . h($param->min ?? '') . '</td>';
                    echo '<td>' . h($param->max ?? '') . '</td>';
                    echo '<td>' . h($param->unit ?? '') . '</td>';
                    echo '<td>' . h($param->note ?? '') . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        }
    }

    // DAQ
    if (!empty($json_data->daq)) {
        $daq = $json_data->daq;
        render_section_header('Data Acquisition (DAQ)');
        echo '<table class="myDataTable"><tbody>';
        render_labeled_field('Name', $daq->name ?? '');
        render_labeled_field('Type', $daq->type ?? '');
        render_labeled_field('Location', $daq->location ?? '');
        render_labeled_field('Description', $daq->description ?? '');
        echo '</tbody></table>';

        if (!empty($daq->devices) && is_array($daq->devices)) {
            foreach ($daq->devices as $idx => $device) {
                render_section_subheader('Device ' . ($idx + 1) . ': ' . ($device->name ?? 'Unknown'));
                if (!empty($device->channels) && is_array($device->channels)) {
                    echo '<div class="table-wrapper"><table class="myDataTable">';
                    echo '<thead><tr><th>Ch#</th><th>Type</th><th>Header</th><th>Unit</th><th>Sensor</th></tr></thead><tbody>';
                    foreach ($device->channels as $channel) {
                        $header = $channel->header ?? new stdClass();
                        $header_str = implode(' / ', array_filter([
                            $header->type ?? '', $header->spec_a ?? '', $header->spec_b ?? '', $header->spec_c ?? ''
                        ]));
                        $sensor = $channel->sensor ?? new stdClass();
                        $sensor_str = $sensor->detail ?? $sensor->type ?? '';
                        echo '<tr><td>' . h($channel->number ?? '') . '</td>';
                        echo '<td>' . h($channel->type ?? '') . '</td>';
                        echo '<td>' . h($header_str) . '</td>';
                        echo '<td>' . h($header->unit ?? '') . '</td>';
                        echo '<td>' . h($sensor_str) . '</td></tr>';
                    }
                    echo '</tbody></table></div>';
                }
            }
        }
    }

    // Protocol
    if ($exp && !empty($exp->protocol) && is_array($exp->protocol)) {
        render_section_header('Experimental Protocol');
        foreach ($exp->protocol as $idx => $step) {
            $step_title = 'Step ' . ($idx + 1);
            if (!empty($step->test)) $step_title .= ': ' . $step->test;
            echo '<h4 style="border-bottom:1px solid #4b5563;padding-bottom:4px;margin:15px 0 5px 0;">' . h($step_title) . '</h4>';
            echo '<table class="myDataTable"><tbody>';
            render_labeled_field('Objective', $step->objective ?? '');
            render_labeled_field('Description', $step->description ?? '');
            echo '</tbody></table>';
            if (!empty($step->parameters) && is_array($step->parameters)) {
                echo '<p style="font-weight:600;margin:5px 0;">Parameters:</p><ul>';
                foreach ($step->parameters as $param) {
                    $ps = ($param->control ?? '') . ': ' . ($param->value ?? '') . ' ' . ($param->unit ?? '');
                    if (!empty($param->note)) $ps .= ' - ' . $param->note;
                    echo '<li>' . h($ps) . '</li>';
                }
                echo '</ul>';
            }
        }
    }

    // Data
    if (!empty($json_data->data->datasets) && is_array($json_data->data->datasets)) {
        render_section_header('Data');
        foreach ($json_data->data->datasets as $idx => $dataset) {
            render_section_subheader('Dataset ' . ($idx + 1) . ': ' . ($dataset->data ?? 'Unknown'));
            echo '<table class="myDataTable"><tbody>';
            render_labeled_field('Type', $dataset->type ?? '');
            render_labeled_field('Format', $dataset->format ?? '');
            render_labeled_field('ID', $dataset->id ?? '');
            render_labeled_field('Rating', $dataset->rating ?? '');
            render_labeled_field('Description', $dataset->description ?? '');
            render_labeled_field('File', $dataset->path ?? '');
            echo '</tbody></table>';

            if (!empty($dataset->parameters) && is_array($dataset->parameters)) {
                echo '<div class="table-wrapper"><table class="myDataTable">';
                echo '<thead><tr><th>Parameter</th><th>Value</th><th>Error</th><th>Unit</th><th>Note</th></tr></thead><tbody>';
                foreach ($dataset->parameters as $param) {
                    echo '<tr><td>' . h($param->control ?? '') . '</td>';
                    echo '<td>' . h($param->value ?? '') . '</td>';
                    echo '<td>' . h($param->error ?? '') . '</td>';
                    echo '<td>' . h($param->unit ?? '') . '</td>';
                    echo '<td>' . h($param->note ?? '') . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
        }
    }
}

include("includes/mheader.php");
?>

<?php if ($from === 'mydata') { ?>
<a href="/my_experimental_data" class="button small" style="position:fixed;top:90px;left:20px;z-index:100;">&larr; Back to My Data</a>
<?php } ?>

<div id="main" class="wrapper style1">
    <div class="container">

        <header class="major">
            <h2>Project Overview</h2>
        </header>

        <section id="content">
            <div style="max-width:800px;margin:0 auto;">

                <!-- Download buttons -->
                <div style="text-align:right;margin-bottom:1em;">
                    <a href="/experimental/api/download_project.php?id=<?php echo $project_pkey; ?>" class="button small primary">Download JSON</a>
                    <a href="/experimental/api/download_project_pdf.php?id=<?php echo $project_pkey; ?>" class="button small">Download PDF</a>
                </div>

                <!-- Project Cover -->
                <div style="text-align:center;margin-bottom:2em;">
                    <h2><?php echo h($project_name); ?></h2>
                    <p style="color:#6c757d;">StraboExperimental Project Report</p>
                </div>

                <?php if (!empty($row->notes)) { ?>
                    <p><strong>Description:</strong> <?php echo h($row->notes); ?></p>
                <?php } ?>

                <table class="myDataTable"><tbody>
                    <?php
                    render_labeled_field('Created', $row->created_date);
                    render_labeled_field('Last Modified', $row->modified_date);
                    render_labeled_field('Experiments', count($exp_rows));
                    ?>
                </tbody></table>

                <?php if (count($exp_rows) > 0) { ?>
                    <h3 style="margin-top:2em;border-bottom:1px solid #4b5563;padding-bottom:6px;">Experiments in this Project</h3>
                    <ol>
                    <?php foreach ($exp_rows as $idx => $exp_row) { ?>
                        <li><a href="#exp-<?php echo $idx; ?>"><?php echo h($exp_row->experiment_id ?: 'Experiment ' . ($idx + 1)); ?></a></li>
                    <?php } ?>
                    </ol>
                <?php } ?>

                <!-- Render each experiment -->
                <?php foreach ($exp_rows as $idx => $exp_row) {
                    if (empty($exp_row->json)) continue;
                    $exp_data = json_decode($exp_row->json);
                    if (empty($exp_data)) continue;
                    // Overlay strabosamples.* spine edits onto the embedded
                    // sample so this public overview reflects Samples-app edits.
                    // Owner pkey is e.userpkey, the spine row's key.
                    require_once(__DIR__ . '/lib/sample_overlay.php');
                    experimental_sample_overlay_apply($exp_data, $db, (int)$exp_row->userpkey);
                ?>
                    <hr style="margin:3em 0;border-color:#4b5563;">
                    <div id="exp-<?php echo $idx; ?>">
                        <?php render_experiment($exp_data, $exp_row->experiment_id, $project_name); ?>
                    </div>
                <?php } ?>

            </div>
        </section>

        <div class="bottomSpacer"></div>

    </div>
</div>

<?php
include("includes/mfooter.php");
?>
