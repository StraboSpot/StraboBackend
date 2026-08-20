<?php
/**
 * File: JsonView.php
 * Description: JsonView class
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class JsonView extends ApiView {
	public function render($content) {
		header('Content-Type: application/json');
		//pretty output to match the stored snapshots, which createVersion
		//already writes with JSON_PRETTY_PRINT
		echo json_encode($content, JSON_PRETTY_PRINT);
		return true;
	}
}
