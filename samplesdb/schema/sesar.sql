-- =============================================================================
-- File: samplesdb/schema/sesar.sql
-- Description: StraboSamples IGSN integration with SESAR (geosamples.org).
--              Design: docs/StraboSamples_IGSN_Feature_Request/
--              IGSN_Design_Decisions.md (D1-D10).
--
--              sesar_connections   one row per (user, SESAR environment): the
--                                  user's SESAR JWT pair, ENCRYPTED with
--                                  $sesar_token_key (libsodium secretbox), plus
--                                  the SESAR identity and cached SESAR codes.
--              sesar_registrations one row per sample StraboSpot minted or
--                                  linked at SESAR (D3): IGSN, environment,
--                                  lifecycle state, last SESAR snapshot (D5)
--                                  and the fingerprint of the last payload
--                                  sent (D6). Rows are history: a confirmed
--                                  deactivation keeps its row (active=FALSE).
--              sesar_vocab_cache   SESAR controlled vocabularies (object types,
--                                  material types), refreshed from the public
--                                  vocab endpoints.
--              sesar_onboarding    per (user, environment) progress toward a
--                                  usable connection (account, API permission,
--                                  SESAR code) + the in-app access request.
--
--              No FK from sesar_registrations to samples on purpose: deleting
--              a sample must NOT delete the record that its IGSN is still live
--              at SESAR (D7), and the launch cleanup (D3) needs sandbox rows
--              even for samples deleted during testing.
--
-- Apply (dev or prod), idempotent / safe to re-run:
--   cat samplesdb/schema/sesar.sql | docker exec -i strabo-postgres \
--       psql -U postgres -d strabospot -v ON_ERROR_STOP=1
--
-- DDL needs the superuser role on prod (-U postgres). Apply BEFORE pulling the
-- code that uses it (D10 rollout step 1).
--
-- Rollback: DROP TABLE strabosamples.sesar_registrations,
--           strabosamples.sesar_connections, strabosamples.sesar_vocab_cache,
--           strabosamples.sesar_onboarding;
-- (only after the launch cleanup has removed sandbox IGSNs from the spine,
-- since these rows are the only record of which IGSNs are sandbox ones).
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS strabosamples.sesar_connections (
    pkey                SERIAL      PRIMARY KEY,
    userpkey            INTEGER     NOT NULL REFERENCES users(pkey),
    environment         TEXT        NOT NULL CHECK (environment IN ('sandbox', 'production')),
    connection_name     TEXT        NOT NULL,           -- the {connection} claim on the JWTs
    orcid               TEXT,                           -- ORCID iD the tokens were issued for
    sesar_user          JSONB,                          -- GET /api/auth/user/ at connect time
    refresh_token_enc   TEXT,                           -- secretbox("v1:" base64); NULL = disconnected
    refresh_expires_at  TIMESTAMPTZ,
    access_token_enc    TEXT,                           -- cached 1-day access token (avoids a refresh
    access_expires_at   TIMESTAMPTZ,                    -- rotation on every request)
    sesar_codes         JSONB,                          -- codes the user may register under (cache)
    codes_fetched_at    TIMESTAMPTZ,
    last_sesar_code     TEXT,                           -- D2: remembered SESAR code choice
    status              TEXT        NOT NULL DEFAULT 'connected'
                                    CHECK (status IN ('connected', 'needs_reconnect', 'disconnected')),
    last_error          TEXT,
    connected_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (userpkey, environment)
);

CREATE TABLE IF NOT EXISTS strabosamples.sesar_registrations (
    pkey                    BIGSERIAL   PRIMARY KEY,
    sample_id               TEXT        NOT NULL,
    sample_userpkey         INTEGER     NOT NULL,
    environment             TEXT        NOT NULL CHECK (environment IN ('sandbox', 'production')),

    -- NULL only while state = 'minting' (POST sent, outcome not yet known).
    igsn                    TEXT,
    sesar_code              TEXT,

    -- minted: registered through StraboSpot; linked: adopted by a pull (D5).
    origin                  TEXT        NOT NULL CHECK (origin IN ('minted', 'linked')),
    -- managed: the connected SESAR account may edit it; readonly: it may not.
    access                  TEXT        NOT NULL DEFAULT 'managed' CHECK (access IN ('managed', 'readonly')),

    -- minting: in flight or unknown outcome (retry searches SESAR by
    -- external_sample_id before POSTing again, D3); active; deactivation_requested; deactivated.
    state                   TEXT        NOT NULL
                                        CHECK (state IN ('minting', 'active', 'deactivation_requested', 'deactivated')),
    active                  BOOLEAN     NOT NULL DEFAULT TRUE,   -- FALSE once deactivated (history row)

    sesar_status            TEXT,       -- SESAR metadata_store_status (draft, pending-review, registered-datacite...)
    snapshot                JSONB,      -- last SESAR record seen (D5 "SESAR record" card, D9 reports)
    snapshot_at             TIMESTAMPTZ,
    sesar_last_update       TIMESTAMPTZ, -- SESAR last_update_date at snapshot time (D6 pull-first check)
    pushed_fingerprint      TEXT,        -- sha256 of the last payload sent (D6 change detection)
    pushed_at               TIMESTAMPTZ,

    deactivation_reason     TEXT,
    deactivation_detail     TEXT,
    deactivation_requested_at TIMESTAMPTZ,
    deactivated_at          TIMESTAMPTZ,

    created_by              INTEGER     NOT NULL REFERENCES users(pkey),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT sesar_reg_igsn_chk CHECK (state = 'minting' OR igsn IS NOT NULL),
    CONSTRAINT sesar_reg_active_chk CHECK (active = (state <> 'deactivated'))
);

-- Phase 4 (minting, 2026-09-27):
--   sesar_sample_id      SESAR's own integer id. The detail GET lacks it but
--                        related-resources link-samples needs it, so it is
--                        stored at mint time (from the POST answer or a list
--                        lookup by external_sample_id).
--   related_resource_id  the "StraboSpot sample page" related resource
--                        linked at mint (NULL = none).
ALTER TABLE strabosamples.sesar_registrations ADD COLUMN IF NOT EXISTS sesar_sample_id     BIGINT;
ALTER TABLE strabosamples.sesar_registrations ADD COLUMN IF NOT EXISTS related_resource_id BIGINT;

-- Phase 5 (pull, 2026-09-27):
--   field_flags  differences between the SESAR record and the linked Field
--                spot that a pull shows but never applies (D5 option A:
--                location, material, purpose), as of the last pull:
--                [{field, current, sesar, distance_m?}]. NULL = never
--                pulled or not Field-linked; [] = no differences.
ALTER TABLE strabosamples.sesar_registrations ADD COLUMN IF NOT EXISTS field_flags JSONB;

-- One live registration per sample per environment (D3 duplicate guard).
CREATE UNIQUE INDEX IF NOT EXISTS idx_sesar_reg_sample_live
    ON strabosamples.sesar_registrations (sample_id, sample_userpkey, environment)
    WHERE active;
CREATE INDEX IF NOT EXISTS idx_sesar_reg_owner
    ON strabosamples.sesar_registrations (sample_userpkey, environment);
CREATE INDEX IF NOT EXISTS idx_sesar_reg_igsn
    ON strabosamples.sesar_registrations (igsn) WHERE igsn IS NOT NULL;

CREATE TABLE IF NOT EXISTS strabosamples.sesar_vocab_cache (
    environment         TEXT        NOT NULL CHECK (environment IN ('sandbox', 'production')),
    vocab               TEXT        NOT NULL,           -- object-types, material-types
    data                JSONB       NOT NULL,
    fetched_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (environment, vocab)
);

-- Getting a user from "never heard of SESAR" to "connected with a SESAR code"
-- (D1 addition, 2026-09-26). One row per (user, SESAR environment).
--   id_token_enc   the ORCID id_token verified by our callback, ENCRYPTED like
--                  the SESAR tokens. Kept for its own 24 h life so "Check
--                  again" after flipping something at SESAR needs no new ORCID
--                  popup; cleared once the SESAR connection exists.
--   stage          last answer from SESAR: no_account (ORCID unknown to
--                  SESAR), no_permission (API access not granted yet),
--                  no_code (connected, no SESAR code), connected.
--   access_*       the in-app API access request (POST /api/api-access-request/),
--                  so the page can say "requested on <date>".
--   institution / position_role  typed once for that request, remembered.
CREATE TABLE IF NOT EXISTS strabosamples.sesar_onboarding (
    userpkey              INTEGER     NOT NULL REFERENCES users(pkey),
    environment           TEXT        NOT NULL CHECK (environment IN ('sandbox', 'production')),
    orcid                 TEXT,
    id_token_enc          TEXT,
    id_token_expires_at   TIMESTAMPTZ,
    stage                 TEXT        CHECK (stage IN ('no_account', 'no_permission', 'no_code', 'connected')),
    checked_at            TIMESTAMPTZ,
    last_error            TEXT,       -- SESAR unreachable etc. (stage kept as it was)
    access_requested_at   TIMESTAMPTZ,
    access_request_error  TEXT,       -- in-app request failed: page falls back to SESAR's own form
    institution           TEXT,
    position_role         TEXT,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (userpkey, environment)
);

-- ---------------------------------------------------------------------------
-- Privileges: created by the superuser, used by the web role. The schema's
-- default ACL already grants these on dev; explicit here so prod does not
-- depend on it. (GRANT is idempotent.)
-- ---------------------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE ON strabosamples.sesar_connections   TO strabodbuser;
GRANT SELECT, INSERT, UPDATE, DELETE ON strabosamples.sesar_registrations TO strabodbuser;
GRANT SELECT, INSERT, UPDATE, DELETE ON strabosamples.sesar_vocab_cache   TO strabodbuser;
GRANT SELECT, INSERT, UPDATE, DELETE ON strabosamples.sesar_onboarding   TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE strabosamples.sesar_connections_pkey_seq   TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE strabosamples.sesar_registrations_pkey_seq TO strabodbuser;

COMMIT;
