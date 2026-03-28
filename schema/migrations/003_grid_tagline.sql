-- =============================================================================
-- Migration 003 — Add grid_tagline config key
-- Safe to re-run: ON DUPLICATE KEY UPDATE is a no-op (keeps existing value).
-- =============================================================================

INSERT INTO ogm_config (config_key, config_value)
VALUES ('grid_tagline', 'Your Virtual World')
ON DUPLICATE KEY UPDATE config_key = config_key;
