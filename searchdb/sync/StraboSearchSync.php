<?php
/**
 * StraboSearch §5.3 incremental live-sync — StraboSearchSync.
 *
 * Synchronous per-item index maintenance, called from the write paths of
 * all four subsystems (Field / Micro / Exp / Samples). The backfill
 * extractors bootstrap the index; this class keeps it current afterwards.
 *
 * Design contract (DESIGN_PROPOSAL.md §5.3.2 / §5.3.3 / §5.3.4 + resolved
 * questions Q1/Q2/Q6):
 *
 *   SYNCHRONOUS   No queue, no worker, no eventual-consistency window.
 *                 Per-item cost is one source re-extract + one upsert
 *                 (~10–50ms). Bulk uploads use the per-dataset batch path
 *                 (syncFieldDataset) with per-item touches suppressed.
 *
 *   NEVER-THROW   A sync failure must NEVER fail the user's write — the
 *                 source store is the source of truth; search staleness is
 *                 recoverable, user-write failure is user-facing (Q6).
 *                 Every public method catches Throwable, logs a
 *                 '[strabosearch-sync]' line via error_log, and returns
 *                 false. The nightly verify pass detects + heals divergence.
 *
 *   TRANSACTION-NEUTRAL   No BEGIN/COMMIT in here. Statements join the
 *                 caller's open transaction when one exists (e.g. the
 *                 samples tabular import wraps service calls in an outer
 *                 transaction — an inner COMMIT would break its
 *                 all-or-nothing guarantee) and autocommit otherwise.
 *
 *   ADVISORY LOCKS   Each touch serializes on
 *                 pg_advisory_lock(hashtext('strabosearch:<type>:<id>:<upk>'))
 *                 preventing TOCTOU duplicate rows when two parallel write
 *                 paths race on the same item — the StraboSamples Phase E
 *                 Bug #1 class. Session-level locks (not _xact) so behavior
 *                 is identical inside and outside caller transactions;
 *                 released in `finally`.
 *
 *   IDEMPOTENT UPSERT + STALE SWEEP   Touch = re-extract current rows,
 *                 INSERT ... ON CONFLICT (identity) DO UPDATE, then delete
 *                 rows for the SAME item whose last_synced predates the
 *                 touch (catches fan-out shrinkage: unlinked sample hosts,
 *                 spot moved out of a project, deleted images). Re-running
 *                 a touch on an unchanged item is a no-op.
 *
 *   ROW MAPPING   Shared with the backfill extractors via
 *                 searchdb/extractors/_row_builders.php — one
 *                 implementation of source-row → index-row, no drift.
 *
 *   ENABLE GATE   All methods no-op (return false) when the strabosearch
 *                 schema is absent (pre-cutover prod) or when the config
 *                 kill switch `STRABOSEARCH_SYNC_DISABLED` is defined true
 *                 (emergency lever; index heals via extractor re-run).
 *
 * Known accepted staleness (documented, heals on next full extract /
 * nightly verify): a FIELD project rename updates the denormalized
 * project_name (touchProjectMeta) but does NOT re-extract every spot's
 * searchtext_tsv — for multi-thousand-spot projects that inline rebuild
 * would blow the upload request budget. Micro (whole-project rebuild) and
 * Exp (touchExpProject) refresh their tsvectors on rename.
 *
 * @package StraboSearch sync
 */

require_once(__DIR__ . '/../extractors/_row_builders.php');

class StraboSearchSync {

	/** Cached enable-gate result (per request). */
	private static $enabled = null;

	/** Bulk-upload suppression for per-item Field touches (§5.3.4). */
	private static $fieldTouchesSuppressed = false;

	/** Per-request cache of PG project.ispublic for Field projects. */
	private static $fieldPubCache = array();

	// =======================================================================
	// Gate, locking, logging, shared SQL
	// =======================================================================

	private static function enabled($db) {
		if (defined('STRABOSEARCH_SYNC_DISABLED') && STRABOSEARCH_SYNC_DISABLED) return false;
		if (self::$enabled === null) {
			$reg = $db->get_var("SELECT to_regclass('strabosearch.item_hit')");
			self::$enabled = !empty($reg);
		}
		return self::$enabled;
	}

	private static function lock($db, $key) {
		$db->query("SELECT pg_advisory_lock(hashtext('strabosearch:" . pg_escape_string($key) . "'))");
	}

	private static function unlock($db, $key) {
		$db->query("SELECT pg_advisory_unlock(hashtext('strabosearch:" . pg_escape_string($key) . "'))");
	}

	/**
	 * §5.3.3: log the divergence, swallow the failure. Returns false so
	 * hooks can `return StraboSearchSync::fail(...)`-style chain if they
	 * care about the outcome (most don't).
	 */
	private static function fail($context, $e) {
		error_log('[strabosearch-sync] ' . $context . ' FAILED: ' . $e->getMessage());
		return false;
	}

	/**
	 * A failed Cypher run leaves the GraphAware Bolt connection dead — the
	 * NEXT query on it blocks forever. Since the never-throw contract keeps
	 * the request alive after a sync failure, every catch path that may
	 * have touched Neo4j must reset the shared connection before handing
	 * it back to the caller.
	 */
	private static function resetNeo($neodb) {
		try {
			if ($neodb && method_exists($neodb, 'reconnect')) $neodb->reconnect();
		} catch (\Throwable $e) {
			error_log('[strabosearch-sync] neo4j reconnect FAILED: ' . $e->getMessage());
		}
	}

	/** Statement-start timestamp used as the stale-sweep boundary. */
	private static function touchEpoch($db) {
		return (string)$db->get_var("SELECT now()");
	}

	/**
	 * Chunked idempotent upsert. $conflictCols is the table's identity;
	 * every other column takes EXCLUDED.*, and last_synced advances to
	 * now() so the stale sweep spares the row.
	 */
	private static function upsert($db, $table, $cols, $conflictCols, $tuples) {
		if (!$tuples) return 0;
		$updatable = array();
		foreach ($cols as $c) {
			if (!in_array($c, $conflictCols, true)) $updatable[] = "$c = EXCLUDED.$c";
		}
		$updatable[] = 'last_synced = now()';
		$n = 0;
		foreach (array_chunk($tuples, 500) as $chunk) {
			$sql = "INSERT INTO $table (" . implode(', ', $cols) . ")\nVALUES "
				. implode(",\n", $chunk)
				. "\nON CONFLICT (" . implode(', ', $conflictCols) . ") DO UPDATE SET "
				. implode(', ', $updatable);
			if ($db->query($sql) === false) {
				throw new \RuntimeException("upsert into $table failed: " . $db->last_error);
			}
			$n += count($chunk);
		}
		return $n;
	}

	private static function upsertItems($db, $cols, $tuples) {
		return self::upsert($db, 'strabosearch.item_hit', $cols,
			array('item_type', 'item_id', 'item_userpkey', 'project_id', 'project_userpkey', 'project_subsystem'),
			$tuples);
	}

	private static function upsertImages($db, $cols, $tuples) {
		return self::upsert($db, 'strabosearch.image_hit', $cols,
			array('image_subsystem', 'image_id', 'image_userpkey'),
			$tuples);
	}

	private static function markSync($db, $source, $rows) {
		$db->query("
			INSERT INTO strabosearch.sync_state (source, last_incremental_sync, last_sync_rows_added)
			VALUES ('" . pg_escape_string($source) . "', now(), " . (int)$rows . ")
			ON CONFLICT (source) DO UPDATE SET
				last_incremental_sync = now(),
				last_sync_rows_added  = " . (int)$rows);
	}

	/**
	 * Field project.ispublic lives in the PG `project` table (Neo4j
	 * Project.ispublic is NULL everywhere). Unknown projects default to
	 * private — same rule as the backfill extractor. Keyed by
	 * (strabo_project_id, user_pkey): Strabo project ids are NOT unique
	 * across users, and an id-only lookup can return another user's flag
	 * (found by the 2026-08-01 ACL denorm audit).
	 */
	private static function fieldProjectIsPublic($db, $pid, $upk) {
		$k = (string)$pid . '|' . (int)$upk;
		if (!array_key_exists($k, self::$fieldPubCache)) {
			$v = $db->get_var("SELECT ispublic FROM project WHERE strabo_project_id = '"
				. pg_escape_string((string)$pid) . "' AND user_pkey = " . (int)$upk);
			self::$fieldPubCache[$k] = ($v === 't' || $v === true);
		}
		return self::$fieldPubCache[$k];
	}

	// =======================================================================
	// FIELD — spots + images
	// =======================================================================

	/**
	 * Bulk-upload suppression (§5.3.4): while suppressed, touchSpot /
	 * touchImage no-op (return true) — the controller calls
	 * syncFieldDataset once at end-of-dataset instead. remove* methods are
	 * NOT suppressed (bulk flows delete server-only spots mid-loop and
	 * those index rows must go regardless).
	 */
	public static function suppressFieldItemTouches() { self::$fieldTouchesSuppressed = true; }
	public static function resumeFieldItemTouches()   { self::$fieldTouchesSuppressed = false; }

	/**
	 * Re-extract one spot's item row(s) + its images' image rows from the
	 * current Neo4j state, then re-evaluate the fan-out of samples linked
	 * to this spot. Rows fan out per (project, dataset) path that reaches
	 * the spot; identical 6-tuples are deduped pre-insert.
	 */
	public static function touchSpot($db, $neodb, $spotId, $ownerPkey, $alsoSamples = true) {
		try {
			if (!self::enabled($db)) return false;
			if (self::$fieldTouchesSuppressed) return true;
			$upk = (int)$ownerPkey;
			$sidEsc = pg_escape_string((string)$spotId);
			$key = 'spot:' . $spotId . ':' . $upk;
			self::lock($db, $key);
			try {
				$t0 = self::touchEpoch($db);
				$vocabTagTypes = array();
				$vocabImageTypes = array();

				// --- item rows -------------------------------------------------
				// Two-pass per project: collect every (project, dataset) fan-out
				// path first, then build ONE tuple per project carrying the
				// merged dataset_ids (+ all dataset names in the searchtext
				// bag). The touch Cypher walks every path, so the merge is
				// current-state complete — a full replace, no union-with-stale.
				$byProj  = array();  // pid → ['row' => last Cypher row, 'dids' => set, 'dnames' => set]
				$tagMaps = array();  // keyed by project id → json_tags map (decode once)
				$rows = $neodb->query(fieldSpotTouchCypher($upk, neoIdLiteral($spotId)));
				if ($rows) {
					foreach ($rows as $r) {
						if ($r->get('sid') === null) continue;
						$pid = $r->get('pid');
						if ($pid === null) continue;
						$pk = (string)$pid;
						if (!isset($tagMaps[$pk])) {
							$tagMaps[$pk] = fieldTagMapFromJsonTags($r->get('pjt'));
						}
						if (!isset($byProj[$pk])) {
							$byProj[$pk] = array('pid' => $pid, 'row' => $r,
								'dids' => array(), 'dnames' => array());
						}
						$byProj[$pk]['row'] = $r;
						$did = $r->get('did');
						if ($did !== null && (string)$did !== '') {
							$byProj[$pk]['dids'][(string)$did] = true;
						}
						$dname = $r->get('dname');
						if (is_string($dname) && $dname !== '') {
							$byProj[$pk]['dnames'][$dname] = true;
						}
					}
				}
				$tuples = array();
				foreach ($byProj as $pk => $agg) {
					$r = $agg['row'];
					$tuples[] = fieldSpotTuple(
						$r, $agg['pid'], $upk, $r->get('pname'),
						implode(' ', array_keys($agg['dnames'])),
						self::fieldProjectIsPublic($db, $agg['pid'], $upk),
						$tagMaps[$pk], $vocabTagTypes, array_keys($agg['dids']));
				}
				$n = self::upsertItems($db, fieldItemCols(), $tuples);
				$db->query("DELETE FROM strabosearch.item_hit
					WHERE item_type = 'spot' AND item_id = '$sidEsc'
					  AND item_userpkey = $upk
					  AND last_synced < '" . pg_escape_string($t0) . "'");

				// --- image rows ------------------------------------------------
				$imgTuples = array();  // keyed by image id → dedupe multi-dataset paths
				$irows = $neodb->query(fieldImagesTouchCypher($upk, neoIdLiteral($spotId)));
				if ($irows) {
					foreach ($irows as $r) {
						$sid = $r->get('sid');
						$pid = $r->get('pid');
						if ($sid === null || $pid === null) continue;
						$pk = (string)$pid;
						if (!isset($tagMaps[$pk])) {
							$tagMaps[$pk] = fieldTagMapFromJsonTags($r->get('pjt'));
						}
						$stats = array('no_filename' => 0, 'no_id' => 0);
						$built = fieldImageTuples($r, $sid, $pid, $upk,
							self::fieldProjectIsPublic($db, $pid, $upk),
							$tagMaps[$pk], $vocabImageTypes, $stats);
						// fieldImageTuples returns tuples in the images-collection
						// order; re-key by the image id embedded at position 1 is
						// fragile, so dedupe on the tuple text (identical paths
						// yield byte-identical tuples).
						foreach ($built as $tp) $imgTuples[md5($tp)] = $tp;
					}
				}
				$n += self::upsertImages($db, fieldImageCols(), array_values($imgTuples));
				$db->query("DELETE FROM strabosearch.image_hit
					WHERE image_subsystem = 'field' AND parent_spot_id = '$sidEsc'
					  AND project_userpkey = $upk
					  AND last_synced < '" . pg_escape_string($t0) . "'");

				if ($vocabTagTypes)   upsertVocabTagTypes($db, $vocabTagTypes, 'field');
				if ($vocabImageTypes) upsertVocabImageTypes($db, $vocabImageTypes, 'field');
				self::markSync($db, 'field', $n);
			} finally {
				self::unlock($db, $key);
			}
			// Outside the spot lock — touchSample takes its own lock.
			if ($alsoSamples) self::touchSamplesLinkedToSpots($db, array($spotId), $upk);
			return true;
		} catch (\Throwable $e) {
			self::resetNeo($neodb);
			return self::fail("touchSpot $spotId/$ownerPkey", $e);
		}
	}

	/**
	 * Drop one spot's item + image rows (post-delete hook — everything
	 * needed is already denormalized in the index), then re-evaluate
	 * linked samples (their field-hosted fan-out rows resolve through the
	 * indexed spot, which is now gone).
	 */
	public static function removeSpot($db, $spotId, $ownerPkey) {
		return self::removeSpots($db, array($spotId), $ownerPkey);
	}

	/**
	 * Bulk form for cascade deletes (dataset/project spot sweeps) — the
	 * caller enumerates doomed spot ids BEFORE the source delete.
	 */
	public static function removeSpots($db, $spotIds, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$spotIds = array_values(array_filter((array)$spotIds, function ($v) {
				return $v !== null && $v !== '';
			}));
			if (!$spotIds) return true;
			$upk = (int)$ownerPkey;
			foreach (array_chunk($spotIds, 500) as $chunk) {
				$in = array();
				foreach ($chunk as $sid) $in[] = "'" . pg_escape_string((string)$sid) . "'";
				$inList = implode(',', $in);
				$db->query("DELETE FROM strabosearch.item_hit
					WHERE item_type = 'spot' AND item_userpkey = $upk AND item_id IN ($inList)");
				$db->query("DELETE FROM strabosearch.image_hit
					WHERE image_subsystem = 'field' AND project_userpkey = $upk
					  AND parent_spot_id IN ($inList)");
			}
			self::markSync($db, 'field', 0);
			self::touchSamplesLinkedToSpots($db, $spotIds, $upk);
			return true;
		} catch (\Throwable $e) {
			return self::fail('removeSpots(' . count((array)$spotIds) . ")/$ownerPkey", $e);
		}
	}

	/**
	 * Touch a single Field image: resolve its parent spot through the
	 * user-anchored walk and re-touch that spot (item has_images + image
	 * rows in one shot). An image unreachable from any spot is unservable
	 * (§5.5) — any stale index row is removed.
	 */
	public static function touchImage($db, $neodb, $imageId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			if (self::$fieldTouchesSuppressed) return true;
			$upk = (int)$ownerPkey;
			$rows = $neodb->query("
				MATCH (u:User {userpkey: $upk})
				      -[:HAS_PROJECT]->(:Project)
				      -[:HAS_DATASET]->(:Dataset)
				      -[:HAS_SPOT]->(s:Spot)
				      -[:HAS_IMAGE]->(i:Image {id: " . neoIdLiteral($imageId) . "})
				RETURN distinct s.id AS sid
			");
			$found = false;
			if ($rows) {
				foreach ($rows as $r) {
					$sid = $r->get('sid');
					if ($sid === null) continue;
					$found = true;
					self::touchSpot($db, $neodb, $sid, $upk, false);
				}
			}
			if (!$found) self::removeImage($db, $imageId, $upk);
			return true;
		} catch (\Throwable $e) {
			self::resetNeo($neodb);
			return self::fail("touchImage $imageId/$ownerPkey", $e);
		}
	}

	public static function removeImage($db, $imageId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$db->query("DELETE FROM strabosearch.image_hit
				WHERE image_subsystem = 'field'
				  AND image_id = '" . pg_escape_string((string)$imageId) . "'
				  AND image_userpkey = " . (int)$ownerPkey);
			return true;
		} catch (\Throwable $e) {
			return self::fail("removeImage $imageId/$ownerPkey", $e);
		}
	}

	/**
	 * §5.3.4 batch path — one advisory lock per dataset, single batch
	 * re-extract at end-of-dataset (the buildPgDataset call sites).
	 * UPSERT-ONLY by design: bulk flows delete removed spots through
	 * removeSpot/removeSpots mid-loop, so no stale sweep is needed here
	 * (and item_hit carries no dataset id to scope one by).
	 */
	public static function syncFieldDataset($db, $neodb, $datasetId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$upk = (int)$ownerPkey;
			$key = 'dataset:' . $datasetId . ':' . $upk;
			$touchedSpotIds = array();
			self::lock($db, $key);
			try {
				$vocabTagTypes = array();
				$vocabImageTypes = array();
				$nItems = 0;
				$nImages = 0;

				// (project, dataset) context pairs for this dataset id under
				// this user — normally exactly one.
				$pdRows = $neodb->query("
					MATCH (u:User {userpkey: $upk})-[:HAS_PROJECT]->(p:Project)
					      -[:HAS_DATASET]->(d:Dataset {id: " . neoIdLiteral($datasetId) . "})
					RETURN p.id AS pid, coalesce(p.desc_project_name, p.projectname) AS pname,
					       substring(toString(p.json_tags), 0, 1000000) AS pjt,
					       d.id AS did, d.name AS dname
				");
				if ($pdRows) {
					foreach ($pdRows as $pd) {
						$pid   = $pd->get('pid');
						$did   = $pd->get('did');
						if ($pid === null || $did === null) continue;
						$pname = $pd->get('pname');
						$dname = $pd->get('dname');
						$pispub = self::fieldProjectIsPublic($db, $pid, $upk);
						$pidLit = neoIdLiteral($pid);
						$didLit = neoIdLiteral($did);
						// json_tags amendment: per-project spot_id → tags map.
						$tagMap = fieldTagMapFromJsonTags($pd->get('pjt'));

						// --- spots (keyset batches, same as backfill) ---------
						$cursor = null;
						while (true) {
							$cursorClause = $cursor !== null
								? "AND s.id > " . neoIdLiteral($cursor) . " " : '';
							$rows = $neodb->query(
								fieldSpotBatchCypher($upk, $pidLit, $didLit, $cursorClause, 50));
							if (!$rows) break;
							$tuples = array();
							foreach ($rows as $r) {
								$sid = $r->get('sid');
								if ($sid === null) continue;
								$cursor = $sid;
								$touchedSpotIds[(string)$sid] = true;
								// dataset_ids = the synced dataset (single-path
								// replace; see fieldSpotTuple docblock).
								$tuples[] = fieldSpotTuple($r, $pid, $upk, $pname, $dname,
									$pispub, $tagMap, $vocabTagTypes, array($did));
							}
							$nItems += self::upsertItems($db, fieldItemCols(), $tuples);
							if (count($rows) < 50) break;
						}

						// --- images (keyset batches) --------------------------
						$cursor = null;
						while (true) {
							$cursorClause = $cursor !== null
								? "AND s.id > " . neoIdLiteral($cursor) . " " : '';
							$rows = $neodb->query(
								fieldImagesBatchCypher($upk, $pidLit, $didLit, $cursorClause, 50));
							if (!$rows) break;
							$tuples = array();
							$count = 0;
							foreach ($rows as $r) {
								$sid = $r->get('sid');
								if ($sid === null) continue;
								$cursor = $sid;
								$count++;
								$stats = array('no_filename' => 0, 'no_id' => 0);
								$tuples = array_merge($tuples, fieldImageTuples(
									$r, $sid, $pid, $upk, $pispub, $tagMap,
									$vocabImageTypes, $stats));
							}
							$nImages += self::upsertImages($db, fieldImageCols(), $tuples);
							if ($count < 50) break;
						}
					}
				}

				if ($vocabTagTypes)   upsertVocabTagTypes($db, $vocabTagTypes, 'field');
				if ($vocabImageTypes) upsertVocabImageTypes($db, $vocabImageTypes, 'field');
				self::markSync($db, 'field', $nItems + $nImages);
			} finally {
				self::unlock($db, $key);
			}
			// Sample fan-out re-evaluation for every spot in the dataset —
			// per-item touches were suppressed during the bulk loop, so
			// sample syncs that ran mid-upload resolved against a not-yet-
			// indexed Field slice. Outside the dataset lock.
			self::touchSamplesLinkedToSpots($db, array_keys($touchedSpotIds), $upk);
			return true;
		} catch (\Throwable $e) {
			self::resetNeo($neodb);
			return self::fail("syncFieldDataset $datasetId/$ownerPkey", $e);
		}
	}

	/**
	 * Drop every Field index row of one project — spots, their images, and
	 * the sample fan-out rows hosted by the project (they carry
	 * project_subsystem='field' + this project_id). Used by
	 * deleteProject / deleteProjectDatasets (post-delete: identifiers are
	 * all denormalized in the index, no pre-enumeration needed).
	 */
	public static function removeFieldProject($db, $projectId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$pidEsc = pg_escape_string((string)$projectId);
			$upk = (int)$ownerPkey;
			$db->query("DELETE FROM strabosearch.item_hit
				WHERE project_subsystem = 'field' AND project_id = '$pidEsc'
				  AND project_userpkey = $upk");
			$db->query("DELETE FROM strabosearch.image_hit
				WHERE image_subsystem = 'field' AND project_id = '$pidEsc'
				  AND project_userpkey = $upk");
			self::markSync($db, 'field', 0);
			return true;
		} catch (\Throwable $e) {
			return self::fail("removeFieldProject $projectId/$ownerPkey", $e);
		}
	}

	/**
	 * Max affected spots that get a full per-spot re-extract (touchSpot,
	 * searchtext ride-along included) when a project-level tag change is
	 * propagated; above the cap the refresh falls back to PG-only updates
	 * of the tag-derived columns (rock_types, met_facies, tag_names,
	 * tag_types, tag_text_tsv), leaving each spot's searchtext_tsv tag
	 * ride-along stale until its next re-extract. That is the same
	 * accepted staleness class as the documented Field project rename;
	 * U10 tag search reads tag_text_tsv / tag_names and stays exact.
	 */
	const FIELD_TAG_TOUCH_CAP = 50;

	/**
	 * json_tags amendment: propagate a PROJECT-ONLY tag edit into the
	 * index. The web version of the StraboField app saves surgically: a
	 * tag assign/rename/unassign changes nothing but Project.json_tags,
	 * so no spot or dataset write follows and the per-spot / per-dataset
	 * hooks never fire. Without this hook the tag-derived index columns
	 * stay stale forever (the nightly verify checks spot presence at the
	 * id level and the spots themselves never changed).
	 *
	 * Diff-gated: resolves each spot's tag facets from the OLD and NEW
	 * blobs via the shared fieldTagsForSpot and refreshes only spots
	 * whose resolved facets differ, so the common no-tag-change autosave
	 * costs one decode and no writes.
	 */
	public static function touchFieldProjectTags($db, $neodb, $projectId, $ownerPkey, $oldJsonTags, $newJsonTags) {
		try {
			if (!self::enabled($db)) return false;
			// Bulk uploads re-extract every spot at end-of-dataset with the
			// fresh blob; nothing to do here.
			if (self::$fieldTouchesSuppressed) return true;
			$upk = (int)$ownerPkey;
			$oldMap = fieldTagMapFromJsonTags($oldJsonTags);
			$newMap = fieldTagMapFromJsonTags($newJsonTags);
			$affected = array();
			$vocabIgnored = array();
			$allSpotIds = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));
			foreach ($allSpotIds as $sid) {
				if (fieldTagsForSpot($oldMap, $sid, $vocabIgnored)
						!== fieldTagsForSpot($newMap, $sid, $vocabIgnored)) {
					$affected[] = (string)$sid;
				}
			}
			if (!$affected) return true;
			return self::refreshFieldProjectTagColumns($db, $neodb, $projectId, $upk,
				$newJsonTags, $affected);
		} catch (\Throwable $e) {
			self::resetNeo($neodb);
			return self::fail("touchFieldProjectTags $projectId/$ownerPkey", $e);
		}
	}

	/**
	 * Refresh the tag-derived index columns for the given spots of one
	 * Field project from a json_tags blob. Two strategies:
	 *
	 *   - small set (<= FIELD_TAG_TOUCH_CAP) with a live $neodb: full
	 *     per-spot touchSpot re-extract, so every column (searchtext_tsv
	 *     included) is exact. This is the typical surgical web-app edit
	 *     (one tag on a handful of spots).
	 *   - large set or $neodb === null: chunked PG-only UPDATEs of
	 *     item_hit + image_hit tag columns. No Neo4j walk (the reason the
	 *     inline whole-project rebuild was rejected for renames), at the
	 *     cost of the documented searchtext tag ride-along staleness.
	 *
	 * Spots without an index row are silently unaffected (a not-yet-
	 * uploaded spot gets indexed with fresh tags by its own upload).
	 * Shared by the insertProject hook and searchdb/heal_project_tags.php.
	 */
	public static function refreshFieldProjectTagColumns($db, $neodb, $projectId, $ownerPkey, $newJsonTags, $spotIds) {
		try {
			if (!self::enabled($db)) return false;
			$upk = (int)$ownerPkey;
			$spotIds = array_values(array_unique(array_filter(
				array_map('strval', (array)$spotIds), 'strlen')));
			if (!$spotIds) return true;

			if ($neodb !== null && count($spotIds) <= self::FIELD_TAG_TOUCH_CAP) {
				foreach ($spotIds as $sid) {
					self::touchSpot($db, $neodb, $sid, $upk, false);
				}
				return true;
			}

			$newMap = fieldTagMapFromJsonTags($newJsonTags);
			$pidEsc = pg_escape_string((string)$projectId);
			$key = 'fieldtags:' . $projectId . ':' . $upk;
			self::lock($db, $key);
			try {
				$vocabSeen = array();
				// Self-typed NULLs: an all-NULL VALUES column (mass unassign)
				// would otherwise resolve to text and break the ::text[] /
				// ::tsvector casts in the SET list.
				$typedArr = function ($arr) {
					$l = pgTextArray($arr);
					return $l === 'NULL' ? 'NULL::TEXT[]' : $l;
				};
				foreach (array_chunk($spotIds, 500) as $chunk) {
					$rows = array();
					foreach ($chunk as $sid) {
						list($rockTypes, $metFacies, $tagNames, $tagTypes)
							= fieldTagsForSpot($newMap, $sid, $vocabSeen);
						$tsv = pgTsvector(implode(' ', $tagNames));
						$rows[] = '(' . implode(',', array(
							pgText($sid, 64),
							$typedArr($rockTypes),
							$typedArr($metFacies),
							$typedArr($tagNames),
							$typedArr($tagTypes),
							$tsv === 'NULL' ? 'NULL::tsvector' : $tsv,
						)) . ')';
					}
					$values = implode(",\n", $rows);
					$sets = "rock_types = v.rock_types::text[],
						met_facies = v.met_facies::text[],
						tag_names = v.tag_names::text[],
						tag_types = v.tag_types::text[],
						tag_text_tsv = v.tag_text_tsv::tsvector,
						last_synced = now()";
					$vAlias = "(VALUES $values) AS v(item_id, rock_types, met_facies, tag_names, tag_types, tag_text_tsv)";
					if ($db->query("UPDATE strabosearch.item_hit i SET $sets
						FROM $vAlias
						WHERE i.item_type = 'spot' AND i.project_subsystem = 'field'
						  AND i.project_id = '$pidEsc' AND i.project_userpkey = $upk
						  AND i.item_id = v.item_id::text") === false) {
						throw new \RuntimeException('item_hit tag refresh failed: ' . $db->last_error);
					}
					if ($db->query("UPDATE strabosearch.image_hit i SET $sets
						FROM $vAlias
						WHERE i.image_subsystem = 'field'
						  AND i.project_id = '$pidEsc' AND i.project_userpkey = $upk
						  AND i.parent_spot_id = v.item_id::text") === false) {
						throw new \RuntimeException('image_hit tag refresh failed: ' . $db->last_error);
					}
				}
				if ($vocabSeen) upsertVocabTagTypes($db, $vocabSeen, 'field');
				self::markSync($db, 'field', count($spotIds));
			} finally {
				self::unlock($db, $key);
			}
			return true;
		} catch (\Throwable $e) {
			return self::fail("refreshFieldProjectTagColumns $projectId/$ownerPkey", $e);
		}
	}

	/** Samples linked (subsystem='field') to any of the given spot ids. */
	private static function touchSamplesLinkedToSpots($db, $spotIds, $refUserpkey) {
		$spotIds = array_values(array_filter((array)$spotIds, function ($v) {
			return $v !== null && $v !== '';
		}));
		if (!$spotIds) return;
		if (!$db->get_var("SELECT to_regclass('strabosamples.sample_subsystem_links')")) return;
		$upk = (int)$refUserpkey;
		foreach (array_chunk($spotIds, 500) as $chunk) {
			$in = array();
			foreach ($chunk as $sid) $in[] = "'" . pg_escape_string((string)$sid) . "'";
			$rows = $db->get_results("
				SELECT DISTINCT sample_id, sample_userpkey
				FROM strabosamples.sample_subsystem_links
				WHERE subsystem = 'field' AND reference_userpkey = $upk
				  AND reference_id IN (" . implode(',', $in) . ")");
			foreach ((array)$rows as $r) {
				self::touchSample($db, $r->sample_id, $r->sample_userpkey);
			}
		}
	}

	// =======================================================================
	// MICRO — whole-project slice rebuild
	// =======================================================================

	/**
	 * Rebuild the index slice of one Micro project (item_hit micrographs +
	 * image_hit micrographs + item_hit sample fan-out rows hosted by the
	 * project). Micro's write model is whole-project replace, so the hook
	 * granularity matches the write granularity exactly.
	 *
	 * $projectMetadataId = micro_projectmetadata.id (internal pkey — what
	 * the source query joins on); $straboProjectId + $ownerPkey scope the
	 * stale sweep (they are the denormalized identity in the index).
	 */
	public static function syncMicroProject($db, $projectMetadataId, $straboProjectId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$pmId = (int)$projectMetadataId;
			$pidEsc = pg_escape_string((string)$straboProjectId);
			$upk = (int)$ownerPkey;
			$key = 'microproject:' . $straboProjectId . ':' . $upk;
			self::lock($db, $key);
			try {
				$t0 = self::touchEpoch($db);
				$t0Esc = pg_escape_string($t0);
				$vocabSeen = array();
				$itemTuples = array();
				$imageTuples = array();
				$rows = $db->get_results(microSourceSql("AND pm.id = $pmId"));
				foreach ((array)$rows as $r) {
					$pair = microTuples($r, $vocabSeen);
					if ($pair === null) continue;
					$itemTuples[]  = $pair[0];
					$imageTuples[] = $pair[1];
				}
				$n  = self::upsertItems($db, microItemCols(), $itemTuples);
				$n += self::upsertImages($db, microImageCols(), $imageTuples);
				$db->query("DELETE FROM strabosearch.item_hit
					WHERE project_subsystem = 'micro' AND item_type = 'micrograph'
					  AND project_id = '$pidEsc' AND project_userpkey = $upk
					  AND last_synced < '$t0Esc'");
				$db->query("DELETE FROM strabosearch.image_hit
					WHERE image_subsystem = 'micro'
					  AND project_id = '$pidEsc' AND project_userpkey = $upk
					  AND last_synced < '$t0Esc'");
				if ($vocabSeen) upsertVocabImageTypes($db, $vocabSeen, 'micro');

				// Sample fan-out rows hosted by this Micro project: refresh
				// (project may have been renamed / re-uploaded / ispublic
				// flipped inside the new JSON).
				$sTuples = array();
				$sRows = $db->get_results(samplesMicroSql(
					"AND pm.strabo_id = '$pidEsc' AND pm.userpkey = $upk"));
				foreach ((array)$sRows as $r) {
					$sTuples[] = sampleTuple($r, 'micro', null);
				}
				$n += self::upsertItems($db, samplesItemCols(), $sTuples);
				$db->query("DELETE FROM strabosearch.item_hit
					WHERE item_type = 'sample' AND project_subsystem = 'micro'
					  AND project_id = '$pidEsc' AND project_userpkey = $upk
					  AND last_synced < '$t0Esc'");

				self::markSync($db, 'micro', $n);
			} finally {
				self::unlock($db, $key);
			}
			return true;
		} catch (\Throwable $e) {
			return self::fail("syncMicroProject $projectMetadataId/$straboProjectId/$ownerPkey", $e);
		}
	}

	/**
	 * Drop the entire index slice of one Micro project (micrographs,
	 * micrograph images, hosted sample fan-out rows). Pre- or post-delete
	 * — identity is denormalized, no source lookup needed.
	 */
	public static function removeMicroProject($db, $straboProjectId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$pidEsc = pg_escape_string((string)$straboProjectId);
			$upk = (int)$ownerPkey;
			$db->query("DELETE FROM strabosearch.item_hit
				WHERE project_subsystem = 'micro' AND project_id = '$pidEsc'
				  AND project_userpkey = $upk");
			$db->query("DELETE FROM strabosearch.image_hit
				WHERE image_subsystem = 'micro' AND project_id = '$pidEsc'
				  AND project_userpkey = $upk");
			self::markSync($db, 'micro', 0);
			return true;
		} catch (\Throwable $e) {
			return self::fail("removeMicroProject $straboProjectId/$ownerPkey", $e);
		}
	}

	// =======================================================================
	// EXP — per-experiment touch + per-project refresh
	// =======================================================================

	/**
	 * Re-extract one experiment by its PG pkey. Owner + project context
	 * come from the source row (key on the experiment OWNER, never the
	 * session user — admin edits must land under e.userpkey).
	 */
	public static function touchExperiment($db, $experimentPkey) {
		try {
			if (!self::enabled($db)) return false;
			$rows = $db->get_results(expSourceSql("AND e.pkey = " . (int)$experimentPkey));
			$rows = (array)$rows;
			if (!$rows) return true;  // gone or unindexable — delete hooks handle removal
			$r = $rows[0];
			$expId = (string)$r->experiment_id;
			$upk = (int)$r->userpkey;
			$key = 'experiment:' . $expId . ':' . $upk;
			self::lock($db, $key);
			try {
				$t0Esc = pg_escape_string(self::touchEpoch($db));
				self::upsertItems($db, expItemCols(), array(expTuple($r)));
				// Stale sweep catches a project move (old project row lingers).
				$db->query("DELETE FROM strabosearch.item_hit
					WHERE item_type = 'experiment'
					  AND item_id = '" . pg_escape_string($expId) . "'
					  AND item_userpkey = $upk
					  AND last_synced < '$t0Esc'");
				self::markSync($db, 'exp', 1);
			} finally {
				self::unlock($db, $key);
			}
			return true;
		} catch (\Throwable $e) {
			return self::fail("touchExperiment $experimentPkey", $e);
		}
	}

	/**
	 * Refresh every index row hosted by one Exp project: all its
	 * experiment rows (searchtext_tsv embeds the project name, so a rename
	 * must re-extract, not just re-label) and its sample fan-out rows.
	 */
	public static function touchExpProject($db, $projectPkey) {
		try {
			if (!self::enabled($db)) return false;
			$pk = (int)$projectPkey;
			$uuid = $db->get_var("SELECT uuid FROM straboexp.project WHERE pkey = $pk");
			if ($uuid === null || $uuid === '') return true;
			$uuidEsc = pg_escape_string((string)$uuid);
			$key = 'expproject:' . $uuid;
			self::lock($db, $key);
			try {
				$t0Esc = pg_escape_string(self::touchEpoch($db));
				$tuples = array();
				$rows = $db->get_results(expSourceSql("AND e.project_pkey = $pk"));
				foreach ((array)$rows as $r) $tuples[] = expTuple($r);
				$n = self::upsertItems($db, expItemCols(), $tuples);
				$db->query("DELETE FROM strabosearch.item_hit
					WHERE item_type = 'experiment' AND project_subsystem = 'exp'
					  AND project_id = '$uuidEsc'
					  AND last_synced < '$t0Esc'");

				$sTuples = array();
				$sRows = $db->get_results(samplesExpSql("AND p.uuid = '$uuidEsc'"));
				foreach ((array)$sRows as $r) $sTuples[] = sampleTuple($r, 'exp', null);
				$n += self::upsertItems($db, samplesItemCols(), $sTuples);
				$db->query("DELETE FROM strabosearch.item_hit
					WHERE item_type = 'sample' AND project_subsystem = 'exp'
					  AND project_id = '$uuidEsc'
					  AND last_synced < '$t0Esc'");

				self::markSync($db, 'exp', $n);
			} finally {
				self::unlock($db, $key);
			}
			return true;
		} catch (\Throwable $e) {
			return self::fail("touchExpProject $projectPkey", $e);
		}
	}

	public static function removeExperiment($db, $experimentId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$db->query("DELETE FROM strabosearch.item_hit
				WHERE item_type = 'experiment'
				  AND item_id = '" . pg_escape_string((string)$experimentId) . "'
				  AND item_userpkey = " . (int)$ownerPkey);
			self::markSync($db, 'exp', 0);
			return true;
		} catch (\Throwable $e) {
			return self::fail("removeExperiment $experimentId/$ownerPkey", $e);
		}
	}

	/**
	 * Drop every index row hosted by one Exp project (experiments + sample
	 * fan-out). No project_userpkey filter: the Exp convention sets
	 * project_userpkey = experiment owner, which can differ per experiment
	 * within one project; the uuid alone is the project identity.
	 */
	public static function removeExpProject($db, $projectUuid) {
		try {
			if (!self::enabled($db)) return false;
			$db->query("DELETE FROM strabosearch.item_hit
				WHERE project_subsystem = 'exp'
				  AND project_id = '" . pg_escape_string((string)$projectUuid) . "'");
			self::markSync($db, 'exp', 0);
			return true;
		} catch (\Throwable $e) {
			return self::fail("removeExpProject $projectUuid", $e);
		}
	}

	// =======================================================================
	// SAMPLES — spine fan-out recompute
	// =======================================================================

	/**
	 * Recompute ALL fan-out rows of one spine sample from its CURRENT
	 * links (§5.3.1 Samples row). Recompute-from-current-state (not
	 * incremental link tracking) deliberately: it absorbs link deletes
	 * that bypass the service (db/lib/sample_sync.php stale-stub cleanup),
	 * spine row deletion (zero tuples → all rows swept), and JSONB-null
	 * unlinks identically.
	 */
	public static function touchSample($db, $sampleId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$sidEsc = pg_escape_string((string)$sampleId);
			$upk = (int)$ownerPkey;
			$key = 'sample:' . $sampleId . ':' . $upk;
			self::lock($db, $key);
			try {
				$t0Esc = pg_escape_string(self::touchEpoch($db));
				$filter = "AND s.id = '$sidEsc' AND s.userpkey = $upk";
				$tuples = array();
				foreach ((array)$db->get_results(samplesFieldSql($filter)) as $r) {
					$tuples[] = sampleTuple($r, 'field', $r->host_loc_wkt);
				}
				foreach ((array)$db->get_results(samplesMicroSql($filter)) as $r) {
					$tuples[] = sampleTuple($r, 'micro', null);
				}
				foreach ((array)$db->get_results(samplesExpSql($filter)) as $r) {
					$tuples[] = sampleTuple($r, 'exp', null);
				}
				$n = self::upsertItems($db, samplesItemCols(), $tuples);
				$db->query("DELETE FROM strabosearch.item_hit
					WHERE item_type = 'sample' AND item_id = '$sidEsc'
					  AND item_userpkey = $upk
					  AND last_synced < '$t0Esc'");
				self::markSync($db, 'samples', $n);
			} finally {
				self::unlock($db, $key);
			}
			return true;
		} catch (\Throwable $e) {
			return self::fail("touchSample $sampleId/$ownerPkey", $e);
		}
	}

	public static function removeSample($db, $sampleId, $ownerPkey) {
		try {
			if (!self::enabled($db)) return false;
			$db->query("DELETE FROM strabosearch.item_hit
				WHERE item_type = 'sample'
				  AND item_id = '" . pg_escape_string((string)$sampleId) . "'
				  AND item_userpkey = " . (int)$ownerPkey);
			self::markSync($db, 'samples', 0);
			return true;
		} catch (\Throwable $e) {
			return self::fail("removeSample $sampleId/$ownerPkey", $e);
		}
	}

	// =======================================================================
	// PROJECT META — cheap denorm refresh (name / ispublic)
	// =======================================================================

	/**
	 * Update the denormalized project_name / project_ispublic columns on
	 * every index row of a project slice WITHOUT re-extracting. The
	 * ispublic flip is the ACL-relevant case: a public→private toggle must
	 * not leave items publicly searchable until the nightly heal.
	 *
	 * $projectId null + $projectUserpkey set = every project of that user
	 * in that subsystem (the deleteUserAccount micro sweep).
	 *
	 * Field caveat (documented in the class docblock): searchtext_tsv still
	 * embeds the old project name until the next re-extract of each spot.
	 */
	public static function touchProjectMeta($db, $subsystem, $projectId, $projectUserpkey,
			$name = null, $ispublic = null) {
		try {
			if (!self::enabled($db)) return false;
			$set = array();
			if ($name !== null) {
				$set[] = "project_name = '" . pg_escape_string(mb_substr((string)$name, 0, 500)) . "'";
			}
			if ($ispublic !== null) {
				$set[] = 'project_ispublic = ' . ($ispublic ? 'TRUE' : 'FALSE');
			}
			if (!$set) return true;
			$setSql = implode(', ', $set) . ', last_synced = now()';

			$where = "project_subsystem = '" . pg_escape_string((string)$subsystem) . "'";
			if ($projectId !== null) {
				$where .= " AND project_id = '" . pg_escape_string((string)$projectId) . "'";
			}
			if ($projectUserpkey !== null) {
				$where .= ' AND project_userpkey = ' . (int)$projectUserpkey;
			}

			$db->query("UPDATE strabosearch.item_hit SET $setSql WHERE $where");
			// image_hit has no project_name column — only the ispublic flip
			// applies there (field + micro slices).
			if ($ispublic !== null && ($subsystem === 'field' || $subsystem === 'micro')) {
				$iwhere = "image_subsystem = '" . pg_escape_string((string)$subsystem) . "'";
				if ($projectId !== null) {
					$iwhere .= " AND project_id = '" . pg_escape_string((string)$projectId) . "'";
				}
				if ($projectUserpkey !== null) {
					$iwhere .= ' AND project_userpkey = ' . (int)$projectUserpkey;
				}
				$db->query("UPDATE strabosearch.image_hit
					SET project_ispublic = " . ($ispublic ? 'TRUE' : 'FALSE') . ",
					    last_synced = now()
					WHERE $iwhere");
			}
			self::markSync($db, $subsystem === 'exp' ? 'exp' : $subsystem, 0);
			return true;
		} catch (\Throwable $e) {
			return self::fail("touchProjectMeta $subsystem/$projectId", $e);
		}
	}
}
?>
