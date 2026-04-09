<?php
/**
 * File: stratSectionDetail/index.php
 * Description: Interactive stratigraphic section viewer powered by D3.js
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("../includes/mheader.php");

$spot_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>

<link rel="stylesheet" href="css/stratViewer.css" />

<!-- Main -->
<div id="main" class="wrapper style1">
    <div class="container">
        <header class="major">
            <h2>Stratigraphic Section Viewer</h2>
            <p id="strat-subtitle"></p>
        </header>

        <?php if ($spot_id === 0): ?>
            <section>
                <div class="strat-error">
                    <i class="fa fa-exclamation-triangle"></i>
                    <p>No spot ID provided. Please provide a valid spot ID in the URL.<br>
                    Example: <code>stratSectionDetail/?id=12345</code></p>
                </div>
            </section>
        <?php else: ?>
            <!-- Toolbar -->
            <section>
                <div id="strat-toolbar">
                    <div class="toolbar-group">
                        <button id="zoom-in" class="button small" title="Zoom In"><i class="fa fa-plus"></i></button>
                        <button id="zoom-out" class="button small" title="Zoom Out"><i class="fa fa-minus"></i></button>
                        <button id="zoom-reset" class="button small" title="Reset View"><i class="fa fa-expand"></i></button>
                    </div>
                    <div class="toolbar-group">
                        <button id="export-svg" class="button small" title="Download SVG"><i class="fa fa-download"></i> SVG</button>
                        <button id="export-png" class="button small" title="Download PNG"><i class="fa fa-image"></i> PNG</button>
                    </div>
                </div>
            </section>

            <!-- Loading State -->
            <div id="strat-loading">
                <div class="spinner"></div>
                <p>Loading stratigraphic section...</p>
            </div>

            <!-- Error State -->
            <div id="strat-error-state" style="display:none;">
                <div class="strat-error">
                    <i class="fa fa-exclamation-triangle"></i>
                    <p id="strat-error-message"></p>
                </div>
            </div>

            <!-- Viewer Container -->
            <div id="strat-viewer-wrapper">
                <div id="strat-viewer"></div>

                <!-- Detail Panel -->
                <div id="detail-panel">
                    <div id="detail-panel-header">
                        <h3 id="detail-panel-title">Unit Details</h3>
                        <button id="detail-panel-close" title="Close"><i class="fa fa-times"></i></button>
                    </div>
                    <div id="detail-panel-content"></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="bottomSpacer"></div>
    </div>
</div>

<?php if ($spot_id !== 0): ?>
<script src="https://d3js.org/d3.v7.min.js"></script>
<script>
    var STRAT_SPOT_ID = <?php echo $spot_id; ?>;
</script>
<script src="js/stratViewer.js"></script>
<?php endif; ?>

<?php include("../includes/mfooter.php"); ?>
