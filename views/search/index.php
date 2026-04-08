<?php
/**
 * Vue complète : /search
 *
 * Variables injectées par SearchController :
 *   $games         array
 *   $totalResults  int
 *   $filters       array
 *   $filterOptions array  { platforms: [...], genres: [...] }
 *   $activeFilters array
 *   $baseUrl       string
 *   $nextCursor    ?string
 *   $pageSize      int
 */

// ──────────────────────────────────────────────
//  Helpers — définis ici, réutilisés par _results.php (function_exists guard)
// ──────────────────────────────────────────────
function searchCoverSrc(?string $url): string
{
    if (!$url) return '';
    if (str_starts_with($url, '/') || str_starts_with($url, 'http')) return $url;
    return '/storage/images/igdb/' . $url;
}

function searchFmtDate(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

$q = htmlspecialchars($filters['q'] ?? '');
?>

<?php /* ═══════════════════════════════════════════════════════════ HERO SEARCH */ ?>
<section class="search-hero">
    <div class="container">
        <form class="search-hero__form" id="search-hero-form" method="get" action="/search"
              role="search" autocomplete="off">
            <div class="search-input-wrap">
                <svg class="search-input-wrap__icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                </svg>
                <input id="search-main-input"
                       class="search-input-wrap__input"
                       type="search"
                       name="q"
                       value="<?= $q ?>"
                       placeholder="Titre du jeu"
                       aria-label="Recherche de jeux"
                       spellcheck="false">
                <button class="search-input-wrap__clear" type="button" id="search-clear-btn"
                        aria-label="Effacer la recherche"
                        style="<?= $q ? '' : 'display:none' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
                <ul class="search-suggestions" id="search-suggestions" role="listbox" aria-label="Suggestions" hidden></ul>
            </div>

            <?php /* Conserver les filtres sidebar dans l'URL lors d'une nouvelle recherche */ ?>
            <?php foreach (['platform', 'genre', 'year_from', 'year_to', 'rating_min', 'sort'] as $k): ?>
                <?php if (!empty($filters[$k])): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars((string)$filters[$k]) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
        </form>

    </div>
</section>

<?php /* ═══════════════════════════════════════════════════════════ LAYOUT */ ?>
<div class="container">
<div class="search-layout">

    <?php /* ─────────────────────────────────────── SIDEBAR FILTRES */ ?>
    <aside class="search-sidebar" id="search-sidebar" aria-label="Filtres">
        <form class="search-sidebar__form" method="get" action="/search" id="filters-form">
            <?php if (!empty($filters['q'])): ?>
                <input type="hidden" name="q" value="<?= $q ?>">
            <?php endif; ?>

            <div class="search-sidebar__header">
                <span class="search-sidebar__title">Filtres</span>
                <div style="display:flex;align-items:center;gap:0.6rem;">
                    <?php if (!empty($activeFilters)): ?>
                        <a href="/search<?= !empty($filters['q']) ? '?q=' . rawurlencode($filters['q']) : '' ?>"
                           class="search-sidebar__reset">Réinitialiser</a>
                    <?php endif; ?>
                    <button class="search-sidebar__close" id="search-sidebar-close"
                            type="button" aria-label="Fermer les filtres">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                            <path d="M18 6L6 18M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="search-sidebar__group">
                <label class="search-sidebar__label" for="f-platform">Plateforme</label>
                <select class="search-sidebar__select" name="platform" id="f-platform">
                    <option value="">Toutes les plateformes</option>
                    <?php foreach ($filterOptions['platforms'] ?? [] as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"
                            <?= (int)($filters['platform'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['abbreviation'] ?: $p['name']) ?>
                            <?php if (!empty($p['generation'])): ?>(Gen.<?= (int)$p['generation'] ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="search-sidebar__group">
                <label class="search-sidebar__label" for="f-genre">Genre</label>
                <select class="search-sidebar__select" name="genre" id="f-genre">
                    <option value="">Tous les genres</option>
                    <?php foreach ($filterOptions['genres'] ?? [] as $g): ?>
                        <option value="<?= htmlspecialchars($g) ?>"
                            <?= ($filters['genre'] ?? '') === $g ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="search-sidebar__group">
                <label class="search-sidebar__label">Année de sortie</label>
                <div class="search-sidebar__range">
                    <input class="search-sidebar__input" type="number" name="year_from"
                           placeholder="De" min="1950" max="<?= date('Y') + 3 ?>"
                           value="<?= htmlspecialchars($filters['year_from'] ?? '') ?>">
                    <span class="search-sidebar__range-sep">–</span>
                    <input class="search-sidebar__input" type="number" name="year_to"
                           placeholder="À" min="1950" max="<?= date('Y') + 3 ?>"
                           value="<?= htmlspecialchars($filters['year_to'] ?? '') ?>">
                </div>
            </div>

            <div class="search-sidebar__group">
                <label class="search-sidebar__label" for="f-rating">
                    Note minimale
                    <span class="search-sidebar__rating-val" id="rating-val-display">
                        <?= (int)($filters['rating_min'] ?? 0) > 0
                            ? (int)$filters['rating_min'] . '/100'
                            : 'Toutes' ?>
                    </span>
                </label>
                <input class="search-sidebar__range-input" type="range"
                       name="rating_min" id="f-rating"
                       min="0" max="100" step="5"
                       value="<?= (int)($filters['rating_min'] ?? 0) ?>">
            </div>

            <button class="search-sidebar__apply btn btn--primary" type="submit">
                Appliquer les filtres
            </button>
        </form>
    </aside>

    <?php /* ─────────────────────────────────────── ZONE RÉSULTATS (remplacée par AJAX) */ ?>
    <section class="search-content">
        <div id="search-results-area">
            <?php require __DIR__ . '/_results.php'; ?>
        </div>
    </section>

</div><!-- /.search-layout -->
</div><!-- /.container -->

<?php /* Bouton FAB mobile filtres */ ?>
<button class="search-filters-fab" id="search-filters-fab" aria-label="Ouvrir les filtres">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
    </svg>
    Filtres
    <?php if (!empty($activeFilters)): ?>
        <span class="search-filters-fab__badge"><?= count($activeFilters) ?></span>
    <?php endif; ?>
</button>

<?php /* ═══════════════════════════════════════════════════════════ POPUP AJOUT */ ?>
<div class="game-modal" id="game-modal" role="dialog" aria-modal="true"
     aria-labelledby="modal-title" hidden>
    <div class="game-modal__backdrop" data-action="close-popup"></div>
    <div class="game-modal__panel">

        <button class="game-modal__close" data-action="close-popup" aria-label="Fermer">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
        </button>

        <div class="game-modal__loading" id="modal-loading" aria-live="polite">
            <div class="modal-spinner" aria-label="Chargement…"></div>
        </div>

        <div class="game-modal__content" id="modal-content" hidden>

            <div class="game-modal__cover-wrap">
                <img class="game-modal__cover" id="modal-cover" src="" alt="">
            </div>

            <div class="game-modal__info">
                <h2 class="game-modal__title" id="modal-title"></h2>
                <p class="game-modal__meta" id="modal-meta"></p>
                <p class="game-modal__synopsis" id="modal-synopsis"></p>

                <form class="add-form" id="add-form" novalidate>
                    <input type="hidden" id="add-game-id" name="game_id" value="">

                    <div class="add-form__field">
                        <label class="add-form__label" for="add-platform">Plateforme *</label>
                        <select class="add-form__select" id="add-platform" name="platform_id" required>
                            <option value="">Choisir une plateforme…</option>
                        </select>
                    </div>

                    <div class="add-form__field">
                        <label class="add-form__label" for="add-region">Région</label>
                        <select class="add-form__select" id="add-region" name="region">
                            <option value="">Toutes régions</option>
                            <option value="PAL">PAL (Europe)</option>
                            <option value="NTSC-U">NTSC-U (Amérique)</option>
                            <option value="NTSC-J">NTSC-J (Japon)</option>
                            <option value="Other">Autre</option>
                        </select>
                    </div>

                    <div class="add-form__field">
                        <label class="add-form__label">Support</label>
                        <div class="add-form__radio-group">
                            <label class="add-form__radio">
                                <input type="radio" name="media_type" value="physical" checked>
                                <span>Physique</span>
                            </label>
                            <label class="add-form__radio">
                                <input type="radio" name="media_type" value="digital">
                                <span>Digital</span>
                            </label>
                        </div>
                    </div>

                    <div class="add-form__duplicate-warn" id="add-duplicate-warn" hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 9v4M12 17h.01"/>
                            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        </svg>
                        Ce jeu est déjà dans votre sélection avec cette configuration.
                    </div>

                    <div class="add-form__actions">
                        <button class="btn btn--secondary" type="button" data-action="close-popup">
                            Annuler
                        </button>
                        <button class="btn btn--primary" type="submit" id="add-submit-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            Ajouter à ma sélection
                        </button>
                    </div>
                </form>

                <div class="add-form__success" id="add-success" hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>
                    </svg>
                    <strong id="add-success-title"></strong> ajouté à votre sélection !
                    <a href="/select" class="btn btn--ghost btn--sm">Voir la sélection</a>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require __DIR__ . '/../partials/selection-bar.php'; ?>
