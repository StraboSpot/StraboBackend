-- microsync_phase0: StraboMicro collaboration, Phase 0 server foundation
-- (2026-09-30). Entity store, change log, membership, presence, blobs,
-- resumable uploads, parked pushes. Read by the /microsync/v1/ API.
--
-- Design: StraboMicro2 repo, docs/specs/collaboration-phase0-design.md §3.
-- Inert on its own: nothing reads or writes these tables until the
-- /microsync/v1/ API is enabled (MICROSYNC_ENABLED in config.inc.php) and a
-- client uses it. The new micro_projectmetadata columns default to the values
-- every existing project already has ('legacy', 'ready', 0, NULL).
--
-- Deletion: every new table cascades from micro_projectmetadata, so deleting
-- a project row (deleteProject) removes its entities, log, and blobs rows too.
--
-- Deploy: apply BEFORE pulling the code that references it. Must run as
-- -U postgres (micro_projectmetadata is owned by postgres). Idempotent:
--   docker exec -i strabo-postgres psql -U postgres -d strabospot \
--     -v ON_ERROR_STOP=1 < sql/microsync_phase0.sql
-- (Applied to dev 2026-09-30.)

BEGIN;

-- 1. Existing table: new columns
ALTER TABLE strabomicro.micro_projectmetadata
  ADD COLUMN IF NOT EXISTS sync_format        varchar     NOT NULL DEFAULT 'legacy', -- 'legacy' | 'entity'
  ADD COLUMN IF NOT EXISTS sync_state         varchar     NOT NULL DEFAULT 'ready',  -- 'initializing' | 'ready'
  ADD COLUMN IF NOT EXISTS head_seq           bigint      NOT NULL DEFAULT 0,        -- last micro_changes.seq for this project
  ADD COLUMN IF NOT EXISTS views_dirty_since  timestamptz,                           -- set by push; cleared by the worker
  ADD COLUMN IF NOT EXISTS views_built_at     timestamptz;

-- 2. Membership (P0-3)
CREATE TABLE IF NOT EXISTS strabomicro.micro_members (
  id            serial PRIMARY KEY,
  project_id    integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  user_pkey     integer NOT NULL REFERENCES public.users(pkey),
  role          varchar NOT NULL CHECK (role IN ('owner','editor','contributor','viewer')),
  state         varchar NOT NULL CHECK (state IN ('invited','active','declined','removed')),
  invited_by    integer REFERENCES public.users(pkey),
  invited_at    timestamptz NOT NULL DEFAULT now(),
  responded_at  timestamptz,
  removed_at    timestamptz,
  invite_token  varchar,                 -- email accept link
  transfer_to   integer REFERENCES public.users(pkey), -- pending ownership transfer (owner row only)
  UNIQUE (project_id, user_pkey)
);
CREATE UNIQUE INDEX IF NOT EXISTS micro_members_one_owner
  ON strabomicro.micro_members(project_id) WHERE role = 'owner' AND state = 'active';
CREATE INDEX IF NOT EXISTS micro_members_user_active
  ON strabomicro.micro_members(user_pkey) WHERE state = 'active';

-- 3. Entities: the source of truth for synced projects (P0-1, P0-5)
CREATE TABLE IF NOT EXISTS strabomicro.micro_entities (
  project_id     integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  entity_type    varchar NOT NULL CHECK (entity_type IN
                   ('project','dataset','sample','micrograph','spot','tag','group','preset')),
  entity_id      varchar NOT NULL,
  parent_type    varchar,                 -- NULL for the project entity
  parent_id      varchar,
  body           jsonb   NOT NULL,        -- entity minus child collections and per-user fields
  child_order    jsonb,                   -- e.g. {"samples":[ids]}, {"micrographs":[ids]}, {"spots":[ids]}
  version        integer NOT NULL DEFAULT 1,
  created_by     integer REFERENCES public.users(pkey),
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_by     integer REFERENCES public.users(pkey),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  deleted_at     timestamptz,             -- tombstone
  deleted_by     integer REFERENCES public.users(pkey),
  deleted_root   varchar,                 -- 'type:id' of the entity whose delete cascaded here
  PRIMARY KEY (project_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS micro_entities_parent
  ON strabomicro.micro_entities(project_id, parent_type, parent_id);

-- 4. Change log (kept forever)
CREATE TABLE IF NOT EXISTS strabomicro.micro_changes (
  seq            bigserial PRIMARY KEY,
  project_id     integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  push_id        uuid,                    -- groups one push; NULL for server jobs
  entity_type    varchar NOT NULL,
  entity_id      varchar NOT NULL,
  op             varchar NOT NULL CHECK (op IN
                   ('create','update','delete','restore','import','replace_project')),
  version        integer NOT NULL,        -- entity version after this change
  changed_paths  text[],
  before         jsonb,
  after          jsonb,
  user_pkey      integer NOT NULL REFERENCES public.users(pkey),
  on_behalf_of   integer REFERENCES public.users(pkey), -- parked push accepted by owner
  at             timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS micro_changes_project_seq ON strabomicro.micro_changes(project_id, seq);
CREATE INDEX IF NOT EXISTS micro_changes_entity
  ON strabomicro.micro_changes(project_id, entity_type, entity_id, seq);

-- 5. Push idempotency: a retried push returns the stored result, never re-applies
CREATE TABLE IF NOT EXISTS strabomicro.micro_pushes (
  push_id     uuid PRIMARY KEY,
  project_id  integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  user_pkey   integer NOT NULL REFERENCES public.users(pkey),
  client_id   varchar,                    -- installation id
  at          timestamptz NOT NULL DEFAULT now(),
  result      jsonb NOT NULL
);

-- 6. Presence
CREATE TABLE IF NOT EXISTS strabomicro.micro_presence (
  project_id     integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  user_pkey      integer NOT NULL REFERENCES public.users(pkey),
  client_id      varchar NOT NULL,
  viewing_type   varchar,                 -- 'micrograph' etc.
  viewing_id     varchar,
  state          varchar NOT NULL DEFAULT 'active' CHECK (state IN ('active','away')),
  last_seen      timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (project_id, user_pkey, client_id)
);

-- 7. Blobs and viewer assets (P0-4, P0-13, P0-16)
CREATE TABLE IF NOT EXISTS strabomicro.micro_blobs (
  project_id   integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  sha256       char(64) NOT NULL,
  size         bigint   NOT NULL,
  kind         varchar  NOT NULL CHECK (kind IN ('image','tiles','tiles_affine','thumbnail','associated_file')),
  uploaded_by  integer REFERENCES public.users(pkey),
  uploaded_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (project_id, sha256)
);
CREATE TABLE IF NOT EXISTS strabomicro.micro_blob_refs (
  project_id   integer NOT NULL,
  sha256       char(64) NOT NULL,
  entity_type  varchar NOT NULL,
  entity_id    varchar NOT NULL,
  role         varchar NOT NULL,          -- 'image', 'tiles', 'tiles_affine', 'thumbnail', 'associated_file:<name>'
  PRIMARY KEY (project_id, entity_type, entity_id, role),
  FOREIGN KEY (project_id, sha256) REFERENCES strabomicro.micro_blobs(project_id, sha256) ON DELETE CASCADE
);

-- 8. Resumable uploads (P0-8); staging files under straboMicroFiles/_staging/<upload_id>
CREATE TABLE IF NOT EXISTS strabomicro.micro_uploads (
  upload_id    uuid PRIMARY KEY,
  project_id   integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  user_pkey    integer NOT NULL REFERENCES public.users(pkey),
  sha256       char(64) NOT NULL,
  size         bigint   NOT NULL,
  kind         varchar  NOT NULL,
  chunk_size   integer  NOT NULL,
  received     bigint   NOT NULL DEFAULT 0, -- contiguous bytes received (chunks are sequential)
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (project_id, user_pkey, sha256)
);

-- 9. Parked pushes; endpoints arrive in Phase 2, table ships now
CREATE TABLE IF NOT EXISTS strabomicro.micro_parked_pushes (
  id            serial PRIMARY KEY,
  project_id    integer NOT NULL REFERENCES strabomicro.micro_projectmetadata(id) ON DELETE CASCADE,
  user_pkey     integer NOT NULL REFERENCES public.users(pkey),
  parked_at     timestamptz NOT NULL DEFAULT now(),
  payload       jsonb   NOT NULL,         -- the rejected push, verbatim
  status        varchar NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending','accepted','discarded','partial')),
  reviewed_by   integer REFERENCES public.users(pkey),
  reviewed_at   timestamptz,
  review        jsonb                     -- per-entity decisions
);

GRANT SELECT, INSERT, UPDATE, DELETE ON
  strabomicro.micro_members, strabomicro.micro_entities, strabomicro.micro_changes,
  strabomicro.micro_pushes, strabomicro.micro_presence, strabomicro.micro_blobs,
  strabomicro.micro_blob_refs, strabomicro.micro_uploads, strabomicro.micro_parked_pushes
  TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE
  strabomicro.micro_members_id_seq, strabomicro.micro_changes_seq_seq,
  strabomicro.micro_parked_pushes_id_seq
  TO strabodbuser;
GRANT SELECT ON
  strabomicro.micro_members, strabomicro.micro_entities, strabomicro.micro_changes,
  strabomicro.micro_blobs, strabomicro.micro_blob_refs
  TO readonly;

COMMIT;
