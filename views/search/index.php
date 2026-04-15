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

use App\Data\GenreTranslations;

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
                       placeholder="Taper le nom du jeu, minimum 3 caractères"
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
            <?php if (!empty($filters['platforms']) && is_array($filters['platforms'])): ?>
                <?php foreach ($filters['platforms'] as $pid): ?>
                    <?php if (is_numeric($pid) && (int)$pid > 0): ?>
                        <input type="hidden" name="platform[]" value="<?= (int)$pid ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($filters['genres']) && is_array($filters['genres'])): ?>
                <?php foreach ($filters['genres'] as $g): ?>
                    <?php $g = trim((string)$g); if ($g !== ''): ?>
                        <input type="hidden" name="genre[]" value="<?= htmlspecialchars($g) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php foreach (['rating_min', 'sort'] as $k): ?>
                <?php if ($k === 'sort' && (($filters['sort'] ?? '') === 'all')): ?>
                    <?php continue; ?>
                <?php endif; ?>
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
                <div class="search-tag-picker" id="platform-picker" data-kind="platform">
                    <div class="search-tag-picker__tags" id="platform-tags">
                        <?php
                            $selectedPlatforms = [];
                            $pGet = $_GET['platform'] ?? [];
                            if (is_string($pGet) && $pGet !== '') $pGet = [$pGet];
                            if (is_numeric($pGet)) $pGet = [(string) $pGet];
                            if (is_array($pGet)) {
                                foreach ($pGet as $pv) {
                                    if (is_numeric($pv)) $selectedPlatforms[] = (int) $pv;
                                }
                            }
                            $selectedPlatforms = array_values(array_unique(array_filter($selectedPlatforms, fn($x) => $x > 0)));

                            $platformLabelMap = [];
                            foreach (($filterOptions['platforms'] ?? []) as $p) {
                                $id = (int) ($p['id'] ?? 0);
                                if ($id <= 0) continue;
                                $label = trim((string) ($p['abbreviation'] ?: $p['name']));
                                if (!empty($p['generation'])) {
                                    $label .= ' (Gen.' . (int) $p['generation'] . ')';
                                }
                                $platformLabelMap[$id] = $label;
                            }
                        ?>
                        <?php foreach ($selectedPlatforms as $pid): ?>
                            <span class="search-tag-picker__tag" data-id="<?= (int)$pid ?>">
                                <?= htmlspecialchars($platformLabelMap[$pid] ?? ('#' . $pid)) ?>
                                <button type="button" class="search-tag-picker__remove" aria-label="Retirer cette plateforme" data-remove-tag>&times;</button>
                                <input type="hidden" name="platform[]" value="<?= (int)$pid ?>">
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <input class="search-tag-picker__input"
                           id="f-platform"
                           type="text"
                           placeholder="Ajouter une plateforme…"
                           autocomplete="off"
                           spellcheck="false"
                           aria-label="Ajouter une plateforme">
                    <ul class="search-tag-picker__suggestions" id="platform-suggestions" role="listbox" hidden></ul>
                </div>
            </div>

            <div class="search-sidebar__group" id="genre-filter-group" data-conditional-genre>
                <label class="search-sidebar__label" for="f-genre">Genre</label>
                <div class="search-sidebar__hint" id="genre-filter-hint" hidden>
                    Choisissez d’abord une plateforme ou tapez au moins 3 caractères dans la recherche.
                </div>
                <div class="search-tag-picker" id="genre-picker" data-kind="genre">
                    <div class="search-tag-picker__tags" id="genre-tags">
                        <?php
                            $selectedGenres = [];
                            $gGet = $_GET['genre'] ?? [];
                            if (is_string($gGet) && $gGet !== '') $gGet = [$gGet];
                            if (is_array($gGet)) {
                                foreach ($gGet as $gv) {
                                    $gv = trim((string) $gv);
                                    if ($gv !== '') $selectedGenres[] = $gv;
                                }
                            }
                            $selectedGenres = array_values(array_unique($selectedGenres));
                        ?>
                        <?php foreach ($selectedGenres as $g): ?>
                            <span class="search-tag-picker__tag" data-id="<?= htmlspecialchars($g) ?>">
                                <?= htmlspecialchars(GenreTranslations::translate($g)) ?>
                                <button type="button" class="search-tag-picker__remove" aria-label="Retirer ce genre" data-remove-tag>&times;</button>
                                <input type="hidden" name="genre[]" value="<?= htmlspecialchars($g) ?>">
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <input class="search-tag-picker__input"
                           id="f-genre"
                           type="text"
                           placeholder="Ajouter un genre…"
                           autocomplete="off"
                           spellcheck="false"
                           aria-label="Ajouter un genre">
                    <ul class="search-tag-picker__suggestions" id="genre-suggestions" role="listbox" hidden></ul>
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

    <?php
        $pickerData = [
            'platforms' => array_map(static function ($p) {
                return [
                    'id' => (int) ($p['id'] ?? 0),
                    'name' => (string) ($p['name'] ?? ''),
                    'abbreviation' => (string) ($p['abbreviation'] ?? ''),
                    'generation' => isset($p['generation']) ? (int) $p['generation'] : null,
                ];
            }, $filterOptions['platforms'] ?? []),
            // Genres: on conserve l'ID/valeur IGDB (EN) pour les filtres,
            // et on fournit un label FR pour l'affichage.
            'genres' => array_values(array_map(static function ($g) {
                $id = (string) $g;
                return [
                    'id' => $id,
                    'label' => GenreTranslations::translate($id),
                    'search' => $id . ' ' . GenreTranslations::translate($id),
                ];
            }, $filterOptions['genres'] ?? [])),
        ];
    ?>
    <?php
        $pickerJson = json_encode($pickerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Empêche une fermeture prématurée du <script> si jamais une valeur contenait "</script".
        $pickerJson = str_replace('</', '<\/', (string) $pickerJson);
    ?>
    <script type="application/json" id="search-filter-data"><?= $pickerJson ?></script>

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
    <?php
        // Badge mobile: uniquement les *vrais* filtres de la sidebar (pas q/sort/page/cursor…)
        $fabCount = 0;

        $platformVals = $_GET['platform'] ?? $_GET['platform[]'] ?? [];
        if (is_string($platformVals) && $platformVals !== '') $platformVals = [$platformVals];
        if (is_numeric($platformVals)) $platformVals = [(string) $platformVals];
        if (is_array($platformVals)) {
            $platformVals = array_values(array_filter(array_map('intval', $platformVals), fn($x) => $x > 0));
        } else {
            $platformVals = [];
        }
        if (!empty($platformVals)) $fabCount += 1;

        $genreVals = $_GET['genre'] ?? $_GET['genre[]'] ?? [];
        if (is_string($genreVals) && trim($genreVals) !== '') $genreVals = [$genreVals];
        if (is_array($genreVals)) {
            $genreVals = array_values(array_filter(array_map(static fn($g) => trim((string)$g), $genreVals), fn($g) => $g !== ''));
        } else {
            $genreVals = [];
        }
        if (!empty($genreVals)) $fabCount += 1;

        $ratingMin = (int)($_GET['rating_min'] ?? 0);
        if ($ratingMin > 0) $fabCount += 1;
    ?>
    <?php if ($fabCount > 0): ?>
        <span class="search-filters-fab__badge"><?= (int) $fabCount ?></span>
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
