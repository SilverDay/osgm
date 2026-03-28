<?php
// OSGridManager Configuration
// Deploy this file to /etc/osgridmanager/config.php (outside webroot)
// Never commit passwords or secrets to version control.

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
            'options'  => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        ],
        'ogm_admin' => [
            'dsn'      => 'mysql:host=127.0.0.1;dbname=osgridmanager;charset=utf8mb4',
            'username' => 'ogm_admin',
            'password' => 'CHANGEME',
            'options'  => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        ],
        'opensim_ro' => [
            'dsn'      => 'mysql:host=127.0.0.1;dbname=opensim;charset=utf8mb4',
            'username' => 'opensim_ro',
            'password' => 'CHANGEME',
            'options'  => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        ],
        'opensim_limited' => [
            'dsn'      => 'mysql:host=127.0.0.1;dbname=opensim;charset=utf8mb4',
            'username' => 'opensim_limited',
            'password' => 'CHANGEME',
            'options'  => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        ],
    ],

    // Application
    'app' => [
        'base_url'        => 'https://grid.example.com',
        'env'             => 'production', // 'development' | 'production'
        'log_dir'         => '/var/log/osgridmanager',
        'upload_dir'      => '/var/lib/osgridmanager/uploads',
        'config_table_db' => 'ogm_rw',
    ],

    // Trusted proxy IPs (for X-Forwarded-For handling)
    'trusted_proxies' => [
        '127.0.0.1',
    ],

    // OpenSim grid settings (static — runtime settings live in ogm_config table)
    'opensim' => [
        'grid_uri'              => 'grid.example.com:8002',
        'robust_admin_user'     => '',
        'robust_admin_password' => '',
    ],
];
