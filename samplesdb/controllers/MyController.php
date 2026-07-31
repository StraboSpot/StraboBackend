<?php
/**
 * File: MyController.php
 * Description: Base controller for /samplesdb/ and /samplesjwtdb/. Child
 *              controllers extend this and override the relevant
 *              {verb}Action methods. Default handlers return 400 so
 *              unsupported verbs surface cleanly.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class MyController
{
    /** @var StraboSamplesService */
    protected $svc;

    public function setStraboSamplesHandler($svc)
    {
        $this->svc = $svc;
    }

    public function getAction($request)
    {
        header("Bad Request", true, 400);
        return array("Error" => "Bad Request.");
    }

    public function postAction($request)
    {
        header("Bad Request", true, 400);
        return array("Error" => "Bad Request.");
    }

    public function putAction($request)
    {
        header("Bad Request", true, 400);
        return array("Error" => "Bad Request.");
    }

    public function deleteAction($request)
    {
        header("Bad Request", true, 400);
        return array("Error" => "Bad Request.");
    }

    public function patchAction($request)
    {
        header("Bad Request", true, 400);
        return array("Error" => "Bad Request.");
    }

    public function optionsAction($request)
    {
        header("Bad Request", true, 400);
        return array("Error" => "Bad Request.");
    }
}
