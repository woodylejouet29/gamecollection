<?php
/**
 * Vue : /collection — Collection personnelle
 *
 * Variables injectées par CollectionController :
 *   $entries       array   – entrées de la page courante (normalisées)
 *   $totalResults  int     – total d'entrées correspondant aux filtres
 *   $filters       array   – { list, platform, status, game_type, region, condition, q, sort }
 *   $filterOptions array   – { platforms: [...] }
 *   $stats         array   – { total, unique_games, physical_count, digital_count, total_value, total_play_minutes, statuses }
 *   $page          int     – page courante
 *   $perPage       int
 *   $totalPages    int
 *   $baseUrl       string
 *   $authUser      array
 */

// ── Helpers ──────────────────────────────────────────────────────────
function colCoverSrc(?string $url): string
{
    if (!$url) return '';
    if (str_starts_with($url, '/') || str_starts_with($url, 'http')) return $url;
    return '/storage/images/igdb/' . $url;
}

function colFmtDate(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

function colFmtPlayTime(?int $minutes): string
{
    if (!$minutes) return '';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h > 0 ? ($h . 'h' . ($m > 0 ? sprintf('%02d', $m) : '')) : $m . 'min';
}

$statusLabels = [
    'owned'           => 'Possédé',
    'playing'         => 'En cours',
    'completed'       => 'Terminé',
    'hundred_percent' => '100%',
    'abandoned'       => 'Abandonné',
];
$statusColors = [
    'owned'           => 'owned',
    'playing'         => 'playing',
    'completed'       => 'completed',
    'hundred_percent' => 'hundred',
    'abandoned'       => 'abandoned',
];
$conditionLabels = [
    'mint'       => 'Mint',
    'near_mint'  => 'Near Mint',
    'very_good'  => 'Très bon',
    'good'       => 'Bon',
    'acceptable' => 'Acceptable',
    'poor'       => 'Mauvais',
    'damaged'    => 'Endommagé',
    'incomplete' => 'Incomplet',
];
$regionLabels = ['PAL' => 'PAL', 'NTSC-U' => 'NTSC-U', 'NTSC-J' => 'NTSC-J', 'NTSC-K' => 'NTSC-K', 'other' => 'Autre'];

$viewMode    = $_COOKIE['view_mode'] ?? 'grid';
$totalMins   = (int) ($stats['total_play_minutes'] ?? 0);
$totalHours  = $totalMins > 0 ? round($totalMins / 60, 1) : 0;
$totalValue  = isset($stats['total_value']) ? number_format((float)$stats['total_value'], 0, ',', ' ') : '0';
$currentList = $filters['list'] ?? 'all';

$listTabs = [
    'all'             => ['label' => 'Tout',       'icon' => ''],
    'playing'         => ['label' => 'En cours',   'icon' => ''],
    'finished'        => ['label' => 'Terminé',    'icon' => ''],
    'hundred_percent' => ['label' => '100%',       'icon' => ''],
    'abandoned'       => ['label' => 'Abandonné',  'icon' => ''],
    'physical'        => ['label' => 'Physique',   'icon' => ''],
    'digital'         => ['label' => 'Démat',      'icon' => ''],
    'ranked'          => ['label' => 'Classement', 'icon' => ''],
];

$sortOptions = [
    'recent'       => 'Plus récents',
    'title_asc'    => 'Titre A→Z',
    'title_desc'   => 'Titre Z→A',
    'acquired_desc'=> 'Date acquisition ↓',
    'acquired_asc' => 'Date acquisition ↑',
    'rank_asc'     => 'Classement',
    'price_desc'   => 'Prix ↓',
    'rating_desc'  => 'Note ↓',
];
?>

<?php /* ═══ STATS STRIP ══════════════════════════════════════════════ */ ?>
<div class="col-stats-strip">
    <div class="container">
        <div class="col-stats">
            <div class="col-stat">
                <span class="col-stat__val"><?= number_format((int)($stats['total'] ?? 0)) ?></span>
                <span class="col-stat__label">entrée<?= ($stats['total'] ?? 0) > 1 ? 's' : '' ?></span>
            </div>
            <div class="col-stat col-stat--sep"></div>
            <div class="col-stat">
                <span class="col-stat__val"><?= (int)($stats['unique_games'] ?? 0) ?></span>
                <span class="col-stat__label">jeu<?= ($stats['unique_games'] ?? 0) > 1 ? 'x' : '' ?> uniques</span>
            </div>
            <div class="col-stat col-stat--sep"></div>
            <div class="col-stat col-stat--split">
                <span class="col-stat__chip col-stat__chip--phys">
                    <?= (int)($stats['physical_count'] ?? 0) ?> Physique<?= ($stats['physical_count'] ?? 0) > 1 ? 's' : '' ?>
                </span>
                <span class="col-stat__chip col-stat__chip--digi">
                    <?= (int)($stats['digital_count'] ?? 0) ?> Démat
                </span>
            </div>
            <?php if ($totalValue !== '0'): ?>
            <div class="col-stat col-stat--sep"></div>
            <div class="col-stat">
                <span class="col-stat__val"><?= $totalValue ?> €</span>
                <span class="col-stat__label">valeur estimée</span>
            </div>
            <?php endif; ?>
            <?php if ($totalHours > 0): ?>
            <div class="col-stat col-stat--sep"></div>
            <div class="col-stat">
                <span class="col-stat__val"><?= $totalHours ?>h</span>
                <span class="col-stat__label">jouées</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php /* ═══ EN-TÊTE ═════════════════════════════════════════════════ */ ?>
<div class="col-header">
    <div class="container">
        <div class="col-header__row">
            <h1 class="col-header__title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true">
                    <path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                Ma collection
            </h1>
            <div class="col-header__actions">
                <?php /* Export dropdown */ ?>
                <div class="col-export-wrap" id="col-export-wrap">
                    <button type="button" class="btn btn--ghost btn--sm col-export-btn" id="col-export-btn"
                            aria-haspopup="true" aria-expanded="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" aria-hidden="true" style="width:14px;height:14px">
                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>
                        </svg>
                        Export
                    </button>
                    <div class="col-export-menu" id="col-export-menu" hidden role="menu">
                        <a href="/api/collection/export?format=json" class="col-export-item" role="menuitem">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:14px;height:14px">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>
                            </svg>
                            JSON
                        </a>
                        <a href="/api/collection/export?format=csv" class="col-export-item" role="menuitem">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:14px;height:14px">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>
                                <line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/>
                            </svg>
                            CSV (Excel)
                        </a>
                    </div>
                </div>
                <a href="/select" class="btn btn--primary btn--sm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" aria-hidden="true" style="width:14px;height:14px">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Ajouter
                </a>
            </div>
        </div>
    </div>
</div>

<?php /* ═══ TABS LISTES FIXES ════════════════════════════════════════ */ ?>
<div class="col-tabs-wrap">
    <div class="container">
        <nav class="col-tabs" aria-label="Listes personnelles" role="tablist">
            <?php foreach ($listTabs as $key => $tab):
                $params = array_merge(
                    array_filter(['sort' => $filters['sort'] !== 'recent' ? $filters['sort'] : '']),
                    ['list' => $key === 'all' ? '' : $key]
                );
                $href = '/collection' . ($params ? '?' . http_build_query(array_filter($params)) : '');
                $count = match($key) {
                    'all'             => $stats['total'] ?? 0,
                    'playing'         => $stats['statuses']['playing'] ?? 0,
                    'finished'        => ($stats['statuses']['completed'] ?? 0) + ($stats['statuses']['hundred_percent'] ?? 0),
                    'hundred_percent' => $stats['statuses']['hundred_percent'] ?? 0,
                    'abandoned'       => $stats['statuses']['abandoned'] ?? 0,
                    'physical'        => $stats['physical_count'] ?? 0,
                    'digital'         => $stats['digital_count'] ?? 0,
                    default           => 0,
                };
            ?>
                <a href="<?= htmlspecialchars($href) ?>"
                   class="col-tab<?= $currentList === $key || ($key === 'all' && $currentList === 'all') ? ' is-active' : '' ?>"
                   role="tab"
                   aria-selected="<?= ($currentList === $key || ($key === 'all' && $currentList === 'all')) ? 'true' : 'false' ?>">
                    <?= htmlspecialchars($tab['label']) ?>
                    <?php if ($count > 0): ?>
                        <span class="col-tab__count"><?= number_format($count) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>

<?php /* ═══ TOOLBAR ═════════════════════════════════════════════════ */ ?>
<div class="col-toolbar">
    <div class="container">
        <div class="col-toolbar__inner">

            <?php /* Recherche titre */ ?>
            <form class="col-search-form" method="get" action="/collection" role="search">
                <?php if ($filters['list'] !== 'all'): ?>
                    <input type="hidden" name="list" value="<?= htmlspecialchars($filters['list']) ?>">
                <?php endif; ?>
                <div class="col-search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                    </svg>
                    <input type="search" name="q" class="col-search-input"
                           placeholder="Rechercher dans ma collection…"
                           value="<?= htmlspecialchars($filters['q']) ?>"
                           aria-label="Rechercher par titre">
                </div>
            </form>

            <div class="col-toolbar__right">
                <?php /* Filtres avancés */ ?>
                <button type="button" class="col-filter-btn<?= !empty(array_filter([$filters['platform'], $filters['status'], $filters['game_type'], $filters['region'], $filters['condition']])) ? ' is-active' : '' ?>"
                        id="col-filter-toggle" aria-controls="col-filter-panel" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" aria-hidden="true">
                        <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
                    </svg>
                    Filtres
                </button>

                <?php /* Tri */ ?>
                <form class="col-sort-form" method="get" action="/collection" id="col-sort-form">
                    <?php foreach (array_filter($filters, fn($v, $k) => $k !== 'sort' && $v !== '' && $v !== 0, ARRAY_FILTER_USE_BOTH) as $k => $v): ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                    <?php endforeach; ?>
                    <label class="sr-only" for="col-sort-select">Trier par</label>
                    <select class="col-sort-select" id="col-sort-select" name="sort"
                            onchange="this.form.submit()">
                        <?php foreach ($sortOptions as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $filters['sort'] === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php /* Vue toggle */ ?>
                <?php require __DIR__ . '/../partials/view-toggle.php'; ?>
            </div>
        </div>
    </div>
</div>

<?php /* ═══ PANEL FILTRES ═══════════════════════════════════════════ */ ?>
<div class="col-filter-panel" id="col-filter-panel" hidden>
    <div class="container">
        <form class="col-filter-form" method="get" action="/collection">
            <?php if ($filters['list'] !== 'all'): ?>
                <input type="hidden" name="list" value="<?= htmlspecialchars($filters['list']) ?>">
            <?php endif; ?>
            <?php if ($filters['sort'] !== 'recent'): ?>
                <input type="hidden" name="sort" value="<?= htmlspecialchars($filters['sort']) ?>">
            <?php endif; ?>

            <div class="col-filter-grid">

                <div class="form-group">
                    <label class="form-label" for="cf-platform">Plateforme</label>
                    <select class="form-input" id="cf-platform" name="platform">
                        <option value="">Toutes</option>
                        <?php foreach ($filterOptions['platforms'] ?? [] as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"
                                    <?= $filters['platform'] == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['abbreviation'] ?: $p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="cf-status">Statut</label>
                    <select class="form-input" id="cf-status" name="status">
                        <option value="">Tous</option>
                        <option value="owned"           <?= $filters['status'] === 'owned'           ? 'selected' : '' ?>>Possédé</option>
                        <option value="playing"         <?= $filters['status'] === 'playing'         ? 'selected' : '' ?>>En cours</option>
                        <option value="completed"       <?= $filters['status'] === 'completed'       ? 'selected' : '' ?>>Terminé</option>
                        <option value="hundred_percent" <?= $filters['status'] === 'hundred_percent' ? 'selected' : '' ?>>100%</option>
                        <option value="abandoned"       <?= $filters['status'] === 'abandoned'       ? 'selected' : '' ?>>Abandonné</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="cf-type">Support</label>
                    <select class="form-input" id="cf-type" name="game_type">
                        <option value="">Tous</option>
                        <option value="physical" <?= $filters['game_type'] === 'physical' ? 'selected' : '' ?>>Physique</option>
                        <option value="digital"  <?= $filters['game_type'] === 'digital'  ? 'selected' : '' ?>>Dématérialisé</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="cf-region">Région</label>
                    <select class="form-input" id="cf-region" name="region">
                        <option value="">Toutes</option>
                        <option value="PAL"    <?= $filters['region'] === 'PAL'    ? 'selected' : '' ?>>PAL</option>
                        <option value="NTSC-U" <?= $filters['region'] === 'NTSC-U' ? 'selected' : '' ?>>NTSC-U</option>
                        <option value="NTSC-J" <?= $filters['region'] === 'NTSC-J' ? 'selected' : '' ?>>NTSC-J</option>
                        <option value="other"  <?= $filters['region'] === 'other'  ? 'selected' : '' ?>>Autre</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="cf-cond">État physique</label>
                    <select class="form-input" id="cf-cond" name="condition">
                        <option value="">Tous</option>
                        <?php foreach ($conditionLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $filters['condition'] === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <div class="col-filter-actions">
                <a href="/collection<?= $filters['list'] !== 'all' ? '?list=' . htmlspecialchars($filters['list']) : '' ?>"
                   class="btn btn--ghost btn--sm">Réinitialiser</a>
                <button type="submit" class="btn btn--primary btn--sm">Appliquer</button>
            </div>
        </form>
    </div>
</div>

<?php /* ═══ RÉSULTATS ════════════════════════════════════════════════ */ ?>
<div class="container col-content">

    <?php /* Compteur + filtres actifs */ ?>
    <div class="col-results-bar">
        <p class="col-results-count" aria-live="polite">
            <?php if ($totalResults > 0): ?>
                <strong><?= number_format($totalResults) ?></strong>
                <?= $totalResults === 1 ? 'entrée' : 'entrées' ?>
                <?php if (!empty($filters['q'])): ?>
                    pour <em>«&nbsp;<?= htmlspecialchars($filters['q']) ?>&nbsp;»</em>
                <?php endif; ?>
            <?php else: ?>
                Aucune entrée
            <?php endif; ?>
        </p>
        <?php
        $activeFilters = array_filter([
            'Plateforme' => $filters['platform'] ? ($filterOptions['platforms'][0]['name'] ?? 'ID '.$filters['platform']) : '',
            'Statut'     => $filters['status']    ? ($statusLabels[$filters['status']] ?? $filters['status']) : '',
            'Support'    => $filters['game_type'] ? ($filters['game_type'] === 'physical' ? 'Physique' : 'Démat') : '',
            'Région'     => $filters['region']    ? ($regionLabels[$filters['region']] ?? $filters['region']) : '',
            'État'       => $filters['condition'] ? ($conditionLabels[$filters['condition']] ?? $filters['condition']) : '',
        ]);
        ?>
        <?php if (!empty($activeFilters)): ?>
        <div class="col-active-filters">
            <?php foreach ($activeFilters as $label => $val): ?>
                <span class="col-filter-tag"><?= htmlspecialchars($label) ?> : <?= htmlspecialchars($val) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php /* Grille des entrées */ ?>
    <?php if (empty($entries)): ?>
        <div class="col-empty">
            <div class="col-empty__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
            </div>
            <h2 class="col-empty__title">
                <?= $currentList === 'all' ? 'Votre collection est vide' : 'Aucune entrée dans cette liste' ?>
            </h2>
            <p class="col-empty__desc">
                <?= $currentList === 'all'
                    ? 'Parcourez le catalogue et ajoutez vos jeux via la page sélection.'
                    : 'Essayez une autre liste ou modifiez vos filtres.' ?>
            </p>
            <?php if ($currentList === 'all'): ?>
                <a href="/search" class="btn btn--primary">Parcourir le catalogue</a>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <div class="game-grid game-grid--<?= htmlspecialchars($viewMode) ?>" id="results-grid">
            <?php foreach ($entries as $entry):
                $game     = $entry['game'];
                $platform = $entry['platform'];
                $review   = $entry['review'];
                $src      = colCoverSrc($game['cover_url'] ?? '');
                $status   = $entry['status'] ?? 'owned';
                $statusLbl= $statusLabels[$status] ?? $status;
                $statusCls= $statusColors[$status] ?? 'owned';
                $isPhys   = $entry['game_type'] === 'physical';
                $genres   = is_array($game['genres']) ? $game['genres'] : [];
                $genreNames = array_slice(array_column($genres, 'name'), 0, 2);
            ?>
            <article class="col-card"
                     data-entry-id="<?= (int)$entry['id'] ?>"
                     data-game-id="<?= (int)$entry['game_id'] ?>"
                     data-status="<?= htmlspecialchars($status) ?>"
                     data-game-type="<?= htmlspecialchars($entry['game_type']) ?>"
                     data-condition="<?= htmlspecialchars($entry['physical_condition'] ?? '') ?>"
                     data-review-rating="<?= (int)($review['rating'] ?? 0) ?>"
                     data-rank="<?= (int)$entry['rank_position'] ?>"
                     data-play-time="<?= (int)($entry['play_time_minutes'] ?? 0) ?>">

                <?php /* ── Couverture ── */ ?>
                <div class="col-card__cover">
                    <?php if ($src): ?>
                        <img src="<?= htmlspecialchars($src) ?>"
                             alt="<?= htmlspecialchars($game['title']) ?>"
                             loading="lazy"
                             onerror="this.style.display='none'">
                    <?php else: ?>
                        <div class="col-card__cover-placeholder" aria-hidden="true">
                            <?= htmlspecialchars(mb_substr($game['title'], 0, 2)) ?>
                        </div>
                    <?php endif; ?>

                    <?php /* Status badge */ ?>
                    <span class="col-status-badge col-status-badge--<?= $statusCls ?>"><?= htmlspecialchars($statusLbl) ?></span>

                    <?php /* Rating badge (user review) */ ?>
                    <?php if ($review && isset($review['rating'])): ?>
                        <span class="col-rating-badge"><?= (int)$review['rating'] ?>/10</span>
                    <?php endif; ?>

                    <?php /* Type badge (D = Démat, P = Physique, pour vue grille) */ ?>
                    <span class="col-type-badge col-type-badge--<?= $isPhys ? 'phys' : 'digi' ?>"
                          title="<?= $isPhys ? 'Physique' : 'Dématérialisé' ?>">
                        <?= $isPhys ? 'P' : 'D' ?>
                    </span>

                    <?php /* Overlay actions au survol */ ?>
                    <div class="col-card__overlay" aria-hidden="true">
                        <button type="button" class="col-card__edit-btn" data-action="quick-edit"
                                aria-label="Modifier <?= htmlspecialchars($game['title']) ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </button>
                        <button type="button" class="col-card__del-btn" data-action="delete"
                                aria-label="Supprimer <?= htmlspecialchars($game['title']) ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <?php /* ── Corps ── */ ?>
                <div class="col-card__body">
                    <h2 class="col-card__title">
                        <a href="/game/<?= htmlspecialchars($game['slug'] ?: (string)$game['id']) ?>">
                            <?= htmlspecialchars($game['title']) ?>
                        </a>
                    </h2>

                    <div class="col-card__meta">
                        <?php if ($platform['abbreviation'] || $platform['name']): ?>
                            <span class="col-card__platform">
                                <?= htmlspecialchars($platform['abbreviation'] ?: $platform['name']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($entry['region'] && $entry['region'] !== 'other'): ?>
                            <span class="col-card__region"><?= htmlspecialchars($regionLabels[$entry['region']] ?? $entry['region']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php /* Extra infos (vues cartes + liste) */ ?>
                    <div class="col-card__extra">
                        <?php if ($entry['play_time_minutes']): ?>
                            <span class="col-card__info-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:11px;height:11px">
                                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                </svg>
                                <?= htmlspecialchars(colFmtPlayTime($entry['play_time_minutes'])) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($entry['acquired_at']): ?>
                            <span class="col-card__info-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:11px;height:11px">
                                    <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
                                </svg>
                                <?= htmlspecialchars(colFmtDate($entry['acquired_at'])) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($isPhys && $entry['physical_condition']): ?>
                            <span class="col-card__info-item">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:11px;height:11px">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                </svg>
                                <?= htmlspecialchars($conditionLabels[$entry['physical_condition']] ?? $entry['physical_condition']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($entry['rank_position'] > 0): ?>
                            <span class="col-card__info-item col-card__rank">
                                #<?= (int)$entry['rank_position'] ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php /* Actions inline (vue liste) */ ?>
                    <div class="col-card__actions">
                        <button type="button" class="col-card__action-btn" data-action="quick-edit"
                                aria-label="Modifier">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            <span>Modifier</span>
                        </button>
                        <button type="button" class="col-card__action-btn col-card__action-btn--danger" data-action="delete"
                                aria-label="Supprimer">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                            </svg>
                            <span>Supprimer</span>
                        </button>
                    </div>
                </div>

            </article>
            <?php endforeach; ?>
        </div>

        <?php /* ── Pagination ── */ ?>
        <?php if ($totalPages > 1): ?>
        <nav class="col-pagination" aria-label="Navigation pages">
            <?php
            $buildPageUrl = function(int $p) use ($baseUrl): string {
                $sep = str_contains($baseUrl, '?') ? '&' : '?';
                return $p > 1 ? $baseUrl . $sep . 'page=' . $p : $baseUrl;
            };
            $prev = $page > 1 ? $buildPageUrl($page - 1) : null;
            $next = $page < $totalPages ? $buildPageUrl($page + 1) : null;
            ?>
            <?php if ($prev): ?>
                <a href="<?= htmlspecialchars($prev) ?>" class="col-page-btn" rel="prev" aria-label="Page précédente">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                </a>
            <?php endif; ?>

            <span class="col-page-info">Page <?= $page ?> / <?= $totalPages ?></span>

            <?php if ($next): ?>
                <a href="<?= htmlspecialchars($next) ?>" class="col-page-btn" rel="next" aria-label="Page suivante">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php /* ═══ MODALE QUICK-EDIT ══════════════════════════════════════ */ ?>
<div class="col-modal" id="col-edit-modal" aria-modal="true" role="dialog"
     aria-labelledby="col-edit-title" hidden>
    <div class="col-modal__backdrop" id="col-edit-backdrop"></div>
    <div class="col-modal__panel" role="document">

        <div class="col-modal__header">
            <h2 class="col-modal__title" id="col-edit-title">Modifier l'entrée</h2>
            <button type="button" class="col-modal__close" id="col-edit-close" aria-label="Fermer">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="col-modal__body">
            <input type="hidden" id="edit-entry-id" value="">
            <input type="hidden" id="edit-game-id" value="">
            <input type="hidden" id="edit-game-type" value="">

            <div class="form-group">
                <label class="form-label" for="edit-status">Statut <span class="required">*</span></label>
                <select class="form-input" id="edit-status">
                    <option value="owned">Possédé</option>
                    <option value="playing">En cours</option>
                    <option value="completed">Terminé</option>
                    <option value="hundred_percent">100% complété</option>
                    <option value="abandoned">Abandonné</option>
                </select>
            </div>

            <div class="form-group" id="edit-playtime-group" hidden>
                <label class="form-label">Durée de complétion</label>
                <div class="edit-playtime-row">
                    <input class="form-input" type="number" id="edit-playtime-h" min="0" max="9999" placeholder="0" aria-label="Heures">
                    <span>h</span>
                    <input class="form-input" type="number" id="edit-playtime-m" min="0" max="59" placeholder="00" aria-label="Minutes">
                    <span>min</span>
                </div>
            </div>

            <div class="form-group" id="edit-rating-group" hidden>
                <label class="form-label">Note <span class="optional">(optionnel)</span></label>
                <div class="edit-rating-pills" role="group" aria-label="Note sur 10">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <button type="button" class="edit-rating-pill" data-value="<?= $i ?>" aria-label="Note <?= $i ?>/10" aria-pressed="false"><?= $i ?></button>
                    <?php endfor; ?>
                    <input type="hidden" id="edit-rating-val" value="">
                    <span class="edit-rating-label" id="edit-rating-label" aria-live="polite"></span>
                </div>
            </div>

            <div class="form-group" id="edit-review-group" hidden>
                <label class="form-label" for="edit-review">Avis <span class="optional">(≥ 100 car.)</span></label>
                <textarea class="form-input" id="edit-review" rows="4"
                          placeholder="Partagez votre ressenti…"
                          aria-describedby="edit-review-count"></textarea>
                <span class="col-char-count" id="edit-review-count">0 / 100</span>
            </div>

            <div class="form-group" id="edit-condition-group" hidden>
                <label class="form-label" for="edit-condition">État physique <span class="required">*</span></label>
                <select class="form-input" id="edit-condition">
                    <option value="mint">Parfait (Mint)</option>
                    <option value="near_mint">Quasi parfait (Near Mint)</option>
                    <option value="very_good">Très bon état</option>
                    <option value="good">Bon état</option>
                    <option value="acceptable">État acceptable</option>
                    <option value="poor">Mauvais état</option>
                    <option value="damaged">Endommagé</option>
                    <option value="incomplete">Incomplet</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="edit-rank">Position dans ma liste</label>
                <input class="form-input" type="number" id="edit-rank" min="0" max="9999" placeholder="0 = non classé">
            </div>

            <div class="col-modal__error" id="col-edit-error" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                </svg>
                <span id="col-edit-error-msg">Une erreur est survenue.</span>
            </div>
        </div>

        <div class="col-modal__footer">
            <button type="button" class="btn btn--ghost btn--sm" id="col-edit-cancel">Annuler</button>
            <button type="button" class="btn btn--primary" id="col-edit-save">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="width:15px;height:15px">
                    <path d="M20 6L9 17l-5-5"/>
                </svg>
                Enregistrer
            </button>
        </div>
    </div>
</div>
