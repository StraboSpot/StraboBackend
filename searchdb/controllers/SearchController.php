<?php
/**
 * File: SearchController.php
 * Description: POST /searchdb/search — run a search. The request body is
 *              the §4.4 query DSL JSON:
 *
 *                {
 *                  "subsystems": ["field","micro","exp","samples"],
 *                  "pathway":    "projects" | "images" | "both",
 *                  "criteria":   [ {"id": "U1", "op": ..., "value": ...,
 *                                   "not": false}, ... ],
 *                  "sort":       "relevance" | "modified_desc" |
 *                                "name_asc" | "owner_asc"   (optional),
 *                  "page":       0,
 *                  "page_size":  25
 *                }
 *
 *              Anonymous callers are served (public projects only —
 *              §5.5.3). Response shape per §5.4.1.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class SearchController extends MyController
{
    public function postAction($request)
    {
        try {
            return $this->svc->runSearch($request->parameters);
        } catch (SearchDslError $e) {
            header("Bad Request", true, 400);
            return array("Error" => $e->getMessage());
        }
    }
}
