# OSGridManager — Module Specifications

## Build Order

Modules should be built in this order (each depends on the previous):

1. **Core** (DB, Router, Session, Auth, Validator, RateLimit)
2. **User & Auth Management**
3. **Profile Management**
4. **Region / SIM Management**
5. **Economy / Inworld Money**
6. **Messaging & Notifications**
7. **Search**
8. **Hypergrid ACL**
9. **Admin Panel**

---

## Module 1: Core

### DB.php
- Singleton PDO wrapper
- Manages two connections: `opensim` (readonly) and `ogm` (readwrite)
- Exposes: `DB::opensim()`, `DB::ogm()`, `DB::ogmAdmin()`
- All connections use `PDO::ERRMODE_EXCEPTION`
- Charset: `utf8mb4`
- Timezone: UTC (`SET time_zone = '+00:00'`)

### Router.php
- Simple path-based router, no regex complexity
- Maps `METHOD /path` → `Controller::method()`
- Supports route groups (e.g., `/api/v1/*`, `/admin/*`)
- Passes `Request` object to handlers

### Request.php
- Wraps `$_GET`, `$_POST`, `php://input`, headers
- `getJson()`: decodes JSON body, returns array or null
- `getHeader(string $name)`: case-insensitive header fetch
- `getIp()`: extracts real IP (respects `X-Forwarded-For` only if trusted proxy configured)

### Session.php
- DB-backed sessions using `ogm_sessions` table
- `Session::start()`: validates or creates session
- `Session::get(string $key)`, `Session::set(string $key, $value)`
- `Session::destroy()`
- Enforces 30-minute inactivity timeout

### Auth.php
- `Auth::loginWeb(string $email_or_name, string $password): bool`
- `Auth::verifyAgainstOpenSim(string $uuid, string $password): bool` — checks OpenSim `auth` table
- `Auth::currentUser(): ?array` — returns user array from session or null
- `Auth::requireLogin(): void` — redirects to login if not authenticated
- `Auth::requireAdmin(): void` — checks admin role

### Validator.php
- `Validator::uuid(string $v): bool`
- `Validator::positiveInt($v, int $min = 1, int $max = PHP_INT_MAX): ?int`
- `Validator::safeString(string $v, int $maxLen): ?string`
- `Validator::email(string $v): ?string`
- `Validator::url(string $v, array $allowedSchemes = ['https']): ?string`
- `Validator::inEnum($v, array $allowed): bool`

### RateLimit.php
- Token bucket algorithm backed by `ogm_rate_limits` table
- `RateLimit::check(string $key, int $capacity, int $refillPerMin): bool`
- `RateLimit::consume(string $key): void`
- Keys: `"ip:{$ip}"`, `"token:{$tokenHash}"`, `"user:{$uuid}:{$action}"`

### Config.php
- Loads from `/etc/osgridmanager/config.php` (static file for DB credentials)
- Runtime config loaded from `ogm_config` table (grid settings)
- `Config::get(string $key, $default = null)`

---

## Module 2: User & Auth Management

### Web UI Pages
- `GET /login` — Login form
- `POST /auth/login` — Process login
- `GET /auth/logout` — Destroy session
- `GET /register` — Registration form (if enabled in config)
- `POST /register` — Create user (if enabled)
- `GET /account` — Account settings (change email, password)
- `POST /account/update` — Save account changes

### Behaviour
- Login checks against OpenSim's `UserAccounts` + `auth` tables
- After login, OGM session created and `ogm_profiles` row ensured (upsert)
- Password change: updates OpenSim `auth` table with new bcrypt hash
- Email change: updates OpenSim `UserAccounts` email field
- Account page shows: avatar UUID, account name, registration date, last login, current region (from OpenSim `GridUser`)

### Admin Functions
- List all users (paginated, searchable)
- View user details (profile, balance, last login, region)
- Enable / disable user account (`UserAccounts.active`)
- Reset user password
- Grant / deduct currency (admin stipend / adjustment)
- View user's transaction history
- View user's sent/received messages (admin only)
- Force-logout user (delete all their sessions)

---

## Module 3: Profile Management

### Web UI Pages
- `GET /profile/{uuid}` — Public profile view
- `GET /profile/edit` — Edit own profile
- `POST /profile/save` — Save profile changes

### Profile Data Sources (combined view)
- **From OpenSim** (`UserAccounts`): avatar name, created date
- **From OpenSim** (`GridUser`): last login, last region, online status
- **From OGM** (`ogm_profiles`): bio, website, display name, avatar pic URL, preferences

### Profile Edit Fields
- Display name (optional nickname, max 64 chars)
- Bio (textarea, max 2000 chars, no HTML)
- Website URL (https only)
- Avatar picture (URL input OR file upload → stored outside webroot)
- Privacy settings: show online status, show in search

### Inworld Profile (XMLRPC)
- `avatar_properties_request`: combines OpenSim + OGM profile data
- Returns image UUID (first life / profile pic) by resolving URL to OpenSim asset if possible, or a placeholder UUID
- `avatar_properties_update`: updates OGM profile fields only (bio, website)

---

## Module 4: Region / SIM Management

### Web UI Pages
- `GET /regions` — List all regions (public, shows online status)
- `GET /regions/{uuid}` — Region detail page
- `GET /admin/regions` — Admin region management
- `GET /admin/regions/{uuid}/edit` — Edit region metadata
- `POST /admin/regions/{uuid}/save` — Save region metadata

### Region Data Sources
- **From OpenSim** (`Regions`): UUID, name, owner UUID, location X/Y, size, flags, server IP/port
- **From OpenSim** (`Presence`): current agent count, online status
- **From OGM** (`ogm_regions`): notes, web URL, featured flag, access level, hypergrid setting
- **From OGM** (`ogm_region_tokens`): API token status

### Admin Region Functions
- View all regions with online/offline status
- Edit OGM metadata (notes, web URL, access level)
- Set featured region (shown prominently on grid homepage)
- Toggle hypergrid access per region
- Generate / revoke region API token (shown once, then only hash stored)
- View region-level API access log

### Region Status Check
- On each page load, query OpenSim DB for `Presence` count per region
- Cache in PHP APC or simple file cache for 60 seconds to avoid DB hammering
- Show green/red indicator per region

---

## Module 5: Economy / Inworld Money

### Web UI Pages
- `GET /economy` — Own wallet: balance, recent transactions, send money form
- `POST /economy/transfer` — Web-initiated transfer
- `GET /economy/history` — Full transaction history (paginated, filterable)
- `GET /admin/economy` — Admin economy overview
- `GET /admin/economy/user/{uuid}` — Per-user economy view
- `POST /admin/economy/adjust` — Admin balance adjustment

### Economy Rules
- Balance is always read from `ogm_economy_balances` (materialized cache)
- Every transfer atomically: checks balance, writes to `ogm_economy_ledger`, updates both user balances in a DB transaction
- New user accounts receive configured starting balance (from `ogm_config.new_user_balance`)
- Daily transfer limit enforced per user (from `ogm_config.max_transfer_per_day`)
- Negative balances not allowed — transfers fail if sender balance insufficient

### Currency Transfer (Web)
- User selects recipient by avatar name search or UUID
- Enters amount and optional description
- CSRF token validated
- Rate limited: 10 transfers per hour via web

### Inworld Money Flow (XMLRPC → REST)
1. Avatar pays object inworld → OpenSim calls XMLRPC `economy.php`
2. XMLRPC handler validates, calls internal Economy service class
3. Economy service performs transfer atomically
4. Sends notification to recipient via `ogm_notifications`
5. Returns success/failure to OpenSim

### Admin Economy
- View all transactions (filterable by user, type, date range)
- Issue stipend to single user or all users
- Reverse a transaction (creates a reversal record — never deletes)
- Export transactions as CSV

---

## Module 6: Messaging & Notifications

### Web UI Pages
- `GET /messages` — Inbox
- `GET /messages/sent` — Sent messages
- `GET /messages/{id}` — Read message
- `GET /messages/compose` — Compose form
- `POST /messages/send` — Send message
- `POST /messages/delete` — Soft-delete message
- `GET /notifications` — Notification list
- `POST /notifications/mark-read` — Mark read

### Messaging Rules
- Grid-internal only — not email
- Messages stored in `ogm_messages`
- Soft delete: `deleted_by_sender` / `deleted_by_recipient` flags — message only removed from DB when both are deleted
- Max body: 4096 chars
- Reply chains: link via `reply_to_msg_id` (add this column to schema)
- Notifications auto-created on: money received, message received, admin announcements

### Inworld Messaging
- LSL script polls `/api/v1/messaging/notifications` to show unread count
- LSL script can send message via `/api/v1/messaging/send`
- LSL script can read inbox summary (last 5 messages) via `/api/v1/messaging/inbox?limit=5`
- Full message reading done via web (link shown inworld)

### Notifications
- Auto-triggered by Economy module (money events) and Messaging module (new message)
- Admin can push system-wide notifications via admin panel
- Inworld polling: LSL polls every 60 seconds for notification count

---

## Module 7: Search

### Web UI Pages
- `GET /search?q=...&type=all` — Search results page

### Search Data
- Backed by `ogm_search_cache` table with FULLTEXT index
- Cache rebuilt by a cron job (every 60 minutes) pulling from:
  - OpenSim `Regions` table (region name, description flags)
  - OpenSim `UserAccounts` (avatar names, for users with `show_in_search = 1`)
  - OpenSim profile tables for classifieds (if available)
- Search results respect privacy flags (`show_in_search`)

### Cron Job: `scripts/rebuild_search_cache.php`
- Run via cron: `*/60 * * * * php /var/www/osgridmanager/scripts/rebuild_search_cache.php`
- Truncates and rebuilds `ogm_search_cache`
- Logs rebuild time and count to `ogm_config` (`search_cache_last_rebuild`)

### Inworld Search (XMLRPC)
- OpenSim's `DataSnapshot` module can be pointed at OSGridManager
- Implement the DSearch XMLRPC interface at `/xmlrpc/search.php`
- Returns region and people search results in OpenSim's expected XML format

---

## Module 8: Hypergrid ACL

### Web UI Pages (Admin only)
- `GET /admin/hypergrid` — ACL list (global + per-region)
- `POST /admin/hypergrid/add` — Add allow/deny rule
- `POST /admin/hypergrid/remove` — Remove rule
- `GET /admin/hypergrid/regions` — Per-region ACL overrides

### ACL Logic
- Global default: from `ogm_config.hypergrid_default` (allow/deny)
- Global rules in `ogm_hypergrid_acl` override default
- Per-region rules in `ogm_hypergrid_region_acl` override global
- Matching: normalize grid URI (lowercase, strip trailing slash, strip `http://` / `https://`)
- Wildcard support: `*.example.com` matches any subdomain

### Integration with OpenSim
- OSGridManager cannot directly block hypergrid at the network level
- It provides an API endpoint for ROBUST to query: `GET /api/v1/hypergrid/check?grid_uri=...&region_uuid=...`
- ROBUST (or a custom module) calls this endpoint to decide whether to allow the teleport
- Document the required OpenSim config to enable this integration

---

## Module 9: Admin Panel

### Additional Admin Pages
- `GET /admin` — Dashboard (user count, region count, economy summary, recent logins)
- `GET /admin/audit` — Audit log (filterable)
- `GET /admin/config` — Runtime config editor (`ogm_config` table)
- `GET /admin/tokens` — API token management (region tokens)
- `GET /admin/logs` — View application error log tail

### Admin Auth
- Separate login at `/admin/login`
- `ogm_admins` table: `admin_id`, `username`, `password_hash`, `totp_secret`, `created_at`, `last_login`
- TOTP 2FA (RFC 6238) required on every login — implement or vendor a minimal TOTP class
- Admin sessions: 15-minute timeout, IP-locked, stored in `ogm_admin_sessions` table
- All admin actions logged to `ogm_audit_log`
