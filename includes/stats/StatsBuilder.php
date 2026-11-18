<?php
/**
 * File: includes/stats/StatsBuilder.php
 * Description: Builds the admin statistics snapshot shown by /stats.
 *
 * Every number is computed here, once, and stored as one JSON row in
 * public.stats_snapshots (DDL sql/stats.sql). The page only reads the newest
 * row, so viewing /stats never runs whole-database queries.
 *
 * Counting rules (shown on the page under "How we count"):
 *  - Search-index figures use each spot's most recently indexed copy; a
 *    location counts only when it is a valid longitude/latitude.
 *  - Accounts: users.active (email confirmed) and not deleted. Bots sign up but
 *    never confirm, so unconfirmed sign-ups are reported separately.
 *  - Field records (projects, datasets, spots, images, samples, tags): DISTINCT
 *    ids in Neo4j. The same spot can exist as several nodes (copies under
 *    collaboration, copied projects), so node counts overstate the holdings.
 *  - Measurements, rock types, map, strike/dip: the StraboSearch index
 *    (strabosearch.item_hit), one row per distinct spot id. Its coverage of the
 *    Neo4j spots is reported as indexCoverage.
 *  - Creation dates: StraboSpot ids are a millisecond timestamp plus one digit,
 *    so id / 10 is the creation time. Ids outside 2014..2027 are left out of the
 *    time series (reported as undatedSpots).
 *  - Test and internal accounts are INCLUDED (Jason, 2026-11-18).
 *  - Neo4j 3.0.7: no newer Cypher; never group by id over all spots (heap).
 *
 * @package    StraboSpot Web Site
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

class StatsBuilder
{
	const ID_MIN = 14000000000000; // 2014-05
	const ID_MAX = 18200000000000; // 2027-09
	const MS_PER_DAY = 86400000;

	private $db;
	private $neodb;
	private $pg;
	private $errors = array();
	private $timings = array();
	private $out = array();

	public function __construct($db, $neodb)
	{
		$this->db = $db;
		$this->neodb = $neodb;
		$db->get_var("SELECT 1"); // make sure the wrapper has connected
		$this->pg = $db->dbh;
	}

	/** Build the whole snapshot. Sections fail independently. */
	public function build()
	{
		$t0 = microtime(true);
		$this->out = array(
			'version' => 1,
			'builtAt' => gmdate('c'),
			'builtAtMs' => (int) round(microtime(true) * 1000),
		);
		$sections = array(
			'accounts', 'field', 'fieldSeries', 'contributors', 'searchIndex',
			'micro', 'experimental', 'samples', 'platform',
		);
		foreach ($sections as $s) {
			$ts = microtime(true);
			try {
				$this->{'section' . ucfirst($s)}();
			} catch (Throwable $e) {
				$this->errors[] = array('section' => $s, 'message' => substr($e->getMessage(), 0, 400));
			}
			$this->timings[$s] = round(microtime(true) - $ts, 2);
		}
		$this->derived();
		$this->out['errors'] = $this->errors;
		$this->out['timings'] = $this->timings;
		$this->out['buildSeconds'] = round(microtime(true) - $t0, 1);
		return $this->out;
	}

	// ------------------------------------------------------------------
	// helpers
	// ------------------------------------------------------------------

	private function pgAll($sql, $params = array())
	{
		$r = $params ? @pg_query_params($this->pg, $sql, $params) : @pg_query($this->pg, $sql);
		if ($r === false) {
			throw new RuntimeException('PostgreSQL: ' . pg_last_error($this->pg));
		}
		$rows = pg_fetch_all($r);
		return $rows ? $rows : array();
	}

	private function pgOne($sql, $params = array())
	{
		$rows = $this->pgAll($sql, $params);
		return $rows ? $rows[0] : array();
	}

	private function pgVal($sql, $params = array())
	{
		$row = $this->pgOne($sql, $params);
		return $row ? reset($row) : null;
	}

	/** Rows of a Cypher query as associative arrays. */
	private function neo($cypher)
	{
		$out = array();
		foreach ($this->neodb->query($cypher) as $rec) {
			$row = array();
			foreach ($rec->keys() as $k) {
				$row[$k] = $rec->value($k);
			}
			$out[] = $row;
		}
		return $out;
	}

	private function neoVal($cypher)
	{
		$rows = $this->neo($cypher);
		return $rows ? (int) reset($rows[0]) : 0;
	}

	private static function dayKey($dayNumber)
	{
		return gmdate('Y-m-d', (int) $dayNumber * 86400);
	}

	/** {day-number: n} from Cypher rows (d, n) into {'YYYY-MM-DD': n}. */
	private static function dayMap($rows)
	{
		$m = array();
		foreach ($rows as $r) {
			$m[self::dayKey($r['d'])] = (int) $r['n'];
		}
		ksort($m);
		return $m;
	}

	// ------------------------------------------------------------------
	// sections
	// ------------------------------------------------------------------

	private function sectionAccounts()
	{
		$row = $this->pgOne("
			SELECT count(*) AS signups,
			       count(*) FILTER (WHERE active AND deleted IS NOT TRUE) AS confirmed,
			       count(*) FILTER (WHERE NOT active AND deleted IS NOT TRUE) AS unconfirmed,
			       count(*) FILTER (WHERE deleted IS TRUE) AS deleted
			FROM users");
		$daily = array();
		foreach ($this->pgAll("
			SELECT to_char(signup_date AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS d, count(*) AS n
			FROM users
			WHERE active AND deleted IS NOT TRUE AND signup_date IS NOT NULL
			GROUP BY 1 ORDER BY 1") as $r) {
			$daily[$r['d']] = (int) $r['n'];
		}
		$withoutDate = (int) $this->pgVal("SELECT count(*) FROM users WHERE active AND deleted IS NOT TRUE AND signup_date IS NULL");
		$this->out['accounts'] = array(
			'confirmed' => (int) $row['confirmed'],
			'unconfirmed' => (int) $row['unconfirmed'],
			'deleted' => (int) $row['deleted'],
			'allSignups' => (int) $row['signups'],
			'confirmedWithoutSignupDate' => $withoutDate,
			'dailyConfirmedSignups' => $daily,
		);
	}

	private function sectionField()
	{
		$f = array();
		$f['spots'] = $this->neoVal("MATCH (s:Spot) RETURN count(DISTINCT s.id)");
		$f['spotNodes'] = $this->neoVal("MATCH (s:Spot) RETURN count(s)");
		$f['projects'] = $this->neoVal("MATCH (p:Project) RETURN count(DISTINCT p.id)");
		$f['projectNodes'] = $this->neoVal("MATCH (p:Project) RETURN count(p)");
		$f['datasets'] = $this->neoVal("MATCH (d:Dataset) RETURN count(DISTINCT d.id)");
		$f['images'] = $this->neoVal("MATCH (i:Image) RETURN count(DISTINCT i.id)");
		$f['imageNodes'] = $this->neoVal("MATCH (i:Image) RETURN count(i)");
		$f['samples'] = $this->neoVal("MATCH (x:Sample) RETURN count(DISTINCT x.id)");
		$f['tags'] = $this->neoVal("MATCH (t:Tag) RETURN count(DISTINCT t.id)");
		$f['publicProjects'] = $this->neoVal("MATCH (p:Project) WHERE toString(p.public) IN ['true','1'] RETURN count(DISTINCT p.id)");

		$row = $this->neo("MATCH (p:Project)-[:HAS_DATASET]->(:Dataset)-[:HAS_SPOT]->(:Spot)
			RETURN count(DISTINCT p.id) AS projects, count(DISTINCT p.userpkey) AS users");
		$f['projectsWithSpots'] = (int) $row[0]['projects'];
		$f['usersWithSpots'] = (int) $row[0]['users'];

		$geom = array();
		foreach ($this->neo("MATCH (s:Spot) RETURN s.geometrytype AS g, count(DISTINCT s.id) AS n") as $r) {
			$g = $r['g'];
			if ($g === null || $g === '') {
				$g = 'None';
			}
			$geom[$g] = (isset($geom[$g]) ? $geom[$g] : 0) + (int) $r['n'];
		}
		arsort($geom);
		$f['geometryTypes'] = $geom;
		$this->out['field'] = $f;
	}

	private function sectionFieldSeries()
	{
		$min = self::ID_MIN;
		// nothing created after this build (some device clocks run ahead)
		$max = min(self::ID_MAX, ((int) round(microtime(true) * 1000) + self::MS_PER_DAY) * 10);
		$per = self::MS_PER_DAY * 10; // ids carry one extra digit
		$s = array();
		$s['spotsDaily'] = self::dayMap($this->neo("MATCH (s:Spot) WHERE s.id >= $min AND s.id < $max
			WITH s.id / $per AS d, s.id AS id RETURN d, count(DISTINCT id) AS n"));
		$s['imagesDaily'] = self::dayMap($this->neo("MATCH (i:Image) WHERE i.id >= $min AND i.id < $max
			WITH i.id / $per AS d, i.id AS id RETURN d, count(DISTINCT id) AS n"));
		$s['projectsDaily'] = self::dayMap($this->neo("MATCH (p:Project) WHERE p.id >= $min AND p.id < $max
			WITH p.id / $per AS d, p.id AS id RETURN d, count(DISTINCT id) AS n"));
		$s['undatedSpots'] = $this->neoVal("MATCH (s:Spot) WHERE NOT (s.id >= $min AND s.id < $max) RETURN count(DISTINCT s.id)");

		// People adding spots recently (ids are creation timestamps)
		$nowMs = (int) round(microtime(true) * 1000);
		foreach (array(1, 7, 30, 365) as $days) {
			$cut = ($nowMs - $days * self::MS_PER_DAY) * 10;
			$s['activeUsers'][$days] = $this->neoVal("MATCH (s:Spot) WHERE s.id >= $cut AND s.id < $max RETURN count(DISTINCT s.userpkey)");
		}
		$s['datedThrough'] = gmdate('Y-m-d');
		$this->out['fieldSeries'] = $s;
	}

	private function sectionContributors()
	{
		$rows = $this->neo("MATCH (s:Spot) RETURN s.userpkey AS u, count(DISTINCT s.id) AS n");
		$bins = array('1-9' => 0, '10-99' => 0, '100-999' => 0, '1k-9.9k' => 0, '10k+' => 0);
		$per = array();
		foreach ($rows as $r) {
			$n = (int) $r['n'];
			if ($r['u'] === null || $n < 1) {
				continue;
			}
			$per[(int) $r['u']] = $n;
			if ($n < 10) $bins['1-9']++;
			elseif ($n < 100) $bins['10-99']++;
			elseif ($n < 1000) $bins['100-999']++;
			elseif ($n < 10000) $bins['1k-9.9k']++;
			else $bins['10k+']++;
		}
		arsort($per);
		$top = array_slice($per, 0, 12, true);
		$names = $this->userNames(array_keys($top));
		$list = array();
		foreach ($top as $u => $n) {
			$list[] = array('userpkey' => $u, 'name' => isset($names[$u]) ? $names[$u] : "User $u", 'spots' => $n);
		}
		$binList = array();
		foreach ($bins as $k => $n) {
			$binList[] = array($k, $n); // a list: jsonb reorders object keys
		}
		$this->out['contributors'] = array(
			'usersWithAnySpot' => count($per),
			'bins' => $binList,
			'topUsers' => $list,
		);
	}

	private function userNames($pkeys)
	{
		$pkeys = array_values(array_filter(array_map('intval', $pkeys)));
		if (!$pkeys) {
			return array();
		}
		$names = array();
		foreach ($this->pgAll("SELECT pkey, firstname, lastname FROM users WHERE pkey = ANY($1::int[])",
			array('{' . implode(',', $pkeys) . '}')) as $r) {
			$names[(int) $r['pkey']] = trim($r['firstname'] . ' ' . $r['lastname']);
		}
		return $names;
	}

	private function sectionSearchIndex()
	{
		// One row per distinct field spot id (the index has a row per node).
		$this->pgAll("DROP TABLE IF EXISTS pg_temp.stats_spot");
		$this->pgAll("CREATE TEMP TABLE stats_spot AS
			SELECT DISTINCT ON (item_id) item_id, item_userpkey, project_id, project_name, project_userpkey,
			       location, date_value, rock_types, orientation_strike, orientation_dip, orientation_trend,
			       orientation_plunge, orientation_planar, orientation_features, has_images, has_samples
			FROM strabosearch.item_hit
			WHERE project_subsystem = 'field' AND item_type = 'spot'
			ORDER BY item_id, last_synced DESC NULLS LAST, item_hit_pkey DESC");
		// A location only counts when it is a real longitude/latitude
		$this->pgAll("UPDATE stats_spot SET location = NULL WHERE location IS NOT NULL
			AND NOT (st_x(location) BETWEEN -180 AND 180 AND st_y(location) BETWEEN -90 AND 90)");

		$x = array();
		$row = $this->pgOne("SELECT count(*) AS spots, count(location) AS located,
			sum(coalesce(array_length(orientation_planar, 1), 0)) AS orientations,
			sum(coalesce(array_length(orientation_strike, 1), 0)) AS planar,
			sum(coalesce(array_length(orientation_trend, 1), 0)) AS linear
			FROM stats_spot");
		$x['spots'] = (int) $row['spots'];
		$x['located'] = (int) $row['located'];
		$x['orientations'] = (int) $row['orientations'];
		$x['planarWithStrike'] = (int) $row['planar'];
		$x['linearWithTrend'] = (int) $row['linear'];
		$x['lastSynced'] = $this->pgVal("SELECT greatest(max(last_incremental_sync), max(last_full_backfill)) FROM strabosearch.sync_state WHERE source = 'field'");

		// Map: half-degree cells, cell centre + count
		$cells = array();
		foreach ($this->pgAll("SELECT floor(st_x(location) * 2) AS gx, floor(st_y(location) * 2) AS gy, count(*) AS n
			FROM stats_spot WHERE location IS NOT NULL
			GROUP BY 1, 2") as $r) {
			$cells[] = array(round(((float) $r['gx'] + 0.5) / 2, 2), round(((float) $r['gy'] + 0.5) / 2, 2), (int) $r['n']);
		}
		$x['mapCells'] = $cells;
		$hemi = $this->pgOne("SELECT count(*) FILTER (WHERE st_y(location) >= 0) AS north,
			count(*) FILTER (WHERE st_y(location) < 0) AS south FROM stats_spot WHERE location IS NOT NULL");
		$x['hemisphere'] = array('north' => (int) $hemi['north'], 'south' => (int) $hemi['south']);

		// Orientation roses and dips (10 degree bins)
		$x['strikeBins'] = $this->bins("SELECT floor(((v::numeric % 360) + 360) % 360 / 10)::int AS b, count(*) AS n
			FROM stats_spot, unnest(orientation_strike) v WHERE v IS NOT NULL GROUP BY 1", 36);
		$x['dipBins'] = $this->bins("SELECT least(floor(v / 10)::int, 8) AS b, count(*) AS n
			FROM stats_spot, unnest(orientation_dip) v WHERE v BETWEEN 0 AND 90 GROUP BY 1", 9);
		$x['trendBins'] = $this->bins("SELECT floor(((v::numeric % 360) + 360) % 360 / 10)::int AS b, count(*) AS n
			FROM stats_spot, unnest(orientation_trend) v WHERE v IS NOT NULL GROUP BY 1", 36);
		$x['plungeBins'] = $this->bins("SELECT least(floor(v / 10)::int, 8) AS b, count(*) AS n
			FROM stats_spot, unnest(orientation_plunge) v WHERE v BETWEEN 0 AND 90 GROUP BY 1", 9);

		$x['features'] = $this->topList("SELECT f AS k, count(*) AS n FROM stats_spot, unnest(orientation_features) f
			WHERE f IS NOT NULL AND f <> '' GROUP BY 1 ORDER BY 2 DESC LIMIT 12");
		$x['rockTypes'] = $this->topList("SELECT r AS k, count(*) AS n FROM stats_spot, unnest(rock_types) r
			WHERE r IS NOT NULL AND r <> '' GROUP BY 1 ORDER BY 2 DESC LIMIT 14");
		$x['rockClasses'] = $this->topList("SELECT split_part(r, ':', 1) AS k, count(DISTINCT item_id) AS n
			FROM stats_spot, unnest(rock_types) r WHERE r IS NOT NULL AND r <> '' GROUP BY 1 ORDER BY 2 DESC LIMIT 8");

		// Field season: spot field date by month of year and hemisphere
		$season = array('north' => array_fill(0, 12, 0), 'south' => array_fill(0, 12, 0));
		foreach ($this->pgAll("SELECT extract(month FROM date_value)::int AS m,
			CASE WHEN st_y(location) < 0 THEN 'south' ELSE 'north' END AS h, count(*) AS n
			FROM stats_spot WHERE date_value BETWEEN '2000-01-01' AND now() AND location IS NOT NULL
			GROUP BY 1, 2") as $r) {
			$season[$r['h']][(int) $r['m'] - 1] = (int) $r['n'];
		}
		$x['seasonByMonth'] = $season;

		// Biggest projects by spots. From the full index, not stats_spot: a copied
		// project keeps its spot ids, so one spot can belong to two projects.
		$top = array();
		foreach ($this->pgAll("SELECT project_id, max(project_name) AS name, max(project_userpkey) AS owner,
			count(DISTINCT item_id) AS n
			FROM strabosearch.item_hit
			WHERE project_subsystem = 'field' AND item_type = 'spot' AND project_id IS NOT NULL
			GROUP BY project_id ORDER BY n DESC LIMIT 12") as $r) {
			$top[] = array('projectId' => $r['project_id'], 'name' => $r['name'], 'owner' => (int) $r['owner'], 'spots' => (int) $r['n']);
		}
		$names = $this->userNames(array_map(function ($p) { return $p['owner']; }, $top));
		foreach ($top as &$p) {
			$p['ownerName'] = isset($names[$p['owner']]) ? $names[$p['owner']] : 'User ' . $p['owner'];
		}
		unset($p);
		$x['topProjects'] = $top;

		$this->pgAll("DROP TABLE IF EXISTS pg_temp.stats_spot");
		$this->out['searchIndex'] = $x;
	}

	private function bins($sql, $size)
	{
		$b = array_fill(0, $size, 0);
		foreach ($this->pgAll($sql) as $r) {
			$i = (int) $r['b'];
			if ($i >= 0 && $i < $size) {
				$b[$i] += (int) $r['n'];
			}
		}
		return $b;
	}

	private function topList($sql)
	{
		$out = array();
		foreach ($this->pgAll($sql) as $r) {
			$out[] = array($r['k'], (int) $r['n']);
		}
		return $out;
	}

	private function sectionMicro()
	{
		require_once(__DIR__ . '/../../microdb/lib/sync_guard.php');
		$vis = micro_sync_visible_sql();
		$row = $this->pgOne("SELECT count(*) AS projects, count(DISTINCT userpkey) AS owners,
			count(*) FILTER (WHERE ispublic) AS public FROM strabomicro.micro_projectmetadata WHERE $vis");
		$m = array(
			'projects' => (int) $row['projects'],
			'owners' => (int) $row['owners'],
			'publicProjects' => (int) $row['public'],
		);
		$row = $this->pgOne("SELECT
			count(DISTINCT item_id) FILTER (WHERE item_type = 'micrograph') AS micrographs,
			count(DISTINCT item_id) FILTER (WHERE item_type = 'micrograph' AND location IS NOT NULL) AS located,
			count(DISTINCT item_id) FILTER (WHERE item_type = 'sample') AS samples
			FROM strabosearch.item_hit WHERE project_subsystem = 'micro'");
		$m['micrographs'] = (int) $row['micrographs'];
		$m['micrographsLocated'] = (int) $row['located'];
		$m['samples'] = (int) $row['samples'];
		$m['minerals'] = $this->topList("SELECT mn AS k, count(DISTINCT item_id) AS n
			FROM strabosearch.item_hit, unnest(minerals) mn
			WHERE project_subsystem = 'micro' AND item_type = 'micrograph' AND mn IS NOT NULL AND mn <> ''
			GROUP BY 1 ORDER BY 2 DESC LIMIT 10");
		$m['instruments'] = $this->topList("SELECT instrument_type AS k, count(DISTINCT item_id) AS n
			FROM strabosearch.item_hit
			WHERE project_subsystem = 'micro' AND item_type = 'micrograph' AND instrument_type IS NOT NULL AND instrument_type <> ''
			GROUP BY 1 ORDER BY 2 DESC LIMIT 8");
		$daily = array();
		foreach ($this->pgAll("SELECT to_char(uploaddate AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS d, count(*) AS n
			FROM strabomicro.micro_projectmetadata WHERE $vis AND uploaddate IS NOT NULL GROUP BY 1 ORDER BY 1") as $r) {
			$daily[$r['d']] = (int) $r['n'];
		}
		$m['projectsByLastUploadDay'] = $daily;
		$m['uploaders30d'] = (int) $this->pgVal("SELECT count(DISTINCT userpkey) FROM up_down_stats
			WHERE data_type = 'strabomicro' AND upload_download = 'upload' AND hit_date > now() - interval '30 days'");
		$this->out['micro'] = $m;
	}

	private function sectionExperimental()
	{
		$e = array();
		$e['projects'] = (int) $this->pgVal("SELECT count(*) FROM straboexp.project");
		$e['experiments'] = (int) $this->pgVal("SELECT count(DISTINCT coalesce(id, pkey::text)) FROM straboexp.experiment");
		$e['owners'] = (int) $this->pgVal("SELECT count(DISTINCT userpkey) FROM straboexp.experiment");
		$e['publicProjects'] = (int) $this->pgVal("SELECT count(*) FROM straboexp.project WHERE ispublic");
		$e['facilities'] = (int) $this->pgVal("SELECT count(*) FROM apprepo.facility");
		$e['apparatus'] = (int) $this->pgVal("SELECT count(*) FROM apprepo.apparatus");
		$daily = array();
		foreach ($this->pgAll("SELECT to_char(created_timestamp AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS d, count(*) AS n
			FROM straboexp.experiment WHERE created_timestamp IS NOT NULL GROUP BY 1 ORDER BY 1") as $r) {
			$daily[$r['d']] = (int) $r['n'];
		}
		$e['experimentsDaily'] = $daily;
		$e['apparatusTypes'] = $this->topList("SELECT apparatus_type AS k, count(DISTINCT item_id) AS n
			FROM strabosearch.item_hit WHERE project_subsystem = 'exp' AND item_type = 'experiment'
			  AND apparatus_type IS NOT NULL AND apparatus_type <> '' GROUP BY 1 ORDER BY 2 DESC LIMIT 8");
		$this->out['experimental'] = $e;
	}

	private function sectionSamples()
	{
		$row = $this->pgOne("SELECT count(*) AS samples,
			count(DISTINCT igsn) FILTER (WHERE igsn IS NOT NULL AND igsn <> '') AS igsns,
			count(*) FILTER (WHERE field_data IS NOT NULL) AS field,
			count(*) FILTER (WHERE micro_data IS NOT NULL) AS micro,
			count(*) FILTER (WHERE experimental_data IS NOT NULL) AS exp,
			count(*) FILTER (WHERE latitude IS NOT NULL AND longitude IS NOT NULL) AS located,
			count(*) FILTER (WHERE parent_sample_id IS NOT NULL AND parent_sample_id <> '') AS children
			FROM strabosamples.samples");
		$cells = array();
		foreach ($this->pgAll("SELECT floor(longitude * 2) AS gx, floor(latitude * 2) AS gy, count(*) AS n
			FROM strabosamples.samples
			WHERE latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180
			GROUP BY 1, 2") as $r) {
			$cells[] = array(round(((float) $r['gx'] + 0.5) / 2, 2), round(((float) $r['gy'] + 0.5) / 2, 2), (int) $r['n']);
		}
		$this->out['samples'] = array(
			'samples' => (int) $row['samples'],
			'igsns' => (int) $row['igsns'],
			'withFieldData' => (int) $row['field'],
			'withMicroData' => (int) $row['micro'],
			'withExperimentalData' => (int) $row['exp'],
			'located' => (int) $row['located'],
			'subsamples' => (int) $row['children'],
			'mapCells' => $cells,
			'types' => $this->topList("SELECT display_sample_type AS k, count(*) AS n FROM strabosamples.samples
				WHERE display_sample_type IS NOT NULL AND display_sample_type <> '' GROUP BY 1 ORDER BY 2 DESC LIMIT 8"),
		);
	}

	private function sectionPlatform()
	{
		$p = array();
		$p['dois'] = (int) $this->pgVal("SELECT count(*) FROM dois");
		$p['versions'] = (int) $this->pgVal("SELECT count(*) FROM versions");
		$p['versionedProjects'] = (int) $this->pgVal("SELECT count(DISTINCT projectid) FROM versions");
		// completed downloads only (failed ones have an error text as status)
		$row = $this->pgOne("SELECT count(*) AS n, coalesce(sum(tilecount), 0) AS tiles FROM tile_downloads WHERE status = 'Zip File Ready.'");
		$p['offlineMapDownloads'] = (int) $row['n'];
		$p['offlineTiles'] = (int) $row['tiles'];
		$p['fieldCamps'] = (int) $this->pgVal("SELECT count(*) FROM fieldcamp WHERE deleted_date IS NULL");
		$p['fieldCampAccounts'] = (int) $this->pgVal("SELECT count(*) FROM fieldcamp_account a JOIN fieldcamp c ON c.pkey = a.fieldcamp_pkey WHERE c.deleted_date IS NULL AND a.removed_date IS NULL");

		// Sync traffic, monthly (up_down_stats starts 2024-04)
		$traffic = array();
		foreach ($this->pgAll("SELECT to_char(hit_date AT TIME ZONE 'UTC', 'YYYY-MM') AS m, upload_download AS dir, data_type AS t,
			count(*) AS events, coalesce(sum(count), 0) AS items
			FROM up_down_stats GROUP BY 1, 2, 3 ORDER BY 1") as $r) {
			$traffic[] = array($r['m'], $r['dir'], $r['t'], (int) $r['events'], (int) $r['items']);
		}
		$p['syncTraffic'] = $traffic;
		$p['uploadUsers30d'] = (int) $this->pgVal("SELECT count(DISTINCT userpkey) FROM up_down_stats
			WHERE upload_download = 'upload' AND hit_date > now() - interval '30 days'");
		$p['syncUsers30d'] = (int) $this->pgVal("SELECT count(DISTINCT userpkey) FROM up_down_stats
			WHERE hit_date > now() - interval '30 days'");

		// Over-the-air updates (tables exist since 2026-11-18)
		$p['otaReleases'] = (int) $this->pgVal("SELECT count(*) FROM ota_releases");
		$p['otaPhones7d'] = (int) $this->pgVal("SELECT count(DISTINCT client_hash) FROM ota_daily_clients WHERE day > current_date - 7");
		$this->out['platform'] = $p;
	}

	/** Figures computed from the sections above. */
	private function derived()
	{
		$o = &$this->out;
		if (isset($o['field']['spots'], $o['searchIndex']['spots']) && $o['field']['spots'] > 0) {
			$o['indexCoverage'] = round($o['searchIndex']['spots'] / $o['field']['spots'], 4);
		}
		// Recent windows from the daily series
		$today = gmdate('Y-m-d');
		$recent = array();
		$series = array(
			'spots' => isset($o['fieldSeries']['spotsDaily']) ? $o['fieldSeries']['spotsDaily'] : null,
			'images' => isset($o['fieldSeries']['imagesDaily']) ? $o['fieldSeries']['imagesDaily'] : null,
			'projects' => isset($o['fieldSeries']['projectsDaily']) ? $o['fieldSeries']['projectsDaily'] : null,
			'accounts' => isset($o['accounts']['dailyConfirmedSignups']) ? $o['accounts']['dailyConfirmedSignups'] : null,
			'experiments' => isset($o['experimental']['experimentsDaily']) ? $o['experimental']['experimentsDaily'] : null,
		);
		foreach ($series as $k => $daily) {
			if ($daily === null) {
				continue;
			}
			foreach (array(1, 7, 30, 365) as $days) {
				$from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
				$sum = 0;
				foreach ($daily as $d => $n) {
					if ($d >= $from && $d <= $today) {
						$sum += $n;
					}
				}
				$recent[$k][$days] = $sum;
			}
		}
		$o['recent'] = $recent;
	}
}
