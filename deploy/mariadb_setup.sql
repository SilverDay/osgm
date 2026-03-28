-- =============================================================================
-- OSGridManager — MariaDB Setup
--
-- Run once as root after mysql_secure_installation:
--   mysql -u root -p < deploy/mariadb_setup.sql
--
-- Replace every <CHANGEME_*> placeholder with a strong, unique password
-- before running. Never reuse passwords across accounts.
--
-- Assumes:
--   - OGM database name : osgridmanager
--   - OpenSim database name : opensim  (adjust if different)
--   - All users connect from localhost  (adjust if DB is on a separate host)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Databases
-- ---------------------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS osgridmanager
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- OpenSim database must already exist. This file does not create it.

-- ---------------------------------------------------------------------------
-- OGM — read-write user (economy, messaging, sessions, rate limits)
-- ---------------------------------------------------------------------------

CREATE USER IF NOT EXISTS 'ogm_rw'@'localhost'
    IDENTIFIED BY '<CHANGEME_OGM_RW>';

GRANT SELECT, INSERT, UPDATE, DELETE
    ON osgridmanager.*
    TO 'ogm_rw'@'localhost';

-- ---------------------------------------------------------------------------
-- OGM — read-only user (search queries, profile reads)
-- ---------------------------------------------------------------------------

CREATE USER IF NOT EXISTS 'ogm_ro'@'localhost'
    IDENTIFIED BY '<CHANGEME_OGM_RO>';

GRANT SELECT
    ON osgridmanager.*
    TO 'ogm_ro'@'localhost';

-- ---------------------------------------------------------------------------
-- OGM — admin user (user management, migration runner)
-- Needs CREATE TABLE for the ogm_migrations tracking table.
-- ---------------------------------------------------------------------------

CREATE USER IF NOT EXISTS 'ogm_admin'@'localhost'
    IDENTIFIED BY '<CHANGEME_OGM_ADMIN>';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX,
      CREATE TEMPORARY TABLES
    ON osgridmanager.*
    TO 'ogm_admin'@'localhost';

-- ---------------------------------------------------------------------------
-- OpenSim — read-only user (profile reads, region list, presence queries)
-- ---------------------------------------------------------------------------

CREATE USER IF NOT EXISTS 'opensim_ro'@'localhost'
    IDENTIFIED BY '<CHANGEME_OPENSIM_RO>';

GRANT SELECT
    ON opensim.*
    TO 'opensim_ro'@'localhost';

-- ---------------------------------------------------------------------------
-- OpenSim — limited write user (password reset, UserLevel, account enable)
--
-- Column-level grants restrict this user to only the fields OGM ever writes.
-- ---------------------------------------------------------------------------

CREATE USER IF NOT EXISTS 'opensim_limited'@'localhost'
    IDENTIFIED BY '<CHANGEME_OPENSIM_LIMITED>';

-- Read everything (needed for joins and lookups)
GRANT SELECT
    ON opensim.*
    TO 'opensim_limited'@'localhost';

-- Write: enable/disable accounts and update email
GRANT UPDATE (Active, Email)
    ON opensim.UserAccounts
    TO 'opensim_limited'@'localhost';

-- Write: UserLevel (set inworld permission tier from admin panel)
GRANT UPDATE (UserLevel)
    ON opensim.UserAccounts
    TO 'opensim_limited'@'localhost';

-- Write: password reset (bcrypt hash only — salt unused in modern OpenSim)
GRANT UPDATE (passwordHash, passwordSalt)
    ON opensim.auth
    TO 'opensim_limited'@'localhost';

-- Write: new account creation via registration approval
-- (INSERT into UserAccounts and auth — mirrors what ROBUST does)
GRANT INSERT
    ON opensim.UserAccounts
    TO 'opensim_limited'@'localhost';

GRANT INSERT
    ON opensim.auth
    TO 'opensim_limited'@'localhost';

-- Write: region management (selected columns only — see spec 08)
GRANT UPDATE (serverIP, serverPort, serverHttpPort, regionName,
              locX, locY, sizeX, sizeY, flags, access,
              owner_uuid, gatekeeperURL)
    ON opensim.Regions
    TO 'opensim_limited'@'localhost';

-- Write: parcel management
GRANT SELECT, INSERT, UPDATE, DELETE
    ON opensim.land
    TO 'opensim_limited'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE
    ON opensim.landaccesslist
    TO 'opensim_limited'@'localhost';

-- ---------------------------------------------------------------------------

FLUSH PRIVILEGES;

-- ---------------------------------------------------------------------------
-- Verification (optional — uncomment to check grants after running)
-- ---------------------------------------------------------------------------
-- SHOW GRANTS FOR 'ogm_rw'@'localhost';
-- SHOW GRANTS FOR 'ogm_ro'@'localhost';
-- SHOW GRANTS FOR 'ogm_admin'@'localhost';
-- SHOW GRANTS FOR 'opensim_ro'@'localhost';
-- SHOW GRANTS FOR 'opensim_limited'@'localhost';
