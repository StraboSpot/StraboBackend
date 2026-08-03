<?php
/**
 * File: MyController.php
 * Description: Base controller for /searchdb/ and /searchdb/jwt/. Child
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
    /** @var StraboSearchService */
    protected $svc;

    public function setStraboSearchHandler($svc)
    {
        $this->svc = $svc;
    }

    /**
     * Shared gate for endpoints that require a logged-in identity
     * (saved searches). Returns true when the caller is anonymous,
     * after emitting the 401 — caller pattern:
     *   if ($err = $this->rejectAnonymous()) return $err;
     */
    protected function rejectAnonymous()
    {
        if ($this->svc->isAnonymous()) {
            header("Unauthorized", true, 401);
            return array("Error" => "This endpoint requires authentication.");
        }
        return null;
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
