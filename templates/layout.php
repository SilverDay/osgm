<?php declare(strict_types=1);
/**
 * Base layout template.
 *
 * Variables expected from the controller:
 *   $pageTitle  string  — used in <title> tag
 *   $content    string  — pre-rendered inner HTML (set via ob_start/ob_get_clean)
 *                         OR include a sub-template below if using direct require.
 *
 * Usage pattern in controllers:
 *   ob_start();
 *   require __DIR__ . '/../../templates/user/login.php';
 *   $content = ob_get_clean();
 *   require __DIR__ . '/../../templates/layout.php';
 */

// These must always be available; default gracefully if not.
$gridName  = \OGM\Core\Config::get('grid_name', 'OSGridManager');
$pageTitle = isset($pageTitle) ? h($pageTitle) . ' &mdash; ' . h($gridName) : h($gridName);
$user      = \OGM\Core\Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <!-- Inline critical meta for CSP compliance — no external resources -->
</head>
<body>

<header class="site-header">
    <div class="container">
        <a href="/" class="site-logo"><?= h($gridName) ?></a>

        <nav class="site-nav" aria-label="Main navigation">
            <ul>
                <li><a href="/regions">Regions</a></li>
                <li><a href="/search">Search</a></li>
                <?php if ($user !== null): ?>
                    <li><a href="/profile/<?= h($user['user_uuid']) ?>">My Profile</a></li>
                    <li><a href="/economy">Wallet</a></li>
                    <li><a href="/messages">Messages</a></li>
                    <?php if (\OGM\Core\Auth::hasWebRole('webadmin')): ?>
                        <li><a href="/admin">Admin</a></li>
                    <?php endif; ?>
                    <li>
                        <form method="post" action="/auth/logout" class="inline-form">
                            <?= \OGM\Core\Csrf::field() ?>
                            <button type="submit" class="btn-link">
                                Logout (<?= h($user['first_name']) ?>)
                            </button>
                        </form>
                    </li>
                <?php else: ?>
                    <li><a href="/login">Login</a></li>
                    <?php if (\OGM\Core\Config::get('registration_enabled', '0') === '1'): ?>
                        <li><a href="/register">Register</a></li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

<main class="site-main">
    <div class="container">
        <?php
        // Flash messages (set via Session::set('flash', ['type' => '...', 'msg' => '...']))
        $flash = \OGM\Core\Session::get('flash');
        if (is_array($flash) && isset($flash['msg'])):
            $flashType = in_array($flash['type'] ?? '', ['success', 'error', 'warning', 'info'], true)
                ? $flash['type']
                : 'info';
            \OGM\Core\Session::delete('flash');
        ?>
        <div class="alert alert-<?= h($flashType) ?>" role="alert">
            <?= h($flash['msg']) ?>
        </div>
        <?php endif; ?>

        <?= $content ?? '' ?>
    </div>
</main>

<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= date('Y') ?> <?= h($gridName) ?> &mdash; Powered by OSGridManager</p>
    </div>
</footer>

<script src="/assets/js/main.js"></script>
</body>
</html>
