<?php
/**
 * File: includes/sesar/SesarVocab.php
 * Description: SESAR controlled vocabularies (object types, material types)
 *              cached in strabosamples.sesar_vocab_cache. The vocab
 *              endpoints are public; the cache is refreshed when older than
 *              MAX_AGE and, when SESAR is unreachable, the stale copy is
 *              served rather than failing the mint form.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

require_once __DIR__ . '/SesarClient.php';

class SesarVocab
{
	const MAX_AGE = 604800;   // 7 days
	const OBJECT_TYPES = 'object-types';
	const MATERIAL_TYPES = 'material-types';

	private $db;
	private $client;

	public function __construct($db, SesarClient $client)
	{
		$this->db = $db;
		$this->client = $client;
	}

	/** Full vocab rows (id, label, hierarchical_label, ...). */
	public function terms($vocab)
	{
		$env = $this->client->environment();
		$row = $this->db->get_row_prepared(
			"SELECT data, extract(epoch FROM now() - fetched_at) AS age
			   FROM strabosamples.sesar_vocab_cache WHERE environment = $1 AND vocab = $2",
			array($env, $vocab)
		);
		if ($row !== null && (float)$row->age < self::MAX_AGE) {
			return json_decode($row->data, true);
		}
		try {
			$terms = $this->client->vocab($vocab);
		} catch (SesarError $e) {
			if ($row !== null) return json_decode($row->data, true);   // stale beats nothing
			throw $e;
		}
		$this->db->prepare_query(
			"INSERT INTO strabosamples.sesar_vocab_cache (environment, vocab, data, fetched_at)
			 VALUES ($1, $2, $3::jsonb, now())
			 ON CONFLICT (environment, vocab) DO UPDATE SET data = EXCLUDED.data, fetched_at = now()",
			array($env, $vocab, json_encode(array_values($terms)))
		);
		return $terms;
	}

	/** Plain labels (for SesarMapper suggestions and form validation). */
	public function labels($vocab)
	{
		$out = array();
		foreach ($this->terms($vocab) as $t) {
			if (is_array($t) && isset($t['label'])) $out[] = (string)$t['label'];
		}
		return $out;
	}

	/**
	 * Object types grouped for the D2 dropdown: [group label => [leaf labels]].
	 * Top-level terms with no children form their own group.
	 */
	public function objectTypeGroups()
	{
		$groups = array();
		foreach ($this->terms(self::OBJECT_TYPES) as $t) {
			if (!is_array($t) || !isset($t['label'])) continue;
			$path = isset($t['hierarchical_label']) ? (string)$t['hierarchical_label'] : (string)$t['label'];
			$parts = array_map('trim', explode('>', $path));
			$group = $parts[0];
			if (!isset($groups[$group])) $groups[$group] = array();
			if (count($parts) > 1) $groups[$group][] = (string)$t['label'];
		}
		foreach ($groups as $g => $leaves) {
			if (empty($leaves)) $groups[$g] = array($g);
		}
		return $groups;
	}
}
