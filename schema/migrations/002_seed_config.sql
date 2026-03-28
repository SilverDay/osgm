-- =============================================================================
-- Migration 002 — Seed default runtime configuration
-- Uses INSERT ... ON DUPLICATE KEY UPDATE so it is safe to re-run:
-- existing values are never overwritten by this migration.
-- =============================================================================

INSERT INTO ogm_config (config_key, config_value) VALUES
  -- Grid identity
  ('grid_name',                      'My OpenSim Grid'),
  ('grid_nick',                      'MYOGM'),

  -- Currency
  ('currency_name',                  'GridBucks'),
  ('currency_symbol',                'G$'),
  ('new_user_balance',               '1000'),
  ('max_transfer_per_day',           '50000'),

  -- Sessions & tokens
  ('session_timeout_mins',           '30'),
  ('api_token_ttl_hours',            '24'),

  -- Search
  ('search_cache_ttl_mins',          '60'),

  -- Hypergrid
  ('hypergrid_enabled',              '1'),
  ('hypergrid_default',              'allow'),
  ('hypergrid_min_userlevel',        '1'),

  -- Registration
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

ON DUPLICATE KEY UPDATE config_key = config_key;  -- no-op on conflict: never overwrite
