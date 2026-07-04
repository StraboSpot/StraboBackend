<?php
/**
 * File: design_template.php
 * Description: Template Wizard - Template Designer with HandsonTable (Page 2)
 *              Columns come from the saved template (method=existing) or the
 *              schema-derived catalog defaults for the chosen sections
 *              (method=new). Data can be pasted straight into the grid or
 *              loaded from a file; Save persists the template (field_templates)
 *              and, when the grid holds data, continues to the review screen.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir(dirname(__DIR__));
include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/services/FieldTabularService.php";

$twsvc = new FieldTabularService($db, $neodb, $strabo);
$twsvc->setUserpkey($userpkey);

// ---- Get POST data from Page 1 ----
$template_method   = isset($_POST['template_method']) ? $_POST['template_method'] : (isset($_GET['template_id']) ? 'existing' : 'new');
$template_id       = isset($_POST['template_id']) ? $_POST['template_id'] : (isset($_GET['template_id']) ? $_GET['template_id'] : '');
$selected_sections = isset($_POST['selected_sections']) ? $_POST['selected_sections'] : array('spot', 'orientation');

// ---- Resolve the working template spec ----
$template_name = '';
$template_pkey = '';
$spec = null;

if ($template_method === 'existing' && $template_id !== '') {
    $tpl = $twsvc->getTemplate((int)$template_id);
    if ($tpl !== null) {
        $spec = $tpl['spec'];
        $template_name = $tpl['name'];
        $template_pkey = $tpl['pkey'];
    }
}

if ($spec === null) {
    $template_method = 'new';
    // Curated starter columns per section, from the schema-derived catalog.
    $catalog = FieldTabularService::catalog();
    $preferred = array(
        'spot'           => array('name', 'latitude', 'longitude', 'altitude', 'date', 'notes', 'gps_accuracy'),
        'orientation'    => array('feature_type', 'strike', 'dip', 'dip_direction', 'trend', 'plunge', 'rake', 'quality'),
        'geologic_unit'  => array(),   // first 6 from the form
        'trace'          => array(),   // first 5 from the form
        'other_features' => array('label', 'name', 'type', 'description'),
        'sample'         => array('sample_id_name', 'label', 'sample_type', 'material_type', 'main_sampling_purpose', 'sample_description'),
    );
    $cols = array(
        array('kind' => 'system', 'key' => 'strabo_internal_id'),
    );
    $sections = array_values(array_unique(array_merge(array('spot'), (array)$selected_sections)));
    foreach ($sections as $section) {
        if (!isset($catalog['groups'][$section])) { continue; }
        $available = array();
        foreach ($catalog['groups'][$section]['fields'] as $f) { $available[$f['name']] = true; }
        $want = $preferred[$section];
        if (empty($want)) {
            $want = array_slice(array_keys($available), 0, ($section === 'geologic_unit') ? 6 : 5);
        }
        if ($section === 'orientation') {
            $cols[] = array('kind' => 'system', 'key' => 'orientation_type');
            $cols[] = array('kind' => 'system', 'key' => 'orientation_role');
        }
        foreach ($want as $name) {
            if (isset($available[$name])) {
                $cols[] = array('kind' => 'field', 'group' => $section, 'name' => $name);
            }
        }
    }
    $v = $twsvc->validateSpec(array('spec_version' => 1, 'layout' => 'long', 'columns' => $cols));
    $spec = $v['spec'];
}

// Ordered headers + parallel descriptors for the grid.
$colDefs = $twsvc->columnDefs($spec);
$columns = array();
$specColumns = array();
foreach ($colDefs as $d) {
    $columns[] = $d['header'];
    if ($d['kind'] === 'system') {
        $specColumns[] = array('kind' => 'system', 'key' => $d['key'], 'header' => $d['header']);
    } elseif ($d['kind'] === 'field') {
        $specColumns[] = array('kind' => 'field', 'group' => $d['group'], 'name' => $d['name'], 'header' => $d['header']);
    } else {
        $specColumns[] = array('kind' => 'custom', 'header' => $d['header']);
    }
}

// Every known display header => descriptor (for mapping grid headers back to
// catalog fields when the user adds columns or edits a file offline).
$headerMap = array(
    'strabo_internal_id' => array('kind' => 'system', 'key' => 'strabo_internal_id'),
    'geometry_type'      => array('kind' => 'system', 'key' => 'geometry_type'),
    'orientation_type'   => array('kind' => 'system', 'key' => 'orientation_type'),
    'orientation_role'   => array('kind' => 'system', 'key' => 'orientation_role'),
);
$catalogGroups = array();   // group => {label, fields: [{name, header, label}]}
// Per-column grid behavior: dropdown vocab (strict for structural columns,
// tolerant for catalog vocab — off-list values flag red and resolve at
// review) and numeric constraints (red-flagged). Jason 2026-07-03.
$columnVocab = array(
    'orientation_type' => array('strict' => true, 'values' => array('planar', 'linear', 'tabular_zone')),
    'orientation_role' => array('strict' => true, 'values' => array('primary', 'associated')),
);
$catalogAll = FieldTabularService::catalog();
foreach ($catalogAll['groups'] as $gkey => $g) {
    $entry = array('label' => $g['label'], 'fields' => array());
    foreach ($g['fields'] as $f) {
        $h = FieldTabularService::displayHeader($gkey, $f['name']);
        $headerMap[$h] = array('kind' => 'field', 'group' => $gkey, 'name' => $f['name']);
        $entry['fields'][] = array('name' => $f['name'], 'header' => $h, 'label' => $f['label']);

        $cv = array();
        if (isset($f['vocab']) && count($f['vocab'])) {
            $cv['strict'] = false;
            $labels = array();
            foreach ($f['vocab'] as $vv) { $labels[] = $vv['label']; }
            $cv['values'] = $labels;
            if (isset($f['vocab_by_type'])) {
                foreach ($f['vocab_by_type'] as $otype => $tv) {
                    $tl = array();
                    foreach ($tv as $vv) { $tl[] = $vv['label']; }
                    $cv['by_type'][$otype] = $tl;
                }
            }
        } elseif (in_array($f['type'], array('integer', 'decimal'))) {
            $cv['numeric'] = $f['type'];
            if (isset($f['constraint']['min'])) { $cv['min'] = $f['constraint']['min']; }
            if (isset($f['constraint']['max'])) { $cv['max'] = $f['constraint']['max']; }
        }
        if (!empty($cv)) { $columnVocab[$h] = $cv; }
    }
    $catalogGroups[$gkey] = $entry;
}

// Real projects for the "upload data to" picker.
$myProjects = $twsvc->myProjects();

include("includes/mheader.php");
?>

<!-- HandsonTable CSS (local 6.2.2 — last MIT-licensed release) -->
<link rel="stylesheet" href="assets/handsontable.full.min.css">
<link rel="stylesheet" href="css/template_designer.css?v=<?php echo filemtime(__DIR__ . '/css/template_designer.css'); ?>">

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Template Design</h2>
						</header>

						<!-- Content -->
							<section id="content">

								<!-- Hidden file input -->
								<input type="file" id="fileInput" accept=".csv,.xlsx,.xls,.tsv" style="display: none;" />

								<!-- Instructions -->
								<div style="background-color: #3b4252; color: #eceff4; padding: 10px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #5e81ac;">
									<strong>Instructions:</strong>
									<ul style="margin: 10px 0 0 0; padding-left: 20px;">
										<li><strong>Reorder columns if desired:</strong> Click a column header once to select it, then drag to move it.</li>
										<li><strong>Add columns:</strong> Pick a StraboField column below, or right-click a header to insert a blank column &mdash; type its header to make it a "custom" column.</li>
										<li><strong>Remove columns:</strong> Right-click a column header and choose "Remove column".</li>
										<li><strong>One row per measurement:</strong> a spot with several orientations takes several rows &mdash; repeat the spot name on each of its rows.</li>
										<li><strong>Get your data in:</strong> paste from Excel/Sheets, <a href="#" id="uploadFileLink">load a file into the grid</a>, or <a href="#" id="downloadTemplateLink">download this template</a> to fill in offline (offline files upload on the <a href="review.php">Import page</a>).</li>
										<li><strong>Save:</strong> stores the template design. With data in the grid, Save continues to a review of every change before anything is written.</li>
									</ul>
								</div>

								<!-- Template Name and Save Button -->
								<div class="row" style="margin-bottom: 20px;">
									<div class="col-2 col-12-small gtr-25">
										<div>Template Name <span class="highlighted">*</span></div>
									</div>
									<div class="col-7 col-12-small gtr-25">
										<input type="text" id="template_name" name="template_name" placeholder="Enter template name" value="<?php echo htmlspecialchars($template_name); ?>" />
									</div>
									<div class="col-3 col-12-small gtr-25" id="saveSection" style="display: none;">
										<ul class="actions fit" style="margin-bottom: 0px;">
											<li><a id="saveBtn" class="button primary fit"><?php echo ($template_method === 'existing') ? 'Save Changes' : 'Save'; ?></a></li>
										</ul>
									</div>
								</div>

								<!-- Add catalog column -->
								<div class="row" style="margin-bottom: 20px;">
									<div class="col-2 col-12-small gtr-25">
										<div>Add Column</div>
									</div>
									<div class="col-7 col-12-small gtr-25">
										<select id="add_column_select">
											<option value="">-- StraboField columns --</option>
											<optgroup label="Wizard columns">
												<option value="orientation_type">Orientation Type (orientation_type)</option>
												<option value="orientation_role">Orientation Role — for associated orientations (orientation_role)</option>
												<option value="geometry_type">Geometry Type — export context, filled by StraboSpot (geometry_type)</option>
											</optgroup>
											<?php foreach ($catalogGroups as $gkey => $g): ?>
											<optgroup label="<?php echo htmlspecialchars($g['label']); ?>">
												<?php foreach ($g['fields'] as $f): ?>
												<option value="<?php echo htmlspecialchars($f['header']); ?>"><?php echo htmlspecialchars($f['label']); ?> (<?php echo htmlspecialchars($f['header']); ?>)</option>
												<?php endforeach; ?>
											</optgroup>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-3 col-12-small gtr-25">
										<ul class="actions fit" style="margin-bottom: 0px;">
											<li><a id="addColumnBtn" class="button fit">Add</a></li>
										</ul>
									</div>
								</div>

								<!-- Project to Save -->
								<div id="project_info" class="row" style="margin-bottom: 20px;display: none;">
									<div class="col-2 col-12-small gtr-25">
										<div>Strabo Project <span class="highlighted">*</span></div>
									</div>
									<div class="col-7 col-12-small gtr-25">
										<select name="project_id" id="project_id">
											<option value="">Please Select Project to Save Data...</option>
											<?php foreach ($myProjects as $p): ?>
											<option value="<?php echo htmlspecialchars($p['id']); ?>"><?php echo htmlspecialchars($p['name']); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>

								<!-- Error Modal -->
								<div id="errorModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); align-items: center; justify-content: center;">
									<div style="background-color: #2e3440; padding: 30px; border-radius: 5px; width: 90%; max-width: 400px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); position: relative;">
										<h3 id="modalTitle" style="margin-top: 0; color: #bf616a;">Error</h3>
										<p id="errorMessage" style="margin-bottom: 20px; color: #eceff4;">Template name is required</p>
										<button id="closeModal" class="button primary" style="width: 100%;">OK</button>
									</div>
								</div>

								<!-- HandsonTable Container -->
								<div id="hot-container"></div>

								<!-- Hidden form for POST submission to the review screen -->
								<form id="submitForm" method="POST" action="review.php" style="display:none;">
									<input type="hidden" name="action" value="stage">
									<input type="hidden" name="template_pkey" id="hidden_template_pkey">
									<input type="hidden" name="template_name" id="hidden_template_name">
									<input type="hidden" name="project_id" id="hidden_project_id">
									<input type="hidden" name="spec_json" id="hidden_spec_json">
									<input type="hidden" name="grid_json" id="hidden_grid_json">
								</form>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<!-- HandsonTable JS (local 6.2.2 — last MIT-licensed release) -->
<script src="assets/handsontable.full.min.js"></script>

<!-- SheetJS for Excel/CSV parsing (load-into-grid) -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>

<script>
// Pass PHP data to JavaScript
window.templateMethod  = '<?php echo $template_method; ?>';
window.templateColumns = <?php echo json_encode($columns); ?>;
window.templateSpecCols = <?php echo json_encode($specColumns); ?>;
window.templatePkey    = '<?php echo htmlspecialchars($template_pkey); ?>';
window.headerMap       = <?php echo json_encode($headerMap); ?>;
window.columnVocab     = <?php echo json_encode($columnVocab); ?>;
window.sectionMeta     = <?php
    $sm = array();
    foreach (FieldTabularService::sectionMeta() as $k => $m) { $sm[$k] = array('label' => $m['label']); }
    echo json_encode($sm);
?>;
</script>
<script src="js/design_template.js?v=<?php echo filemtime(__DIR__ . '/js/design_template.js'); ?>"></script>

<?php
include("includes/mfooter.php");
?>
