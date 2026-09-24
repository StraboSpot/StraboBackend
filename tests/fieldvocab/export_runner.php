<?php
/**
 * File: tests/fieldvocab/export_runner.php
 * Description: Runs ONE straboOutputClass export for the golden fixture in its
 *              own process (some exports stream to stdout and exit(); some use
 *              captureDir), for smoke_test_export_labels.php.
 *
 *              Usage (the fixture must already be uploaded):
 *                php export_runner.php <method> <dsids> <labels|raw> <outdir> > <outdir>/stdout.bin
 *
 *              "raw" swaps in an empty choice map first, which makes every
 *              display copy an identical copy: the export as it was before
 *              translation, from the same code, for an in-place comparison.
 */

$SID_PREFIX = 'fvexp';
require_once __DIR__ . '/fixture_lib.php';

list(, $method, $dsids, $mode, $outdir) = $argv;
if ($mode === 'raw') FieldVocab::setMap(array('schema' => 1, 'forms' => array()));
if (!is_dir($outdir)) mkdir($outdir, 0775, true);

$o = new straboOutputClass(fresh_strabo(), array('dsids' => $dsids, 'userpkey' => $OWNER));
$o->captureDir = $outdir;
if ($method === 'geologicUnitsOut') $o->geologicUnitsOut(GF_PROJECT);
else $o->$method();
