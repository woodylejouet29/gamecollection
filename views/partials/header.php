<?php
$authUser    = \App\Core\Middleware\AuthMiddleware::user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pendingCount = (int)($_SESSION['pending_count'] ?? 0);

/**
 * Retourne la classe CSS "--active" si le path courant commence par $prefix.
 */
function navActive(string $currentPath, string $prefix): string {
    return ($prefix !== '/' && str_starts_with($currentPath, $prefix)) ||
           ($prefix === '/' && $currentPath === '/')
        ? ' header__nav-link--active' : '';
}

/**
 * Retourne la classe "is-active" pour les liens du drawer / bottom nav.
 */
function navActiveClass(string $currentPath, string $prefix): string {
    return ($prefix !== '/' && str_starts_with($currentPath, $prefix)) ||
           ($prefix === '/' && $currentPath === '/')
        ? ' is-active' : '';
}
?>
<header class="header" id="site-header">
    <div class="header__inner">

        <a href="/" class="header__logo">
            <img
                class="header__logo-img header__logo-img--desktop-dark"
                src="/images/logositeblanc.png"
                alt="PlayShelf"
                width="140"
                height="36"
                loading="eager"
                decoding="async"
            >
            <img
                class="header__logo-img header__logo-img--desktop-light"
                src="/images/logositenoir.png"
                alt="PlayShelf"
                width="140"
                height="36"
                loading="eager"
                decoding="async"
            >
            <img
                class="header__logo-img header__logo-img--mobile-dark"
                src="/images/logomobileblanc.png"
                alt="PlayShelf"
                width="36"
                height="36"
                loading="eager"
                decoding="async"
            >
            <img
                class="header__logo-img header__logo-img--mobile-light"
                src="/images/logomobilenoir.png"
                alt="PlayShelf"
                width="36"
                height="36"
                loading="eager"
                decoding="async"
            >
        </a>

        <?php /* Navigation principale (masquée en mobile) */ ?>
        <nav class="header__nav" aria-label="Navigation principale">
            <a href="/search"     class="header__nav-link<?= navActive($currentPath, '/search') ?>">Rechercher</a>
            <a href="/agenda"     class="header__nav-link<?= navActive($currentPath, '/agenda') ?>">Agenda</a>
            <?php if ($authUser): ?>
            <a href="/collection" class="header__nav-link<?= navActive($currentPath, '/collection') ?>">Ma collection</a>
            <a href="/wishlist"   class="header__nav-link<?= navActive($currentPath, '/wishlist') ?>">Wishlist</a>
            <?php endif; ?>
        </nav>

        <?php /* Actions */ ?>
        <div class="header__actions">
            <?php if ($authUser): ?>
                <?php /* Icône sélection avec badge si items en attente */ ?>
                <a href="/select"
                   class="header__icon-btn icon-badge"
                   title="Ma sélection"
                   aria-label="Ma sélection"
                   id="header-select-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    <?php if ($pendingCount > 0): ?>
                    <span class="badge" id="header-pending-badge"><?= min($pendingCount, 99) ?></span>
                    <?php else: ?>
                    <span class="badge" id="header-pending-badge" style="display:none">0</span>
                    <?php endif; ?>
                </a>

                <?php /* Avatar + pseudo */ ?>
                <a href="/collection" class="header__user" title="Ma collection">
                    <?php if (!empty($authUser['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($authUser['avatar_url']) ?>" alt="" class="header__avatar">
                    <?php else: ?>
                        <span class="header__avatar-placeholder" aria-hidden="true">
                            <?= strtoupper(substr($authUser['username'] ?? 'U', 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                    <span class="header__username"><?= htmlspecialchars($authUser['username'] ?? '') ?></span>
                </a>

                <a href="/logout" class="btn btn--ghost btn--sm">Déconnexion</a>

            <?php else: ?>
                <a href="/login"    class="btn btn--ghost btn--sm">Connexion</a>
                <a href="/register" class="btn btn--primary btn--sm">Inscription</a>
            <?php endif; ?>

            <?php /* Toggle thème */ ?>
            <button class="header__theme-btn" onclick="toggleTheme()" aria-label="Basculer le thème">
                <span class="theme-sun" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <circle cx="12" cy="12" r="4"/>
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
                    </svg>
                </span>
                <span class="theme-moon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </span>
            </button>

            <?php /* Hamburger mobile */ ?>
            <button class="header__burger"
                    id="header-burger"
                    aria-label="Ouvrir le menu"
                    aria-expanded="false"
                    aria-controls="header-drawer">
                <svg id="burger-icon-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>
                <svg id="burger-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="display:none">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>
</header>

<?php /* Backdrop (fond sombre derrière le drawer) */ ?>
<div id="header-backdrop" class="header__backdrop" aria-hidden="true"></div>

<?php /* Tiroir latéral (slide-in depuis la droite) */ ?>
<nav id="header-drawer" class="header__drawer" aria-label="Menu" aria-hidden="true">

    <div class="header__drawer-header">
        <?php if ($authUser): ?>
            <a href="/edit-profile" class="header__drawer-user">
                <?php if (!empty($authUser['avatar_url'])): ?>
                    <img src="<?= htmlspecialchars($authUser['avatar_url']) ?>" alt="" class="header__avatar">
                <?php else: ?>
                    <span class="header__avatar-placeholder" aria-hidden="true">
                        <?= strtoupper(substr($authUser['username'] ?? 'U', 0, 1)) ?>
                    </span>
                <?php endif; ?>
                <div class="header__drawer-userinfo">
                    <span class="header__drawer-username"><?= htmlspecialchars($authUser['username'] ?? '') ?></span>
                    <span class="header__drawer-userlabel">Voir mon profil</span>
                </div>
            </a>
        <?php else: ?>
            <span class="header__drawer-brand">PlayShelf</span>
        <?php endif; ?>
        <button class="header__drawer-close" id="header-drawer-close" aria-label="Fermer le menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="header__drawer-body">
        <div class="header__drawer-section">
            <a href="/search" class="header__drawer-link<?= navActiveClass($currentPath, '/search') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                Rechercher
            </a>
            <a href="/agenda" class="header__drawer-link<?= navActiveClass($currentPath, '/agenda') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                Agenda
            </a>
            <?php if ($authUser): ?>
            <a href="/collection" class="header__drawer-link<?= navActiveClass($currentPath, '/collection') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                Ma collection
            </a>
            <a href="/wishlist" class="header__drawer-link<?= navActiveClass($currentPath, '/wishlist') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                Wishlist
            </a>
            <?php endif; ?>
        </div>

        <?php if ($authUser): ?>
        <div class="header__drawer-sep"></div>
        <div class="header__drawer-section">
            <a href="/select" class="header__drawer-link<?= navActiveClass($currentPath, '/select') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                Ma sélection
                <?php if ($pendingCount > 0): ?>
                <span class="badge"><?= min($pendingCount, 99) ?></span>
                <?php endif; ?>
            </a>
        </div>
        <div class="header__drawer-sep"></div>
        <div class="header__drawer-section">
            <a href="/logout" class="header__drawer-link header__drawer-logout">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Déconnexion
            </a>
        </div>
        <?php else: ?>
        <div class="header__drawer-sep"></div>
        <div class="header__drawer-section">
            <a href="/login" class="header__drawer-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Connexion
            </a>
            <a href="/register" class="header__drawer-link header__drawer-register">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                Inscription
            </a>
        </div>
        <?php endif; ?>
    </div>
</nav>

<?php /* Bottom nav bar (mobile uniquement) */ ?>
<nav class="bottom-nav" aria-label="Navigation mobile">
    <a href="/search" class="bottom-nav__item<?= navActiveClass($currentPath, '/search') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <span>Catalogue</span>
    </a>
    <a href="/agenda" class="bottom-nav__item<?= navActiveClass($currentPath, '/agenda') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <span>Agenda</span>
    </a>
    <?php if ($authUser): ?>
    <a href="/collection" class="bottom-nav__item<?= navActiveClass($currentPath, '/collection') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        <span>Collection</span>
    </a>
    <a href="/wishlist" class="bottom-nav__item<?= navActiveClass($currentPath, '/wishlist') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <span>Wishlist</span>
    </a>
    <a href="/select" class="bottom-nav__item icon-badge<?= navActiveClass($currentPath, '/select') ?>" id="bottom-select-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <?php if ($pendingCount > 0): ?>
        <span class="badge" id="bottom-pending-badge"><?= min($pendingCount, 99) ?></span>
        <?php else: ?>
        <span class="badge" id="bottom-pending-badge" style="display:none">0</span>
        <?php endif; ?>
        <span>Sélection</span>
    </a>
    <?php else: ?>
    <a href="/login" class="bottom-nav__item<?= navActiveClass($currentPath, '/login') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        <span>Connexion</span>
    </a>
    <a href="/register" class="bottom-nav__item bottom-nav__item--accent<?= navActiveClass($currentPath, '/register') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
        <span>Inscription</span>
    </a>
    <?php endif; ?>
</nav>
