<?php
/**
 * File: globe/index.php
 * Description: /globe front door to the StraboSearch Globe View (M3,
 *              docs/StraboSearch/GlobeView_Design_Proposal.md). The legacy
 *              standalone Cesium page that lived here (CDN Cesium 1.111 over
 *              /search/newsearchdatasets.json) was retired 2026-08-24; the
 *              browse-mode globe inside /strabosearch/ replaces it: an empty
 *              query in globe view shows every visible project (all public
 *              data for anonymous visitors, plus your own when signed in).
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

header('Location: /strabosearch/?view=globe', true, 302);
exit();
