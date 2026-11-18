-- =============================================================================
-- File: sql/ota.sql
-- Description: StraboField over-the-air update server (/ota/, Expo Updates
--              protocol v1; docs/StraboField_Update_Server/DESIGN.md).
--
--              ota_assets          one row per stored file (content-addressed by
--                                  SHA-256; the file lives in OTA_DATA_DIR/assets)
--              ota_releases        one row per published update or directive, per
--                                  platform + runtime version + channel. body holds
--                                  the EXACT bytes that were signed by the app
--                                  developers; the server never changes them.
--              ota_release_assets  which files each update uses (served only then)
--              ota_daily_clients   one row per install per day (requests counted),
--                                  for "which builds are still in use"
--              ota_fatal_errors    expo-fatal-error reports (crash + roll back)
--
-- Apply (dev or prod), idempotent / safe to re-run:
--   cat sql/ota.sql | docker exec -i strabo-postgres psql -U postgres -d strabospot
--
-- DDL needs the superuser role on prod (-U postgres); the GRANTs at the end
-- give the web user what it needs. Apply BEFORE pulling the code that uses it.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ota_assets (
    sha256       CHAR(64)    PRIMARY KEY,               -- lowercase hex
    bytes        BIGINT      NOT NULL,
    uploaded_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS ota_releases (
    pkey             SERIAL      PRIMARY KEY,
    kind             VARCHAR(24) NOT NULL CHECK (kind IN ('update','rollBackToEmbedded')),
    update_id        UUID        UNIQUE,                -- manifest id; NULL for directives
    platform         VARCHAR(8)  NOT NULL CHECK (platform IN ('ios','android')),
    runtime_version  VARCHAR(128) NOT NULL,
    channel          VARCHAR(32) NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL,              -- manifest createdAt / directive commitTime
    body             TEXT        NOT NULL,              -- exact signed bytes
    signature        TEXT,                              -- base64 RSA-SHA256 over body; NULL = unsigned
    message          TEXT,                              -- publisher's note
    published_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    published_from   VARCHAR(64),
    withdrawn_at     TIMESTAMPTZ,
    withdrawn_note   TEXT
);
CREATE INDEX IF NOT EXISTS ota_releases_pick
    ON ota_releases (platform, runtime_version, channel, created_at DESC, pkey DESC);

CREATE TABLE IF NOT EXISTS ota_release_assets (
    release_pkey  INTEGER      NOT NULL REFERENCES ota_releases(pkey) ON DELETE CASCADE,
    sha256        CHAR(64)     NOT NULL REFERENCES ota_assets(sha256),
    ext           VARCHAR(16)  NOT NULL,                -- as in the URL, '' or '.png'
    content_type  VARCHAR(128) NOT NULL,
    is_launch     BOOLEAN      NOT NULL DEFAULT FALSE,
    PRIMARY KEY (release_pkey, sha256, ext)
);
CREATE INDEX IF NOT EXISTS ota_release_assets_file ON ota_release_assets (sha256, ext);

CREATE TABLE IF NOT EXISTS ota_daily_clients (
    day                 DATE         NOT NULL,
    client_hash         CHAR(64)     NOT NULL,          -- sha256 of eas-client-id (never stored raw)
    platform            VARCHAR(8)   NOT NULL,
    runtime_version     VARCHAR(128) NOT NULL,
    channel             VARCHAR(32)  NOT NULL,
    current_update_id   VARCHAR(64),
    embedded_update_id  VARCHAR(64),
    requests            INTEGER      NOT NULL DEFAULT 1,
    last_seen           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (day, client_hash)
);

CREATE TABLE IF NOT EXISTS ota_fatal_errors (
    pkey               SERIAL       PRIMARY KEY,
    received_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    client_hash        CHAR(64),
    platform           VARCHAR(8),
    runtime_version    VARCHAR(128),
    channel            VARCHAR(32),
    current_update_id  VARCHAR(64),
    embedded_update_id VARCHAR(64),
    error              TEXT         NOT NULL
);
CREATE INDEX IF NOT EXISTS ota_fatal_errors_when ON ota_fatal_errors (received_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON ota_assets, ota_releases, ota_release_assets,
    ota_daily_clients, ota_fatal_errors TO strabodbuser;
GRANT USAGE, SELECT ON SEQUENCE ota_releases_pkey_seq, ota_fatal_errors_pkey_seq TO strabodbuser;
