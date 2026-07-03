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

## Open findings (as of the audit that added these suites)

These are surfaced by the probe suites; none were fixed by this test work.

**A. `update_collaboration_level.php` has no caller authorization.** It runs
`UPDATE collaborators SET collaboration_level=$l WHERE uuid=$u` with no check
that the caller owns the project, and does not validate `$l`. A collaborator
knows their own uuid (it is in their invite link), so an edit/readonly
collaborator can set their own level to `owner` and gain `canEditProjectMetadata`
rights, or set an arbitrary string. GET-based, no CSRF token.

**B. `delete_collaborator.php` / `deny_collaboration.php` have no caller
authorization.** Both mutate `collaborators` by `uuid` alone; any authenticated
user who knows a uuid can disable a collaborator or reject an invite.

Fix pattern for A/B: scope the UPDATE by `project_owner_user_pkey = <session
userpkey>` (and for deny, by the invitee), exactly as `halt_collaboration.php`
already does. `halt_collaboration.php` is the passing positive control.

**C. MoveSpot skips the source-dataset permission check for collaborators.**
`MoveSpotToDatasetController` resolves the spot's current dataset with
`getDatasetId()`, which filters by the requesting user's `userpkey` *before* the
effective-owner swap. A collaborator's spots are stored under the project
owner's pkey, so the lookup returns nothing and the source check is skipped —
an editor can move another editor's spot out of a dataset they cannot edit
(demonstrated: HTTP 201). The owner is correctly blocked (positive control),
because owner-project data is under the owner's pkey. Fix: resolve the source
via `getDatasetContext()` / `getDatasetOwnerInfo()` (owner-agnostic), like the
target dataset already is.

**D. "Halted collaborator keeps readonly" is unreachable via the UI.**
`getProjectContext()` only maps a disabled collaborator to `readonly` when
`accepted=true`, but every website page that disables a collaborator
(`halt`/`delete`/`deny`) also sets `accepted=false`, which resolves to `none`
(no access). So a really-halted collaborator loses read access entirely, not
the readonly the design comments intend. `run_tests.php`'s fixture masks this by
seeding `accepted=true, disabled=true` — a state no page produces. Decide which
side is correct (halt should probably keep `accepted=true`) and align the code +
fixture.

## Known test-environment notes

- Basic-Auth usernames are lowercased before the users lookup
  (`db/index.php:40`), so **seed emails must be lowercase** or HTTP calls
  authenticate as nobody (pkey 0).
- A successful spot upload mirror-writes a PG `project` row (`project.user_pkey`
  FKs `users.pkey`); hermetic cleanup must delete those before deleting users.
- Spot↔dataset edges use `HAS_SPOT` in production (`addSpotToDataset` default);
  seed with `HAS_SPOT`, not `CONTAINS_SPOT`, for move/remove paths to behave.
