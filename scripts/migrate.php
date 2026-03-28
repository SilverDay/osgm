#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * OSGridManager — Database Migration Runner
 *
 * Usage:
 *   php scripts/migrate.php              — apply all pending migrations
 *   php scripts/migrate.php status       — list applied / pending migrations
 *   php scripts/migrate.php --dry-run    — show what would be applied, no changes
 *
 * The database connection is read from config/config.php (or OGM_CONFIG env var).
 * Migrations live in schema/migrations/ and are applied in filename order.
 *
 * Each applied migration is recorded in ogm_migrations so it is never run twice.
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$appRoot = dirname(__DIR__);

require_once $appRoot . '/src/Core/Logger.php';
require_once $appRoot . '/src/Core/Config.php';
require_once $appRoot . '/src/Core/DB.php';
require_once $appRoot . '/src/Core/Validator.php';

$configPath = getenv('OGM_CONFIG');
if ($configPath !== false && $configPath !== '') {
    OGM\Core\Config::setConfigPath($configPath);
}

try {
    OGM\Core\Config::load();
} catch (\Throwable $e) {
    die("Config error: " . $e->getMessage() . "\n");
}

// ---------------------------------------------------------------------------
// Parse arguments
// ---------------------------------------------------------------------------

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$status = in_array('status', $args, true);
$args   = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--') && $a !== 'status'));

// ---------------------------------------------------------------------------
// Connect
// ---------------------------------------------------------------------------

$dsn      = OGM\Core\Config::file('db.ogm_rw.dsn');
$user     = OGM\Core\Config::file('db.ogm_rw.username');
$password = OGM\Core\Config::file('db.ogm_rw.password');

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (\Throwable $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// ---------------------------------------------------------------------------
// Ensure ogm_migrations tracking table exists
// ---------------------------------------------------------------------------

$pdo->exec("
    CREATE TABLE IF NOT EXISTS ogm_migrations (
        migration   VARCHAR(255)    NOT NULL,
        applied_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        checksum    CHAR(64)        NOT NULL,
        PRIMARY KEY (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------------------------------------------------------------------------
// Discover migration files
// ---------------------------------------------------------------------------

$migrationsDir = $appRoot . '/schema/migrations';
$files = glob($migrationsDir . '/*.sql');

if ($files === false || $files === []) {
    echo "No migration files found in schema/migrations/\n";
    exit(0);
}

sort($files); // lexicographic = numeric order given NNN_ prefix

// ---------------------------------------------------------------------------
// Load applied migrations from DB
// ---------------------------------------------------------------------------

$applied = [];
foreach ($pdo->query("SELECT migration, applied_at, checksum FROM ogm_migrations ORDER BY migration") as $row) {
    $applied[$row['migration']] = $row;
}

// ---------------------------------------------------------------------------
// Status command
// ---------------------------------------------------------------------------

if ($status) {
    echo "\nOSGridManager Migration Status\n";
    echo str_repeat('─', 70) . "\n";
    printf("  %-40s  %-10s  %s\n", 'Migration', 'Status', 'Applied at');
    echo str_repeat('─', 70) . "\n";

    foreach ($files as $file) {
        $name      = basename($file);
        $checksum  = hash('sha256', (string) file_get_contents($file));
        $isApplied = isset($applied[$name]);
        $changed   = $isApplied && !hash_equals($applied[$name]['checksum'], $checksum);

        if ($isApplied && $changed) {
            $statusLabel = 'MODIFIED';
        } elseif ($isApplied) {
            $statusLabel = 'applied';
        } else {
            $statusLabel = 'PENDING';
        }

        $appliedAt = $isApplied ? $applied[$name]['applied_at'] : '—';
        printf("  %-40s  %-10s  %s\n", $name, $statusLabel, $appliedAt);
    }

    $pending = count(array_filter($files, fn($f) => !isset($applied[basename($f)])));
    echo str_repeat('─', 70) . "\n";
    echo "  " . count($applied) . " applied, {$pending} pending\n\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Migrate command
// ---------------------------------------------------------------------------

$pending = array_filter($files, fn($f) => !isset($applied[basename($f)]));
$pending = array_values($pending);

if ($pending === []) {
    echo "Nothing to migrate — all migrations are applied.\n";
    exit(0);
}

echo "\nOSGridManager Migration Runner" . ($dryRun ? ' [DRY RUN]' : '') . "\n";
echo str_repeat('─', 60) . "\n";
echo count($pending) . " pending migration(s) to apply:\n\n";

foreach ($pending as $file) {
    echo "  • " . basename($file) . "\n";
}

echo "\n";

if ($dryRun) {
    echo "Dry run complete — no changes made.\n\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Apply each pending migration
// ---------------------------------------------------------------------------

$exitCode = 0;

foreach ($pending as $file) {
    $name     = basename($file);
    $sql      = file_get_contents($file);
    $checksum = hash('sha256', (string) $sql);

    echo "Applying {$name} ... ";

    $statements = parseSqlStatements((string) $sql);

    try {
        $pdo->beginTransaction();

        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }

        // Record migration
        $ins = $pdo->prepare(
            'INSERT INTO ogm_migrations (migration, checksum) VALUES (:name, :checksum)'
        );
        $ins->execute([':name' => $name, ':checksum' => $checksum]);

        $pdo->commit();
        echo "OK\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo "FAILED\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "\nMigration aborted. Fix the error and re-run.\n\n";
        $exitCode = 1;
        break;
    }
}

if ($exitCode === 0) {
    echo "\nAll migrations applied successfully.\n\n";
}

exit($exitCode);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Split a SQL file into individual statements.
 *
 * Handles:
 *   - Single-line comments (-- ...)
 *   - Multi-line comments (/* ... *\/)
 *   - Quoted strings (won't split on ; inside quotes)
 *   - Blank / whitespace-only statements
 *
 * Does NOT handle DELIMITER changes or stored procedures — not needed here.
 */
function parseSqlStatements(string $sql): array
{
    $statements = [];
    $current    = '';
    $inSingle   = false;  // inside '...'
    $inDouble   = false;  // inside "..."
    $inBlock    = false;  // inside /* ... */
    $len        = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        // Block comment start
        if (!$inSingle && !$inDouble && $ch === '/' && $next === '*') {
            $inBlock = true;
            $i++;
            continue;
        }
        // Block comment end
        if ($inBlock && $ch === '*' && $next === '/') {
            $inBlock = false;
            $i++;
            continue;
        }
        if ($inBlock) {
            continue;
        }

        // Line comment
        if (!$inSingle && !$inDouble && $ch === '-' && $next === '-') {
            // Skip to end of line
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        // String delimiters
        if ($ch === "'" && !$inDouble) {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle) {
            $inDouble = !$inDouble;
        }

        // Statement terminator
        if ($ch === ';' && !$inSingle && !$inDouble) {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    // Trailing statement without semicolon
    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}
