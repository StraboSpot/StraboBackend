# StraboSamples Dev-Clone Setup

Hands-on runbook for spinning up a dev environment with **realistic sample data from all three subsystems** so the Phase-1 migration scripts (next session) have something to round-trip against.

The exit criterion for Phase 1 (§13.2 of the design doc) is *"migration runs lossless round-trip on a dev clone."* Until you've completed the steps below, that criterion can't be evaluated — the dev DB only has whatever data has accumulated from local development; it isn't representative of prod.

Run order:

1. Snapshot prod (read-only against prod)
2. Restore PostgreSQL into the dev `strabo-postgres` container
3. Restore Neo4j into the dev `strabo-neo4j` container
4. Apply the StraboSamples DDL
5. (Optional) Sanity-check counts

All commands assume the standard local Docker environment (containers `strabo-postgres`, `strabo-neo4j`, `strabo-php`). Adjust prod hostnames/credentials to match your environment.

---

## 1. Snapshot prod

### PostgreSQL

`pg_dump` against the prod replica (or a window when the primary is quiet). The `strabospot` database is the only one we need. Excluding the giant `rawcache` (request logging) trims the dump considerably.

```bash
# On a host that can reach prod postgres
pg_dump \
  -h <prod-postgres-host> \
  -U <admin-user> \
  -d strabospot \
  --no-owner --no-acl \
  --exclude-table=public.rawcache \
  --exclude-table=public.jwts \
  -Fc \
  -f strabospot-prod.dump
```

`-Fc` = custom format (compressed, supports `pg_restore --jobs`). `--exclude-table` drops the request-log + JWT tables (large and irrelevant to samples).

### Neo4j

Use `neo4j-admin database dump` on the prod node (requires the database to be **stopped** for an offline dump, or use `neo4j-admin database backup` for an online backup if available on your edition).

```bash
# On the prod Neo4j host, with the database stopped
neo4j-admin database dump --to-path=/tmp neo4j
# Produces /tmp/neo4j.dump
```

If you only need a subset for samples work, an alternative is to export sample-touching nodes via Cypher:

```cypher
// Export rich sample-spots + their datasets/projects
MATCH (p:Project)-[:HAS_DATASET]->(d:Dataset)-[:HAS_SPOT]->(s:Spot)
WHERE s.isSample = 1 OR s.json_samples IS NOT NULL
RETURN p, d, s
```

…piped through `apoc.export.cypher.query` if APOC is available. Full dump is simpler.

---

## 2. Restore PostgreSQL into dev

Copy the dump into the `strabo-postgres` container and restore. **This wipes the existing dev DB** — confirm you don't have unmerged dev-only data first.

```bash
# Copy dump in
docker cp strabospot-prod.dump strabo-postgres:/tmp/

# Drop existing dev DB and recreate
docker exec strabo-postgres psql -U postgres -c "DROP DATABASE IF EXISTS strabospot;"
docker exec strabo-postgres psql -U postgres -c "CREATE DATABASE strabospot OWNER strabodbuser;"

# Restore (parallel jobs)
docker exec strabo-postgres pg_restore \
  -U postgres -d strabospot \
  --jobs=4 \
  --no-owner --no-acl \
  /tmp/strabospot-prod.dump

# Re-grant the role
docker exec strabo-postgres psql -U postgres -d strabospot \
  -c "GRANT ALL ON SCHEMA public, straboexp, strabomicro TO strabodbuser;
      GRANT ALL ON ALL TABLES IN SCHEMA public, straboexp, strabomicro TO strabodbuser;
      GRANT ALL ON ALL SEQUENCES IN SCHEMA public, straboexp, strabomicro TO strabodbuser;"
```

The `pg_restore` step will emit warnings about `rawcache` / `jwts` not being restorable (we excluded them at dump time) — those are expected.

---

## 3. Restore Neo4j into dev

Stop the dev Neo4j, drop in the dump, restore, restart.

```bash
docker cp neo4j.dump strabo-neo4j:/tmp/

# Stop the database (depending on Neo4j version — 4.x syntax)
docker exec strabo-neo4j cypher-shell -u neo4j -p <dev-password> \
  "STOP DATABASE neo4j;"

docker exec strabo-neo4j neo4j-admin database load --from-path=/tmp --overwrite-destination=true neo4j

docker exec strabo-neo4j cypher-shell -u neo4j -p <dev-password> \
  "START DATABASE neo4j;"
```

If your dev Neo4j password doesn't match prod's, you'll need to reset auth after restore (`docker exec strabo-neo4j neo4j-admin dbms set-initial-password <new>`).

---

## 4. Apply the StraboSamples DDL

The schema artifact lives at `samplesdb/schema/strabosamples.sql`. It creates the `strabosamples` schema and the eight tables documented in design proposal §5.

```bash
docker cp samplesdb/schema/strabosamples.sql strabo-postgres:/tmp/

# Note: requires CREATE EXTENSION pgcrypto, which needs superuser.
# Use the `postgres` superuser, not `strabodbuser`.
docker exec strabo-postgres psql \
  -U postgres -d strabospot \
  -v ON_ERROR_STOP=1 \
  -f /tmp/strabosamples.sql

# Grant the app role read/write on the new schema
docker exec strabo-postgres psql -U postgres -d strabospot -c "
  GRANT USAGE ON SCHEMA strabosamples TO strabodbuser;
  GRANT ALL ON ALL TABLES IN SCHEMA strabosamples TO strabodbuser;
  GRANT ALL ON ALL SEQUENCES IN SCHEMA strabosamples TO strabodbuser;
  ALTER DEFAULT PRIVILEGES IN SCHEMA strabosamples
    GRANT ALL ON TABLES TO strabodbuser;
  ALTER DEFAULT PRIVILEGES IN SCHEMA strabosamples
    GRANT ALL ON SEQUENCES TO strabodbuser;
"
```

To roll back the schema entirely (e.g., to re-apply during iteration):

```bash
docker exec strabo-postgres psql -U postgres -d strabospot \
  -c "DROP SCHEMA strabosamples CASCADE;"
```

The DDL is wrapped in a single transaction, so a `BEGIN; ... COMMIT;` failure leaves nothing partially-applied.

---

## 5. (Optional) Sanity-check counts

Confirm the restore looks right before handing off to migration work. Expected orders of magnitude (prod, 2026-05-28 audit):

```bash
docker exec strabo-postgres psql -U strabodbuser -d strabospot -c "
  SELECT 'micro_samplemetadata' AS source, count(*) FROM strabomicro.micro_samplemetadata
  UNION ALL
  SELECT 'straboexp.sample',              count(*) FROM straboexp.sample
  UNION ALL
  SELECT 'straboexp.sample_composition',  count(*) FROM straboexp.sample_composition
  UNION ALL
  SELECT 'straboexp.sample_parameter',    count(*) FROM straboexp.sample_parameter
  UNION ALL
  SELECT 'straboexp.document (sample)',   count(*) FROM straboexp.document WHERE sample_pkey IS NOT NULL
  UNION ALL
  SELECT 'collaborators',                 count(*) FROM collaborators
  UNION ALL
  SELECT 'users',                         count(*) FROM users;
"
```

Neo4j sample-spot count (rich samples):

```bash
docker exec strabo-neo4j cypher-shell -u neo4j -p <dev-password> \
  "MATCH (s:Spot) WHERE s.isSample = 1 RETURN count(s);"
```

Prod showed ~31 rich sample-spots and 191 Micro↔Field-bridge sample ids (see `docs/StraboSamples/sample_audit_results.txt`). If your numbers are wildly off, the restore is incomplete.

---

## Sequencing note

This runbook is a one-time setup before Phase 1 migration work begins in the next session. The expected handoff:

- **You** (the user): work through the runbook against prod and your local Docker, producing a dev DB that mirrors prod and a freshly-applied `strabosamples` schema.
- **Next session**: author the migration scripts (`samples/migration` sub-branch — see §10 of the design doc), run them against this dev clone, and verify the lossless round-trip exit criterion.

No code in the repo depends on the dev clone existing — the DDL artifact and the JSON schema are self-contained and reviewable on their own.
