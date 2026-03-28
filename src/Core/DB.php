<?php declare(strict_types=1);

namespace OGM\Core;

/**
 * PDO wrapper managing all database connections.
 *
 * Connection names match config keys:
 *   ogm_rw       — OGM read-write (economy, messaging, sessions)
 *   ogm_ro       — OGM read-only  (search, profile reads)
 *   ogm_admin    — OGM admin-only (user management)
 *   opensim_ro   — OpenSim read-only
 *   opensim_limited — OpenSim limited write (password reset, UserLevel)
 */
class DB
{
    private static ?self $instance = null;

    /** @var array<string, \PDO> */
    private array $connections = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get (or lazy-create) a named connection from config.
     */
    public function connection(string $name): \PDO
    {
        if (!isset($this->connections[$name])) {
            $cfg = Config::file("db.{$name}");
            if (!is_array($cfg)) {
                throw new \RuntimeException("DB connection '{$name}' not found in config.");
            }
            $pdo = new \PDO(
                $cfg['dsn'],
                $cfg['username'],
                $cfg['password'],
                $cfg['options'] ?? []
            );
            // Always use UTC
            $pdo->exec("SET time_zone = '+00:00'");
            $this->connections[$name] = $pdo;
        }
        return $this->connections[$name];
    }

    // Convenience accessors -------------------------------------------------------

    public function ogmRw(): \PDO
    {
        return $this->connection('ogm_rw');
    }

    public function ogmRo(): \PDO
    {
        return $this->connection('ogm_ro');
    }

    public function ogmAdmin(): \PDO
    {
        return $this->connection('ogm_admin');
    }

    public function opensimRo(): \PDO
    {
        return $this->connection('opensim_ro');
    }

    public function opensimLimited(): \PDO
    {
        return $this->connection('opensim_limited');
    }

    // Static shorthand ------------------------------------------------------------

    public static function ogm(): \PDO
    {
        return self::getInstance()->ogmRw();
    }

    public static function opensim(): \PDO
    {
        return self::getInstance()->opensimRo();
    }
}
