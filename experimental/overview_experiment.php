<?php
/**
 * File: overview_experiment.php
 * Description: HTML overview page for a single StraboExperimental experiment
 *              Mirrors the PDF download layout in browser-viewable format.
 *
 * Query params:
 *   u    - Experiment UUID (required)
 *   from - If "mydata", shows a back button to /my_experimental_data
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
$from = isset($_GET['from']) ? $_GET['from'] : '';

if (empty($experiment_uuid)) {
    include("includes/mheader.php");
?>
    <div id="main" class="wrapper style1">
        <div class="container">
            <header class="major"><h2>Experiment Overview</h2></header>
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
        SELECT e.pkey, e.project_pkey, e.id as experiment_id, e.uuid, e.json,
               e.userpkey as experiment_owner, p.name as project_name, p.uuid as project_uuid, p.ispublic
        FROM straboexp.experiment e
        LEFT JOIN straboexp.project p ON e.project_pkey = p.pkey
        WHERE e.uuid = $1
    ", array($experiment_uuid));
} else {
    $row = $db->get_row_prepared("
        SELECT e.pkey, e.project_pkey, e.id as experiment_id, e.uuid, e.json,
               e.userpkey as experiment_owner, p.name as project_name, p.uuid as project_uuid, p.ispublic
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
            <header class="major"><h2>Experiment Overview</h2></header>
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

// Parse experiment data
$json_data = !empty($row->json) ? json_decode($row->json) : null;
$experiment_id = $row->experiment_id ?: 'N/A';
$project_name = $row->project_name ?: 'N/A';
$pkey = (int)$row->pkey;

// Extract top-level info
$title = $experiment_id;
$description = '';
$start_date = '';
$end_date = '';
$features = [];
$author = null;

if ($json_data && !empty($json_data->experiment)) {
    $exp = $json_data->experiment;
    if (!empty($exp->title)) $title = $exp->title;
    if (!empty($exp->description)) $description = $exp->description;
    if (!empty($exp->start_date)) $start_date = $exp->start_date;
    if (!empty($exp->end_date)) $end_date = $exp->end_date;
    if (!empty($exp->features) && is_array($exp->features)) $features = $exp->features;
    if (!empty($exp->author)) $author = $exp->author;
}

// Helper functions for rendering
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

include("includes/mheader.php");
?>

<?php if ($from === 'mydata') { ?>
<a href="/my_experimental_data" class="button small" style="position:fixed;top:90px;left:20px;z-index:100;">&larr; Back to My Data</a>
<?php } ?>

<div id="main" class="wrapper style1">
    <div class="container">

        <header class="major">
            <h2>Experiment Overview</h2>
        </header>

        <section id="content">
            <div style="max-width:800px;margin:0 auto;">

                <!-- Download buttons -->
                <div style="text-align:right;margin-bottom:1em;">
                    <a href="/experimental/api/download_experiment.php?id=<?php echo $pkey; ?>" class="button small primary">Download JSON</a>
                    <a href="/experimental/api/download_pdf.php?id=<?php echo $pkey; ?>" class="button small">Download PDF</a>
                </div>

                <!-- Title Section -->
                <div style="text-align:center;margin-bottom:2em;">
                    <h2><?php echo h($title); ?></h2>
                    <?php if ($project_name !== 'N/A') { ?>
                        <p style="color:#6c757d;">Project: <?php echo h($project_name); ?></p>
                    <?php } ?>
                    <?php if (!empty($json_data->experiment->id)) { ?>
                        <p style="color:#6c757d;">ID: <?php echo h($json_data->experiment->id); ?></p>
                    <?php } ?>
                    <?php
                    if ($start_date || $end_date) {
                        $date_str = $start_date;
                        if ($end_date && $end_date !== $start_date) $date_str .= ' to ' . $end_date;
                        echo '<p style="color:#6c757d;">' . h($date_str) . '</p>';
                    }
                    ?>
                </div>

                <?php if (!empty($description)) { ?>
                    <p><?php echo h($description); ?></p>
                <?php } ?>

                <?php if (!empty($features)) { ?>
                    <?php render_section_subheader('Test Features'); ?>
                    <p><?php echo h(implode(', ', $features)); ?></p>
                <?php } ?>

                <?php if ($author) {
                    render_section_subheader('Author');
                    $author_str = trim(($author->firstname ?? '') . ' ' . ($author->lastname ?? ''));
                    if (!empty($author->affiliation)) $author_str .= ' (' . $author->affiliation . ')';
                    echo '<p>' . h($author_str) . '</p>';
                    if (!empty($author->email)) echo '<p>Email: ' . h($author->email) . '</p>';
                } ?>

                <!-- Sample Section -->
                <?php if ($json_data && !empty($json_data->sample)) {
                    $sample = $json_data->sample;
                    render_section_header('Sample');
                ?>
                    <table class="myDataTable"><tbody>
                        <?php
                        render_labeled_field('Name', $sample->name ?? '');
                        render_labeled_field('ID', $sample->id ?? '');
                        render_labeled_field('IGSN', $sample->igsn ?? '');
                        render_labeled_field('Description', $sample->description ?? '');
                        ?>
                    </tbody></table>

                    <?php if (!empty($sample->parent)) {
                        render_section_subheader('Parent Sample');
                    ?>
                        <table class="myDataTable"><tbody>
                            <?php
                            render_labeled_field('Name', $sample->parent->name ?? '');
                            render_labeled_field('ID', $sample->parent->id ?? '');
                            render_labeled_field('IGSN', $sample->parent->igsn ?? '');
                            render_labeled_field('Description', $sample->parent->description ?? '');
                            ?>
                        </tbody></table>
                    <?php } ?>

                    <?php if (!empty($sample->material->material)) {
                        $mat = $sample->material->material;
                        render_section_subheader('Material');
                    ?>
                        <table class="myDataTable"><tbody>
                            <?php
                            render_labeled_field('Type', $mat->type ?? '');
                            render_labeled_field('Name', $mat->name ?? '');
                            render_labeled_field('State', $mat->state ?? '');
                            render_labeled_field('Note', $mat->note ?? '');
                            ?>
                        </tbody></table>
                    <?php } ?>

                    <?php if (!empty($sample->material->composition) && is_array($sample->material->composition)) {
                        render_section_subheader('Mineralogy');
                    ?>
                        <div class="table-wrapper">
                        <table class="myDataTable">
                            <thead><tr><th>Mineral</th><th>Fraction</th><th>Unit</th><th>Grain Size</th></tr></thead>
                            <tbody>
                            <?php foreach ($sample->material->composition as $comp) { ?>
                                <tr>
                                    <td><?php echo h($comp->mineral ?? ''); ?></td>
                                    <td><?php echo h($comp->fraction ?? ''); ?></td>
                                    <td><?php echo h($comp->unit ?? ''); ?></td>
                                    <td><?php echo h(($comp->grainsize ?? '') . ($comp->grainsize ? ' um' : '')); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        </div>
                    <?php } ?>

                    <?php if (!empty($sample->material->provenance)) {
                        $prov = $sample->material->provenance;
                        render_section_subheader('Provenance');
                    ?>
                        <table class="myDataTable"><tbody>
                            <?php
                            render_labeled_field('Formation', $prov->formation ?? '');
                            render_labeled_field('Member', $prov->member ?? '');
                            render_labeled_field('Source', $prov->source ?? '');
                            if (!empty($prov->location)) {
                                $loc_parts = array_filter([$prov->location->city ?? '', $prov->location->state ?? '', $prov->location->country ?? '']);
                                if ($loc_parts) render_labeled_field('Location', implode(', ', $loc_parts));
                            }
                            ?>
                        </tbody></table>
                    <?php } ?>

                    <?php if (!empty($sample->material->texture)) {
                        $tex = $sample->material->texture;
                        render_section_subheader('Texture');
                    ?>
                        <table class="myDataTable"><tbody>
                            <?php
                            render_labeled_field('Bedding', $tex->bedding ?? '');
                            render_labeled_field('Lineation', $tex->lineation ?? '');
                            render_labeled_field('Foliation', $tex->foliation ?? '');
                            render_labeled_field('Fault', $tex->fault ?? '');
                            ?>
                        </tbody></table>
                    <?php } ?>

                    <?php if (!empty($sample->parameters) && is_array($sample->parameters)) {
                        render_section_subheader('Parameters');
                    ?>
                        <div class="table-wrapper">
                        <table class="myDataTable">
                            <thead><tr><th>Parameter</th><th>Value</th><th>Unit</th><th>Note</th></tr></thead>
                            <tbody>
                            <?php foreach ($sample->parameters as $param) { ?>
                                <tr>
                                    <td><?php echo h($param->control ?? ''); ?></td>
                                    <td><?php echo h($param->value ?? ''); ?></td>
                                    <td><?php echo h($param->unit ?? ''); ?></td>
                                    <td><?php echo h($param->note ?? ''); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        </div>
                    <?php } ?>
                <?php } ?>

                <!-- Facility & Apparatus Section -->
                <?php if ($json_data && (!empty($json_data->facility) || !empty($json_data->apparatus))) {
                    render_section_header('Facility & Apparatus');

                    if (!empty($json_data->facility)) {
                        $fac = $json_data->facility;
                        render_section_subheader('Facility');
                ?>
                        <table class="myDataTable"><tbody>
                            <?php
                            render_labeled_field('Name', $fac->name ?? '');
                            render_labeled_field('Institute', $fac->institute ?? '');
                            render_labeled_field('Department', $fac->department ?? '');
                            render_labeled_field('Type', $fac->type ?? '');
                            render_labeled_field('Description', $fac->description ?? '');
                            if (!empty($fac->address)) {
                                $addr_parts = array_filter([
                                    $fac->address->street ?? '', $fac->address->building ?? '',
                                    $fac->address->city ?? '', $fac->address->state ?? '',
                                    $fac->address->postcode ?? '', $fac->address->country ?? ''
                                ]);
                                if ($addr_parts) render_labeled_field('Address', implode(', ', $addr_parts));
                            }
                            if (!empty($fac->contact)) {
                                $contact_str = trim(($fac->contact->firstname ?? '') . ' ' . ($fac->contact->lastname ?? ''));
                                if (!empty($fac->contact->email)) $contact_str .= ' (' . $fac->contact->email . ')';
                                render_labeled_field('Contact', $contact_str);
                            }
                            ?>
                        </tbody></table>
                <?php
                    }

                    if (!empty($json_data->apparatus)) {
                        $app = $json_data->apparatus;
                        render_section_subheader('Apparatus');
                ?>
                        <table class="myDataTable"><tbody>
                            <?php
                            render_labeled_field('Name', $app->name ?? '');
                            render_labeled_field('Type', $app->type ?? '');
                            render_labeled_field('ID', $app->id ?? '');
                            render_labeled_field('Location', $app->location ?? '');
                            render_labeled_field('Description', $app->description ?? '');
                            if (!empty($app->features) && is_array($app->features)) {
                                render_labeled_field('Features', implode(', ', $app->features));
                            }
                            ?>
                        </tbody></table>

                        <?php if (!empty($app->parameters) && is_array($app->parameters)) { ?>
                            <div class="table-wrapper">
                            <table class="myDataTable">
                                <thead><tr><th>Type</th><th>Min</th><th>Max</th><th>Unit</th><th>Note</th></tr></thead>
                                <tbody>
                                <?php foreach ($app->parameters as $param) { ?>
                                    <tr>
                                        <td><?php echo h($param->type ?? ''); ?></td>
                                        <td><?php echo h($param->min ?? ''); ?></td>
                                        <td><?php echo h($param->max ?? ''); ?></td>
                                        <td><?php echo h($param->unit ?? ''); ?></td>
                                        <td><?php echo h($param->note ?? ''); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                            </div>
                        <?php } ?>
                <?php } ?>
                <?php } ?>

                <!-- Experimental Setup / Geometry Section -->
                <?php if ($json_data && !empty($json_data->experiment->geometry) && is_array($json_data->experiment->geometry)) {
                    render_section_header('Sample Assembly Geometry');
                    $geometry = $json_data->experiment->geometry;
                    usort($geometry, function($a, $b) { return ($a->order ?? 0) - ($b->order ?? 0); });

                    foreach ($geometry as $geom) {
                ?>
                    <h4 style="border-bottom:1px solid #4b5563;padding-bottom:4px;margin:10px 0 5px 0;">
                        <?php echo h(($geom->order ?? '') . '. ' . ($geom->type ?? 'Component')); ?>
                    </h4>
                    <table class="myDataTable"><tbody>
                        <?php
                        render_labeled_field('Geometry', $geom->geometry ?? '');
                        render_labeled_field('Material', $geom->material ?? '');
                        ?>
                    </tbody></table>
                    <?php if (!empty($geom->dimensions) && is_array($geom->dimensions)) { ?>
                        <p style="font-weight:600;margin:5px 0;">Dimensions:</p>
                        <ul>
                        <?php foreach ($geom->dimensions as $dim) {
                            $dim_str = ($dim->variable ?? '') . ': ' . ($dim->value ?? '') . ' ' . ($dim->unit ?? '');
                            if (!empty($dim->note)) $dim_str .= ' (' . $dim->note . ')';
                            echo '<li>' . h($dim_str) . '</li>';
                        } ?>
                        </ul>
                    <?php } ?>
                <?php } } ?>

                <!-- DAQ Section -->
                <?php if ($json_data && !empty($json_data->daq)) {
                    $daq = $json_data->daq;
                    render_section_header('Data Acquisition (DAQ)');
                ?>
                    <table class="myDataTable"><tbody>
                        <?php
                        render_labeled_field('Name', $daq->name ?? '');
                        render_labeled_field('Type', $daq->type ?? '');
                        render_labeled_field('Location', $daq->location ?? '');
                        render_labeled_field('Description', $daq->description ?? '');
                        ?>
                    </tbody></table>

                    <?php if (!empty($daq->devices) && is_array($daq->devices)) {
                        foreach ($daq->devices as $idx => $device) {
                            render_section_subheader('Device ' . ($idx + 1) . ': ' . ($device->name ?? 'Unknown'));

                            if (!empty($device->channels) && is_array($device->channels)) { ?>
                                <div class="table-wrapper">
                                <table class="myDataTable">
                                    <thead><tr><th>Ch#</th><th>Type</th><th>Header</th><th>Unit</th><th>Sensor</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($device->channels as $channel) {
                                        $header = $channel->header ?? new stdClass();
                                        $header_str = implode(' / ', array_filter([
                                            $header->type ?? '', $header->spec_a ?? '', $header->spec_b ?? '', $header->spec_c ?? ''
                                        ]));
                                        $sensor = $channel->sensor ?? new stdClass();
                                        $sensor_str = $sensor->detail ?? $sensor->type ?? '';
                                    ?>
                                        <tr>
                                            <td><?php echo h($channel->number ?? ''); ?></td>
                                            <td><?php echo h($channel->type ?? ''); ?></td>
                                            <td><?php echo h($header_str); ?></td>
                                            <td><?php echo h($header->unit ?? ''); ?></td>
                                            <td><?php echo h($sensor_str); ?></td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                                </div>
                            <?php }
                        }
                    } ?>
                <?php } ?>

                <!-- Protocol Section -->
                <?php if ($json_data && !empty($json_data->experiment->protocol) && is_array($json_data->experiment->protocol)) {
                    render_section_header('Experimental Protocol');

                    foreach ($json_data->experiment->protocol as $idx => $step) {
                        $step_title = 'Step ' . ($idx + 1);
                        if (!empty($step->test)) $step_title .= ': ' . $step->test;
                ?>
                    <h4 style="border-bottom:1px solid #4b5563;padding-bottom:4px;margin:15px 0 5px 0;">
                        <?php echo h($step_title); ?>
                    </h4>
                    <table class="myDataTable"><tbody>
                        <?php
                        render_labeled_field('Objective', $step->objective ?? '');
                        render_labeled_field('Description', $step->description ?? '');
                        ?>
                    </tbody></table>

                    <?php if (!empty($step->parameters) && is_array($step->parameters)) { ?>
                        <p style="font-weight:600;margin:5px 0;">Parameters:</p>
                        <ul>
                        <?php foreach ($step->parameters as $param) {
                            $param_str = ($param->control ?? '') . ': ' . ($param->value ?? '') . ' ' . ($param->unit ?? '');
                            if (!empty($param->note)) $param_str .= ' - ' . $param->note;
                            echo '<li>' . h($param_str) . '</li>';
                        } ?>
                        </ul>
                    <?php } ?>
                <?php } } ?>

                <!-- Data Section -->
                <?php if ($json_data && !empty($json_data->data->datasets) && is_array($json_data->data->datasets)) {
                    render_section_header('Data');

                    foreach ($json_data->data->datasets as $idx => $dataset) {
                        render_section_subheader('Dataset ' . ($idx + 1) . ': ' . ($dataset->data ?? 'Unknown'));
                ?>
                    <table class="myDataTable"><tbody>
                        <?php
                        render_labeled_field('Type', $dataset->type ?? '');
                        render_labeled_field('Format', $dataset->format ?? '');
                        render_labeled_field('ID', $dataset->id ?? '');
                        render_labeled_field('Rating', $dataset->rating ?? '');
                        render_labeled_field('Description', $dataset->description ?? '');
                        render_labeled_field('File', $dataset->path ?? '');
                        ?>
                    </tbody></table>

                    <?php if (!empty($dataset->parameters) && is_array($dataset->parameters)) { ?>
                        <div class="table-wrapper">
                        <table class="myDataTable">
                            <thead><tr><th>Parameter</th><th>Value</th><th>Error</th><th>Unit</th><th>Note</th></tr></thead>
                            <tbody>
                            <?php foreach ($dataset->parameters as $param) { ?>
                                <tr>
                                    <td><?php echo h($param->control ?? ''); ?></td>
                                    <td><?php echo h($param->value ?? ''); ?></td>
                                    <td><?php echo h($param->error ?? ''); ?></td>
                                    <td><?php echo h($param->unit ?? ''); ?></td>
                                    <td><?php echo h($param->note ?? ''); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        </div>
                    <?php } ?>

                    <?php if (!empty($dataset->headers) && is_array($dataset->headers)) { ?>
                        <p style="font-weight:600;margin:8px 0 4px 0;">Time Series Headers:</p>
                        <div class="table-wrapper">
                        <table class="myDataTable">
                            <thead><tr><th>Col#</th><th>Header</th><th>Type</th><th>Unit</th><th>Rating</th></tr></thead>
                            <tbody>
                            <?php foreach ($dataset->headers as $hdr) {
                                $header = $hdr->header ?? new stdClass();
                                $header_str = implode(' / ', array_filter([
                                    $header->header ?? '', $header->spec_a ?? '', $header->spec_b ?? '', $header->spec_c ?? ''
                                ]));
                            ?>
                                <tr>
                                    <td><?php echo h($hdr->number ?? ''); ?></td>
                                    <td><?php echo h($header_str); ?></td>
                                    <td><?php echo h($hdr->type ?? ''); ?></td>
                                    <td><?php echo h($header->unit ?? ''); ?></td>
                                    <td><?php echo h($hdr->rating ?? ''); ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        </div>
                    <?php } ?>
                <?php } } ?>

            </div>
        </section>

        <div class="bottomSpacer"></div>

    </div>
</div>

<?php
include("includes/mfooter.php");
?>
