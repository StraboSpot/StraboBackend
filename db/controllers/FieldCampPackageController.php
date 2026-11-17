<?php
/**
 * File: FieldCampPackageController.php
 * Description: The first address of a field camp account's package
 *              (2026-11-10), kept working: GET / HEAD /db/fieldCampPackage
 *              and POST /db/fieldCampPackage/installed answer for the
 *              account's own camp, exactly as /db/projectPackage/{id} does
 *              (ProjectPackageController). New code uses /db/projectPackage.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/ProjectPackageController.php';

class FieldCampPackageController extends ProjectPackageController
{
	/** No id in the address: the account's own camp. */
	protected function load($request)
	{
		require_once __DIR__ . '/../../includes/fieldcamp/FieldCampPackage.php';
		return FieldCampPackage::accountCamp($this->strabo->db, $this->strabo->userpkey);
	}

	protected function installedAt() { return 2; }
}
