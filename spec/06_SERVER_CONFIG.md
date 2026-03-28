# OSGridManager — Server & Apache Configuration

## Ubuntu 22.04 Package Requirements

```bash
# PHP 8.3 via PPA
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y \
  php8.3 \
  php8.3-cli \
  php8.3-fpm \
  php8.3-mysql \
  php8.3-mbstring \
  php8.3-xml \
  php8.3-curl \
  php8.3-intl \
  php8.3-opcache \
  apache2 \
  libapache2-mod-fcgid \
  mariadb-server \
  certbot \
  python3-certbot-apache

# Enable Apache modules
sudo a2enmod rewrite headers ssl proxy_fcgi setenvif
sudo a2enconf php8.3-fpm
```

---

## PHP 8.3 Configuration (`/etc/php/8.3/fpm/php.ini` overrides)

Create `/etc/php/8.3/fpm/conf.d/99-osgridmanager.ini`:

```ini
; Security
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/osgridmanager/php_error.log
error_reporting = E_ALL
expose_php = Off

; Session (disabled — using DB sessions)
session.use_cookies = 1
session.use_only_cookies = 1
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1

; Limits
max_execution_time = 30
max_input_time = 30
memory_limit = 128M
post_max_size = 8M
upload_max_filesize = 4M
max_file_uploads = 5

; Timezone
date.timezone = UTC

; Opcache
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 4000
opcache.validate_timestamps = 0  ; set to 1 during development
```

---

## Apache Virtual Host Configuration

### Main Site (`/etc/apache2/sites-available/osgridmanager.conf`)

```apache
<VirtualHost *:80>
    ServerName grid.example.com
    Redirect permanent / https://grid.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName grid.example.com
    DocumentRoot /var/www/osgridmanager/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/grid.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/grid.example.com/privkey.pem

    # PHP-FPM
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Security headers (applied globally)
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; frame-ancestors 'none'"
    Header unset X-Powered-By
    Header unset Server

    # Main site document root
    <Directory /var/www/osgridmanager/public>
        Options -Indexes -ExecCGI
        AllowOverride All
        Require all granted
    </Directory>

    # Admin panel — restrict to admin IPs
    <Location /admin>
        Require ip 127.0.0.1 ::1
        # Add admin office IP:
        # Require ip 192.168.1.0/24
    </Location>

    # API endpoint — allow all (auth handled in PHP)
    <Location /api>
        Require all granted
    </Location>

    # XMLRPC endpoint — restrict to localhost + known OpenSim server IPs
    <Location /xmlrpc>
        Require ip 127.0.0.1 ::1
        # Add OpenSim server IP(s):
        # Require ip 10.0.0.0/8
    </Location>

    # Deny access to sensitive paths
    <LocationMatch "^/(config|src|schema|templates|scripts)">
        Require all denied
    </LocationMatch>

    # Rate limiting (basic — supplement with PHP-level limiting)
    <Location /api/v1/auth>
        # mod_ratelimit if available
    </Location>

    ErrorLog ${APACHE_LOG_DIR}/osgridmanager_error.log
    CustomLog ${APACHE_LOG_DIR}/osgridmanager_access.log combined
</VirtualHost>
```

---

## Public `.htaccess` (`/var/www/osgridmanager/public/.htaccess`)

```apache
Options -Indexes -ExecCGI
DirectoryIndex index.php

# Route all requests through front controller
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L,QSA]

# Block hidden files
RewriteRule (^|/)\. - [F,L]

# Disable ETags (fingerprinting mitigation)
FileETag None

# Cache static assets
<FilesMatch "\.(css|js|png|jpg|gif|ico|woff2?)$">
    Header set Cache-Control "public, max-age=2592000"
</FilesMatch>

# No caching for PHP
<FilesMatch "\.php$">
    Header set Cache-Control "no-store, no-cache, must-revalidate"
    Header set Pragma "no-cache"
</FilesMatch>
```

---

## MariaDB Hardening

```sql
-- Run after mysql_secure_installation

-- Create databases
CREATE DATABASE osgridmanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- OpenSim DB already exists, e.g.: opensim

-- OGM read-write user (for economy, messaging, sessions)
CREATE USER 'ogm_rw'@'localhost' IDENTIFIED BY '<strong_password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON osgridmanager.* TO 'ogm_rw'@'localhost';

-- OGM read-only user (for search, profile reads)
CREATE USER 'ogm_ro'@'localhost' IDENTIFIED BY '<strong_password>';
GRANT SELECT ON osgridmanager.* TO 'ogm_ro'@'localhost';

-- OGM admin user (for user management — used only in admin panel)
CREATE USER 'ogm_admin'@'localhost' IDENTIFIED BY '<strong_password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES ON osgridmanager.* TO 'ogm_admin'@'localhost';

-- OpenSim read-only user
CREATE USER 'opensim_ro'@'localhost' IDENTIFIED BY '<strong_password>';
GRANT SELECT ON opensim.* TO 'opensim_ro'@'localhost';

-- OpenSim limited write (for password reset, account enable/disable only)
CREATE USER 'opensim_limited'@'localhost' IDENTIFIED BY '<strong_password>';
GRANT SELECT ON opensim.* TO 'opensim_limited'@'localhost';
GRANT UPDATE (Active, Email) ON opensim.UserAccounts TO 'opensim_limited'@'localhost';
GRANT UPDATE (passwordHash, passwordSalt) ON opensim.auth TO 'opensim_limited'@'localhost';

FLUSH PRIVILEGES;
```

---

## Config File (`/etc/osgridmanager/config.php`)

```php
<?php
// OSGridManager Configuration
// This file is outside the webroot — never expose to web server

return [
    // Database connections
    'db' => [
        'ogm_rw' => [
            'dsn'      => 'mysql:host=127.0.0.1;dbname=osgridmanager;charset=utf8mb4',
            'username' => 'ogm_rw',
            'password' => 'CHANGEME',
            'options'  => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        ],
        'ogm_ro' => [
            'dsn'      => 'mysql:host=127.0.0.1;dbname=osgridmanager;charset=utf8mb4',
            'username' => 'ogm_ro',
            'password' => 'CHANGEME',
            'options'  => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        ],
        'ogm_admin' => [
            'dsn'      => 'mysql:host=127.0.0.1;dbname=osgridmanager;charset=utf8mb4',
            'username' => 'ogm_admin',
            'password' => 'CHANGEME',
            'options'  => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        ],
        'opensim_ro' => [
            'dsn'      => 'mysql:host=127.0.0.1;dbname=opensim;charset=utf8mb4',
            'username' => 'opensim_ro',
            'password' => 'CHANGEME',
            'options'  => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        ],
        'opensim_limited' => [
            'dsn'      => 'mysql:host=127.0.0.1;dbname=opensim;charset=utf8mb4',
            'username' => 'opensim_limited',
            'password' => 'CHANGEME',
            'options'  => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        ],
    ],

    // Application
    'app' => [
        'base_url'        => 'https://grid.example.com',
        'env'             => 'production', // 'development' | 'production'
        'log_dir'         => '/var/log/osgridmanager',
        'upload_dir'      => '/var/lib/osgridmanager/uploads',
        'config_table_db' => 'ogm_rw', // which connection to use for ogm_config reads
    ],

    // Trusted proxy IPs (for X-Forwarded-For handling)
    'trusted_proxies' => [
        '127.0.0.1',
    ],

    // OpenSim grid settings (static — for reference, runtime in ogm_config)
    'opensim' => [
        'grid_uri'     => 'grid.example.com:8002',
        'robust_admin_user'     => '', // optional: ROBUST admin user for REST calls
        'robust_admin_password' => '',
    ],
];
```

---

## Cron Jobs (`/etc/cron.d/osgridmanager`)

```cron
# Rebuild search cache every hour
0 * * * * www-data php /var/www/osgridmanager/scripts/rebuild_search_cache.php >> /var/log/osgridmanager/cron.log 2>&1

# Clean expired sessions every 15 minutes
*/15 * * * * www-data php /var/www/osgridmanager/scripts/cleanup_sessions.php >> /var/log/osgridmanager/cron.log 2>&1

# Clean expired user tokens every hour
0 * * * * www-data php /var/www/osgridmanager/scripts/cleanup_tokens.php >> /var/log/osgridmanager/cron.log 2>&1

# Clean expired rate limit buckets every 6 hours
0 */6 * * * www-data php /var/www/osgridmanager/scripts/cleanup_ratelimits.php >> /var/log/osgridmanager/cron.log 2>&1

# Release expired economy holds every 5 minutes
*/5 * * * * www-data php /var/www/osgridmanager/scripts/release_economy_holds.php >> /var/log/osgridmanager/cron.log 2>&1
```

---

## Log Directory Setup

```bash
sudo mkdir -p /var/log/osgridmanager
sudo chown www-data:www-data /var/log/osgridmanager
sudo chmod 750 /var/log/osgridmanager

sudo mkdir -p /var/lib/osgridmanager/uploads
sudo chown www-data:www-data /var/lib/osgridmanager/uploads
sudo chmod 750 /var/lib/osgridmanager/uploads

# logrotate config
cat > /etc/logrotate.d/osgridmanager << 'EOF'
/var/log/osgridmanager/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 640 www-data www-data
}
EOF
```
