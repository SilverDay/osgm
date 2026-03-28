<?php declare(strict_types=1);
/** Variables available: (none beyond those set by layout bootstrap) */
?>
<div class="auth-wrap">
    <div class="card auth-card">
        <h1 class="card-title">Log In</h1>

        <form method="post" action="/auth/login" autocomplete="on" novalidate>
            <?= \OGM\Core\Csrf::field() ?>

            <div class="form-group">
                <label for="identifier">Avatar Name or Email</label>
                <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    autocomplete="username"
                    required
                    maxlength="255"
                    placeholder="Firstname Lastname or email@example.com"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    maxlength="128"
                >
            </div>

            <div class="form-group mt-2">
                <button type="submit" class="btn btn-primary" style="width:100%">Log In</button>
            </div>
        </form>

        <?php if (\OGM\Core\Config::get('registration_enabled', '0') === '1'): ?>
        <p class="text-center text-sm mt-2">
            No account yet? <a href="/register">Register</a>
        </p>
        <?php endif; ?>
    </div>
</div>

<style>
.auth-wrap  { display: flex; justify-content: center; padding: 2rem 1rem; }
.auth-card  { width: 100%; max-width: 400px; }
</style>
