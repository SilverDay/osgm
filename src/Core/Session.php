<?php declare(strict_types=1);

namespace OGM\Core;

/**
 * DB-backed session manager.
 *
 * Sessions are stored in ogm_sessions, not in PHP's default file handler.
 * Session IDs are 64-char hex strings (32 random bytes).
 * Session data is serialised PHP stored in a single in-memory array;
 * the array is persisted to the DB on each write operation.
 *
 * Binding: IP address + SHA-256 of User-Agent.
 * Timeout: configurable (default 30 min inactivity).
 *
 * Cookie name: ogm_sid
 * Cookie flags: HttpOnly, Secure, SameSite=Strict
 */
class Session
{
    private const COOKIE_NAME  = 'ogm_sid';
    private const ID_BYTES     = 32;
    private const DATA_COLUMN  = 'session_data';

    private static ?array  $data      = null;
    private static ?string $sessionId = null;
    private static bool    $started   = false;

    /**
     * Initialise the session for the current request.
     * Must be called once, before any Session::get/set usage.
     */
    public static function start(Request $request): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;
        self::$data    = [];

        $cookieId = $_COOKIE[self::COOKIE_NAME] ?? null;

        if ($cookieId !== null && preg_match('/^[0-9a-f]{64}$/', $cookieId)) {
            if (self::loadFromDb($cookieId, $request)) {
                self::$sessionId = $cookieId;
                return;
            }
            // Invalid/expired session — delete cookie and start fresh
            self::deleteCookie();
        }

        // No session — lazy creation on first write
        self::$data = [];
    }

    /**
     * Retrieve a value from session data.
     */
    public static function get(string $key): mixed
    {
        return self::$data[$key] ?? null;
    }

    /**
     * Store a value in the session. Creates a session row if one does not exist.
     */
    public static function set(string $key, mixed $value): void
    {
        if (self::$data === null) {
            self::$data = [];
        }
        self::$data[$key] = $value;

        if (self::$sessionId === null) {
            self::create();
        } else {
            self::persist();
        }
    }

    /**
     * Remove a key from the session.
     */
    public static function delete(string $key): void
    {
        unset(self::$data[$key]);
        if (self::$sessionId !== null) {
            self::persist();
        }
    }

    /**
     * Destroy the current session (logout).
     */
    public static function destroy(): void
    {
        if (self::$sessionId !== null) {
            try {
                $stmt = DB::getInstance()->ogmRw()->prepare(
                    'DELETE FROM ogm_sessions WHERE session_id = :sid'
                );
                $stmt->execute([':sid' => self::$sessionId]);
            } catch (\Throwable $e) {
                Logger::error('Session destroy failed: ' . $e->getMessage());
            }
        }
        self::deleteCookie();
        self::$data      = [];
        self::$sessionId = null;
    }

    /**
     * Regenerate the session ID (call after privilege escalation / login).
     */
    public static function regenerate(Request $request): void
    {
        $oldId = self::$sessionId;
        $newId = self::generateId();

        if ($oldId !== null) {
            try {
                $stmt = DB::getInstance()->ogmRw()->prepare(
                    'UPDATE ogm_sessions SET session_id = :new WHERE session_id = :old'
                );
                $stmt->execute([':new' => $newId, ':old' => $oldId]);
            } catch (\Throwable $e) {
                Logger::error('Session regenerate failed: ' . $e->getMessage());
            }
        }

        self::$sessionId = $newId;
        self::sendCookie($newId);
    }

    // -------------------------------------------------------------------------

    private static function loadFromDb(string $id, Request $request): bool
    {
        $timeoutMins = (int) Config::get('session_timeout_mins', 30);

        try {
            $stmt = DB::getInstance()->ogmRw()->prepare(
                'SELECT ' . self::DATA_COLUMN . ', ip_address, user_agent_hash, last_activity, expires_at
                 FROM ogm_sessions
                 WHERE session_id = :sid AND is_active = 1'
            );
            $stmt->execute([':sid' => $id]);
            $row = $stmt->fetch();
        } catch (\Throwable $e) {
            Logger::error('Session load failed: ' . $e->getMessage());
            return false;
        }

        if ($row === false) {
            return false;
        }

        // Check expiry
        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        if ($now > $expiresAt) {
            return false;
        }

        // Inactivity check
        $lastActivity = new \DateTimeImmutable($row['last_activity'], new \DateTimeZone('UTC'));
        if (($now->getTimestamp() - $lastActivity->getTimestamp()) > ($timeoutMins * 60)) {
            return false;
        }

        // IP binding (log mismatch but don't hard-fail — configurable in future)
        if ($row['ip_address'] !== $request->getIp()) {
            Logger::security('Session IP mismatch', [
                'action'   => 'session_ip_mismatch',
                'expected' => $row['ip_address'],
                'actual'   => $request->getIp(),
            ]);
            return false;
        }

        // User-Agent hash binding
        $uaHash = hash('sha256', $request->getUserAgent());
        if (!hash_equals($row['user_agent_hash'], $uaHash)) {
            Logger::security('Session UA mismatch', ['action' => 'session_ua_mismatch']);
            return false;
        }

        // Update last_activity
        try {
            $newExpiry = date('Y-m-d H:i:s', strtotime("+{$timeoutMins} minutes"));
            $stmt = DB::getInstance()->ogmRw()->prepare(
                'UPDATE ogm_sessions
                 SET last_activity = NOW(), expires_at = :exp
                 WHERE session_id = :sid'
            );
            $stmt->execute([':exp' => $newExpiry, ':sid' => $id]);
        } catch (\Throwable $e) {
            Logger::error('Session activity update failed: ' . $e->getMessage());
        }

        // Unserialise stored data
        $raw = $row[self::DATA_COLUMN] ?? '';
        self::$data = $raw !== '' && $raw !== null
            ? (unserialize($raw) ?: [])
            : [];

        return true;
    }

    private static function create(): void
    {
        // Session::start() must have been called with a Request — recover it from globals
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $uaHash  = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $id      = self::generateId();
        $timeoutMins = (int) Config::get('session_timeout_mins', 30);
        $expiry  = date('Y-m-d H:i:s', strtotime("+{$timeoutMins} minutes"));

        try {
            $stmt = DB::getInstance()->ogmRw()->prepare(
                'INSERT INTO ogm_sessions
                 (session_id, user_uuid, ip_address, user_agent_hash, role,
                  ' . self::DATA_COLUMN . ', created_at, last_activity, expires_at, is_active)
                 VALUES
                 (:sid, :uuid, :ip, :ua, :role, :data, NOW(), NOW(), :exp, 1)'
            );
            $stmt->execute([
                ':sid'  => $id,
                ':uuid' => self::$data['user_uuid'] ?? '00000000-0000-0000-0000-000000000000',
                ':ip'   => $ip,
                ':ua'   => $uaHash,
                ':role' => self::$data['role'] ?? 'user',
                ':data' => serialize(self::$data),
                ':exp'  => $expiry,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Session create failed: ' . $e->getMessage());
            return;
        }

        self::$sessionId = $id;
        self::sendCookie($id);
    }

    private static function persist(): void
    {
        if (self::$sessionId === null) {
            return;
        }
        try {
            $stmt = DB::getInstance()->ogmRw()->prepare(
                'UPDATE ogm_sessions
                 SET ' . self::DATA_COLUMN . ' = :data, last_activity = NOW()
                 WHERE session_id = :sid'
            );
            $stmt->execute([
                ':data' => serialize(self::$data),
                ':sid'  => self::$sessionId,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Session persist failed: ' . $e->getMessage());
        }
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(self::ID_BYTES));
    }

    private static function sendCookie(string $id): void
    {
        $secure   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $timeoutMins = (int) Config::get('session_timeout_mins', 30);
        setcookie(self::COOKIE_NAME, $id, [
            'expires'  => time() + ($timeoutMins * 60),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function deleteCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
