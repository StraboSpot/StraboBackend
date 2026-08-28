<?php
/**
 * File: admin_merge_prefs.php
 * Description: Admin page (userpkey 3 only) for per-project merge preferences.
 *              Flagging a project forces UNION tag-merge semantics on upload
 *              even with zero collaborator rows - the escape hatch for
 *              multi-device groups sharing one set of credentials, whose
 *              uploads would otherwise wipe each other's geologic units
 *              under the solo REPLACE semantics (2026-08-16 tag fix).
 *              Projects are picked owner-first (email search, then that
 *              user's project list) because Strabo project ids are NOT
 *              unique across accounts.
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

// ---------------------------------------------------------------------------
// AJAX endpoints (JSON, no page chrome)
// ---------------------------------------------------------------------------
if(isset($_GET['ajax'])){
	header('Content-Type: application/json');

	if($_GET['ajax'] === "usersearch"){

		$q = trim($_GET['q'] ?? '');
		if(strlen($q) < 2){ echo json_encode(array()); exit(); }

		$like = "%" . strtolower($q) . "%";
		$rows = $db->get_results_prepared("
			select pkey, email, firstname, lastname
			from users
			where deleted is not true
			and (lower(email) like $1 or lower(firstname || ' ' || lastname) like $1)
			order by email
			limit 10
			", array($like));

		$out = array();
		if($rows){
			foreach($rows as $r){
				$out[] = array(
					'pkey'  => (int)$r->pkey,
					'email' => $r->email,
					'name'  => trim($r->firstname . " " . $r->lastname)
				);
			}
		}
		echo json_encode($out);
		exit();

	}elseif($_GET['ajax'] === "userprojects"){

		$opkey = (int)($_GET['pkey'] ?? 0);
		if($opkey <= 0){ echo json_encode(array()); exit(); }

		$flaggedids = array();
		$frows = $db->get_results_prepared(
			"select strabo_project_id from project_merge_prefs where project_owner_user_pkey = $1 and union_tags = true",
			array($opkey));
		if($frows){
			foreach($frows as $fr){ $flaggedids[] = (string)$fr->strabo_project_id; }
		}

		// Property match (not bare id) - userpkey disambiguates; Project label
		// is small, and this also catches projects missing their HAS_PROJECT edge.
		$records = $neodb->get_results("
			match (p:Project) where p.userpkey = $opkey
			return distinct p.id as id, p.desc_project_name as pname,
				p.projectname as legacyname, p.modified_timestamp as mt
			");

		$out = array();
		if($records){
			foreach($records as $rec){
				$pid = (string)$rec->get("id");
				if($pid === "") continue;
				$pname = $rec->get("pname");
				if($pname == "") $pname = $rec->get("legacyname");
				if($pname == "") $pname = "(unnamed project)";
				$mt = $rec->get("mt");
				$mdate = "";
				if(is_numeric($mt) && (float)$mt > 0){
					$mdate = date("Y-m-d", (int)((float)$mt / 1000));
				}
				$out[] = array(
					'id'       => $pid,
					'name'     => $pname,
					'modified' => $mdate,
					'flagged'  => in_array($pid, $flaggedids)
				);
			}
		}
		// Most recently modified first.
		usort($out, function($a, $b){ return strcmp($b['modified'], $a['modified']); });
		echo json_encode($out);
		exit();
	}

	echo json_encode(array());
	exit();
}

// ---------------------------------------------------------------------------
// Add / remove (POST-redirect-GET)
// ---------------------------------------------------------------------------
if($_SERVER['REQUEST_METHOD'] === 'POST'){

	$action = $_POST['action'] ?? '';

	if($action === "add"){

		$projectid = trim($_POST['project_id'] ?? '');
		$ownerpkey = (int)($_POST['owner_pkey'] ?? 0);
		$note = trim($_POST['note'] ?? '');

		if(!preg_match('/^\d+$/', $projectid) || $ownerpkey <= 0){
			header("Location: /admin_merge_prefs?err=" . urlencode("Pick a user and one of their projects first."));
			exit();
		}

		// Confirm the (id, owner) pair really exists - ids are not unique alone.
		$prow = $neodb->getRecord("match (p:Project {id:" . (int)$projectid . ", userpkey:" . $ownerpkey . "}) return p.id as id limit 1");
		if(!$prow){
			header("Location: /admin_merge_prefs?err=" . urlencode("No project $projectid found for that user."));
			exit();
		}

		$existing = (int)$db->get_var_prepared(
			"select count(*) from project_merge_prefs where strabo_project_id = $1 and project_owner_user_pkey = $2",
			array($projectid, $ownerpkey));

		if($existing > 0){
			header("Location: /admin_merge_prefs?err=" . urlencode("Project $projectid is already flagged for that user."));
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

$flash = $_GET['msg'] ?? '';
$flasherror = $_GET['err'] ?? '';

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

// Resolve project names from Neo4j (small list; one lookup per row, keyed by
// id + owner because ids are not unique).
$projectnames = array();
if($rows){
	foreach($rows as $row){
		$prow = $neodb->getRecord("match (p:Project {id:" . (int)$row->strabo_project_id . ", userpkey:" . (int)$row->project_owner_user_pkey . "}) return p.desc_project_name as pname, p.projectname as legacyname limit 1");
		if($prow){
			$pname = $prow->get("pname");
			if($pname == "") $pname = $prow->get("legacyname");
			if($pname == "") $pname = "(unnamed project)";
			$projectnames[$row->pkey] = $pname;
		}else{
			$projectnames[$row->pkey] = "(project not found)";
		}
	}
}

include("includes/mheader.php");
?>

<style>
.amp-wrap { max-width: 900px; margin: 0 auto; padding: 0 1em; }
.amp-intro { max-width: 700px; margin: 0 auto 1.5em auto; }
.amp-flash { text-align: center; margin: 10px; color: #2e7d32; }
.amp-flash-err { text-align: center; margin: 10px; color: #c62828; }
.amp-picker { border: 1px solid #ccc; border-radius: 6px; padding: 1em 1.25em; margin-bottom: 2em; }
.amp-picker h4 { margin-top: 0; }
.amp-field { margin-bottom: 0.75em; position: relative; }
.amp-field label { display: block; font-weight: bold; margin-bottom: 0.25em; }
.amp-field input[type=text] { width: 100%; max-width: 420px; box-sizing: border-box; }
.amp-userlist { position: absolute; z-index: 50; background: #fff; border: 1px solid #aaa;
	max-width: 420px; width: 100%; box-sizing: border-box; max-height: 260px; overflow-y: auto;
	margin: 0; padding: 0; list-style: none; box-shadow: 0 3px 8px rgba(0,0,0,0.15); }
.amp-userlist li { padding: 0.4em 0.6em; cursor: pointer; border-bottom: 1px solid #eee; }
.amp-userlist li:hover { background: #f0f0f0; }
.amp-userlist li .amp-uname { color: #666; font-size: 0.9em; }
.amp-chosen { display: inline-block; background: #eef3f7; border: 1px solid #b8c8d4;
	border-radius: 4px; padding: 0.3em 0.6em; margin-bottom: 0.5em; }
.amp-chosen a { margin-left: 0.6em; cursor: pointer; }
.amp-projlist { border: 1px solid #ccc; max-width: 640px; max-height: 320px; overflow-y: auto;
	margin: 0.25em 0 0.75em 0; padding: 0; list-style: none; }
.amp-projlist li { padding: 0.45em 0.6em; cursor: pointer; border-bottom: 1px solid #eee;
	display: flex; justify-content: space-between; gap: 1em; }
.amp-projlist li:hover { background: #f0f0f0; }
.amp-projlist li.amp-selected { background: #dbe9f5; }
.amp-projlist li.amp-alreadyflagged { color: #999; cursor: default; }
.amp-projlist li .amp-pmeta { color: #666; font-size: 0.85em; white-space: nowrap; }
.amp-empty { color: #666; font-style: italic; }
.amp-submitrow { margin-top: 0.75em; }
.amp-table td { padding: 0.35em 0.6em; }
</style>

<div class="amp-wrap">

	<div align="center">
		<h2>Project Merge Preferences</h2>
		<p class="amp-intro">
			Projects listed here use <strong>union</strong> tag-merge semantics on upload even with no
			collaborators: geologic units absent from a device's upload are kept, never deleted.
			Use this for groups running multiple devices on one shared account. Note: on flagged
			projects, deleting a unit from the app will no longer stick (remove it server-side instead),
			and a project that later gains real collaborators no longer needs its flag.
		</p>
	</div>

<?php if($flash != ""){ ?>
	<div class="amp-flash"><?php echo htmlspecialchars($flash); ?></div>
<?php } ?>
<?php if($flasherror != ""){ ?>
	<div class="amp-flash-err"><?php echo htmlspecialchars($flasherror); ?></div>
<?php } ?>

	<div class="amp-picker">
		<h4>Flag a project</h4>

		<div class="amp-field" id="ampUserField">
			<label for="ampUserSearch">1. Find the account (email or name)</label>
			<div id="ampChosenUser" style="display:none;"></div>
			<input type="text" id="ampUserSearch" placeholder="Start typing an email..." autocomplete="off">
			<ul class="amp-userlist" id="ampUserList" style="display:none;"></ul>
		</div>

		<div class="amp-field" id="ampProjectField" style="display:none;">
			<label>2. Choose the project</label>
			<ul class="amp-projlist" id="ampProjList"></ul>
		</div>

		<form method="post" action="/admin_merge_prefs" id="ampAddForm" style="display:none;">
			<input type="hidden" name="action" value="add">
			<input type="hidden" name="project_id" id="ampProjectId" value="">
			<input type="hidden" name="owner_pkey" id="ampOwnerPkey" value="">
			<div class="amp-field">
				<label for="ampNote">3. Note (who / why)</label>
				<input type="text" name="note" id="ampNote" placeholder="e.g. Wesdome 3-iPad group, Craig Pearman">
			</div>
			<div class="amp-submitrow">
				<button type="submit" id="ampSubmit" disabled>Flag Project</button>
				<span id="ampSummary" style="margin-left:0.75em; color:#444;"></span>
			</div>
		</form>
	</div>

	<h4>Currently flagged projects</h4>
	<div class="strabotable" style="margin-left:0px;">
		<table class="amp-table">
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

</div>

<script>
(function(){
	var searchInput = document.getElementById('ampUserSearch');
	var userList = document.getElementById('ampUserList');
	var chosenUser = document.getElementById('ampChosenUser');
	var projectField = document.getElementById('ampProjectField');
	var projList = document.getElementById('ampProjList');
	var addForm = document.getElementById('ampAddForm');
	var projectIdInput = document.getElementById('ampProjectId');
	var ownerPkeyInput = document.getElementById('ampOwnerPkey');
	var submitBtn = document.getElementById('ampSubmit');
	var summary = document.getElementById('ampSummary');

	var debounceTimer = null;
	var selectedUser = null;

	function esc(s){
		var d = document.createElement('div');
		d.textContent = (s === null || s === undefined) ? '' : String(s);
		return d.innerHTML;
	}

	function resetProjectStep(){
		projectField.style.display = 'none';
		addForm.style.display = 'none';
		projList.innerHTML = '';
		projectIdInput.value = '';
		ownerPkeyInput.value = '';
		submitBtn.disabled = true;
		summary.textContent = '';
	}

	function resetUserStep(){
		selectedUser = null;
		chosenUser.style.display = 'none';
		chosenUser.innerHTML = '';
		searchInput.style.display = '';
		searchInput.value = '';
		resetProjectStep();
	}

	searchInput.addEventListener('input', function(){
		clearTimeout(debounceTimer);
		var q = searchInput.value.trim();
		if(q.length < 2){
			userList.style.display = 'none';
			return;
		}
		debounceTimer = setTimeout(function(){
			fetch('/admin_merge_prefs?ajax=usersearch&q=' + encodeURIComponent(q))
				.then(function(r){ return r.json(); })
				.then(function(users){
					userList.innerHTML = '';
					if(!users.length){
						userList.innerHTML = '<li class="amp-empty">No matching users.</li>';
					}
					users.forEach(function(u){
						var li = document.createElement('li');
						li.innerHTML = esc(u.email) + ' <span class="amp-uname">' + esc(u.name) + ' (' + u.pkey + ')</span>';
						li.addEventListener('click', function(){ pickUser(u); });
						userList.appendChild(li);
					});
					userList.style.display = '';
				});
		}, 250);
	});

	document.addEventListener('click', function(e){
		if(!document.getElementById('ampUserField').contains(e.target)){
			userList.style.display = 'none';
		}
	});

	function pickUser(u){
		selectedUser = u;
		userList.style.display = 'none';
		searchInput.style.display = 'none';
		chosenUser.className = 'amp-chosen';
		chosenUser.innerHTML = esc(u.email) + ' <span class="amp-uname">' + esc(u.name) + '</span> <a id="ampChangeUser">(change)</a>';
		chosenUser.style.display = '';
		document.getElementById('ampChangeUser').addEventListener('click', resetUserStep);
		loadProjects(u.pkey);
	}

	function loadProjects(pkey){
		resetProjectStep();
		projectField.style.display = '';
		projList.innerHTML = '<li class="amp-empty">Loading projects...</li>';
		fetch('/admin_merge_prefs?ajax=userprojects&pkey=' + pkey)
			.then(function(r){ return r.json(); })
			.then(function(projects){
				projList.innerHTML = '';
				if(!projects.length){
					projList.innerHTML = '<li class="amp-empty">This user has no projects.</li>';
					return;
				}
				projects.forEach(function(p){
					var li = document.createElement('li');
					var meta = (p.modified ? 'modified ' + esc(p.modified) : '') + (p.flagged ? ' &middot; already flagged' : '');
					li.innerHTML = '<span>' + esc(p.name) + ' <span class="amp-pmeta">(' + esc(p.id) + ')</span></span>'
						+ '<span class="amp-pmeta">' + meta + '</span>';
					if(p.flagged){
						li.className = 'amp-alreadyflagged';
					}else{
						li.addEventListener('click', function(){ pickProject(p, li); });
					}
					projList.appendChild(li);
				});
				addForm.style.display = '';
			});
	}

	function pickProject(p, li){
		Array.prototype.forEach.call(projList.children, function(c){ c.classList.remove('amp-selected'); });
		li.classList.add('amp-selected');
		projectIdInput.value = p.id;
		ownerPkeyInput.value = selectedUser.pkey;
		submitBtn.disabled = false;
		summary.textContent = 'Flag "' + p.name + '" (' + p.id + ') owned by ' + selectedUser.email;
	}

	if(window.history.replaceState && (window.location.search.indexOf('msg=') !== -1 || window.location.search.indexOf('err=') !== -1)){
		window.history.replaceState(null, '', window.location.pathname);
	}
})();
</script>

<?php
include("includes/mfooter.php");
?>
