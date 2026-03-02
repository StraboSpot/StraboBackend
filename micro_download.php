<?

function dumpVar($var){
	echo "<pre>";
	print_r($var);
	echo "</pre>";
}

$json = file_get_contents("https://raw.githubusercontent.com/jasonash/StraboMicro2/refs/heads/main/releases.json");
$datas = json_decode($json);
$data = $datas[0];

include("includes/mheader.php");
include("Parsedown.php");
$pd = new Parsedown();

$notes = $pd->text($data->notes);

?>

<style>
* {
  /*outline: 1px solid red;*/
}
</style>




			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>StraboMicro <?php echo $data->name?></h2>
							<!--<p>Ipsum dolor feugiat aliquam tempus sed magna lorem consequat accumsan</p>-->
						</header>

						<!-- Content -->
							<section id="content">


								<!--
								<div class="row">
									<div class="col-6 col-12-xsmall" style="outline: 1px solid grey;margin-left: auto; margin-right: auto; border-radius:10px; background-color:#272833; padding: 15px; margin-top:30px;">

										<div style="text-align:center;font-weight:bold;font-size:2em;padding-bottom:5px;">WARNING!!!</div>
										
										Please note that this is a DEVELOPMENT version of StraboMicro and is not meant for production use! Please be careful when using this application
										and do not rely on it to preserve your data.

									</div>
								</div>
								-->

								
								
								<div style="padding-top:40px;">
									<h3>Release Date: <?php echo $data->date?></h3>
									<h3>Downloads:</h3>
									<div style="padding-left:20px;">
										<ul>
							<?php
								foreach($data->assets as $a){
							?>
											<li><?php echo $a->platform; ?>: <a href="<?php echo $a->url; ?>" target="_blank"><?php echo $a->name; ?></a></li>
							<?php
							}
							?>
										</ul>
									</div>
									
									<details>
										<summary><h3 style="display:inline;">Notes:</h3></summary>
										<div>
											<?php echo $notes; ?>
										</div>
									</details>

									<div style="padding-top:25px;padding-bottom:25px;">
										<h3>Installation:</h3>
										<div style="padding-left:30px;">
										<div><strong>macOS</strong>: Download the DMG, open it, and drag StraboMicro2 to your Applications folder.</div>
										<div><strong>Windows</strong>: Download and run the installer. If you see a SmartScreen warning, click "More info" then "Run anyway".</div>
										<div><strong>Linux (AppImage)</strong>: Download the AppImage, make it executable (<code>chmod +x</code>), and run with: <code>./StraboMicro2-*.AppImage --no-sandbox</code></div>
										<div><strong>Linux (Debian/Ubuntu)</strong>: Install the .deb package: <code>sudo dpkg -i strabomicro2_*.deb</code></div>
										</div>
									</div>

									<!-- Figure out if there are any older "non beta" releases to show: -->
									<?php
										$showcount = 0;
										$showrecords = [];
										for($x = 1; $x < count($datas); $x++){
											$adata = $datas[$x];
											if (!$adata->prerelease) {
												$showcount++;
												$showrecords[] = $adata;
											}
										}
									?>									

									<!-- Older Releases Section: -->
									<?php
									if($showcount > 0){
									?>
									<details>
										<summary><h3 style="display:inline;">Older Releases:</h3></summary>
										<div style="margin-left:50px;">
											<?php
											foreach($showrecords as $showrecord){
												$notes = $pd->text($showrecord->notes);
											?>

											<div style="padding-top:40px;">
												<h3>Release Date: <?php echo $showrecord->date?></h3>
												<h3>Downloads:</h3>
												<div style="padding-left:20px;">
													<ul>
										<?php
											foreach($showrecord->assets as $a){
										?>
														<li><?php echo $a->platform; ?>: <a href="<?php echo $a->url; ?>" target="_blank"><?php echo $a->name; ?></a></li>
										<?php
										}
										?>
													</ul>
												</div>
												
												<details>
													<summary><h3 style="display:inline;">Notes:</h3></summary>
													<div>
														<?php echo $notes; ?>
													</div>
												</details>
											</div>

											<?php
											}
											?>
										</div>
									</details>							
									<?php
									}
									?>
									
									
									
									
									
								</div>



							</section>
					</div>
				</div>

<div class="bottomSpacer"></div>



<?
include("includes/mfooter.php");


/*
Array
(
    [0] => stdClass Object
        (
            [assets] => Array
                (
                    [0] => stdClass Object
                        (
                            [name] => StraboMicro2.Setup.2.0.0-beta.10.exe
                            [platform] => Windows
                            [size] => 151776486
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.10/StraboMicro2.Setup.2.0.0-beta.10.exe
                        )

                    [1] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.10-arm64.dmg
                            [platform] => macOS (Apple Silicon)
                            [size] => 200494131
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.10/StraboMicro2-2.0.0-beta.10-arm64.dmg
                        )

                    [2] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.10.dmg
                            [platform] => macOS (Intel)
                            [size] => 208288030
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.10/StraboMicro2-2.0.0-beta.10.dmg
                        )

                    [3] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.10.AppImage
                            [platform] => Linux (AppImage)
                            [size] => 216577628
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.10/StraboMicro2-2.0.0-beta.10.AppImage
                        )

                    [4] => stdClass Object
                        (
                            [name] => strabomicro2_2.0.0-beta.10_amd64.deb
                            [platform] => Linux (Debian)
                            [size] => 123201726
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.10/strabomicro2_2.0.0-beta.10_amd64.deb
                        )

                )

            [date] => 2026-03-02T17:54:12Z
            [name] => StraboMicro2 v2.0.0-beta.10
            [notes] => ## StraboMicro2 v2.0.0-beta.10

### New Features
- Bidirectional PPL/XPL sibling pairing
- Add dockable toolbar positioning with edge cycling
- Add login/logout actions to header auth banner
- Add startup message dialog fetching from StraboSpot
- Add Grain Size & Point Count summaries to PropertiesPanel
- Add Spot Label Mode (Original / Mineralogy / None)
- Add Mineral Color Tool for viewing spots by mineral classification
- Pull latitude/longitude from parent spot when linking StraboField sample
- Persist sketch settings between app restarts
- Add dedicated Sketches tab in properties panel
- Add sketch export functionality (Phase 8)
- Add sketch layer management UI (Phase 6)
- Add sketch text tool (Phase 5)
- Add sketch eraser tool (Phase 4)
- Add sketch drawing tools (Phase 3)
- Add sketch layer canvas rendering (Phase 2)
- Add sketch overlay data model and store actions (Phase 1)
- Resize XPL to match PPL dimensions when adding sibling
- Add 'Add Corresponding XPL Image' option for quick sibling creation
- Add PPL/XPL toggle button to canvas for sibling pairs
- Share spots between PPL/XPL sibling pairs
- Add XPL/PPL synchronized viewing with sibling pairing
- Add preset serialization for .smz export/import
- Complete Quick Spot Presets with all metadata dialogs
- Add keyboard hints for preset keys in Quick Edit mode
- Add Applied Presets section to Properties panel
- Add pie chart indicator for applied presets on canvas
- Integrate Quick Apply Presets into Quick Edit toolbar
- Add Quick Apply Presets UI and menu integration
- Add Quick Apply Presets core infrastructure
- Add Grain Size Analysis dialog
- Add FastSAM model download from Hugging Face
- Add FastSAM grain detection integration
- Add thumbnail preview to New Micrograph wizard header
- Refactor Image Comparator to multi-canvas grid view
- Integrate 3-Point Registration into New Micrograph wizard (Phase 3)
- Add AffineRegistrationModal UI component (Phase 2)
- Add affine transform placement infrastructure (Phase 1)
- Add Lasso tool button to drawing toolbar
- Add Quick Edit entry dialog and statistics panel
- Add Quick Edit visual states to SpotRenderer
- Add Quick Edit menu item and IPC handler
- Add Quick Edit mode core infrastructure
- Add batch delete for multiple selected spots
- Add Merge and Split tools for polygon spots
- Add Batch Edit dialog and menu entry points
- Add multi-select for spots
- Move grain detection to Web Worker with progress indicator
- Add Grain Detection dialog with interactive preview
- Add grain detection core service with OpenCV.js
- Add arrow key spatial navigation in Point Count mode
- Add grid preview canvas to Point Count dialog
- Add lasso selection tool for batch point classification
- Add Point Count renderer (Phase 4)
- Add Tools menu and Point Count dialog (Phase 3)
- Add Point Count store integration (Phase 2)
- Add Point Count data model and storage (Phase 1)
- Add resizable left edge to Statistics Panel
- Make Quick Classify toolbar draggable and auto-show Statistics Panel
- Add floating Statistics Panel for point counting
- Add visual classification indicator and Delete key to clear
- Add Quick Classify Toolbar for keyboard-driven spot classification
- Complete Spot Generation Phase 2 - Point Counting Statistics
- Add archived spots system with View menu toggle
- Add random/stratified point generation and Clear All Spots
- Implement Spot Generation System Phase 1 - Core Infrastructure
- Add Edit All Associated Micrographs Opacity option
- Add inline editing for Detailed Notes panel
- Add Send Error Report functionality
- Add persistent error logging with viewer dialog
- Add Image Comparator for side-by-side micrograph comparison
- Block export/upload for incomplete micrographs

### Bug Fixes
- Merge latest-mac.yml from both macOS arch builds for auto-update
- Reassign Cmd+Shift+P shortcut to Quick Spot Presets
- Add file validation to single-image import dialogs
- Pre-validate image files during batch import with magic-byte checks
- Disable sketch toolbar while text input is active
- Display orientation info in micrograph metadata summary panel
- Resolve sibling primary when clearing/editing spots from XPL view
- Share dock position between toolbars via useSyncExternalStore
- Center horizontal slider vertically and add spacing before preview
- Center dock button in vertical mode, fix slider overlap in horizontal mode
- Use wasmPaths.mjs to load ONNX backend from unpacked asar
- Override file protocol to serve WASM files from unpacked asar
- Unpack onnxruntime-web WASM files from asar for Windows builds
- Use NODE_PATH to bypass asar resolution for sharp native module
- Comprehensive sharp native module fix with diagnostics
- Revert skipOpenTelemetrySetup which surfaced hidden canvas error
- Patch sharp native module resolution for Windows asar builds
- Load sharp before Sentry to avoid require hook breaking asar resolution
- Disable asar packaging to resolve native module loading on Windows
- Force-include sharp and @img native binaries in build files
- Explicitly include sharp win32 native binaries in Windows build
- Show detecting overlay immediately when image loads, before inference starts
- Clear stale detection results and show loading state on dialog open
- Pre-initialize contour worker to eliminate delay on subsequent detection runs
- Strip letterbox padding from masks before upsampling to fix grain positioning
- Cache model session, only load bytes on first detection
- Enable WASM multi-threading and fix detection issues
- Send model bytes via IPC instead of file:// URL
- Migrate FastSAM from onnxruntime-node to onnxruntime-web (WASM)
- Improve auto-updater reliability for production release
- Move useMemo before conditional returns in SpotRenderer to fix hooks violation
- Add cache-busting param to startup message fetch
- Move Configure Mineral Colors from Tools menu to Spot menu
- Open startup message links in default browser instead of in-app
- Skip startup message when content is blank or whitespace-only
- Replace native color picker with themed HexColorPicker in mineral color dialog
- Handle Windows EPERM on scratch file unlink after copy
- Use standard filled spot style in Quick Edit mode
- Sync View menu toggle states with persisted settings on app restart
- Use case-insensitive mineral name matching in color resolution
- Add hardcoded DEFAULT_MINERAL_COLORS as final fallback in SpotRenderer
- Add guard for missing IPC handlers in preload
- Add rehydration guard and debug logging for mineral color mode
- Replace free-text mineral input with autocomplete dropdown in Quick Classify shortcuts
- Require valid scale method selection in associated micrograph wizard
- Use EditSampleDialog consistently for all sample editing
- Map other_sampling_purpose and other_material_type from StraboField
- Handle 'other' values when linking sample from StraboField
- Allow saving sample after linking from StraboField when sample lacks name
- Fix race condition in sketch layer hidden warning
- Add centered warning modal when sketch layer is hidden
- Block drawing on hidden sketch layers in TiledViewer
- Disable drawing on hidden sketch layers
- Ensure sketch layer visibility and auto-switch to Sketches tab
- Auto-create sketch layer when entering sketch mode
- Always show email field in error report dialog
- Allow sending error reports without login
- Parent micrograph zoom issue when navigating back from sibling view
- Exit sketch mode when switching micrographs
- Critical bug: use return value from updateMicrograph in all sketch layer actions
- Fix sketch layers not updating - subscribe to project state directly in panel
- Fix infinite render loop in SketchLayersPanel - use stable empty array reference
- Fix sketch layers not rendering - subscribe to actual state instead of using getter functions
- Move sketch layers panel from left sidebar to right properties panel
- Restrict sibling pairing options to PPL/XPL image types only
893fa97 Revert "[Fix] Adjust zoom proportionally when toggling between siblings with different dimensions"
- Adjust zoom proportionally when toggling between siblings with different dimensions
- Adjust XPL scalePixelsPerCentimeter to match PPL physical size
51d697d Revert "[Fix] Apply siblingScaleFactor when rendering XPL sibling in TiledViewer"
- Apply siblingScaleFactor when rendering XPL sibling in TiledViewer
- Apply scale factor when XPL has different dimensions than PPL
- Validate XPL image aspect ratio matches PPL before allowing add
- Dispatch thumbnail-generated event after sibling linking
- Hide secondary siblings from canvas overlay and fix thumbnail regeneration timing
- Handle null pointInParent when filtering point-located micrographs
- XPL inherits PPL position when linking siblings after-the-fact
- Cascade delete to PPL/XPL siblings when deleting micrograph
- Exclude XPL siblings from composite thumbnails and exports
- Properly detect sibling toggle in both directions
- Preserve zoom/pan when toggling PPL/XPL sibling views
- Downscale large images (>10K pixels) during manual import
- Downscale large images (>10K pixels) during import for better performance
- Sync micrograph dimensions with actual image files during import
- Add limitInputPixels option for very large panorama images
- Convert legacy TIFF images to JPEG during SMZ import
- Add fallback to uiImages for legacy projects missing images
- Skip to next unclassified spot after categorization in Quick Edit
- Update Quick Edit reviewed count on batch categorization
- Sync Quick Edit spot list when batch deleting via lasso
- Quick Edit lasso selection improvements
- Make grain analysis charts theme-aware
- Calculate total micrograph area for 'All micrographs' scope
- Improve Total Area formatting for small grains
- Add missing spot fields to project serialization
- Add onnxruntime-common to asarUnpack
- Add DLL path to Windows PATH for onnxruntime loading
- Require onnxruntime-node from unpacked location directly
- Use process.dlopen() to preload onnxruntime native binding
- Patch module resolution for onnxruntime-node on Windows
- Make onnxruntime-node loading graceful for Windows compatibility
- Add onnxruntime-node to asarUnpack for Windows
- Downgrade onnxruntime-node to 1.19.0 for Windows compatibility
- Implement GrainSight-compatible contour extraction
- Rewrite boundary extraction for proper contour tracing
- Use marching squares algorithm for mask contour extraction
- Scale FastSAM coordinates to preview image size
- Add affine overlay support to Image Comparator
- Correct overlay scale in Image Comparator
- Render actual overlay images with red outlines in Image Comparator
- Fix Image Comparator canvas sizing
- Persist affine transform data in project.json and regenerate on import
- Always resize affine medium image to full transformed dimensions
- Use micrograph data for affine overlay dimensions, not cache metadata
- Use correct field name affineTileHash for affine overlays
- Add affine overlay support to composite thumbnail generation
- Add affine overlay support to export-composite handler
- Add affine overlay support to composite image generation
- Recognize affine placement as valid location
- Rewrite affine tile generator with manual inverse sampling
- Improve panel sizing with useLayoutEffect and explicit flex
- Fix panel sizing in AffineRegistrationModal
- Fix panel sizing - use separate sizes for each panel
- Fix image sizing and centering in AffineRegistrationModal
- Correct affine transform for Sharp's inverse mapping
- Store and use affine tile hash to fix path mismatch
- Add --no-sandbox to AppImage via electron-builder config
- Improve Linux sandbox disable - set env var before Electron loads
- Disable sandbox on Linux for AppImage compatibility
- Always fill point spots with their set color
- Use theme-aware colors in Point Count and Quick Edit panels
- Store theme in createWindow scope so all menu rebuilds use correct theme
- Use correct buildMenuFn reference for theme sync
- Rebuild menu on theme change to sync checked state
- Use menu item IDs for theme sync
- Sync theme to menu after store rehydration
- Sync theme menu checked state with persisted preference
- Skip safeStorage entirely on Linux to prevent keyring hangs
- Linux compatibility - EXDEV error and login hang
- Load OpenCV.js via IPC for packaged app
- Try multiple URLs when fetching OpenCV.js in worker
- Include point count sessions in SMZ export/import
- Keep crosshair cursor during Split Spot mode
- Pre-mark classified spots as reviewed when entering Quick Edit
- Correct Quick Edit statistics calculations
- Mark spots as reviewed when classified in Quick Edit mode
- Improve Quick Edit navigation
- Use bright lime green for classified spots
- Use solid lines in Quick Edit mode
- Use cyan outlines for unclassified spots in Quick Edit
- Fix Quick Edit classification to advance within session
- Fix array corruption in project serialization
- Fix split and merge spot micrograph lookup
- Show crosshair cursor during split line drawing
- Clear multi-selection when clicking on empty canvas
- Use convex hull for merging non-overlapping polygons
- Use structuredClone in saveEditingGeometry for proper React updates
- Force stage redraw after geometry edit save
- Support curved split lines by buffering entire polyline
- Use polygon-clipping library for split algorithm
- Rewrite splitSpot algorithm to use half-plane intersection
- Fix split mode click handling priority
- Include activeSpotId in multi-select selection
- Remove opacity penalty for unclassified spots
- Scale grain detection coordinates from medium to full resolution
- Convert grain detection opacity to 0-100 scale for SpotRenderer
- Switch OpenCV.js to bundled local file with improved loader
- Close Statistics Panel when exiting Point Count mode
- Point Count mode isolates canvas from spots and navigation
- GridPreviewCanvas zoom limits and responsive width
- Fix GridPreviewCanvas container dimensions and add debug logging
- Consistent mineral colors between points and statistics panel
- Fix Point Count dialog view determination on open
- Reset Point Count dialog view state when reopening
- Fix React hooks violation in PointCountRenderer
- Adjust Quick Classify toolbar default position higher on screen
- Auto-pan canvas to keep current spot visible during Quick Classify
- Rebuild micrographIndex when adding spots
- Enable opacity for point spots
- Fix preview image scaling in Generate Spots dialog
- Show all populated fields in Project Metadata summary
- Keep crosshair cursor when drawing/measure tool is active
- Make color picker text fields read-only
- Block canvas navigation when notes are being edited
- Wire up Show Spot Labels menu toggle to viewer
- Fix mineralogy dialog save and dropdown selection issues
- Show sample notes when viewing spot on micrograph
- Show unsaved notes warning BEFORE navigation
- Enable back button when clicking point micrographs
- Disable auto-updates for dev builds
- Use medium resolution images as base layer in Image Comparator
- Fix image paths in Image Comparator
- Save scalePixelsPerCentimeter when using point placement in Edit dialog
- Recognize pointInParent as valid location for associated micrographs
- Auto-refresh expired tokens for all server operations
- Disable Save button until changes are made in Associated Files dialog
- Simplify incomplete micrographs dialog guidance text
- Skip unlocated micrographs in all compositing functions
- Fix server upload issues and JSON serialization
- Show Debug menu in GitHub dev builds
- Add padding when fitting micrograph to screen
- Add Optical Microscopy to instruments without dataType validation
- Prevent memory spike when locating batch-imported micrographs
- Use thumbnails instead of medium resolution in placement canvases
- Release memory when switching micrographs in TiledViewer
- Release memory when EditMicrographLocationDialog closes
- Fix image cleanup race condition in placement canvases
- Ensure project folders exist before batch import
- Fix tile cache path for batch-imported micrographs
- More aggressive memory cleanup after batch import
- Use RSS-based thresholds for main process memory color
- Add memory cleanup for placement canvas Image objects
- Eagerly release large buffers during image conversion
- Prevent OOM during batch import of large images
- Prevent OOM crashes when rapidly switching micrographs

### UI/UX Improvements
- Remove preset colors and pie chart indicators from Quick Edit mode
- Remove Point Count Statistics from View menu
- Reorganize top menu: add Spot menu, remove View clutter
- Split Location into separate Longitude and Latitude fields in sample metadata
- Add IGSN field to sample metadata display
- Remove duplicate Name and Label fields from sample metadata display
- Make project tree headers clickable to expand/collapse
- Reorganize drawing toolbar and move measure tool
- Add delete dataset option and improve material type display
- Simplify import progress messages
- Make histogram text white and larger for dark mode
- Increase histogram chart font sizes for readability
- Enlarge charts in Grain Size Analysis dialog
- Add tabs to Properties Panel for Micrograph/Spot and Project views
- Apply theme-aware background to Image Comparator canvases
- Apply theme-aware background to AffineRegistrationModal canvases
- Affine overlay outline follows transformed shape
- Make preview panel pannable/zoomable and taller
- Redesign AffineRegistrationModal with preview pane
- Remove Tags option from Batch Edit Spots dialog
- Hide single-spot menu options when multiple spots selected
- Show selection count in title bar
- Add Recommended Points info box to Point Count setup dialog
- Include time in default Point Count session name
- Move Continue button above edit/delete icons in session card
- Simplify Point Count session rename - replace Continue with Save button during edit
- Show rename/delete actions on Point Count recent session card
- Change lasso selection highlight to bright yellow
- Consistent header styling for Quick Classify toolbar
- Remove hover effects from Point Count points
- Improve unclassified point visibility in Point Count mode
- Widen Statistics Panel to fit table content without scrollbar
- Move Statistics Panel to app level for full-window dragging
- Make Statistics Panel draggable floating window
- Improve classification indicator visibility
- Add scroll arrows for Quick Classify shortcut chips
- Replace edit text links with pencil icons in notes panel
- Place buttons to the right of textarea, top-aligned
- Position buttons inside textarea area like mockup
- Update inline notes buttons to match mockup
- Move inline notes Save/Cancel buttons beside textarea
- Combine search and dropdown into single data type selector
- Reduce accordion header height in metadata panel
- Add title bar above status bar showing active spot or micrograph
- Swap toolbar and login status positions in header
- Remove Dataset Metadata accordion from details panel
- Rename 'View Log File' to 'Report Error'
- Add consistent plus menus for adding items in project tree
- Improve accordion panel visual distinction
- Remove opacity sliders from Image Comparator
- Clarify notes label in Associated Files dialog
- Add placeholder to maintain consistent thumbnail width
- Hide action buttons for micrographs needing setup
- Add warning indicator for micrographs needing setup
- Simplify Instrument Database dialog
- Improve batch edit color defaults
- Add checkbox to disable auto Quick Edit after grain detection
- Auto-open Quick Edit after grain detection
- Implement lazy pan for Quick Classify - only scrolls when spot is off-screen
- Show clear message when adding duplicate associated files

### Performance
- Reduce grain detection to 1024px for speed testing
- Reduce grain detection processing size to 1536px for faster detection
- Remove strokes from point count points for performance
- Disable hit-testing on point count points
- Memoize Point component in PointCountRenderer
- Implement viewport culling for spots
- Improve Image Comparator loading experience


---

### Downloads

| Platform | Download |
|----------|----------|
| macOS (Apple Silicon) | `StraboMicro2-2.0.0-beta.10-arm64.dmg` |
| macOS (Intel) | `StraboMicro2-2.0.0-beta.10.dmg` |
| Windows | `StraboMicro2 Setup 2.0.0-beta.10.exe` |
| Linux (AppImage) | `StraboMicro2-2.0.0-beta.10.AppImage` |
| Linux (Debian) | `strabomicro2_2.0.0-beta.10_amd64.deb` |

### Installation

**macOS**: Download the DMG, open it, and drag StraboMicro2 to your Applications folder.

**Windows**: Download and run the installer. If you see a SmartScreen warning, click "More info" then "Run anyway".

**Linux (AppImage)**: Download the AppImage, make it executable (`chmod +x`), and run with: `./StraboMicro2-*.AppImage --no-sandbox`

**Linux (Debian/Ubuntu)**: Install the .deb package: `sudo dpkg -i strabomicro2_*.deb`

            [prerelease] => 1
            [version] => v2.0.0-beta.10
        )

    [1] => stdClass Object
        (
            [assets] => Array
                (
                    [0] => stdClass Object
                        (
                            [name] => StraboMicro2.Setup.2.0.0-beta.8.exe
                            [platform] => Windows
                            [size] => 109751167
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.8/StraboMicro2.Setup.2.0.0-beta.8.exe
                        )

                    [1] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.8-arm64.dmg
                            [platform] => macOS (Apple Silicon)
                            [size] => 136009104
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.8/StraboMicro2-2.0.0-beta.8-arm64.dmg
                        )

                    [2] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.8.dmg
                            [platform] => macOS (Intel)
                            [size] => 143746278
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.8/StraboMicro2-2.0.0-beta.8.dmg
                        )

                    [3] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.8.AppImage
                            [platform] => Linux (AppImage)
                            [size] => 155693191
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.8/StraboMicro2-2.0.0-beta.8.AppImage
                        )

                    [4] => stdClass Object
                        (
                            [name] => strabomicro2_2.0.0-beta.8_amd64.deb
                            [platform] => Linux (Debian)
                            [size] => 99862416
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.8/strabomicro2_2.0.0-beta.8_amd64.deb
                        )

                )

            [date] => 2025-12-01T23:45:18Z
            [name] => StraboMicro2 v2.0.0-beta.8
            [notes] => ## StraboMicro2 v2.0.0-beta.8

### Downloads

| Platform | Download |
|----------|----------|
| macOS (Apple Silicon) | `StraboMicro2-2.0.0-beta.8-arm64.dmg` |
| macOS (Intel) | `StraboMicro2-2.0.0-beta.8.dmg` |
| Windows | `StraboMicro2 Setup 2.0.0-beta.8.exe` |
| Linux (AppImage) | `StraboMicro2-2.0.0-beta.8.AppImage` |
| Linux (Debian) | `strabomicro2_2.0.0-beta.8_amd64.deb` |

### Installation

**macOS**: Download the DMG, open it, and drag StraboMicro2 to your Applications folder.

**Windows**: Download and run the installer. If you see a SmartScreen warning, click "More info" then "Run anyway".

**Linux**: Download the AppImage, make it executable (`chmod +x`), and run it. Or install the .deb package.

            [prerelease] => 1
            [version] => v2.0.0-beta.8
        )

    [2] => stdClass Object
        (
            [assets] => Array
                (
                    [0] => stdClass Object
                        (
                            [name] => StraboMicro2.Setup.2.0.0-beta.6.exe
                            [platform] => Windows
                            [size] => 109749998
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.6/StraboMicro2.Setup.2.0.0-beta.6.exe
                        )

                    [1] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.6-arm64.dmg
                            [platform] => macOS (Apple Silicon)
                            [size] => 135964234
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.6/StraboMicro2-2.0.0-beta.6-arm64.dmg
                        )

                    [2] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.6.dmg
                            [platform] => macOS (Intel)
                            [size] => 143748432
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.6/StraboMicro2-2.0.0-beta.6.dmg
                        )

                    [3] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.6.AppImage
                            [platform] => Linux (AppImage)
                            [size] => 155693080
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.6/StraboMicro2-2.0.0-beta.6.AppImage
                        )

                    [4] => stdClass Object
                        (
                            [name] => strabomicro2_2.0.0-beta.6_amd64.deb
                            [platform] => Linux (Debian)
                            [size] => 99912820
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.6/strabomicro2_2.0.0-beta.6_amd64.deb
                        )

                )

            [date] => 2025-12-01T23:14:57Z
            [name] => StraboMicro2 v2.0.0-beta.6
            [notes] => ## StraboMicro2 v2.0.0-beta.6

### Downloads

| Platform | Download |
|----------|----------|
| macOS (Apple Silicon) | `StraboMicro2-2.0.0-beta.6-arm64.dmg` |
| macOS (Intel) | `StraboMicro2-2.0.0-beta.6.dmg` |
| Windows | `StraboMicro2 Setup 2.0.0-beta.6.exe` |
| Linux (AppImage) | `StraboMicro2-2.0.0-beta.6.AppImage` |
| Linux (Debian) | `strabomicro2_2.0.0-beta.6_amd64.deb` |

### Installation

**macOS**: Download the DMG, open it, and drag StraboMicro2 to your Applications folder.

**Windows**: Download and run the installer. If you see a SmartScreen warning, click "More info" then "Run anyway".

**Linux**: Download the AppImage, make it executable (`chmod +x`), and run it. Or install the .deb package.

            [prerelease] => 1
            [version] => v2.0.0-beta.6
        )

    [3] => stdClass Object
        (
            [assets] => Array
                (
                    [0] => stdClass Object
                        (
                            [name] => StraboMicro2.Setup.2.0.0-beta.5.exe
                            [platform] => Windows
                            [size] => 109751236
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.5/StraboMicro2.Setup.2.0.0-beta.5.exe
                        )

                    [1] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.5-arm64.dmg
                            [platform] => macOS (Apple Silicon)
                            [size] => 136002826
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.5/StraboMicro2-2.0.0-beta.5-arm64.dmg
                        )

                    [2] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.5.dmg
                            [platform] => macOS (Intel)
                            [size] => 142849151
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.5/StraboMicro2-2.0.0-beta.5.dmg
                        )

                    [3] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.5.AppImage
                            [platform] => Linux (AppImage)
                            [size] => 155689114
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.5/StraboMicro2-2.0.0-beta.5.AppImage
                        )

                    [4] => stdClass Object
                        (
                            [name] => strabomicro2_2.0.0-beta.5_amd64.deb
                            [platform] => Linux (Debian)
                            [size] => 99867000
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.5/strabomicro2_2.0.0-beta.5_amd64.deb
                        )

                )

            [date] => 2025-12-01T22:57:46Z
            [name] => StraboMicro2 v2.0.0-beta.5
            [notes] => ## StraboMicro2 v2.0.0-beta.5

### Downloads

| Platform | Download |
|----------|----------|
| macOS (Apple Silicon) | `StraboMicro2-2.0.0-beta.5-arm64.dmg` |
| macOS (Intel) | `StraboMicro2-2.0.0-beta.5.dmg` |
| Windows | `StraboMicro2 Setup 2.0.0-beta.5.exe` |
| Linux (AppImage) | `StraboMicro2-2.0.0-beta.5.AppImage` |
| Linux (Debian) | `strabomicro2_2.0.0-beta.5_amd64.deb` |

### Installation

**macOS**: Download the DMG, open it, and drag StraboMicro2 to your Applications folder.

**Windows**: Download and run the installer. If you see a SmartScreen warning, click "More info" then "Run anyway".

**Linux**: Download the AppImage, make it executable (`chmod +x`), and run it. Or install the .deb package.

            [prerelease] => 1
            [version] => v2.0.0-beta.5
        )

    [4] => stdClass Object
        (
            [assets] => Array
                (
                    [0] => stdClass Object
                        (
                            [name] => StraboMicro2.Setup.2.0.0-beta.4.exe
                            [platform] => Windows
                            [size] => 109751130
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.4/StraboMicro2.Setup.2.0.0-beta.4.exe
                        )

                    [1] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.4-arm64.dmg
                            [platform] => macOS (Apple Silicon)
                            [size] => 136002935
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.4/StraboMicro2-2.0.0-beta.4-arm64.dmg
                        )

                    [2] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.4.AppImage
                            [platform] => Linux (AppImage)
                            [size] => 155693101
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.4/StraboMicro2-2.0.0-beta.4.AppImage
                        )

                    [3] => stdClass Object
                        (
                            [name] => strabomicro2_2.0.0-beta.4_amd64.deb
                            [platform] => Linux (Debian)
                            [size] => 99855076
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.4/strabomicro2_2.0.0-beta.4_amd64.deb
                        )

                )

            [date] => 2025-12-01T22:36:01Z
            [name] => StraboMicro2 v2.0.0-beta.4
            [notes] => ## StraboMicro2 v2.0.0-beta.4

### Downloads

| Platform | Download |
|----------|----------|
| macOS (Apple Silicon) | `StraboMicro2-2.0.0-beta.4-arm64.dmg` |
| macOS (Intel) | `StraboMicro2-2.0.0-beta.4-x64.dmg` |
| Windows | `StraboMicro2-2.0.0-beta.4.exe` |
| Linux (AppImage) | `StraboMicro2-2.0.0-beta.4.AppImage` |
| Linux (Debian) | `StraboMicro2-2.0.0-beta.4.deb` |

### Installation

**macOS**: Download the DMG, open it, and drag StraboMicro2 to your Applications folder.

**Windows**: Download and run the installer. If you see a SmartScreen warning, click "More info" then "Run anyway".

**Linux**: Download the AppImage, make it executable (`chmod +x`), and run it. Or install the .deb package.

            [prerelease] => 1
            [version] => v2.0.0-beta.4
        )

    [5] => stdClass Object
        (
            [assets] => Array
                (
                    [0] => stdClass Object
                        (
                            [name] => StraboMicro2.Setup.2.0.0-beta.3.exe
                            [platform] => Windows
                            [size] => 109751173
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.3/StraboMicro2.Setup.2.0.0-beta.3.exe
                        )

                    [1] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.3-arm64.dmg
                            [platform] => macOS (Apple Silicon)
                            [size] => 136006146
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.3/StraboMicro2-2.0.0-beta.3-arm64.dmg
                        )

                    [2] => stdClass Object
                        (
                            [name] => StraboMicro2-2.0.0-beta.3.AppImage
                            [platform] => Linux (AppImage)
                            [size] => 155693115
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.3/StraboMicro2-2.0.0-beta.3.AppImage
                        )

                    [3] => stdClass Object
                        (
                            [name] => strabomicro2_2.0.0-beta.3_amd64.deb
                            [platform] => Linux (Debian)
                            [size] => 99858076
                            [url] => https://github.com/jasonash/StraboMicro2/releases/download/v2.0.0-beta.3/strabomicro2_2.0.0-beta.3_amd64.deb
                        )

                )

            [date] => 2025-12-01T22:25:00Z
            [name] => StraboMicro2 v2.0.0-beta.3
            [notes] => ## StraboMicro2 v2.0.0-beta.3

### Downloads

| Platform | Download |
|----------|----------|
| macOS (Apple Silicon) | `StraboMicro2-2.0.0-beta.3-arm64.dmg` |
| macOS (Intel) | `StraboMicro2-2.0.0-beta.3-x64.dmg` |
| Windows | `StraboMicro2-2.0.0-beta.3.exe` |
| Linux (AppImage) | `StraboMicro2-2.0.0-beta.3.AppImage` |
| Linux (Debian) | `StraboMicro2-2.0.0-beta.3.deb` |

### Installation

**macOS**: Download the DMG, open it, and drag StraboMicro2 to your Applications folder.

**Windows**: Download and run the installer. If you see a SmartScreen warning, click "More info" then "Run anyway".

**Linux**: Download the AppImage, make it executable (`chmod +x`), and run it. Or install the .deb package.

            [prerelease] => 1
            [version] => v2.0.0-beta.3
        )

)

*/

?>