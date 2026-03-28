# OSGridManager — Project Overview & Architecture

## Purpose

OSGridManager is a self-hosted LAMP-based web platform for managing a private OpenSimulator grid running in ROBUST (grid mode). It replaces jOpenSim with a modern, security-first, dependency-lean alternative providing:

- Web-based self-service for users (profile, inventory overview, messaging, currency)
- Admin panel for operators (user management, region management, grid monitoring)
- LSL-callable HTTP API for inworld integration (money, profiles, messaging, search)
- XMLRPC endpoints compatible with OpenSim's economy and profile modules

---

## Tech Stack

| Layer        | Technology                          |
|--------------|-------------------------------------|
| OS           | Ubuntu 22.04 LTS                    |
| Web Server   | Apache 2.4                          |
| Database     | MariaDB 10.6+                       |
| Language     | PHP 8.3                             |
| Frontend     | Vanilla JS + minimal CSS (no React) |
| Auth         | Session-based + HMAC API tokens     |
| Inworld API  | REST over HTTPS + XMLRPC            |
| Dependencies | Minimal — no Composer frameworks    |

> **Framework policy:** No Laravel, Symfony, or similar. Allowed: PDO, standard PHP extensions (curl, openssl, mbstring, intl, xml). Utility libraries must be single-file and vendored locally if needed.

---

## Deployment Architecture

```
[OpenSim ROBUST grid]
        |
        | DB (shared MariaDB or separate, connected via PDO)
        |
[OSGridManager LAMP Server]
   Apache 2.4 (TLS via Let's Encrypt)
        |
   /var/www/osgridmanager/
        ├── public/          ← web root (Apache DocumentRoot)
        ├── src/             ← PHP application core
        ├── api/             ← REST API (LSL-callable)
        ├── xmlrpc/          ← XMLRPC endpoints (economy, profiles)
        ├── admin/           ← Admin panel (separate path, IP-restricted)
        └── config/          ← Config files (outside web root ideally)
```

The web platform connects to:
1. **OpenSim's existing MariaDB** (read/write for user, region, asset data)
2. **OSGridManager's own DB schema** (economy ledger, messages, sessions, API tokens)

Both can be on the same MariaDB instance using separate databases.

---

## Security Architecture

### Principles
- **Least privilege DB users**: separate DB users per function (readonly for search/profile reads, readwrite for economy/messages, admin-only for user management)
- **No framework magic**: all SQL via prepared PDO statements, no ORM
- **Input validation at boundary**: all external input (web forms, LSL HTTP, XMLRPC) validated and typed before use
- **Secrets out of webroot**: config.php lives at `/etc/osgridmanager/config.php`, symlinked or included via absolute path
- **Admin panel hardened**: `/admin/` restricted by Apache IP allowlist + separate session namespace + 2FA (TOTP) for admin accounts
- **API tokens HMAC-signed**: LSL-callable API uses per-region HMAC-SHA256 tokens, not plaintext shared secrets
- **Rate limiting**: Apache mod_ratelimit + PHP-level token bucket per IP/token for API endpoints
- **TLS enforced**: HTTPS only, HSTS header, no mixed content
- **Content Security Policy**: strict CSP headers on all web responses
- **No eval(), no dynamic includes**: static require/include paths only

### Authentication Flow (Web Users)
1. Login form → POST `/auth/login`
2. PHP validates credentials against OpenSim `UserAccounts` table (bcrypt hash)
3. Session created with: user UUID, role, IP-bound session token, last activity
4. Session stored in `ogm_sessions` table (not filesystem)
5. Every request: session token validated, IP checked, inactivity timeout enforced (30 min)

### Authentication Flow (LSL API)
1. Region owner registers region in admin panel → receives `region_token` (HMAC key)
2. LSL script sends: `region_uuid + timestamp + HMAC-SHA256(payload, region_token)`
3. API validates: token exists, timestamp within ±120s, HMAC matches
4. Per-user actions additionally require `user_token` generated at login and retrievable inworld

---

## Module Overview

| Module          | Web UI | LSL API | XMLRPC | Priority |
|-----------------|--------|---------|--------|----------|
| Auth & Sessions | ✓      | ✓       | —      | 1        |
| User Management | ✓      | —       | —      | 1        |
| Profile         | ✓      | ✓       | ✓      | 2        |
| Region/SIM Mgmt | ✓      | ✓       | —      | 2        |
| Economy/Money   | ✓      | ✓       | ✓      | 3        |
| Messaging       | ✓      | ✓       | —      | 3        |
| Search          | ✓      | ✓       | ✓      | 4        |
| Hypergrid ACL   | ✓      | —       | —      | 4        |
| Admin Panel     | ✓      | —       | —      | 5        |

---

## OpenSim Database Integration

OSGridManager reads from OpenSim's own database tables. Do NOT modify OpenSim's schema. All OSGridManager-specific data lives in its own tables (prefix `ogm_`).

### Key OpenSim tables used (read)
- `UserAccounts` — user UUID, name, email, created, active flags
- `auth` — password hashes (bcrypt in modern OpenSim)
- `GridUser` — last login, last region, online status
- `Regions` — region UUID, name, owner, location, flags
- `Presence` — current session / online presence
- `Inventory*` — inventory folders and items (read-only for profile display)
- `Friends` — friend relationships

### Key OpenSim tables used (write — with caution)
- `UserAccounts` — admin can update email, active status
- `auth` — admin can reset passwords (bcrypt, never plaintext)

---

## File Structure (Full)

```
/var/www/osgridmanager/
├── public/                    ← Apache DocumentRoot
│   ├── index.php              ← Router / front controller
│   ├── assets/
│   │   ├── css/main.css
│   │   └── js/main.js
│   └── .htaccess              ← Rewrite rules, security headers
├── src/
│   ├── Core/
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Session.php
│   │   ├── Auth.php
│   │   ├── DB.php             ← PDO wrapper (two connections: opensim + ogm)
│   │   ├── Config.php
│   │   ├── RateLimit.php
│   │   └── Validator.php
│   ├── Modules/
│   │   ├── User/
│   │   │   ├── UserController.php
│   │   │   ├── UserModel.php
│   │   │   └── UserView.php
│   │   ├── Profile/
│   │   ├── Region/
│   │   ├── Economy/
│   │   ├── Messaging/
│   │   ├── Search/
│   │   └── HypergridACL/
│   └── Admin/
│       ├── AdminAuth.php
│       └── [admin controllers]
├── api/                       ← REST API (LSL HTTP target)
│   ├── index.php              ← API router
│   ├── v1/
│   │   ├── auth.php
│   │   ├── profile.php
│   │   ├── economy.php
│   │   ├── messaging.php
│   │   ├── region.php
│   │   └── search.php
│   └── middleware/
│       ├── TokenAuth.php
│       └── RateLimit.php
├── xmlrpc/                    ← XMLRPC endpoints
│   ├── economy.php            ← Economy module calls
│   └── profile.php            ← Profile/classifieds
├── templates/                 ← HTML templates (no Twig, plain PHP)
│   ├── layout.php
│   ├── user/
│   ├── profile/
│   ├── region/
│   ├── economy/
│   ├── messaging/
│   └── admin/
├── config/                    ← Should ideally be outside webroot
│   └── config.example.php
└── schema/
    ├── ogm_schema.sql         ← OSGridManager DB schema
    └── migrations/
```
