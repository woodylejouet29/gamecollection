<?php
/**
 * Composant filtres universels.
 *
 * Variables attendues (injectées par le contrôleur via la vue parente) :
 *   $filterOptions = [
 *       'platforms' => [['id'=>1,'name'=>'PS5'], ...],  // optionnel
 *       'genres'    => [['id'=>1,'name'=>'RPG'],  ...],  // optionnel
 *   ];
 *   $activeFilters = $_GET  (ou tableau personnalisé)
 *
 * Les filtres soumettent en GET pour des URLs partageables.
 * Le JS (app.js) gère l'ouverture/fermeture du panel.
 */

use App\Data\GenreTranslations;

$filterOptions = $filterOptions ?? [];
$activeFilters = $activeFilters ?? $_GET;

// Retirer "page" et "sort" des filtres actifs pour ne pas polluer les tags
$displayFilters = array_diff_key($activeFilters, ['page' => '', 'sort' => '']);

// Ne compter/afficher que les valeurs réellement actives
$displayFilters = array_filter($displayFilters, static function ($v): bool {
    if ($v === null || $v === '' || $v === 0 || $v === '0') {
        return false;
    }
    if (is_array($v)) {
        $vals = array_values(array_filter($v, static fn($x) => !($x === null || $x === '' || $x === 0 || $x === '0')));
        return count($vals) > 0;
    }
    return true;
});

// Libellés lisibles pour les tags actifs
$filterLabels = [
    'rating_min' => 'Note min',
    'platform'   => 'Plateforme',
    'genre'      => 'Genre',
    'region'     => 'Région',
    'type'       => 'Type',
    'q'          => 'Recherche',
];

$hasActiveFilters = !empty($displayFilters);
$activeCount      = count($displayFilters);

$typeOptions = [
    ''   => 'Tous les types',
    '0'  => 'Jeu principal',
    '2'  => 'Extension',
    '3'  => 'Bundle',
    '4'  => 'Extension standalone',
    '8'  => 'Remake',
    '9'  => 'Remaster',
    '10' => 'Version étendue',
    '11' => 'Portage',
];

$regionOptions = [
    ''       => 'Toutes les régions',
    'PAL'    => 'PAL (Europe)',
    'NTSC-U' => 'NTSC-U (Amér. Nord)',
    'NTSC-J' => 'NTSC-J (Japon)',
    'NTSC-K' => 'NTSC-K (Corée)',
    'other'  => 'Autres',
];
?>

<div class="filters-bar">

    <button class="filters-toggle<?= $hasActiveFilters ? ' is-active' : '' ?>"
            id="filters-toggle-btn"
            aria-controls="filters-panel"
            aria-expanded="false"
            type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
        </svg>
        Filtres
        <?php if ($activeCount > 0): ?>
            <span class="badge"><?= $activeCount ?></span>
        <?php endif; ?>
    </button>

    <?php /* Tags des filtres actifs */ if ($hasActiveFilters): ?>
        <div class="filters-active" id="filters-active-tags">
            <?php foreach ($displayFilters as $key => $value):
                if ($value === '' || $value === null) continue;
                $label = ($filterLabels[$key] ?? $key) . ' : ' . htmlspecialchars((string) $value);
            ?>
            <span class="filter-tag">
                <?= $label ?>
                <a href="?<?= http_build_query(array_merge($activeFilters, [$key => '', 'page' => '1'])) ?>"
                   class="filter-tag__remove"
                   aria-label="Retirer ce filtre">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12"/>
                    </svg>
                </a>
            </span>
            <?php endforeach; ?>
            <a href="?<?= http_build_query(array_diff_key($activeFilters, $displayFilters)) ?>"
               class="filter-tag" style="opacity:.6">
                Tout effacer ×
            </a>
        </div>
    <?php endif; ?>

    <?php if (isset($totalResults)): ?>
    <span class="results-count">
        <strong><?= number_format($totalResults) ?></strong> résultat<?= $totalResults !== 1 ? 's' : '' ?>
    </span>
    <?php endif; ?>

</div>

<?php /* Panel filtres */ ?>
<div class="filters-panel" id="filters-panel" aria-hidden="true">
    <form method="get" action="" id="filters-form">

        <div class="filters-panel__grid">

            <?php /* Recherche textuelle */ ?>
            <div class="form-group">
                <label class="form-label" for="f-q">Titre</label>
                <input type="search"
                       class="form-input"
                       id="f-q"
                       name="q"
                       placeholder="Nom du jeu…"
                       value="<?= htmlspecialchars($activeFilters['q'] ?? '') ?>">
            </div>

            <?php /* Note */ ?>
            <div class="form-group">
                <label class="form-label" for="f-rating">Note minimum IGDB</label>
                <div class="form-row">
                    <input type="number"
                           class="form-input"
                           id="f-rating"
                           name="rating_min"
                           placeholder="0"
                           min="0"
                           max="100"
                           value="<?= htmlspecialchars($activeFilters['rating_min'] ?? '') ?>">
                </div>
            </div>

            <?php /* Plateformes */ if (!empty($filterOptions['platforms'])): ?>
            <div class="form-group">
                <label class="form-label" for="f-platform">Plateforme</label>
                <select class="form-input" id="f-platform" name="platform">
                    <option value="">Toutes les plateformes</option>
                    <?php foreach ($filterOptions['platforms'] as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"
                        <?= ($activeFilters['platform'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php /* Genres */ if (!empty($filterOptions['genres'])): ?>
            <div class="form-group">
                <label class="form-label" for="f-genre">Genre</label>
                <select class="form-input" id="f-genre" name="genre">
                    <option value="">Tous les genres</option>
                    <?php foreach ($filterOptions['genres'] as $g): ?>
                    <?php $name = is_array($g) ? (string)($g['name'] ?? '') : (string)$g; ?>
                    <option value="<?= htmlspecialchars($name) ?>"
                        <?= ($activeFilters['genre'] ?? '') === $name ? 'selected' : '' ?>>
                        <?= htmlspecialchars(GenreTranslations::translate($name)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php /* Région */ ?>
            <div class="form-group">
                <label class="form-label" for="f-region">Région</label>
                <select class="form-input" id="f-region" name="region">
                    <?php foreach ($regionOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"
                        <?= ($activeFilters['region'] ?? '') === $val ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php /* Type de jeu */ ?>
            <div class="form-group">
                <label class="form-label" for="f-type">Type</label>
                <select class="form-input" id="f-type" name="type">
                    <?php foreach ($typeOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"
                        <?= ($activeFilters['type'] ?? '') === $val ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <div class="filters-panel__actions">
            <a href="?" class="btn btn--ghost btn--sm">Réinitialiser</a>
            <button type="submit" class="btn btn--primary btn--sm">Appliquer les filtres</button>
        </div>

    </form>
</div>
