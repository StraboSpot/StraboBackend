<?php
/**
 * File: exportjobs/lib/ExportPlugin.php
 * Description: Per-subsystem export plugin contract (design §8.5). The job
 *              system is subsystem-agnostic: it claims a job, hands the
 *              recipe to the plugin named by recipe.plugin, packages whatever
 *              the plugin wrote into the bundle directory, and records the
 *              outcome. M1 ships only the EchoExportPlugin (tests); the
 *              FieldExportPlugin arrives with M2/M3.
 *
 * @package    StraboSpot Web Site
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

interface ExportPlugin
{
	/** Stable key matched against recipe['plugin'] (e.g. "field", "echo"). */
	public function key();

	/**
	 * Run one job. Write every output file under $bundleDir (it becomes the
	 * zip root). Call $progress($phase, $done, $total, $note) at phase
	 * boundaries and inside long loops; it also refreshes the heartbeat and
	 * throws ExportCancelled if the user cancelled.
	 *
	 * @param array    $job        the export_jobs row (assoc)
	 * @param array    $recipe     decoded recipe JSON
	 * @param string   $bundleDir  absolute, exists, empty
	 * @param callable $progress   function($phase, $done, $total, $note)
	 * @return array   {item_count:int, child_count:int, readme:string[], warnings:string[]}
	 */
	public function run(array $job, array $recipe, $bundleDir, $progress);
}

class ExportJobError extends Exception {}
class ExportCancelled extends Exception {}
