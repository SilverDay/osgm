-- =============================================================================
-- Migration 001 — Initial schema
-- Creates all OGM tables. Safe to run on a fresh database.
-- =============================================================================

CREATE TABLE IF NOT EXISTS ogm_sessions (
    session_id      CHAR(64)        NOT NULL,
    user_uuid       CHAR(36)        NOT NULL,
    ip_address      VARCHAR(45)     NOT NULL,
    user_agent_hash CHAR(64)        NOT NULL,
    role            ENUM('user','admin') NOT NULL DEFAULT 'user',
    session_data    TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME        NOT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (session_id),
    INDEX idx_user_uuid (user_uuid),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_region_tokens (
    token_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    region_uuid     CHAR(36)        NOT NULL UNIQUE,
    region_name     VARCHAR(255)    NOT NULL,
    token_hash      CHAR(64)        NOT NULL,
    created_by      CHAR(36)        NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used       DATETIME        NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (token_id),
    INDEX idx_region_uuid (region_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ogm_user_tokens (
    token_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uuid       CHAR(36)        NOT NULL,
    token_hash      CHAR(64)        NOT NULL,
    scope           SET('profile','economy','messaging','search') NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME        NOT NULL,
    last_used       DATETIME        NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (token_id),
    INDEX idx_user_uuid (user_uuid),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_economy_ledger (
    tx_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tx_uuid         CHAR(36)        NOT NULL UNIQUE,
    from_uuid       CHAR(36)        NOT NULL,
    to_uuid         CHAR(36)        NOT NULL,
    amount          INT UNSIGNED    NOT NULL,
    tx_type         ENUM('transfer','purchase','stipend','fee','refund','adjustment') NOT NULL,
    description     VARCHAR(512)    NOT NULL DEFAULT '',
    object_uuid     CHAR(36)        NULL,
    region_uuid     CHAR(36)        NULL,
    status          ENUM('pending','confirmed','failed','reversed') NOT NULL DEFAULT 'confirmed',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at    DATETIME        NULL,
    metadata        JSON            NULL,
    PRIMARY KEY (tx_id),
    INDEX idx_from (from_uuid),
    INDEX idx_to (to_uuid),
    INDEX idx_type (tx_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ogm_economy_balances (
    user_uuid       CHAR(36)        NOT NULL,
    balance         INT UNSIGNED    NOT NULL DEFAULT 0,
    last_tx_id      BIGINT UNSIGNED NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_messages (
    msg_id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_uuid            CHAR(36)        NOT NULL,
    to_uuid              CHAR(36)        NOT NULL,
    subject              VARCHAR(255)    NOT NULL DEFAULT '',
    body                 TEXT            NOT NULL,
    reply_to_msg_id      BIGINT UNSIGNED NULL,
    sent_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at              DATETIME        NULL,
    deleted_by_sender    TINYINT(1)      NOT NULL DEFAULT 0,
    deleted_by_recipient TINYINT(1)      NOT NULL DEFAULT 0,
    source               ENUM('web','inworld') NOT NULL DEFAULT 'web',
    PRIMARY KEY (msg_id),
    INDEX idx_from (from_uuid),
    INDEX idx_to (to_uuid),
    INDEX idx_sent (sent_at),
    INDEX idx_reply (reply_to_msg_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ogm_notifications (
    notif_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_uuid       CHAR(36)        NOT NULL,
    type            ENUM('money_received','money_sent','message_received','region_event','system','admin') NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    body            VARCHAR(1024)   NOT NULL,
    read_at         DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    metadata        JSON            NULL,
    PRIMARY KEY (notif_id),
    INDEX idx_user (user_uuid),
    INDEX idx_read (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_profiles (
    user_uuid       CHAR(36)        NOT NULL,
    display_name    VARCHAR(64)     NULL,
    bio             TEXT            NULL,
    website         VARCHAR(512)    NULL,
    avatar_pic_url  VARCHAR(512)    NULL,
    show_online     TINYINT(1)      NOT NULL DEFAULT 1,
    show_in_search  TINYINT(1)      NOT NULL DEFAULT 1,
    language        CHAR(5)         NOT NULL DEFAULT 'en',
    timezone        VARCHAR(64)     NOT NULL DEFAULT 'UTC',
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_regions (
    region_uuid     CHAR(36)        NOT NULL,
    notes           TEXT            NULL,
    web_url         VARCHAR(512)    NULL,
    is_featured     TINYINT(1)      NOT NULL DEFAULT 0,
    access_level    ENUM('public','private','hypergrid_allowed') NOT NULL DEFAULT 'public',
    allow_hypergrid TINYINT(1)      NOT NULL DEFAULT 1,
    updated_by      CHAR(36)        NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (region_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_hypergrid_acl (
    acl_id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    grid_uri        VARCHAR(512)    NOT NULL,
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

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_search_cache (
    entry_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    entry_type      ENUM('region','user','classifieds','event') NOT NULL,
    entry_uuid      CHAR(36)        NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    description     TEXT            NULL,
    keywords        VARCHAR(512)    NULL,
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

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_audit_log (
    log_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_uuid      CHAR(36)        NOT NULL,
    action          VARCHAR(128)    NOT NULL,
    target_uuid     CHAR(36)        NULL,
    target_type     VARCHAR(64)     NULL,
    ip_address      VARCHAR(45)     NULL,
    detail          JSON            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    INDEX idx_actor (actor_uuid),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_rate_limits (
    bucket_key      VARCHAR(128)    NOT NULL,
    tokens          FLOAT           NOT NULL DEFAULT 10,
    last_refill     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ogm_config (
    config_key      VARCHAR(128)    NOT NULL,
    config_value    TEXT            NOT NULL,
    updated_by      CHAR(36)        NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_web_roles (
    user_uuid   CHAR(36)                            NOT NULL,
    role        ENUM('user','moderator','webadmin') NOT NULL DEFAULT 'user',
    granted_by  CHAR(36)                            NULL,
    granted_at  DATETIME                            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes       VARCHAR(512)                        NULL,
    PRIMARY KEY (user_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_registrations (
    reg_id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uuid           CHAR(36)        NOT NULL UNIQUE,
    avatar_firstname    VARCHAR(64)     NOT NULL,
    avatar_lastname     VARCHAR(64)     NOT NULL,
    email               VARCHAR(255)    NOT NULL,
    password_hash       CHAR(60)        NOT NULL,
    email_token         CHAR(64)        NOT NULL,
    email_verified_at   DATETIME        NULL,
    admin_approved_by   CHAR(36)        NULL,
    admin_approved_at   DATETIME        NULL,
    admin_rejected_at   DATETIME        NULL,
    rejection_reason    VARCHAR(512)    NULL,
    status              ENUM('pending_email','pending_approval','approved','rejected','expired')
                        NOT NULL DEFAULT 'pending_email',
    ip_address          VARCHAR(45)     NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME        NOT NULL,
    PRIMARY KEY (reg_id),
    INDEX idx_uuid (user_uuid),
    INDEX idx_status (status),
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ogm_parcel_leases (
    lease_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    parcel_uuid     CHAR(36)        NOT NULL,
    region_uuid     CHAR(36)        NOT NULL,
    tenant_uuid     CHAR(36)        NOT NULL,
    leased_by       CHAR(36)        NOT NULL,
    lease_start     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_end       DATETIME        NULL,
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
