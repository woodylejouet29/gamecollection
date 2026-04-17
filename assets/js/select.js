/**
 * select.js — Page /select : liste de sélection
 *
 * Modules :
 *   SelectPage   : orchestrateur principal (init, render, submit)
 *   Templates    : génération HTML des cartes jeux et sections copies
 *   FormState    : gestion des états visuels (show/hide champs conditionnels)
 *   Validator    : validation côté client avant envoi
 *   Submission   : collecte des données et POST /api/collection/add
 */

'use strict';

const CFG = window.SELECT_CONFIG || {
    gameUrl:       '/api/games/',
    addUrl:        '/api/collection/add',
    checkDupUrl:   '/api/select/check-duplicate',
    collectionUrl: '/collection',
    searchUrl:     '/search',
};

const STORAGE_KEY = 'game_selection';

// ──────────────────────────────────────────────
//  Textes localisés
// ──────────────────────────────────────────────
const LABELS = {
    statuses: {
        owned:           'Possédé',
        playing:         'En cours',
        completed:       'Terminé',
        hundred_percent: '100% complété',
        abandoned:       'Abandonné',
    },
    conditions: {
        mint:       'Neuf - Sous blister',
        near_mint:  'Neuf',
        very_good:  'Très bon état',
        good:       'Bon état',
        acceptable: 'État acceptable',
        poor:       'Mauvais état',
        damaged:    'Endommagé',
        incomplete: 'Incomplet',
    },
    regions: {
        PAL:    'PAL',
        'NTSC-U': 'NTSC-U',
        'NTSC-J': 'NTSC-J',
        'NTSC-K': 'NTSC-K',
        other:  'Autre',
        '':     'Toutes régions',
    },
};

// ──────────────────────────────────────────────
//  Helpers
// ──────────────────────────────────────────────
function storageLoad() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); }
    catch { return []; }
}

function storageSave(items) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    window.dispatchEvent(new CustomEvent('selection:updated', { detail: { count: items.length } }));
}

function coverSrc(url) {
    if (!url) return '';
    if (url.startsWith('//')) return 'https:' + url;
    if (url.startsWith('/') || url.startsWith('http')) return url;
    // En mode "IGDB direct", on ne sert plus de fichiers locaux.
    return '';
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
}

/** Désactive la carte si l'ajout collection est interdit (J-7 sortie). */
function applyCollectionGate(card, message) {
    if (!card || !message) return;
    card.dataset.collectionBlocked = '1';
    const body = card.querySelector('.sel-game__body');
    if (body) {
        const note = document.createElement('div');
        note.className = 'sel-game__gate-banner';
        note.setAttribute('role', 'alert');
        note.textContent = message;
        body.insertBefore(note, body.firstChild);
    }
    card.querySelectorAll('input, select, textarea, button').forEach(el => {
        if (el.classList.contains('sel-game__remove')) return;
        el.disabled = true;
    });
}

// ──────────────────────────────────────────────
//  Templates HTML
// ──────────────────────────────────────────────
const Templates = {

    /** Génère une option <select> */
    option: (value, label, selected = false) =>
        `<option value="${escHtml(String(value))}"${selected ? ' selected' : ''}>${escHtml(label)}</option>`,

    /** Génère une pill de notation 1-10 */
    ratingPills: (copyId) => {
        let html = '<div class="sel-rating-pills" role="group" aria-label="Note sur 10">';
        for (let i = 1; i <= 10; i++) {
            html += `<button type="button" class="sel-rating-pill" data-value="${i}" data-copy="${copyId}"
                     aria-label="Note ${i}/10" aria-pressed="false">${i}</button>`;
        }
        html += `<input type="hidden" name="rating" class="copy-rating-input" value="">`;
        html += `<span class="sel-rating-val" aria-live="polite"></span>`;
        html += '</div>';
        return html;
    },

    /** Génère une section d'exemplaire (copie) */
    copySection: (copyIdx, gameIdx, isPhysical) => {
        const cid = `g${gameIdx}_c${copyIdx}`;
        const statusOptions = Object.entries(LABELS.statuses)
            .map(([v, l]) => Templates.option(v, l, v === 'owned'))
            .join('');
        const condOptions = Object.entries(LABELS.conditions)
            .map(([v, l]) => Templates.option(v, l, v === 'very_good'))
            .join('');

        return `
        <li class="sel-copy" data-copy-idx="${copyIdx}" data-game-idx="${gameIdx}">
            <div class="sel-copy__header">
                <span class="sel-copy__label">
                    <span class="sel-copy__num">${copyIdx + 1}</span>
                    Exemplaire ${copyIdx + 1}
                </span>
                <button type="button" class="sel-copy__del" data-action="del-copy"
                        ${copyIdx === 0 ? 'hidden' : ''}
                        aria-label="Supprimer l'exemplaire ${copyIdx + 1}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                    Supprimer
                </button>
            </div>

            <div class="sel-copy__fields">

                <!-- Statut + Rang -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="${cid}-status">
                            Statut <span class="required">*</span>
                        </label>
                        <select class="form-input copy-status" id="${cid}-status"
                                name="status" required aria-required="true">
                            ${statusOptions}
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="${cid}-rank">
                            Position dans ma liste
                            <span class="required">*</span>
                        </label>
                        <input class="form-input" type="number" id="${cid}-rank"
                               name="rank_position" value="0" min="0" max="9999"
                               required aria-required="true"
                               placeholder="0 = non classé">
                    </div>
                </div>

                <!-- Date + Prix -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="${cid}-date">
                            Date d'acquisition <span class="optional">(optionnel)</span>
                        </label>
                        <input class="form-input" type="date" id="${cid}-date"
                               name="acquired_at">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="${cid}-price">
                            Prix payé (€) <span class="optional">(optionnel)</span>
                        </label>
                        <input class="form-input" type="number" id="${cid}-price"
                               name="price_paid" min="0" step="0.01"
                               placeholder="ex. 29.99">
                    </div>
                </div>

                <!-- Durée de complétion (masqué par défaut) -->
                <div class="form-group copy-playtime" hidden aria-hidden="true">
                    <label class="form-label">Durée de complétion</label>
                    <div class="sel-playtime-row">
                        <input class="form-input" type="number" name="play_time_hours"
                               min="0" max="9999" placeholder="0" aria-label="Heures">
                        <span>h</span>
                        <input class="form-input" type="number" name="play_time_minutes"
                               min="0" max="59" placeholder="00" aria-label="Minutes">
                        <span>min</span>
                    </div>
                </div>

                <!-- Note + Avis (masqués par défaut) -->
                <div class="copy-review" hidden aria-hidden="true">
                    <div class="form-group">
                        <label class="form-label">Note <span class="optional">(optionnel)</span></label>
                        ${Templates.ratingPills(cid)}
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="${cid}-review">
                            Avis <span class="optional">(optionnel, ≥ 100 car.)</span>
                        </label>
                        <textarea class="form-input copy-review-text" id="${cid}-review"
                                  name="review_body" rows="4"
                                  placeholder="Partagez votre ressenti sur ce jeu…"
                                  minlength="100"
                                  aria-describedby="${cid}-review-count"></textarea>
                        <span class="sel-char-count" id="${cid}-review-count"
                              aria-live="polite">0 / 100 caractères minimum</span>
                    </div>
                </div>

                <!-- Champs physiques (masqués si dématérialisé) -->
                <div class="sel-physical-fields copy-physical${isPhysical ? '' : ' is-hidden'}"
                     aria-hidden="${isPhysical ? 'false' : 'true'}">

                    <div class="form-group">
                        <label class="form-label" for="${cid}-cond">
                            État du jeu <span class="required">*</span>
                        </label>
                        <select class="form-input copy-condition" id="${cid}-cond"
                                name="physical_condition"
                                ${isPhysical ? 'required aria-required="true"' : ''}>
                            ${condOptions}
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="${cid}-condnote">
                            Commentaire état
                            <span class="optional">(optionnel, max 500 car.)</span>
                        </label>
                        <textarea class="form-input copy-condition-note" id="${cid}-condnote"
                                  name="condition_note" rows="2" maxlength="500"
                                  placeholder="Détails sur l'état du jeu…"
                                  aria-describedby="${cid}-condnote-count"></textarea>
                        <span class="sel-char-count" id="${cid}-condnote-count"
                              aria-live="polite">0 / 500</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                Boîte d'origine <span class="required">*</span>
                            </label>
                            <div class="sel-radio-group" role="radiogroup"
                                 aria-label="Boîte d'origine">
                                <label class="sel-radio-label">
                                    <input type="radio" name="${cid}-has_box" value="1"
                                           ${isPhysical ? 'required' : ''}>
                                    <span class="sel-radio-btn">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="width:12px;height:12px"><path d="M20 6L9 17l-5-5"/></svg>
                                        Oui
                                    </span>
                                </label>
                                <label class="sel-radio-label">
                                    <input type="radio" name="${cid}-has_box" value="0"
                                           ${isPhysical ? 'required' : ''}>
                                    <span class="sel-radio-btn">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:12px;height:12px"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                        Non
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Manuel <span class="required">*</span>
                            </label>
                            <div class="sel-radio-group" role="radiogroup" aria-label="Manuel">
                                <label class="sel-radio-label">
                                    <input type="radio" name="${cid}-has_manual" value="1"
                                           ${isPhysical ? 'required' : ''}>
                                    <span class="sel-radio-btn">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="width:12px;height:12px"><path d="M20 6L9 17l-5-5"/></svg>
                                        Présent
                                    </span>
                                </label>
                                <label class="sel-radio-label">
                                    <input type="radio" name="${cid}-has_manual" value="0"
                                           ${isPhysical ? 'required' : ''}>
                                    <span class="sel-radio-btn">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="width:12px;height:12px"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                        Absent
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Photos (jusqu'à 3 URLs) -->
                    <div class="form-group">
                        <label class="form-label">
                            Photos <span class="optional">(optionnel, URL — max 3)</span>
                        </label>
                        <div class="sel-photos-list" data-photos>
                            <div class="sel-photo-input-row">
                                <input class="form-input" type="url" name="photo_url[]"
                                       placeholder="https://…">
                            </div>
                        </div>
                        <input class="sel-photo-file" type="file" accept="image/*" hidden>
                        <button type="button" class="sel-photo-upload-btn" data-action="upload-photo"
                                aria-label="Uploader une image">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <path d="M7 10l5-5 5 5"/>
                                <path d="M12 5v12"/>
                            </svg>
                            Uploader une image
                        </button>
                        <button type="button" class="sel-photo-add-btn" data-action="add-photo"
                                aria-label="Ajouter une photo">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            Ajouter une photo
                        </button>
                    </div>
                </div>

            </div>
        </li>`;
    },

    /** Génère une carte complète pour un jeu */
    gameCard: (item, gameData, gameIdx) => {
        const isPhysical = item.mediaType !== 'digital';
        const cover = coverSrc(gameData.cover_url || item.gameCover || '');
        const regionLabel = LABELS.regions[item.region] || item.region || 'Toutes régions';
        const typeLabel   = isPhysical ? 'Physique' : 'Dématérialisé';
        const typeBadgeClass = isPhysical ? 'physical' : 'digital';

        // Versions
        const versions = Array.isArray(gameData.versions) ? gameData.versions : [];
        const hasVersions = versions.length > 0;
        const versionOpts = versions.map(v =>
            Templates.option(v.id, v.name || v.title || `Édition #${v.id}`)
        ).join('');

        return `
        <li class="sel-game" data-game-idx="${gameIdx}"
            data-game-id="${item.gameId}"
            data-platform-id="${item.platformId}"
            data-region="${escHtml(item.region || '')}"
            data-game-type="${isPhysical ? 'physical' : 'digital'}">

            <div class="sel-game__header">
                <div class="sel-game__cover-wrap" aria-hidden="true">
                    ${cover
                        ? `<img class="sel-game__cover" src="${escHtml(cover)}"
                               alt="${escHtml(gameData.title || item.gameTitle)}"
                               loading="lazy"
                               onerror="this.parentElement.innerHTML=Templates._coverPlaceholder()">`
                        : Templates._coverPlaceholder()
                    }
                </div>

                <div class="sel-game__info">
                    <h2 class="sel-game__title">
                        ${escHtml(gameData.title || item.gameTitle || 'Jeu inconnu')}
                    </h2>
                    <div class="sel-game__meta">
                        <span class="sel-badge sel-badge--platform">
                            ${escHtml(item.platformName || `Plateforme #${item.platformId}`)}
                        </span>
                        <span class="sel-badge sel-badge--region">${escHtml(regionLabel)}</span>
                        <span class="sel-badge sel-badge--${typeBadgeClass}">${typeLabel}</span>
                    </div>
                </div>

                <button type="button" class="sel-game__remove" data-action="remove-game"
                        title="Retirer ${escHtml(gameData.title || item.gameTitle)} de la sélection"
                        aria-label="Retirer ${escHtml(gameData.title || item.gameTitle)} de la sélection">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="sel-game__body">

                <!-- Version -->
                <div class="sel-version${hasVersions ? '' : ' sel-version--hidden'}">
                    <div class="form-group">
                        <label class="form-label" for="g${gameIdx}-version">
                            Édition <span class="optional">(optionnel)</span>
                        </label>
                        <select class="form-input sel-version-select" id="g${gameIdx}-version"
                                name="game_version_id">
                            <option value="">Édition standard</option>
                            ${versionOpts}
                        </select>
                    </div>
                </div>

                <!-- Type toggle -->
                <div class="form-group">
                    <label class="form-label">Support</label>
                    <div class="sel-type-toggle" role="group" aria-label="Type de support">
                        <button type="button" class="sel-type-btn${isPhysical ? ' is-active' : ''}"
                                data-type="physical" aria-pressed="${isPhysical}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 aria-hidden="true">
                                <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            Physique
                        </button>
                        <button type="button" class="sel-type-btn${!isPhysical ? ' is-active' : ''}"
                                data-type="digital" aria-pressed="${!isPhysical}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 aria-hidden="true">
                                <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>
                            </svg>
                            Dématérialisé
                        </button>
                    </div>
                </div>

                <!-- Exemplaires -->
                <ul class="sel-copies" data-copies-list aria-label="Exemplaires">
                    ${Templates.copySection(0, gameIdx, isPhysical)}
                </ul>

                <button type="button" class="sel-add-copy" data-action="add-copy"
                        aria-label="Ajouter un exemplaire">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         aria-hidden="true">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Ajouter un exemplaire
                </button>
            </div>
        </li>`;
    },

    _coverPlaceholder: () => `
        <div class="sel-game__cover-placeholder">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
                <path d="M21 15l-5-5L5 21"/>
            </svg>
        </div>`,
};

// Exposer Templates._coverPlaceholder pour le onerror inline
window.Templates = Templates;

// ──────────────────────────────────────────────
//  FormState — affichage conditionnel des champs
// ──────────────────────────────────────────────
const FormState = {

    /** Mise à jour de la visibilité des champs selon le statut */
    onStatusChange(copyEl, status) {
        const showReview   = status === 'completed' || status === 'hundred_percent';
        const showPlaytime = showReview;

        const playtime = copyEl.querySelector('.copy-playtime');
        const review   = copyEl.querySelector('.copy-review');

        if (playtime) {
            playtime.hidden = !showPlaytime;
            playtime.setAttribute('aria-hidden', String(!showPlaytime));
        }
        if (review) {
            review.hidden = !showReview;
            review.setAttribute('aria-hidden', String(!showReview));
        }
    },

    /** Mise à jour de la visibilité des champs physiques selon le type */
    onTypeChange(gameCard, isPhysical) {
        gameCard.dataset.gameType = isPhysical ? 'physical' : 'digital';

        gameCard.querySelectorAll('.copy-physical').forEach(el => {
            el.classList.toggle('is-hidden', !isPhysical);
            el.setAttribute('aria-hidden', String(!isPhysical));

            // required/optional dynamique
            el.querySelectorAll('.copy-condition, [name$="-has_box"], [name$="-has_manual"]')
              .forEach(input => {
                  if (isPhysical) {
                      input.setAttribute('required', '');
                      input.setAttribute('aria-required', 'true');
                  } else {
                      input.removeAttribute('required');
                      input.removeAttribute('aria-required');
                  }
              });
        });

        // Badge type
        gameCard.querySelectorAll('.sel-badge--physical, .sel-badge--digital').forEach(b => {
            b.textContent = isPhysical ? 'Physique' : 'Dématérialisé';
            b.className   = `sel-badge sel-badge--${isPhysical ? 'physical' : 'digital'}`;
        });
    },

    /** Synchronise les boutons toggle */
    syncTypeButtons(gameCard, isPhysical) {
        gameCard.querySelectorAll('.sel-type-btn').forEach(btn => {
            const active = btn.dataset.type === (isPhysical ? 'physical' : 'digital');
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', String(active));
        });
    },
};

// ──────────────────────────────────────────────
//  Validator
// ──────────────────────────────────────────────
const Validator = {

    /**
     * Valide tous les formulaires.
     * Retourne true si valide, false sinon (et affiche les erreurs natives du navigateur).
     */
    validateAll() {
        let valid = true;
        const list = document.getElementById('sel-list');
        if (!list) return true;

        list.querySelectorAll('.sel-copy').forEach(copy => {
            const gameCard  = copy.closest('.sel-game');
            const isPhys    = (gameCard?.dataset.gameType ?? 'physical') === 'physical';
            const status    = copy.querySelector('.copy-status')?.value || 'owned';
            const rankInput = copy.querySelector('[name="rank_position"]');

            // Rang obligatoire
            if (rankInput && rankInput.value === '') {
                rankInput.reportValidity();
                valid = false;
            }

            // Avis ≥ 100 car. si rempli
            if (status === 'completed' || status === 'hundred_percent') {
                const ta = copy.querySelector('.copy-review-text');
                if (ta && ta.value.trim() !== '' && ta.value.trim().length < 100) {
                    ta.setCustomValidity('L\'avis doit contenir au moins 100 caractères.');
                    ta.reportValidity();
                    valid = false;
                } else if (ta) {
                    ta.setCustomValidity('');
                }
            }

            // Champs physiques
            if (isPhys) {
                const boxGroup    = copy.querySelectorAll('[name$="-has_box"]');
                const manualGroup = copy.querySelectorAll('[name$="-has_manual"]');
                const boxChecked  = Array.from(boxGroup).some(r => r.checked);
                const manChecked  = Array.from(manualGroup).some(r => r.checked);

                if (!boxChecked && boxGroup.length) {
                    boxGroup[0].setCustomValidity('Veuillez indiquer si la boîte est présente.');
                    boxGroup[0].reportValidity();
                    valid = false;
                } else if (boxGroup.length) {
                    boxGroup.forEach(r => r.setCustomValidity(''));
                }

                if (!manChecked && manualGroup.length) {
                    manualGroup[0].setCustomValidity('Veuillez indiquer si le manuel est présent.');
                    manualGroup[0].reportValidity();
                    valid = false;
                } else if (manualGroup.length) {
                    manualGroup.forEach(r => r.setCustomValidity(''));
                }
            }
        });

        return valid;
    },
};

// ──────────────────────────────────────────────
//  Collecte des données du formulaire
// ──────────────────────────────────────────────
function collectFormData() {
    const list = document.getElementById('sel-list');
    if (!list) return [];

    const games = [];

    list.querySelectorAll('.sel-game').forEach(gameCard => {
        const gameId    = parseInt(gameCard.dataset.gameId, 10);
        const platId    = parseInt(gameCard.dataset.platformId, 10);
        const region    = gameCard.dataset.region || '';
        const gameType  = gameCard.dataset.gameType || 'physical';
        const versionEl = gameCard.querySelector('.sel-version-select');
        const versionId = versionEl?.value ? parseInt(versionEl.value, 10) : null;

        const copies = [];

        gameCard.querySelectorAll('.sel-copy').forEach(copyEl => {
            const status    = copyEl.querySelector('.copy-status')?.value || 'owned';
            const rank      = parseInt(copyEl.querySelector('[name="rank_position"]')?.value || '0', 10);
            const acqDate   = copyEl.querySelector('[name="acquired_at"]')?.value || '';
            const price     = copyEl.querySelector('[name="price_paid"]')?.value || '';
            const ptHours   = copyEl.querySelector('[name="play_time_hours"]')?.value || '';
            const ptMins    = copyEl.querySelector('[name="play_time_minutes"]')?.value || '';
            const ratingIn  = copyEl.querySelector('.copy-rating-input')?.value || '';
            const reviewTa  = copyEl.querySelector('.copy-review-text');
            const reviewVal = reviewTa ? reviewTa.value.trim() : '';

            // Physique uniquement
            const condSel   = copyEl.querySelector('.copy-condition');
            const condNote  = copyEl.querySelector('.copy-condition-note')?.value?.trim() || '';
            const boxRadios = copyEl.querySelectorAll('[name$="-has_box"]');
            const manRadios = copyEl.querySelectorAll('[name$="-has_manual"]');
            const hasBoxVal = Array.from(boxRadios).find(r => r.checked)?.value ?? null;
            const hasManVal = Array.from(manRadios).find(r => r.checked)?.value ?? null;

            // Photos
            const photoUrls = Array.from(copyEl.querySelectorAll('[name="photo_url[]"]'))
                .map(i => i.value.trim())
                .filter(Boolean)
                .slice(0, 3);

            const copy = {
                status,
                rank_position:      rank,
                acquired_at:        acqDate,
                price_paid:         price,
                play_time_hours:    ptHours,
                play_time_minutes:  ptMins,
                rating:             ratingIn !== '' ? parseInt(ratingIn, 10) : null,
                review_body:        reviewVal,
                physical_condition: gameType === 'physical' ? (condSel?.value || 'good') : null,
                condition_note:     gameType === 'physical' ? condNote : '',
                has_box:            gameType === 'physical' && hasBoxVal !== null ? (hasBoxVal === '1') : null,
                has_manual:         gameType === 'physical' && hasManVal !== null ? (hasManVal === '1') : null,
                photo_urls:         gameType === 'physical' ? photoUrls : [],
            };

            copies.push(copy);
        });

        games.push({ game_id: gameId, platform_id: platId, game_version_id: versionId, region, game_type: gameType, copies });
    });

    return games;
}

// ──────────────────────────────────────────────
//  Mise à jour du compteur de la barre sticky
// ──────────────────────────────────────────────
function updateBar() {
    const list    = document.getElementById('sel-list');
    const bar     = document.getElementById('sel-bar');
    const counter = document.getElementById('sel-bar-count');
    const submit  = document.getElementById('sel-submit-btn');

    if (!list || !bar) return;

    const gameCount = list.querySelectorAll('.sel-game').length;
    const copyCount = list.querySelectorAll('.sel-copy').length;
    const hasBlocked = list.querySelector('.sel-game[data-collection-blocked="1"]') !== null;

    bar.hidden = gameCount === 0;
    bar.classList.toggle('is-visible', gameCount > 0);
    if (submit) submit.disabled = gameCount === 0 || hasBlocked;

    if (counter) {
        const gLabel = gameCount <= 1 ? 'jeu' : 'jeux';
        const cLabel = copyCount <= 1 ? 'exemplaire' : 'exemplaires';
        counter.textContent = `${gameCount} ${gLabel} · ${copyCount} ${cLabel}`;
    }

    // Sous-titre header
    const sub = document.getElementById('sel-subtitle');
    if (sub) {
        if (gameCount === 0) {
            sub.textContent = 'Votre sélection est vide.';
        } else if (hasBlocked) {
            sub.textContent = 'Retirez les jeux non éligibles (sortie trop lointaine ou date inconnue) pour valider.';
        } else {
            const gTxt = gameCount === 1 ? '1 jeu' : `${gameCount} jeux`;
            const cTxt = copyCount === 1 ? '1 exemplaire' : `${copyCount} exemplaires`;
            sub.textContent = `${gTxt} · ${cTxt} à ajouter`;
        }
    }
}

// ──────────────────────────────────────────────
//  Rendu d'une carte jeu
// ──────────────────────────────────────────────
async function renderGameCard(item, gameIdx) {
    const list = document.getElementById('sel-list');
    if (!list) return;

    // Placeholder de chargement
    const placeholder = document.createElement('li');
    placeholder.className = 'sel-game sel-game--loading';
    placeholder.setAttribute('aria-label', `Chargement de ${item.gameTitle || 'jeu'}…`);
    placeholder.innerHTML = `
        <div class="sel-game__header">
            <div class="sel-game__cover-wrap">
                <div class="sel-game__cover-placeholder">
                    <div class="sel-loading__spinner" style="width:24px;height:24px;border-width:2px"></div>
                </div>
            </div>
            <div class="sel-game__info">
                <h2 class="sel-game__title">${escHtml(item.gameTitle || '…')}</h2>
                <div class="sel-game__meta">
                    <span class="sel-badge sel-badge--platform">${escHtml(item.platformName || '—')}</span>
                </div>
            </div>
        </div>`;
    list.appendChild(placeholder);

    try {
        const res      = await fetch(`${CFG.gameUrl}${item.gameId}`, { headers: { Accept: 'application/json' } });
        const raw      = res.ok ? await res.json() : null;
        const payload  = raw?.data ?? raw ?? { title: item.gameTitle };
        const gate     = typeof window.collectionReleaseGate !== 'undefined'
            ? window.collectionReleaseGate.check(payload.release_date ?? '')
            : { ok: true };

        const cardHtml = Templates.gameCard(item, payload, gameIdx);
        const tmp      = document.createElement('ul');
        tmp.innerHTML  = cardHtml;
        const card     = tmp.firstElementChild;

        list.replaceChild(card, placeholder);
        bindCardEvents(card);
        if (!gate.ok) applyCollectionGate(card, gate.message);
    } catch {
        placeholder.className = 'sel-game sel-game--error';
        placeholder.innerHTML = `
            <div class="sel-game__header">
                <div class="sel-game__info">
                    <h2 class="sel-game__title">${escHtml(item.gameTitle || 'Erreur')}</h2>
                    <p style="font-size:.8rem;color:var(--error);margin-top:.25rem">
                        Impossible de charger les données de ce jeu.
                    </p>
                </div>
                <button type="button" class="sel-game__remove" data-action="remove-game"
                        aria-label="Retirer ce jeu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>`;
        bindCardEvents(placeholder);
    }

    updateBar();
}

// ──────────────────────────────────────────────
//  Liaison des événements d'une carte
// ──────────────────────────────────────────────
function bindCardEvents(card) {
    // Retirer le jeu
    card.querySelector('[data-action="remove-game"]')?.addEventListener('click', () => {
        const gameId    = parseInt(card.dataset.gameId, 10);
        const platId    = parseInt(card.dataset.platformId, 10);
        const region    = card.dataset.region || '';
        const mediaType = card.dataset.gameType || 'physical';

        const items = storageLoad().filter(i =>
            !(i.gameId === gameId && i.platformId === platId &&
              i.region === region && i.mediaType === mediaType)
        );
        storageSave(items);
        card.remove();
        updateBar();

        const list = document.getElementById('sel-list');
        if (list && list.children.length === 0) showEmpty();
    });

    // Type toggle
    card.querySelectorAll('.sel-type-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const type       = btn.dataset.type;
            const isPhysical = type === 'physical';
            FormState.syncTypeButtons(card, isPhysical);
            FormState.onTypeChange(card, isPhysical);
        });
    });

    // Ajout d'un exemplaire
    card.querySelector('[data-action="add-copy"]')?.addEventListener('click', () => {
        const copiesList = card.querySelector('[data-copies-list]');
        if (!copiesList) return;

        const existing   = copiesList.querySelectorAll('.sel-copy').length;
        const gameIdx    = parseInt(card.dataset.gameIdx, 10);
        const isPhysical = card.dataset.gameType === 'physical';

        const tmp      = document.createElement('ul');
        tmp.innerHTML  = Templates.copySection(existing, gameIdx, isPhysical);
        const newCopy  = tmp.firstElementChild;
        copiesList.appendChild(newCopy);

        bindCopyEvents(newCopy, copiesList);
        refreshCopyNumbers(copiesList);
        updateBar();
        newCopy.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    // Liaison des événements des copies existantes
    card.querySelectorAll('.sel-copy').forEach(copy => {
        const copiesList = card.querySelector('[data-copies-list]');
        if (copiesList) bindCopyEvents(copy, copiesList);
    });
}

// ──────────────────────────────────────────────
//  Événements dans une section copie
// ──────────────────────────────────────────────
function bindCopyEvents(copy, copiesList) {
    // Suppression
    copy.querySelector('[data-action="del-copy"]')?.addEventListener('click', () => {
        copy.remove();
        refreshCopyNumbers(copiesList);
        updateBar();
    });

    // Changement de statut
    copy.querySelector('.copy-status')?.addEventListener('change', e => {
        FormState.onStatusChange(copy, e.target.value);
    });

    // Compteur de caractères — avis
    copy.querySelector('.copy-review-text')?.addEventListener('input', e => {
        const len   = e.target.value.trim().length;
        const cntEl = copy.querySelector('[id$="-review-count"]');
        if (cntEl) {
            cntEl.textContent = `${len} / 100 caractères minimum`;
            cntEl.className   = `sel-char-count${len >= 100 ? ' is-ok' : len > 60 ? ' is-warn' : ''}`;
        }
        // Reset validity
        e.target.setCustomValidity('');
    });

    // Compteur de caractères — commentaire état
    copy.querySelector('.copy-condition-note')?.addEventListener('input', e => {
        const len   = e.target.value.length;
        const cntEl = copy.querySelector('[id$="-condnote-count"]');
        if (cntEl) cntEl.textContent = `${len} / 500`;
    });

    // Rating pills
    copy.querySelectorAll('.sel-rating-pill').forEach(pill => {
        pill.addEventListener('click', () => {
            const val       = parseInt(pill.dataset.value, 10);
            const container = pill.closest('.sel-rating-pills');
            if (!container) return;

            // Toggle : cliquer sur le même pill désélectionne
            const hiddenInput = container.querySelector('.copy-rating-input');
            const isSelected  = pill.classList.contains('is-selected');
            const valLabel    = container.querySelector('.sel-rating-val');

            container.querySelectorAll('.sel-rating-pill').forEach(p => {
                p.classList.remove('is-selected');
                p.setAttribute('aria-pressed', 'false');
            });

            if (!isSelected) {
                pill.classList.add('is-selected');
                pill.setAttribute('aria-pressed', 'true');
                if (hiddenInput) hiddenInput.value = val;
                if (valLabel) valLabel.textContent = `(${val}/10)`;
            } else {
                if (hiddenInput) hiddenInput.value = '';
                if (valLabel) valLabel.textContent = '';
            }
        });
    });

    // Ajout de photo
    copy.querySelector('[data-action="add-photo"]')?.addEventListener('click', e => {
        const photosList = copy.querySelector('[data-photos]');
        if (!photosList) return;
        if (photosList.querySelectorAll('.sel-photo-input-row').length >= 3) {
            e.currentTarget.disabled = true;
            return;
        }
        const row  = document.createElement('div');
        row.className = 'sel-photo-input-row';
        row.innerHTML = `<input class="form-input" type="url" name="photo_url[]" placeholder="https://…">`;
        photosList.appendChild(row);

        if (photosList.querySelectorAll('.sel-photo-input-row').length >= 3) {
            e.currentTarget.disabled = true;
        }
    });

    // Upload de photo (convertit en URL stockée côté serveur)
    const uploadBtn  = copy.querySelector('[data-action="upload-photo"]');
    const fileInput  = copy.querySelector('.sel-photo-file');
    const photosList = copy.querySelector('[data-photos]');

    uploadBtn?.addEventListener('click', () => {
        if (!fileInput || !photosList) return;
        if (photosList.querySelectorAll('.sel-photo-input-row').length >= 3
            && Array.from(photosList.querySelectorAll('input[name="photo_url[]"]')).every(i => i.value.trim() !== '')
        ) {
            uploadBtn.disabled = true;
            return;
        }
        fileInput.click();
    });

    fileInput?.addEventListener('change', async () => {
        if (!fileInput || !photosList || !fileInput.files || fileInput.files.length === 0) return;
        const file = fileInput.files[0];
        fileInput.value = '';

        if (!file.type || !file.type.startsWith('image/')) return;

        const prevLabel = uploadBtn ? uploadBtn.textContent : '';
        if (uploadBtn) {
            uploadBtn.disabled = true;
            uploadBtn.textContent = 'Upload…';
        }

        try {
            const fd = new FormData();
            fd.append('file', file, file.name || 'photo');

            const res = await fetch('/api/uploads/selection-photo', { method: 'POST', body: fd });
            const json = await res.json().catch(() => ({}));
            const url = json?.url || json?.data?.url || '';
            if (!res.ok || !json?.success || !url) throw new Error('upload failed');

            // Met l'URL dans un champ existant vide, sinon crée une nouvelle ligne
            const inputs = Array.from(photosList.querySelectorAll('input[name="photo_url[]"]'));
            const empty = inputs.find(i => i.value.trim() === '');
            if (empty) {
                empty.value = url;
            } else {
                if (photosList.querySelectorAll('.sel-photo-input-row').length < 3) {
                    const row  = document.createElement('div');
                    row.className = 'sel-photo-input-row';
                    row.innerHTML = `<input class="form-input" type="url" name="photo_url[]" placeholder="https://…">`;
                    photosList.appendChild(row);
                    const input = row.querySelector('input');
                    if (input) input.value = url;
                }
            }

            // Désactiver les boutons si on a atteint 3
            const rows = photosList.querySelectorAll('.sel-photo-input-row').length;
            if (rows >= 3) {
                copy.querySelector('[data-action="add-photo"]')?.setAttribute('disabled', '');
            }
        } catch {
            // Silencieux : l'utilisateur peut toujours coller une URL
        } finally {
            if (uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.textContent = prevLabel || 'Uploader une image';
            }
        }
    });
}

// ──────────────────────────────────────────────
//  Renumérotation des copies
// ──────────────────────────────────────────────
function refreshCopyNumbers(copiesList) {
    const copies = copiesList.querySelectorAll('.sel-copy');
    copies.forEach((copy, idx) => {
        copy.dataset.copyIdx = idx;
        const num   = copy.querySelector('.sel-copy__num');
        const label = copy.querySelector('.sel-copy__label');
        if (num)   num.textContent   = idx + 1;
        if (label) label.childNodes[label.childNodes.length - 1].textContent = ` Exemplaire ${idx + 1}`;
        const delBtn = copy.querySelector('[data-action="del-copy"]');
        if (delBtn) {
            delBtn.hidden = idx === 0;
            delBtn.setAttribute('aria-label', `Supprimer l'exemplaire ${idx + 1}`);
        }
    });
}

// ──────────────────────────────────────────────
//  États de la page
// ──────────────────────────────────────────────
function showLoading()  { set('sel-loading', false); set('sel-empty', true); set('sel-games', true); }
function showEmpty()    { set('sel-loading', true); set('sel-empty', false); set('sel-games', true); }
function showGames()    { set('sel-loading', true); set('sel-empty', true); set('sel-games', false); }

function set(id, hidden) {
    const el = document.getElementById(id);
    if (el) el.hidden = hidden;
}

// ──────────────────────────────────────────────
//  Soumission
// ──────────────────────────────────────────────
async function handleSubmit() {
    if (!Validator.validateAll()) return;

    const submitBtn = document.getElementById('sel-submit-btn');
    const bar       = document.getElementById('sel-bar');
    const page      = document.querySelector('.sel-page');
    const resultEl  = document.getElementById('sel-result');

    // État "en cours"
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = `<div class="sel-bar__spinner"></div> Ajout en cours…`;
    }
    if (page) page.classList.add('sel-submitting');

    const games = collectFormData();

    try {
        const res  = await fetch(CFG.addUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body:    JSON.stringify({ games }),
        });
        const json = await res.json();

        if (json.success && (!json.errors || json.errors.length === 0)) {
            // Succès total
            storageSave([]);
            showSuccessResult(json.data?.created?.length || games.length);
            set('sel-games', true);
            if (bar) { bar.hidden = true; bar.classList.remove('is-visible'); }
            setTimeout(() => { window.location.href = CFG.collectionUrl; }, 2500);
        } else if (json.success && json.errors?.length > 0) {
            // Succès partiel
            showPartialResult(json.data?.created || [], json.errors);
            resetSubmitBtn(submitBtn);
        } else {
            // Erreur
            showErrorResult(json.errors || [], json.error);
            resetSubmitBtn(submitBtn);
        }
    } catch (err) {
        if (resultEl) {
            resultEl.hidden   = false;
            resultEl.innerHTML = buildErrorHtml('Erreur réseau', 'Impossible de contacter le serveur. Veuillez réessayer.');
        }
        resetSubmitBtn(submitBtn);
    }

    if (page) page.classList.remove('sel-submitting');
}

function resetSubmitBtn(btn) {
    if (!btn) return;
    btn.disabled  = false;
    btn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" aria-hidden="true" style="width:16px;height:16px">
            <path d="M20 6L9 17l-5-5"/>
        </svg>
        Ajouter à ma collection`;
}

function showSuccessResult(count) {
    const el = document.getElementById('sel-result');
    if (!el) return;
    el.hidden   = false;
    el.innerHTML = `
        <div class="sel-result-success">
            <div class="sel-result-success__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                     stroke-linecap="round" aria-hidden="true">
                    <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>
                </svg>
            </div>
            <h2 class="sel-result-success__title">
                ${count} ${count <= 1 ? 'exemplaire ajouté' : 'exemplaires ajoutés'} !
            </h2>
            <p class="sel-result-success__desc">
                Vos jeux ont bien été ajoutés à votre collection. Redirection en cours…
            </p>
            <a href="${CFG.collectionUrl}" class="btn btn--primary">
                Voir ma collection
            </a>
        </div>`;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function showErrorResult(errors, singleError) {
    const el = document.getElementById('sel-result');
    if (!el) return;

    const msg = singleError?.message || 'Une erreur est survenue. Vérifiez les champs et réessayez.';
    el.hidden   = false;
    el.innerHTML = buildErrorHtml('Erreur lors de l\'ajout', msg);
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function showPartialResult(created, errors) {
    const el = document.getElementById('sel-result');
    if (!el) return;

    const dupErrors = errors.filter(e => e.code === 'DUPLICATE_ENTRY');
    const releaseErrors = errors.filter(e =>
        e.code === 'COLLECTION_TOO_EARLY' || e.code === 'RELEASE_DATE_UNKNOWN' || e.code === 'RELEASE_DATE_INVALID'
    );
    const otherErrors = errors.filter(e =>
        e.code !== 'DUPLICATE_ENTRY'
        && e.code !== 'COLLECTION_TOO_EARLY'
        && e.code !== 'RELEASE_DATE_UNKNOWN'
        && e.code !== 'RELEASE_DATE_INVALID'
    );

    let html = `<div class="sel-result-error">
        <div class="sel-result-error__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M12 9v4M12 17h.01"/>
                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
        </div>
        <h2 class="sel-result-error__title">Ajout partiel</h2>
        <p class="sel-result-error__desc">
            ${created.length} exemplaire(s) ajouté(s). 
            ${dupErrors.length > 0 ? `${dupErrors.length} doublon(s) ignoré(s).` : ''}
            ${releaseErrors.length > 0 ? `${releaseErrors.length} jeu(x) non éligible(s) (règle de sortie).` : ''}
            ${otherErrors.length > 0 ? `${otherErrors.length} autre(s) erreur(s).` : ''}
        </p>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center;margin-top:.5rem">
            <a href="${CFG.collectionUrl}" class="btn btn--primary">Voir ma collection</a>
        </div>
    </div>`;

    el.hidden   = false;
    el.innerHTML = html;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function buildErrorHtml(title, msg) {
    return `<div class="sel-result-error">
        <div class="sel-result-error__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
            </svg>
        </div>
        <h2 class="sel-result-error__title">${escHtml(title)}</h2>
        <p class="sel-result-error__desc">${escHtml(msg)}</p>
    </div>`;
}

// ──────────────────────────────────────────────
//  Initialisation principale
// ──────────────────────────────────────────────
(async function SelectPage() {
    showLoading();

    const items = storageLoad();

    if (items.length === 0) {
        showEmpty();
        updateBar();
        return;
    }

    showGames();

    // Rendu parallèle de toutes les cartes
    await Promise.all(items.map((item, idx) => renderGameCard(item, idx)));

    updateBar();

    // Bouton soumission
    document.getElementById('sel-submit-btn')?.addEventListener('click', handleSubmit);

    // Vider la sélection
    document.getElementById('sel-clear-btn')?.addEventListener('click', () => {
        if (!confirm('Vider toute la sélection ?')) return;
        storageSave([]);
        document.getElementById('sel-list')?.replaceChildren();
        showEmpty();
        updateBar();
    });
})();
