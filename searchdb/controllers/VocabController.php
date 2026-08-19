<?php
/**
 * File: VocabController.php
 * Description: GET /searchdb/vocab/{facet} — vocabulary values for one
 *              facet's picker widget. Facets:
 *
 *                rock_type     — the materialized F7 hierarchy (path,
 *                                parent_path, depth), colon-delimited
 *                tag_type      — F11 live vocab (raw_value, display_label)
 *                image_type    — I1 unified values
 *                sample_type   — U7 display_sample_type observed values
 *                sample_purpose— U7 display_sample_purpose observed values
 *                owner         — U4 dropdown: owners the searcher can see
 *                                (self + collaborators + public-project
 *                                owners) per §4.3
 *                feature_type / planar_linear / met_facies / trace_type /
 *                mineral / mineral_method / instrument_type /
 *                detector_type / apparatus_type / daq_sensor_type /
 *                measurement_type — observed-value lists
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */


class VocabController extends MyController
{
    public function getAction($request)
    {
        $facet = isset($request->url_elements[2]) ? (string)$request->url_elements[2] : '';
        try {
            return $this->svc->vocab($facet);
        } catch (SearchDslError $e) {
            header("Bad Request", true, 400);
            return array("Error" => $e->getMessage());
        }
    }
}
