-- microsync_join_links: join links (QR code + short address) and the owner's
-- waiting list for a synced StraboMicro project (2026-10-30).
--
-- micro_join_links: at most one open link per project (revoked_at IS NULL);
-- making a new one revokes the old one. pdf_title and pdf_details are what the
-- owner last entered for the printable page.
--
-- micro_join_requests: one row per person per project. Separate from
-- micro_members so waiting people never reach the member list or older apps;
-- approving makes them an active member.
--
-- Design: StraboMicro2 repo, docs/specs/collaboration-workflow-spec-v3.md.
--
-- Deploy: apply BEFORE pulling the code that references it, as postgres.
-- Idempotent:

CREATE TABLE IF NOT EXISTS strabomicro.micro_join_links (
  id          serial PRIMARY KEY,
  project_id  integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  code        varchar NOT NULL UNIQUE,
  role        varchar NOT NULL CHECK (role IN ('editor','contributor','viewer')),
  created_by  integer NOT NULL REFERENCES public.users(pkey),
  created_at  timestamptz NOT NULL DEFAULT now(),
  expires_at  timestamptz NOT NULL,
  revoked_at  timestamptz,
  pdf_title   varchar,
  pdf_details varchar
);
CREATE UNIQUE INDEX IF NOT EXISTS micro_join_links_one_open
  ON strabomicro.micro_join_links(project_id) WHERE revoked_at IS NULL;

CREATE TABLE IF NOT EXISTS strabomicro.micro_join_requests (
  id           serial PRIMARY KEY,
  project_id   integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  link_id      integer NOT NULL REFERENCES strabomicro.micro_join_links(id) ON DELETE CASCADE,
  user_pkey    integer NOT NULL REFERENCES public.users(pkey),
  state        varchar NOT NULL CHECK (state IN ('requested','approved','denied')),
  requested_at timestamptz NOT NULL DEFAULT now(),
  decided_at   timestamptz,
  decided_by   integer REFERENCES public.users(pkey),
  UNIQUE (project_id, user_pkey)
);
CREATE INDEX IF NOT EXISTS micro_join_requests_waiting
  ON strabomicro.micro_join_requests(project_id) WHERE state = 'requested';

GRANT SELECT, INSERT, UPDATE, DELETE ON strabomicro.micro_join_links, strabomicro.micro_join_requests TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE strabomicro.micro_join_links_id_seq, strabomicro.micro_join_requests_id_seq TO strabodbuser;
GRANT SELECT ON strabomicro.micro_join_links, strabomicro.micro_join_requests TO readonly;
