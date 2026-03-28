# OSGridManager — Security Requirements

## Mandatory Rules for Claude Code

These are non-negotiable constraints. Every generated file must comply.

---

## 1. Input Validation

- **All external input** (POST body, GET params, HTTP headers, XMLRPC params) MUST be validated before use
- Use a central `Validator` class — never validate inline ad-hoc
- Validation rules per field type:

| Type        | Rule                                                     |
|-------------|----------------------------------------------------------|
| UUID        | Regex `/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i` |
| Integer     | `filter_var($v, FILTER_VALIDATE_INT)` + range check      |
| String      | `mb_strlen()` limit + `strip_tags()` where HTML not expected |
| Email       | `filter_var($v, FILTER_VALIDATE_EMAIL)`                  |
| URL         | `filter_var($v, FILTER_VALIDATE_URL)` + allowlist schemes (`https`) |
| Enum        | `in_array($v, ALLOWED_VALUES, true)` — strict comparison |

- **Reject and log** any request that fails validation — never silently discard

---

## 2. SQL / Database

- **ALL** database queries MUST use PDO prepared statements with bound parameters
- **NEVER** use string concatenation to build SQL queries
- **NEVER** use `query()` with user-supplied data — only `prepare()` + `execute()`
- Use separate DB connections with separate MariaDB users:
  - `ogm_readonly@localhost` — for search, profile reads, region list
  - `ogm_readwrite@localhost` — for economy, messaging, session management
  - `ogm_admin@localhost` — for user management (admin panel only)
  - `opensim_readonly@localhost` — for reading OpenSim's own tables

```php
// CORRECT
$stmt = $pdo->prepare("SELECT * FROM UserAccounts WHERE PrincipalID = :uuid");
$stmt->execute([':uuid' => $uuid]);

// WRONG — never do this
$stmt = $pdo->query("SELECT * FROM UserAccounts WHERE PrincipalID = '$uuid'");
```

---

## 3. Output / XSS Prevention

- **All output to HTML** MUST be escaped with `htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')`
- Create a global `h()` helper function: `function h($s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }`
- **All JSON output** via `json_encode()` — never manually construct JSON strings
- **No `echo $_GET['x']`** anywhere in the codebase
- Templates must never contain raw PHP variable output without `h()`

---

## 4. Authentication & Sessions

- Sessions stored in `ogm_sessions` DB table — never in files or PHP default session handler
- Session ID: `bin2hex(random_bytes(32))` — never `session_id()` or predictable values
- Session binding: IP address + User-Agent hash — invalidate on mismatch (configurable strictness)
- Session timeout: 30 minutes inactivity (configurable in `ogm_config`)
- On logout: delete session row from DB, clear cookie
- Password verification: `password_verify()` against OpenSim's bcrypt hash
- Password hashing (for OGM-managed passwords): `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])`

---

## 5. CSRF Protection

- All state-changing web forms MUST include a CSRF token
- Token: `bin2hex(random_bytes(32))` stored in session, validated on POST
- API endpoints (REST/XMLRPC) exempt — they use HMAC authentication instead
- Implement as a simple `Csrf::token()` / `Csrf::verify()` utility class

---

## 6. API Token Security

- Tokens never stored in plaintext — only `hash('sha256', $token)` stored in DB
- Tokens issued as: `base64_url_encode(random_bytes(32))`
- HMAC signatures: `hash_hmac('sha256', $payload, $secret)`
- Use `hash_equals()` for all token/signature comparisons — never `===` or `==`
- Timestamps: reject requests with timestamp outside ±120 seconds
- Rate limit enforcement checked before any token validation logic (fail fast)

---

## 7. HTTP Security Headers

All responses MUST include these headers (set in Apache `.htaccess` AND in PHP for API):

```
Strict-Transport-Security: max-age=63072000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; frame-ancestors 'none'
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

For API endpoints, also include:
```
Access-Control-Allow-Origin: [configured grid hostname only]
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: X-OGM-Region, X-OGM-Timestamp, X-OGM-Signature, X-OGM-User, X-OGM-User-Token, Content-Type
```

---

## 8. File System

- Config file stored at `/etc/osgridmanager/config.php` — NOT in webroot
- Web root contains ONLY: `index.php`, `assets/`, `.htaccess`
- No direct PHP file access — all requests routed through `index.php` (front controller)
- API and XMLRPC have their own `index.php` entry points
- `.htaccess` rules:
  ```apache
  Options -Indexes
  Options -ExecCGI
  # Block direct access to everything except entry points
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ index.php [L]
  ```
- Upload handling: avatars/images must be validated (MIME type, size limit 2MB), stored outside webroot, served via a PHP proxy

---

## 9. Admin Panel Hardening

- Admin panel at `/admin/` — separate Apache `<Location>` block with IP allowlist
- Separate session namespace: `ogm_admin_sessions` table with stricter timeout (15 min)
- TOTP-based 2FA required for admin accounts (use `otphp` or implement RFC 6238 directly — single file, no Composer)
- All admin actions logged to `ogm_audit_log` with full before/after detail
- Admin accounts never share UUIDs with regular user accounts — separate `ogm_admins` table

---

## 10. Error Handling & Logging

- **Never expose stack traces or SQL errors to clients**
- Catch all exceptions at controller level — log internally, return generic error to client
- Log to file: `/var/log/osgridmanager/error.log` and `/var/log/osgridmanager/access.log`
- Log format: `[timestamp] [level] [ip] [user_uuid] [action] [detail]`
- Log security events specifically: failed logins, invalid tokens, rate limit hits, CSRF failures
- `error_reporting(0)` and `display_errors=Off` in production PHP config
- `log_errors=On` with `error_log=/var/log/osgridmanager/php_error.log`

---

## 11. Rate Limiting

Implement PHP token bucket rate limiter backed by `ogm_rate_limits` table:

| Endpoint group          | Limit              |
|-------------------------|--------------------|
| Auth / login            | 5 attempts / 5 min per IP |
| Economy transfers       | 30 / hour per user |
| Messaging send          | 20 / hour per user |
| Search                  | 60 / min per token |
| General API             | 120 / min per token |
| General API (no token)  | 10 / min per IP    |

---

## 12. Dependency Policy

- **No Composer autoloading** — all includes explicit
- Allowed standard PHP extensions: `pdo`, `pdo_mysql`, `openssl`, `mbstring`, `intl`, `xml`, `curl`, `json`, `hash`, `filter`
- If a third-party single-file library is absolutely needed (e.g., TOTP), it must be:
  - Vendored in `/src/vendor/`
  - License-compatible (MIT/BSD)
  - The smallest implementation available
  - Reviewed and commented in the codebase
- **No npm/node in production** — build step only if needed for CSS

---

## 13. OpenSim Database Access

- Connect to OpenSim DB as `opensim_readonly` — a MariaDB user with SELECT-only grants on the opensim schema
- Never modify OpenSim's core tables from OSGridManager (except: `UserAccounts.active`, `auth.passwordHash` via admin panel — clearly documented)
- If OpenSim DB is on a different host, use TLS for the MariaDB connection

---

## 14. XMLRPC Security

- XMLRPC endpoints validate caller via OpenSim's `secureSessionId` or via a configurable shared secret in `ogm_config`
- Limit accepted XMLRPC methods to an explicit whitelist — reject unknown methods
- Parse XMLRPC using PHP's native `xmlrpc_decode()` — never eval or exec
- Apply the same rate limiting as REST endpoints

---

## 15. Hypergrid ACL Enforcement

- Hypergrid ACL checks are advisory from OSGridManager's side (actual enforcement is in OpenSim config/modules)
- OSGridManager maintains the ACL database and provides an API for ROBUST to query
- Default policy configurable per `ogm_config.hypergrid_default` (allow/deny)
- Grid URI matching: normalize to lowercase, strip trailing slashes before comparison
