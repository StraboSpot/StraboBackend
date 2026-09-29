<?php
/**
 * File: upload_once.php
 * Description: Child process for equivalence_test.php. Runs ONE jwtmicrodb
 *              StraboMicro::insertProject (or deleteProject) through either the
 *              old delete-and-recreate path or the new in-place path. The
 *              MICRO_INPLACE_REBUILD constant can only be set once per PHP
 *              process, so each operation runs in its own process.
 *
 *              Usage (inside strabo-php):
 *                php upload_once.php <old|new> <userpkey> <zipPath> <straboId>
 *                php upload_once.php delete <userpkey> - <straboId>
 *
 *              Prints one JSON line: {"result": {...}, "pid": <micro_projectmetadata.id or null>}
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if ($argc < 5) {
	fwrite(STDERR, "usage: php upload_once.php <old|new|delete> <userpkey> <zipPath|-> <straboId>\n");
	exit(2);
}
list(, $mode, $userpkey, $zipPath, $straboId) = $argv;
$userpkey = (int)$userpkey;

define('MICRO_INPLACE_REBUILD', $mode === 'new');
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/neodb.php';
require_once '/srv/app/www/jwtmicrodb/strabomicroclass.php';
require_once '/srv/app/www/includes/UUID.php';

$sm = new StraboMicro($neodb, $userpkey, $db);
$sm->setuserpkey($userpkey);
$sm->setuuid(new UUID());

if ($mode === 'delete') {
	$sm->deleteProject($straboId);
	$result = array('deleted' => true);
} else {
	// insertProject copies (never moves) tmp_name, so the fixture survives.
	$post  = array('project_id' => $straboId, 'overwrite' => 'yes');
	$files = array('tmp_name' => $zipPath, 'name' => basename($zipPath));
	$result = $sm->insertProject($post, $files);
}

$pid = $db->get_var_prepared(
	"SELECT id FROM micro_projectmetadata WHERE userpkey = $1 AND strabo_id = $2",
	array($userpkey, $straboId));

echo json_encode(array('result' => $result, 'pid' => ($pid === null || $pid === '') ? null : (int)$pid)) . "\n";
