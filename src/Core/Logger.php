<?php declare(strict_types=1);

namespace OGM\Core;

class Logger
{
    private static string $logDir = '/var/log/osgridmanager';

    public static function setLogDir(string $dir): void
    {
        self::$logDir = rtrim($dir, '/');
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', 'error.log', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', 'access.log', $message, $context);
    }

    public static function security(string $message, array $context = []): void
    {
        self::write('SECURITY', 'error.log', $message, $context);
    }

    private static function write(string $level, string $file, string $message, array $context): void
    {
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '-';
        $userUuid = $context['user_uuid'] ?? '-';
        $action   = $context['action'] ?? '-';

        unset($context['user_uuid'], $context['action']);

        $detail = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $line   = sprintf(
            "[%s] [%s] [%s] [%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $ip,
            $userUuid,
            $action,
            $message,
            $detail
        );

        $path = self::$logDir . '/' . $file;

        // Suppress errors — logging must never crash the application.
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
