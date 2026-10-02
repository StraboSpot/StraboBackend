<?php
/**
 * File: micro_collaborators.php
 * Description: Read-only list of a synced StraboMicro project's members
 *              (collaboration Phase 2, 17a): name, email, role, state.
 *              Members are invited and managed in StraboMicro
 *              (File > Collaborate...). Open to the project's active members.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once(__DIR__ . '/microdb/lib/micro_members_web.php');

$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$msdb = micro_members_db($db);
try {
	$p = MsStore::project($msdb, $project_id, (int)$userpkey);
} catch (MsHttpError $e) {
	exit('Project not found.');
}
$list = MsMembers::memberList($msdb, $p);
$name = $db->get_var_prepared(
	"SELECT COALESCE((SELECT e.body->>'name' FROM strabomicro.micro_entities e
	                   WHERE e.project_id = p.id AND e.entity_type = 'project' AND e.entity_id = p.strabo_id), p.name)
	   FROM strabomicro.micro_projectmetadata p WHERE p.id = $1",
	array($project_id));
$stateText = array('active' => 'Member', 'invited' => 'Invited', 'declined' => 'Declined');

include 'includes/mheader.php';
?>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Collaborators</h2>
		</header>

		<section id="content">
			<h3><?php echo htmlspecialchars((string)$name)?></h3>
			<p>Collaborators are invited and managed in StraboMicro: open the project and choose File &gt; Collaborate...</p>

			<div class="table-wrapper">
				<table class="myDataTable">
					<thead>
						<tr>
							<th>Name</th>
							<th class="hideSmall">Email</th>
							<th>Role</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
<?php foreach ($list['members'] as $m) { ?>
						<tr>
							<td><?php echo htmlspecialchars($m['user']['name'])?></td>
							<td class="hideSmall"><?php echo htmlspecialchars(isset($m['user']['email']) ? $m['user']['email'] : '')?></td>
							<td><?php echo htmlspecialchars(micro_role_label($m['role']))?></td>
							<td><?php echo htmlspecialchars(isset($stateText[$m['state']]) ? $stateText[$m['state']] : $m['state'])?></td>
						</tr>
<?php } ?>
					</tbody>
				</table>
			</div>
<?php if ($list['transferTo'] !== null) { ?>
			<p>Ownership has been offered to <?php echo htmlspecialchars($list['transferTo']['name'])?> and is waiting for them to accept in StraboMicro.</p>
<?php } ?>
			<p><a href="/my_micro_data" class="button primary small">Back to My StraboMicro Data</a></p>
		</section>

	<div class="bottomSpacer"></div>

	</div>
</div>

<?php
include 'includes/mfooter.php';
