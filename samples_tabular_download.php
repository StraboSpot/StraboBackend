<?php
/**
 * File: samples_tabular_download.php
 * Description: Session-gated download endpoint for the StraboSamples
 *              tabular workflow. Streams either the empty import
 *              template or a populated export of the caller's samples,
 *              as XLSX (default) or CSV.
 *
 *              GET params:
 *                what   = template | export   (default: export)
 *                format = xlsx | csv          (default: xlsx)
 *
 *              Owner-only by design (agreed 2026-07-03): the export
 *              contains samples where userpkey = caller, never
 *              collaborator-shared samples.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/samplesdb/services/SampleTabularService.php";

$what   = (isset($_GET['what'])   && $_GET['what']   === 'template') ? 'template' : 'export';
$format = (isset($_GET['format']) && $_GET['format'] === 'csv')      ? 'csv'      : 'xlsx';

$svc = new SampleTabularService($db, $neodb);
$svc->setUserpkey($userpkey);

if ($what === 'template') {
    $rows       = array();
    $customKeys = array();
    $basename   = 'strabosamples_template';
} else {
    $export     = $svc->exportRows();
    $rows       = $export['rows'];
    $customKeys = $export['custom_keys'];
    $basename   = 'strabosamples_export_' . date('Ymd');
}

if ($format === 'csv') {
    $csv = $svc->buildCsv($rows, $customKeys, $what === 'template');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $basename . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

$wb = $svc->buildWorkbook($rows, $customKeys, $what === 'template');
if (!class_exists('PHPExcel_Writer_Excel2007')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/PHPExcel/Writer/Excel2007.php';
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $basename . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new PHPExcel_Writer_Excel2007($wb);
$writer->save('php://output');
exit;
