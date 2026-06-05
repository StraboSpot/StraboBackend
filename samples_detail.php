<?php
/**
 * File: samples_detail.php
 * Description: Sample Overview — public detail page for a single sample.
 *              Design §12.2 + mockups (sample_overview_mockup.png,
 *              …parent_child_1.png, …parent_child_2.png). Routed via
 *              /samples/{owner_pkey}/{id} from the .htaccess rewrite.
 *
 *              Auth: §12.2 specifies the Share URL is public-read for
 *              any logged-in user. logincheck.php gates the page; the
 *              spine/links/family reads bypass StraboSamplesService's
 *              canRead because the URL itself acts as the auth gate.
 *              Editing (Collaborate / Edit buttons) is conditionally
 *              rendered based on ownership / accepted-collab grants.
 *
 *              Layout (Phase 4 v1): card-per-link for §16 #9. The
 *              render layer takes a flat list of subsystem_links rows
 *              and emits one card per row. Swapping to a card-per-
 *              subsystem-with-picker later only touches the renderer;
 *              the data path is layout-agnostic.
 *
 *              Family-tree widget (§12.2.1): lightweight SVG radial
 *              layout — focus center, parent above, children arc'd
 *              below, click for a detail card with "View Sample" CTA
 *              that navigates to the neighbor's overview. The full
 *              Neo4j-Graph-Browser feel (force layout, drag, smooth
 *              animations) is a follow-on iteration; this v1 covers
 *              the data + interaction shape so the widget is real
 *              from launch and grows polish later.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("logincheck.php");
include("prepare_connections.php");
require_once __DIR__ . "/samplesdb/services/StraboSamplesService.php";
require_once __DIR__ . "/samplesdb/lib/vocab.php";

$ownerPkey = isset($_GET['owner']) ? (int)$_GET['owner'] : 0;
$sampleId  = isset($_GET['id'])    ? trim((string)$_GET['id']) : '';

$svc = new StraboSamplesService($db, $neodb);
$svc->setUserpkey($userpkey);

// ---- Direct spine read (public-read per §12.2, bypasses canRead). ----
$spineRow = null;
if ($ownerPkey > 0 && $sampleId !== '') {
    $spineRow = $db->get_row_prepared(
        "SELECT id, userpkey, name, igsn, description, notes,
                latitude, longitude,
                display_sample_type, display_sample_purpose,
                parent_sample_id, parent_userpkey,
                field_data, micro_data, experimental_data,
                created_at, created_by, modified_at, modified_by
           FROM strabosamples.samples
          WHERE id=$1 AND userpkey=$2",
        array($sampleId, $ownerPkey)
    );
}

$notFound = !$spineRow;

if (!$notFound) {
    $sample = array(
        'id'                     => $spineRow->id,
        'userpkey'               => (int)$spineRow->userpkey,
        'name'                   => $spineRow->name,
        'igsn'                   => $spineRow->igsn,
        'description'            => $spineRow->description,
        'notes'                  => $spineRow->notes,
        'latitude'               => $spineRow->latitude !== null ? (float)$spineRow->latitude  : null,
        'longitude'              => $spineRow->longitude !== null ? (float)$spineRow->longitude : null,
        'display_sample_type'    => $spineRow->display_sample_type,
        'display_sample_purpose' => $spineRow->display_sample_purpose,
        'parent_sample_id'       => $spineRow->parent_sample_id,
        'parent_userpkey'        => $spineRow->parent_userpkey !== null ? (int)$spineRow->parent_userpkey : null,
        'field_data'             => $spineRow->field_data        ? json_decode($spineRow->field_data,        true) : null,
        'micro_data'             => $spineRow->micro_data        ? json_decode($spineRow->micro_data,        true) : null,
        'experimental_data'      => $spineRow->experimental_data ? json_decode($spineRow->experimental_data, true) : null,
        'created_at'             => $spineRow->created_at,
        'created_by'             => $spineRow->created_by !== null ? (int)$spineRow->created_by : null,
        'modified_at'            => $spineRow->modified_at,
        'modified_by'            => $spineRow->modified_by !== null ? (int)$spineRow->modified_by : null,
    );

    // Subsystem links — drives card-per-link rendering.
    $linkRows = $db->get_results_prepared(
        "SELECT subsystem, reference_id, reference_userpkey, reference_metadata,
                created_at, modified_at
           FROM strabosamples.sample_subsystem_links
          WHERE sample_id=$1 AND sample_userpkey=$2
          ORDER BY subsystem, reference_id",
        array($sampleId, $ownerPkey)
    );
    $links = array();
    if (is_array($linkRows)) {
        foreach ($linkRows as $r) {
            $links[] = array(
                'subsystem'          => $r->subsystem,
                'reference_id'       => $r->reference_id,
                'reference_userpkey' => (int)$r->reference_userpkey,
                'reference_metadata' => $r->reference_metadata ? json_decode($r->reference_metadata, true) : null,
                'created_at'         => $r->created_at,
                'modified_at'        => $r->modified_at,
            );
        }
    }

    // ---- Resolve View <Subsystem> URL server-side. ----
    // Cross-system auth model: a sample is mirrored from a host project
    // whose visibility is owned by THAT subsystem, not by StraboSamples.
    // If the page viewer isn't the host project owner AND the project
    // isn't public, the existing subsystem pages will show "Project Not
    // Found" — better UX to disable the button up front and tell the
    // user why than to dangle a link that goes nowhere.
    //
    // Field detail page does its own session/owner check; let it through.
    // Micro + Experimental gate explicitly here so the button hides when
    // the viewer genuinely can't reach the page.
    $viewerPkey = (int)$userpkey;

    // Batch-lookup micro_projectmetadata for every micro link's project.
    // IMPORTANT: micro_projectmetadata.strabo_id is NOT unique — when
    // multiple users upload the same project file, each gets a row with
    // the same strabo_id but a different (id, userpkey). The link's
    // reference_userpkey identifies which user's copy of the project
    // this link points at, so we key the index on (strabo_id, userpkey)
    // and resolve using that composite. Keying on strabo_id alone would
    // arbitrarily collapse copies and produce wrong auth verdicts.
    $microProjectIndex = array();
    $microStraboIds = array();
    foreach ($links as $l) {
        if ($l['subsystem'] !== 'micro') continue;
        $sid = isset($l['reference_metadata']['project_strabo_id']) ? $l['reference_metadata']['project_strabo_id'] : null;
        if ($sid !== null && $sid !== '') $microStraboIds[$sid] = true;
    }
    if ($microStraboIds) {
        $params = array_keys($microStraboIds);
        $placeholders = array();
        foreach ($params as $i => $_) $placeholders[] = '$' . ($i + 1);
        $sql = "SELECT id, strabo_id, ispublic, userpkey
                  FROM micro_projectmetadata
                 WHERE strabo_id IN (" . implode(',', $placeholders) . ")";
        $rows = $db->get_results_prepared($sql, $params);
        if (is_array($rows)) {
            foreach ($rows as $pr) {
                $microProjectIndex[$pr->strabo_id . '|' . (int)$pr->userpkey] = $pr;
            }
        }
    }

    // Batch-lookup straboexp.experiment + project.ispublic for every exp link.
    $expIndex = array();
    $expUuids = array();
    foreach ($links as $l) {
        if ($l['subsystem'] !== 'experimental') continue;
        $u = isset($l['reference_metadata']['experiment_uuid']) ? $l['reference_metadata']['experiment_uuid'] : null;
        if ($u !== null && $u !== '') $expUuids[$u] = true;
    }
    if ($expUuids) {
        $params = array_keys($expUuids);
        $placeholders = array();
        foreach ($params as $i => $_) $placeholders[] = '$' . ($i + 1);
        $sql = "SELECT e.uuid AS experiment_uuid, e.userpkey AS experiment_userpkey,
                       COALESCE(p.ispublic, FALSE) AS project_ispublic
                  FROM straboexp.experiment e
             LEFT JOIN straboexp.project p ON p.pkey = e.project_pkey
                 WHERE e.uuid IN (" . implode(',', $placeholders) . ")";
        $rows = $db->get_results_prepared($sql, $params);
        if (is_array($rows)) {
            foreach ($rows as $er) {
                $expIndex[$er->experiment_uuid] = $er;
            }
        }
    }

    foreach ($links as &$l) {
        $l['view_href']        = null;
        $l['view_unavailable'] = null;
        $meta = $l['reference_metadata'] ?: array();
        if ($l['subsystem'] === 'field') {
            if (!empty($meta['dataset_id'])) {
                $l['view_href'] = '/StraboFieldDatasetDetail/?dataset_id=' . rawurlencode((string)$meta['dataset_id']);
            }
        } elseif ($l['subsystem'] === 'micro') {
            $sid = isset($meta['project_strabo_id']) ? $meta['project_strabo_id'] : null;
            $key = ($sid !== null) ? ($sid . '|' . (int)$l['reference_userpkey']) : null;
            if ($key !== null && isset($microProjectIndex[$key])) {
                $pr = $microProjectIndex[$key];
                $isPublic = ($pr->ispublic === 't' || $pr->ispublic === true || $pr->ispublic === 'true');
                $isOwner  = ((int)$pr->userpkey === $viewerPkey);
                if ($isPublic || $isOwner) {
                    $l['view_href'] = '/microproject?id=' . (int)$pr->id;
                } else {
                    $l['view_unavailable'] = 'Host StraboMicro project is private and owned by another user.';
                }
            } else {
                $l['view_unavailable'] = 'Host StraboMicro project not found.';
            }
        } elseif ($l['subsystem'] === 'experimental') {
            $u = isset($meta['experiment_uuid']) ? $meta['experiment_uuid'] : null;
            if ($u !== null && isset($expIndex[$u])) {
                $er = $expIndex[$u];
                $isPublic = ($er->project_ispublic === 't' || $er->project_ispublic === true || $er->project_ispublic === 'true');
                $isOwner  = ((int)$er->experiment_userpkey === $viewerPkey);
                if ($isPublic || $isOwner) {
                    $l['view_href'] = '/experimental/overview_experiment.php?u=' . rawurlencode($u);
                } else {
                    $l['view_unavailable'] = 'Host StraboExperimental project is private and owned by another user.';
                }
            } else {
                $l['view_unavailable'] = 'Host StraboExperimental experiment not found.';
            }
        }
    }
    unset($l);

    // Collaborators (accepted only — pending invites stay private to the owner).
    $collabRows = $db->get_results_prepared(
        "SELECT c.collaborator_pkey, c.permission_level, c.accepted, c.accepted_at,
                u.firstname, u.lastname, u.email
           FROM strabosamples.sample_collaborators c
           JOIN users u ON u.pkey = c.collaborator_pkey
          WHERE c.sample_id=$1 AND c.sample_userpkey=$2
            AND c.accepted = TRUE AND c.removed_at IS NULL
          ORDER BY c.accepted_at",
        array($sampleId, $ownerPkey)
    );
    $collaborators = array();
    if (is_array($collabRows)) {
        foreach ($collabRows as $c) {
            $name = trim(($c->firstname ?: '') . ' ' . ($c->lastname ?: ''));
            $collaborators[] = array(
                'pkey'             => (int)$c->collaborator_pkey,
                'name'             => $name !== '' ? $name : $c->email,
                'email'            => $c->email,
                'permission_level' => $c->permission_level,
                'initials'         => strtoupper(substr($c->firstname ?: $c->email, 0, 1)
                                              . substr($c->lastname  ?: '', 0, 1)),
            );
        }
    }

    // Owner display name for the metadata block.
    $ownerRow = $db->get_row_prepared(
        "SELECT firstname, lastname, email FROM users WHERE pkey = $1",
        array($ownerPkey)
    );
    $ownerName = '';
    if ($ownerRow) {
        $ownerName = trim(($ownerRow->firstname ?: '') . ' ' . ($ownerRow->lastname ?: ''));
        if ($ownerName === '') $ownerName = $ownerRow->email;
    }

    // Family snapshot — direct queries so public-read works.
    $family = array('focus' => null, 'parent' => null, 'children' => array());
    $family['focus'] = array(
        'id'                     => $sample['id'],
        'userpkey'               => $sample['userpkey'],
        'name'                   => $sample['name'],
        'display_sample_type'    => $sample['display_sample_type'],
        'display_sample_purpose' => $sample['display_sample_purpose'],
    );
    if ($sample['parent_sample_id'] !== null && $sample['parent_userpkey'] !== null) {
        $pRow = $db->get_row_prepared(
            "SELECT id, userpkey, name, display_sample_type, display_sample_purpose
               FROM strabosamples.samples WHERE id=$1 AND userpkey=$2",
            array($sample['parent_sample_id'], $sample['parent_userpkey'])
        );
        if ($pRow) {
            $family['parent'] = array(
                'id'                     => $pRow->id,
                'userpkey'               => (int)$pRow->userpkey,
                'name'                   => $pRow->name,
                'display_sample_type'    => $pRow->display_sample_type,
                'display_sample_purpose' => $pRow->display_sample_purpose,
            );
        } else {
            $family['parent'] = array(
                'orphaned'         => true,
                'parent_sample_id' => $sample['parent_sample_id'],
                'parent_userpkey'  => $sample['parent_userpkey'],
            );
        }
    }
    $childRows = $db->get_results_prepared(
        "SELECT id, userpkey, name, display_sample_type, display_sample_purpose
           FROM strabosamples.samples
          WHERE parent_sample_id=$1 AND parent_userpkey=$2
          ORDER BY created_at",
        array($sampleId, $ownerPkey)
    );
    if (is_array($childRows)) {
        foreach ($childRows as $c) {
            $family['children'][] = array(
                'id'                     => $c->id,
                'userpkey'               => (int)$c->userpkey,
                'name'                   => $c->name,
                'display_sample_type'    => $c->display_sample_type,
                'display_sample_purpose' => $c->display_sample_purpose,
            );
        }
    }

    // Permission flags: drive Edit/Collaborate button visibility.
    $isOwner = ((int)$userpkey === $ownerPkey);
    $canEdit = $isOwner;
    if (!$canEdit) {
        $row = $db->get_row_prepared(
            "SELECT 1 AS ok FROM strabosamples.sample_collaborators
              WHERE sample_id=$1 AND sample_userpkey=$2
                AND collaborator_pkey=$3 AND accepted=TRUE
                AND removed_at IS NULL AND permission_level='edit'
              LIMIT 1",
            array($sampleId, $ownerPkey, (int)$userpkey)
        );
        $canEdit = (bool)$row;
    }

    $payload = array(
        'sample'        => $sample,
        'owner'         => array('pkey' => $ownerPkey, 'name' => $ownerName),
        'links'         => $links,
        'collaborators' => $collaborators,
        'family'        => $family,
        'permissions'   => array('isOwner' => $isOwner, 'canEdit' => $canEdit),
    );
}

// Build the canonical Share URL from the request host so it's correct
// across staging / prod without hard-coding.
$shareScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$shareHost   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'strabospot.org';
$shareUrl    = $shareScheme . '://' . $shareHost . '/samples/' . $ownerPkey . '/' . rawurlencode((string)$sampleId);

include("includes/mheader.php");
?>

<style>
.sd-wrap { max-width: 1100px; margin: 0 auto; padding: 0 1em; color: #ffffff; }
.sd-header {
    text-align: center;
    margin-bottom: 1em;
}
.sd-back-link {
    display: block;
    text-align: left;
    margin: 0 0 0.4em 0;
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.9em;
    text-decoration: none;
}
.sd-back-link:hover {
    color: #e44c65;
    text-decoration: none;
}
.sd-header h1 {
    color: #ffffff;
    margin-bottom: 0.5em;
    border-bottom: 3px solid #e44c65;
    display: inline-block;
    padding-bottom: 0.3em;
}
.sd-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75em;
    justify-content: center;
    align-items: center;
    margin-bottom: 1.25em;
}
.sd-action-btn {
    background: #e44c65;
    color: #ffffff;
    border: none;
    border-radius: 4px;
    padding: 0.55em 1.05em;
    cursor: pointer;
    font-size: 0.95em;
    text-decoration: none;
}
.sd-action-btn:hover { background: #f06880; color: #ffffff; }
.sd-action-btn.outline {
    background: transparent;
    color: #e44c65;
    border: 1px solid #e44c65;
}
.sd-action-btn.outline:hover { background: rgba(228, 76, 101, 0.12); }
.sd-share-line {
    color: rgba(255, 255, 255, 0.75);
    font-size: 0.92em;
    word-break: break-all;
}
.sd-share-line strong { color: #ffffff; margin-right: 0.4em; }

.sd-avatars { display: inline-flex; gap: 0.4em; margin-left: 0.4em; }
.sd-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: #4fa8c1;
    color: #ffffff;
    font-size: 0.78em;
    font-weight: 700;
    border: 2px solid rgba(255,255,255,0.25);
    cursor: default;
}
.sd-avatar[title] { cursor: help; }

.sd-section {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 6px;
    padding: 1.25em 1.5em;
    margin-bottom: 1.25em;
}
.sd-section h3 {
    color: #ffffff;
    font-size: 1.1em;
    margin-bottom: 0.6em;
    border-bottom: 1px solid rgba(255,255,255,0.12);
    padding-bottom: 0.4em;
}
.sd-metadata-row {
    display: grid;
    grid-template-columns: minmax(320px, 1fr) 280px;
    gap: 1.5em;
    align-items: start;
}
.sd-metadata-fields { color: rgba(255, 255, 255, 0.88); font-size: 0.95em; line-height: 1.7; }
.sd-metadata-fields .sd-field-label {
    color: rgba(255, 255, 255, 0.6);
    margin-right: 0.5em;
    font-weight: 600;
}
.sd-metadata-actions { margin-top: 0.9em; display: flex; gap: 0.5em; flex-wrap: wrap; }

.sd-family-widget {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 4px;
    padding: 0.5em;
    height: 240px;
    position: relative;
}
.sd-family-widget svg { width: 100%; height: 100%; display: block; }
.sd-family-node { cursor: pointer; transition: transform 0.15s ease; }
.sd-family-node:hover { transform: scale(1.05); transform-origin: center; }
.sd-family-node.focus circle { fill: #e44c65; stroke: rgba(255,255,255,0.5); }
.sd-family-node circle { fill: rgba(255,255,255,0.85); stroke: rgba(255,255,255,0.35); stroke-width: 2; }
.sd-family-node text { font-size: 11px; fill: #ffffff; text-anchor: middle; pointer-events: none; }
.sd-family-edge { stroke: rgba(255,255,255,0.45); stroke-width: 1.5; fill: none; }
.sd-family-detail {
    position: absolute;
    top: 12px;
    left: 12px;
    right: 12px;
    background: rgba(30, 30, 45, 0.96);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 4px;
    padding: 0.7em 0.85em;
    font-size: 0.85em;
    color: #ffffff;
    display: none;
}
.sd-family-detail.visible { display: block; }
.sd-family-detail .sd-family-close {
    float: right;
    cursor: pointer;
    color: rgba(255,255,255,0.6);
    margin-left: 0.6em;
}
.sd-family-detail .sd-action-btn { margin-top: 0.5em; display: inline-block; }

.sd-type-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4em;
    margin-bottom: 1.25em;
    color: rgba(255,255,255,0.7);
    font-size: 0.95em;
    align-items: center;
}
.sd-tab {
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.2);
    color: rgba(255,255,255,0.85);
    border-radius: 999px;
    padding: 0.35em 0.95em;
    cursor: pointer;
    font-size: 0.9em;
}
.sd-tab:hover:not(.active) { background: rgba(255,255,255,0.12); }
.sd-tab.active { background: #e44c65; border-color: #e44c65; color: #ffffff; }

.sd-link-card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 6px;
    padding: 1em 1.2em;
    margin-bottom: 0.9em;
    color: #ffffff;
    position: relative;
}
.sd-link-card-head {
    display: flex;
    align-items: center;
    gap: 0.6em;
    color: rgba(255,255,255,0.8);
    font-size: 0.92em;
    margin-bottom: 0.6em;
}
.sd-link-card-head .sd-link-icon {
    width: 26px;
    height: 26px;
    opacity: 0.9;
    object-fit: contain;
    vertical-align: middle;
}
.sd-link-card-head .sd-view-btn { margin-left: auto; }
.sd-link-fields {
    color: rgba(255,255,255,0.88);
    font-size: 0.92em;
    line-height: 1.55;
}
.sd-link-fields .sd-field-label {
    color: rgba(255,255,255,0.55);
    margin-right: 0.4em;
    font-weight: 600;
}
.sd-view-btn {
    background: #e44c65;
    color: #ffffff;
    border: none;
    border-radius: 4px;
    padding: 0.4em 0.85em;
    font-size: 0.85em;
    cursor: pointer;
    text-decoration: none;
}
.sd-view-btn:hover { background: #f06880; color: #ffffff; }
.sd-view-btn-disabled {
    background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.45);
    cursor: not-allowed;
    text-decoration: none;
}
.sd-view-btn-disabled:hover {
    background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.45);
}

.sd-empty-cards {
    text-align: center;
    margin: 1.5em 0 3em 0;
    color: rgba(255,255,255,0.55);
}

.sd-notfound {
    text-align: center;
    margin: 4em auto;
    color: rgba(255,255,255,0.75);
}

@media (max-width: 800px) {
    .sd-metadata-row { grid-template-columns: 1fr; }
    .sd-family-widget { height: 280px; }
}

/* ---- Collaborator-management modal ---- */
/* Defensive overrides: massets/css/main.css has site-wide
   `select { width: 100%; height: 3em; padding: 0 1em; padding-right: 3em;
            background-image: <arrow> }` and `input/textarea { display: block;
   width: 100%; padding: 0 1em; height: 3em }`. Without these resets the
   inline cm-row-level select would expand to fill the row and the inputs
   would all be ~3em tall full-width form fields. The .cm-modal scope
   keeps the override contained. */
.cm-modal input[type="text"],
.cm-modal input[type="number"],
.cm-modal input[type="email"],
.cm-modal select,
.cm-modal textarea {
    height: auto;
    line-height: 1.4;
    background-image: none;
}

.cm-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    z-index: 9999;
    padding: 4em 1em 2em 1em;
    overflow-y: auto;
}
/* Same trap as ms-modal-overlay: class display:flex defeats the UA
   stylesheet's [hidden] rule, so we re-assert it explicitly. */
.cm-modal-overlay[hidden] { display: none; }
.cm-modal {
    background: #1f1f2e;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    width: 100%;
    max-width: 640px;
    color: #fff;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6);
}
.cm-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1em 1.25em;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}
.cm-modal-header h3 { margin: 0; color: #fff; font-size: 1.15em; }
.cm-modal-close {
    background: none; border: none; color: rgba(255, 255, 255, 0.7);
    font-size: 1.6em; line-height: 1; cursor: pointer;
    padding: 0 0.3em;
}
.cm-modal-close:hover { color: #fff; }
.cm-modal-body { padding: 1em 1.25em 1.25em 1.25em; }
.cm-section { margin-bottom: 1.5em; }
.cm-section:last-child { margin-bottom: 0; }
.cm-section h4 {
    margin: 0 0 0.6em 0;
    color: rgba(255, 255, 255, 0.85);
    font-size: 1em;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    padding-bottom: 0.4em;
}
.cm-row {
    display: flex;
    align-items: center;
    gap: 0.6em;
    padding: 0.55em 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.cm-row:last-child { border-bottom: none; }
.cm-avatar {
    width: 32px; height: 32px; flex: 0 0 32px;
    background: rgba(228, 76, 101, 0.6);
    color: #fff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.82em; font-weight: 700;
}
.cm-row-meta { flex: 1; min-width: 0; }
.cm-row-name {
    color: #fff;
    font-weight: 600;
    font-size: 0.95em;
}
.cm-row-email {
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.85em;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cm-status-badge {
    display: inline-block;
    font-size: 0.7em;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    margin-left: 0.4em;
    vertical-align: middle;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.cm-status-badge.accepted { background: rgba(76, 200, 110, 0.25); color: #cfe9d4; }
.cm-status-badge.pending  { background: rgba(255, 200, 80, 0.20); color: #f5dc99; }
.cm-row-level {
    /* The site-wide select rule sets width:100% which would expand this
       inline control to fill the entire row — explicit override required. */
    width: auto;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #fff;
    border-radius: 3px;
    padding: 4px 22px 4px 8px;
    font-size: 0.85em;
    flex: 0 0 auto;
    /* Small custom arrow since appearance:none hides the native one. */
    background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40' viewBox='0 0 40 40'%3E%3Cpath d='M9.4,12.3l10.4,10.4l10.4-10.4c0.2-0.2,0.5-0.4,0.9-0.4c0.3,0,0.6,0.1,0.9,0.4l3.3,3.3c0.2,0.2,0.4,0.5,0.4,0.9 c0,0.4-0.1,0.6-0.4,0.9L20.7,31.9c-0.2,0.2-0.5,0.4-0.9,0.4c-0.3,0-0.6-0.1-0.9-0.4L4.3,17.3c-0.2-0.2-0.4-0.5-0.4-0.9 c0-0.4,0.1-0.6,0.4-0.9l3.3-3.3c0.2-0.2,0.5-0.4,0.9-0.4S9.1,12.1,9.4,12.3z' fill='rgba(255,255,255,0.55)' /%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: calc(100% - 5px) center;
    background-size: 0.7rem;
}
.cm-row-level option { background: #2a2a3a; }
.cm-row-remove {
    background: none; border: none; color: rgba(255, 255, 255, 0.5);
    font-size: 1.3em; line-height: 1; cursor: pointer;
    padding: 0 0.3em;
}
.cm-row-remove:hover { color: #e44c65; }
.cm-empty,
.cm-loading {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.9em;
    padding: 0.6em 0;
    font-style: italic;
}
.cm-invite-form label {
    display: block;
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.9em;
    margin: 0.5em 0 0.3em 0;
}
.cm-invite-form textarea,
.cm-invite-form select {
    width: 100%;
    box-sizing: border-box;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #fff;
    border-radius: 4px;
    padding: 0.55em 0.7em;
    font-size: 0.95em;
    font-family: inherit;
}
.cm-invite-form textarea { resize: vertical; min-height: 4em; }
.cm-invite-form select option { background: #2a2a3a; }
.cm-btn-submit {
    margin-top: 0.7em;
    background: #e44c65;
    color: #fff;
    border: none;
    border-radius: 4px;
    padding: 0.55em 1em;
    font-size: 0.95em;
    cursor: pointer;
}
.cm-btn-submit:hover:not(:disabled) { background: #f06880; }
.cm-btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
.cm-invite-results {
    margin-top: 0.8em;
    border-radius: 4px;
    overflow: hidden;
}
.cm-invite-result {
    padding: 0.4em 0.7em;
    font-size: 0.9em;
    border-left: 3px solid transparent;
    margin-bottom: 1px;
}
.cm-invite-result.invited        { background: rgba(76, 200, 110, 0.15); border-color: #4cc86e; }
.cm-invite-result.re_enabled     { background: rgba(76, 200, 110, 0.15); border-color: #4cc86e; }
.cm-invite-result.already_active { background: rgba(180, 180, 180, 0.10); border-color: #888; }
.cm-invite-result.unknown        { background: rgba(228, 76, 101, 0.18); border-color: #e44c65; }
.cm-invite-result.is_owner       { background: rgba(228, 76, 101, 0.18); border-color: #e44c65; }
.cm-modal-error {
    background: rgba(228, 76, 101, 0.18);
    border-left: 3px solid #e44c65;
    padding: 0.55em 0.8em;
    border-radius: 3px;
    color: #fff;
    font-size: 0.92em;
    margin-top: 0.6em;
}

/* ===== Changelog viewer modal ===== */
.cl-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    z-index: 9999;
    padding: 4em 1em 2em 1em;
    overflow-y: auto;
}
.cl-modal-overlay[hidden] { display: none; }
.cl-modal {
    background: #1f1f2e;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    width: 100%;
    max-width: 760px;
    color: #fff;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6);
}
.cl-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1em 1.25em;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}
.cl-modal-header h3 { margin: 0; color: #fff; font-size: 1.15em; }
.cl-modal-close {
    background: none; border: none; color: rgba(255, 255, 255, 0.7);
    font-size: 1.6em; line-height: 1; cursor: pointer;
    padding: 0 0.3em;
}
.cl-modal-close:hover { color: #fff; }
.cl-modal-body { padding: 0.5em 1.25em 1.25em 1.25em; }
.cl-list { padding: 0.4em 0; }
.cl-row {
    display: flex;
    gap: 0.75em;
    padding: 0.85em 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.cl-row:last-child { border-bottom: none; }
.cl-avatar {
    width: 32px; height: 32px; flex: 0 0 32px;
    background: rgba(120, 140, 200, 0.45);
    color: #fff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78em; font-weight: 700;
}
.cl-row-meta { flex: 1; min-width: 0; }
.cl-row-head {
    display: flex; flex-wrap: wrap; align-items: center; gap: 0.5em;
    margin-bottom: 0.35em;
}
.cl-actor {
    color: #fff;
    font-weight: 600;
    font-size: 0.95em;
}
.cl-time {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.78em;
    margin-left: auto;
    white-space: nowrap;
}
.cl-source {
    display: inline-block;
    font-size: 0.75em;
    font-weight: 600;
    padding: 1px 7px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.75);
}
.cl-type {
    display: inline-block;
    font-size: 0.7em;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.cl-type-create               { background: rgba(76, 200, 110, 0.25); color: #cfe9d4; }
.cl-type-update               { background: rgba(80, 150, 230, 0.25); color: #c9def0; }
.cl-type-parent_set,
.cl-type-parent_clear         { background: rgba(180, 130, 220, 0.25); color: #ddc9f0; }
.cl-type-composition_change,
.cl-type-parameters_change,
.cl-type-documents_change     { background: rgba(255, 170, 70, 0.22); color: #f6dcb0; }
.cl-type-writeback_translation{ background: rgba(228, 100, 170, 0.28); color: #f3c9e0; }
.cl-row-body {
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.9em;
    line-height: 1.45;
}
.cl-summary {
    margin-bottom: 0.25em;
}
.cl-diff-list {
    margin: 0.25em 0 0 0;
    padding: 0;
    list-style: none;
}
.cl-diff-row {
    display: flex; flex-wrap: wrap; gap: 0.35em;
    padding: 0.2em 0;
    font-size: 0.88em;
    line-height: 1.4;
}
.cl-diff-field {
    color: rgba(255, 255, 255, 0.65);
    font-weight: 600;
    min-width: 7em;
}
.cl-diff-old {
    color: #f0a5a5;
    text-decoration: line-through;
    text-decoration-color: rgba(240, 165, 165, 0.5);
    word-break: break-word;
}
.cl-diff-new {
    color: #b8e0c0;
    word-break: break-word;
}
.cl-diff-arrow {
    color: rgba(255, 255, 255, 0.4);
    flex: 0 0 auto;
}
.cl-diff-noop {
    color: rgba(255, 255, 255, 0.55);
    font-style: italic;
}
.cl-wbnote {
    padding: 0.3em 0;
    font-size: 0.88em;
}
.cl-wbnote-translated {
    color: rgba(200, 230, 210, 0.95);
}
.cl-wbnote-skipped {
    color: rgba(240, 200, 130, 0.95);
}
.cl-wbnote-reason {
    color: rgba(255, 255, 255, 0.5);
    font-style: italic;
    margin-left: 0.4em;
}
.cl-empty,
.cl-loading {
    color: rgba(255, 255, 255, 0.55);
    font-size: 0.9em;
    padding: 1.2em 0.6em;
    font-style: italic;
    text-align: center;
}
.cl-pagination {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: 1em;
    padding-top: 0.8em;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}
.cl-counter {
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.85em;
}
.cl-btn-more {
    background: rgba(255, 255, 255, 0.10);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    padding: 0.45em 1em;
    font-size: 0.9em;
    cursor: pointer;
}
.cl-btn-more:hover:not(:disabled) { background: rgba(255, 255, 255, 0.16); }
.cl-btn-more:disabled { opacity: 0.6; cursor: not-allowed; }
.cl-modal-error {
    background: rgba(228, 76, 101, 0.18);
    border-left: 3px solid #e44c65;
    padding: 0.55em 0.8em;
    border-radius: 3px;
    color: #fff;
    font-size: 0.92em;
    margin-top: 0.6em;
}

/* ===== Edit Metadata modal ===== */
.em-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    z-index: 9999;
    padding: 4em 1em 2em 1em;
    overflow-y: auto;
}
.em-modal-overlay[hidden] { display: none; }
.em-modal {
    background: #1f1f2e;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    width: 100%;
    max-width: 640px;
    color: #fff;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.6);
}
.em-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1em 1.25em;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}
.em-modal-header h3 { margin: 0; color: #fff; font-size: 1.15em; }
.em-modal-close {
    background: none; border: none; color: rgba(255, 255, 255, 0.7);
    font-size: 1.6em; line-height: 1; cursor: pointer;
    padding: 0 0.3em;
}
.em-modal-close:hover { color: #fff; }
.em-modal-form { padding: 1em 1.25em 1.25em 1.25em; }
.em-form-row { margin-bottom: 0.9em; }
.em-form-row label {
    display: block;
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.9em;
    margin-bottom: 0.3em;
}
.em-required { color: #e44c65; }
/* Override site-wide form rules (select height:3em, input width:100% etc.)
   inside the modal scope only. Same recipe as ms-modal-form / cm-modal. */
.em-modal-form input[type="text"],
.em-modal-form input[type="number"],
.em-modal-form textarea,
.em-modal-form select {
    width: 100%;
    box-sizing: border-box;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    color: #fff;
    padding: 0.55em 0.7em;
    font-size: 0.95em;
    font-family: inherit;
    height: auto;
    line-height: 1.4;
    background-image: none;
}
.em-modal-form select {
    padding-right: 2.2em;
    background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40' viewBox='0 0 40 40'%3E%3Cpath d='M9.4,12.3l10.4,10.4l10.4-10.4c0.2-0.2,0.5-0.4,0.9-0.4c0.3,0,0.6,0.1,0.9,0.4l3.3,3.3c0.2,0.2,0.4,0.5,0.4,0.9 c0,0.4-0.1,0.6-0.4,0.9L20.7,31.9c-0.2,0.2-0.5,0.4-0.9,0.4c-0.3,0-0.6-0.1-0.9-0.4L4.3,17.3c-0.2-0.2-0.4-0.5-0.4-0.9 c0-0.4,0.1-0.6,0.4-0.9l3.3-3.3c0.2-0.2,0.5-0.4,0.9-0.4S9.1,12.1,9.4,12.3z' fill='rgba(255,255,255,0.55)' /%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.55em center;
    background-size: 0.9em;
    appearance: auto;
}
.em-modal-form select option,
.em-modal-form select optgroup {
    background: #2a2a3a;
    color: #fff;
}
.em-modal-form input:focus,
.em-modal-form textarea:focus,
.em-modal-form select:focus {
    border-color: #e44c65;
    outline: none;
}
.em-modal-form textarea { resize: vertical; min-height: 4em; }
.em-modal-form input:disabled {
    background: rgba(255, 255, 255, 0.03);
    color: rgba(255, 255, 255, 0.5);
    cursor: not-allowed;
}
.em-vocab-other { margin-top: 0.4em; }
.em-vocab-other[hidden] { display: none; }
.em-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 1em;
}
.em-readonly-note {
    margin-top: 0.35em;
    color: rgba(255, 200, 100, 0.85);
    font-size: 0.82em;
    font-style: italic;
    line-height: 1.35;
}
.em-readonly-note[hidden] { display: none; }
.em-modal-error {
    background: rgba(228, 76, 101, 0.18);
    border-left: 3px solid #e44c65;
    padding: 0.55em 0.8em;
    border-radius: 3px;
    margin: 0.4em 0 0.8em 0;
    color: #fff;
    font-size: 0.92em;
}
.em-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.6em;
    margin-top: 1em;
}
.em-btn-cancel,
.em-btn-submit {
    border: none;
    border-radius: 4px;
    padding: 0.55em 1.2em;
    font-size: 0.95em;
    cursor: pointer;
}
.em-btn-cancel {
    background: rgba(255, 255, 255, 0.10);
    color: #fff;
}
.em-btn-cancel:hover { background: rgba(255, 255, 255, 0.16); }
.em-btn-submit {
    background: #e44c65;
    color: #fff;
}
.em-btn-submit:hover:not(:disabled) { background: #f06880; }
.em-btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
</style>

<div id="main" class="wrapper style1">
    <div class="container">

<?php if ($notFound): ?>
        <div class="sd-notfound">
            <h2>Sample not found.</h2>
            <p>The sample may have been removed, or you may have followed a broken link.</p>
            <p><a class="sd-action-btn" href="/my_samples.php">Back to My Samples</a></p>
        </div>
<?php else: ?>
        <div class="sd-wrap">
            <a class="sd-back-link" id="sd-back-link" href="/my_samples.php">← Back to My Samples</a>
            <div class="sd-header">
                <h1 id="sd-title"></h1>
            </div>

            <div class="sd-actions">
                <a class="sd-action-btn outline" href="#" id="sd-share-btn">Share</a>
                <div class="sd-share-line"><strong>SAMPLE URL:</strong><span id="sd-share-url"></span></div>
                <a class="sd-action-btn" href="#" id="sd-collab-btn" style="display:none">Collaborate</a>
                <div class="sd-avatars" id="sd-avatars"></div>
            </div>

            <div class="sd-section">
                <h3>Sample Metadata</h3>
                <div class="sd-metadata-row">
                    <div>
                        <div class="sd-metadata-fields" id="sd-metadata-fields"></div>
                        <div class="sd-metadata-actions">
                            <a class="sd-action-btn" href="#" id="sd-edit-btn" style="display:none">Edit Metadata</a>
                            <a class="sd-action-btn outline" href="#" id="sd-changelog-btn">View Changelog</a>
                        </div>
                    </div>
                    <div class="sd-family-widget" id="sd-family-widget">
                        <svg viewBox="0 0 280 220" id="sd-family-svg"></svg>
                        <div class="sd-family-detail" id="sd-family-detail"></div>
                    </div>
                </div>
            </div>

            <div class="sd-type-tabs">
                <span>Type:</span>
                <button type="button" class="sd-tab active" data-type="all">All</button>
                <button type="button" class="sd-tab" data-type="field">StraboField</button>
                <button type="button" class="sd-tab" data-type="micro">StraboMicro</button>
                <button type="button" class="sd-tab" data-type="experimental">StraboExperimental</button>
            </div>

            <div id="sd-cards"></div>

            <div class="bottomSpacer"></div>
        </div>
<?php endif; ?>

    </div>
</div>

<?php if (!$notFound): ?>
<!-- Collaborator-management modal. Owner-only — opened from the Collaborate
     button. Hidden by default; the JS below toggles visibility and drives
     the API via /samples_collab.php. -->
<div id="cm-modal-overlay" class="cm-modal-overlay" hidden>
    <div class="cm-modal" role="dialog" aria-modal="true" aria-labelledby="cm-modal-title">
        <div class="cm-modal-header">
            <h3 id="cm-modal-title">Manage Collaborators</h3>
            <button type="button" class="cm-modal-close" id="cm-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="cm-modal-body">
            <div class="cm-section">
                <h4>Current Collaborators</h4>
                <div id="cm-list"><div class="cm-loading">Loading&hellip;</div></div>
            </div>
            <div class="cm-section">
                <h4>Invite New Collaborators</h4>
                <div class="cm-invite-form">
                    <label for="cm-invite-emails">Email addresses</label>
                    <textarea id="cm-invite-emails" rows="2" placeholder="One per line, or comma-separated"></textarea>
                    <label for="cm-invite-level">Permission level</label>
                    <select id="cm-invite-level">
                        <option value="edit">Can edit</option>
                        <option value="readonly">Read-only</option>
                    </select>
                    <button type="button" class="cm-btn-submit" id="cm-invite-btn">Send Invites</button>
                </div>
                <div id="cm-invite-results" class="cm-invite-results" hidden></div>
            </div>
            <div class="cm-modal-error" id="cm-modal-error" hidden></div>
        </div>
    </div>
</div>

<!-- Changelog viewer modal. Visible to anyone who can read the sample
     (owner + accepted collaborators incl. readonly). Opened from the
     View Changelog button under Sample Metadata. Drives /samples_changelog.php
     with a single 'list' action; renders distinct shapes per change_type
     (incl. the spine_diff payload from upload updates and the
     writeback_translation note shape from cross-vocab pushes). -->
<div id="cl-modal-overlay" class="cl-modal-overlay" hidden>
    <div class="cl-modal" role="dialog" aria-modal="true" aria-labelledby="cl-modal-title">
        <div class="cl-modal-header">
            <h3 id="cl-modal-title">Changelog</h3>
            <button type="button" class="cl-modal-close" id="cl-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="cl-modal-body">
            <div id="cl-list" class="cl-list"><div class="cl-loading">Loading&hellip;</div></div>
            <div class="cl-pagination" id="cl-pagination" hidden>
                <span class="cl-counter" id="cl-counter"></span>
                <button type="button" class="cl-btn-more" id="cl-load-more">Load more</button>
            </div>
            <div class="cl-modal-error" id="cl-modal-error" hidden></div>
        </div>
    </div>
</div>

<!-- Edit Metadata modal. Visible to owner + accepted-edit collaborators
     (gated by perms.canEdit). Opened from the Edit Metadata button under
     Sample Metadata. Edits writable spine fields only — parent management
     is the separate /sample/{id}/parent sub-resource. Lat/lng inputs are
     disabled when the sample has a Field link (the Spot's geometry is
     authoritative per §6.1; the API would 409 the request anyway). -->
<div id="em-modal-overlay" class="em-modal-overlay" hidden>
    <div class="em-modal" role="dialog" aria-modal="true" aria-labelledby="em-modal-title">
        <div class="em-modal-header">
            <h3 id="em-modal-title">Edit Sample Metadata</h3>
            <button type="button" class="em-modal-close" id="em-modal-close" aria-label="Close">&times;</button>
        </div>
        <form id="em-modal-form" class="em-modal-form" novalidate>
            <div class="em-form-row">
                <label for="em-f-name">Sample ID <span class="em-required">*</span></label>
                <input type="text" id="em-f-name" maxlength="500" autocomplete="off">
            </div>
            <div class="em-form-row">
                <label for="em-f-igsn">IGSN</label>
                <input type="text" id="em-f-igsn" maxlength="500" autocomplete="off">
            </div>
            <div class="em-form-grid">
                <div class="em-form-row">
                    <label for="em-f-type">Material Type</label>
                    <?php echo samples_vocab_render_material_select('em-f-type'); ?>
                    <input type="text" id="em-f-type-other" class="em-vocab-other" maxlength="500" autocomplete="off" placeholder="Enter material type" hidden>
                </div>
                <div class="em-form-row">
                    <label for="em-f-purpose">Sampling Purpose</label>
                    <?php echo samples_vocab_render_purpose_select('em-f-purpose'); ?>
                    <input type="text" id="em-f-purpose-other" class="em-vocab-other" maxlength="500" autocomplete="off" placeholder="Enter sampling purpose" hidden>
                </div>
                <div class="em-form-row">
                    <label for="em-f-lat">Latitude</label>
                    <input type="text" id="em-f-lat" inputmode="decimal" autocomplete="off" placeholder="-90 to 90">
                    <div class="em-readonly-note" id="em-readonly-note" hidden>
                        Location is managed by StraboField &mdash; edit the Spot's geometry in the StraboField project.
                    </div>
                </div>
                <div class="em-form-row">
                    <label for="em-f-lng">Longitude</label>
                    <input type="text" id="em-f-lng" inputmode="decimal" autocomplete="off" placeholder="-180 to 180">
                </div>
            </div>
            <div class="em-form-row">
                <label for="em-f-desc">Description</label>
                <textarea id="em-f-desc" rows="2" maxlength="4000"></textarea>
            </div>
            <div class="em-form-row">
                <label for="em-f-notes">Notes</label>
                <textarea id="em-f-notes" rows="2" maxlength="4000"></textarea>
            </div>
            <div class="em-modal-error" id="em-modal-error" hidden></div>
            <div class="em-modal-footer">
                <button type="button" class="em-btn-cancel" id="em-modal-cancel">Cancel</button>
                <button type="submit" class="em-btn-submit" id="em-modal-submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="sd-data"><?php echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="application/json" id="sd-share-url-data"><?php echo json_encode($shareUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="text/javascript">
(function() {
    'use strict';

    var payload  = JSON.parse(document.getElementById('sd-data').textContent || '{}');
    var shareUrl = JSON.parse(document.getElementById('sd-share-url-data').textContent || '""');
    var sample = payload.sample, owner = payload.owner, links = payload.links || [];
    var collabs = payload.collaborators || [], family = payload.family || {};
    var perms = payload.permissions || {isOwner: false, canEdit: false};

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function fmtDate(ts) {
        if (!ts) return '—';
        var d = new Date(ts);
        if (isNaN(d.getTime())) return ts;
        return d.toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: 'numeric'});
    }
    function field(label, value) {
        if (value === null || value === undefined || value === '') return '';
        return '<div><span class="sd-field-label">' + escapeHtml(label) + ':</span> ' + escapeHtml(value) + '</div>';
    }
    function subsystemLabel(s) {
        if (s === 'field')        return 'StraboField';
        if (s === 'micro')        return 'StraboMicro';
        if (s === 'experimental') return 'StraboExperimental';
        return s;
    }
    function subsystemIcon(s) {
        // Canonical subsystem icons used across the site (also in
        // /fullsearch as .pickaxe-image / .microscope-image / .beaker-image).
        var src = s === 'field' ? '/fullsearch/images/pickaxe.png'
                : s === 'micro' ? '/fullsearch/images/microscope.png'
                :                 '/fullsearch/images/beaker.png';
        var alt = subsystemLabel(s);
        return '<img class="sd-link-icon" src="' + src + '" alt="' + alt + '">';
    }

    function viewSampleHref(node) {
        return '/samples/' + encodeURIComponent(node.userpkey) + '/' + encodeURIComponent(node.id);
    }

    // ---- Back-to-MySamples link ----
    // If the viewer came from /my_samples.php (with any query state),
    // point the explicit Back link at that exact URL so filter/sort/
    // search are preserved. Otherwise leave the default. The browser
    // back button already restores state correctly via MySamples's
    // history.replaceState-driven URL sync; this is for users who don't
    // reach for the browser back.
    (function() {
        try {
            var ref = document.referrer || '';
            if (ref) {
                var u = new URL(ref, window.location.origin);
                if (u.origin === window.location.origin && u.pathname === '/my_samples.php') {
                    document.getElementById('sd-back-link').href = u.pathname + u.search + u.hash;
                }
            }
        } catch (_) { /* leave default */ }
    })();

    // ---- Header + metadata block ----
    document.getElementById('sd-title').textContent = 'Sample: ' + (sample.name || sample.id);
    var shareEl = document.getElementById('sd-share-url');
    shareEl.textContent = shareUrl;
    document.getElementById('sd-share-btn').addEventListener('click', function(e) {
        e.preventDefault();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(shareUrl).then(function() {
                this.textContent = 'Copied!';
                setTimeout(function() { document.getElementById('sd-share-btn').textContent = 'Share'; }, 1200);
            }.bind(this));
        }
    });

    // 'Sample ID' is the scientific identifier (sample.name) — the canonical
    // user-facing label. sample.id is the technical PK / URL token and only
    // surfaces in the share URL above. Falls back to sample.id when name is
    // null (legacy/migrated rows without a populated name).
    var metaHtml = '';
    metaHtml += field('Sample ID',                    sample.name || sample.id);
    metaHtml += field('Sample Owner',                 owner && owner.name);
    metaHtml += field('Last Updated',                 fmtDate(sample.modified_at));
    metaHtml += field('Material Type',                sample.display_sample_type);
    metaHtml += field('Sample Purpose',               sample.display_sample_purpose);
    if (sample.latitude !== null && sample.longitude !== null) {
        metaHtml += field('Current Sample Location', sample.latitude.toFixed(6) + ', ' + sample.longitude.toFixed(6));
    }
    metaHtml += field('IGSN',                         sample.igsn);
    metaHtml += field('Description',                  sample.description);
    metaHtml += field('Notes',                        sample.notes);
    document.getElementById('sd-metadata-fields').innerHTML = metaHtml || '<div style="opacity:.6">No metadata recorded.</div>';

    // Edit + Collaborate visibility from perms.
    if (perms.canEdit) document.getElementById('sd-edit-btn').style.display    = 'inline-block';
    if (perms.isOwner) document.getElementById('sd-collab-btn').style.display  = 'inline-block';
    document.getElementById('sd-edit-btn').addEventListener('click', function(e) {
        e.preventDefault();
        openEditModal();
    });
    document.getElementById('sd-collab-btn').addEventListener('click', function(e) {
        e.preventDefault();
        openCollabModal();
    });
    document.getElementById('sd-changelog-btn').addEventListener('click', function(e) {
        e.preventDefault();
        openChangelogModal();
    });

    // ---- Collaborator avatars ----
    var avatarsHtml = collabs.map(function(c) {
        var title = c.name + ' (' + c.permission_level + ')';
        return '<span class="sd-avatar" title="' + escapeHtml(title) + '">' + escapeHtml(c.initials || '?') + '</span>';
    }).join('');
    document.getElementById('sd-avatars').innerHTML = avatarsHtml;

    // ---- Family tree widget (simple radial; §12.2.1 v1) ----
    (function renderFamily() {
        var svg = document.getElementById('sd-family-svg');
        var detail = document.getElementById('sd-family-detail');
        var W = 280, H = 220, cx = W/2, cy = H/2 + 10;
        var children = family.children || [];
        var parent = family.parent;

        var positions = [];
        positions.push({key: 'focus', x: cx, y: cy, label: family.focus && (family.focus.name || family.focus.id) || sample.id, node: family.focus});

        if (parent) {
            positions.push({
                key: 'parent', x: cx, y: 30,
                label: parent.orphaned ? '(inaccessible)' : (parent.name || parent.id),
                node: parent,
            });
        }

        if (children.length) {
            // Arc the children below the focus.
            var arcR = 70, spanDeg = Math.min(180, 30 * children.length + 60);
            var startDeg = 90 + spanDeg/2, stepDeg = children.length > 1 ? spanDeg / (children.length - 1) : 0;
            children.forEach(function(c, i) {
                var deg = children.length === 1 ? 90 : (startDeg - stepDeg * i);
                var rad = deg * Math.PI / 180;
                positions.push({
                    key: 'child-' + i, x: cx + arcR * Math.cos(rad), y: cy + arcR * Math.sin(rad),
                    label: c.name || c.id, node: c,
                });
            });
        }

        // Edges from focus.
        var edges = '';
        positions.forEach(function(p) {
            if (p.key === 'focus') return;
            edges += '<line class="sd-family-edge" x1="' + cx + '" y1="' + cy + '" x2="' + p.x + '" y2="' + p.y + '"/>';
        });

        var nodes = positions.map(function(p, i) {
            var safe = escapeHtml(p.label).substring(0, 18);
            return '<g class="sd-family-node ' + (p.key === 'focus' ? 'focus' : '') + '" data-idx="' + i + '">'
                + '<circle cx="' + p.x + '" cy="' + p.y + '" r="18"/>'
                + '<text x="' + p.x + '" y="' + (p.y + 32) + '">' + safe + '</text>'
                + '</g>';
        }).join('');

        svg.innerHTML = edges + nodes;

        svg.addEventListener('click', function(e) {
            var g = e.target.closest && e.target.closest('.sd-family-node');
            if (!g) { detail.classList.remove('visible'); return; }
            var idx = parseInt(g.getAttribute('data-idx'), 10);
            var p = positions[idx];
            if (!p || !p.node) return;
            if (p.key === 'focus') { detail.classList.remove('visible'); return; }
            var n = p.node;
            var html = '';
            html += '<span class="sd-family-close" data-close="1">×</span>';
            if (n.orphaned) {
                html += '<div><strong>Parent (inaccessible)</strong></div>';
                html += '<div style="opacity:.7">id: ' + escapeHtml(n.parent_sample_id) + ' / userpkey: ' + n.parent_userpkey + '</div>';
            } else {
                html += '<div><strong>' + escapeHtml(n.name || n.id) + '</strong></div>';
                html += '<div style="opacity:.75">' + escapeHtml(n.display_sample_type || '—') + ' / ' + escapeHtml(n.display_sample_purpose || '—') + '</div>';
                html += '<a class="sd-action-btn" href="' + escapeHtml(viewSampleHref(n)) + '">View Sample</a>';
            }
            detail.innerHTML = html;
            detail.classList.add('visible');
        });
        detail.addEventListener('click', function(e) {
            if (e.target.getAttribute('data-close')) detail.classList.remove('visible');
        });
    })();

    // ---- Subsystem cards (card-per-link, §16 #9 v1) ----
    // The render contract: takes a flat list of {subsystem, reference_id, ...}
    // and emits one card per row. Swapping to card-per-subsystem + picker
    // is purely a render-layer change — links[] stays the same.
    function renderCards(typeFilter) {
        var visible = links.filter(function(l) {
            return typeFilter === 'all' || l.subsystem === typeFilter;
        });
        if (!links.length) {
            return '<div class="sd-empty-cards">No subsystem links yet. Upload a Field / Micro / Experimental project that references this sample.</div>';
        }
        if (!visible.length) {
            return '<div class="sd-empty-cards">No ' + escapeHtml(subsystemLabel(typeFilter)) + ' links.</div>';
        }
        return visible.map(function(l) { return renderLinkCard(l); }).join('');
    }

    function renderLinkCard(link) {
        var meta = link.reference_metadata || {};
        var subData = link.subsystem === 'field'        ? (sample.field_data || {})
                    : link.subsystem === 'micro'        ? (sample.micro_data || {})
                    :                                     (sample.experimental_data || {});
        var label = subsystemLabel(link.subsystem);

        // view_href is resolved server-side against the host project's
        // visibility (see samples_detail.php's link-resolution block).
        // Null means the viewer can't reach the host page; render a
        // disabled button with the reason so the UX is honest.
        //
        // target=_blank because these jump out of the samples system into
        // a subsystem page that has no back-link home — opening in a new
        // tab keeps the Sample Overview as the user's anchor point.
        // rel=noopener prevents the opened page from manipulating
        // window.opener (defensive — the linked pages are first-party,
        // but the rel hardening is cheap).
        var viewBtnHtml;
        if (link.view_href) {
            viewBtnHtml = '<a class="sd-view-btn" target="_blank" rel="noopener" href="' + escapeHtml(link.view_href) + '">View ' + escapeHtml(label) + '</a>';
        } else {
            var reason = link.view_unavailable || 'View page is not available for this link.';
            viewBtnHtml = '<span class="sd-view-btn sd-view-btn-disabled" title="' + escapeHtml(reason) + '">View ' + escapeHtml(label) + '</span>';
        }

        var fieldsHtml = '';
        fieldsHtml += field('Reference ID', link.reference_id);
        if (meta.project_name)  fieldsHtml += field('Project',  meta.project_name);
        if (meta.dataset_name)  fieldsHtml += field('Dataset',  meta.dataset_name);
        if (subData.material_type)  fieldsHtml += field('Material Type',  subData.material_type);
        if (subData.sample_id_name) fieldsHtml += field('Sample Label',   subData.sample_id_name);
        if (subData.inplaceness)    fieldsHtml += field('In-place-ness',  subData.inplaceness);
        if (subData.notes)          fieldsHtml += field('Notes',          subData.notes);
        if (!fieldsHtml) fieldsHtml = '<div style="opacity:.6">No subsystem-specific fields stored.</div>';

        return ''
            + '<div class="sd-link-card" data-subsystem="' + escapeHtml(link.subsystem) + '">'
            +   '<div class="sd-link-card-head">'
            +     subsystemIcon(link.subsystem)
            +     '<span>' + escapeHtml(label) + ' &middot; Last Updated ' + escapeHtml(fmtDate(link.modified_at)) + '</span>'
            +     viewBtnHtml
            +   '</div>'
            +   '<div class="sd-link-fields">' + fieldsHtml + '</div>'
            + '</div>';
    }

    var $cards = document.getElementById('sd-cards');
    var $tabs  = document.querySelectorAll('.sd-tab');
    function applyFilter(type) {
        $tabs.forEach(function(b) { b.classList.toggle('active', b.getAttribute('data-type') === type); });
        $cards.innerHTML = renderCards(type);
    }
    $tabs.forEach(function(b) {
        b.addEventListener('click', function() { applyFilter(b.getAttribute('data-type')); });
    });
    applyFilter('all');

    // ---- Collaborator-management modal ----
    var $cmModal     = document.getElementById('cm-modal-overlay');
    var $cmClose     = document.getElementById('cm-modal-close');
    var $cmList      = document.getElementById('cm-list');
    var $cmInviteBtn = document.getElementById('cm-invite-btn');
    var $cmEmails    = document.getElementById('cm-invite-emails');
    var $cmLevel     = document.getElementById('cm-invite-level');
    var $cmResults   = document.getElementById('cm-invite-results');
    var $cmError     = document.getElementById('cm-modal-error');
    var cmEscHandler = null;

    function openCollabModal() {
        $cmModal.hidden = false;
        document.body.style.overflow = 'hidden';
        cmEscHandler = function(e) { if (e.key === 'Escape') closeCollabModal(); };
        document.addEventListener('keydown', cmEscHandler);
        fetchAndRenderCollaborators();
    }
    function closeCollabModal() {
        $cmModal.hidden = true;
        document.body.style.overflow = '';
        $cmList.innerHTML = '<div class="cm-loading">Loading&hellip;</div>';
        $cmEmails.value = '';
        $cmLevel.value = 'edit';
        $cmResults.hidden = true;
        $cmResults.innerHTML = '';
        $cmError.hidden = true;
        $cmError.textContent = '';
        $cmInviteBtn.disabled = false;
        $cmInviteBtn.textContent = 'Send Invites';
        if (cmEscHandler) {
            document.removeEventListener('keydown', cmEscHandler);
            cmEscHandler = null;
        }
    }
    $cmModal.addEventListener('click', function(e) {
        if (e.target === $cmModal) closeCollabModal();
    });
    $cmClose.addEventListener('click', closeCollabModal);

    function postCollab(payload) {
        return fetch('/samples_collab.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        }).then(function(r) {
            return r.json().then(
                function(j) { return {status: r.status, body: j}; },
                function()  { return {status: r.status, body: null}; }
            );
        });
    }

    function fetchAndRenderCollaborators() {
        postCollab({
            sample_id:  sample.id,
            owner_pkey: owner.pkey,
            action:     'list',
        }).then(function(res) {
            if (!res.body || !res.body.ok) {
                var err = res.body && res.body.error ? res.body.error : 'unknown';
                $cmList.innerHTML = '<div class="cm-empty">Could not load collaborators (' + escapeHtml(err) + ').</div>';
                return;
            }
            renderCollaboratorList(res.body.collaborators || []);
        }).catch(function() {
            $cmList.innerHTML = '<div class="cm-empty">Network error loading collaborators.</div>';
        });
    }

    function renderCollaboratorList(rows) {
        if (!rows.length) {
            $cmList.innerHTML = '<div class="cm-empty">No collaborators yet. Invite someone below.</div>';
            return;
        }
        $cmList.innerHTML = rows.map(function(r) {
            var statusClass = r.accepted ? 'accepted' : 'pending';
            var statusText  = r.accepted ? 'Accepted'  : 'Pending';
            return ''
                + '<div class="cm-row" data-pkey="' + r.collaborator_pkey + '">'
                +   '<div class="cm-avatar">' + escapeHtml(r.initials || '?') + '</div>'
                +   '<div class="cm-row-meta">'
                +     '<div class="cm-row-name">' + escapeHtml(r.name || '(unknown)')
                +       '<span class="cm-status-badge ' + statusClass + '">' + statusText + '</span>'
                +     '</div>'
                +     '<div class="cm-row-email">' + escapeHtml(r.email || '') + '</div>'
                +   '</div>'
                +   '<select class="cm-row-level" data-pkey="' + r.collaborator_pkey + '">'
                +     '<option value="edit"'     + (r.permission_level === 'edit'     ? ' selected' : '') + '>Edit</option>'
                +     '<option value="readonly"' + (r.permission_level === 'readonly' ? ' selected' : '') + '>Read-only</option>'
                +   '</select>'
                +   '<button type="button" class="cm-row-remove" data-pkey="' + r.collaborator_pkey + '" aria-label="Remove" title="Remove">&times;</button>'
                + '</div>';
        }).join('');
    }

    $cmList.addEventListener('change', function(e) {
        if (!e.target.classList || !e.target.classList.contains('cm-row-level')) return;
        var pkey = parseInt(e.target.getAttribute('data-pkey'), 10);
        var level = e.target.value;
        e.target.disabled = true;
        postCollab({
            sample_id:         sample.id,
            owner_pkey:        owner.pkey,
            action:            'update_level',
            collaborator_pkey: pkey,
            permission_level:  level,
        }).then(function(res) {
            e.target.disabled = false;
            if (!res.body || !res.body.ok) {
                var err = res.body && res.body.error ? res.body.error : 'unknown';
                $cmError.textContent = 'Could not change permission level (' + err + ').';
                $cmError.hidden = false;
                fetchAndRenderCollaborators();  // revert visually by reload
            } else {
                $cmError.hidden = true;
            }
        }).catch(function() {
            e.target.disabled = false;
            $cmError.textContent = 'Network error changing permission level.';
            $cmError.hidden = false;
        });
    });

    $cmList.addEventListener('click', function(e) {
        if (!e.target.classList || !e.target.classList.contains('cm-row-remove')) return;
        var pkey = parseInt(e.target.getAttribute('data-pkey'), 10);
        var row  = e.target.closest('.cm-row');
        var name = row && row.querySelector('.cm-row-name')
                   ? row.querySelector('.cm-row-name').textContent.replace(/(Accepted|Pending)\s*$/, '').trim()
                   : 'this collaborator';
        if (!confirm('Remove ' + name + '?')) return;
        e.target.disabled = true;
        postCollab({
            sample_id:         sample.id,
            owner_pkey:        owner.pkey,
            action:            'remove',
            collaborator_pkey: pkey,
        }).then(function(res) {
            if (res.body && res.body.ok) {
                $cmError.hidden = true;
                fetchAndRenderCollaborators();
            } else {
                e.target.disabled = false;
                var err = res.body && res.body.error ? res.body.error : 'unknown';
                $cmError.textContent = 'Could not remove (' + err + ').';
                $cmError.hidden = false;
            }
        }).catch(function() {
            e.target.disabled = false;
            $cmError.textContent = 'Network error removing collaborator.';
            $cmError.hidden = false;
        });
    });

    $cmInviteBtn.addEventListener('click', function() {
        $cmError.hidden = true;
        $cmResults.hidden = true;
        $cmResults.innerHTML = '';
        var raw = $cmEmails.value;
        // Allow any whitespace, comma, or semicolon as separators.
        var emails = raw.split(/[\s,;]+/).filter(function(e) { return e.length > 0; });
        if (!emails.length) {
            $cmError.textContent = 'Please enter at least one email address.';
            $cmError.hidden = false;
            return;
        }
        $cmInviteBtn.disabled = true;
        $cmInviteBtn.textContent = 'Sending&hellip;';
        postCollab({
            sample_id:        sample.id,
            owner_pkey:       owner.pkey,
            action:           'invite',
            emails:           emails,
            permission_level: $cmLevel.value,
        }).then(function(res) {
            $cmInviteBtn.disabled = false;
            $cmInviteBtn.textContent = 'Send Invites';
            if (!res.body || !res.body.ok) {
                var err = res.body && res.body.error ? res.body.error : 'unknown';
                $cmError.textContent = 'Invite failed (' + err + ').';
                $cmError.hidden = false;
                return;
            }
            var statusMsg = {
                invited:        'Invited',
                re_enabled:     'Re-invited (was previously removed)',
                already_active: 'Already a collaborator',
                unknown:        'No StraboSpot user with this email',
                is_owner:       'Cannot invite the owner',
            };
            $cmResults.innerHTML = (res.body.results || []).map(function(r) {
                var label = statusMsg[r.status] || r.status;
                return '<div class="cm-invite-result ' + escapeHtml(r.status) + '"><strong>'
                     + escapeHtml(r.email) + '</strong>: ' + escapeHtml(label) + '</div>';
            }).join('');
            $cmResults.hidden = false;
            $cmEmails.value = '';
            fetchAndRenderCollaborators();  // refresh roster
        }).catch(function() {
            $cmInviteBtn.disabled = false;
            $cmInviteBtn.textContent = 'Send Invites';
            $cmError.textContent = 'Network error sending invites.';
            $cmError.hidden = false;
        });
    });

    // ---- Changelog viewer modal ----
    // Pulls /samples_changelog.php with action=list, render distinct shapes
    // per change_type. Pagination is a "Load more" button that grows the
    // accumulated list — newest-first ordering is preserved across pages.
    var $clModal    = document.getElementById('cl-modal-overlay');
    var $clClose    = document.getElementById('cl-modal-close');
    var $clList     = document.getElementById('cl-list');
    var $clPager    = document.getElementById('cl-pagination');
    var $clCounter  = document.getElementById('cl-counter');
    var $clMore     = document.getElementById('cl-load-more');
    var $clError    = document.getElementById('cl-modal-error');
    var clEscHandler = null;
    var CL_PAGE_SIZE = 50;
    var clState = { entries: [], total: 0, offset: 0 };

    function openChangelogModal() {
        $clModal.hidden = false;
        document.body.style.overflow = 'hidden';
        clEscHandler = function(e) { if (e.key === 'Escape') closeChangelogModal(); };
        document.addEventListener('keydown', clEscHandler);
        clState = { entries: [], total: 0, offset: 0 };
        $clList.innerHTML = '<div class="cl-loading">Loading&hellip;</div>';
        $clPager.hidden = true;
        $clError.hidden = true;
        fetchChangelogPage();
    }
    function closeChangelogModal() {
        $clModal.hidden = true;
        document.body.style.overflow = '';
        if (clEscHandler) {
            document.removeEventListener('keydown', clEscHandler);
            clEscHandler = null;
        }
    }
    $clModal.addEventListener('click', function(e) {
        if (e.target === $clModal) closeChangelogModal();
    });
    $clClose.addEventListener('click', closeChangelogModal);
    $clMore.addEventListener('click', function() { fetchChangelogPage(); });

    function fetchChangelogPage() {
        $clMore.disabled = true;
        fetch('/samples_changelog.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action:     'list',
                sample_id:  sample.id,
                owner_pkey: owner.pkey,
                limit:      CL_PAGE_SIZE,
                offset:     clState.offset,
            }),
        }).then(function(r) {
            return r.json().then(
                function(j) { return {status: r.status, body: j}; },
                function()  { return {status: r.status, body: null}; }
            );
        }).then(function(res) {
            $clMore.disabled = false;
            if (!res.body || !res.body.ok) {
                var err = res.body && res.body.error ? res.body.error : 'unknown';
                if (clState.entries.length === 0) {
                    $clList.innerHTML = '<div class="cl-empty">Could not load changelog (' + escapeHtml(err) + ').</div>';
                } else {
                    $clError.textContent = 'Could not load more entries (' + err + ').';
                    $clError.hidden = false;
                }
                return;
            }
            clState.entries = clState.entries.concat(res.body.changelog || []);
            clState.total   = res.body.total || 0;
            clState.offset  = clState.entries.length;
            renderChangelogList();
        }).catch(function() {
            $clMore.disabled = false;
            if (clState.entries.length === 0) {
                $clList.innerHTML = '<div class="cl-empty">Network error loading changelog.</div>';
            } else {
                $clError.textContent = 'Network error loading more entries.';
                $clError.hidden = false;
            }
        });
    }

    function renderChangelogList() {
        if (!clState.entries.length) {
            $clList.innerHTML = '<div class="cl-empty">No history recorded for this sample.</div>';
            $clPager.hidden = true;
            return;
        }
        $clList.innerHTML = clState.entries.map(renderChangelogEntry).join('');
        if (clState.entries.length < clState.total) {
            $clCounter.textContent = 'Showing ' + clState.entries.length + ' of ' + clState.total;
            $clMore.hidden = false;
            $clPager.hidden = false;
        } else {
            $clCounter.textContent = clState.total + ' entr' + (clState.total === 1 ? 'y' : 'ies');
            $clMore.hidden = true;
            $clPager.hidden = false;
        }
    }

    function fmtDateTime(ts) {
        if (!ts) return '';
        var d = new Date(ts);
        if (isNaN(d.getTime())) return ts;
        return d.toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit',
        });
    }
    function typeBadgeLabel(t) {
        switch (t) {
            case 'create':                  return 'Created';
            case 'update':                  return 'Updated';
            case 'parent_set':              return 'Parent set';
            case 'parent_clear':            return 'Parent cleared';
            case 'composition_change':      return 'Composition';
            case 'parameters_change':       return 'Parameters';
            case 'documents_change':        return 'Documents';
            case 'writeback_translation':   return 'Field writeback';
            default:                         return t;
        }
    }
    function sourceBadgeLabel(s) {
        switch (s) {
            case 'samples_api':   return 'Samples app';
            case 'field':         return 'StraboField';
            case 'micro':         return 'StraboMicro';
            case 'experimental':  return 'StraboExperimental';
            default:               return s;
        }
    }
    function fmtValue(v) {
        if (v === null || v === undefined || v === '') return '(empty)';
        if (typeof v === 'object') return JSON.stringify(v);
        return String(v);
    }

    function renderChangelogEntry(entry) {
        var typeClass = 'cl-type-' + (entry.change_type || '').replace(/[^a-z_]/gi, '_');
        return ''
            + '<div class="cl-row">'
            +   '<div class="cl-avatar" title="' + escapeHtml(entry.actor_name || '') + '">'
            +     escapeHtml(entry.actor_initials || '?')
            +   '</div>'
            +   '<div class="cl-row-meta">'
            +     '<div class="cl-row-head">'
            +       '<span class="cl-actor">' + escapeHtml(entry.actor_name || '(unknown)') + '</span>'
            +       '<span class="cl-type ' + typeClass + '">' + escapeHtml(typeBadgeLabel(entry.change_type)) + '</span>'
            +       (entry.source_subsystem ? '<span class="cl-source">' + escapeHtml(sourceBadgeLabel(entry.source_subsystem)) + '</span>' : '')
            +       '<span class="cl-time">' + escapeHtml(fmtDateTime(entry.changed_at)) + '</span>'
            +     '</div>'
            +     '<div class="cl-row-body">' + renderEntryBody(entry) + '</div>'
            +   '</div>'
            + '</div>';
    }

    function renderEntryBody(entry) {
        var c = entry.changes || {};
        switch (entry.change_type) {
            case 'create':
                return renderCreateBody(c, entry);
            case 'update':
                return renderUpdateBody(c, entry);
            case 'parent_set':
                return renderParentBody(c, /*cleared*/false);
            case 'parent_clear':
                return renderParentBody(c, /*cleared*/true);
            case 'composition_change':
                return renderCountChangeBody(c, 'composition');
            case 'parameters_change':
                return renderCountChangeBody(c, 'parameters');
            case 'documents_change':
                return renderCountChangeBody(c, 'documents');
            case 'writeback_translation':
                return renderWritebackBody(c);
            default:
                return renderFallbackBody(c);
        }
    }

    // create has two shapes — modal {created: {...spine fields...}} vs.
    // upload {source, spine_written}. Render both clearly.
    function renderCreateBody(c, entry) {
        if (c && c.source) {
            var subsystemLabel =
                  c.source === 'field'        ? 'StraboField'
                : c.source === 'micro'        ? 'StraboMicro'
                : c.source === 'experimental' ? 'StraboExperimental'
                : c.source;
            var spineNote = c.spine_written
                ? ' Spine fields populated from this source.'
                : ' Spine fields preserved from a higher-priority source.';
            return '<div class="cl-summary">Sample created via ' + escapeHtml(subsystemLabel) + ' upload.'
                 + escapeHtml(spineNote) + '</div>';
        }
        // Modal-created sample. The 'created' payload is the initial spine
        // dict — surface the few keys the user cares about.
        var initial = c && c.created ? c.created : {};
        var keys = Object.keys(initial);
        if (!keys.length) {
            return '<div class="cl-summary">Sample created.</div>';
        }
        var items = keys.map(function(k) {
            return ''
                + '<li class="cl-diff-row">'
                +   '<span class="cl-diff-field">' + escapeHtml(k) + '</span>'
                +   '<span class="cl-diff-arrow">=</span>'
                +   '<span class="cl-diff-new">' + escapeHtml(fmtValue(initial[k])) + '</span>'
                + '</li>';
        }).join('');
        return '<div class="cl-summary">Sample created.</div>'
             + '<ul class="cl-diff-list">' + items + '</ul>';
    }

    // update has two shapes:
    //   modal:  {fieldA: {old, new}, fieldB: {updated: true}}
    //   upload: {source, spine_written, *_data: {updated: true}, spine_diff?: {field: {old, new}}}
    // The spine_diff under upload-updates is exactly the same shape as the
    // modal's per-field diff, so we render both with the same row.
    function renderUpdateBody(c, entry) {
        c = c || {};
        var html = '';
        var diffEntries = [];

        if (c.source) {
            // Upload update — surface source + spine_diff (if present).
            var subsystemLabel =
                  c.source === 'field'        ? 'StraboField'
                : c.source === 'micro'        ? 'StraboMicro'
                : c.source === 'experimental' ? 'StraboExperimental'
                : c.source;
            var summary = 'Updated via ' + subsystemLabel + ' upload.';
            if (!c.spine_written) summary += ' Spine fields skipped (higher-priority source already wrote them).';
            html += '<div class="cl-summary">' + escapeHtml(summary) + '</div>';
            if (c.spine_diff && typeof c.spine_diff === 'object') {
                Object.keys(c.spine_diff).forEach(function(k) {
                    diffEntries.push([k, c.spine_diff[k]]);
                });
            }
        } else {
            // Modal update — top-level keys are the diff.
            Object.keys(c).forEach(function(k) {
                diffEntries.push([k, c[k]]);
            });
        }

        if (!diffEntries.length) {
            if (!html) html = '<div class="cl-diff-noop">No spine fields changed.</div>';
            return html;
        }

        html += '<ul class="cl-diff-list">';
        diffEntries.forEach(function(pair) {
            var field = pair[0], delta = pair[1] || {};
            if (delta.updated === true) {
                html += ''
                    + '<li class="cl-diff-row">'
                    +   '<span class="cl-diff-field">' + escapeHtml(field) + '</span>'
                    +   '<span class="cl-diff-noop">(JSONB updated)</span>'
                    + '</li>';
            } else {
                html += ''
                    + '<li class="cl-diff-row">'
                    +   '<span class="cl-diff-field">' + escapeHtml(field) + '</span>'
                    +   '<span class="cl-diff-old">' + escapeHtml(fmtValue(delta.old)) + '</span>'
                    +   '<span class="cl-diff-arrow">→</span>'
                    +   '<span class="cl-diff-new">' + escapeHtml(fmtValue(delta.new)) + '</span>'
                    + '</li>';
            }
        });
        html += '</ul>';
        return html;
    }

    function renderParentBody(c, cleared) {
        var p = (c && c.parent) || {};
        var oldId = p.old && p.old.parent_sample_id ? p.old.parent_sample_id : null;
        var newId = p.new && p.new.parent_sample_id ? p.new.parent_sample_id : null;
        if (cleared) {
            return '<div class="cl-summary">Parent link cleared'
                 + (oldId ? ' (was <code>' + escapeHtml(oldId) + '</code>)' : '')
                 + '.</div>';
        }
        if (oldId && newId) {
            return ''
                + '<div class="cl-summary">Parent changed.</div>'
                + '<ul class="cl-diff-list"><li class="cl-diff-row">'
                +   '<span class="cl-diff-field">parent</span>'
                +   '<span class="cl-diff-old">' + escapeHtml(oldId) + '</span>'
                +   '<span class="cl-diff-arrow">→</span>'
                +   '<span class="cl-diff-new">' + escapeHtml(newId) + '</span>'
                + '</li></ul>';
        }
        return '<div class="cl-summary">Parent set to <code>' + escapeHtml(newId || '?') + '</code>.</div>';
    }

    function renderCountChangeBody(c, key) {
        var delta = (c && c[key]) || {};
        var oldN = delta.old_count === undefined ? '?' : delta.old_count;
        var newN = delta.new_count === undefined ? '?' : delta.new_count;
        var label = key.charAt(0).toUpperCase() + key.slice(1);
        return '<div class="cl-summary">'
             + escapeHtml(label) + ' replaced: '
             + '<span class="cl-diff-old">' + oldN + ' entr' + (oldN === 1 ? 'y' : 'ies') + '</span>'
             + ' <span class="cl-diff-arrow">→</span> '
             + '<span class="cl-diff-new">' + newN + ' entr' + (newN === 1 ? 'y' : 'ies') + '</span>'
             + '.</div>';
    }

    // writeback_translation has two payload variants from
    // StraboSamplesService::writeBackFieldSpot:
    //   {notes: [...]}                                  — mixed push + skips
    //   {skipped_only: true, notes: [...]}              — all-skipped, nothing pushed
    // Each note is either translated ({spine_column, field_key, original, translated})
    // or skipped ({spine_column, field_key, skipped, reason}).
    function renderWritebackBody(c) {
        c = c || {};
        var notes = Array.isArray(c.notes) ? c.notes : [];
        var summary = c.skipped_only
            ? 'Spine edits could not be written back to StraboField — no representable values.'
            : 'Spine edits translated for StraboField writeback.';
        var html = '<div class="cl-summary">' + escapeHtml(summary) + '</div>';
        if (!notes.length) return html;
        html += notes.map(function(n) {
            if (n.skipped !== undefined) {
                return ''
                    + '<div class="cl-wbnote cl-wbnote-skipped">'
                    +   '<strong>' + escapeHtml(n.spine_column || n.field_key || '?') + '</strong>: skipped '
                    +   '<code>' + escapeHtml(fmtValue(n.skipped)) + '</code>'
                    +   '<span class="cl-wbnote-reason">(' + escapeHtml(n.reason || 'no mapping') + ')</span>'
                    + '</div>';
            }
            return ''
                + '<div class="cl-wbnote cl-wbnote-translated">'
                +   '<strong>' + escapeHtml(n.spine_column || n.field_key || '?') + '</strong>: '
                +   '<code>' + escapeHtml(fmtValue(n.original)) + '</code>'
                +   ' <span class="cl-diff-arrow">→</span> '
                +   '<code>' + escapeHtml(fmtValue(n.translated)) + '</code>'
                + '</div>';
        }).join('');
        return html;
    }

    function renderFallbackBody(c) {
        // Unknown change_type — surface raw JSON so the user/dev can still
        // see something. Cheap defensive default; no need to be pretty.
        if (!c) return '<div class="cl-diff-noop">(no detail)</div>';
        return '<pre style="margin:0;padding:0.4em 0.6em;background:rgba(255,255,255,0.05);border-radius:3px;font-size:0.82em;overflow-x:auto;">'
             + escapeHtml(JSON.stringify(c, null, 2))
             + '</pre>';
    }

    // ---- Edit Metadata modal ----
    // Edits the writable spine fields (name, igsn, description, notes,
    // latitude, longitude, display_sample_type, display_sample_purpose).
    // Parent management lives on /sample/{id}/parent and is out of scope
    // for this modal. Lat/lng inputs disable when the sample has a Field
    // link — the Spot's geometry is authoritative (§6.1) and the API
    // would 409 'field_link_read_only' on a lat/lng PUT anyway.
    var VOCAB_OTHER_SENTINEL = '__other__';
    var $emModal       = document.getElementById('em-modal-overlay');
    var $emForm        = document.getElementById('em-modal-form');
    var $emClose       = document.getElementById('em-modal-close');
    var $emCancel      = document.getElementById('em-modal-cancel');
    var $emSubmit      = document.getElementById('em-modal-submit');
    var $emError       = document.getElementById('em-modal-error');
    var $emName        = document.getElementById('em-f-name');
    var $emIgsn        = document.getElementById('em-f-igsn');
    var $emType        = document.getElementById('em-f-type');
    var $emTypeOther   = document.getElementById('em-f-type-other');
    var $emPurpose     = document.getElementById('em-f-purpose');
    var $emPurposeOther= document.getElementById('em-f-purpose-other');
    var $emLat         = document.getElementById('em-f-lat');
    var $emLng         = document.getElementById('em-f-lng');
    var $emDesc        = document.getElementById('em-f-desc');
    var $emNotes       = document.getElementById('em-f-notes');
    var $emReadonlyNote= document.getElementById('em-readonly-note');
    var emEscHandler   = null;

    // Pre-fill a vocab <select>: try to match an option's value; if no
    // match (i.e., the stored value is free-text), select the Other
    // sentinel and pre-fill the companion text input. Cleanly handles
    // null/empty (no selection, no other-input shown).
    function setVocabValue($sel, $other, current) {
        current = (current === null || current === undefined) ? '' : String(current);
        $sel.value = current;
        // If the assignment didn't take, value will revert (most browsers
        // fall through to the first option, '', when no match exists).
        if (current !== '' && $sel.value !== current) {
            $sel.value = VOCAB_OTHER_SENTINEL;
            $other.value = current;
            $other.hidden = false;
        } else {
            $other.value = '';
            $other.hidden = true;
        }
    }

    function readVocabField($sel, $other) {
        if ($sel.value === VOCAB_OTHER_SENTINEL) return ($other.value || '').trim();
        return $sel.value;
    }

    function bindVocabOther($sel, $other) {
        $sel.addEventListener('change', function() {
            var isOther = $sel.value === VOCAB_OTHER_SENTINEL;
            $other.hidden = !isOther;
            if (isOther) $other.focus();
            else         $other.value = '';
        });
    }
    bindVocabOther($emType,    $emTypeOther);
    bindVocabOther($emPurpose, $emPurposeOther);

    // Same numeric-input constraint as the create modal: type="text" with
    // an oninput filter so what the user sees matches what .value returns
    // (type="number" diverges on Firefox + Safari).
    function emConstrainNumeric(input) {
        input.addEventListener('input', function(e) {
            var v = e.target.value;
            var m = v.match(/^-?\d*\.?\d*/);
            var stripped = m ? m[0] : '';
            if (v !== stripped) {
                e.target.value = stripped;
                try { e.target.setSelectionRange(stripped.length, stripped.length); } catch (_) {}
            }
        });
    }
    emConstrainNumeric($emLat);
    emConstrainNumeric($emLng);

    function hasFieldLink() {
        for (var i = 0; i < links.length; i++) {
            if (links[i].subsystem === 'field') return true;
        }
        return false;
    }

    function openEditModal() {
        // Pre-fill from the in-memory sample. Re-prefilling on every open
        // means a successful save followed by reopen shows the fresh values.
        $emName.value  = (sample.name        || '');
        $emIgsn.value  = (sample.igsn        || '');
        $emDesc.value  = (sample.description || '');
        $emNotes.value = (sample.notes       || '');
        setVocabValue($emType,    $emTypeOther,    sample.display_sample_type    || '');
        setVocabValue($emPurpose, $emPurposeOther, sample.display_sample_purpose || '');
        $emLat.value = (sample.latitude  === null || sample.latitude  === undefined) ? '' : String(sample.latitude);
        $emLng.value = (sample.longitude === null || sample.longitude === undefined) ? '' : String(sample.longitude);

        // Lat/lng disabled when a Field link exists. Visible note explains why.
        var fieldLinked = hasFieldLink();
        $emLat.disabled = fieldLinked;
        $emLng.disabled = fieldLinked;
        $emReadonlyNote.hidden = !fieldLinked;
        if (fieldLinked) {
            $emLat.title = 'Managed by StraboField — edit the Spot\'s geometry there.';
            $emLng.title = 'Managed by StraboField — edit the Spot\'s geometry there.';
        } else {
            $emLat.title = '';
            $emLng.title = '';
        }

        $emError.hidden = true;
        $emError.textContent = '';
        $emSubmit.disabled = false;
        $emSubmit.textContent = 'Save Changes';

        $emModal.hidden = false;
        document.body.style.overflow = 'hidden';
        setTimeout(function() { $emName.focus(); }, 0);
        emEscHandler = function(e) { if (e.key === 'Escape') closeEditModal(); };
        document.addEventListener('keydown', emEscHandler);
    }

    function closeEditModal() {
        $emModal.hidden = true;
        document.body.style.overflow = '';
        if (emEscHandler) {
            document.removeEventListener('keydown', emEscHandler);
            emEscHandler = null;
        }
    }

    $emModal.addEventListener('click', function(e) {
        if (e.target === $emModal) closeEditModal();
    });
    $emClose.addEventListener('click', closeEditModal);
    $emCancel.addEventListener('click', closeEditModal);

    // Apply the API response back into the in-memory sample + the rendered
    // metadata block + the page title so the user sees the change without
    // a full reload. The api-core-writes path returns the assembled sample
    // (spine + JSONB + parent if any) on success.
    function applyEditedSample(updated) {
        // Mirror spine fields the page renders.
        sample.name                   = updated.name;
        sample.igsn                   = updated.igsn;
        sample.description            = updated.description;
        sample.notes                  = updated.notes;
        sample.latitude               = updated.latitude;
        sample.longitude              = updated.longitude;
        sample.display_sample_type    = updated.display_sample_type;
        sample.display_sample_purpose = updated.display_sample_purpose;
        sample.modified_at            = updated.modified_at;

        // Re-render the metadata block. Identical recipe to the initial
        // server render at page load (label sequence + render predicates).
        var metaHtml = '';
        metaHtml += field('Sample ID',                    sample.name || sample.id);
        metaHtml += field('Sample Owner',                 owner && owner.name);
        metaHtml += field('Last Updated',                 fmtDate(sample.modified_at));
        metaHtml += field('Material Type',                sample.display_sample_type);
        metaHtml += field('Sample Purpose',               sample.display_sample_purpose);
        if (sample.latitude !== null && sample.longitude !== null) {
            metaHtml += field('Current Sample Location', sample.latitude.toFixed(6) + ', ' + sample.longitude.toFixed(6));
        }
        metaHtml += field('IGSN',                         sample.igsn);
        metaHtml += field('Description',                  sample.description);
        metaHtml += field('Notes',                        sample.notes);
        document.getElementById('sd-metadata-fields').innerHTML = metaHtml || '<div style="opacity:.6">No metadata recorded.</div>';

        // Update page title (renders the same name-fallback as the metadata row).
        document.getElementById('sd-title').textContent = 'Sample: ' + (sample.name || sample.id);
    }

    $emForm.addEventListener('submit', function(e) {
        e.preventDefault();
        $emError.hidden = true;
        $emError.textContent = '';

        var name = $emName.value.trim();
        if (!name) {
            $emError.textContent = 'Sample ID is required.';
            $emError.hidden = false;
            $emName.focus();
            return;
        }

        // Build body. Send empty strings for text fields so the user can
        // clear them server-side (PUT semantics — present-with-value
        // replaces the column, absent means "don't touch").
        var body = {
            sample_id:    sample.id,
            owner_pkey:   owner.pkey,
            name:         name,
            igsn:         $emIgsn.value.trim(),
            description:  $emDesc.value.trim(),
            notes:        $emNotes.value.trim(),
            display_sample_type:    readVocabField($emType,    $emTypeOther),
            display_sample_purpose: readVocabField($emPurpose, $emPurposeOther),
        };

        // Lat/lng only included if NOT disabled — the disabled state means
        // the Field link owns geometry, and even sending them as null would
        // trigger the 409 guard server-side.
        if (!$emLat.disabled) {
            var latRaw = $emLat.value.trim();
            if (latRaw === '') {
                body.latitude = null;
            } else {
                var lat = Number(latRaw);
                if (!Number.isFinite(lat) || lat < -90 || lat > 90) {
                    $emError.textContent = 'Latitude must be a number between -90 and 90.';
                    $emError.hidden = false;
                    return;
                }
                body.latitude = lat;
            }
        }
        if (!$emLng.disabled) {
            var lngRaw = $emLng.value.trim();
            if (lngRaw === '') {
                body.longitude = null;
            } else {
                var lng = Number(lngRaw);
                if (!Number.isFinite(lng) || lng < -180 || lng > 180) {
                    $emError.textContent = 'Longitude must be a number between -180 and 180.';
                    $emError.hidden = false;
                    return;
                }
                body.longitude = lng;
            }
        }

        $emSubmit.disabled = true;
        $emSubmit.textContent = 'Saving…';

        fetch('/samples_edit.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
        }).then(function(r) {
            return r.json().then(
                function(j) { return {status: r.status, body: j}; },
                function()  { return {status: r.status, body: null}; }
            );
        }).then(function(res) {
            if (res.body && res.body.ok && res.body.sample) {
                applyEditedSample(res.body.sample);
                closeEditModal();
                return;
            }
            var code = res.body && res.body.error ? res.body.error : 'unknown';
            var msg;
            switch (code) {
                case 'not_found':
                    msg = 'Sample not found (may have been deleted). Reload the page.'; break;
                case 'forbidden':
                    msg = 'You do not have permission to edit this sample.'; break;
                case 'field_link_read_only':
                    msg = 'Latitude/Longitude are managed by StraboField for this sample.'; break;
                case 'no_writable_fields':
                    msg = 'Nothing to save.'; break;
                case 'not_authenticated':
                    msg = 'Your session has expired. Please reload the page and sign in again.'; break;
                case 'invalid_json':
                    msg = 'The server could not read the request. Please reload and try again.'; break;
                default:
                    msg = 'Could not save changes (' + code + ').';
            }
            $emError.textContent = msg;
            $emError.hidden = false;
            $emSubmit.disabled = false;
            $emSubmit.textContent = 'Save Changes';
        }).catch(function() {
            $emError.textContent = 'Network error — please try again.';
            $emError.hidden = false;
            $emSubmit.disabled = false;
            $emSubmit.textContent = 'Save Changes';
        });
    });
})();
</script>
<?php endif; ?>

<?php
include("includes/mfooter.php");
