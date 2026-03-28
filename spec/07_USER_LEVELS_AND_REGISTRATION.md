# OSGridManager — User Levels, Roles & Registration

## Overview

OSGridManager operates with two independent permission axes:

| Axis | Where | Field | Managed by |
|------|-------|-------|------------|
| **OpenSim UserLevel** | OpenSim grid (inworld) | `UserAccounts.UserLevel` | Web admin panel |
| **OGM Web Role** | OSGridManager web app | `ogm_web_roles.role` | Web admin panel |

These are independent — a single OpenSim account can hold any combination. A level-200 inworld God may have no web admin role, and a web admin may be a level-0 normal user inworld.

---

## OpenSim UserLevel Definitions

OpenSim's `UserAccounts.UserLevel` is an integer that controls inworld permissions, feature access, and hypergrid policies. OSGridManager manages this field via the admin panel.

| Level | Name | Description | Key OpenSim Behaviour |
|-------|------|-------------|----------------------|
| `0` | Normal User | Default registered user | Standard access, no hypergrid by default |
| `1` | Hypergrid Allowed | User may use Hypergrid teleport | OpenSim checks this for HG teleport permission |
| `10` | Support User | Grid support staff | Can access restricted areas, assist users |
| `100` | Grid VIP / Trusted | Long-standing or privileged user | May bypass certain access restrictions |
| `200` | Inworld God / Operator | Full inworld admin / operator | `llGodLike()` returns true, can edit any region |

### UserLevel and Hypergrid

OSGridManager links UserLevel to Hypergrid access control:
- Users with `UserLevel < 1` are blocked from outbound Hypergrid teleports (configurable threshold in `ogm_config`)
- Inbound Hypergrid visitors are handled separately via `ogm_hypergrid_acl`
- Admin can set minimum UserLevel required for HG access via `ogm_config.hypergrid_min_userlevel`

### UserLevel Storage

`UserLevel` lives in OpenSim's `UserAccounts` table — OSGridManager writes to it via the `opensim_limited` DB user. Add the required grant:

```sql
-- Add to mariadb_setup.sql
GRANT UPDATE (UserLevel) ON opensim.UserAccounts TO 'opensim_limited'@'localhost';
```

---

## OGM Web Roles

Web roles are stored in OGM's own tables and control access to the web panel only. They have no inworld effect.

| Role | Access |
|------|--------|
| `user` | Own profile, wallet, messages, search |
| `moderator` | Read-only view of all users, flag messages, view audit log |
| `webadmin` | Full web panel access: manage users, regions, economy, config |

### Role Overlap with Inworld

A single OpenSim account (identified by UUID) can have:
- Any `UserLevel` (0, 1, 10, 100, 200)
- Any OGM web role (`user`, `moderator`, `webadmin`)

Example: The grid operator might have `UserLevel=200` AND `ogm_web_role=webadmin`. A builder might have `UserLevel=100` and `ogm_web_role=user`.

### Role Storage Schema Addition

Add to `02_DATABASE_SCHEMA.sql` (Module 10 — User Levels & Roles):

```sql
-- OGM web roles — extends session/auth for web panel access control
CREATE TABLE IF NOT EXISTS ogm_web_roles (
    user_uuid   CHAR(36)                            NOT NULL,
    role        ENUM('user','moderator','webadmin') NOT NULL DEFAULT 'user',
    granted_by  CHAR(36)                            NULL,
    granted_at  DATETIME                            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes       VARCHAR(512)                        NULL,
    PRIMARY KEY (user_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- UserLevel change audit (OpenSim field — track all changes)
CREATE TABLE IF NOT EXISTS ogm_userlevel_history (
    history_id      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uuid       CHAR(36)        NOT NULL,
    old_level       SMALLINT        NOT NULL,
    new_level       SMALLINT        NOT NULL,
    changed_by      CHAR(36)        NOT NULL,   -- web admin UUID
    reason          VARCHAR(512)    NULL,
    changed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    INDEX idx_user (user_uuid),
    INDEX idx_changed (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Session Role Handling

When a user logs into the web panel, OGM:
1. Loads their OpenSim `UserAccounts` record
2. Looks up their `ogm_web_roles` entry (defaults to `user` if absent)
3. Loads their `UserLevel` from OpenSim
4. Stores both in the session: `{ web_role: 'webadmin', userlevel: 200 }`

The `Auth` class exposes:
- `Auth::hasWebRole(string $role): bool` — checks session web role
- `Auth::getUserLevel(): int` — returns OpenSim UserLevel from session
- `Auth::requireWebRole(string $minRole): void` — redirects if insufficient

Role hierarchy for `requireWebRole()`: `user` < `moderator` < `webadmin`

---

## User Registration Flow

Registration is **optional** and **fully configurable**. All settings live in `ogm_config`.

### Configuration Flags

| Config Key | Type | Default | Description |
|------------|------|---------|-------------|
| `registration_enabled` | bool | `0` | Master switch — enables/disables registration |
| `registration_email_verify` | bool | `1` | Require email verification step |
| `registration_admin_approval` | bool | `1` | Require web admin approval after verification |
| `registration_default_userlevel` | int | `0` | UserLevel assigned to new accounts |
| `registration_default_web_role` | string | `user` | Web role assigned on approval |
| `registration_notification_email` | string | `` | Admin email to notify on new registrations |
| `registration_smtp_host` | string | `localhost` | SMTP host for outbound email |
| `registration_smtp_port` | int | `587` | SMTP port |
| `registration_smtp_user` | string | `` | SMTP username (blank = no auth) |
| `registration_smtp_pass` | string | `` | SMTP password (stored encrypted) |
| `registration_smtp_from` | string | `` | From address for registration emails |
| `registration_smtp_tls` | bool | `1` | Use STARTTLS |

Add these to the `INSERT INTO ogm_config` seed block in `02_DATABASE_SCHEMA.sql`.

### Registration Schema Addition

```sql
-- Pending registrations (pre-activation state)
CREATE TABLE IF NOT EXISTS ogm_registrations (
    reg_id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_uuid           CHAR(36)        NOT NULL UNIQUE,    -- UUID pre-generated at form submit
    avatar_firstname    VARCHAR(64)     NOT NULL,
    avatar_lastname     VARCHAR(64)     NOT NULL,
    email               VARCHAR(255)    NOT NULL,
    password_hash       CHAR(60)        NOT NULL,           -- bcrypt, stored before account creation
    email_token         CHAR(64)        NOT NULL,           -- SHA256 hex for email verification link
    email_verified_at   DATETIME        NULL,
    admin_approved_by   CHAR(36)        NULL,               -- web admin UUID who approved
    admin_approved_at   DATETIME        NULL,
    admin_rejected_at   DATETIME        NULL,
    rejection_reason    VARCHAR(512)    NULL,
    status              ENUM(
                          'pending_email',     -- awaiting email verification
                          'pending_approval',  -- email verified, awaiting admin
                          'approved',          -- account created in OpenSim
                          'rejected',          -- rejected by admin
                          'expired'            -- token expired, never verified
                        ) NOT NULL DEFAULT 'pending_email',
    ip_address          VARCHAR(45)     NOT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME        NOT NULL,           -- 48h for email verify step
    PRIMARY KEY (reg_id),
    INDEX idx_uuid (user_uuid),
    INDEX idx_status (status),
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Registration Flow (State Machine)

```
[Visitor] --> /register (form: firstname, lastname, email, password, password confirm)
     |
     | POST /register
     v
[OSGridManager]
  1. Validate all inputs
  2. Check: avatar name not already taken (query OpenSim UserAccounts)
  3. Check: email not already registered
  4. Check: rate limit (max 3 registrations per IP per hour)
  5. Generate user_uuid (UUID v4)
  6. bcrypt password
  7. Generate email_token = bin2hex(random_bytes(32))
  8. Insert into ogm_registrations (status: pending_email, expires_at: +48h)
  9. Send verification email (see Email Templates below)
 10. Show "Check your email" confirmation page

     |
     | User clicks email link: GET /register/verify?token=<email_token>
     v
[OSGridManager]
  1. Look up token in ogm_registrations
  2. Check: not expired, status = pending_email
  3. Mark email_verified_at = NOW()

  IF registration_admin_approval = OFF:
    --> Jump directly to APPROVAL step below

  IF registration_admin_approval = ON:
    4. Set status = pending_approval
    5. Send admin notification email (to registration_notification_email)
    6. Show "Awaiting admin approval" page to user

     |
     | Admin logs into web panel → /admin/registrations
     | Admin clicks Approve or Reject
     v
[OSGridManager — Admin Approval]
  ON APPROVE:
    1. Create OpenSim account via direct DB insert into UserAccounts + auth tables
       (same method as OpenSim itself — bcrypt hash already stored in ogm_registrations)
    2. Set UserAccounts.UserLevel = registration_default_userlevel
    3. Set UserAccounts.Active = 1
    4. Insert ogm_web_roles (role = registration_default_web_role)
    5. Insert ogm_profiles (defaults)
    6. Insert ogm_economy_balances (balance = new_user_balance config)
    7. Set ogm_registrations.status = approved
    8. Send approval email to user
    9. Log to ogm_audit_log

  ON REJECT:
    1. Set ogm_registrations.status = rejected
    2. Set rejection_reason
    3. Send rejection email to user (reason included if configured)
    4. Log to ogm_audit_log

     |
     | User receives approval email → clicks login link
     v
[User logs in normally via /login]
```

### Flow Variants

**Variant A: Registration OFF**
- `/register` returns 404 or "Registration closed" page
- No registration links shown anywhere

**Variant B: Registration ON, email verify ON, admin approval OFF**
- After email verify: account immediately created and activated
- User gets "Account created — you can now log in" email

**Variant C: Registration ON, email verify OFF, admin approval ON**
- Immediately goes to `pending_approval` after form submit
- Admin approves → account created
- *(Not recommended — allows unverified emails, but supported)*

**Variant D: Registration ON, email verify OFF, admin approval OFF**
- Immediate account creation on form submit
- Useful for invite-only situations where email is pre-verified externally

### Account Creation in OpenSim (DB Direct)

When approving a registration, OSGridManager creates the OpenSim account by inserting directly into OpenSim's DB. This mirrors what ROBUST does:

```sql
-- Insert into OpenSim UserAccounts
INSERT INTO UserAccounts
  (PrincipalID, ScopeID, FirstName, LastName, Email,
   ServiceURLs, Created, UserLevel, UserFlags, UserTitle, Active)
VALUES
  (:uuid, '00000000-0000-0000-0000-000000000000',
   :firstname, :lastname, :email,
   'HomeURI= GatekeeperURI= InventoryServerURI= AssetServerURI= ',
   UNIX_TIMESTAMP(), :userlevel, 0, '', 1);

-- Insert into auth table (bcrypt)
INSERT INTO auth (UUID, passwordHash, passwordSalt, webLoginKey)
VALUES (:uuid, :bcrypt_hash, '', '00000000-0000-0000-0000-000000000000');
```

> **Note for Claude Code:** The `ScopeID` for single-grid setups is always the null UUID. `ServiceURLs` format must match exactly what your ROBUST version expects — verify against an existing account row before implementing. The `auth.passwordSalt` field is unused in bcrypt mode (OpenSim detects bcrypt by the `$2y$` prefix) — store empty string.

---

## Email Templates

All emails sent as plaintext (no HTML) for maximum compatibility and deliverability.

### 1. Email Verification

```
Subject: [GridName] Please verify your email address

Hello [FirstName],

Thank you for registering on [GridName].

Please verify your email address by clicking the link below:
[BaseURL]/register/verify?token=[email_token]

This link expires in 48 hours.

If you did not register, please ignore this email.

-- [GridName] Team
```

### 2. Admin Notification (new pending registration)

```
Subject: [GridName] New registration awaiting approval: [FirstName] [LastName]

A new user has completed email verification and is awaiting your approval.

Avatar Name: [FirstName] [LastName]
Email: [email]
Registered: [timestamp]
IP Address: [ip]

Review and approve or reject at:
[BaseURL]/admin/registrations

-- OSGridManager Notification
```

### 3. Approval Email

```
Subject: [GridName] Your account has been approved!

Hello [FirstName],

Your account on [GridName] has been approved. You can now log in.

Avatar Name: [FirstName] [LastName]
Login: [BaseURL]/login

Welcome to the grid!

-- [GridName] Team
```

### 4. Rejection Email

```
Subject: [GridName] Registration update

Hello [FirstName],

We have reviewed your registration for [GridName].

Unfortunately, we are unable to approve your account at this time.
[IF rejection_reason]: Reason: [rejection_reason]

If you believe this is an error, please contact the grid administrator.

-- [GridName] Team
```

---

## SMTP Implementation

No external mail library. Implement a minimal `Mailer` class using PHP's `mail()` with SMTP via `stream_socket_client()` for direct SMTP, or configure PHP's `sendmail_path` to use `msmtp` / `ssmtp`.

**Recommended approach:** Configure `msmtp` as the system MTA and use PHP's `mail()` function. This keeps the PHP code simple and delegates SMTP complexity to a battle-tested tool.

```bash
# Install msmtp
sudo apt install msmtp msmtp-mta

# /etc/msmtprc
account default
host smtp.example.com
port 587
tls on
tls_starttls on
auth on
user mailuser@example.com
password CHANGEME
from noreply@grid.example.com
logfile /var/log/osgridmanager/msmtp.log
```

The `Mailer` class (`src/Core/Mailer.php`) should:
- Use `mail()` with proper headers
- Sanitize all inputs (especially `To:` header — injection risk)
- Log every sent email to `ogm_audit_log` (action: `email.sent`, detail: to/subject/type)
- Catch and log failures without exposing errors to users

---

## Web Admin: User Level Management

### Admin UI Additions

**`/admin/users/{uuid}`** — User detail page, now includes:
- Current `UserLevel` with dropdown to change (0, 1, 10, 100, 200)
- Optional reason field for level changes
- UserLevel change history (from `ogm_userlevel_history`)
- Current OGM web role with dropdown to change
- "Force logout" button (deletes all sessions)

**`/admin/registrations`** — Registration queue:
- Table: pending approvals (status = `pending_approval`)
- Columns: avatar name, email, registered at, IP, verified at
- Actions: Approve / Reject (with reason)
- Tabs: Pending | Approved | Rejected | Expired
- Filter by date range

### UserLevel Change Logic (in `AdminUserController.php`)

```
1. Validate new level is in allowed set {0, 1, 10, 100, 200}
2. Read current level from OpenSim UserAccounts
3. If new == current: no-op, return
4. UPDATE opensim.UserAccounts SET UserLevel = :new WHERE PrincipalID = :uuid
5. INSERT INTO ogm_userlevel_history (old, new, changed_by, reason)
6. INSERT INTO ogm_audit_log (action: 'userlevel.changed', detail: {old, new, reason})
7. If level dropped below hypergrid_min_userlevel: optionally revoke active user tokens
```

---

## Land Management

See `08_LAND_MANAGEMENT.md` for the full land module spec.
(Separated for clarity — land management references this file for user level context.)
