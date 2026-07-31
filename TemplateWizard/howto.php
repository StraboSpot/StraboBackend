<?php
/**
 * File: howto.php
 * Description: Template Wizard - How-To guide for the long (one row per
 *              measurement) format. Walks through the permutations of
 *              entering spots with multi-dimensional data, each illustrated
 *              with rows from ONE worked example dataset. The same data
 *              renders the page tables AND builds the downloadable workbook
 *              (?demo=xlsx|csv) through the real template pipeline, so the
 *              guide and the file cannot drift apart.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir(dirname(__DIR__));
include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/services/FieldTabularService.php";

$twsvc = new FieldTabularService($db, $neodb, $strabo);
$twsvc->setUserpkey($userpkey);

// ---------------------------------------------------------------------------
// The worked example. Column order here == spec column order below.
//  0 strabo_internal_id  1 name  2 latitude  3 longitude  4 date  5 notes
//  6 orientation_type    7 orientation_role
//  8 feature_type  9 strike  10 dip  11 trend  12 plunge  13 quality
// 14 sample_id_name  15 main_sampling_purpose  16 sample_description
// ---------------------------------------------------------------------------
function tw_howto_spec($twsvc)
{
    $v = $twsvc->validateSpec(array('spec_version' => 1, 'layout' => 'long', 'columns' => array(
        array('kind' => 'system', 'key' => 'strabo_internal_id'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'name'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'latitude'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'longitude'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'date'),
        array('kind' => 'field', 'group' => 'spot', 'name' => 'notes'),
        array('kind' => 'system', 'key' => 'orientation_type'),
        array('kind' => 'system', 'key' => 'orientation_role'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'feature_type'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'strike'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'dip'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'trend'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'plunge'),
        array('kind' => 'field', 'group' => 'orientation', 'name' => 'quality'),
        array('kind' => 'field', 'group' => 'sample', 'name' => 'sample_id_name'),
        array('kind' => 'field', 'group' => 'sample', 'name' => 'main_sampling_purpose'),
        array('kind' => 'field', 'group' => 'sample', 'name' => 'sample_description'),
    )));
    return $v['spec'];
}

/**
 * The permutation blocks. Every row is a full 17-cell line of the example
 * file; 'show' lists the column indices the page table displays for that
 * block (the downloadable workbook always carries all columns).
 */
function tw_howto_blocks()
{
    return array(
        array(
            'title'   => '1. A simple spot &mdash; one row, no measurements',
            'explain' => 'The minimum for a <strong>new</strong> spot: a name plus latitude and longitude. '
                       . 'Leave <code>strabo_internal_id</code> blank &mdash; blank id means &ldquo;create a new spot&rdquo;. '
                       . '(Ids only come from files the wizard exported; never type one by hand.)',
            'show'    => array(0, 1, 2, 3, 4, 5),
            'rows'    => array(
                array('', 'Outcrop 1', '38.9581', '-95.2478', '2026-07-01', 'Roadcut on K-10; massive limestone.', '', '', '', '', '', '', '', '', '', '', ''),
            ),
        ),
        array(
            'title'   => '2. One measurement &mdash; spot and orientation share a row',
            'explain' => 'A spot with a single orientation is still just one row: fill the spot columns and the '
                       . 'orientation columns together. Every row that carries orientation values needs an '
                       . '<code>orientation_type</code> (<code>planar</code> / <code>linear</code> / <code>tabular_zone</code>).',
            'show'    => array(1, 6, 8, 9, 10, 13),
            'rows'    => array(
                array('', 'Outcrop 2', '38.9612', '-95.2431', '2026-07-01', 'Bedding attitude on the quarry wall.', 'planar', '', 'bedding', '245', '32', '', '', '5', '', '', ''),
            ),
        ),
        array(
            'title'   => '3. Several measurements &mdash; one row per measurement',
            'explain' => 'This is the heart of the format: <strong>repeat the spot name on every row</strong> and put each '
                       . 'measurement on its own row. Spot-level cells (coordinates, date, notes&hellip;) go on the first row; '
                       . 'on the other rows either leave them blank (recommended) or repeat them <em>exactly</em> &mdash; two '
                       . 'different values for the same spot is an error. Rows are grouped by the name/id, never by adjacency, '
                       . 'so sorting the sheet in Excel cannot break a spot apart. Vocabulary cells accept the dropdown label '
                       . '(&ldquo;joint&rdquo;) or the stored value &mdash; either works.',
            'show'    => array(1, 2, 3, 6, 8, 9, 10, 11, 12, 13),
            'rows'    => array(
                array('', 'Outcrop 3', '38.9640', '-95.2389', '2026-07-02', 'Fractured sandstone bench.', 'planar', '', 'bedding', '118', '24', '', '', '4', '', '', ''),
                array('', 'Outcrop 3', '', '', '', '', 'planar', '', 'joint', '200', '85', '', '', '3', '', '', ''),
                array('', 'Outcrop 3', '', '', '', '', 'linear', '', 'intersection', '', '', '37', '12', '4', '', '', ''),
            ),
        ),
        array(
            'title'   => '4. Associated orientations &mdash; a lineation measured on a plane',
            'explain' => 'To record that a linear feature was measured <em>on</em> a planar one (a stretching lineation on a '
                       . 'foliation, slickenlines on a fault&hellip;), add the <code>orientation_role</code> column: mark the plane '
                       . '<code>primary</code> and the lineation <code>associated</code>. An associated row attaches to the '
                       . 'nearest primary row <strong>above it</strong> within the same spot &mdash; this is the one place row '
                       . 'order matters, so keep each associated row directly under its plane. Rows without a role count as primary.',
            'show'    => array(1, 6, 7, 8, 9, 10, 11, 12),
            'rows'    => array(
                array('', 'Outcrop 4', '38.9688', '-95.2340', '2026-07-02', 'Gneiss with strong stretching fabric.', 'planar', 'primary', 'foliation', '152', '68', '', '', '5', '', '', ''),
                array('', 'Outcrop 4', '', '', '', '', 'linear', 'associated', 'stretching', '', '', '160', '45', '5', '', '', ''),
            ),
        ),
        array(
            'title'   => '5. Samples &mdash; on their own rows or sharing a row',
            'explain' => 'Any row with sample cells adds one sample to the spot. A row may carry an orientation '
                       . '<em>and</em> a sample at the same time (first row below), or just a sample (second row). '
                       . 'The same one-row-per-instance rule applies as for orientations.',
            'show'    => array(1, 6, 8, 9, 10, 14, 15, 16),
            'rows'    => array(
                array('', 'Outcrop 5', '38.9715', '-95.2302', '2026-07-03', 'Limestone ledge with thin ash bed.', 'planar', '', 'bedding', '300', '15', '', '', '4', 'KU-26-001', 'petrology', 'Fresh limestone hand sample.'),
                array('', 'Outcrop 5', '', '', '', '', '', '', '', '', '', '', '', '', 'KU-26-002', 'geochronology', 'Ash bed; zircon separation.'),
            ),
        ),
    );
}

// ---------------------------------------------------------------------------
// ?demo=xlsx|csv — the example file, built through the real export pipeline
// (band row, vocab dropdowns, locked id column), filled with the rows above.
// ---------------------------------------------------------------------------
$demo = isset($_GET['demo']) ? $_GET['demo'] : '';
if ($demo === 'xlsx' || $demo === 'csv') {
    $spec = tw_howto_spec($twsvc);
    $headers = array();
    foreach ($twsvc->columnDefs($spec) as $d) { $headers[] = $d['header']; }
    $rows = array();
    foreach (tw_howto_blocks() as $b) {
        foreach ($b['rows'] as $line) {
            $assoc = array();
            foreach ($headers as $i => $h) {
                if ($line[$i] !== '') { $assoc[$h] = $line[$i]; }
            }
            $rows[] = $assoc;
        }
    }
    $export = array('headers' => $headers, 'rows' => $rows, 'spec' => $spec);
    if ($demo === 'csv') {
        $csv = $twsvc->buildCsv($export);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="StraboSpot_HowTo_Example.csv"');
        header('Cache-Control: max-age=0');
        echo $csv;
        exit;
    }
    if (!class_exists('PHPExcel')) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/PHPExcel.php';
    }
    $wb = $twsvc->buildWorkbook($export, true, 'How-To Example');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="StraboSpot_HowTo_Example.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new PHPExcel_Writer_Excel2007($wb);
    $writer->save('php://output');
    exit;
}

// ---------------------------------------------------------------------------
// Page render helpers: band-colored tables matching the XLSX section band.
// ---------------------------------------------------------------------------
$twSpec = tw_howto_spec($twsvc);
$twDefs = $twsvc->columnDefs($twSpec);
$twHeaders = array();
foreach ($twDefs as $d) { $twHeaders[] = $d['header']; }
$twMeta = FieldTabularService::sectionMeta();

/** ARGB (PHPExcel) -> #RRGGBB CSS. */
function tw_css_color($argb) { return '#' . substr($argb, 2); }

/** Render one permutation table showing a subset of columns. */
function tw_render_block_table($block, $twHeaders, $twDefs, $twMeta)
{
    $show = $block['show'];
    $subsetHeaders = array();
    foreach ($show as $i) { $subsetHeaders[] = $twHeaders[$i]; }
    $subsetDefs = array();
    foreach ($show as $i) { $subsetDefs[] = $twDefs[$i]; }
    $runs = FieldTabularService::sectionRuns($subsetHeaders, $subsetDefs);

    echo '<div class="table-wrapper"><table class="tw-howto-table">';
    // Band row (same sections + colors as row 1 of the workbook)
    echo '<thead><tr>';
    foreach ($runs as $run) {
        $meta = $twMeta[$run['section']];
        $span = $run['end'] - $run['start'] + 1;
        echo '<th colspan="' . $span . '" style="background-color:' . tw_css_color($meta['fill'])
           . ';color:' . tw_css_color($meta['font']) . ';text-align:center;font-weight:bold;padding:0.3em 0.5em;">'
           . htmlspecialchars($meta['label']) . '</th>';
    }
    echo '</tr><tr>';
    foreach ($subsetHeaders as $h) {
        echo '<th style="white-space:nowrap;padding:0.3em 0.5em;">' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($block['rows'] as $line) {
        echo '<tr>';
        foreach ($show as $i) {
            $v = $line[$i];
            echo '<td style="white-space:nowrap;padding:0.3em 0.5em;">'
               . ($v === '' ? '' : htmlspecialchars($v)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

include("includes/mheader.php");
?>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Template Wizard How-To</h2>
							<p>Entering spots with multiple measurements</p>
						</header>

						<!-- Content -->
							<section id="content">

								<div style="background-color: #3b4252; color: #eceff4; padding: 10px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #5e81ac;">
									<strong>The one idea behind the format:</strong>
									<div style="margin: 10px 0 0 0; padding-left: 20px;">
										A spreadsheet row is <em>one measurement</em>, not one spot. A spot with three orientations takes
										three rows &mdash; each carrying the spot&rsquo;s name so the wizard knows they belong together.
										Everything else on this page is a variation on that idea. All the examples below come from one
										small worked dataset (each table shows just the columns that matter for its example &mdash; the
										downloadable file carries them all):
									</div>
									<ul class="actions" style="margin: 15px 0 5px 20px;">
										<li><a href="howto.php?demo=xlsx" class="button small primary">&#8681; Download the filled example (.xlsx)</a></li>
										<li><a href="howto.php?demo=csv" class="button small">CSV version</a></li>
									</ul>
								</div>

<?php $twBlocks = tw_howto_blocks(); foreach ($twBlocks as $b): ?>
								<h3 style="margin-top: 1.5em;"><?php echo $b['title']; ?></h3>
								<p><?php echo $b['explain']; ?></p>
								<?php tw_render_block_table($b, $twHeaders, $twDefs, $twMeta); ?>
<?php endforeach; ?>

								<hr />

								<h3>The rules, in one place</h3>
								<ul>
									<li><strong>One row per measurement.</strong> Repeat the spot&rsquo;s name (or its <code>strabo_internal_id</code>) on every row of the spot.</li>
									<li><strong>Spot-level columns must agree.</strong> Name, coordinates, date, notes and other once-per-spot values: fill them on one row and leave the rest blank, or repeat them identically. Two different values for the same spot is an error the review screen will point at.</li>
									<li><strong>Row order doesn&rsquo;t matter</strong> &mdash; grouping is by name/id, so Excel sorting is safe. The single exception: an <code>associated</code> orientation attaches to the nearest <code>primary</code> row above it within the same spot, so keep those pairs together.</li>
									<li><strong>New spots</strong> need a name, latitude and longitude, and a blank id. <strong>Updates</strong> (files you exported, then edited) carry the spot&rsquo;s id on every row &mdash; leave that column alone and the wizard matches rows to existing spots.</li>
									<li><strong>Vocabulary cells</strong> accept the dropdown label or the stored value, case-insensitive. Anything unrecognized isn&rsquo;t rejected outright &mdash; the review screen lets you map it, keep it as free text, or fix it in the file.</li>
									<li><strong>The id column is locked</strong> in Excel and LibreOffice. Some spreadsheet apps (Apple Numbers among them) ignore protection entirely &mdash; don&rsquo;t type in <code>strabo_internal_id</code> there. The wizard re-validates every id at review regardless, so a stray edit is caught before anything imports.</li>
									<li><strong>Nothing imports until the file is clean.</strong> Upload &rarr; review &rarr; confirm; errors block the whole file, so a half-imported dataset can&rsquo;t happen.</li>
								</ul>

								<p style="font-size: 0.9em;">The example file downloads with the same locked columns and dropdown
									validation as any wizard template &mdash; you can even import it as-is into a scratch dataset to
									watch the review screen group the rows: 5 new spots, 6 orientations (one carrying an associated
									lineation), 2 samples.</p>

								<hr />

								<ul class="actions">
									<li><a href="index.php" class="button">Back to Wizard</a></li>
									<li><a href="review.php" class="button">&#8682; Import a Spreadsheet</a></li>
								</ul>

							</section>
					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include("includes/mfooter.php");
?>
