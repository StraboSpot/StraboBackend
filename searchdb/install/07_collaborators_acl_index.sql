-- StraboSearch Phase 2 — collaborators ACL index (adjacent install task).
--
-- Per §5.5.2 + §5.7 Q3 of DESIGN_PROPOSAL.md.
--
-- Supports the live-JOIN ACL pattern in §5.5.2:
--
--   WHERE project_ispublic = true
--      OR project_userpkey = $searcher_pkey
--      OR EXISTS (SELECT 1 FROM collaborators
--                 WHERE strabo_project_id       = item_hit.project_id
--                   AND project_owner_user_pkey = item_hit.project_userpkey
--                   AND collaborator_user_pkey  = $searcher_pkey
--                   AND accepted = true
--                   AND disabled = false)
--
-- Prod `collaborators` carries 25 rows total (17 accepted), so the JOIN is
-- sub-millisecond unindexed. This partial composite future-proofs 100×-scale
-- growth at negligible cost today.
--
-- This index lives on the existing `public.collaborators` table, not in the
-- `strabosearch` schema. install.php --reset drops + recreates it as part
-- of the Phase 2 reset cycle.

CREATE INDEX IF NOT EXISTS collaborators_search_acl_idx
    ON public.collaborators (collaborator_user_pkey, strabo_project_id, project_owner_user_pkey)
    WHERE accepted = true AND disabled = false;
