<?php
/**
 * Partial : zone résultats de recherche.
 *
 * Rendu seul pour les réponses AJAX (SearchController détecte X-Requested-With).
 * Inclus dans views/search/index.php pour le rendu complet initial.
 *
 * Variables attendues :
 *   $games        array           – jeux de la page courante
 *   $totalResults int             – total de jeux correspondant aux filtres
 *   $filters      array           – filtres actifs
 *   $baseUrl      string          – URL canonique /search?... sans cursor
 *   $nextCursor   ?string         – cursor pour la page suivante
 *   $pageSize     int             – taille page (pour hasNext)
 */

// ──────────────────────────────────────────────
//  Helpers (protégés contre la double déclaration quand le fichier
//  est inclus depuis index.php qui les définit déjà)
// ──────────────────────────────────────────────
if (!function_exists('searchCoverSrc')) {
    function searchCoverSrc(?string $url): string
    {
        if (!$url) return '';
        if (str_starts_with($url, '/') || str_starts_with($url, 'http')) return $url;
        return '/storage/images/igdb/' . $url;
    }
}
if (!function_exists('searchFmtDate')) {
    function searchFmtDate(?string $date): string
    {
        if (!$date) return '';
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : $date;
    }
}

if (!function_exists('searchFmtDateShort')) {
    function searchFmtDateShort(?string $date): string
    {
        if (!$date) return '';
        $ts = strtotime($date);
        if (!$ts) return $date;
        $months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
        $m = (int) date('n', $ts);
        $mon = $months[$m - 1] ?? date('M', $ts);
        return (int) date('j', $ts) . ' ' . $mon . ' ' . date('Y', $ts);
    }
}

$viewMode    = $_COOKIE['view_mode'] ?? 'grid';
$currentSort = $filters['sort'] ?? 'all';
$platformMap = $platformMap ?? [];
$platformMap = is_array($platformMap) ? $platformMap : [];
$platformBadgeStyle = static function (int $id) use ($platformMap): string {
    $row = $platformMap[$id] ?? null;
    if (!is_array($row)) return '';
    return \App\Data\PlatformBadgeColors::style(
        $id,
        (string) ($row['slug'] ?? ''),
        (string) ($row['abbreviation'] ?? ''),
        (string) ($row['name'] ?? '')
    );
};
$sortOptions = [
    'recent'     => 'Plus récents',
    'upcoming'   => 'Prochaines sorties',
    'rating'     => 'Mieux notés',
];

$baseUrl    = $baseUrl ?? '/search';
$nextCursor = $nextCursor ?? null;
$pageSize   = (int) ($pageSize ?? 42);
$error      = !empty($error);
$countMode  = (string) ($countMode ?? 'estimated');
?>

<?php /* Toolbar : compteur + tri + toggle vue */ ?>
<div class="search-toolbar">
    <p class="search-toolbar__count" id="results-count" aria-live="polite" aria-atomic="true">
        <?php if ($countMode === 'none' && !empty($games)): ?>
            <strong><?= number_format(is_array($games) ? count($games) : 0) ?>+</strong> résultats
            <?php if (!empty($filters['q'])): ?>
                pour <em>« <?= htmlspecialchars($filters['q']) ?> »</em>
            <?php endif; ?>
        <?php elseif ($totalResults > 0): ?>
            <strong><?= number_format($totalResults) ?></strong>
            <?= $totalResults === 1 ? 'résultat' : 'résultats' ?>
            <?php if (!empty($filters['q'])): ?>
                pour <em>« <?= htmlspecialchars($filters['q']) ?> »</em>
            <?php endif; ?>
        <?php else: ?>
            Aucun résultat
        <?php endif; ?>
    </p>

    <div class="search-toolbar__right">
        <div class="search-toolbar__sort">
            <label class="sr-only" for="sort-select">Trier par</label>
            <select class="sort-select" id="sort-select" name="sort" form="filters-form">
                <option value="" <?= ($currentSort === 'all') ? 'selected' : '' ?>>Tous les jeux</option>
                <?php foreach ($sortOptions as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $currentSort === $val ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php require __DIR__ . '/../partials/view-toggle.php'; ?>
    </div>
</div>

<?php /* Grille de résultats */ ?>
<?php if ($error): ?>
    <div class="search-empty">
        <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path d="M32 6c14.36 0 26 11.64 26 26S46.36 58 32 58 6 46.36 6 32 17.64 6 32 6z"/>
            <path d="M32 18v16" stroke-linecap="round"/>
            <path d="M32 42h.01" stroke-linecap="round"/>
        </svg>
        <p>Erreur temporaire lors du chargement des résultats. Réessayez dans quelques secondes.</p>
        <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn--secondary">Réessayer</a>
    </div>
<?php elseif (empty($games)): ?>
    <div class="search-empty">
        <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <circle cx="28" cy="28" r="20"/><path d="M44 44l12 12"/>
            <path d="M20 28h16M28 20v16" stroke-linecap="round"/>
        </svg>
        <p>Aucun jeu ne correspond à votre recherche.</p>
        <a href="/search" class="btn btn--secondary">Réinitialiser les filtres</a>
    </div>
<?php else: ?>
    <div class="game-grid game-grid--<?= htmlspecialchars($viewMode) ?>" id="results-grid">
        <?php foreach ($games as $g): ?>
            <?php
                $src    = searchCoverSrc($g['cover_url'] ?? '');
                $rating = isset($g['igdb_rating']) ? round((float)$g['igdb_rating']) : null;
                $genres = is_string($g['genres'] ?? null)
                              ? json_decode($g['genres'], true)
                              : ($g['genres'] ?? []);
                $platformIds = $g['platform_ids'] ?? [];
                if (is_string($platformIds)) {
                    $platformIds = json_decode($platformIds, true);
                }
                $platformIds = is_array($platformIds) ? array_values(array_filter($platformIds, 'is_numeric')) : [];
                $platformBadges = [];
                $platformBadgeExtraCount = 0;
                foreach ($platformIds as $pid) {
                    $pid = (int) $pid;
                    if ($pid <= 0) continue;
                    if (isset($platformMap[$pid])) {
                        if (count($platformBadges) < 2) {
                            $row = $platformMap[$pid];
                            $platformBadges[] = [
                                'id' => $pid,
                                'label' => is_array($row) ? (string) ($row['label'] ?? '') : (string) $row,
                            ];
                        } else {
                            $platformBadgeExtraCount++;
                        }
                    }
                }
                $collectionGate = \App\Services\CollectionReleasePolicy::checkAddAllowed($g['release_date'] ?? null);
                $collectionAddBlocked = !$collectionGate['allowed'];
                $collectionAddHint = $collectionAddBlocked
                    ? (string) ($collectionGate['message'] ?? 'Ajout à la collection non disponible pour l\'instant.')
                    : 'Ajouter à ma sélection';
            ?>
            <article class="search-card" data-game-id="<?= (int)$g['id'] ?>">

                <div class="search-card__cover">
                    <div class="search-card__placeholder" aria-hidden="true">
                        <?= htmlspecialchars(mb_substr($g['title'] ?? '?', 0, 25)) ?>
                    </div>
                    <?php if ($src): ?>
                        <img src="<?= htmlspecialchars($src) ?>"
                             alt="<?= htmlspecialchars($g['title'] ?? '') ?>"
                             loading="lazy"
                             onerror="this.style.display='none'">
                    <?php endif; ?>
                    <button class="wish-badge wish-badge--overlay"
                            type="button"
                            data-action="wishlist-toggle"
                            data-game-id="<?= (int)$g['id'] ?>"
                            aria-pressed="false"
                            aria-label="Ajouter à ma wishlist"
                            title="Ajouter à ma wishlist">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M13 3c0 3-2 4-2 6 0 1.2.8 2.2 2 2.6 0-2.2 2-3.4 2-6 2.5 2 4 4.4 4 7.4A7 7 0 1 1 5 13c0-2.3 1.1-4.3 2.6-5.8.2 1.7 1.2 3.1 2.6 4C9.4 7.6 11 6.2 13 3Z"
                                  fill="currentColor"/>
                        </svg>
                    </button>
                    <?php if ($rating !== null): ?>
                        <span class="search-card__rating
                            <?= $rating >= 80 ? 'search-card__rating--high' : ($rating >= 60 ? 'search-card__rating--mid' : 'search-card__rating--low') ?>">
                            <?= $rating ?>
                        </span>
                    <?php endif; ?>
                    <button class="search-card__add-btn<?= $collectionAddBlocked ? ' search-card__add-btn--blocked' : '' ?>"
                            type="button"
                            data-action="open-popup"
                            data-game-id="<?= (int)$g['id'] ?>"
                            data-game-title="<?= htmlspecialchars($g['title'] ?? '') ?>"
                            data-release-date="<?= htmlspecialchars((string)($g['release_date'] ?? '')) ?>"
                            aria-label="<?= $collectionAddBlocked
                                ? 'Ajout indisponible — ' . htmlspecialchars(strip_tags($collectionAddHint))
                                : 'Ajouter ' . htmlspecialchars($g['title'] ?? '') . ' à ma sélection' ?>"
                            title="<?= htmlspecialchars($collectionAddHint) ?>"
                            <?= $collectionAddBlocked ? 'disabled tabindex="-1"' : '' ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                    </button>
                </div>

                <div class="search-card__body">
                    <h2 class="search-card__title">
                        <a href="/game/<?= htmlspecialchars($g['slug'] ?? (string)$g['id']) ?>">
                            <?= htmlspecialchars($g['title'] ?? '—') ?>
                        </a>
                    </h2>
                    <?php if (!empty($platformBadges)): ?>
                        <div class="search-card__platforms" aria-label="Plateformes">
                            <?php foreach ($platformBadges as $b): ?>
                                <span class="platform-badge platform-badge--xs"
                                      style="<?= htmlspecialchars($platformBadgeStyle((int)($b['id'] ?? 0)), ENT_QUOTES) ?>">
                                    <?= htmlspecialchars((string)($b['label'] ?? '')) ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if ($platformBadgeExtraCount > 0): ?>
                                <span class="platform-badge platform-badge--xs platform-badge--more">
                                    <?= $platformBadgeExtraCount ?> autre<?= $platformBadgeExtraCount > 1 ? 's' : '' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($g['release_date'])): ?>
                        <span class="search-card__date" data-role="date-line"><?= searchFmtDate($g['release_date']) ?></span>
                        <span class="search-card__date-badge" data-role="date-badge" aria-label="Date de sortie">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18"/>
                            </svg>
                            <span><?= htmlspecialchars(searchFmtDateShort($g['release_date'])) ?></span>
                        </span>
                    <?php endif; ?>
                    <button class="wish-badge wish-badge--inline"
                            type="button"
                            data-action="wishlist-toggle"
                            data-game-id="<?= (int)$g['id'] ?>"
                            aria-pressed="false"
                            aria-label="Ajouter à ma wishlist"
                            title="Ajouter à ma wishlist">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M13 3c0 3-2 4-2 6 0 1.2.8 2.2 2 2.6 0-2.2 2-3.4 2-6 2.5 2 4 4.4 4 7.4A7 7 0 1 1 5 13c0-2.3 1.1-4.3 2.6-5.8.2 1.7 1.2 3.1 2.6 4C9.4 7.6 11 6.2 13 3Z"
                                  fill="currentColor"/>
                        </svg>
                    </button>
                    <?php if (!empty($g['developer'])): ?>
                        <span class="search-card__dev" data-role="dev-line"><?= htmlspecialchars($g['developer']) ?></span>
                    <?php endif; ?>
                </div>

            </article>
        <?php endforeach; ?>
    </div>

    <?php
        $gamesCount = is_array($games) ? count($games) : 0;
        $countModeForPager = $countMode;
        require __DIR__ . '/../partials/pagination_cursor.php';
    ?>
<?php endif; ?>
