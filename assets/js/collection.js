/**
 * collection.js — Page /collection
 *
 * Modules :
 *   ExportMenu   – dropdown export CSV / JSON
 *   FilterPanel  – toggle du panel filtres avancés
 *   QuickEdit    – modale de modification rapide d'une entrée
 *   DeleteEntry  – suppression avec confirmation
 *   SearchForm   – soumission auto après pause de frappe
 */
(function CollectionApp() {
    'use strict';

    const CFG = window.COLLECTION_CONFIG || {};

    // ──────────────────────────────────────────────
    //  Utilitaires
    // ──────────────────────────────────────────────
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    function apiPost(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data),
        }).then(r => r.json());
    }
    function apiPatch(url, data) {
        return fetch(url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data),
        }).then(r => r.json());
    }
    function apiDelete(url, data) {
        return fetch(url, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data),
        }).then(r => r.json());
    }

    const RATING_LABELS = {
        1: 'Catastrophique', 2: 'Très mauvais', 3: 'Mauvais', 4: 'Médiocre',
        5: 'Passable', 6: 'Correct', 7: 'Bon', 8: 'Très bon', 9: 'Excellent', 10: 'Chef-d\'œuvre',
    };

    // ──────────────────────────────────────────────
    //  Module : ExportMenu
    // ──────────────────────────────────────────────
    (function ExportMenu() {
        const wrap = $('#col-export-wrap');
        const btn  = $('#col-export-btn');
        const menu = $('#col-export-menu');
        if (!wrap || !btn || !menu) return;

        // Conserver les filtres courants dans l'export "par plateforme"
        const xlsxByPlat = document.getElementById('col-export-xlsx-by-platform');
        if (xlsxByPlat) {
            const qs = window.location.search || '';
            const base = xlsxByPlat.getAttribute('href') || '/api/collection/export-xlsx-by-platform';
            xlsxByPlat.setAttribute('href', base + qs);
        }

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = menu.hidden === false;
            menu.hidden = open;
            btn.setAttribute('aria-expanded', String(!open));
        });

        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) {
                menu.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !menu.hidden) {
                menu.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
                btn.focus();
            }
        });
    })();

    // ──────────────────────────────────────────────
    //  Module : FilterPanel
    // ──────────────────────────────────────────────
    (function FilterPanel() {
        const toggleBtn = $('#col-filter-toggle');
        const panel     = $('#col-filter-panel');
        if (!toggleBtn || !panel) return;

        toggleBtn.addEventListener('click', () => {
            const open = !panel.hidden;
            panel.hidden = open;
            toggleBtn.setAttribute('aria-expanded', String(!open));
        });
    })();

    // ──────────────────────────────────────────────
    //  Module : SearchForm (debounce auto-submit)
    // ──────────────────────────────────────────────
    (function SearchForm() {
        const input = $('.col-search-input');
        const form  = $('.col-search-form');
        if (!input || !form) return;

        let timer;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => form.submit(), 600);
        });
    })();

    // ──────────────────────────────────────────────
    //  Module : QuickEdit
    // ──────────────────────────────────────────────
    (function QuickEdit() {
        const modal      = $('#col-edit-modal');
        const backdrop   = $('#col-edit-backdrop');
        const closeBtn   = $('#col-edit-close');
        const cancelBtn  = $('#col-edit-cancel');
        const saveBtn    = $('#col-edit-save');
        const errorBox   = $('#col-edit-error');
        const errorMsg   = $('#col-edit-error-msg');

        if (!modal) return;

        // Champs
        const fEntryId   = $('#edit-entry-id');
        const fGameId    = $('#edit-game-id');
        const fGameType  = $('#edit-game-type');
        const fStatus    = $('#edit-status');
        const fRating    = $('#edit-rating-val');
        const fReview    = $('#edit-review');
        const fCondition = $('#edit-condition');
        const fRank      = $('#edit-rank');
        const fPlayH     = $('#edit-playtime-h');
        const fPlayM     = $('#edit-playtime-m');

        const grpPlayTime  = $('#edit-playtime-group');
        const grpRating    = $('#edit-rating-group');
        const grpReview    = $('#edit-review-group');
        const grpCondition = $('#edit-condition-group');
        const charCount    = $('#edit-review-count');
        const ratingLabel  = $('#edit-rating-label');

        let currentCard = null;

        function openModal(card) {
            currentCard = card;
            const entryId   = card.dataset.entryId;
            const gameId    = card.dataset.gameId;
            const gameType  = card.dataset.gameType;
            const status    = card.dataset.status;
            const condition = card.dataset.condition;
            const rank      = parseInt(card.dataset.rank) || 0;
            const rating    = parseInt(card.dataset.reviewRating) || 0;
            const playMin   = parseInt(card.dataset.playTime) || 0;

            fEntryId.value  = entryId;
            fGameId.value   = gameId;
            fGameType.value = gameType;
            fStatus.value   = status;
            fCondition.value= condition || 'very_good';
            fRank.value     = rank > 0 ? rank : '';

            if (playMin > 0) {
                fPlayH.value = Math.floor(playMin / 60);
                fPlayM.value = playMin % 60;
            } else {
                fPlayH.value = '';
                fPlayM.value = '';
            }

            setRating(rating);
            if (fReview) fReview.value = '';
            updateCharCount();

            updateConditionalFields(status, gameType);
            hideError();

            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            setTimeout(() => fStatus?.focus(), 50);
        }

        function closeModal() {
            modal.hidden = true;
            document.body.style.overflow = '';
            currentCard = null;
        }

        function setRating(val) {
            if (!fRating) return;
            fRating.value = val || '';
            $$('.edit-rating-pill').forEach(p => {
                const v = parseInt(p.dataset.value);
                p.classList.toggle('is-on', v === val);
                p.setAttribute('aria-pressed', String(v === val));
            });
            if (ratingLabel) {
                ratingLabel.textContent = val ? RATING_LABELS[val] || '' : '';
            }
        }

        function updateConditionalFields(status, gameType) {
            const isCompleted = ['completed', 'hundred_percent'].includes(status);
            const isPhysical  = gameType === 'physical';

            if (grpPlayTime)  grpPlayTime.hidden  = !isCompleted;
            if (grpRating)    grpRating.hidden    = !isCompleted;
            if (grpReview)    grpReview.hidden    = !isCompleted;
            if (grpCondition) grpCondition.hidden = !isPhysical;
        }

        function updateCharCount() {
            if (!fReview || !charCount) return;
            const len = fReview.value.length;
            charCount.textContent = len + ' / 100';
            charCount.classList.toggle('is-ok', len >= 100);
        }

        function showError(msg) {
            if (!errorBox || !errorMsg) return;
            errorMsg.textContent = msg;
            errorBox.hidden = false;
        }
        function hideError() {
            if (errorBox) errorBox.hidden = true;
        }

        // ── Events ──
        if (fStatus) {
            fStatus.addEventListener('change', () => {
                updateConditionalFields(fStatus.value, fGameType.value);
            });
        }

        $$('.edit-rating-pill').forEach(p => {
            p.addEventListener('click', () => {
                const val = parseInt(p.dataset.value);
                const current = parseInt(fRating.value) || 0;
                setRating(val === current ? 0 : val);
            });
        });

        if (fReview) {
            fReview.addEventListener('input', updateCharCount);
        }

        closeBtn?.addEventListener('click', closeModal);
        cancelBtn?.addEventListener('click', closeModal);
        backdrop?.addEventListener('click', closeModal);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });

        saveBtn?.addEventListener('click', async () => {
            hideError();
            saveBtn.disabled = true;
            saveBtn.textContent = 'Enregistrement…';

            const status    = fStatus?.value || '';
            const rating    = parseInt(fRating?.value) || 0;
            const reviewBody= fReview?.value || '';
            const condition = fCondition?.value || '';
            const rank      = parseInt(fRank?.value) || 0;

            const hours   = parseInt(fPlayH?.value) || 0;
            const minutes = parseInt(fPlayM?.value) || 0;
            const totalMin = (hours * 60) + minutes;

            const isCompleted = ['completed', 'hundred_percent'].includes(status);
            const isPhysical  = fGameType.value === 'physical';

            // Validation avis
            if (isCompleted && rating > 0 && reviewBody.length > 0 && reviewBody.length < 100) {
                showError('L\'avis doit faire au moins 100 caractères (ou laisser le champ vide).');
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="width:15px;height:15px"><path d="M20 6L9 17l-5-5"/></svg> Enregistrer';
                return;
            }

            const payload = {
                entry_id:          parseInt(fEntryId.value),
                status,
                rank_position:     rank,
                play_time_minutes: isCompleted && totalMin > 0 ? totalMin : undefined,
                physical_condition:isPhysical && condition ? condition : undefined,
                rating:            isCompleted && rating > 0 ? rating : undefined,
                review_body:       isCompleted && reviewBody.length >= 100 ? reviewBody : undefined,
            };

            try {
                const res = await apiPatch(CFG.updateUrl, payload);
                if (res.success) {
                    updateCardDOM(currentCard, { status, rating, condition });
                    closeModal();
                } else {
                    showError(res.error?.message || 'Erreur lors de la mise à jour.');
                }
            } catch {
                showError('Erreur réseau. Veuillez réessayer.');
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" style="width:15px;height:15px"><path d="M20 6L9 17l-5-5"/></svg> Enregistrer';
            }
        });

        // Ouvre la modale via délégation d'événements
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="quick-edit"]');
            if (!btn) return;
            const card = btn.closest('.col-card');
            if (card) openModal(card);
        });

        // ── Mise à jour du DOM de la carte après sauvegarde ──
        function updateCardDOM(card, { status, rating, condition }) {
            if (!card) return;

            // Mise à jour des data-*
            card.dataset.status    = status;
            card.dataset.condition = condition || '';
            if (rating > 0) card.dataset.reviewRating = rating;

            // Badge statut
            const statusBadge = card.querySelector('.col-status-badge');
            if (statusBadge) {
                const labelMap = {
                    owned: 'Possédé', playing: 'En cours', completed: 'Terminé',
                    hundred_percent: '100%', abandoned: 'Abandonné',
                };
                const colorMap = {
                    owned: 'owned', playing: 'playing', completed: 'completed',
                    hundred_percent: 'hundred', abandoned: 'abandoned',
                };
                statusBadge.textContent = labelMap[status] || status;
                statusBadge.className = 'col-status-badge col-status-badge--' + (colorMap[status] || 'owned');
            }

            // Badge rating
            const ratingBadge = card.querySelector('.col-rating-badge');
            if (rating > 0) {
                if (ratingBadge) {
                    ratingBadge.textContent = rating + '/10';
                } else {
                    const cover = card.querySelector('.col-card__cover');
                    if (cover) {
                        const span = document.createElement('span');
                        span.className = 'col-rating-badge';
                        span.textContent = rating + '/10';
                        cover.appendChild(span);
                    }
                }
            }
        }
    })();

    // ──────────────────────────────────────────────
    //  Module : DeleteEntry
    // ──────────────────────────────────────────────
    (function DeleteEntry() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="delete"]');
            if (!btn) return;
            const card    = btn.closest('.col-card');
            const entryId = parseInt(card?.dataset?.entryId);
            if (!entryId) return;

            const title = card.querySelector('.col-card__title a')?.textContent?.trim() || 'cette entrée';

            if (!confirm(`Supprimer "${title}" de votre collection ?\nCette action est irréversible.`)) return;

            // Feedback visuel immédiat
            card.style.opacity = '0.5';
            card.style.pointerEvents = 'none';

            apiDelete(CFG.deleteUrl, { entry_id: entryId })
                .then(res => {
                    if (res.success) {
                        card.style.transition = 'opacity .25s, transform .25s';
                        card.style.opacity    = '0';
                        card.style.transform  = 'scale(.92)';
                        setTimeout(() => card.remove(), 260);

                        // Mise à jour du compteur
                        const countEl = document.querySelector('.col-results-count strong');
                        if (countEl) {
                            const n = Math.max(0, parseInt(countEl.textContent.replace(/\s/g, '')) - 1);
                            countEl.textContent = n.toLocaleString('fr-FR');
                        }
                    } else {
                        card.style.opacity = '';
                        card.style.pointerEvents = '';
                        alert('Erreur lors de la suppression. Veuillez réessayer.');
                    }
                })
                .catch(() => {
                    card.style.opacity = '';
                    card.style.pointerEvents = '';
                    alert('Erreur réseau. Veuillez réessayer.');
                });
        });
    })();

})();
