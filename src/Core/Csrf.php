<?php declare(strict_types=1);

namespace OGM\Core;

/**
 * CSRF protection utility.
 *
 * Tokens are 32-byte random values stored in the DB-backed session.
 * A single per-session token is reused across forms (double-submit cookie
 * pattern not needed — token is in session, not cookie).
 *
 * Usage in templates:
 *   <?= Csrf::field() ?>
 *
 * Usage in POST handlers:
 *   Csrf::verify();  // aborts with 403 on failure
 */
class Csrf
{
    private const SESSION_KEY  = '_csrf_token';
    private const FORM_FIELD   = '_csrf';
    private const TOKEN_BYTES  = 32;

    /**
     * Return the current CSRF token, generating one if it does not yet exist.
     */
    public static function token(): string
    {
        $existing = Session::get(self::SESSION_KEY);
        if ($existing !== null && is_string($existing) && strlen($existing) === self::TOKEN_BYTES * 2) {
            return $existing;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        Session::set(self::SESSION_KEY, $token);
        return $token;
    }

    /**
     * Render a hidden HTML form field containing the CSRF token.
     */
    public static function field(): string
    {
        $token = h(self::token());
        return '<input type="hidden" name="' . self::FORM_FIELD . '" value="' . $token . '">';
    }

    /**
     * Verify the CSRF token submitted in the current POST request.
     * Terminates with a 403 response on failure and logs the event.
     */
    public static function verify(): void
    {
        $submitted = $_POST[self::FORM_FIELD] ?? '';
        $expected  = Session::get(self::SESSION_KEY) ?? '';

        if (!is_string($submitted) || !is_string($expected)) {
            self::fail();
        }

        if (!hash_equals($expected, $submitted)) {
            self::fail();
        }
    }

    // -------------------------------------------------------------------------

    private static function fail(): never
    {
        Logger::security('CSRF token mismatch', ['action' => 'csrf_fail']);
        http_response_code(403);
        echo 'Invalid or missing security token. Please go back and try again.';
        exit;
    }
}
