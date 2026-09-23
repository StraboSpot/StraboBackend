<?php
/**
 * File: MysamplesController.php
 * Description: GET /samplesdb/mysamples — list the authenticated user's
 *              samples (own + accepted, non-removed collaborator-on).
 *              Spine columns only; full per-sample detail is on
 *              /samplesdb/sample/{id}.
 *
 *              Query parameters:
 *                omit=field[,micro,experimental]
 *                    Drop samples whose every origin is in the list
 *                    (a sample made on the website is never dropped;
 *                    a Micro sample already linked to a Field spot
 *                    survives omit=field). The Field app's sample
 *                    picker uses omit=field so users only see samples
 *                    a Field sample can link to. Unknown value = 400.
 *                include_subsystem_flags=1
 *                    Add has_field_data / has_micro_data /
 *                    experimental_link_count per row so a picker can
 *                    warn "already linked to a Field spot".
 *
 *              Pagination and the type/purpose/search/sort filters are
 *              still deferred per design §16 item 3. The shape is
 *              `{ "samples": [...], "count": N }` plus an `omit` echo
 *              when the filter was used.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class MysamplesController extends MyController
{
    public function getAction($request)
    {
        $params = is_array($request->parameters) ? $request->parameters : array();

        $omit = StraboSamplesService::normalizeOmit(isset($params['omit']) ? $params['omit'] : '');
        if ($omit === null) {
            header("Bad Request", true, 400);
            return array(
                'Error' => "Unknown omit value. Allowed: "
                    . implode(', ', StraboSamplesService::$OMIT_SUBSYSTEMS) . " (comma separated).",
            );
        }

        $withFlags = isset($params['include_subsystem_flags'])
            && in_array(strtolower((string)$params['include_subsystem_flags']), array('1', 'true', 'yes'), true);

        $samples = $this->svc->listMySamples(array(
            'omit'                    => $omit,
            'include_subsystem_flags' => $withFlags,
        ));
        $out = array(
            'samples' => $samples,
            'count'   => count($samples),
        );
        if ($omit) {
            $out['omit'] = $omit;
        }
        return $out;
    }
}
