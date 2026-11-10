<?php
/**
 * File: admin_field_camp_instructors.php
 * Description: Admin page (userpkey 3 only) for the Field Camp Wizard's
 *              instructor list: the accounts that may open the wizard, set up
 *              camps and create camp accounts. Accounts are picked by email
 *              search. Removing an instructor only closes the wizard for them;
 *              their camps and camp accounts stay.
 *              See sql/fieldcamp.sql and includes/fieldcamp/FieldCamp.php.
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

if(!FieldCamp::isAdmin($userpkey)) die("Not authorized.");
if(!FieldCamp::installed($db)) die("The Field Camp tables are missing. Apply sql/fieldcamp.sql first.");

// ---------------------------------------------------------------------------
// AJAX: account search (JSON, no page chrome)
// ---------------------------------------------------------------------------
if(isset($_GET['ajax'])){
	header('Content-Type: application/json');
	$out = array();
	if($_GET['ajax'] === "usersearch"){
		$q = trim($_GET['q'] ?? '');
		if(strlen($q) >= 2){
			$like = "%" . strtolower($q) . "%";
			$rows = $db->get_results_prepared("
				select pkey, email, firstname, lastname
				from users
				where deleted is not true and active is true
				and (lower(email) like $1 or lower(firstname || ' ' || lastname) like $1)
				and pkey not in (select user_pkey from fieldcamp_account)
				and pkey not in (select user_pkey from fieldcamp_instructors)
				order by email
				limit 10
				", array($like));
			if($rows){
				foreach($rows as $r){
					$out[] = array(
						'pkey'  => (int)$r->pkey,
						'email' => $r->email,
						'name'  => trim($r->firstname . " " . $r->lastname)
					);
				}
			}
		}
	}
	echo json_encode($out);
	exit();
}

// ---------------------------------------------------------------------------
// Add / remove (POST-redirect-GET)
// ---------------------------------------------------------------------------
if($_SERVER['REQUEST_METHOD'] === 'POST'){

	if(!FieldCamp::tokenOk($_POST['csrf'] ?? null)){
		header("Location: /admin_field_camp_instructors?err=" . urlencode("The page had expired. Please try again."));
		exit();
	}
	$action = $_POST['action'] ?? '';

	if($action === "add"){
		$problem = FieldCamp::addInstructor($db, (int)($_POST['user_pkey'] ?? 0), (string)($_POST['note'] ?? ''), $userpkey);
		if($problem !== ''){
			header("Location: /admin_field_camp_instructors?err=" . urlencode($problem));
			exit();
		}
		header("Location: /admin_field_camp_instructors?msg=" . urlencode("Instructor added."));
		exit();
	}elseif($action === "remove"){
		FieldCamp::removeInstructor($db, (int)($_POST['pkey'] ?? 0));
		header("Location: /admin_field_camp_instructors?msg=" . urlencode("Instructor removed."));
		exit();
	}

	header("Location: /admin_field_camp_instructors");
	exit();
}

$flash = $_GET['msg'] ?? '';
$flasherror = $_GET['err'] ?? '';
$rows = FieldCamp::listInstructors($db);
$csrf = FieldCamp::token();

include("includes/mheader.php");
include("includes/fieldcamp/fieldcamp_style.php");
?>

<style>
.fci-field { position: relative; }
.fci-userlist { position: absolute; z-index: 50; background: #2a2a3a;
	border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 4px;
	max-width: 460px; width: 100%; box-sizing: border-box; max-height: 260px; overflow-y: auto;
	margin: 0; padding: 0; list-style: none; box-shadow: 0 3px 10px rgba(0, 0, 0, 0.5); }
.fci-userlist li { padding: 0.4em 0.6em; cursor: pointer; color: #ffffff;
	border-bottom: 1px solid rgba(255, 255, 255, 0.1); text-align: left; }
.fci-userlist li:hover { background: rgba(255, 255, 255, 0.08); }
.fci-userlist li .fci-uname { color: rgba(255, 255, 255, 0.6); font-size: 0.9em; }
.fci-chosen { display: inline-block; background: rgba(255, 255, 255, 0.12);
	border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 4px; color: #ffffff;
	padding: 0.3em 0.6em; margin-bottom: 0.5em; }
.fci-chosen a { margin-left: 0.6em; cursor: pointer; color: #e44c65; }
</style>

<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Field Camp Instructors</h2>
		</header>

		<p class="fc-intro">
			Accounts listed here see <strong>Field Camp Wizard</strong> in their account menu and can set up
			camps and create camp accounts. Removing someone closes the wizard for them; their camps and
			camp accounts stay.
		</p>

<?php if($flash != ""){ ?>
		<div class="fc-flash"><?php echo htmlspecialchars($flash); ?></div>
<?php } ?>
<?php if($flasherror != ""){ ?>
		<div class="fc-flash-err"><?php echo htmlspecialchars($flasherror); ?></div>
<?php } ?>

		<div class="fc-top">
			<div><a href="/field_camp_wizard" class="button small">Field Camp Wizard</a></div>
		</div>

		<div class="fc-panel">
			<h4>Add an instructor</h4>
			<div class="fc-field fci-field" id="fciUserField">
				<label for="fciUserSearch">Find the account (email or name)</label>
				<div id="fciChosenUser" style="display:none;"></div>
				<input type="text" id="fciUserSearch" placeholder="Start typing an email..." autocomplete="off">
				<ul class="fci-userlist" id="fciUserList" style="display:none;"></ul>
			</div>
			<form method="post" action="/admin_field_camp_instructors" id="fciAddForm" style="display:none;">
				<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
				<input type="hidden" name="action" value="add">
				<input type="hidden" name="user_pkey" id="fciUserPkey" value="">
				<div class="fc-field">
					<label for="fciNote">Note (school / why)</label>
					<input type="text" name="note" id="fciNote" placeholder="e.g. KU field camp director">
				</div>
				<div class="fc-buttons"><button type="submit" class="button primary small">Add instructor</button></div>
			</form>
		</div>

		<h4>Current instructors</h4>
		<div class="strabotable" style="margin-left:0px;">
			<table class="fc-table">
				<tr>
					<td>Account</td>
					<td>Name</td>
					<td>Camps</td>
					<td>Note</td>
					<td>Added</td>
					<td>&nbsp;</td>
				</tr>
<?php
if($rows){
	foreach($rows as $row){
?>
				<tr>
					<td><?php echo htmlspecialchars($row->email . " (" . $row->user_pkey . ")"); ?></td>
					<td><?php echo htmlspecialchars(trim($row->firstname . " " . $row->lastname)); ?></td>
					<td><?php echo (int)$row->campcount; ?></td>
					<td><?php echo htmlspecialchars((string)$row->note); ?></td>
					<td><?php echo htmlspecialchars(substr((string)$row->created_date, 0, 10)); ?></td>
					<td>
						<form method="post" action="/admin_field_camp_instructors" style="margin:0;" onsubmit="return confirm('Remove <?php echo htmlspecialchars($row->email, ENT_QUOTES); ?> from the instructor list? Their camps and camp accounts stay.');">
							<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
							<input type="hidden" name="action" value="remove">
							<input type="hidden" name="pkey" value="<?php echo (int)$row->pkey; ?>">
							<button type="submit" class="button small">Remove</button>
						</form>
					</td>
				</tr>
<?php
	}
}else{
?>
				<tr><td colspan="6">No instructors yet. (You, as the admin, always have access.)</td></tr>
<?php
}
?>
			</table>
		</div>

		<div class="bottomSpacer"></div>
	</div>
</div>

<script>
(function(){
	var searchInput = document.getElementById('fciUserSearch');
	var userList = document.getElementById('fciUserList');
	var chosenUser = document.getElementById('fciChosenUser');
	var addForm = document.getElementById('fciAddForm');
	var pkeyInput = document.getElementById('fciUserPkey');
	var debounceTimer = null;

	function esc(s){
		var d = document.createElement('div');
		d.textContent = (s === null || s === undefined) ? '' : String(s);
		return d.innerHTML;
	}

	function reset(){
		chosenUser.style.display = 'none';
		chosenUser.innerHTML = '';
		searchInput.style.display = '';
		searchInput.value = '';
		addForm.style.display = 'none';
		pkeyInput.value = '';
	}

	searchInput.addEventListener('input', function(){
		clearTimeout(debounceTimer);
		var q = searchInput.value.trim();
		if(q.length < 2){ userList.style.display = 'none'; return; }
		debounceTimer = setTimeout(function(){
			fetch('/admin_field_camp_instructors?ajax=usersearch&q=' + encodeURIComponent(q))
				.then(function(r){ return r.json(); })
				.then(function(users){
					userList.innerHTML = '';
					if(!users.length){
						userList.innerHTML = '<li>No matching accounts.</li>';
					}
					users.forEach(function(u){
						var li = document.createElement('li');
						li.innerHTML = esc(u.email) + ' <span class="fci-uname">' + esc(u.name) + ' (' + u.pkey + ')</span>';
						li.addEventListener('click', function(){ pick(u); });
						userList.appendChild(li);
					});
					userList.style.display = '';
				});
		}, 250);
	});

	document.addEventListener('click', function(e){
		if(!document.getElementById('fciUserField').contains(e.target)){
			userList.style.display = 'none';
		}
	});

	function pick(u){
		userList.style.display = 'none';
		searchInput.style.display = 'none';
		chosenUser.className = 'fci-chosen';
		chosenUser.innerHTML = esc(u.email) + ' <span class="fci-uname">' + esc(u.name) + '</span> <a id="fciChange">(change)</a>';
		chosenUser.style.display = '';
		document.getElementById('fciChange').addEventListener('click', reset);
		pkeyInput.value = u.pkey;
		addForm.style.display = '';
	}

	if(window.history.replaceState && (window.location.search.indexOf('msg=') !== -1 || window.location.search.indexOf('err=') !== -1)){
		window.history.replaceState(null, '', window.location.pathname);
	}
})();
</script>

<?php
include("includes/mfooter.php");
?>
