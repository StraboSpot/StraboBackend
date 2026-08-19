<?php
/**
 * File: ajax.php
 * Description: Template Wizard JSON endpoints — template save/delete/list
 *              and the project→datasets lookup for the target picker.
 *              Session-gated; owner-only by construction (service scopes
 *              every query to the logged-in userpkey).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

chdir(dirname(__DIR__));
include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/services/FieldTabularService.php";

header('Content-Type: application/json');

$svc = new FieldTabularService($db, $neodb, $strabo);
$svc->setUserpkey($userpkey);

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

try {
    switch ($action) {

        case 'templates':
            $out = array();
            foreach ($svc->listTemplates() as $t) {
                $out[] = array('pkey' => (int)$t->pkey, 'name' => $t->name);
            }
            echo json_encode(array('ok' => true, 'templates' => $out));
            break;

        case 'datasets':
            $projectId = isset($_REQUEST['project_id']) ? (int)$_REQUEST['project_id'] : 0;
            echo json_encode(array('ok' => true, 'datasets' => $svc->projectDatasets($projectId)));
            break;

        case 'save_template':
            $name = isset($_POST['name']) ? $_POST['name'] : '';
            $spec = json_decode(isset($_POST['spec_json']) ? $_POST['spec_json'] : '', true);
            $pkey = (isset($_POST['pkey']) && $_POST['pkey'] !== '') ? (int)$_POST['pkey'] : null;
            if (!is_array($spec)) {
                echo json_encode(array('ok' => false, 'message' => 'Malformed template spec.'));
                break;
            }
            echo json_encode($svc->saveTemplate($name, $spec, $pkey));
            break;

        case 'delete_template':
            $pkey = isset($_POST['pkey']) ? (int)$_POST['pkey'] : 0;
            echo json_encode($svc->deleteTemplate($pkey));
            break;

        default:
            echo json_encode(array('ok' => false, 'message' => 'Unknown action.'));
    }
} catch (Exception $e) {
    echo json_encode(array('ok' => false, 'message' => 'Server error: ' . $e->getMessage()));
}
