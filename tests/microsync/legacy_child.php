<?php
/**
 * File: legacy_child.php
 * Description: Runs one legacy StraboMicro operation in its own process, so
 *              tests/microsync/worker_test.php can drive both legacy classes
 *              (jwtmicrodb and microdb define the same class name).
 *
 *              Usage: php legacy_child.php <jwt|microdb> <upload|uploadnofile|delete|list> <userpkey> <zipPath|-> <straboId|->
 *              Prints one JSON line: {"result": ...}
 */

if ($argc < 6) {
	fwrite(STDERR, "usage: php legacy_child.php <jwt|microdb> <upload|delete|list> <userpkey> <zip|-> <straboId|->\n");
	exit(2);
}
list(, $api, $action, $userpkey, $zipPath, $straboId) = $argv;
$userpkey = (int)$userpkey;
$_SERVER['DOCUMENT_ROOT'] = '/srv/app/www';

require_once '/srv/app/www/includes/config.inc.php';
require_once '/srv/app/www/db.php';
require_once '/srv/app/www/includes/UUID.php';
require_once $api === 'microdb' ? '/srv/app/www/microdb/strabomicroclass.php' : '/srv/app/www/jwtmicrodb/strabomicroclass.php';

$sm = new StraboMicro(null, $userpkey, $db);
$sm->setuserpkey($userpkey);
$sm->setuuid(new UUID());

if ($action === 'upload') {
	$result = $sm->insertProject(array('project_id' => $straboId, 'overwrite' => 'yes'),
		array('tmp_name' => $zipPath, 'name' => basename($zipPath)));
} elseif ($action === 'uploadnofile') {
	// The chunked path: the app sends the file in parts first, then calls
	// insertProjectWithoutFile, which reads it from the temp folder.
	$tmp = '/StraboData/bigDriveData/tempFiles/micro_' . $straboId . '.zip';
	copy($zipPath, $tmp);
	$result = $sm->insertProjectWithoutFile(array('project_id' => $straboId, 'overwrite' => 'yes'));
} elseif ($action === 'delete') {
	$result = $sm->deleteProject($straboId);
} elseif ($action === 'list') {
	$result = $sm->getMyProjects();
} else {
	fwrite(STDERR, "unknown action\n");
	exit(2);
}
echo json_encode(array('result' => $result)) . "\n";
