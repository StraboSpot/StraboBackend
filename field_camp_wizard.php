<?php
/**
 * File: field_camp_wizard.php
 * Description: Field Camp Wizard, first page: the camps an instructor has set
 *              up, with a button to start a new one. Open only to accounts on
 *              the instructor list (FieldCamp::canUse); everyone else gets
 *              "Not found." so the feature stays invisible.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . '/includes/fieldcamp/FieldCamp.php';

if(!FieldCamp::canUse($db, $userpkey)){
	header("HTTP/1.0 404 Not Found");
	exit("Not found.");
}

$camps = FieldCamp::listCamps($db, $userpkey);
$isAdmin = FieldCamp::isAdmin($userpkey);

$flash = $_SESSION['fieldcamp_flash'] ?? null;
unset($_SESSION['fieldcamp_flash']);

$statusLabels = array(
	'draft' => 'Draft', 'building' => 'Building', 'ready' => 'Ready',
	'failed' => 'Failed', 'archived' => 'Archived',
);

include("includes/mheader.php");
include("includes/fieldcamp/fieldcamp_style.php");
?>

<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Field Camp Wizard</h2>
		</header>

		<p class="fc-intro">
			Set up a StraboField project for a field camp: the study area, its maps and rock units,
			and a set of accounts for the students.
		</p>

<?php if($flash && !empty($flash['ok'])){ ?>
		<div class="fc-flash"><?php echo htmlspecialchars($flash['ok']); ?></div>
<?php } ?>
<?php if($flash && !empty($flash['err'])){ ?>
		<div class="fc-flash-err"><?php echo htmlspecialchars(implode(" ", (array)$flash['err'])); ?></div>
<?php } ?>

		<div class="fc-top">
			<div><a href="/field_camp?new=1" class="button primary small">New field camp</a></div>
<?php if($isAdmin){ ?>
			<div><a href="/admin_field_camp_instructors" class="button small">Instructor list</a></div>
<?php } ?>
		</div>

<?php if(!$camps){ ?>
		<div class="fc-empty">No field camps yet. Choose "New field camp" to set up the first one.</div>
<?php }else{ ?>
		<div class="fc-list">
<?php
	foreach($camps as $c){
		$status = isset($statusLabels[$c->status]) ? $c->status : 'draft';
		$accounts = (int)$c->accountcount;
		$inactive = (int)$c->inactivecount;
?>
			<div class="fc-camp">
				<div>
					<div class="fc-camp-name">
						<?php echo htmlspecialchars($c->camp_name); ?>
						<span class="fc-pill fc-pill-<?php echo $status; ?>"><?php echo $statusLabels[$status]; ?></span>
					</div>
					<div class="fc-meta">
						<span><?php echo (int)$c->camp_year; ?></span>
						<span>Project: <?php echo htmlspecialchars($c->project_name); ?></span>
						<span><?php echo $accounts; ?> account<?php echo $accounts === 1 ? '' : 's'; ?><?php
							if($accounts > 0 && $inactive === $accounts){ echo " (deactivated)"; }
							elseif($inactive > 0){ echo " ($inactive deactivated)"; } ?></span>
						<span><?php echo ($c->bbox_west === null || $c->bbox_west === '') ? 'No study area yet' : 'Study area set'; ?></span>
<?php if($isAdmin && (int)$c->owner_user_pkey !== (int)$userpkey){ ?>
						<span>Owner: <?php echo htmlspecialchars($c->owner_email); ?></span>
<?php } ?>
					</div>
				</div>
				<div>
					<a href="/field_camp?id=<?php echo (int)$c->pkey; ?>" class="button small">Open</a>
				</div>
			</div>
<?php
	}
?>
		</div>
<?php } ?>

<?php
// Deleted camps are kept on the server; the admin can bring one back with its accounts.
$deletedCamps = $isAdmin ? FieldCamp::listDeletedCamps($db) : array();
if($deletedCamps){ ?>
		<h4 style="margin-top:2em;">Deleted field camps (admin)</h4>
		<div class="fc-list">
<?php 	foreach($deletedCamps as $c){ $n = (int)$c->accountcount; ?>
			<div class="fc-camp">
				<div>
					<div class="fc-camp-name"><?php echo htmlspecialchars($c->camp_name); ?></div>
					<div class="fc-meta">
						<span><?php echo (int)$c->camp_year; ?></span>
						<span>Deleted <?php echo htmlspecialchars(substr((string)$c->deleted_date, 0, 16)); ?></span>
						<span><?php echo $n; ?> account<?php echo $n === 1 ? '' : 's'; ?> removed with it</span>
						<span>Owner: <?php echo htmlspecialchars((string)$c->owner_email); ?></span>
					</div>
				</div>
				<div>
					<form method="post" action="/field_camp_action" style="margin:0;">
						<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(FieldCamp::token()); ?>">
						<input type="hidden" name="action" value="camp_restore">
						<input type="hidden" name="id" value="<?php echo (int)$c->pkey; ?>">
						<button type="submit" class="button small">Restore</button>
					</form>
				</div>
			</div>
<?php 	} ?>
		</div>
<?php } ?>

		<div class="bottomSpacer"></div>
	</div>
</div>

<?php
include("includes/mfooter.php");
?>
