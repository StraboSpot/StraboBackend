# Collaboration Test Coverage & Findings

This directory tests the collaborative-project workflow (project sharing between
users, enforced by `db/services/CollaborationAuth.php`, shared by `/db/` Basic
Auth and `/jwtdb/` JWT). This file is the map of what is covered and what the
suites surfaced.

## Roles & states

- **owner** — full control of their project.
- **edit** collaborator — may create/edit datasets they created; may NOT edit
  project metadata; during active collaboration may NOT edit another user's
  dataset (creator-only rule).
- **readonly** collaborator — read only.
- **none** — non-collaborator / outsider; reads 404.
- **pending** (`accepted=false`) — resolves to `none` until accepted.
- **halted/disabled** — see finding D below.

## Suites

| Suite | Surface | What it covers |
|-------|---------|----------------|
| `setup_test_data.php` | fixture | seeds owner/editor/readonly/outsider + projects/datasets/spots for the two suites below |
| `run_tests.php` | `/db/` Basic Auth | baseline read/write matrix, halted, former-collaborator upload block (28) |
| `run_merge_tests.php` | `/db/` Basic Auth | project-upload merge semantics, per-dataset boundaries, image ownership (36) |
| `run_tests_jwt.php` | **`/jwtdb/` JWT** | the read/write matrix again through the JWT bootstrap + JWT auth gate (28) |
| `e2e_collab_lifecycle.php` | website pages (forged session) | invite→accept→downgrade→revoke→re-invite→halt end-to-end + negative-auth probes |
| `run_state_matrix_tests.php` | `/db/` + class | **two-editor** creator-only rule; pending/disabled/re-enabled state resolution (15) |
| `run_endpoint_permutation_tests.php` | `/db/` Basic Auth | `datasettimestamp`, `projecttimestamp`, `movespottodataset` gates (12) |
| `repro_*.php` | mixed | regression repros for known duplicate/timestamp bugs |
| `cleanup_*/scan_*/_*_selftest.php` | tools | production remediation tooling + its self-tests (not feature tests) |

The probe suites (`e2e_collab_lifecycle.php`, `run_endpoint_permutation_tests.php`)
split their output into **FUNCTIONAL** checks (expected to pass) and **AUTHZ
PROBES** that assert the *secure* expectation. A probe failure is a finding, not
a broken test; those suites exit non-zero while a finding stands.

## Findings (surfaced by the probe suites; FIXED on `fix/collab-authz-findings`)

The audit surfaced these; the fix branch `fix/collab-authz-findings` addresses
all four, and the probe suites now report 0 findings.

**A. `update_collaboration_level.php` had no caller authorization.** It ran
`UPDATE collaborators SET collaboration_level=$l WHERE uuid=$u` with no check
that the caller owns the project, and did not validate `$l`. A collaborator
knows their own uuid (it is in their invite link), so an edit/readonly
collaborator could set their own level to `owner` and gain
`canEditProjectMetadata` rights, or set an arbitrary string. GET-based, no CSRF
token. **Fix:** validate `$l ∈ {readonly, edit}` and scope the UPDATE by
`project_owner_user_pkey = <session userpkey>`.

**B. `delete_collaborator.php` / `deny_collaboration.php` had no caller
authorization.** Both mutated `collaborators` by `uuid` alone; any authenticated
user who knew a uuid could disable a collaborator or reject an invite. **Fix:**
`delete_collaborator` is scoped to the owner OR the collaborator themselves
(so both remove-and-leave still work); `deny_collaboration` is scoped to the
invitee. `halt_collaboration.php` was already correctly owner-scoped and is the
passing positive control.

**C. MoveSpot skipped the source-dataset permission check for collaborators.**
`MoveSpotToDatasetController` resolved the spot's current dataset with
`getDatasetId()`, which filters by the requesting user's `userpkey` *before* the
effective-owner swap. A collaborator's spots are stored under the project
owner's pkey, so the lookup returned nothing and the source check was skipped —
an editor could move another editor's spot out of a dataset they cannot edit
(demonstrated: HTTP 201). **Fix:** check the target first (to learn the
effective owner), resolve the source dataset under the effective owner, then run
the source-permission check with the requester's rights.

**D. "Halted collaborator keeps readonly" was unreachable via the UI.**
`getProjectContext()` only maps a disabled collaborator to `readonly` when
`accepted=true`, but `halt_collaboration.php` also set `accepted=false`, which
resolves to `none` (no access) — so a halted collaborator lost read access
entirely instead of dropping to readonly. **Fix:** `halt` now sets only
`disabled=TRUE` (suspend), preserving `accepted` and the level; `delete`
(revoke) remains the path that clears `accepted` and drops to `none`.

## Known test-environment notes

- Basic-Auth usernames are lowercased before the users lookup
  (`db/index.php:40`), so **seed emails must be lowercase** or HTTP calls
  authenticate as nobody (pkey 0).
- A successful spot upload mirror-writes a PG `project` row (`project.user_pkey`
  FKs `users.pkey`); hermetic cleanup must delete those before deleting users.
- Spot↔dataset edges use `HAS_SPOT` in production (`addSpotToDataset` default);
  seed with `HAS_SPOT`, not `CONTAINS_SPOT`, for move/remove paths to behave.
