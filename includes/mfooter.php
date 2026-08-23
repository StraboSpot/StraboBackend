<?php
/**
 * File: mfooter.php
 * Description: Page footer template. Since 2026-08 this is a two-line
 *              composition: mfooter_visual.php (the visible footer) +
 *              mfooter_close.php (#page-wrapper closer and the site
 *              script stack). Full-viewport "app frame" pages include
 *              mfooter_close.php directly to skip the visual footer;
 *              every other page keeps including this file, whose output
 *              is byte-identical to the pre-split footer.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include(__DIR__ . "/mfooter_visual.php");
include(__DIR__ . "/mfooter_close.php");
