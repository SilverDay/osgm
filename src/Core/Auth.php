<?php declare(strict_types=1);

namespace OGM\Core;

/**
 * Authentication manager for web sessions.
 *
 * Credentials are verified against OpenSim's UserAccounts + auth tables.
 * Modern OpenSim uses bcrypt ($2y$); older installs used MD5($pass:$salt).
 * Both are detected and handled transparently.
 *
 * Web roles are loaded from ogm_web_roles; UserLevel from UserAccounts.
 * Both are stored in the session after login.
 */
class Auth
{
    /** Role hierarchy for requireWebRole(). */
    private const ROLE_LEVELS = ['user' => 0, 'moderator' => 1, 'webadmin' => 2];

    // -------------------------------------------------------------------------
    // Login / Logout
    // -------------------------------------------------------------------------

    /**
     * Attempt login with either avatar name ("FirstName LastName") or email.
     *
     * @return bool True on success.
     */
    public static function loginWeb(string $identifier, string $password, Request $request): bool
    {
        $identifier = trim($identifier);

        // Fetch the OpenSim account
        $account = self::findAccount($identifier);
        if ($account === null) {
            Logger::security('Login failed: account not found', [
                'action' => 'login_failed',
                'id'     => substr($identifier, 0, 64),
            ]);
            return false;
        }

        // Check account is active
        if ((int) $account['Active'] !== 1) {
            Logger::security('Login failed: account inactive', [
                'action'    => 'login_failed_inactive',
                'user_uuid' => $account['PrincipalID'],
            ]);
            return false;
        }

        // Verify password against OpenSim auth table
        if (!self::verifyAgainstOpenSim($account['PrincipalID'], $password)) {
            Logger::security('Login failed: bad password', [
                'action'    => 'login_failed_password',
                'user_uuid' => $account['PrincipalID'],
            ]);
            return false;
        }

        // Regenerate session to prevent fixation
        Session::regenerate($request);

        // Determine web role
        $webRole = self::loadWebRole($account['PrincipalID']);

        // Store identity in session
        Session::set('user_uuid',  $account['PrincipalID']);
        Session::set('first_name', $account['FirstName']);
        Session::set('last_name',  $account['LastName']);
        Session::set('web_role',   $webRole);
        Session::set('userlevel',  (int) $account['UserLevel']);
        Session::set('logged_in',  true);

        // Ensure ogm_profiles row exists (upsert)
        self::ensureProfileRow($account['PrincipalID']);

        Logger::info('Login successful', [
            'action'    => 'login_success',
            'user_uuid' => $account['PrincipalID'],
        ]);

        return true;
    }

    /**
     * Destroy the current session (logout).
     */
    public static function logout(): void
    {
        $uuid = Session::get('user_uuid') ?? '-';
        Logger::info('Logout', ['action' => 'logout', 'user_uuid' => $uuid]);
        Session::destroy();
    }

    // -------------------------------------------------------------------------
    // OpenSim password verification
    // -------------------------------------------------------------------------

    /**
     * Verify a plaintext password against OpenSim's auth table.
     * Handles both bcrypt ($2y$ prefix) and legacy MD5 hashes.
     */
    public static function verifyAgainstOpenSim(string $uuid, string $password): bool
    {
        try {
            $stmt = DB::getInstance()->opensimRo()->prepare(
                'SELECT passwordHash, passwordSalt FROM auth WHERE UUID = :uuid'
            );
            $stmt->execute([':uuid' => strtolower($uuid)]);
            $row = $stmt->fetch();
        } catch (\Throwable $e) {
            Logger::error('Auth DB query failed: ' . $e->getMessage());
            return false;
        }

        if ($row === false) {
            return false;
        }

        $hash = $row['passwordHash'];
        $salt = $row['passwordSalt'];

        // Bcrypt: hash starts with $2y$ (modern OpenSim)
        if (str_starts_with($hash, '$2y$')) {
            return password_verify($password, $hash);
        }

        // Legacy MD5: hash is 32 hex chars, salt may be present
        if (strlen($hash) === 32 && ctype_xdigit($hash)) {
            $legacyHash = md5($password . ':' . $salt);
            return hash_equals($hash, $legacyHash);
        }

        Logger::error('Unknown password hash format for user: ' . $uuid);
        return false;
    }

    // -------------------------------------------------------------------------
    // Current user helpers
    // -------------------------------------------------------------------------

    /**
     * Return the current user's session data as an array, or null if not logged in.
     *
     * @return array{user_uuid: string, first_name: string, last_name: string,
     *               web_role: string, userlevel: int}|null
     */
    public static function currentUser(): ?array
    {
        if (!Session::get('logged_in')) {
            return null;
        }

        return [
            'user_uuid'  => (string) Session::get('user_uuid'),
            'first_name' => (string) Session::get('first_name'),
            'last_name'  => (string) Session::get('last_name'),
            'web_role'   => (string) Session::get('web_role'),
            'userlevel'  => (int)    Session::get('userlevel'),
        ];
    }

    /**
     * Redirect to /login if the user is not authenticated.
     */
    public static function requireLogin(): void
    {
        if (!Session::get('logged_in')) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Require a minimum web role. Redirects to /login or /403 if insufficient.
     * Role hierarchy: user < moderator < webadmin
     */
    public static function requireWebRole(string $minRole): void
    {
        self::requireLogin();

        $current = (string) Session::get('web_role');
        $minLevel = self::ROLE_LEVELS[$minRole] ?? 0;
        $curLevel = self::ROLE_LEVELS[$current]  ?? 0;

        if ($curLevel < $minLevel) {
            Logger::security('Insufficient web role', [
                'action'   => 'auth_role_denied',
                'required' => $minRole,
                'actual'   => $current,
            ]);
            http_response_code(403);
            echo 'Access denied.';
            exit;
        }
    }

    /**
     * Alias: require admin-level web role.
     */
    public static function requireAdmin(): void
    {
        self::requireWebRole('webadmin');
    }

    /**
     * Check whether the current user has at least the given web role.
     */
    public static function hasWebRole(string $role): bool
    {
        $current  = (string) (Session::get('web_role') ?? 'user');
        $minLevel = self::ROLE_LEVELS[$role]    ?? 0;
        $curLevel = self::ROLE_LEVELS[$current] ?? 0;
        return $curLevel >= $minLevel;
    }

    /**
     * Return the OpenSim UserLevel stored in the session.
     */
    public static function getUserLevel(): int
    {
        return (int) Session::get('userlevel');
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Find a UserAccounts row by email OR by "FirstName LastName".
     * Always includes ScopeID filter for single-grid safety.
     */
    private static function findAccount(string $identifier): ?array
    {
        $pdo = DB::getInstance()->opensimRo();

        // Try email first
        if (str_contains($identifier, '@')) {
            $stmt = $pdo->prepare(
                'SELECT PrincipalID, FirstName, LastName, Email, Active, UserLevel
                 FROM UserAccounts
                 WHERE Email = :email
                   AND ScopeID = \'00000000-0000-0000-0000-000000000000\'
                 LIMIT 1'
            );
            $stmt->execute([':email' => $identifier]);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        }

        // Try "FirstName LastName"
        $parts = explode(' ', $identifier, 2);
        if (count($parts) === 2) {
            $stmt = $pdo->prepare(
                'SELECT PrincipalID, FirstName, LastName, Email, Active, UserLevel
                 FROM UserAccounts
                 WHERE FirstName = :first AND LastName = :last
                   AND ScopeID = \'00000000-0000-0000-0000-000000000000\'
                 LIMIT 1'
            );
            $stmt->execute([':first' => $parts[0], ':last' => $parts[1]]);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        }

        return null;
    }

    /**
     * Load the OGM web role for a user, defaulting to 'user' if no row exists.
     */
    private static function loadWebRole(string $uuid): string
    {
        try {
            $stmt = DB::getInstance()->ogmRw()->prepare(
                'SELECT role FROM ogm_web_roles WHERE user_uuid = :uuid'
            );
            $stmt->execute([':uuid' => $uuid]);
            $row = $stmt->fetch();
            if ($row !== false && Validator::inEnum($row['role'], ['user', 'moderator', 'webadmin'])) {
                return $row['role'];
            }
        } catch (\Throwable $e) {
            Logger::error('loadWebRole failed: ' . $e->getMessage());
        }
        return 'user';
    }

    /**
     * Ensure an ogm_profiles row exists for a user (created at first login).
     */
    private static function ensureProfileRow(string $uuid): void
    {
        try {
            $stmt = DB::getInstance()->ogmRw()->prepare(
                'INSERT IGNORE INTO ogm_profiles (user_uuid) VALUES (:uuid)'
            );
            $stmt->execute([':uuid' => $uuid]);
        } catch (\Throwable $e) {
            Logger::error('ensureProfileRow failed: ' . $e->getMessage());
        }
    }
}
