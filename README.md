# OSGridManager

A self-hosted LAMP-based web management platform for private [OpenSimulator](http://opensimulator.org/) grids running in ROBUST (grid mode).

Built as a modern, security-first replacement for jOpenSim — with no large framework dependencies.

---

## Features

- **User self-service** — profile, wallet, messaging, transaction history
- **Admin panel** — user management, region management, economy oversight, audit log
- **Inworld REST API** — LSL-callable HTTP endpoints for money, profiles, messaging, search
- **XMLRPC endpoints** — compatible with OpenSim's economy and profile modules
- **Hypergrid ACL** — allowlist/denylist management for hypergrid visitors
- **Registration workflow** — configurable with email verification and/or admin approval
- **OpenSim UserLevel management** — set inworld permissions from the web panel

---

## Tech Stack

| Layer | Technology |
|---|---|
| OS | Ubuntu 22.04 LTS |
| Web server | Apache 2.4 + PHP-FPM |
| Database | MariaDB 10.6+ |
| Language | PHP 8.3 (strict types, no framework) |
| Frontend | Vanilla JS + plain CSS (no React, no jQuery) |
| Auth | DB-backed sessions + HMAC API tokens |
| Inworld API | REST over HTTPS + XMLRPC |

No Composer. No Laravel. No Symfony. All dependencies are standard PHP extensions.

---

## Requirements

- PHP 8.3 with extensions: `pdo_mysql`, `mbstring`, `xml`, `curl`, `intl`, `openssl`, `opcache`
- Apache 2.4 with `mod_rewrite`, `mod_headers`
- MariaDB 10.6+
- An existing OpenSimulator ROBUST grid database

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/SilverDay/osgm.git /srv/vhosts/osgm.silverday.de
```

### 2. Configure the application

```bash
cp config/config.example.php config/config.php
nano config/config.php
```

Fill in your MariaDB credentials for all five connection entries (`ogm_rw`, `ogm_ro`, `ogm_admin`, `opensim_ro`, `opensim_limited`), your base URL, and your log/upload directories.

> `config/config.php` is excluded from version control. Never commit it.

### 3. Create the database

```bash
mysql -u root -p < schema/ogm_schema.sql
```

### 4. Create MariaDB users

```bash
mysql -u root -p < deploy/mariadb_setup.sql
```

### 5. Configure Apache

Copy and adapt the virtual host from `deploy/apache_vhost.conf`. Key points:
- `DocumentRoot` → `public/`
- `/admin` location restricted to your admin IP(s)
- `/xmlrpc` restricted to localhost and OpenSim server IPs
- `/config`, `/src`, `/schema`, `/templates`, `/scripts` all denied from web

### 6. Set up cron jobs

```bash
sudo cp deploy/cron.d_osgridmanager /etc/cron.d/osgridmanager
```

### 7. Create log and upload directories

```bash
sudo mkdir -p /var/log/osgridmanager /var/lib/osgridmanager/uploads
sudo chown www-data:www-data /var/log/osgridmanager /var/lib/osgridmanager/uploads
sudo chmod 750 /var/log/osgridmanager /var/lib/osgridmanager/uploads
```

---

## Configuration

Runtime settings (grid name, currency, registration flags, etc.) are stored in the `ogm_config` database table and editable via the admin panel. Static settings (DB credentials, file paths) live in `config/config.php`.

Key runtime config keys:

| Key | Default | Description |
|---|---|---|
| `grid_name` | `My OpenSim Grid` | Displayed grid name |
| `currency_name` | `GridBucks` | Currency display name |
| `new_user_balance` | `1000` | Starting balance for new accounts |
| `registration_enabled` | `0` | Enable/disable self-registration |
| `registration_email_verify` | `1` | Require email verification |
| `registration_admin_approval` | `1` | Require admin approval |
| `hypergrid_enabled` | `1` | Enable hypergrid features |
| `session_timeout_mins` | `30` | Web session inactivity timeout |

---

## Project Structure

```
/
├── config/               # Local config (config.php gitignored)
│   └── config.example.php
├── public/               # Apache DocumentRoot
│   ├── index.php         # Front controller
│   ├── .htaccess
│   └── assets/
├── src/
│   ├── Core/             # Framework: Router, DB, Session, Auth, Validator, ...
│   ├── Modules/          # Feature modules: User, Profile, Economy, Messaging, ...
│   └── Admin/            # Admin panel controllers
├── api/                  # REST API (LSL-callable)
│   └── v1/
├── xmlrpc/               # XMLRPC endpoints (OpenSim economy/profile)
├── templates/            # Plain PHP templates
├── schema/               # Database schema + migrations
├── scripts/              # Cron scripts
├── deploy/               # Apache vhost, MariaDB setup, cron config
├── examples/             # Example LSL scripts
└── spec/                 # Project specifications
```

---

## Security

- All SQL via PDO prepared statements — no string interpolation
- DB-backed sessions (not filesystem) with IP + User-Agent binding
- CSRF tokens on all state-changing forms
- Passwords never stored — verified against OpenSim's bcrypt/MD5 hashes
- API tokens stored as SHA-256 hashes only
- Admin panel IP-allowlisted at Apache level + separate session table + TOTP 2FA
- Strict CSP, HSTS, X-Frame-Options, and other security headers on all responses
- Rate limiting (token bucket) on all endpoints

---

## Implementation Status

| Phase | Description | Status |
|---|---|---|
| 1 | Foundation (Core framework) | Done |
| 2 | User & Auth | Planned |
| 2b | Registration & User Levels | Planned |
| 3 | Profile | Planned |
| 4 | Region Management | Planned |
| 4b | Land Management | Planned |
| 5 | Economy | Planned |
| 6 | Messaging & Notifications | Planned |
| 7 | REST API | Planned |
| 8 | Search | Planned |
| 9 | Hypergrid ACL | Planned |
| 10 | Admin Panel | Planned |

---

## License

Private project — SilverDay Media. Not open source.
