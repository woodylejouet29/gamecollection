<?php
use App\Core\Middleware\AuthMiddleware;

$authUser         = AuthMiddleware::user();
$topRatedGames    = $topRatedGames    ?? [];
$todayGames       = $todayGames       ?? [];
$latestReviews    = $latestReviews    ?? [];
$genreHighlights  = $genreHighlights  ?? [];
$topPlatforms     = $topPlatforms     ?? [];
$stats            = $stats            ?? ['members' => 0, 'games' => 0, 'entries' => 0, 'reviews' => 0];

/**
 * Retourne le src d'un cover : gère les paths locaux et les URLs distantes.
 */
function coverSrc(?string $url): string {
    if (!$url) return '';
    if (str_starts_with($url, 'http')) return $url;
    return '/' . ltrim($url, '/');
}

/**
 * Affiche les étoiles d'une note sur 10.
 */
function ratingStars(int $rating): string {
    $stars = (int) round($rating / 2);
    $html  = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<span class="review-card__star' . ($i > $stars ? ' review-card__star--empty' : '') . '">★</span>';
    }
    return $html;
}

/**
 * Formate une date SQL (YYYY-MM-DD) en "janv. 2025".
 */
function fmtDate(?string $date): string {
    if (!$date) return '';
    $ts = strtotime($date);
    if (!$ts) return $date;
    static $months = [
        1=>'janv.', 2=>'févr.', 3=>'mars',  4=>'avr.',
        5=>'mai',   6=>'juin',  7=>'juil.', 8=>'août',
        9=>'sept.', 10=>'oct.', 11=>'nov.', 12=>'déc.',
    ];
    return ($months[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
}
?>

<?php if ($authUser): ?>
<!-- ═══════════════════════════════════════════════════════
     HÉRO — CONNECTÉ
═══════════════════════════════════════════════════════ -->
<section class="hero-user">
    <div class="container hero-user__inner">
        <div class="hero-user__greeting">
            <?php if (!empty($authUser['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($authUser['avatar_url']) ?>"
                     alt=""
                     class="hero-user__avatar"
                     loading="lazy">
            <?php else: ?>
                <span class="hero-user__avatar-placeholder" aria-hidden="true">
                    <?= strtoupper(substr($authUser['username'] ?? 'U', 0, 1)) ?>
                </span>
            <?php endif; ?>
            <div>
                <h1 class="hero-user__title">
                    Bonjour, <span class="gradient-text"><?= htmlspecialchars($authUser['username'] ?? '') ?></span>&nbsp;👋
                </h1>
                <p class="hero-user__sub">Continuez à enrichir votre collection.</p>
            </div>
        </div>
        <div class="hero-user__actions">
            <a href="/search"     class="btn btn--primary">Rechercher un jeu</a>
            <a href="/collection" class="btn btn--ghost">Ma collection</a>
        </div>
    </div>
</section>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════
     HÉRO — INVITÉ
═══════════════════════════════════════════════════════ -->
<section class="hero-guest">
    <div class="hero-guest__inner">

        <div class="hero-guest__text">
            <p class="hero-guest__eyebrow">La plateforme des collectionneurs</p>
            <h1 class="hero-guest__title">
                Cataloguez votre passion<br>
                <span class="gradient-text">jeu vidéo</span>
            </h1>
            <p class="hero-guest__desc">
                Organisez votre collection physique et digitale, notez vos expériences,
                découvrez les perles rares. Rejoignez <?= number_format($stats['members']) ?> collectionneurs.
            </p>
            <div class="hero-guest__actions">
                <a href="/register" class="btn btn--primary btn--lg-inline">Créer un compte gratuit</a>
                <a href="/search"   class="btn btn--ghost btn--lg-inline">Explorer le catalogue</a>
            </div>
        </div>

        <?php if (!empty($recentGames)): ?>
        <div class="hero-guest__mosaic" aria-hidden="true">
            <?php foreach (array_slice($recentGames, 0, 12) as $g):
                $src = coverSrc($g['cover_url'] ?? null);
                if (!$src) continue;
            ?>
                <div class="hero-mosaic-item">
                    <img src="<?= htmlspecialchars($src) ?>"
                         alt=""
                         loading="lazy"
                         width="130" height="170">
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<!-- Barre de stats globales -->
<div class="hero-stats">
    <div class="container hero-stats__inner">
        <div class="hero-stat">
            <span class="hero-stat__num stat-num" data-count="<?= $stats['members'] ?>">
                <?= number_format($stats['members']) ?>
            </span>
            <span class="hero-stat__label">membres</span>
        </div>
        <div class="hero-stat">
            <span class="hero-stat__num stat-num" data-count="<?= $stats['games'] ?>">
                <?= number_format($stats['games']) ?>
            </span>
            <span class="hero-stat__label">jeux au catalogue</span>
        </div>
        <div class="hero-stat">
            <span class="hero-stat__num stat-num" data-count="<?= $stats['entries'] ?>">
                <?= number_format($stats['entries']) ?>
            </span>
            <span class="hero-stat__label">jeux collectionnés</span>
        </div>
        <div class="hero-stat">
            <span class="hero-stat__num stat-num" data-count="<?= $stats['reviews'] ?>">
                <?= number_format($stats['reviews']) ?>
            </span>
            <span class="hero-stat__label">avis publiés</span>
        </div>
    </div>
</div>

<!-- Features -->
<section class="home-features">
    <div class="container">
        <div class="home-features__grid">

            <div class="home-feature">
                <div class="home-feature__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>
                    </svg>
                </div>
                <h3>Catalogue IGDB complet</h3>
                <p>
                    Accédez à <?= number_format($stats['games']) ?> jeux issus de la base IGDB —
                    PC, consoles, portables, toutes générations confondues.
                </p>
            </div>

            <div class="home-feature">
                <div class="home-feature__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                    </svg>
                </div>
                <h3>Collection physique &amp; digitale</h3>
                <p>
                    Renseignez l'état, la région, la version de chaque exemplaire.
                    Suivez vos prix d'achat, temps de jeu et statuts.
                </p>
            </div>

            <div class="home-feature">
                <div class="home-feature__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <h3>Communauté &amp; avis</h3>
                <p>
                    Notez vos jeux, rédigez vos avis, consultez les retours
                    de la communauté. Partagez votre collection.
                </p>
            </div>

        </div>
    </div>
</section>

<?php endif; // end invité ?>

<div class="home-columns">
    <!-- ═══════════════════════════════════════════════════════
         MIEUX NOTÉS (ANNÉE EN COURS)
    ═══════════════════════════════════════════════════════ -->
    <section class="home-section home-section--col">
        <div class="container">
            <div class="home-section__header">
                <h2 class="home-section__title">Mieux notés</h2>
                <a href="/search?sort=rating" class="home-section__link">Voir tout →</a>
            </div>

            <?php if (!empty($topRatedGames)): ?>
            <div class="games-scroll-wrapper">
                <button class="games-scroll-nav games-scroll-nav--prev is-hidden" aria-label="Précédent">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <div class="games-scroll">
                    <div class="games-scroll__track">
                        <?php foreach ($topRatedGames as $g):
                            $src = coverSrc($g['cover_url'] ?? null);
                        ?>
                        <a href="/game/<?= htmlspecialchars($g['slug'] ?? '') ?>"
                           class="game-card-mini"
                           title="<?= htmlspecialchars($g['title'] ?? '') ?>">
                            <div class="game-card-mini__cover">
                                <div class="game-card-mini__cover-placeholder" aria-hidden="true">
                                    <?= htmlspecialchars(mb_substr($g['title'] ?? '?', 0, 30)) ?>
                                </div>
                                <?php if ($src): ?>
                                    <img src="<?= htmlspecialchars($src) ?>"
                                         alt="<?= htmlspecialchars($g['title'] ?? '') ?>"
                                         loading="lazy"
                                         width="130" height="170"
                                         onerror="this.style.display='none'">
                                <?php endif; ?>
                                <?php if (!empty($g['igdb_rating'])): ?>
                                    <span class="game-card-mini__rating" style="z-index:2;position:relative"><?= round((float)$g['igdb_rating']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="game-card-mini__info">
                                <span class="game-card-mini__title"><?= htmlspecialchars($g['title'] ?? '') ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="games-scroll-nav games-scroll-nav--next" aria-label="Suivant">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                </button>
            </div>
            <?php else: ?>
            <div class="home-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                Aucun jeu synchronisé pour l'instant.
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════
         ÇA SORT AUJOURD'HUI
    ═══════════════════════════════════════════════════════ -->
    <section class="home-section home-section--col">
        <div class="container">
            <div class="home-section__header">
                <h2 class="home-section__title">Ça sort aujourd’hui</h2>
                <a href="/search?sort=upcoming" class="home-section__link">Voir tout →</a>
            </div>

            <?php if (!empty($todayGames)): ?>
            <div class="games-scroll-wrapper">
                <button class="games-scroll-nav games-scroll-nav--prev is-hidden" aria-label="Précédent">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <div class="games-scroll">
                    <div class="games-scroll__track">
                        <?php foreach ($todayGames as $g):
                            $src = coverSrc($g['cover_url'] ?? null);
                        ?>
                        <a href="/game/<?= htmlspecialchars($g['slug'] ?? '') ?>"
                           class="game-card-mini"
                           title="<?= htmlspecialchars($g['title'] ?? '') ?>">
                            <div class="game-card-mini__cover">
                                <div class="game-card-mini__cover-placeholder" aria-hidden="true">
                                    <?= htmlspecialchars(mb_substr($g['title'] ?? '?', 0, 30)) ?>
                                </div>
                                <?php if ($src): ?>
                                    <img src="<?= htmlspecialchars($src) ?>"
                                         alt="<?= htmlspecialchars($g['title'] ?? '') ?>"
                                         loading="lazy"
                                         width="130" height="170"
                                         onerror="this.style.display='none'">
                                <?php endif; ?>
                            </div>
                            <div class="game-card-mini__info">
                                <span class="game-card-mini__title"><?= htmlspecialchars($g['title'] ?? '') ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="games-scroll-nav games-scroll-nav--next" aria-label="Suivant">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                </button>
            </div>
            <?php else: ?>
            <div class="home-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
                Aucun jeu ne sort aujourd’hui (selon la date de sortie IGDB).
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════
         POPULAIRES PAR GENRE
    ═══════════════════════════════════════════════════════ -->
    <?php if (!empty($genreHighlights)): ?>
    <section class="home-section home-section--col genre-section">
        <div class="container">
            <div class="home-section__header">
                <h2 class="home-section__title">Populaires par genre</h2>
                <a href="/search?order=igdb_rating.desc" class="home-section__link">Voir tout →</a>
            </div>

            <div class="genre-tabs" role="tablist">
                <?php $first = true; foreach ($genreHighlights as $genre => $games): ?>
                <button class="genre-tab<?= $first ? ' is-active' : '' ?>"
                        role="tab"
                        aria-selected="<?= $first ? 'true' : 'false' ?>"
                        aria-controls="genre-<?= htmlspecialchars(str_replace(' ', '-', strtolower($genre))) ?>"
                        data-target="genre-<?= htmlspecialchars(str_replace(' ', '-', strtolower($genre))) ?>">
                    <?= htmlspecialchars($genre) ?>
                </button>
                <?php $first = false; endforeach; ?>
            </div>

            <?php $first = true; foreach ($genreHighlights as $genre => $games): ?>
            <div class="genre-panel<?= $first ? ' is-active' : '' ?>"
                 id="genre-<?= htmlspecialchars(str_replace(' ', '-', strtolower($genre))) ?>"
                 role="tabpanel">
                <div class="genre-panel__grid">
                    <?php foreach (array_slice($games, 0, 6) as $g):
                        $src = coverSrc($g['cover_url'] ?? null);
                    ?>
                    <a href="/game/<?= htmlspecialchars($g['slug'] ?? '') ?>"
                       class="game-card-genre"
                       title="<?= htmlspecialchars($g['title'] ?? '') ?>">
                        <div class="game-card-genre__cover">
                            <div class="game-card-mini__cover-placeholder" aria-hidden="true">
                                <?= htmlspecialchars(mb_substr($g['title'] ?? '?', 0, 30)) ?>
                            </div>
                            <?php if ($src): ?>
                                <img src="<?= htmlspecialchars($src) ?>"
                                     alt="<?= htmlspecialchars($g['title'] ?? '') ?>"
                                     loading="lazy"
                                     onerror="this.style.display='none'">
                            <?php endif; ?>
                            <?php if (!empty($g['igdb_rating'])): ?>
                                <span class="game-card-genre__rating" style="z-index:2;position:relative"><?= round((float)$g['igdb_rating']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="game-card-genre__title"><?= htmlspecialchars($g['title'] ?? '') ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php $first = false; endforeach; ?>

        </div>
    </section>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════
         PLATEFORMES
    ═══════════════════════════════════════════════════════ -->
    <?php if (!empty($topPlatforms)): ?>
    <section class="home-section home-section--col">
        <div class="container">
            <div class="home-section__header">
                <h2 class="home-section__title">Parcourir par plateforme</h2>
                <a href="/search" class="home-section__link">Toutes les plateformes →</a>
            </div>

            <div class="platform-grid">
                <?php foreach ($topPlatforms as $p): ?>
                <a href="/search?platform=<?= (int)($p['id'] ?? 0) ?>"
                   class="platform-card"
                   title="<?= htmlspecialchars($p['name'] ?? '') ?>">
                    <?php if (!empty($p['logo_url'])): ?>
                        <img src="<?= htmlspecialchars(coverSrc($p['logo_url'])) ?>"
                             alt="<?= htmlspecialchars($p['abbreviation'] ?? $p['name'] ?? '') ?>"
                             class="platform-card__logo"
                             loading="lazy"
                             height="36">
                    <?php else: ?>
                        <span class="platform-card__abbr">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($p['abbreviation'] ?? $p['name'] ?? '?', 0, 4))) ?>
                        </span>
                    <?php endif; ?>
                    <span><?= htmlspecialchars($p['abbreviation'] ?? $p['name'] ?? '') ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
</div>


<!-- ═══════════════════════════════════════════════════════
     DERNIERS AVIS COMMUNAUTÉ
═══════════════════════════════════════════════════════ -->
<section class="home-section">
    <div class="container">
        <div class="home-section__header">
            <h2 class="home-section__title">Derniers avis de la communauté</h2>
        </div>

        <?php if (!empty($latestReviews)): ?>
        <div class="reviews-grid">
            <?php foreach ($latestReviews as $r):
                $game   = $r['games']  ?? [];
                $user   = $r['users']  ?? [];
                $rating = (int)($r['rating'] ?? 0);
                $gameSrc = coverSrc($game['cover_url'] ?? null);
            ?>
            <article class="review-card">

                <div class="review-card__header">
                    <div class="review-card__cover">
                        <?php if ($gameSrc): ?>
                            <img src="<?= htmlspecialchars($gameSrc) ?>"
                                 alt=""
                                 loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div class="review-card__meta">
                        <a href="/game/<?= htmlspecialchars($game['slug'] ?? '') ?>"
                           class="review-card__game">
                            <?= htmlspecialchars($game['title'] ?? 'Jeu inconnu') ?>
                        </a>
                        <div class="review-card__rating" aria-label="Note : <?= $rating ?>/10">
                            <?= ratingStars($rating) ?>
                            <span class="review-card__score"><?= $rating ?>/10</span>
                        </div>
                    </div>
                </div>

                <p class="review-card__body"><?= htmlspecialchars($r['body'] ?? '') ?></p>

                <footer class="review-card__footer">
                    <?php if (!empty($user['avatar_url'])): ?>
                        <img src="<?= htmlspecialchars($user['avatar_url']) ?>"
                             alt=""
                             class="review-card__avatar"
                             loading="lazy">
                    <?php else: ?>
                        <span class="review-card__avatar-placeholder" aria-hidden="true">
                            <?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                    <span class="review-card__user">
                        <?= htmlspecialchars($user['username'] ?? 'Anonyme') ?>
                    </span>
                    <time class="review-card__date" datetime="<?= htmlspecialchars($r['created_at'] ?? '') ?>">
                        <?= htmlspecialchars(fmtDate(substr($r['created_at'] ?? '', 0, 10))) ?>
                    </time>
                </footer>

            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="home-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Aucun avis pour l'instant — soyez le premier !
        </div>
        <?php endif; ?>
    </div>
</section>


<!-- ═══════════════════════════════════════════════════════
     CTA FINAL — INVITÉS SEULEMENT
═══════════════════════════════════════════════════════ -->
<?php if (!$authUser): ?>
<section class="home-cta-band">
    <div class="container">
        <h2 class="home-cta-band__title">
            Prêt à cataloguer votre collection ?
        </h2>
        <p class="home-cta-band__sub">
            Inscription gratuite en 30 secondes. Aucune carte bancaire requise.
        </p>
        <div class="home-cta-band__actions">
            <a href="/register" class="btn btn--primary btn--lg-inline">Créer mon compte</a>
            <a href="/search"   class="btn btn--ghost btn--lg-inline">Explorer sans compte</a>
        </div>
    </div>
</section>
<?php endif; ?>
