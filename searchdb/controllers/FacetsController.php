<?php
/**
 * File: FacetsController.php
 * Description: GET /searchdb/facets[?subsystems=field,micro,...] — the
 *              empty-search initial-state facet values + counts for the
 *              criteria-builder dropdowns, served from the pre-aggregated
 *              strabosearch.vocab_facet_counts table (§5.4.3: the
 *              materialized copy is ONLY for the no-other-criteria state;
 *              query-time facet counts ride the search response).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class FacetsController extends MyController
{
    public function getAction($request)
    {
        $subsystems = null;
        if (isset($request->parameters['subsystems']) && $request->parameters['subsystems'] !== '') {
            $subsystems = array_values(array_filter(array_map('trim',
                explode(',', (string)$request->parameters['subsystems']))));
        }
        try {
            return $this->svc->initialFacetCounts($subsystems);
        } catch (SearchDslError $e) {
            header("Bad Request", true, 400);
            return array("Error" => $e->getMessage());
        }
    }
}
