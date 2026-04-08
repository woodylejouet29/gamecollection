<?php
/**
 * Barre sticky de sélection — affichée en bas de page quand ≥1 jeu est sélectionné.
 *
 * Fonctionne entièrement via JS (app.js : SelectionBar).
 * Les cases à cocher des cartes doivent avoir data-game-id et class="game-select-cb".
 *
 * Usage : <?php require __DIR__ . '/../partials/selection-bar.php'; ?>
 * (inclure juste avant </main> ou dans le layout)
 */
?>
<div class="selection-bar" id="selection-bar" role="region" aria-label="Sélection en cours" aria-hidden="true" hidden>
    <div class="selection-bar__inner">

        <p class="selection-bar__count">
            <strong id="selection-count">0</strong>
            <span id="selection-label"> jeu sélectionné</span>
        </p>

        <div class="selection-bar__actions">
            <button class="selection-bar__clear" id="selection-clear" type="button">
                Tout désélectionner
            </button>
            <a href="/select" class="btn btn--primary btn--sm" id="selection-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width:15px;height:15px">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
                Voir ma sélection
            </a>
        </div>

    </div>
</div>
