<?php
/**
 * File: design_template.php
 * Description: Template Wizard - Template Designer (column list builder,
 *              2026-09-18 rewrite; replaces the Handsontable grid).
 *              A template is an ordered list of columns. The page shows
 *              the list (reorder, remove, custom headers), the StraboField
 *              catalog to add from, and a read-only preview of the sheet.
 *              Columns come from the saved template (GET template_id) or
 *              the catalog defaults for the sections chosen on the landing
 *              page (POST template_method=new + selected_sections[]).
 *              Save persists the template (ajax.php save_template) and
 *              returns to the landing page; Download blank saves first,
 *              then streams the fillable workbook. Nothing here imports
 *              data: that is the Import page's job.
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

// ---- Which template, or which starting sections ----
$template_method   = isset($_POST['template_method']) ? $_POST['template_method'] : (isset($_GET['template_id']) ? 'existing' : 'new');
$template_id       = isset($_POST['template_id']) ? $_POST['template_id'] : (isset($_GET['template_id']) ? $_GET['template_id'] : '');
$selected_sections = isset($_POST['selected_sections']) ? $_POST['selected_sections'] : array('spot', 'orientation');

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

// ---- Hints shown beside each field: type, range, dropdown size, applicability ----
function tw_field_hint($f)
{
    $bits = array();
    if (!empty($f['vocab'])) {
        $bits[] = 'dropdown, ' . count($f['vocab']) . ' values';
    } elseif (isset($f['type'])) {
        $t = $f['type'];
        if ($t === 'integer' || $t === 'decimal') {
            $range = '';
            if (isset($f['constraint']['min']) && isset($f['constraint']['max'])) {
                $range = ' ' . $f['constraint']['min'] . ' to ' . $f['constraint']['max'];
            }
            $bits[] = 'number' . $range;
        } elseif ($t === 'date') {
            $bits[] = 'date';
        } elseif ($t === 'boolean') {
            $bits[] = 'yes / no';
        } else {
            $bits[] = 'text';
        }
    }
    if (!empty($f['applies_to']) && is_array($f['applies_to']) && count($f['applies_to']) < 3) {
        $bits[] = implode(' / ', $f['applies_to']) . ' only';
    }
    return implode('; ', $bits);
}

$systemMeta = array(
    'strabo_internal_id' => array('label' => 'StraboSpot id',     'hint' => 'locked; filled by StraboSpot on export, blank for new spots'),
    'orientation_type'   => array('label' => 'Orientation type',  'hint' => 'planar / linear / tabular zone; required with orientation columns'),
    'orientation_role'   => array('label' => 'Orientation role',  'hint' => 'primary / associated; lets a row attach to the measurement above it'),
    'geometry_type'      => array('label' => 'Geometry type',     'hint' => 'export context for line / polygon spots; filled by StraboSpot'),
    'geometry_wkt'       => array('label' => 'Geometry (WKT)',    'hint' => 'full line / polygon shape as WKT; filled on export, used when a spot is created (ignored on updates)'),
);

// Current columns, in order, as the page shows them.
$current = array();
foreach ($twsvc->columnDefs($spec) as $d) {
    if ($d['kind'] === 'system') {
        $current[] = array('kind' => 'system', 'key' => $d['key'], 'header' => $d['header'],
                           'label' => $systemMeta[$d['key']]['label'], 'section' => 'system',
                           'hint' => $systemMeta[$d['key']]['hint']);
    } elseif ($d['kind'] === 'field') {
        $current[] = array('kind' => 'field', 'group' => $d['group'], 'name' => $d['name'], 'header' => $d['header'],
                           'label' => isset($d['def']['label']) ? $d['def']['label'] : $d['name'], 'section' => $d['group'],
                           'hint' => is_array($d['def']) ? tw_field_hint($d['def']) : '');
    } else {
        $current[] = array('kind' => 'custom', 'header' => $d['header'], 'label' => $d['header'],
                           'section' => 'custom', 'hint' => 'custom field on the spot');
    }
}

// The catalog to add from, grouped by section.
$catalogAll = FieldTabularService::catalog();
$catalogGroups = array();
foreach ($catalogAll['groups'] as $gkey => $g) {
    $fields = array();
    foreach ($g['fields'] as $f) {
        $fields[] = array(
            'name'   => $f['name'],
            'header' => FieldTabularService::displayHeader($gkey, $f['name']),
            'label'  => isset($f['label']) ? $f['label'] : $f['name'],
            'hint'   => tw_field_hint($f),
        );
    }
    $catalogGroups[] = array('key' => $gkey, 'label' => $g['label'], 'fields' => $fields);
}
$systemExtras = array(
    array('key' => 'orientation_role', 'header' => 'orientation_role', 'label' => $systemMeta['orientation_role']['label'], 'hint' => $systemMeta['orientation_role']['hint']),
    array('key' => 'geometry_type',    'header' => 'geometry_type',    'label' => $systemMeta['geometry_type']['label'],    'hint' => $systemMeta['geometry_type']['hint']),
    array('key' => 'geometry_wkt',     'header' => 'geometry_wkt',     'label' => $systemMeta['geometry_wkt']['label'],     'hint' => $systemMeta['geometry_wkt']['hint']),
);
$sectionLabels = array();
foreach (FieldTabularService::sectionMeta() as $k => $m) { $sectionLabels[$k] = $m['label']; }

include("includes/mheader.php");
?>

<link rel="stylesheet" href="css/template_designer.css?v=<?php echo filemtime(__DIR__ . '/css/template_designer.css'); ?>">

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Template Design</h2>
							<p>Choose the columns your spreadsheets carry.</p>
						</header>

						<!-- Content -->
							<section id="content">

								<div class="tw-intro">
									<p>
										A template is an ordered list of columns. Add StraboField fields from the catalog on the right, drag
										or use the arrows to reorder, and remove what you do not need. The preview shows the spreadsheet you
										will get; spots with several measurements take one row each (<a href="howto.php">how-to</a>).
									</p>
								</div>

								<!-- Name + actions -->
								<div class="row gtr-uniform gtr-25 tw-toolbar">
									<div class="col-6 col-12-medium">
										<label for="template_name" class="tw-label">Template name</label>
										<input type="text" id="template_name" name="template_name" placeholder="Enter template name" maxlength="120" value="<?php echo htmlspecialchars($template_name); ?>" />
									</div>
									<div class="col-6 col-12-medium tw-toolbar-actions">
										<ul class="actions">
											<li><a href="#" id="tw-save" class="button primary" aria-disabled="true"><?php echo ($template_method === 'existing') ? 'Save changes' : 'Save template'; ?></a></li>
											<li><a href="#" id="tw-download" class="button" aria-disabled="true" title="Save, then download a blank, fillable spreadsheet">Download blank</a></li>
											<li><a href="index.php" id="tw-cancel" class="button">Cancel</a></li>
										</ul>
									</div>
								</div>
								<div id="tw-status" class="tw-status" role="status" aria-live="polite"></div>

								<!-- Builder -->
								<div class="row gtr-25 tw-builder">
									<div class="col-7 col-12-medium">
										<h3 class="tw-pane-title">Columns in this template <span id="tw-count" class="tw-count"></span></h3>
										<ol id="tw-columns" class="tw-columns" aria-label="Template columns"></ol>
										<p class="tw-hint">Drag the handle or use the arrows to reorder. The StraboSpot id column stays first; the orientation type column is placed before the first orientation field automatically.</p>
									</div>
									<div class="col-5 col-12-medium">
										<h3 class="tw-pane-title">Add columns</h3>
										<input type="text" id="tw-filter" placeholder="Filter fields&hellip;" aria-label="Filter fields" />
										<div id="tw-catalog" class="tw-catalog"></div>
										<div class="tw-custom">
											<label for="tw-custom-header" class="tw-label">Custom column</label>
											<div class="tw-custom-row">
												<input type="text" id="tw-custom-header" placeholder="Column header, e.g. weathering_grade" maxlength="80" />
												<a href="#" id="tw-custom-add" class="button small">Add</a>
											</div>
											<p class="tw-hint">Custom columns import as custom fields on the spot.</p>
										</div>
									</div>
								</div>

								<!-- Preview -->
								<h3 class="tw-pane-title">Spreadsheet preview</h3>
								<p class="tw-hint">Header rows as they will appear, with an example spot carrying two measurements.</p>
								<div class="table-wrapper tw-preview-wrap">
									<table id="tw-preview" class="tw-preview"></table>
								</div>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<script>
window.twDesigner = <?php echo json_encode(array(
    'method'   => $template_method,
    'pkey'     => (string)$template_pkey,
    'name'     => $template_name,
    'columns'  => $current,
    'catalog'  => $catalogGroups,
    'system'   => $systemExtras,
    'sections' => $sectionLabels,
    'systemMeta' => $systemMeta,
)); ?>;
</script>
<script src="js/design_template.js?v=<?php echo filemtime(__DIR__ . '/js/design_template.js'); ?>"></script>

<?php
include("includes/mfooter.php");
?>
