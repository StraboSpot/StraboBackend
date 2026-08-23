<?php
/**
 * File: api.php
 * Description: Session-authenticated same-origin proxy between the
 *              /search page and the StraboSearch service layer (§5.4,
 *              §6). The website is PHP-session authed while /searchdb/
 *              speaks HTTP Basic / JWT, so browser JS cannot present the
 *              session user's credentials to /searchdb/ directly; this
 *              proxy instantiates StraboSearchService with the session
 *              identity instead (userpkey 0 = anonymous searcher,
 *              §5.5.3 — NOT prepare_connections.php's 99999 default,
 *              which would impersonate a real-shaped pkey).
 *
 *              Actions (all respond application/json):
 *                POST ?action=search        body = §4.4 DSL
 *                POST ?action=search_geo    body = §4.4 DSL + geo block
 *                                           (Globe View D7: one marker
 *                                           per matching project)
 *                GET  ?action=facets[&subsystems=field,micro,...]
 *                GET  ?action=vocab&facet=<facet>
 *                GET  ?action=saved_list
 *                POST ?action=saved_create  body = {search_name, dsl}
 *                POST ?action=saved_update  body = {pkey, search_name?, dsl?}
 *                POST ?action=saved_delete  body = {pkey}
 *                GET  ?action=legacy_list   (old fullsearches rows, §6.6.3)
 *
 *              Saved-search actions require a logged-in session (401
 *              otherwise) — parity with SavedController::rejectAnonymous.
 *
 * @package    StraboSpot Web Site — StraboSearch
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

session_start();

$userpkey = 0;   // anonymous searcher (§5.5.3)
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === 'yes'
    && isset($_SESSION['userpkey']) && (int)$_SESSION['userpkey'] > 0) {
    $userpkey = (int)$_SESSION['userpkey'];
}
// Long searches must not serialize the user's other tabs on the session lock.
session_write_close();

include_once("../includes/config.inc.php");
include("../db.php");
include("../searchdb/services/StraboSearchService.php");

$svc = new StraboSearchService($db, $userpkey);

header('Content-Type: application/json');

function respond($payload, $code = 200)
{
    if ($code !== 200) http_response_code($code);
    echo json_encode($payload);
    exit();
}

function requireLogin($svc)
{
    if ($svc->isAnonymous()) {
        respond(array('Error' => 'Login required.'), 401);
    }
}

function jsonBody()
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        respond(array('Error' => 'Request body must be a JSON object.'), 400);
    }
    return $body;
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

try {
    switch ($action) {

        case 'search':
            respond($svc->runSearch(jsonBody()));

        case 'search_geo':
            respond($svc->runGeoSearch(jsonBody()));

        case 'facets':
            $subsystems = null;
            if (isset($_GET['subsystems']) && $_GET['subsystems'] !== '') {
                $subsystems = array_values(array_filter(array_map('trim',
                    explode(',', (string)$_GET['subsystems']))));
            }
            respond($svc->initialFacetCounts($subsystems));

        case 'vocab':
            $facet = isset($_GET['facet']) ? (string)$_GET['facet'] : '';
            respond($svc->vocab($facet));

        case 'saved_list':
            requireLogin($svc);
            respond($svc->listSavedSearches());

        case 'saved_create':
            requireLogin($svc);
            respond($svc->createSavedSearch(jsonBody()));

        case 'saved_update':
            requireLogin($svc);
            $body = jsonBody();
            $pkey = isset($body['pkey']) ? (int)$body['pkey'] : 0;
            respond($svc->updateSavedSearch($pkey, $body));

        case 'saved_delete':
            requireLogin($svc);
            $body = jsonBody();
            $pkey = isset($body['pkey']) ? (int)$body['pkey'] : 0;
            respond($svc->deleteSavedSearch($pkey));

        case 'legacy_list':
            requireLogin($svc);
            $rows = $db->get_results_prepared(
                "SELECT pkey, search_name, search_json,
                        to_char(date_saved, 'YYYY-MM-DD') AS date_saved
                 FROM fullsearches WHERE user_pkey = $1 ORDER BY pkey DESC",
                array($userpkey));
            $out = array();
            foreach ((array)$rows as $r) {
                $out[] = array(
                    'pkey'        => (int)$r->pkey,
                    'search_name' => $r->search_name,
                    'search_json' => json_decode($r->search_json, true),
                    'date_saved'  => $r->date_saved,
                );
            }
            respond(array('legacy' => $out));

        default:
            respond(array('Error' => 'Unknown action.'), 400);
    }
} catch (SearchDslError $e) {
    respond(array('Error' => $e->getMessage()), 400);
}
