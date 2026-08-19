<?php
/**
 * File: SavedController.php
 * Description: Saved-search CRUD on strabosearch.saved_search (§5.4.1,
 *              §6.6). Requires a logged-in identity — anonymous callers
 *              get 401 (§5.5.3: saved searches still require login).
 *
 *                GET    /searchdb/saved          — list own saved searches
 *                POST   /searchdb/saved          — create  { search_name, dsl }
 *                PUT    /searchdb/saved/{pkey}   — update  { search_name?, dsl? }
 *                DELETE /searchdb/saved/{pkey}   — delete
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class SavedController extends MyController
{
    public function getAction($request)
    {
        if ($err = $this->rejectAnonymous()) return $err;
        return $this->svc->listSavedSearches();
    }

    public function postAction($request)
    {
        if ($err = $this->rejectAnonymous()) return $err;
        try {
            return $this->svc->createSavedSearch($request->parameters);
        } catch (SearchDslError $e) {
            header("Bad Request", true, 400);
            return array("Error" => $e->getMessage());
        }
    }

    public function putAction($request)
    {
        if ($err = $this->rejectAnonymous()) return $err;
        $pkey = isset($request->url_elements[2]) ? (int)$request->url_elements[2] : 0;
        try {
            return $this->svc->updateSavedSearch($pkey, $request->parameters);
        } catch (SearchDslError $e) {
            header("Bad Request", true, 400);
            return array("Error" => $e->getMessage());
        }
    }

    public function deleteAction($request)
    {
        if ($err = $this->rejectAnonymous()) return $err;
        $pkey = isset($request->url_elements[2]) ? (int)$request->url_elements[2] : 0;
        return $this->svc->deleteSavedSearch($pkey);
    }
}
