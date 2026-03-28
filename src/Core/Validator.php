<?php declare(strict_types=1);

namespace OGM\Core;

/**
 * Central input validation class.
 *
 * All methods return the validated/sanitised value on success or null on failure.
 * Boolean helpers (uuid, inEnum) return bool.
 *
 * Never validate inline — always use this class.
 */
class Validator
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Validate an OpenSim-style UUID string (lowercase, hyphenated).
     */
    public static function uuid(string $v): bool
    {
        return (bool) preg_match(self::UUID_PATTERN, $v);
    }

    /**
     * Validate and return a positive integer within the given range.
     * Returns null on failure.
     */
    public static function positiveInt(
        mixed $v,
        int $min = 1,
        int $max = PHP_INT_MAX
    ): ?int {
        $int = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) {
            return null;
        }
        return (int) $int;
    }

    /**
     * Validate a non-empty string up to $maxLen characters (mb-safe).
     * Strips HTML tags (caller should also use h() on output).
     * Returns null if empty after stripping or over length.
     */
    public static function safeString(string $v, int $maxLen = 255): ?string
    {
        $stripped = strip_tags($v);
        $stripped = trim($stripped);
        if ($stripped === '' || mb_strlen($stripped) > $maxLen) {
            return null;
        }
        return $stripped;
    }

    /**
     * Like safeString but allows empty strings (returns '' rather than null when empty).
     */
    public static function safeStringOptional(string $v, int $maxLen = 255): ?string
    {
        $stripped = strip_tags($v);
        $stripped = trim($stripped);
        if (mb_strlen($stripped) > $maxLen) {
            return null;
        }
        return $stripped;
    }

    /**
     * Validate an email address. Returns the normalised address or null.
     */
    public static function email(string $v): ?string
    {
        $filtered = filter_var(trim($v), FILTER_VALIDATE_EMAIL);
        return $filtered !== false ? (string) $filtered : null;
    }

    /**
     * Validate a URL. Only $allowedSchemes are accepted (default: https only).
     * Returns the URL or null.
     */
    public static function url(string $v, array $allowedSchemes = ['https']): ?string
    {
        $url = filter_var(trim($v), FILTER_VALIDATE_URL);
        if ($url === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, $allowedSchemes, true)) {
            return null;
        }
        return $url;
    }

    /**
     * Strict enum membership check (uses identical comparison).
     */
    public static function inEnum(mixed $v, array $allowed): bool
    {
        return in_array($v, $allowed, true);
    }

    /**
     * Validate an OpenSim avatar name (FirstName LastName, ASCII letters, no spaces in each part).
     * Returns ['first' => ..., 'last' => ...] or null.
     */
    public static function avatarName(string $v): ?array
    {
        $parts = explode(' ', trim($v), 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$first, $last] = $parts;
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{1,62}$/', $first)) {
            return null;
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{1,62}$/', $last)) {
            return null;
        }
        return ['first' => $first, 'last' => $last];
    }

    /**
     * Validate a bcrypt password hash (starts with $2y$ and is 60 chars).
     */
    public static function bcryptHash(string $v): bool
    {
        return strlen($v) === 60 && str_starts_with($v, '$2y$');
    }

    /**
     * Sanitise a value for use in an email header (To:, Subject:).
     * Removes CR, LF, and NUL to prevent header injection.
     */
    public static function emailHeader(string $v): string
    {
        return str_replace(["\r", "\n", "\0"], '', $v);
    }

    /**
     * Normalise a grid URI for Hypergrid ACL matching.
     * Lowercases, strips scheme and trailing slashes.
     */
    public static function normaliseGridUri(string $v): string
    {
        $v = strtolower(trim($v));
        $v = preg_replace('#^https?://#', '', $v) ?? $v;
        return rtrim($v, '/');
    }

    /**
     * Validate and return a password that meets minimum complexity.
     * Returns null if the password is too short or too long.
     */
    public static function password(string $v, int $minLen = 8, int $maxLen = 128): ?string
    {
        $len = mb_strlen($v);
        if ($len < $minLen || $len > $maxLen) {
            return null;
        }
        return $v;
    }
}
