<?php
/**
 * File: samplesdb/lib/sample_moved.php
 * Description: Where a StraboSamples sample lives after "Transfer to Other
 *              Account" moved it (IGSN Phase 9 review Q6). A transfer keeps
 *              the sample id and changes the owner, so /samples/{old}/{id}
 *              (bookmarks, the link back on SESAR / DataCite IGSN records)
 *              would say "not found". ProjectTransfer writes an
 *              'ownership_transfer' changelog entry naming from_userpkey and
 *              to_userpkey on every moved sample; this follows those hops.
 *
 * @package    StraboSpot Web Site / StraboSamples
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 */

if (!function_exists('samples_moved_to')) {

    /**
     * @return int|null the current owner of ($sampleId) when it was moved
     *                  away from $ownerPkey and still exists, else null
     */
    function samples_moved_to($db, $sampleId, $ownerPkey)
    {
        $cur = (int)$ownerPkey;
        $seen = array($cur => true);
        for ($hop = 0; $hop < 10; $hop++) {
            $to = $db->get_var_prepared(
                "SELECT changes->>'to_userpkey' FROM strabosamples.sample_changelog
                  WHERE sample_id = $1 AND change_type = 'ownership_transfer' AND changes->>'from_userpkey' = $2
                  ORDER BY changed_at DESC, pkey DESC LIMIT 1",
                array((string)$sampleId, (string)$cur)
            );
            if ($to === null || $to === '' || !ctype_digit((string)$to)) return null;
            $cur = (int)$to;
            if (isset($seen[$cur])) {
                // Moved back to an earlier owner (A -> B -> A): valid only if it is there now.
                break;
            }
            $seen[$cur] = true;
            $here = $db->get_var_prepared("SELECT 1 FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
                array((string)$sampleId, $cur));
            if ($here !== null && $here !== '') return $cur;
        }
        $here = $db->get_var_prepared("SELECT 1 FROM strabosamples.samples WHERE id = $1 AND userpkey = $2",
            array((string)$sampleId, $cur));
        return ($cur !== (int)$ownerPkey && $here !== null && $here !== '') ? $cur : null;
    }
}
