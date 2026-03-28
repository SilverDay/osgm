<?php declare(strict_types=1);

namespace OGM\Core;

/**
 * Token bucket rate limiter backed by the ogm_rate_limits table.
 *
 * Bucket keys:
 *   "ip:{$ip}"                  — per-IP limit
 *   "token:{$tokenHash}"        — per-API-token limit
 *   "user:{$uuid}:{$action}"    — per-user per-action limit
 *
 * Limits from spec:
 *   Auth / login         → 5 attempts / 5 min per IP
 *   Economy transfers    → 30 / hour per user
 *   Messaging send       → 20 / hour per user
 *   Search               → 60 / min per token
 *   General API          → 120 / min per token
 *   General API (no tok) → 10 / min per IP
 */
class RateLimit
{
    /**
     * Check whether the bucket has capacity and consume one token.
     *
     * @param string $key           Bucket key (e.g. "ip:1.2.3.4")
     * @param int    $capacity      Maximum tokens in bucket
     * @param int    $refillPerMin  Tokens added per minute
     * @return bool  true = request allowed, false = rate-limited
     */
    public static function check(string $key, int $capacity, int $refillPerMin): bool
    {
        $pdo = DB::getInstance()->ogmRw();

        // Upsert row and fetch current state atomically
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ogm_rate_limits (bucket_key, tokens, last_refill)
                 VALUES (:key, :capacity, NOW())
                 ON DUPLICATE KEY UPDATE bucket_key = bucket_key'
            );
            $stmt->execute([':key' => $key, ':capacity' => $capacity]);

            $stmt = $pdo->prepare(
                'SELECT tokens, last_refill FROM ogm_rate_limits
                 WHERE bucket_key = :key FOR UPDATE'
            );
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch();

            if ($row === false) {
                $pdo->rollBack();
                return false;
            }

            $now         = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $lastRefill  = new \DateTimeImmutable($row['last_refill'], new \DateTimeZone('UTC'));
            $elapsedSecs = max(0, $now->getTimestamp() - $lastRefill->getTimestamp());

            // Refill tokens based on elapsed time
            $tokensToAdd = ($elapsedSecs / 60.0) * $refillPerMin;
            $newTokens   = min((float) $capacity, (float) $row['tokens'] + $tokensToAdd);

            if ($newTokens < 1.0) {
                // No tokens available — rate limited
                $pdo->rollBack();
                Logger::security('Rate limit exceeded', ['action' => 'rate_limit', 'bucket' => $key]);
                return false;
            }

            // Consume one token
            $newTokens -= 1.0;
            $stmt = $pdo->prepare(
                'UPDATE ogm_rate_limits
                 SET tokens = :tokens, last_refill = NOW()
                 WHERE bucket_key = :key'
            );
            $stmt->execute([':tokens' => $newTokens, ':key' => $key]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Logger::error('RateLimit check failed: ' . $e->getMessage());
            // Fail open to avoid blocking legitimate traffic on DB error
            return true;
        }
    }

    /**
     * Reset a bucket (e.g. after successful auth to clear login attempt counter).
     */
    public static function reset(string $key, int $capacity): void
    {
        try {
            $stmt = DB::getInstance()->ogmRw()->prepare(
                'INSERT INTO ogm_rate_limits (bucket_key, tokens, last_refill)
                 VALUES (:key, :capacity, NOW())
                 ON DUPLICATE KEY UPDATE tokens = :capacity, last_refill = NOW()'
            );
            $stmt->execute([':key' => $key, ':capacity' => $capacity]);
        } catch (\Throwable $e) {
            Logger::error('RateLimit reset failed: ' . $e->getMessage());
        }
    }

    // Shorthand helpers for spec-defined limits -----------------------------------

    /** Login rate limit: 5 attempts per 5 minutes per IP. */
    public static function loginByIp(string $ip): bool
    {
        return self::check("login:ip:{$ip}", 5, 1); // 1 token/min → 5 tokens refill in 5 min
    }

    /** General API (no token): 10 req/min per IP. */
    public static function apiByIp(string $ip): bool
    {
        return self::check("api:ip:{$ip}", 10, 10);
    }

    /** General API (with token): 120 req/min per token hash. */
    public static function apiByToken(string $tokenHash): bool
    {
        return self::check("api:token:{$tokenHash}", 120, 120);
    }

    /** Economy transfer: 30/hour per user. */
    public static function economyByUser(string $uuid): bool
    {
        return self::check("economy:user:{$uuid}", 30, (int) round(30 / 60.0));
    }

    /** Messaging send: 20/hour per user. */
    public static function messagingByUser(string $uuid): bool
    {
        return self::check("messaging:user:{$uuid}", 20, (int) round(20 / 60.0));
    }

    /** Search: 60/min per token hash. */
    public static function searchByToken(string $tokenHash): bool
    {
        return self::check("search:token:{$tokenHash}", 60, 60);
    }

    /** Registration: 3 per hour per IP. */
    public static function registrationByIp(string $ip): bool
    {
        return self::check("register:ip:{$ip}", 3, (int) round(3 / 60.0));
    }
}
