<?php
/**
 * Composant toggle de vue (Grille / Cartes / Liste).
 *
 * Usage dans une vue :
 *   <?php $viewMode = $_COOKIE['view_mode'] ?? 'grid'; ?>
 *   <?php require __DIR__ . '/../partials/view-toggle.php'; ?>
 *
 * Puis sur le conteneur de résultats :
 *   <div class="game-grid game-grid--<?= htmlspecialchars($viewMode) ?>" id="results-grid">
 */
$viewMode = $_COOKIE['view_mode'] ?? 'grid';
?>
<div class="view-toggle" role="group" aria-label="Mode d'affichage">

    <button class="view-toggle__btn<?= $viewMode === 'grid' ? ' is-active' : '' ?>"
            data-view="grid"
            title="Vue grille"
            aria-pressed="<?= $viewMode === 'grid' ? 'true' : 'false' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="1"/>
            <rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/>
            <rect x="14" y="14" width="7" height="7" rx="1"/>
        </svg>
    </button>

    <button class="view-toggle__btn<?= $viewMode === 'cards' ? ' is-active' : '' ?>"
            data-view="cards"
            title="Vue cartes"
            aria-pressed="<?= $viewMode === 'cards' ? 'true' : 'false' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <rect x="3" y="3" width="18" height="8" rx="1"/>
            <rect x="3" y="13" width="8" height="8" rx="1"/>
            <rect x="13" y="13" width="8" height="8" rx="1"/>
        </svg>
    </button>

    <button class="view-toggle__btn<?= $viewMode === 'list' ? ' is-active' : '' ?>"
            data-view="list"
            title="Vue liste"
            aria-pressed="<?= $viewMode === 'list' ? 'true' : 'false' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>
        </svg>
    </button>

</div>
