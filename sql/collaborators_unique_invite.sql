-- collaborators: one row per (project, owner, invitee)
--
-- Background (2026-09-02): invite_collaborators.php does a check-then-insert
-- (count existing, else INSERT; existing rows are re-enabled by UPDATE). That
-- is correct but not race-proof: two simultaneous submits could both pass the
-- count and insert twice. This index makes the database the guarantee. Every
-- reader (my_field_data pending list, collaborate.php, CollaborationAuth,
-- StraboSearch ACL) assumes one row per triple.
--
-- Apply on prod as postgres (not strabodbuser):
--   sudo docker exec -i <postgres container> psql -U postgres -d strabospot < sql/collaborators_unique_invite.sql
--
-- STEP 1 (read-only): list existing duplicates. Must return zero rows before STEP 3.
SELECT strabo_project_id, project_owner_user_pkey, collaborator_user_pkey,
       count(*) AS copies,
       array_agg(pkey ORDER BY pkey) AS pkeys,
       array_agg(accepted ORDER BY pkey) AS accepted,
       array_agg(disabled ORDER BY pkey) AS disabled
  FROM collaborators
 GROUP BY 1, 2, 3
HAVING count(*) > 1
 ORDER BY copies DESC;

-- STEP 2 (only if STEP 1 returned rows; review them first): keep, per triple,
-- the row that is live (accepted and not disabled) if any, else the newest,
-- and delete the rest. Uncomment to run.
-- WITH ranked AS (
--   SELECT pkey,
--          row_number() OVER (
--            PARTITION BY strabo_project_id, project_owner_user_pkey, collaborator_user_pkey
--            ORDER BY (accepted AND NOT disabled) DESC, disabled ASC, created_date DESC NULLS LAST, pkey DESC
--          ) AS rn
--     FROM collaborators)
-- DELETE FROM collaborators WHERE pkey IN (SELECT pkey FROM ranked WHERE rn > 1);

-- STEP 3: the guard.
CREATE UNIQUE INDEX IF NOT EXISTS collaborators_unique_invite_idx
    ON collaborators (strabo_project_id, project_owner_user_pkey, collaborator_user_pkey);
