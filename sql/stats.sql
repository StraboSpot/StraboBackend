-- Admin statistics snapshots for /stats (built by scripts/stats/build_stats.php).
-- One JSON document per build; the page shows the newest. Safe to run twice.
CREATE TABLE IF NOT EXISTS stats_snapshots (
	pkey          serial PRIMARY KEY,
	built_at      timestamptz NOT NULL DEFAULT now(),
	build_seconds numeric,
	error_count   integer NOT NULL DEFAULT 0,
	data          jsonb NOT NULL
);
CREATE INDEX IF NOT EXISTS stats_snapshots_built_at ON stats_snapshots (built_at DESC);
