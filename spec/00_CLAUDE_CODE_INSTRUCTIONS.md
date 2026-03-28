# OSGridManager — Claude Code Instructions

## What This Is

You are implementing **OSGridManager**, a self-hosted LAMP-based web management platform for a private OpenSimulator grid. This is a **security-first** project with no large framework dependencies.

Read all specification files in this directory before writing any code:

1. `01_PROJECT_OVERVIEW.md` — Architecture, tech stack, file structure
2. `02_DATABASE_SCHEMA.sql` — Full OGM database schema (run this first)
3. `03_API_CONTRACT.md` — REST and XMLRPC API specifications
4. `04_SECURITY_REQUIREMENTS.md` — **Read this carefully — non-negotiable security rules**
5. `05_MODULE_SPECS.md` — Per-module functional specifications
6. `06_SERVER_CONFIG.md` — Apache, PHP, MariaDB configuration
7. `07_USER_LEVELS_AND_REGISTRATION.md` — User levels, web roles, registration flow, email verification
8. `08_LAND_MANAGEMENT.md` — Region and parcel management, leases, access lists

---

## Coding Standards

### PHP
- PHP 8.3, strict types always: `<?php declare(strict_types=1);`
- All classes use namespaces: `OGM\Core\`, `OGM\Modules\Economy\`, etc.
- PSR-4 class naming, but **no Composer autoloader** — use explicit `require_once` with `__DIR__` paths
- Return types declared on all functions
- Constructor property promotion where appropriate
- No `var_dump()`, `print_r()`, or `die()` in production code — use proper logging
- Constants in `UPPER_SNAKE_CASE`, classes in `PascalCase`, methods in `camelCase`

### SQL
- All queries: prepared statements with named parameters (`:name` style)
- Never string-interpolate into SQL — ever
- Use DB transactions for multi-step operations (especially economy transfers)
- Always specify column names in INSERT/SELECT — never `SELECT *` in production code

### HTML/Templates
- Templates are plain PHP files in `/templates/`
- Always use `h()` helper for output: `<?= h($value) ?>`
- No inline JavaScript
- Forms always include CSRF token: `<?= Csrf::field() ?>`
- Semantic HTML5, accessible (labels, ARIA where needed)
- Mobile-responsive using simple CSS (no frameworks — flexbox/grid is fine)

### JavaScript
- Vanilla JS only — no jQuery, no frameworks
- `'use strict';` at top of every script
- Minimal: only enhance where needed (form validation, AJAX for notifications poll)
- LSL scripts are not JavaScript — see API contract for LSL integration notes

---

## Implementation Order

### Phase 1: Foundation
1. Create directory structure as per `01_PROJECT_OVERVIEW.md`
2. Create `/etc/osgridmanager/config.php` from template in `06_SERVER_CONFIG.md`
3. Implement `src/Core/Config.php`
4. Implement `src/Core/DB.php`
5. Implement `src/Core/Router.php` + `Request.php` + `Response.php`
6. Implement `src/Core/Validator.php`
7. Implement `src/Core/RateLimit.php`
8. Implement `src/Core/Session.php`
9. Implement `src/Core/Auth.php`
10. Create `public/index.php` (front controller)
11. Create `public/.htaccess`
12. Create `templates/layout.php` (base layout with security headers)

### Phase 2: User & Auth
13. `src/Modules/User/UserModel.php`
14. `src/Modules/User/UserController.php`
15. Templates: login, logout, account
16. Test: login with OpenSim credentials

### Phase 2b: User Levels & Registration
17. `src/Core/Mailer.php` — minimal SMTP via PHP `mail()` + msmtp
18. `src/Modules/Registration/RegistrationModel.php`
19. `src/Modules/Registration/RegistrationController.php`
20. Templates: register form, verify pending, awaiting approval, approved, rejected
21. Templates: email plaintext templates (verify, admin notify, approval, rejection)
22. `src/Admin/AdminRegistrationController.php`
23. Templates: admin registration queue (pending, approve, reject)
24. `scripts/expire_registrations.php` — cron: mark unverified as expired after 48h

**Key rules for registration module:**
- Respect all 4 `ogm_config` flags: `registration_enabled`, `registration_email_verify`, `registration_admin_approval`, and their interactions (4 flow variants documented in spec 07)
- Avatar name uniqueness checked against OpenSim `UserAccounts` (FirstName + LastName combination)
- Email uniqueness checked against both OpenSim `UserAccounts.Email` and `ogm_registrations.email`
- Account creation on approval: INSERT into OpenSim `UserAccounts` + `auth` tables (see exact SQL in spec 07)
- Email header injection prevention: sanitize all `To:`, `Subject:` values before passing to `mail()`
- All registration events logged to `ogm_audit_log`

**User level management (add to admin user detail page):**
- Dropdown to set `UserLevel` (0, 1, 10, 100, 200) — writes to OpenSim `UserAccounts.UserLevel`
- Reason field for audit trail
- Display `ogm_userlevel_history` on user detail page
- Dropdown to set OGM web role (`user`, `moderator`, `webadmin`) — writes to `ogm_web_roles`

### Phase 3: Profile
17. `src/Modules/Profile/ProfileModel.php`
18. `src/Modules/Profile/ProfileController.php`
19. Templates: profile view, profile edit
20. `xmlrpc/profile.php` — XMLRPC handler

### Phase 4: Region Management
25. `src/Modules/Region/RegionModel.php`
26. `src/Modules/Region/RegionController.php`
27. Templates: region list, region detail

### Phase 4b: Land Management
28. `src/Modules/Land/LandModel.php` — region + parcel queries against OpenSim tables
29. `src/Modules/Land/ParcelAccessModel.php` — `landaccesslist` CRUD
30. `src/Modules/Land/LeaseModel.php` — `ogm_parcel_leases` management
31. `src/Modules/Land/LandController.php` — admin CRUD for regions + parcels
32. Templates: `admin/land/` — region edit, parcel list, parcel edit, access list, lease management
33. Templates: `land/` — public region/parcel browse, user land holdings

**Key rules for land module:**
- Never modify `land.Bitmap` field — read-only always
- Always show restart warning after any write to `land` or `Regions` tables
- Parcel flag display: read-only named badges — never allow full bitmask editing in v1
- Group-owned parcels (`IsGroupOwned=1`): show group UUID as-is, no group name resolution
- Validate landing coords against region `sizeX`/`sizeY`
- Always include `ScopeID = '00000000-0000-0000-0000-000000000000'` in UserAccounts queries

### Phase 5: Economy
34. `src/Modules/Economy/EconomyModel.php`
35. `src/Modules/Economy/EconomyController.php`
36. `src/Modules/Economy/EconomyService.php` (handles atomic transfers)
37. Templates: wallet, history, transfer form
38. `xmlrpc/economy.php` — XMLRPC handler

### Phase 6: Messaging & Notifications
39. `src/Modules/Messaging/MessagingModel.php`
40. `src/Modules/Messaging/MessagingController.php`
41. `src/Modules/Notifications/NotificationService.php`
42. Templates: inbox, sent, compose, read, notifications

### Phase 7: REST API
43. `api/index.php` — API router
44. `api/middleware/TokenAuth.php`
45. `api/middleware/RateLimit.php`
46. `api/v1/auth.php`
47. `api/v1/economy.php`
48. `api/v1/messaging.php`
49. `api/v1/profile.php`
50. `api/v1/region.php`
51. `api/v1/search.php`

### Phase 8: Search
52. `src/Modules/Search/SearchModel.php`
53. `src/Modules/Search/SearchController.php`
54. `scripts/rebuild_search_cache.php`
55. Templates: search results

### Phase 9: Hypergrid ACL
56. `src/Modules/HypergridACL/HypergridACLModel.php`
57. `src/Modules/HypergridACL/HypergridACLController.php`
58. `api/v1/hypergrid.php`

### Phase 10: Admin Panel
59. `src/Admin/AdminAuth.php` (with TOTP)
60. Admin controllers for: users, regions, land, economy, config, tokens, audit log, registrations
61. Admin templates
62. `admin/index.php` entry point

### Phase 11: Scripts & Config
63. All cron scripts in `/scripts/` (including `expire_registrations.php`)
64. Apache vhost config file (ready to deploy)
65. MariaDB setup script (including all new grants from specs 07 and 08)

---

## Key Decisions to Preserve

- **Economy transfers are atomic**: use `BEGIN TRANSACTION` / `COMMIT` / `ROLLBACK` — never leave ledger and balance out of sync
- **Session IDs are DB-backed**: PHP's built-in session handler is NOT used
- **Tokens are hashed**: never store plaintext tokens — only `hash('sha256', $token)`
- **HMAC comparison uses `hash_equals()`**: never `===`
- **All user output is escaped**: use the `h()` helper — never raw echo of user data
- **Admin panel uses IP allowlist** in Apache config AND separate session table
- **Config credentials** live in `/etc/osgridmanager/config.php` — not in webroot

---

## OpenSim Integration Notes

### Password Hashing
Modern OpenSim (0.9.x) uses bcrypt for the `auth` table. Older versions used MD5(`$password:$salt`). Detect by checking if `passwordHash` starts with `$2y$` (bcrypt) or is 32 hex chars (MD5). Implement both verifications in `Auth::verifyAgainstOpenSim()`.

### UUID Format
OpenSim uses standard UUID format: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` (lowercase). Always store and compare in lowercase.

### Presence / Online Status
Query `SELECT RegionID, UserID FROM Presence WHERE UserID = :uuid` to check online status. A row existing = online.

### Grid User
`SELECT LastRegionID, LastLogin FROM GridUser WHERE UserID = :uuid` for last seen info.

### Asset Server
Avatar images may reference OpenSim asset UUIDs. For profile pictures, OSGridManager stores a web URL (in `ogm_profiles.avatar_pic_url`) rather than an asset UUID to avoid tight coupling with the asset server.

---

## LSL Integration Pattern

LSL (Linden Scripting Language) has these constraints:
- `llHTTPRequest()` max URL length: 2048 chars
- Request body max: 2048 bytes
- Cannot compute HMAC natively
- HTTP headers: use `HTTP_CUSTOM_HEADER` with `[HTTP_CUSTOM_HEADER, "X-OGM-Region", val]`
- JSON: use `llList2Json()` and `llJsonGetValue()`

**Authentication flow for LSL:**
1. Region object calls `/api/v1/auth/inworld-login` with region token in headers
2. Server returns short-lived `user_token` for that avatar's session
3. Subsequent LSL calls attach `X-OGM-User-Token: <token>` header
4. Token expires when avatar leaves region (LSL calls `/api/v1/auth/token-revoke`)

Provide at least one example LSL script (`examples/ogm_listener.lsl`) demonstrating the full authentication and balance-check flow.

---

## Testing Checklist (for each module)

Before marking a module complete:

- [ ] All inputs validated (try empty string, null, oversized, special chars, SQL injection attempt)
- [ ] SQL uses prepared statements only
- [ ] Output is escaped via `h()`
- [ ] CSRF tokens present on all state-changing forms
- [ ] Rate limits tested (hammer endpoint, verify 429 after threshold)
- [ ] Auth required where expected (verify 401/redirect without session)
- [ ] Audit log entry created for sensitive operations
- [ ] Error conditions handled gracefully (no stack traces to client)
- [ ] Admin functions not accessible to regular users

---

## Files to Create (Summary)

```
/etc/osgridmanager/
└── config.php

/var/www/osgridmanager/
├── public/
│   ├── index.php
│   ├── .htaccess
│   └── assets/
│       ├── css/main.css
│       └── js/main.js
├── src/
│   ├── Core/
│   │   ├── Config.php
│   │   ├── DB.php
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Session.php
│   │   ├── Auth.php
│   │   ├── Csrf.php
│   │   ├── Validator.php
│   │   ├── RateLimit.php
│   │   └── Logger.php
│   ├── Modules/
│   │   ├── User/
│   │   │   ├── UserModel.php
│   │   │   └── UserController.php
│   │   ├── Profile/
│   │   │   ├── ProfileModel.php
│   │   │   └── ProfileController.php
│   │   ├── Region/
│   │   │   ├── RegionModel.php
│   │   │   └── RegionController.php
│   │   ├── Economy/
│   │   │   ├── EconomyModel.php
│   │   │   ├── EconomyController.php
│   │   │   └── EconomyService.php
│   │   ├── Messaging/
│   │   │   ├── MessagingModel.php
│   │   │   └── MessagingController.php
│   │   ├── Notifications/
│   │   │   └── NotificationService.php
│   │   ├── Search/
│   │   │   ├── SearchModel.php
│   │   │   └── SearchController.php
│   │   └── HypergridACL/
│   │       ├── HypergridACLModel.php
│   │       └── HypergridACLController.php
│   └── Admin/
│       ├── AdminAuth.php
│       ├── AdminUserController.php
│       ├── AdminRegionController.php
│       ├── AdminEconomyController.php
│       ├── AdminConfigController.php
│       └── AdminAuditController.php
├── api/
│   ├── index.php
│   ├── middleware/
│   │   ├── TokenAuth.php
│   │   └── RateLimit.php
│   └── v1/
│       ├── auth.php
│       ├── economy.php
│       ├── messaging.php
│       ├── profile.php
│       ├── region.php
│       ├── search.php
│       └── hypergrid.php
├── xmlrpc/
│   ├── economy.php
│   ├── profile.php
│   └── search.php
├── templates/
│   ├── layout.php
│   ├── user/
│   ├── profile/
│   ├── region/
│   ├── economy/
│   ├── messaging/
│   ├── search/
│   └── admin/
├── scripts/
│   ├── rebuild_search_cache.php
│   ├── cleanup_sessions.php
│   ├── cleanup_tokens.php
│   ├── cleanup_ratelimits.php
│   └── release_economy_holds.php
├── schema/
│   └── ogm_schema.sql
├── examples/
│   └── ogm_listener.lsl
└── deploy/
    ├── apache_vhost.conf
    ├── mariadb_setup.sql
    └── cron.d_osgridmanager
```
