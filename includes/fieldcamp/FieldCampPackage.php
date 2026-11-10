<?php
/**
 * File: includes/fieldcamp/FieldCampPackage.php
 * Description: Field Camp Wizard, stage 4: the custom maps of a camp and the
 *              project package students load in StraboField.
 *
 *              The package is a zip in the layout of the app's own
 *              "Backup, Save & Zip" (checked against a backup made by
 *              StraboField 2.32.0 on an iPad, 11-2026), files at the root:
 *                data.json        mapNamesDb, mapTilesDb, otherMapsDb,
 *                                 projectDb (project + its Default dataset),
 *                                 spotsDb
 *                other_maps.json  the custom maps again, as the app writes them
 *                images/          empty
 *                maps/OfflineTiles.zip  the offline map tiles, once built
 *                                 (FieldCampTiles); mapNamesDb lists the maps
 *              The app's "Import Project From Zip" makes a folder named after
 *              the zip and unzips into it, so the zip's name is the name the
 *              project is saved under on the device.
 *
 *              The project holds the camp's project name, one empty dataset
 *              "Default", the selected rock units as geologic unit tags
 *              (FieldCampUnits) and the selected My Maps as custom maps. No
 *              spots. Without built tiles the package has no maps folder and
 *              an empty mapNamesDb.
 *
 *              Shared-package camps (delivery "shared"): one package for
 *              every student, so the project id and dataset id are the
 *              camp's, given once and kept.
 *
 *              Tables: sql/fieldcamp.sql. Design: docs/Field_Camp_Builder/DESIGN.md.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/FieldCampUnits.php';
require_once __DIR__ . '/FieldCampTiles.php';

class FieldCampPackage
{
	const MAX_TITLE = 100;

	/** Where the app fetches My Maps tiles (its STRABO_APIS.MY_MAPS_TILES). */
	const MY_MAPS_TILES = 'https://strabospot.org/geotiff/tiles/';

	/** The app's defaults for a new project (project.constants.js). */
	const OTHER_FEATURES = array('geomorphic', 'hydrologic', 'paleontological', 'igneous', 'metamorphic',
		'sedimentological', 'other');
	const RELATIONSHIP_TYPES = array('cross-cuts', 'mutually cross-cuts', 'is cut by', 'is younger than',
		'is older than', 'is lower metamorphic grade than', 'is higher metamorphic grade than',
		'is included within', 'includes', 'merges with');

	private static $tablesChecked = null;

	/** True once the stage 4 part of sql/fieldcamp.sql has been applied. */
	public static function installed($db)
	{
		if (self::$tablesChecked === null) {
			$found = $db->get_var_prepared("select to_regclass('public.fieldcamp_map') is not null", array());
			self::$tablesChecked = ($found === true || $found === 't') && FieldCampUnits::installed($db);
		}
		return self::$tablesChecked;
	}

	// -------------------------------------------------------------------
	// Custom maps (the owner's My Maps)
	// -------------------------------------------------------------------

	/**
	 * The extent of a My Maps entry from the gdalinfo text saved at upload:
	 * the four corners' longitude and latitude (gdalinfo prints them in
	 * degrees, minutes, seconds beside the corner coordinates, whatever the
	 * map's projection).
	 *
	 * @return array|null west/south/east/north, or null when the text has no corners
	 */
	public static function extentFromGdalinfo($text)
	{
		$lons = array(); $lats = array();
		$re = '/^(?:Upper|Lower) (?:Left|Right)\s*\([^)]*\)\s*\(\s*(\d+)d\s*(\d+)\'+\s*([\d.]+)"([EW]),\s*(\d+)d\s*(\d+)\'+\s*([\d.]+)"([NS])\s*\)/m';
		if (!preg_match_all($re, (string)$text, $all, PREG_SET_ORDER)) return null;
		foreach ($all as $m) {
			$lon = (int)$m[1] + (int)$m[2] / 60 + (float)$m[3] / 3600;
			$lat = (int)$m[5] + (int)$m[6] / 60 + (float)$m[7] / 3600;
			$lons[] = $m[4] === 'W' ? -$lon : $lon;
			$lats[] = $m[8] === 'S' ? -$lat : $lat;
		}
		if (count($lons) < 4) return null;
		return array('west' => min($lons), 'south' => min($lats), 'east' => max($lons), 'north' => max($lats));
	}

	/**
	 * The bbox string a custom map carries in the app: what /geotiff/bbox/{id}
	 * answers (gdalinfo's WGS84 extent of the file), since that is where the
	 * app gets it. Falls back to the extent saved at upload when the file
	 * cannot be read here.
	 *
	 * @return string "west,south,east,north" or ''
	 */
	public static function bboxString($hash, $gdalinfoText)
	{
		$hash = preg_replace('/[^a-zA-Z0-9]/', '', (string)$hash);
		$file = __DIR__ . '/../../geotiff/upload/files/' . $hash . '.tif';
		if ($hash !== '' && is_file($file)) {
			$lines = array();
			exec('/usr/bin/gdalinfo -json ' . escapeshellarg($file) . ' 2>/dev/null', $lines);
			$info = json_decode(implode("\n", $lines));
			$c = $info->wgs84Extent->coordinates[0] ?? null;
			if (is_array($c) && count($c) >= 3) {
				return $c[0][0] . ',' . $c[1][1] . ',' . $c[2][0] . ',' . $c[0][1];
			}
		}
		$e = self::extentFromGdalinfo($gdalinfoText);
		if ($e === null) return '';
		return round($e['west'], 7) . ',' . round($e['south'], 7) . ',' . round($e['east'], 7) . ',' . round($e['north'], 7);
	}

	/**
	 * The maps the Maps step offers, newest first: every My Maps entry of the
	 * camp's owner, then the PUBLIC maps of other accounts (the ones on the
	 * site's Public Maps page) that overlap the study area or are already
	 * selected. Each comes with its extent, whether it overlaps the study
	 * area and whether it is selected for the camp.
	 *
	 * @return array of ['hash', 'name', 'uploaded', 'extent' (array|null), 'overlaps' (bool|null), 'chosen', 'title', 'public' (bool)]
	 */
	public static function listMyMaps($db, $camp)
	{
		$chosen = array();
		foreach (self::listCampMaps($db, $camp->pkey) as $m) $chosen[$m->geotiff_hash] = $m->title;

		$area = FieldCampUnits::campBbox($camp);
		$owner = (int)$camp->owner_user_pkey;
		$rows = $db->get_results_prepared("
			select hash, name, gdalinfo, uploaddate, (userpkey = $1) as mine
			from geotiffs
			where userpkey = $1 or ispublic is true
			order by (userpkey = $1) desc, uploaddate desc, pkey desc
			", array($owner));
		$out = array();
		foreach (($rows ? $rows : array()) as $r) {
			$mine = ($r->mine === true || $r->mine === 't');
			$extent = self::extentFromGdalinfo($r->gdalinfo);
			$overlaps = null;
			if ($extent !== null && $area !== null) {
				$overlaps = $extent['west'] < $area['east'] && $extent['east'] > $area['west']
					&& $extent['south'] < $area['north'] && $extent['north'] > $area['south'];
			}
			// Other people's public maps are only of interest where the camp is.
			if (!$mine && $overlaps !== true && !isset($chosen[$r->hash])) continue;
			$out[] = array(
				'hash' => $r->hash,
				'name' => (string)$r->name,
				'uploaded' => substr((string)$r->uploaddate, 0, 10),
				'extent' => $extent,
				'overlaps' => $overlaps,
				'chosen' => isset($chosen[$r->hash]),
				'title' => $chosen[$r->hash] ?? self::titleFromFileName($r->name),
				'public' => !$mine,
			);
		}
		return $out;
	}

	/** The maps selected for a camp (rows of fieldcamp_map), oldest selection first. */
	public static function listCampMaps($db, $camppkey)
	{
		$rows = $db->get_results_prepared(
			"select * from fieldcamp_map where fieldcamp_pkey = $1 order by pkey", array((int)$camppkey));
		return $rows ? $rows : array();
	}

	/**
	 * Save the Maps step: the selected maps and their titles replace the
	 * camp's list. Only maps the step offers are accepted: the camp owner's
	 * own, and public maps over the study area. All or nothing.
	 *
	 * @param array $rows [['hash' => string, 'chosen' => bool, 'title' => string], ...]
	 * @return array list of problems (empty = saved)
	 */
	public static function saveMaps($db, $camp, $rows)
	{
		$mine = array();
		foreach (self::listMyMaps($db, $camp) as $m) $mine[$m['hash']] = $m;

		$errors = array(); $keep = array(); $titles = array();
		foreach ((array)$rows as $row) {
			if (!is_array($row) || empty($row['chosen'])) continue;
			$hash = (string)($row['hash'] ?? '');
			if (!isset($mine[$hash]) || isset($keep[$hash])) continue;   // not a map this camp may use
			$title = trim(preg_replace('/\s+/u', ' ', (string)($row['title'] ?? '')));
			if ($title === '') {
				$errors[] = "\"" . $mine[$hash]['name'] . "\" needs a title. It is the name students see in the app's map list.";
			} elseif (mb_strlen($title) > self::MAX_TITLE) {
				$errors[] = "The title of \"" . $mine[$hash]['name'] . "\" is too long (" . self::MAX_TITLE . " characters at most).";
			} elseif (isset($titles[mb_strtolower($title)])) {
				$errors[] = "Two selected maps are both titled \"$title\". Give each its own title.";
			}
			$titles[mb_strtolower($title)] = true;
			$keep[$hash] = $title;
		}
		if ($errors) return array_values(array_unique($errors));

		$db->query("BEGIN");
		$db->prepare_query("delete from fieldcamp_map where fieldcamp_pkey = $1", array((int)$camp->pkey));
		foreach ($keep as $hash => $title) {
			$db->prepare_query("insert into fieldcamp_map (fieldcamp_pkey, geotiff_hash, title) values ($1, $2, $3)",
				array((int)$camp->pkey, $hash, $title));
		}
		$db->prepare_query("update fieldcamp set modified_date = now() where pkey = $1", array((int)$camp->pkey));
		$db->query("COMMIT");
		return array();
	}

	/** "USGS_B-1221-D.tif" as "USGS B-1221-D": a starting title for a map. */
	public static function titleFromFileName($name)
	{
		$t = preg_replace('/\.(tiff?|zip)$/i', '', trim((string)$name));
		$t = trim(preg_replace('/[_\s]+/', ' ', $t));
		return mb_substr($t === '' ? 'Map' : $t, 0, self::MAX_TITLE);
	}

	/**
	 * A My Maps entry as the app's custom map object (the same object sits in
	 * project.other_maps, in otherMapsDb and in other_maps.json).
	 */
	public static function customMap($hash, $title, $bbox)
	{
		$map = array(
			'title' => (string)$title,
			'opacity' => 1,
			'overlay' => false,
			'id' => (string)$hash,
			'source' => 'strabospot_mymaps',
			'attributions' => '<a href="https://www.strabospot.org">StraboSpot Contributed</a>',
			'imageType' => 'png',
			'mime' => 'image/png',
			'tilePath' => '{z}/{x}/{y}.png',
			'url' => array(self::MY_MAPS_TILES),
			'maxZoom' => 25,
		);
		// The app looks the bbox up itself when a map has none.
		if ((string)$bbox !== '') $map['bbox'] = (string)$bbox;
		return $map;
	}

	/**
	 * The camp's selected maps as custom map objects. A map deleted from
	 * My Maps since, or a public map that was made private again, is left out.
	 */
	public static function customMapsForCamp($db, $camp)
	{
		$out = array();
		foreach (self::listCampMaps($db, $camp->pkey) as $m) {
			$g = $db->get_row_prepared("select hash, gdalinfo from geotiffs where hash = $1 and (userpkey = $2 or ispublic is true)",
				array($m->geotiff_hash, (int)$camp->owner_user_pkey));
			if (!$g) continue;
			$out[] = self::customMap($g->hash, $m->title, self::bboxString($g->hash, $g->gdalinfo));
		}
		return $out;
	}

	// -------------------------------------------------------------------
	// The package
	// -------------------------------------------------------------------

	/**
	 * The camp's project id and dataset id, given at the first call and kept.
	 * The app's id format (getNewId): milliseconds x 10, a 14 digit number.
	 *
	 * @return array [project id, dataset id]
	 */
	public static function ensureIds($db, $camp)
	{
		$row = $db->get_row_prepared("select project_id, dataset_id from fieldcamp where pkey = $1", array((int)$camp->pkey));
		if ($row && (int)$row->project_id > 0 && (int)$row->dataset_id > 0) {
			return array((int)$row->project_id, (int)$row->dataset_id);
		}
		$projectId = (int)floor(microtime(true) * 10000);
		$datasetId = $projectId + random_int(50, 500);
		// "where project_id is null": two first builds at once end up with one pair of ids.
		$db->prepare_query("update fieldcamp set project_id = $2, dataset_id = $3 where pkey = $1 and project_id is null",
			array((int)$camp->pkey, $projectId, $datasetId));
		$row = $db->get_row_prepared("select project_id, dataset_id from fieldcamp where pkey = $1", array((int)$camp->pkey));
		return array((int)$row->project_id, (int)$row->dataset_id);
	}

	/**
	 * Everything that goes into data.json, as PHP values ready for json_encode
	 * (objects the app expects as objects are objects here, also when empty).
	 */
	public static function buildData($db, $camp)
	{
		list($projectId, $datasetId) = self::ensureIds($db, $camp);
		$nowMs = (int)floor(microtime(true) * 1000);
		$created = strtotime((string)$camp->created_date);
		$createdIso = gmdate('Y-m-d\TH:i:s', $created ? $created : time()) . '.000Z';

		$tags = FieldCampUnits::tagsForCamp($db, (int)$camp->pkey);
		$maps = self::customMapsForCamp($db, $camp);

		$description = array(
			// Noon UTC: the same calendar day in every time zone a camp is likely to be in.
			'start_date' => ((string)$camp->start_date !== '' ? substr((string)$camp->start_date, 0, 10) . 'T12:00:00.000Z' : $createdIso),
			'gps_datum' => 'WGS84 (Default)',
			'project_name' => (string)$camp->project_name,
		);
		if ((string)$camp->end_date !== '') $description['end_date'] = substr((string)$camp->end_date, 0, 10) . 'T12:00:00.000Z';
		$notes = (string)$camp->camp_name . " " . (int)$camp->camp_year . ". Project made with the StraboSpot Field Camp Wizard.";
		if ($tags) $notes .= " Geologic unit tags from Macrostrat (macrostrat.org, CC-BY 4.0).";
		$description['notes'] = $notes;

		$project = array(
			'id' => $projectId,
			'description' => $description,
			'date' => $createdIso,
			'modified_timestamp' => $nowMs,
			'other_features' => self::OTHER_FEATURES,
			'relationship_types' => self::RELATIONSHIP_TYPES,
			'templates' => new stdClass(),
			'useContinuousTagging' => false,
			'tags' => $tags,
			'other_maps' => $maps,
			'preferences' => new stdClass(),
		);

		$datasets = new stdClass();
		$datasets->{(string)$datasetId} = array(
			'id' => $datasetId,
			'name' => 'Default',
			'date' => $createdIso,
			'modified_timestamp' => $nowMs,
			'spotIds' => array(),
			'images' => array('imageIds' => array()),
		);

		$otherMapsDb = new stdClass();
		foreach ($maps as $m) $otherMapsDb->{$m['id']} = $m;

		// projectDb is the app's whole project slice. Its import reads project and datasets only;
		// the other keys are written with the values a fresh project has, as the app's backup has them.
		$projectDb = array(
			'activeDatasetsIds' => array($datasetId),
			'addTagToSelectedSpot' => false,
			'backupFileName' => '',
			'changedImageIds' => array(),
			'datasets' => $datasets,
			'deviceBackUpDirectoryExists' => false,
			'downloadsDirectory' => false,
			'isImageTransferring' => false,
			'isMultipleFeaturesTaggingEnabled' => false,
			'isTestingMode' => false,
			'project' => $project,
			'projectTransferProgress' => 0,
			'readOnlyDatasetsIds' => array(),
			'sampleSpotIdsToUpload' => array(),
			'targetDatasetId' => $datasetId,
			'_persist' => array('version' => 1, 'rehydrated' => true),
		);

		// Offline maps: only what a finished tile build holds (FieldCampTiles).
		$manifest = FieldCampTiles::installed($db) ? FieldCampTiles::manifest($camp) : null;

		return array(
			'mapNamesDb' => FieldCampTiles::mapNamesDb($manifest, FieldCampTiles::layers($maps)),
			'mapTilesDb' => new stdClass(),
			'otherMapsDb' => $otherMapsDb,
			'projectDb' => $projectDb,
			'spotsDb' => new stdClass(),
		);
	}

	/**
	 * The zip's name without ".zip": the date, then the project name
	 * ("2026-11-10_KU_Field_Camp"), close to the name the app gives its own
	 * backups. The app saves the imported project under this name. No time of
	 * day: the server's clock is not the instructor's.
	 */
	public static function fileName($camp, $time = null)
	{
		$name = preg_replace('/[^A-Za-z0-9]+/', '_', (string)$camp->project_name);
		$name = trim($name, '_');
		if ($name === '') $name = 'Field_Camp';
		return date('Y-m-d', $time === null ? time() : $time) . '_' . substr($name, 0, 60);
	}

	/**
	 * Write the package zip.
	 *
	 * @return array ['error' => string, 'tags' => int, 'maps' => int, 'offline_maps' => int, 'bytes' => int]
	 */
	public static function buildZip($db, $camp, $path)
	{
		$out = array('error' => '', 'tags' => 0, 'maps' => 0, 'offline_maps' => 0, 'bytes' => 0);
		$data = self::buildData($db, $camp);
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$json = json_encode($data, $flags);
		$other = json_encode($data['otherMapsDb'], $flags);
		if ($json === false || $other === false) {
			error_log("FieldCampPackage::buildZip camp " . (int)$camp->pkey . ": " . json_last_error_msg());
			$out['error'] = "The package could not be built.";
			return $out;
		}

		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			error_log("FieldCampPackage::buildZip camp " . (int)$camp->pkey . ": cannot write $path");
			$out['error'] = "The package could not be built.";
			return $out;
		}
		$zip->addFromString('data.json', $json);
		$zip->addFromString('other_maps.json', $other);
		$zip->addEmptyDir('images');
		$offline = count(get_object_vars($data['mapNamesDb']));
		if ($offline > 0) {
			// The tile zip goes in as it is: its PNGs are compressed already.
			$zip->addFile(FieldCampTiles::tilesZipPath($camp), 'maps/OfflineTiles.zip');
			$zip->setCompressionName('maps/OfflineTiles.zip', ZipArchive::CM_STORE);
		}
		$out['offline_maps'] = $offline;
		if (!$zip->close()) {
			$out['error'] = "The package could not be built.";
			return $out;
		}

		$out['tags'] = count($data['projectDb']['project']['tags']);
		$out['maps'] = count($data['projectDb']['project']['other_maps']);
		$out['bytes'] = (int)filesize($path);
		return $out;
	}
}
