<?php
/**
 * File: micrographBigWindow.php
 * Description: Micrograph data handler
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

SESSION_START();
include("prepare_connections.php");
include 'microdb/microLandingClass.php';

$micrograph_id = isset($_GET['micrograph_id']) ? (int)$_GET['micrograph_id'] : 0;
$pkey = isset($_GET['pkey']) ? (int)$_GET['pkey'] : 0;

$row = $db->get_row_prepared("SELECT * FROM micro_projectmetadata WHERE id = $1 AND (ispublic OR userpkey=$2)", array($pkey, $userpkey));

if($row->id == ""){
	echo "Error! Project not found.";
	exit();
}

$json = $row->projectjson;
$json = json_decode($json);
// Defensive: overlay strabosamples.* spine edits onto the samples so any
// sample-field rendering in this pane reflects Samples-app edits. showMicrograph
// currently renders only the image/scale-bar/spot-map (no sample fields), but
// keep this consistent with the other Micro read surfaces (microproject.php,
// micrographDetailsPane.php) so a future field add here can't go stale. Owner is
// the project owner ($row->userpkey), which differs from the viewer on a public
// project. No-op when there are no spine edits.
require_once __DIR__ . '/microdb/lib/sample_overlay.php';
micro_sample_overlay_apply($json, $db, (int)$row->userpkey);
$json->pkey = $pkey;
$ml = new MicroLanding($json);

$ml->showMicrograph($micrograph_id);

?>