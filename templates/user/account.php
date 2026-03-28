<?php declare(strict_types=1);
/**
 * Variables:
 *   $user     array  — Auth::currentUser() result
 *   $full     array|null — UserModel::findByUuid() result
 *   $lastSeen array  — ['last_region_id', 'last_login']
 *   $online   bool
 */

$avatarName    = h($user['first_name']) . ' ' . h($user['last_name']);
$registeredOn  = isset($full['Created']) && $full['Created']
    ? date('Y-m-d', (int) $full['Created'])
    : '—';
$lastLoginText = isset($lastSeen['last_login']) && $lastSeen['last_login']
    ? date('Y-m-d H:i', (int) $lastSeen['last_login']) . ' UTC'
    : 'Never';
$currentEmail  = h($full['Email'] ?? '');
$webRole       = h($user['web_role']);
$userLevel     = (int) $user['userlevel'];
?>

<div class="account-grid">

    <!-- ------------------------------------------------------------------ -->
    <!-- Info panel                                                           -->
    <!-- ------------------------------------------------------------------ -->
    <section class="card" aria-labelledby="info-heading">
        <h2 class="card-title" id="info-heading">Account Information</h2>

        <dl class="info-list">
            <dt>Avatar Name</dt>
            <dd><?= $avatarName ?></dd>

            <dt>UUID</dt>
            <dd class="mono"><?= h($user['user_uuid']) ?></dd>

            <dt>Email</dt>
            <dd><?= $currentEmail ?: '<em class="text-muted">not set</em>' ?></dd>

            <dt>Registered</dt>
            <dd><?= h($registeredOn) ?></dd>

            <dt>Last Login</dt>
            <dd><?= h($lastLoginText) ?></dd>

            <dt>Status</dt>
            <dd>
                <?php if ($online): ?>
                    <span class="badge badge-green">Online</span>
                <?php else: ?>
                    <span class="badge badge-grey">Offline</span>
                <?php endif; ?>
            </dd>

            <dt>Web Role</dt>
            <dd><span class="badge badge-blue"><?= $webRole ?></span></dd>

            <dt>UserLevel</dt>
            <dd><?= h((string) $userLevel) ?></dd>
        </dl>
    </section>

    <div class="account-forms">

        <!-- ---------------------------------------------------------------- -->
        <!-- Change email                                                      -->
        <!-- ---------------------------------------------------------------- -->
        <section class="card" aria-labelledby="email-heading">
            <h2 class="card-title" id="email-heading">Change Email</h2>

            <form method="post" action="/account/update" novalidate>
                <?= \OGM\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="email">

                <div class="form-group">
                    <label for="new_email">New Email Address</label>
                    <input
                        type="email"
                        id="new_email"
                        name="new_email"
                        autocomplete="email"
                        maxlength="255"
                        required
                        value="<?= $currentEmail ?>"
                    >
                </div>

                <button type="submit" class="btn btn-primary">Update Email</button>
            </form>
        </section>

        <!-- ---------------------------------------------------------------- -->
        <!-- Change password                                                   -->
        <!-- ---------------------------------------------------------------- -->
        <section class="card" aria-labelledby="pw-heading">
            <h2 class="card-title" id="pw-heading">Change Password</h2>

            <form method="post" action="/account/update" novalidate data-pw-confirm>
                <?= \OGM\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="password">

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        autocomplete="current-password"
                        maxlength="128"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        autocomplete="new-password"
                        minlength="8"
                        maxlength="128"
                        required
                    >
                    <p class="form-hint">Minimum 8 characters.</p>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        autocomplete="new-password"
                        maxlength="128"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </section>

    </div><!-- /.account-forms -->

</div><!-- /.account-grid -->

<style>
.account-grid  { display: grid; grid-template-columns: 280px 1fr; gap: 1.5rem; align-items: start; }
.account-forms { display: flex; flex-direction: column; gap: 1.5rem; }
.info-list     { display: grid; grid-template-columns: 140px 1fr; gap: .4rem .75rem; font-size: .9rem; }
.info-list dt  { font-weight: 600; color: var(--color-muted); }
.info-list dd  { margin: 0; }
@media (max-width: 700px) {
    .account-grid { grid-template-columns: 1fr; }
}
</style>
