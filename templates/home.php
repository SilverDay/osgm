<?php declare(strict_types=1);
/**
 * Variables:
 *   $gridName      string
 *   $tagline       string
 *   $onlineRegions int
 *   $onlineAvatars int
 *   $user          array|null — Auth::currentUser()
 */
?>

<!-- Hero -->
<section class="hero">
    <h1 class="hero-title"><?= h($gridName) ?></h1>
    <?php if ($tagline !== ''): ?>
        <p class="hero-tagline"><?= h($tagline) ?></p>
    <?php endif; ?>

    <div class="hero-actions">
        <?php if ($user !== null): ?>
            <a href="/account" class="btn btn-primary">My Account</a>
            <a href="/regions" class="btn btn-primary">Explore Regions</a>
        <?php else: ?>
            <a href="/login" class="btn btn-primary">Log In</a>
            <?php if (\OGM\Core\Config::get('registration_enabled', '0') === '1'): ?>
                <a href="/register" class="btn btn-primary">Register</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Stats bar -->
<section class="stats-bar" aria-label="Grid status">
    <div class="stat-item">
        <span class="stat-value"><?= h((string) $onlineRegions) ?></span>
        <span class="stat-label">Region<?= $onlineRegions !== 1 ? 's' : '' ?> Online</span>
    </div>
    <div class="stat-divider" aria-hidden="true"></div>
    <div class="stat-item">
        <span class="stat-value"><?= h((string) $onlineAvatars) ?></span>
        <span class="stat-label">Avatar<?= $onlineAvatars !== 1 ? 's' : '' ?> Inworld</span>
    </div>
</section>

<!-- Quick links for logged-in users -->
<?php if ($user !== null): ?>
<section class="quick-links" aria-labelledby="quick-heading">
    <h2 id="quick-heading" class="section-title">Quick Access</h2>
    <div class="quick-grid">
        <a href="/profile/<?= h($user['user_uuid']) ?>" class="quick-card">
            <span class="quick-icon" aria-hidden="true">👤</span>
            <span>My Profile</span>
        </a>
        <a href="/economy" class="quick-card">
            <span class="quick-icon" aria-hidden="true">💰</span>
            <span>Wallet</span>
        </a>
        <a href="/messages" class="quick-card">
            <span class="quick-icon" aria-hidden="true">✉️</span>
            <span>Messages</span>
        </a>
        <a href="/regions" class="quick-card">
            <span class="quick-icon" aria-hidden="true">🌐</span>
            <span>Regions</span>
        </a>
        <a href="/search" class="quick-card">
            <span class="quick-icon" aria-hidden="true">🔍</span>
            <span>Search</span>
        </a>
        <a href="/account" class="quick-card">
            <span class="quick-icon" aria-hidden="true">⚙️</span>
            <span>Settings</span>
        </a>
    </div>
</section>
<?php endif; ?>

<style>
/* Hero */
.hero          { text-align: center; padding: 3rem 1rem 2rem; }
.hero-title    { font-size: clamp(1.75rem, 5vw, 3rem); font-weight: 800; color: var(--color-primary); }
.hero-tagline  { font-size: 1.15rem; color: var(--color-muted); margin: .5rem 0 1.5rem; }
.hero-actions  { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }

/* Stats bar */
.stats-bar     { display: flex; align-items: center; justify-content: center; gap: 2rem;
                 background: var(--color-surface); border: 1px solid var(--color-border);
                 border-radius: var(--radius); padding: 1.25rem 2rem; margin: 1.5rem 0; }
.stat-item     { text-align: center; }
.stat-value    { display: block; font-size: 2rem; font-weight: 700; color: var(--color-primary); line-height: 1.1; }
.stat-label    { font-size: .8rem; color: var(--color-muted); text-transform: uppercase; letter-spacing: .05em; }
.stat-divider  { width: 1px; height: 2.5rem; background: var(--color-border); }

/* Quick links */
.section-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem; color: var(--color-muted); }
.quick-grid    { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 1rem; }
.quick-card    { display: flex; flex-direction: column; align-items: center; gap: .4rem;
                 background: var(--color-surface); border: 1px solid var(--color-border);
                 border-radius: var(--radius); padding: 1.25rem .75rem; text-align: center;
                 text-decoration: none; color: var(--color-text); font-size: .85rem; font-weight: 600;
                 transition: border-color .15s, box-shadow .15s; }
.quick-card:hover { border-color: var(--color-primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1);
                    text-decoration: none; color: var(--color-primary); }
.quick-icon    { font-size: 1.5rem; }
</style>
