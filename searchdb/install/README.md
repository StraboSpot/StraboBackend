# StraboSearch — Phase 2 schema install

First sub-branch of Phase 2 ("Foundation: index + extraction") per
`docs/StraboSearch/MASTER_PLAN.md`. Creates the `strabosearch` PostgreSQL
schema, the two primary search tables (`item_hit` + `image_hit`), the
vocab / facet-count / saved-search / sync-state supporting tables, and the
`collaborators` ACL index. Driven by §5.1 + §5.5.2 of
`docs/StraboSearch/DESIGN_PROPOSAL.md` (SIGNED OFF v0.7 2026-06-16).

## Files

| File | Purpose |
|---|---|
| `01_schema.sql` | `CREATE SCHEMA strabosearch` + grants |
| `02_item_hit.sql` | `strabosearch.item_hit` (per-item rows, Projects pathway) |
| `03_image_hit.sql` | `strabosearch.image_hit` (per-image rows, Images pathway) |
| `04_vocab_tables.sql` | `vocab_image_type` + `vocab_rock_type` + `vocab_facet_counts` |
| `05_saved_search.sql` | `saved_search` (replaces legacy `public.fullsearches`) |
| `06_sync_state.sql` | `sync_state` (one row per source subsystem, seeded) |
| `07_collaborators_acl_index.sql` | partial composite on `public.collaborators` (adjacent task, §5.7 Q3) |
| `install.php` | runner — drops spike, applies the SQL files in order |
| `../verify_schema.php` | post-install integrity checker |

Indexes for `item_hit` / `image_hit` are deliberately NOT created here —
per §5.1.5 they're applied post-backfill in a single REINDEX pass to avoid
per-row write amplification. They ship with the extractor sub-branches.

## Permissions — read this first

Two of the DDL steps require the postgres **superuser**:

- `01_schema.sql` — `CREATE SCHEMA strabosearch` needs `CREATE` on the
  `strabospot` database, which `strabodbuser` does not have.
- `07_collaborators_acl_index.sql` — `CREATE INDEX` on `public.collaborators`
  requires table ownership, which strabodbuser also does not have.

Both files transfer ownership / grants back to `strabodbuser` so the rest
of the schema (tables 02–06) can be managed via the app credentials.

## Usage

### First install — canonical superuser pipe

```bash
cat searchdb/install/0?_*.sql | docker exec -i strabo-postgres \
    psql -U postgres -d strabospot
```

This applies every DDL file in numeric order, idempotently. Mirrors the
samples runbook's Step 3 fallback pattern.

### Subsequent runs — install.php

```bash
# Drops the spike, checks prereqs, re-applies 02–06 (idempotent).
docker exec strabo-php php /srv/app/www/searchdb/install/install.php

# Print the plan without executing.
docker exec strabo-php php /srv/app/www/searchdb/install/install.php --dry-run

# Post-install verification (independent of install.php).
docker exec strabo-php php /srv/app/www/searchdb/verify_schema.php
```

`install.php` is the **spike-teardown + guard + table-level re-apply** helper.
It does NOT attempt 01 or 07 — those need the superuser pipe above.

## What install.php does

1. **Drops `strabosearch_spike`** via `searchdb/spike/spike_teardown.sql`
   (per §5.7 Q4 — Phase 0.3's scratch schema is no longer needed; the spike
   transferred ownership to strabodbuser at install, so this works).
2. **Prerequisite check** — confirms `strabosearch` schema +
   `collaborators_search_acl_idx` are present. If either is missing,
   refuses to proceed and emits the superuser-pipe command above.
3. **Re-applies 02–06** in numeric order. All `CREATE ... IF NOT EXISTS`,
   so re-runs are no-ops. This is the path future additions to the schema
   take.
4. **Prints a one-line summary per step** + table count + seed-row count.

## Verifier

`searchdb/verify_schema.php` reads `information_schema` + `pg_indexes` and
checks:

- `strabosearch` schema exists
- All seven expected tables exist with their required columns + types
- `UNIQUE` constraints on `item_hit.item_hit_fanout_uq` +
  `image_hit.image_hit_identity_uq` exist
- `sync_state` carries exactly the four seed rows
- `collaborators_search_acl_idx` exists on `public.collaborators`

Exit non-zero on any drift. Safe to re-run; reads only.
