<?php
/**
 * File: admin_merge_prefs.php
 * Description: Admin page (userpkey 3 only) for per-project merge preferences.
 *              Flagging a project forces UNION tag-merge semantics on upload
 *              even with zero collaborator rows - the escape hatch for
 *              multi-device groups sharing one set of credentials, whose
 *              uploads would otherwise wipe each other's geologic units
 *              under the solo REPLACE semantics (2026-08-16 tag fix).
 *              See sql/project_merge_prefs.sql and combineNormalProject().
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");

if($userpkey !== 3) die("Not authorized.");

$flash = "";
$flasherror = "";

// Handle add/remove (POST-redirect-GET)
if($_SERVER['REQUEST_METHOD'] === 'POST'){

	$action = $_POST['action'] ?? '';

	if($action === "add"){

		$projectid = trim($_POST['project_id'] ?? '');
		$note = trim($_POST['note'] ?? '');

		if(!preg_match('/^\d+$/', $projectid)){
			header("Location: /admin_merge_prefs?err=" . urlencode("Project id must be numeric."));
			exit();
		}

		// Resolve the project in Neo4j to confirm it exists and find its owner.
		$prow = $neodb->getRecord("match (p:Project {id:" . (int)$projectid . "}) return p.userpkey as ownerpkey, p.desc_project_name as pname, p.projectname as legacyname limit 1");
		if(!$prow){
			header("Location: /admin_merge_prefs?err=" . urlencode("No project with id $projectid found."));
			exit();
		}

		$ownerpkey = (int)$prow->get("ownerpkey");

		$existing = (int)$db->get_var_prepared(
			"select count(*) from project_merge_prefs where strabo_project_id = $1 and project_owner_user_pkey = $2",
			array($projectid, $ownerpkey));

		if($existing > 0){
			header("Location: /admin_merge_prefs?err=" . urlencode("Project $projectid is already flagged."));
			exit();
		}

		$db->prepare_query(
			"insert into project_merge_prefs (strabo_project_id, project_owner_user_pkey, union_tags, note) values ($1, $2, true, $3)",
			array($projectid, $ownerpkey, $note));

		header("Location: /admin_merge_prefs?msg=" . urlencode("Project $projectid flagged for union tag merge."));
		exit();

	}elseif($action === "remove"){

		$pkey = (int)($_POST['pkey'] ?? 0);
		$db->prepare_query("delete from project_merge_prefs where pkey = $1", array($pkey));
		header("Location: /admin_merge_prefs?msg=" . urlencode("Flag removed."));
		exit();

	}

	header("Location: /admin_merge_prefs");
	exit();
}

if(isset($_GET['msg'])){ $flash = $_GET['msg']; }
if(isset($_GET['err'])){ $flasherror = $_GET['err']; }

$rows = $db->get_results_prepared("
	select
		pmp.pkey,
		pmp.strabo_project_id,
		pmp.project_owner_user_pkey,
		pmp.note,
		pmp.created_date,
		(select email from users where pkey = pmp.project_owner_user_pkey) as owner_email,
		(select count(*) from collaborators c
			where c.strabo_project_id = pmp.strabo_project_id
			and c.project_owner_user_pkey = pmp.project_owner_user_pkey) as collabcount
	from project_merge_prefs pmp
	where pmp.union_tags = true
	order by pmp.created_date desc
	", array());

// Resolve project names from Neo4j (small list; one lookup per row).
$projectnames = array();
if($rows){
	foreach($rows as $row){
		$prow = $neodb->getRecord("match (p:Project {id:" . (int)$row->strabo_project_id . ", userpkey:" . (int)$row->project_owner_user_pkey . "}) return p.desc_project_name as pname, p.projectname as legacyname limit 1");
		if($prow){
			$pname = $prow->get("pname");
			if($pname == "") $pname = $prow->get("legacyname");
			$projectnames[$row->pkey] = $pname;
		}else{
			$projectnames[$row->pkey] = "(project not found)";
		}
	}
}

include("includes/header.php");
?>

<div align="center">
	<h2>Project Merge Preferences</h2>
	<p style="max-width:700px;">
		Projects listed here use <strong>union</strong> tag-merge semantics on upload even with no
		collaborators: geologic units absent from a device's upload are kept, never deleted.
		Use this for groups running multiple devices on one shared account. Note: on flagged
		projects, deleting a unit from the app will no longer stick (remove it server-side instead),
		and a project that later gains real collaborators no longer needs its flag.
	</p>
</div>

<?php if($flash != ""){ ?>
	<div align="center" style="color:green; margin:10px;"><?php echo htmlspecialchars($flash); ?></div>
<?php } ?>
<?php if($flasherror != ""){ ?>
	<div align="center" style="color:red; margin:10px;"><?php echo htmlspecialchars($flasherror); ?></div>
<?php } ?>

<div align="center" style="margin:20px;">
	<form method="post" action="/admin_merge_prefs">
		<input type="hidden" name="action" value="add">
		<input type="text" name="project_id" placeholder="Project ID" style="width:180px;" required>
		<input type="text" name="note" placeholder="Note (who / why)" style="width:280px;">
		<button type="submit">Flag Project</button>
	</form>
</div>

<div class="strabotable" style="margin-left:0px;">
	<table>
		<tr>
			<td>Project ID</td>
			<td>Project Name</td>
			<td>Owner</td>
			<td>Collaborators</td>
			<td>Note</td>
			<td>Flagged</td>
			<td>&nbsp;</td>
		</tr>
<?php
if($rows){
	foreach($rows as $row){
?>
		<tr>
			<td><?php echo htmlspecialchars($row->strabo_project_id); ?></td>
			<td><?php echo htmlspecialchars($projectnames[$row->pkey]); ?></td>
			<td><?php echo htmlspecialchars($row->owner_email . " (" . $row->project_owner_user_pkey . ")"); ?></td>
			<td><?php echo (int)$row->collabcount; ?><?php if((int)$row->collabcount > 0){ echo " (flag redundant)"; } ?></td>
			<td><?php echo htmlspecialchars($row->note); ?></td>
			<td><?php echo htmlspecialchars(substr($row->created_date, 0, 10)); ?></td>
			<td>
				<form method="post" action="/admin_merge_prefs" onsubmit="return confirm('Remove the union-merge flag from project <?php echo htmlspecialchars($row->strabo_project_id); ?>? Its uploads go back to solo replace semantics.');">
					<input type="hidden" name="action" value="remove">
					<input type="hidden" name="pkey" value="<?php echo (int)$row->pkey; ?>">
					<button type="submit">Remove</button>
				</form>
			</td>
		</tr>
<?php
	}
}else{
?>
		<tr><td colspan="7">No projects flagged.</td></tr>
<?php
}
?>
	</table>
</div>

<script>
	if(window.history.replaceState && (window.location.search.indexOf('msg=') !== -1 || window.location.search.indexOf('err=') !== -1)){
		window.history.replaceState(null, '', window.location.pathname);
	}
</script>

<?php
include("includes/footer.php");
?>
