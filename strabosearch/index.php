<?php
/**
 * File: index.php
 * Description: StraboSpot Search (StraboSearch v1) — single page housing
 *              the §6 criteria builder + two-pathway results. Lives at
 *              /strabosearch/ (NOT the design doc's original /search —
 *              that URL is owned by the live "Search Strabo Field Data"
 *              map viewer; relocation decided with Jason 2026-08-02).
 *              Anonymous visitors are served (public-data search,
 *              §5.5.3), so this page deliberately does NOT include
 *              logincheck.php; save/saved-searches controls are
 *              login-gated server-side below. All data flows through the
 *              session proxy (/strabosearch/api.php) into the Phase 3
 *              service layer.
 *
 * @package    StraboSpot Web Site — StraboSearch
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("../includes/mheader.php");

$searchLoggedIn = (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === 'yes'
    && isset($_SESSION['userpkey']) && (int)$_SESSION['userpkey'] > 0);

// mtime cache-buster: no Cache-Control headers are sent for static assets,
// so browsers (mobile Chrome especially) heuristic-cache CSS/JS and a plain
// reload serves stale layout. ?v=<mtime> makes every edit a new URL.
function ss_asset($path) {
    $mtime = @filemtime(__DIR__ . '/../' . ltrim($path, '/'));
    return htmlspecialchars($path . ($mtime ? '?v=' . $mtime : ''));
}
?>

			<link rel="stylesheet" href="/assets/js/featherlight/featherlight.css" />
			<link rel="stylesheet" href="/assets/js/leaflet/leaflet.css" />
			<link rel="stylesheet" href="<?php echo ss_asset('/strabosearch/css/search.css'); ?>" />

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>StraboSpot Search</h2>
						</header>

						<div id="loadingScreen" style="display:none;">
							<div class="grayOut" style="display:inline;"></div>
							<div id="loadingmessage" style="text-align:center;display:block;">
								<div class="loader" style="margin-left: 100px;"></div>
								<div id="loadingText">Searching. Please wait...</div>
							</div>
						</div>

						<!-- Criteria builder (§6.3) — rows rendered by builder.js -->
						<div id="criteriaBuilder" class="ss-criteria" aria-label="Search criteria"></div>

						<!-- Action row (§6.2.3) -->
						<div class="ss-action-row">
							<ul class="actions fit">
								<li><a href="javascript:void(0);" id="ssSearchBtn" class="button primary fit">Search</a></li>
<?php if ($searchLoggedIn) { ?>
								<li><a href="javascript:void(0);" id="ssMySearchesBtn" class="button fit">My searches &#9662;</a></li>
								<li><a href="javascript:void(0);" id="ssSaveBtn" class="button fit">Save current</a></li>
<?php } ?>
							</ul>
						</div>

						<hr />

						<!-- Results region (§6.5) — rendered by results.js -->
						<div id="ssAnonNote" style="display:none;" class="ss-anon-note">
							Sign in to also search private projects you own or collaborate on.
							<a href="javascript:void(0);" id="ssAnonNoteDismiss" aria-label="Dismiss note">&times;</a>
						</div>
						<div id="ssResults" class="ss-results">
							<div class="ss-quiet-prompt">Compose a search above to see results.</div>
						</div>

						<div class="bottomSpacer"></div>

					</div>
				</div>

				<script>
				window.STRABO_SEARCH = {
					loggedIn: <?php echo $searchLoggedIn ? 'true' : 'false'; ?>,
					api: '/strabosearch/api.php',
					thumb: '/strabosearch/thumb.php',
					icons: {
						field: '/strabosearch/images/pickaxe.png',
						micro: '/strabosearch/images/microscope.png',
						exp:   '/strabosearch/images/beaker.png'
					},
					landing: { field: '/fpl/', micro: '/mpl/', exp: '/epl/' },
					fieldDataset: '/StraboFieldDatasetDetail/?dataset_id='
				};
				</script>
				<script src="/assets/js/leaflet/leaflet.js"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/catalog.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/builder.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/results.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/saved.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/app.js'); ?>"></script>

<?php
include("../includes/mfooter.php");
?>
