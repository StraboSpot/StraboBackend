-- microsync_chat: project chat for StraboMicro collaboration (2026-10-06).
--
-- micro_chat: one row per message. Chat is not project data (17bg g): it is
-- never in the change log, version history, as-of downloads or .smz files.
-- A deleted message keeps its row (body emptied, refs cleared) so every app
-- learns of the deletion; rev moves on insert AND on delete, so apps fetch
-- "everything since rev N" and see both. client_msg_id makes a resent
-- message (offline queue, retry after a lost reply) land once. Rows go with
-- the project (ON DELETE CASCADE at the purge, 17ad); while the project is
-- deleted the API hides them like everything else (410).
--
-- micro_chat_reads: how far each person has read in each project (17bg d),
-- shared by all their computers.
--
-- Design: StraboMicro2 repo, docs/specs/collaboration-workflow-spec-v3.md
-- 17bd-17bi.
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres.
-- Idempotent:
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/microsync_chat.sql

BEGIN;

CREATE SEQUENCE IF NOT EXISTS strabomicro.micro_chat_rev_seq;

CREATE TABLE IF NOT EXISTS strabomicro.micro_chat (
  id             bigserial PRIMARY KEY,
  project_id     integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  author_pkey    integer NOT NULL REFERENCES public.users(pkey),
  client_msg_id  uuid NOT NULL,
  body           text NOT NULL,
  refs           jsonb NOT NULL DEFAULT '[]'::jsonb,    -- [{"type":"spot"|"micrograph","id":"..."}]
  created_at     timestamptz NOT NULL DEFAULT now(),
  deleted_at     timestamptz,
  deleted_by     integer REFERENCES public.users(pkey),
  rev            bigint NOT NULL DEFAULT nextval('strabomicro.micro_chat_rev_seq'),
  UNIQUE (project_id, author_pkey, client_msg_id)
);
CREATE INDEX IF NOT EXISTS micro_chat_project_id ON strabomicro.micro_chat (project_id, id);
CREATE INDEX IF NOT EXISTS micro_chat_project_rev ON strabomicro.micro_chat (project_id, rev);
CREATE INDEX IF NOT EXISTS micro_chat_rate ON strabomicro.micro_chat (project_id, author_pkey, created_at);

CREATE TABLE IF NOT EXISTS strabomicro.micro_chat_reads (
  project_id     integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  user_pkey      integer NOT NULL REFERENCES public.users(pkey),
  last_read_id   bigint NOT NULL DEFAULT 0,
  updated_at     timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (project_id, user_pkey)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON
  strabomicro.micro_chat, strabomicro.micro_chat_reads
  TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE
  strabomicro.micro_chat_id_seq, strabomicro.micro_chat_rev_seq
  TO strabodbuser;
GRANT SELECT ON strabomicro.micro_chat, strabomicro.micro_chat_reads TO readonly;

COMMIT;
