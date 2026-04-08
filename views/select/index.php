<?php
/**
 * Vue : /select — Liste de sélection
 *
 * Toute la logique métier est gérée côté client (select.js) :
 *   1. Lecture de localStorage (game_selection)
 *   2. Fetch /api/games/{id} pour chaque jeu
 *   3. Rendu dynamique des formulaires
 *   4. Soumission vers POST /api/collection/add
 *
 * PHP ne fait qu'injecter la configuration via window.SELECT_CONFIG (SelectController).
 */
?>

<div class="sel-page">
<div class="sel-container container">

    <?php /* ═══ EN-TÊTE ══════════════════════════════════════════════════ */ ?>
    <header class="sel-header">
        <a href="/search" class="sel-back" aria-label="Retour au catalogue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" aria-hidden="true">
                <path d="M19 12H5M12 5l-7 7 7 7"/>
            </svg>
            <span>Catalogue</span>
        </a>

        <div class="sel-header__text">
            <h1 class="sel-header__title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" aria-hidden="true" class="sel-header__icon">
                    <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="1"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
                Ma sélection
            </h1>
            <p class="sel-header__sub" id="sel-subtitle" aria-live="polite">Chargement…</p>
        </div>
    </header>

    <?php /* ═══ ÉTAT DE CHARGEMENT ════════════════════════════════════════ */ ?>
    <div class="sel-loading" id="sel-loading" role="status" aria-label="Chargement en cours">
        <div class="sel-loading__spinner" aria-hidden="true"></div>
        <p>Chargement de votre sélection…</p>
    </div>

    <?php /* ═══ ÉTAT VIDE ══════════════════════════════════════════════════ */ ?>
    <div class="sel-empty" id="sel-empty" hidden>
        <div class="sel-empty__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
                <rect x="9" y="3" width="6" height="4" rx="1"/>
                <path d="M12 12v4M12 8h.01"/>
            </svg>
        </div>
        <h2 class="sel-empty__title">Votre sélection est vide</h2>
        <p class="sel-empty__desc">
            Parcourez le catalogue et cliquez sur <strong>Ajouter à ma sélection</strong>
            pour retrouver vos jeux ici.
        </p>
        <a href="/search" class="btn btn--primary btn--lg-inline">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" aria-hidden="true" style="width:16px;height:16px">
                <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
            </svg>
            Parcourir le catalogue
        </a>
    </div>

    <?php /* ═══ LISTE DES JEUX (rendue par JS) ══════════════════════════ */ ?>
    <div class="sel-games" id="sel-games" hidden>
        <ul class="sel-list" id="sel-list" aria-label="Jeux à ajouter à la collection"></ul>
    </div>

    <?php /* ═══ ERREUR GÉNÉRALE ════════════════════════════════════════════ */ ?>
    <div class="sel-result" id="sel-result" hidden aria-live="polite"></div>

</div><!-- /.sel-container -->

<?php /* ═══ BARRE DE SOUMISSION STICKY ════════════════════════════════ */ ?>
<div class="sel-bar" id="sel-bar" hidden role="region" aria-label="Soumettre la sélection">
    <div class="sel-bar__inner">
        <div class="sel-bar__info">
            <span class="sel-bar__count" id="sel-bar-count">0 jeu · 0 exemplaire</span>
            <span class="sel-bar__hint">Vérification des doublons avant l'ajout</span>
        </div>
        <div class="sel-bar__actions">
            <button type="button" class="sel-bar__clear btn btn--ghost btn--sm" id="sel-clear-btn">
                Vider
            </button>
            <button type="button" class="btn btn--primary" id="sel-submit-btn" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round" aria-hidden="true" style="width:16px;height:16px">
                    <path d="M20 6L9 17l-5-5"/>
                </svg>
                Ajouter à ma collection
            </button>
        </div>
    </div>
</div>

</div><!-- /.sel-page -->
