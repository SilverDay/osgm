<?php declare(strict_types=1);

namespace OGM\Modules\User;

use OGM\Core\Auth;
use OGM\Core\Config;
use OGM\Core\Csrf;
use OGM\Core\Logger;
use OGM\Core\RateLimit;
use OGM\Core\Request;
use OGM\Core\Response;
use OGM\Core\Session;
use OGM\Core\Validator;

class UserController
{
    private const TEMPLATES = __DIR__ . '/../../../templates';

    // -------------------------------------------------------------------------
    // Home
    // -------------------------------------------------------------------------

    public function home(Request $request, Response $response): void
    {
        $gridName      = Config::get('grid_name', 'OSGridManager');
        $tagline       = Config::get('grid_tagline', '');
        $onlineRegions = UserModel::countOnlineRegions();
        $onlineAvatars = UserModel::countOnlineAvatars();
        $user          = Auth::currentUser();

        ob_start();
        require self::TEMPLATES . '/home.php';
        $content = (string) ob_get_clean();

        $pageTitle = 'Welcome';
        require self::TEMPLATES . '/layout.php';
    }

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------

    public function showLogin(Request $request, Response $response): void
    {
        // Already logged in → go to account
        if (Auth::currentUser() !== null) {
            header('Location: /account');
            exit;
        }

        $pageTitle = 'Login';
        ob_start();
        require self::TEMPLATES . '/user/login.php';
        $content = (string) ob_get_clean();

        require self::TEMPLATES . '/layout.php';
    }

    public function processLogin(Request $request, Response $response): void
    {
        // 1. CSRF check — hard fail with 403 if invalid
        Csrf::verify();

        $ip = $request->getIp();

        // 2. Rate limit — checked before any DB work
        if (!RateLimit::loginByIp($ip)) {
            http_response_code(429);
            Session::set('flash', [
                'type' => 'error',
                'msg'  => 'Too many login attempts. Please wait a few minutes before trying again.',
            ]);
            Logger::security('Login rate limit hit', ['action' => 'login_rate_limited']);
            header('Location: /login');
            exit;
        }

        // 3. Read and do basic validation (blank checks do not consume rate limit tokens above
        //    because we return before this point only on rate-limit; blank input is caught here
        //    after a token has already been consumed — acceptable, prevents trivial bypasses)
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $password   = (string) ($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            Session::set('flash', ['type' => 'error', 'msg' => 'Please enter your avatar name or email and password.']);
            header('Location: /login');
            exit;
        }

        // Reject oversized inputs before touching the DB
        if (mb_strlen($identifier) > 255 || mb_strlen($password) > 128) {
            Session::set('flash', ['type' => 'error', 'msg' => 'Invalid credentials.']);
            header('Location: /login');
            exit;
        }

        // 4. Attempt login
        $success = Auth::loginWeb($identifier, $password, $request);

        if ($success) {
            // Reset the rate limit bucket on successful login
            RateLimit::reset("login:ip:{$ip}", 5);

            $user = Auth::currentUser();
            UserModel::writeAuditLog(
                actorUuid:  $user['user_uuid'],
                action:     'login_success',
                targetUuid: $user['user_uuid'],
                targetType: 'user',
                ip:         $ip,
            );

            header('Location: /account');
            exit;
        }

        // 5. Login failed
        UserModel::writeAuditLog(
            actorUuid:  '00000000-0000-0000-0000-000000000000',
            action:     'login_failed',
            targetUuid: null,
            targetType: 'user',
            ip:         $ip,
            detail:     ['identifier' => substr($identifier, 0, 64)],
        );

        Session::set('flash', ['type' => 'error', 'msg' => 'Invalid credentials. Please try again.']);
        header('Location: /login');
        exit;
    }

    // -------------------------------------------------------------------------
    // Logout
    // -------------------------------------------------------------------------

    public function logout(Request $request, Response $response): void
    {
        // Only accept POST — GET logout is a CSRF vulnerability
        if (!$request->isPost()) {
            http_response_code(405);
            header('Allow: POST');
            exit;
        }

        Csrf::verify();

        $user = Auth::currentUser();
        $uuid = $user['user_uuid'] ?? '00000000-0000-0000-0000-000000000000';

        UserModel::writeAuditLog(
            actorUuid:  $uuid,
            action:     'logout',
            targetUuid: $uuid,
            targetType: 'user',
            ip:         $request->getIp(),
        );

        Auth::logout();

        header('Location: /');
        exit;
    }

    // -------------------------------------------------------------------------
    // Account
    // -------------------------------------------------------------------------

    public function showAccount(Request $request, Response $response): void
    {
        Auth::requireLogin();

        $user     = Auth::currentUser();
        $full     = UserModel::findByUuid($user['user_uuid']);
        $lastSeen = UserModel::getLastSeen($user['user_uuid']);
        $online   = UserModel::isOnline($user['user_uuid']);

        $pageTitle = 'Account Settings';
        ob_start();
        require self::TEMPLATES . '/user/account.php';
        $content = (string) ob_get_clean();

        require self::TEMPLATES . '/layout.php';
    }

    public function updateAccount(Request $request, Response $response): void
    {
        Auth::requireLogin();
        Csrf::verify();

        $user   = Auth::currentUser();
        $uuid   = $user['user_uuid'];
        $ip     = $request->getIp();
        $action = trim((string) ($_POST['action'] ?? ''));

        if (!Validator::inEnum($action, ['email', 'password'])) {
            http_response_code(400);
            Session::set('flash', ['type' => 'error', 'msg' => 'Invalid request.']);
            header('Location: /account');
            exit;
        }

        match ($action) {
            'email'    => $this->handleEmailChange($uuid, $ip),
            'password' => $this->handlePasswordChange($uuid, $ip),
        };
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function handleEmailChange(string $uuid, string $ip): never
    {
        $newEmail = Validator::email(trim((string) ($_POST['new_email'] ?? '')));

        if ($newEmail === null) {
            Session::set('flash', ['type' => 'error', 'msg' => 'Please enter a valid email address.']);
            header('Location: /account');
            exit;
        }

        // Uniqueness check — allow same user to keep their existing address
        $existing = UserModel::findByEmail($newEmail);
        if ($existing !== null && strtolower($existing['PrincipalID']) !== strtolower($uuid)) {
            Session::set('flash', ['type' => 'error', 'msg' => 'That email address is already in use.']);
            header('Location: /account');
            exit;
        }

        try {
            UserModel::updateEmail($uuid, $newEmail);
        } catch (\Throwable $e) {
            Logger::error('Email update failed: ' . $e->getMessage(), ['user_uuid' => $uuid]);
            Session::set('flash', ['type' => 'error', 'msg' => 'Could not update email. Please try again.']);
            header('Location: /account');
            exit;
        }

        UserModel::writeAuditLog(
            actorUuid:  $uuid,
            action:     'email_changed',
            targetUuid: $uuid,
            targetType: 'user',
            ip:         $ip,
            detail:     ['new_email' => $newEmail],   // old email deliberately omitted — minimise PII in logs
        );

        Session::set('flash', ['type' => 'success', 'msg' => 'Email address updated successfully.']);
        header('Location: /account');
        exit;
    }

    private function handlePasswordChange(string $uuid, string $ip): never
    {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password']     ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        // Cheap checks first — before any DB call
        if ($currentPassword === '') {
            Session::set('flash', ['type' => 'error', 'msg' => 'Please enter your current password.']);
            header('Location: /account');
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            Session::set('flash', ['type' => 'error', 'msg' => 'New passwords do not match.']);
            header('Location: /account');
            exit;
        }

        if (Validator::password($newPassword) === null) {
            Session::set('flash', ['type' => 'error', 'msg' => 'New password must be between 8 and 128 characters.']);
            header('Location: /account');
            exit;
        }

        // Verify current password against OpenSim
        if (!Auth::verifyAgainstOpenSim($uuid, $currentPassword)) {
            UserModel::writeAuditLog(
                actorUuid:  $uuid,
                action:     'password_change_failed',
                targetUuid: $uuid,
                targetType: 'user',
                ip:         $ip,
                detail:     ['reason' => 'wrong_current_password'],
            );
            Session::set('flash', ['type' => 'error', 'msg' => 'Current password is incorrect.']);
            header('Location: /account');
            exit;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            UserModel::updatePassword($uuid, $hash);
        } catch (\Throwable $e) {
            Logger::error('Password update failed: ' . $e->getMessage(), ['user_uuid' => $uuid]);
            Session::set('flash', ['type' => 'error', 'msg' => 'Could not update password. Please try again.']);
            header('Location: /account');
            exit;
        }

        UserModel::writeAuditLog(
            actorUuid:  $uuid,
            action:     'password_changed',
            targetUuid: $uuid,
            targetType: 'user',
            ip:         $ip,
            // No hash or old password in the audit log — ever
        );

        Session::set('flash', ['type' => 'success', 'msg' => 'Password changed successfully.']);
        header('Location: /account');
        exit;
    }
}
