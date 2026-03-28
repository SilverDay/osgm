-- =============================================================================
-- OSGridManager (OGM) Database Schema
-- Version: 1.0
-- Engine: MariaDB 10.6+
-- Charset: utf8mb4
-- Notes:
--   - All OGM tables prefixed with ogm_
--   - OpenSim tables are in a separate database (not modified here)
--   - UUIDs stored as CHAR(36) to match OpenSim convention
--   - Timestamps stored as BIGINT (Unix epoch) to match OpenSim convention
--     where appropriate, or DATETIME for OGM-native records
-- =============================================================================

CREATE DATABASE IF NOT EXISTS osgridmanager
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE osgridmanager;

-- =============================================================================
-- SESSIONS
-- session_data: serialised PHP array for CSRF tokens and miscellaneous state.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_sessions (
    session_id      CHAR(64)        NOT NULL,           -- HMAC-SHA256 hex
    user_uuid       CHAR(36)        NOT NULL,
    ip_address      VARCHAR(45)     NOT NULL,           -- IPv4 or IPv6
    user_agent_hash CHAR(64)        NOT NULL,           -- SHA256 of UA string
    role            ENUM('user','admin') NOT NULL DEFAULT 'user',
    session_data    TEXT            NULL,               -- serialised PHP array
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME        NOT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (session_id),
    INDEX idx_user_uuid (user_uuid),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- API TOKENS (for LSL inworld API calls)
-- =============================================================================

-- Region tokens: authorize region-level API calls (economy hooks, search)
CREATE TABLE IF NOT EXISTS ogm_region_tokens (
    token_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    region_uuid     CHAR(36)        NOT NULL UNIQUE,
    region_name     VARCHAR(255)    NOT NULL,
    token_hash      CHAR(64)        NOT NULL,           -- SHA256 of actual token
    created_by      CHAR(36)        NOT NULL,           -- admin user UUID
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used       DATETIME        NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (token_id),
    INDEX idx_region_uuid (region_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User tokens: authorize per-user inworld actions (messaging, currency transfer)
CREATE TABLE IF NOT EXISTS ogm_user_tokens (
    token_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uuid       CHAR(36)        NOT NULL,
    token_hash      CHAR(64)        NOT NULL,           -- SHA256 of actual token
    scope           SET('profile','economy','messaging','search') NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME        NOT NULL,
    last_used       DATETIME        NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (token_id),
    INDEX idx_user_uuid (user_uuid),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- ECONOMY / INWORLD CURRENCY
-- =============================================================================

-- Master ledger: every balance-affecting event recorded here
CREATE TABLE IF NOT EXISTS ogm_economy_ledger (
    tx_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tx_uuid         CHAR(36)        NOT NULL UNIQUE,    -- generated UUID per tx
    from_uuid       CHAR(36)        NOT NULL,           -- sender (or 'SYSTEM' for grants)
    to_uuid         CHAR(36)        NOT NULL,           -- recipient
    amount          INT UNSIGNED    NOT NULL,           -- always positive; direction via from/to
    tx_type         ENUM(
                      'transfer',      -- user-to-user transfer
                      'purchase',      -- inworld object purchase
                      'stipend',       -- admin grant / stipend
                      'fee',           -- system fee deducted
                      'refund',        -- refund of a previous tx
                      'adjustment'     -- admin manual correction
                    ) NOT NULL,
    description     VARCHAR(512)    NOT NULL DEFAULT '',
    object_uuid     CHAR(36)        NULL,               -- inworld object if relevant
    region_uuid     CHAR(36)        NULL,               -- region where tx occurred
    status          ENUM('pending','confirmed','failed','reversed') NOT NULL DEFAULT 'confirmed',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    DATETIME        NULL,
    metadata        JSON            NULL,               -- extensible, not used in queries
    PRIMARY KEY (tx_id),
    INDEX idx_from (from_uuid),
    INDEX idx_to (to_uuid),
    INDEX idx_type (tx_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Current balances (derived from ledger, maintained as materialized cache)
CREATE TABLE IF NOT EXISTS ogm_economy_balances (
    user_uuid       CHAR(36)        NOT NULL,
    balance         INT UNSIGNED    NOT NULL DEFAULT 0,
    last_tx_id      BIGINT UNSIGNED NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pending/unconfirmed inworld purchase holds
CREATE TABLE IF NOT EXISTS ogm_economy_holds (
    hold_id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uuid       CHAR(36)        NOT NULL,
    amount          INT UNSIGNED    NOT NULL,
    hold_reason     VARCHAR(255)    NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME        NOT NULL,
    released        TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (hold_id),
    INDEX idx_user (user_uuid),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- MESSAGING (Grid-internal, not email)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_messages (
    msg_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_uuid           CHAR(36)        NOT NULL,
    to_uuid             CHAR(36)        NOT NULL,
    subject             VARCHAR(255)    NOT NULL DEFAULT '',
    body                TEXT            NOT NULL,
    reply_to_msg_id     BIGINT UNSIGNED NULL,               -- reply chain support
    sent_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at             DATETIME        NULL,
    deleted_by_sender   TINYINT(1)      NOT NULL DEFAULT 0,
    deleted_by_recipient TINYINT(1)     NOT NULL DEFAULT 0,
    source              ENUM('web','inworld') NOT NULL DEFAULT 'web',
    PRIMARY KEY (msg_id),
    INDEX idx_from (from_uuid),
    INDEX idx_to (to_uuid),
    INDEX idx_sent (sent_at),
    INDEX idx_reply (reply_to_msg_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications (system events pushed to users)
CREATE TABLE IF NOT EXISTS ogm_notifications (
    notif_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_uuid       CHAR(36)        NOT NULL,
    type            ENUM(
                      'money_received',
                      'money_sent',
                      'message_received',
                      'region_event',
                      'system',
                      'admin'
                    ) NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    body            VARCHAR(1024)   NOT NULL,
    read_at         DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metadata        JSON            NULL,
    PRIMARY KEY (notif_id),
    INDEX idx_user (user_uuid),
    INDEX idx_read (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- PROFILES (OGM-managed extensions to OpenSim profile data)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_profiles (
    user_uuid       CHAR(36)        NOT NULL,
    display_name    VARCHAR(64)     NULL,               -- optional nickname
    bio             TEXT            NULL,               -- freeform biography
    website         VARCHAR(512)    NULL,
    avatar_pic_url  VARCHAR(512)    NULL,               -- web-hosted image URL
    show_online     TINYINT(1)      NOT NULL DEFAULT 1,
    show_in_search  TINYINT(1)      NOT NULL DEFAULT 1,
    language        CHAR(5)         NOT NULL DEFAULT 'en',
    timezone        VARCHAR(64)     NOT NULL DEFAULT 'UTC',
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- REGIONS (OGM management layer over OpenSim Regions table)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_regions (
    region_uuid     CHAR(36)        NOT NULL,
    notes           TEXT            NULL,               -- admin notes
    web_url         VARCHAR(512)    NULL,               -- optional website for region
    is_featured     TINYINT(1)      NOT NULL DEFAULT 0,
    access_level    ENUM('public','private','hypergrid_allowed') NOT NULL DEFAULT 'public',
    allow_hypergrid TINYINT(1)      NOT NULL DEFAULT 1,
    updated_by      CHAR(36)        NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (region_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- HYPERGRID ACCESS CONTROL
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_hypergrid_acl (
    acl_id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    grid_uri        VARCHAR(512)    NOT NULL,           -- e.g. hgrid.example.org:8002
    action          ENUM('allow','deny') NOT NULL DEFAULT 'allow',
    notes           VARCHAR(512)    NULL,
    created_by      CHAR(36)        NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (acl_id),
    INDEX idx_grid_uri (grid_uri(128))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ogm_hypergrid_region_acl (
    acl_id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    region_uuid     CHAR(36)        NOT NULL,
    grid_uri        VARCHAR(512)    NOT NULL,
    action          ENUM('allow','deny') NOT NULL DEFAULT 'allow',
    notes           VARCHAR(512)    NULL,
    created_by      CHAR(36)        NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (acl_id),
    INDEX idx_region (region_uuid),
    INDEX idx_grid_uri (grid_uri(128))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- SEARCH INDEX (cached for performance, rebuilt from OpenSim tables)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_search_cache (
    entry_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    entry_type      ENUM('region','user','classifieds','event') NOT NULL,
    entry_uuid      CHAR(36)        NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    description     TEXT            NULL,
    keywords        VARCHAR(512)    NULL,               -- space-separated
    region_uuid     CHAR(36)        NULL,
    position_x      FLOAT           NULL,
    position_y      FLOAT           NULL,
    position_z      FLOAT           NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    cached_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entry_id),
    UNIQUE KEY unique_entry (entry_type, entry_uuid),
    FULLTEXT KEY ft_search (title, description, keywords),
    INDEX idx_type (entry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- AUDIT LOG
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_audit_log (
    log_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_uuid      CHAR(36)        NOT NULL,           -- who did it
    action          VARCHAR(128)    NOT NULL,           -- e.g. 'user.password_reset'
    target_uuid     CHAR(36)        NULL,               -- affected entity
    target_type     VARCHAR(64)     NULL,               -- 'user', 'region', 'economy', etc.
    ip_address      VARCHAR(45)     NULL,
    detail          JSON            NULL,               -- before/after or extra context
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    INDEX idx_actor (actor_uuid),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- RATE LIMITING (PHP-level token bucket storage)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_rate_limits (
    bucket_key      VARCHAR(128)    NOT NULL,           -- e.g. 'ip:1.2.3.4' or 'token:abc'
    tokens          FLOAT           NOT NULL DEFAULT 10,
    last_refill     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- SYSTEM CONFIG (runtime-editable key/value store)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_config (
    config_key      VARCHAR(128)    NOT NULL,
    config_value    TEXT            NOT NULL,
    updated_by      CHAR(36)        NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- WEB ROLES (Phase 2b — User Levels & Registration)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_web_roles (
    user_uuid   CHAR(36)                            NOT NULL,
    role        ENUM('user','moderator','webadmin') NOT NULL DEFAULT 'user',
    granted_by  CHAR(36)                            NULL,
    granted_at  DATETIME                            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes       VARCHAR(512)                        NULL,
    PRIMARY KEY (user_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- UserLevel change audit (tracks all UserAccounts.UserLevel changes)
CREATE TABLE IF NOT EXISTS ogm_userlevel_history (
    history_id      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uuid       CHAR(36)        NOT NULL,
    old_level       SMALLINT        NOT NULL,
    new_level       SMALLINT        NOT NULL,
    changed_by      CHAR(36)        NOT NULL,
    reason          VARCHAR(512)    NULL,
    changed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    INDEX idx_user (user_uuid),
    INDEX idx_changed (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- REGISTRATION (Phase 2b)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_registrations (
    reg_id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uuid           CHAR(36)        NOT NULL UNIQUE,
    avatar_firstname    VARCHAR(64)     NOT NULL,
    avatar_lastname     VARCHAR(64)     NOT NULL,
    email               VARCHAR(255)    NOT NULL,
    password_hash       CHAR(60)        NOT NULL,           -- bcrypt
    email_token         CHAR(64)        NOT NULL,           -- SHA256 hex
    email_verified_at   DATETIME        NULL,
    admin_approved_by   CHAR(36)        NULL,
    admin_approved_at   DATETIME        NULL,
    admin_rejected_at   DATETIME        NULL,
    rejection_reason    VARCHAR(512)    NULL,
    status              ENUM(
                          'pending_email',
                          'pending_approval',
                          'approved',
                          'rejected',
                          'expired'
                        ) NOT NULL DEFAULT 'pending_email',
    ip_address          VARCHAR(45)     NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME        NOT NULL,
    PRIMARY KEY (reg_id),
    INDEX idx_uuid (user_uuid),
    INDEX idx_status (status),
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- LAND MANAGEMENT (Phase 4b)
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_parcel_leases (
    lease_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    parcel_uuid     CHAR(36)        NOT NULL,
    region_uuid     CHAR(36)        NOT NULL,
    tenant_uuid     CHAR(36)        NOT NULL,
    leased_by       CHAR(36)        NOT NULL,
    lease_start     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_end       DATETIME        NULL,               -- NULL = indefinite
    rent_amount     INT UNSIGNED    NOT NULL DEFAULT 0,
    rent_period     ENUM('weekly','monthly','once','free') NOT NULL DEFAULT 'free',
    notes           VARCHAR(512)    NULL,
    status          ENUM('active','expired','terminated') NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lease_id),
    INDEX idx_parcel (parcel_uuid),
    INDEX idx_tenant (tenant_uuid),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ogm_region_flag_history (
    history_id      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    region_uuid     CHAR(36)        NOT NULL,
    old_flags       INT             NOT NULL,
    new_flags       INT             NOT NULL,
    changed_by      CHAR(36)        NOT NULL,
    reason          VARCHAR(512)    NULL,
    changed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    INDEX idx_region (region_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- SEED DATA
-- =============================================================================

INSERT INTO ogm_config (config_key, config_value) VALUES
  ('grid_name',                      'My OpenSim Grid'),
  ('grid_nick',                      'MYOGM'),
  ('currency_name',                  'GridBucks'),
  ('currency_symbol',                'G$'),
  ('new_user_balance',               '1000'),
  ('max_transfer_per_day',           '50000'),
  ('session_timeout_mins',           '30'),
  ('api_token_ttl_hours',            '24'),
  ('search_cache_ttl_mins',          '60'),
  ('hypergrid_enabled',              '1'),
  ('hypergrid_default',              'allow'),
  ('hypergrid_min_userlevel',        '1'),
  ('registration_enabled',           '0'),
  ('registration_email_verify',      '1'),
  ('registration_admin_approval',    '1'),
  ('registration_default_userlevel', '0'),
  ('registration_default_web_role',  'user'),
  ('registration_notification_email',''),
  ('registration_smtp_host',         'localhost'),
  ('registration_smtp_port',         '587'),
  ('registration_smtp_user',         ''),
  ('registration_smtp_pass',         ''),
  ('registration_smtp_from',         ''),
  ('registration_smtp_tls',          '1')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
