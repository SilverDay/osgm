<?php declare(strict_types=1);

namespace OGM\Core;

class Config
{
    private static array $file   = [];
    private static array $runtime = [];
    private static bool  $loaded  = false;

    private static string $configPath = '';

    /**
     * Override the config file path (useful for testing or non-standard deployments).
     */
    public static function setConfigPath(string $path): void
    {
        self::$configPath = $path;
        self::$loaded     = false;
    }

    /**
     * Load the static config file. Called once at bootstrap.
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        // Default: config/config.php two levels above this file (project root /config/)
        if (self::$configPath === '') {
            self::$configPath = dirname(__DIR__, 2) . '/config/config.php';
        }

        if (!is_readable(self::$configPath)) {
            throw new \RuntimeException(
                'Configuration file not found or not readable: ' . self::$configPath
            );
        }

        $data = require self::$configPath;

        if (!is_array($data)) {
            throw new \RuntimeException('Configuration file must return an array.');
        }

        self::$file  = $data;
        self::$loaded = true;

        // Configure logger log dir from file config
        $logDir = self::$file['app']['log_dir'] ?? '/var/log/osgridmanager';
        Logger::setLogDir($logDir);
    }

    /**
     * Get a value from the static config file using dot notation.
     * Example: Config::file('db.ogm_rw.password')
     */
    public static function file(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::dotGet(self::$file, $key, $default);
    }

    /**
     * Get a value from the runtime ogm_config table (cached in memory).
     * Falls back to $default if not set.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$runtime[$key] ?? $default;
    }

    /**
     * Load all runtime config values from the ogm_config table.
     * Call this after DB is initialised.
     */
    public static function loadRuntime(DB $db): void
    {
        try {
            $stmt = $db->ogmRw()->prepare(
                'SELECT config_key, config_value FROM ogm_config'
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                self::$runtime[$row['config_key']] = $row['config_value'];
            }
        } catch (\Throwable $e) {
            Logger::error('Failed to load runtime config: ' . $e->getMessage());
        }
    }

    /**
     * Update a runtime config value in DB and memory.
     */
    public static function set(string $key, string $value, string $updatedBy, DB $db): void
    {
        $stmt = $db->ogmRw()->prepare(
            'INSERT INTO ogm_config (config_key, config_value, updated_by)
             VALUES (:key, :value, :by)
             ON DUPLICATE KEY UPDATE config_value = :value, updated_by = :by'
        );
        $stmt->execute([':key' => $key, ':value' => $value, ':by' => $updatedBy]);
        self::$runtime[$key] = $value;
    }

    // -------------------------------------------------------------------------

    private static function dotGet(array $arr, string $key, mixed $default): mixed
    {
        $parts = explode('.', $key);
        $node  = $arr;
        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
