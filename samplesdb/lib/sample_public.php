<?php
/**
 * File: samplesdb/lib/sample_public.php
 * Description: Which StraboSamples samples logged-out visitors may see (the
 *              public read-only Sample Overview, /samples/{owner}/{id}).
 *              Decided with Jason 2026-09-27. A sample is public when:
 *
 *                via_project  any host project holding it is public. Same
 *                             rule StraboSearch applies to sample hits
 *                             (searchdb/extractors/_row_builders.php
 *                             samples*Sql): Field = the PG project flag on
 *                             the indexed spot, Micro = micro_projectmetadata
 *                             by (strabo_id, userpkey), Exp = the project by
 *                             project_uuid.
 *                via_igsn     it has an IGSN StraboSpot registered at SESAR
 *                             (strabosamples.sesar_registrations, active or
 *                             deactivation requested): its name, location,
 *                             description and purpose are already public at
 *                             SESAR / DataCite, and the SESAR record links
 *                             back to this page. The table arrives with the
 *                             IGSN feature; until then this clause is off.
 *
 *              What the public page then shows (also decided 09-27): only
 *              what is already public. Notes and custom fields only when
 *              via_project; project cards only for public hosts; relatives
 *              only when they are public themselves; never collaborators or
 *              the change history.
 *
 * @package    StraboSpot Web Site / StraboSamples
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

if (!function_exists('samples_public_status')) {

    /**
     * @return array (id|owner key) => array('via_project' => bool, 'via_igsn' => bool)
     *               for every requested pair that is public; others are absent.
     */
    function samples_public_many($db, array $pairs)
    {
        if (empty($pairs)) return array();
        $ids = array();
        $owners = array();
        foreach ($pairs as $p) {
            $ids[] = (string)$p[0];
            $owners[] = (int)$p[1];
        }
        $idArr = '{' . implode(',', array_map(function ($v) {
            return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $v) . '"';
        }, $ids)) . '}';
        $ownerArr = '{' . implode(',', $owners) . '}';

        $igsnSql = "FALSE";
        if (samples_public_has_sesar_table($db)) {
            $igsnSql = "EXISTS (SELECT 1 FROM strabosamples.sesar_registrations g
                                 WHERE g.sample_id = k.id AND g.sample_userpkey = k.owner
                                   AND g.active AND g.state IN ('active', 'deactivation_requested'))";
        }
        $rows = $db->get_results_prepared(
            "WITH k AS (SELECT * FROM unnest($1::text[], $2::int[]) AS t(id, owner))
             SELECT k.id, k.owner,
                    (EXISTS (SELECT 1 FROM strabosamples.sample_subsystem_links l
                               JOIN strabosearch.item_hit ih
                                 ON ih.item_type = 'spot' AND ih.project_subsystem = 'field'
                                AND ih.item_id = l.reference_id AND ih.item_userpkey = l.reference_userpkey
                              WHERE l.sample_id = k.id AND l.sample_userpkey = k.owner
                                AND l.subsystem = 'field' AND ih.project_ispublic)
                     OR EXISTS (SELECT 1 FROM strabosamples.sample_subsystem_links l
                                  JOIN strabomicro.micro_projectmetadata pm
                                    ON pm.strabo_id = l.reference_metadata->>'project_strabo_id'
                                   AND pm.userpkey = l.reference_userpkey
                                 WHERE l.sample_id = k.id AND l.sample_userpkey = k.owner
                                   AND l.subsystem = 'micro' AND pm.ispublic)
                     OR EXISTS (SELECT 1 FROM strabosamples.sample_subsystem_links l
                                  JOIN straboexp.project p ON p.uuid::text = l.reference_metadata->>'project_uuid'
                                 WHERE l.sample_id = k.id AND l.sample_userpkey = k.owner
                                   AND l.subsystem = 'experimental' AND p.ispublic)) AS via_project,
                    $igsnSql AS via_igsn
               FROM k",
            array($idArr, $ownerArr)
        );
        $out = array();
        foreach ((is_array($rows) ? $rows : array()) as $r) {
            $vp = ($r->via_project === 't' || $r->via_project === true);
            $vi = ($r->via_igsn === 't' || $r->via_igsn === true);
            if ($vp || $vi) $out[$r->id . '|' . (int)$r->owner] = array('via_project' => $vp, 'via_igsn' => $vi);
        }
        return $out;
    }

    /** @return array|null array('via_project', 'via_igsn') when public, else null */
    function samples_public_status($db, $sampleId, $ownerPkey)
    {
        $m = samples_public_many($db, array(array((string)$sampleId, (int)$ownerPkey)));
        $k = (string)$sampleId . '|' . (int)$ownerPkey;
        return isset($m[$k]) ? $m[$k] : null;
    }

    /** Is a Field spot's project public (the flag StraboSearch indexes)? */
    function samples_public_field_spot($db, $spotId, $spotUserpkey)
    {
        $v = $db->get_var_prepared(
            "SELECT bool_or(project_ispublic) FROM strabosearch.item_hit
              WHERE item_type = 'spot' AND project_subsystem = 'field' AND item_id = $1 AND item_userpkey = $2",
            array((string)$spotId, (int)$spotUserpkey)
        );
        return $v === 't' || $v === true;
    }

    function samples_public_has_sesar_table($db)
    {
        static $has = null;
        if ($has === null) {
            $has = $db->get_var("SELECT to_regclass('strabosamples.sesar_registrations') IS NOT NULL") === 't';
        }
        return $has;
    }
}
