<?php declare(strict_types=1);

namespace OGM\Modules\User;

use OGM\Core\DB;
use OGM\Core\Logger;
use OGM\Core\Validator;

/**
 * Data-access layer for OpenSim user accounts and OGM profile data.
 *
 * All reads against OpenSim tables use the opensimRo() connection.
 * All writes use opensimLimited() (column-level grants enforced at DB level).
 * OGM profile data uses ogmRo() for reads and ogmRw() for writes.
 *
 * Two-query pattern for findByUuid: separate queries to opensimRo and ogmRo,
 * merged in PHP — avoids cross-database JOIN privilege dependency.
 *
 * All UserAccounts queries include ScopeID = '00000000-0000-0000-0000-000000000000'.
 */
class UserModel
{
    private const SCOPE_ID = '00000000-0000-0000-0000-000000000000';

    // -------------------------------------------------------------------------
    // Lookups
    // -------------------------------------------------------------------------

    /**
     * Find a user by their UUID. Returns merged OpenSim + OGM profile data,
     * or null if not found.
     *
     * @return array{
     *   PrincipalID: string, FirstName: string, LastName: string,
     *   Email: string, Active: int, UserLevel: int, Created: int,
     *   display_name: string|null, bio: string|null, website: string|null,
     *   avatar_pic_url: string|null, show_online: int, show_in_search: int,
     *   language: string, timezone: string
     * }|null
     */
    public static function findByUuid(string $uuid): ?array
    {
        if (!Validator::uuid($uuid)) {
            return null;
        }

        try {
            $stmt = DB::getInstance()->opensimRo()->prepare(
                'SELECT PrincipalID, FirstName, LastName, Email, Active, UserLevel, Created
                 FROM UserAccounts
                 WHERE PrincipalID = :uuid
                   AND ScopeID = :scope
                 LIMIT 1'
            );
            $stmt->execute([':uuid' => strtolower($uuid), ':scope' => self::SCOPE_ID]);
            $account = $stmt->fetch();
        } catch (\Throwable $e) {
            Logger::error('UserModel::findByUuid opensim query failed: ' . $e->getMessage());
            return null;
        }

        if ($account === false) {
            return null;
        }

        // Fetch OGM profile data separately
        try {
            $stmt = DB::getInstance()->ogmRo()->prepare(
                'SELECT display_name, bio, website, avatar_pic_url,
                        show_online, show_in_search, language, timezone
                 FROM ogm_profiles
                 WHERE user_uuid = :uuid'
            );
            $stmt->execute([':uuid' => strtolower($uuid)]);
            $profile = $stmt->fetch();
        } catch (\Throwable $e) {
            Logger::error('UserModel::findByUuid ogm_profiles query failed: ' . $e->getMessage());
            $profile = false;
        }

        // Merge; use sensible defaults if no OGM profile row yet
        $defaults = [
            'display_name'   => null,
            'bio'            => null,
            'website'        => null,
            'avatar_pic_url' => null,
            'show_online'    => 1,
            'show_in_search' => 1,
            'language'       => 'en',
            'timezone'       => 'UTC',
        ];

        return array_merge($account, $profile !== false ? $profile : $defaults);
    }

    /**
     * Find a user by email address. Returns core account data only (no profile).
     *
     * @return array{PrincipalID: string, FirstName: string, LastName: string,
     *               Email: string, Active: int, UserLevel: int, Created: int}|null
     */
    public static function findByEmail(string $email): ?array
    {
        if (Validator::email($email) === null) {
            return null;
        }

        try {
            $stmt = DB::getInstance()->opensimRo()->prepare(
                'SELECT PrincipalID, FirstName, LastName, Email, Active, UserLevel, Created
                 FROM UserAccounts
                 WHERE Email = :email
                   AND ScopeID = :scope
                 LIMIT 1'
            );
            $stmt->execute([':email' => $email, ':scope' => self::SCOPE_ID]);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        } catch (\Throwable $e) {
            Logger::error('UserModel::findByEmail failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a user by avatar name (FirstName + LastName).
     *
     * @return array{PrincipalID: string, FirstName: string, LastName: string,
     *               Email: string, Active: int, UserLevel: int, Created: int}|null
     */
    public static function findByName(string $first, string $last): ?array
    {
        try {
            $stmt = DB::getInstance()->opensimRo()->prepare(
                'SELECT PrincipalID, FirstName, LastName, Email, Active, UserLevel, Created
                 FROM UserAccounts
                 WHERE FirstName = :first
                   AND LastName  = :last
                   AND ScopeID   = :scope
                 LIMIT 1'
            );
            $stmt->execute([':first' => $first, ':last' => $last, ':scope' => self::SCOPE_ID]);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        } catch (\Throwable $e) {
            Logger::error('UserModel::findByName failed: ' . $e->getMessage());
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Presence / online status
    // -------------------------------------------------------------------------

    /**
     * Return last-seen data from OpenSim's GridUser table.
     *
     * @return array{last_region_id: string|null, last_login: int|null}
     */
    public static function getLastSeen(string $uuid): array
    {
        if (!Validator::uuid($uuid)) {
            return ['last_region_id' => null, 'last_login' => null];
        }

        try {
            $stmt = DB::getInstance()->opensimRo()->prepare(
                'SELECT LastRegionID, LastLogin
                 FROM GridUser
                 WHERE UserID = :uuid
                 LIMIT 1'
            );
            $stmt->execute([':uuid' => strtolower($uuid)]);
            $row = $stmt->fetch();
        } catch (\Throwable $e) {
            Logger::error('UserModel::getLastSeen failed: ' . $e->getMessage());
            return ['last_region_id' => null, 'last_login' => null];
        }

        if ($row === false) {
            return ['last_region_id' => null, 'last_login' => null];
        }

        return [
            'last_region_id' => $row['LastRegionID'] ?? null,
            'last_login'     => isset($row['LastLogin']) ? (int) $row['LastLogin'] : null,
        ];
    }

    /**
     * Check whether a user is currently online (row exists in Presence table).
     */
    public static function isOnline(string $uuid): bool
    {
        if (!Validator::uuid($uuid)) {
            return false;
        }

        try {
            $stmt = DB::getInstance()->opensimRo()->prepare(
                'SELECT 1 FROM Presence WHERE UserID = :uuid LIMIT 1'
            );
            $stmt->execute([':uuid' => strtolower($uuid)]);
            return $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            Logger::error('UserModel::isOnline failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Count distinct regions with at least one avatar currently online.
     */
    public static function countOnlineRegions(): int
    {
        try {
            $stmt = DB::getInstance()->opensimRo()->query(
                'SELECT COUNT(DISTINCT RegionID) AS cnt FROM Presence'
            );
            $row = $stmt->fetch();
            return $row !== false ? (int) $row['cnt'] : 0;
        } catch (\Throwable $e) {
            Logger::error('UserModel::countOnlineRegions failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count all avatars currently online.
     */
    public static function countOnlineAvatars(): int
    {
        try {
            $stmt = DB::getInstance()->opensimRo()->query(
                'SELECT COUNT(*) AS cnt FROM Presence'
            );
            $row = $stmt->fetch();
            return $row !== false ? (int) $row['cnt'] : 0;
        } catch (\Throwable $e) {
            Logger::error('UserModel::countOnlineAvatars failed: ' . $e->getMessage());
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Writes (via opensimLimited — column-level grants enforced at DB)
    // -------------------------------------------------------------------------

    /**
     * Update the email address for an OpenSim account.
     *
     * @throws \RuntimeException on database failure
     */
    public static function updateEmail(string $uuid, string $email): void
    {
        $stmt = DB::getInstance()->opensimLimited()->prepare(
            'UPDATE UserAccounts
             SET Email = :email
             WHERE PrincipalID = :uuid
               AND ScopeID = :scope'
        );
        $stmt->execute([':email' => $email, ':uuid' => strtolower($uuid), ':scope' => self::SCOPE_ID]);
    }

    /**
     * Update the password hash in OpenSim's auth table.
     * Expects a bcrypt hash (password_hash output starting with $2y$).
     * passwordSalt is set to '' — OpenSim detects bcrypt by the $2y$ prefix.
     *
     * @throws \RuntimeException on database failure
     */
    public static function updatePassword(string $uuid, string $bcryptHash): void
    {
        if (!Validator::bcryptHash($bcryptHash)) {
            throw new \InvalidArgumentException('updatePassword requires a bcrypt hash.');
        }

        $stmt = DB::getInstance()->opensimLimited()->prepare(
            'UPDATE auth
             SET passwordHash = :hash, passwordSalt = \'\'
             WHERE UUID = :uuid'
        );
        $stmt->execute([':hash' => $bcryptHash, ':uuid' => strtolower($uuid)]);
    }

    // -------------------------------------------------------------------------
    // Audit log helper (internal to this module)
    // -------------------------------------------------------------------------

    /**
     * Write an entry to ogm_audit_log.
     * Kept package-private (called from UserController only).
     */
    public static function writeAuditLog(
        string $actorUuid,
        string $action,
        ?string $targetUuid,
        ?string $targetType,
        string $ip,
        array $detail = []
    ): void {
        try {
            $stmt = DB::getInstance()->ogmRw()->prepare(
                'INSERT INTO ogm_audit_log
                    (actor_uuid, action, target_uuid, target_type, ip_address, detail, created_at)
                 VALUES
                    (:actor, :action, :target, :target_type, :ip, :detail, NOW())'
            );
            $stmt->execute([
                ':actor'       => $actorUuid,
                ':action'      => $action,
                ':target'      => $targetUuid,
                ':target_type' => $targetType,
                ':ip'          => $ip,
                ':detail'      => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            // Audit log failure must never crash the application
            Logger::error('Audit log write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }
}
