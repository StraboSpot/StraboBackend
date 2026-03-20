<?php
/**
 * File: instrumentcatalog.php
 * Description: StraboMicro Instrument Catalog - Browse institutes and instruments
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include_once("adminkeys.php");
session_start();

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 7200)) {
	$_SESSION['loggedin'] = "no";
}
if (isset($_SESSION['LAST_ACTIVITY'])) {
	$_SESSION['LAST_ACTIVITY'] = time();
}

$is_logged_in = (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] == "yes");
$userpkey = $is_logged_in ? (int)$_SESSION['userpkey'] : 0;
$username = $is_logged_in ? $_SESSION['username'] : '';
$firstname = $is_logged_in ? $_SESSION['firstname'] : '';
$lastname = $is_logged_in ? $_SESSION['lastname'] : '';
$is_admin = $is_logged_in && in_array($userpkey, $admin_pkeys);

// Initialize database
include_once $_SERVER['DOCUMENT_ROOT'] . "/includes/config.inc.php";
include $_SERVER['DOCUMENT_ROOT'] . "/db.php";

// Determine which institutes the user can manage
$user_institute_pkeys = array();
if ($is_logged_in && !$is_admin) {
	$uirows = $db->get_results_prepared(
		"SELECT institution_pkey FROM instrument_users WHERE users_pkey = $1",
		array($userpkey)
	);
	if ($uirows) {
		foreach ($uirows as $uirow) {
			$user_institute_pkeys[] = $uirow->institution_pkey;
		}
	}
}

// Get all institutes with instrument counts
$institutionrows = $db->get_results("
	SELECT i.*,
		(SELECT count(*) FROM instrument WHERE institution_pkey = i.pkey) as instrument_count
	FROM institute i
	ORDER BY institute_name
");

// Build institute data with instruments
$institutes = array();
if ($institutionrows) {
	foreach ($institutionrows as $irow) {
		$can_manage = $is_admin || in_array($irow->pkey, $user_institute_pkeys);

		$instrumentrows = $db->get_results_prepared("
			SELECT * FROM instrument WHERE institution_pkey = $1 ORDER BY instrumentname
		", array($irow->pkey));

		// Get detectors for each instrument
		$instruments = array();
		if ($instrumentrows) {
			foreach ($instrumentrows as $inst) {
				$detectors = $db->get_results_prepared(
					"SELECT * FROM instrument_detector WHERE instrument_pkey = $1 ORDER BY pkey",
					array($inst->pkey)
				);
				$inst->detectors = $detectors ? $detectors : array();
				$instruments[] = $inst;
			}
		}

		$institutes[] = array(
			'info' => $irow,
			'instruments' => $instruments,
			'can_manage' => $can_manage
		);
	}
}

include 'includes/mheader.php';
?>

<script src='/assets/js/jquery/jquery.min.js'></script>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>StraboMicro Instrument Catalog</h2>
		</header>

		<!-- Contact Link -->
		<div style="text-align: center; margin-bottom: 1.5em;">
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.05em;">
				If you need an institute added to the catalog, please
				<a href="mailto:strabospot@gmail.com?subject=Need Institute Added to Strabo Micro Instrument Catalog&body=Hello%2C%0A%0APlease%20add%20the%20following%20institute%20to%20the%20Strabo%20Micro%20Instrument%20Catalog%3A%0A%0AInstitute%20Type%3A%20%28Government%20or%20Education%29%0AInstitute%20Name%3A%0A%0AStrabo%20Account%3A <?php echo urlencode($username); ?>%0A%0AThanks%2C%0A%0A<?php echo urlencode($firstname); ?>%20<?php echo urlencode($lastname); ?>%0A">click here</a>.
			</p>
		</div>

		<!-- Admin: Add Institute -->
		<?php if ($is_admin) { ?>
		<div style="text-align: center; margin-bottom: 1.5em;">
			<a href="add_institute" class="button primary small">+ Add Institute</a>
		</div>
		<?php } ?>

		<!-- Search Bar -->
		<div style="max-width: 500px; margin: 0 auto 2em auto;">
			<input type="text" id="instrumentSearch" placeholder="Search institutes or instruments..." style="width: 100%; padding: 10px 15px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.05); color: #fff; font-size: 1em;">
		</div>

		<!-- Institutes List -->
		<div id="instituteList">
		<?php foreach ($institutes as $inst_data) {
			$irow = $inst_data['info'];
			$instruments = $inst_data['instruments'];
			$can_manage = $inst_data['can_manage'];
			$count = count($instruments);
		?>
			<div class="institute-card" data-name="<?php echo htmlspecialchars(strtolower($irow->institute_name)); ?>" data-instruments="<?php
				$names = array();
				foreach ($instruments as $ins) {
					$names[] = strtolower($ins->instrumentname . ' ' . $ins->instrumenttype . ' ' . $ins->instrumentbrand . ' ' . $ins->instrumentmodel);
				}
				echo htmlspecialchars(implode('|', $names));
			?>">
				<!-- Institute Header (clickable) -->
				<div class="institute-header" onclick="toggleInstitute(<?php echo $irow->pkey; ?>)">
					<div style="flex: 1;">
						<h3 style="color: #fff; font-size: 1.3em; margin: 0; font-weight: 400;"><?php echo htmlspecialchars($irow->institute_name); ?></h3>
					</div>
					<div style="display: flex; align-items: center; gap: 15px;">
						<?php if ($is_admin) { ?>
						<a href="edit_institute?p=<?php echo $irow->pkey; ?>" class="button small" style="padding: 4px 12px; font-size: 0.8em;" onclick="event.stopPropagation();">Edit</a>
						<a href="delete_institute?p=<?php echo $irow->pkey; ?>" class="button small" style="padding: 4px 12px; font-size: 0.8em;" onclick="event.stopPropagation(); return confirm('Are you sure you want to delete this institute and all its instruments?');">Delete</a>
						<?php } ?>
						<span style="color: rgba(255,255,255,0.6); font-size: 0.95em;">
							<?php echo $count; ?> instrument<?php echo $count !== 1 ? 's' : ''; ?>
						</span>
						<span class="institute-chevron" id="chevron-<?php echo $irow->pkey; ?>" style="color: #e44c65; font-size: 1.2em; transition: transform 0.2s;">&#9654;</span>
					</div>
				</div>

				<!-- Institute Expanded Content -->
				<div class="institute-body" id="institute-<?php echo $irow->pkey; ?>" style="display: none;">
					<?php if ($can_manage) { ?>
					<div style="margin-top: 1em; margin-bottom: 1em;">
						<a href="add_instrument?i=<?php echo $irow->pkey; ?>" class="button primary small">+ Add Instrument</a>
					</div>
					<?php } ?>

					<?php if ($count > 0) { ?>
					<table class="instrument-table">
						<thead>
							<tr>
								<th>Name</th>
								<th>Type</th>
								<th>Brand</th>
								<th>Model</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($instruments as $row) { ?>
							<tr>
								<td><?php echo htmlspecialchars($row->instrumentname); ?></td>
								<td><?php echo htmlspecialchars($row->instrumenttype); ?></td>
								<td><?php echo $row->instrumentbrand ? htmlspecialchars($row->instrumentbrand) : '<span style="color: rgba(255,255,255,0.3);">&mdash;</span>'; ?></td>
								<td><?php echo $row->instrumentmodel ? htmlspecialchars($row->instrumentmodel) : '<span style="color: rgba(255,255,255,0.3);">&mdash;</span>'; ?></td>
								<td style="white-space: nowrap;">
									<a href="view_instrument?ii=<?php echo $row->pkey; ?>" class="button small" style="padding: 4px 12px; font-size: 0.85em;">View</a>
									<?php if ($can_manage) { ?>
									<a href="edit_instrument?ii=<?php echo $row->pkey; ?>" class="button small" style="padding: 4px 12px; font-size: 0.85em;">Edit</a>
									<a href="delete_instrument?ii=<?php echo $row->pkey; ?>" class="button small" style="padding: 4px 12px; font-size: 0.85em;" onclick="return confirm('Are you sure you want to delete this instrument?')">Delete</a>
									<?php } ?>
								</td>
							</tr>
						<?php } ?>
						</tbody>
					</table>
					<?php } else { ?>
					<p style="color: rgba(255,255,255,0.6); padding: 0.5em 0;">
						No instruments yet exist for <?php echo htmlspecialchars($irow->institute_name); ?>.
						<?php if ($can_manage) { ?>
						<a href="add_instrument?i=<?php echo $irow->pkey; ?>">Add one now</a>.
						<?php } ?>
					</p>
					<?php } ?>
				</div>
			</div>
		<?php } ?>
		</div>

		<!-- No Results Message -->
		<div id="noResults" style="display: none; text-align: center; padding: 2em 0;">
			<p style="color: rgba(255,255,255,0.6); font-size: 1.1em;">No institutes or instruments match your search.</p>
		</div>

		<div class="bottomSpacer"></div>

	</div>
</div>

<style>
.institute-card {
	background: rgba(255, 255, 255, 0.04);
	border: 1px solid rgba(255, 255, 255, 0.1);
	border-radius: 6px;
	margin-bottom: 1em;
	overflow: hidden;
}

.institute-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 1em 1.5em;
	cursor: pointer;
	transition: background 0.15s;
}

.institute-header:hover {
	background: rgba(255, 255, 255, 0.04);
}

.institute-body {
	padding: 0 1.5em 1.5em 1.5em;
	border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.instrument-table {
	width: 100%;
	border-collapse: collapse;
}

.instrument-table th {
	text-align: left;
	color: rgba(255, 255, 255, 0.5);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	padding: 0.7em 1em;
	border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.instrument-table td {
	padding: 0.7em 1em;
	color: rgba(255, 255, 255, 0.85);
	border-bottom: 1px solid rgba(255, 255, 255, 0.05);
	font-size: 0.95em;
}

.instrument-table tbody tr:hover {
	background: rgba(255, 255, 255, 0.03);
}

.institute-chevron.expanded {
	transform: rotate(90deg);
}
</style>

<script>
function toggleInstitute(id) {
	var body = document.getElementById('institute-' + id);
	var chevron = document.getElementById('chevron-' + id);
	if (body.style.display === 'none') {
		body.style.display = 'block';
		chevron.classList.add('expanded');
	} else {
		body.style.display = 'none';
		chevron.classList.remove('expanded');
	}
}

$(document).ready(function() {
	$('#instrumentSearch').on('input', function() {
		var query = $(this).val().toLowerCase().trim();
		var visibleCount = 0;

		$('.institute-card').each(function() {
			var name = $(this).data('name');
			var instruments = $(this).data('instruments');
			var match = !query || name.indexOf(query) !== -1 || String(instruments).indexOf(query) !== -1;
			$(this).toggle(match);
			if (match) visibleCount++;
		});

		$('#noResults').toggle(visibleCount === 0);
	});
});
</script>

<?php
include 'includes/mfooter.php';
?>
