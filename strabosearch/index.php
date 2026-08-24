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
 *              2026-08 (Globe View M1): full-viewport app frame — the
 *              criteria builder lives in a fixed left rail, results fill
 *              the right pane, each scrolls internally (design doc
 *              docs/StraboSearch/GlobeView_Design_Proposal.md §3). The
 *              visual footer is skipped via mfooter_close.php; below the
 *              desktop breakpoint the frame falls back to stacked
 *              document flow (the M4 drawer treatment comes later).
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

			<div id="loadingScreen" style="display:none;">
				<div class="grayOut" style="display:inline;"></div>
				<div id="loadingmessage" style="text-align:center;display:block;">
					<div class="loader" style="margin-left: 100px;"></div>
					<div id="loadingText">Searching. Please wait...</div>
				</div>
			</div>

			<!-- App frame: criteria rail + results pane -->
			<div id="ssAppFrame" class="ss-app-frame">

				<div class="ss-rail">
					<div class="ss-rail-head">
						<h2>StraboSpot Search</h2>
					</div>

					<div class="ss-rail-scroll">
						<div id="ssAnonNote" style="display:none;" class="ss-anon-note">
							Sign in to also search private projects you own or collaborate on.
							<a href="javascript:void(0);" id="ssAnonNoteDismiss" aria-label="Dismiss note">&times;</a>
						</div>

						<!-- Criteria builder (§6.3) — rows rendered by builder.js -->
						<div id="criteriaBuilder" class="ss-criteria" aria-label="Search criteria"></div>
					</div>

					<!-- Rail actions (§6.2.3) -->
					<div class="ss-rail-actions">
						<a href="javascript:void(0);" id="ssSearchBtn" class="button primary fit">Search</a>
<?php if ($searchLoggedIn) { ?>
						<div class="ss-actions-row">
							<a href="javascript:void(0);" id="ssMySearchesBtn" class="button small fit">My searches &#9662;</a>
							<a href="javascript:void(0);" id="ssSaveBtn" class="button small fit">Save current</a>
						</div>
<?php } ?>
					</div>
				</div>

				<!-- Results region (§6.5) — rendered by results.js -->
				<div class="ss-pane">
					<div id="ssResults" class="ss-results">
						<div class="ss-quiet-prompt">Compose a search to see results.<br /><a href="javascript:void(0);" id="ssBrowseGlobe" class="ss-browse-link">or browse everything on the globe</a></div>
					</div>
					<!-- Globe view (M2) — OUTSIDE #ssResults so results.js
					     re-renders never destroy the Cesium canvas. -->
					<div id="ssGlobeWrap" class="ss-globe-wrap" style="display:none;">
						<div id="ssGlobeStatus" class="ss-globe-status" role="status"></div>
						<!-- Layers panel (M3): basemap radio + Macrostrat overlay.
						     Site CSS hides native radios/checkboxes; every input is
						     immediately followed by its label[for] (the one pattern
						     the theme styles visibly). -->
						<div id="ssGlobeLayers" class="ss-globe-layers">
							<button id="ssLayersBtn" class="ss-layers-btn" type="button" aria-expanded="false" aria-controls="ssLayersPanel">Layers</button>
							<div id="ssLayersPanel" class="ss-layers-panel" style="display:none;">
								<div class="ss-layers-group" role="radiogroup" aria-label="Basemap">
									<div class="ss-layers-title">Basemap</div>
									<div class="ss-layers-row">
										<input type="radio" id="ssBaseTerrain" name="ssBasemap" value="terrain" checked>
										<label for="ssBaseTerrain">Terrain</label>
									</div>
									<div class="ss-layers-row">
										<input type="radio" id="ssBaseSatellite" name="ssBasemap" value="satellite">
										<label for="ssBaseSatellite">Satellite</label>
									</div>
								</div>
								<div class="ss-layers-group">
									<div class="ss-layers-title">Overlay</div>
									<div class="ss-layers-row">
										<input type="checkbox" id="ssMacrostratChk">
										<label for="ssMacrostratChk">Macrostrat geology</label>
									</div>
									<div class="ss-layers-opacity">
										<label for="ssMacrostratOpacity">Opacity</label>
										<input type="range" id="ssMacrostratOpacity" min="10" max="100" value="60" disabled>
									</div>
								</div>
							</div>
						</div>
						<div id="ssGlobeContainer" class="ss-globe-container"></div>
						<div id="ssGlobePopup" class="ss-globe-popup" style="display:none;"></div>
					</div>
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
					fieldDataset: '/StraboFieldDatasetDetail/?dataset_id=',
					cesium: {
						base: '/assets/js/cesium/',
						js:   '/assets/js/cesium/Cesium.js',
						css:  '/assets/js/cesium/Widgets/widgets.css'
					},
					tiles: {
						// Site tile proxy, same basemap set every Leaflet
						// map on the site uses (map_interface.js et al.).
						outdoors:   'https://tiles.strabospot.org/v5/mapbox.outdoors/{z}/{x}/{y}.png',
						satellite:  'https://tiles.strabospot.org/v5/mapbox.satellite/{z}/{x}/{y}.png',
						macrostrat: 'https://tiles.strabospot.org/v5/macrostrat/{z}/{x}/{y}.png'
					}
				};
				</script>
				<script src="/assets/js/leaflet/leaflet.js"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/catalog.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/builder.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/globe.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/results.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/saved.js'); ?>"></script>
				<script src="<?php echo ss_asset('/strabosearch/js/app.js'); ?>"></script>

<?php
// App-frame page: script stack + closers only, no visual footer (§M1).
include("../includes/mfooter_close.php");
?>
