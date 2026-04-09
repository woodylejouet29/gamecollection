/**
 * search.js — Modules JavaScript de la page /search
 *
 * Modules :
 *   - AjaxSearch         : rechargement partiel des résultats + recherche live (debounce 300 ms)
 *   - FilterSidebar      : ouverture/fermeture sur mobile + slider note
 *   - GameModal          : popup d'ajout (chargement AJAX fiche jeu)
 *   - SelectionStore     : CRUD localStorage de la sélection
 *   - LazyImages         : IntersectionObserver pour les images hors viewport
 */

'use strict';

const SEARCH_CFG = window.SEARCH_CONFIG || {
    gameUrl:    '/api/games/',
    suggestUrl: '/api/games/search',
};

// ──────────────────────────────────────────────
//  SelectionStore — localStorage
// ──────────────────────────────────────────────

const SelectionStore = (() => {
    const KEY = 'game_selection';

    function load() {
        try { return JSON.parse(localStorage.getItem(KEY) || '[]'); }
        catch { return []; }
    }

    function save(items) {
        localStorage.setItem(KEY, JSON.stringify(items));
        // Notifier app.js (SelectionBar)
        window.dispatchEvent(new CustomEvent('selection:updated', { detail: { count: items.length } }));
    }

    function add(entry) {
        const items = load();
        items.push(entry);
        save(items);
    }

    function remove(gameId, platformId, region, mediaType) {
        const items = load().filter(i =>
            !(i.gameId === gameId && i.platformId === platformId &&
              i.region === region && i.mediaType === mediaType)
        );
        save(items);
    }

    function isDuplicate(gameId, platformId, region, mediaType) {
        return load().some(i =>
            i.gameId === gameId && i.platformId === platformId &&
            i.region === region && i.mediaType === mediaType
        );
    }

    function count() {
        return load().length;
    }

    return { load, add, remove, isDuplicate, count };
})();

// ──────────────────────────────────────────────
//  FilterSidebar — mobile
// ──────────────────────────────────────────────

(function FilterSidebar() {
    const fab      = document.getElementById('search-filters-fab');
    const sidebar  = document.getElementById('search-sidebar');
    const closeBtn = document.getElementById('search-sidebar-close');

    if (!fab || !sidebar) return;

    // Certains styles globaux (transform/backdrop-filter sur des ancêtres) peuvent casser `position: fixed`
    // sur mobile. On déplace donc le FAB sous <body> pour garantir son positionnement.
    if (fab.parentElement !== document.body) {
        document.body.appendChild(fab);
    }

    // Overlay backdrop pour fermer la sidebar mobile
    let backdrop = null;

    fab.addEventListener('click', () => {
        if (sidebar.classList.contains('is-open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeSidebar();
    });

    function openSidebar() {
        // Nettoyer au cas où (évite empilement de backdrops)
        backdrop?.remove();
        backdrop = null;

        sidebar.classList.add('is-open');

        backdrop = document.createElement('div');
        backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:499;';
        backdrop.addEventListener('click', closeSidebar);
        document.body.appendChild(backdrop);
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('is-open');
        backdrop?.remove();
        backdrop = null;
        document.body.style.overflow = '';
    }

    // Slider de note → affichage en temps réel
    const slider  = document.getElementById('f-rating');
    const display = document.getElementById('rating-val-display');

    if (slider && display) {
        slider.addEventListener('input', () => {
            const v = parseInt(slider.value, 10);
            display.textContent = v > 0 ? `${v}/100` : 'Toutes';
        });
    }
})();

// ──────────────────────────────────────────────
//  GameModal — popup fiche jeu
// ──────────────────────────────────────────────

(function GameModal() {
    const modal       = document.getElementById('game-modal');
    const loading     = document.getElementById('modal-loading');
    const content     = document.getElementById('modal-content');
    const coverImg    = document.getElementById('modal-cover');
    const titleEl     = document.getElementById('modal-title');
    const metaEl      = document.getElementById('modal-meta');
    const synopsisEl  = document.getElementById('modal-synopsis');
    const platformSel = document.getElementById('add-platform');
    const gameIdInput = document.getElementById('add-game-id');
    const form        = document.getElementById('add-form');
    const dupWarn     = document.getElementById('add-duplicate-warn');
    const successEl   = document.getElementById('add-success');
    const successTitle = document.getElementById('add-success-title');

    if (!modal) return;

    let currentGame = null;

    // Délégation de clics pour ouvrir la popup
    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-action="open-popup"]');
        if (btn) {
            e.preventDefault();
            openPopup(parseInt(btn.dataset.gameId, 10));
        }

        if (e.target.closest('[data-action="close-popup"]')) {
            closePopup();
        }
    });

    // Fermer avec Escape
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !modal.hidden) closePopup();
    });

    // Soumission du formulaire d'ajout
    if (form) {
        form.addEventListener('submit', handleSubmit);
    }

    // Vérification doublon en temps réel
    const checkDuplicate = () => {
        if (!currentGame || !platformSel) return;
        const dup = SelectionStore.isDuplicate(
            currentGame.id,
            parseInt(platformSel.value, 10) || 0,
            document.getElementById('add-region')?.value ?? '',
            form?.querySelector('[name="media_type"]:checked')?.value ?? 'physical'
        );
        if (dupWarn) dupWarn.hidden = !dup;
    };

    document.addEventListener('change', e => {
        if (e.target.closest('#add-form')) checkDuplicate();
    });

    async function openPopup(gameId) {
        if (!gameId) return;

        // Réinitialiser l'état
        resetModal();
        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        try {
            const res = await fetch(`${SEARCH_CFG.gameUrl}${gameId}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (!res.ok) throw new Error('not found');
            const payload = await res.json();
            // L’API renvoie { success, data } (voir GameApiController::show) ; le jeu est dans data.
            currentGame = payload?.data ?? payload;
            renderModal(currentGame);
        } catch {
            if (loading) loading.innerHTML = '<p style="padding:2rem;text-align:center;color:var(--muted)">Impossible de charger ce jeu.</p>';
        }
    }

    function renderModal(game) {
        // Couverture
        const src = coverSrc(game.cover_url);
        if (coverImg) {
            if (src) {
                coverImg.src = src;
                coverImg.alt = game.title ?? '';
                coverImg.onerror = () => { coverImg.closest('.game-modal__cover-wrap').style.display = 'none'; };
            } else {
                coverImg.closest('.game-modal__cover-wrap').style.display = 'none';
            }
        }

        // Texte
        if (titleEl)    titleEl.textContent = game.title ?? '';
        if (metaEl)     metaEl.textContent  = buildMeta(game);
        if (synopsisEl) synopsisEl.textContent = game.synopsis ?? '';

        // Plateformes
        if (platformSel && gameIdInput) {
            gameIdInput.value = game.id;
            platformSel.innerHTML = '<option value="">Choisir une plateforme…</option>';

            const platforms = game.platforms ?? [];
            platforms.forEach(p => {
                const name    = p.platforms?.name ?? `Plateforme #${p.platform_id}`;
                const abbr    = p.platforms?.abbreviation ?? '';
                const label   = abbr ? `${abbr} — ${name}` : name;
                const option  = new Option(label, p.platform_id);
                platformSel.add(option);
            });
        }

        // Cacher le spinner, montrer le contenu
        if (loading)  loading.hidden = true;
        if (content)  content.hidden = false;
    }

    function buildMeta(game) {
        const parts = [];
        if (game.developer)    parts.push(game.developer);
        if (game.release_date) parts.push(game.release_date.slice(0, 4));
        if (game.igdb_rating)  parts.push(`★ ${Math.round(game.igdb_rating)}/100`);
        return parts.join(' · ');
    }

    function handleSubmit(e) {
        e.preventDefault();

        if (!currentGame) return;

        const platformId = parseInt(platformSel?.value ?? '', 10);
        if (!platformId) {
            platformSel?.focus();
            platformSel?.setCustomValidity('Veuillez choisir une plateforme.');
            platformSel?.reportValidity();
            return;
        }
        platformSel?.setCustomValidity('');

        const region    = document.getElementById('add-region')?.value ?? '';
        const mediaType = form.querySelector('[name="media_type"]:checked')?.value ?? 'physical';
        const platformName = platformSel?.options[platformSel.selectedIndex]?.text ?? '';

        if (SelectionStore.isDuplicate(currentGame.id, platformId, region, mediaType)) {
            if (dupWarn) dupWarn.hidden = false;
            return;
        }

        SelectionStore.add({
            gameId:       currentGame.id,
            gameTitle:    currentGame.title ?? '',
            gameCover:    currentGame.cover_url ?? '',
            gameSlug:     currentGame.slug ?? '',
            platformId,
            platformName,
            region,
            mediaType,
            addedAt:      new Date().toISOString(),
        });

        // Afficher le succès
        if (form)        form.hidden = true;
        if (successEl)   successEl.hidden = false;
        if (successTitle) successTitle.textContent = currentGame.title ?? '';
    }

    function resetModal() {
        currentGame = null;
        if (loading)  { loading.hidden = false; loading.innerHTML = '<div class="modal-spinner" aria-label="Chargement…"></div>'; }
        if (content)  content.hidden = true;
        if (form)     { form.hidden = false; form.reset(); }
        if (successEl) successEl.hidden = true;
        if (dupWarn)   dupWarn.hidden = true;
    }

    function closePopup() {
        modal.hidden = true;
        document.body.style.overflow = '';
        currentGame = null;
    }
})();

// ──────────────────────────────────────────────
//  LazyImages — IntersectionObserver
//  Exposé globalement pour être réutilisé après injection AJAX.
// ──────────────────────────────────────────────

const LazyImages = (() => {
    const obs = ('IntersectionObserver' in window)
        ? new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const img = entry.target;
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    delete img.dataset.src;
                }
                observer.unobserve(img);
            });
          }, { rootMargin: '200px' })
        : null;

    function observe(root) {
        if (!obs) return;
        (root || document).querySelectorAll('img[data-src]').forEach(img => obs.observe(img));
    }

    // Observation initiale
    observe();

    return { observe };
})();

// ──────────────────────────────────────────────
//  AjaxSearch — rechargement partiel des résultats
//  sans rechargement de page (sidebar + header restent en place)
// ──────────────────────────────────────────────

(function AjaxSearch() {
    const area        = document.getElementById('search-results-area');
    const filtersForm = document.getElementById('filters-form');
    const heroForm    = document.getElementById('search-hero-form');
    const heroInput   = document.getElementById('search-main-input');
    const clearBtn    = document.getElementById('search-clear-btn');
    const fab         = document.getElementById('search-filters-fab');
    const suggestions = document.getElementById('search-suggestions');

    if (!area || !filtersForm) return;

    const DEBOUNCE_MS = 650;
    let debounceTimer = null;
    let suggestTimer  = null;

    let suggestAbortCtrl = null;
    let activeSuggestIndex = -1;
    let lastSuggestQuery = '';

    function syncClearButton() {
        if (!clearBtn || !heroInput) return;
        clearBtn.style.display = heroInput.value.trim() ? '' : 'none';
    }

    function hideSuggestions() {
        if (!suggestions) return;
        suggestions.hidden = true;
        suggestions.innerHTML = '';
        activeSuggestIndex = -1;
    }

    function hasDigit(str) {
        return /\d/.test(str);
    }

    function shouldLoadFullResults(q) {
        // Évite les requêtes "chères" sur des fragments courts (ex: "2", "2k", "wwe 2")
        // On laisse quand même passer les clears (q vide).
        const len = q.trim().length;
        if (len === 0) return true;
        if (len < 3) return false;
        if (hasDigit(q) && len < 4) return false;
        return true;
    }

    function coverThumbSrc(url) {
        return coverSrc(url);
    }

    function renderSuggestions(items) {
        if (!suggestions) return;
        suggestions.innerHTML = '';

        if (!Array.isArray(items) || items.length === 0) {
            suggestions.hidden = true;
            activeSuggestIndex = -1;
            return;
        }

        items.forEach((g, idx) => {
            const li = document.createElement('li');
            li.className = 'search-suggestions__item';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.dataset.index = String(idx);

            const title = g?.title ?? '';
            const rating = (g?.igdb_rating !== undefined && g?.igdb_rating !== null)
                ? Math.round(Number(g.igdb_rating))
                : null;

            const thumb = g?.cover_url ? coverThumbSrc(g.cover_url) : '';
            if (thumb) {
                const img = document.createElement('img');
                img.className = 'search-suggestions__thumb';
                img.loading = 'lazy';
                img.alt = '';
                img.src = thumb;
                img.onerror = () => { img.remove(); };
                li.appendChild(img);
            } else {
                const ph = document.createElement('div');
                ph.className = 'search-suggestions__thumb-placeholder';
                ph.textContent = truncate(title, 18) || '—';
                li.appendChild(ph);
            }

            const info = document.createElement('div');
            info.className = 'search-suggestions__info';

            const name = document.createElement('div');
            name.className = 'search-suggestions__name';
            name.textContent = title;
            info.appendChild(name);

            if (rating !== null && !Number.isNaN(rating)) {
                const r = document.createElement('div');
                r.className = 'search-suggestions__rating';
                r.textContent = `★ ${rating}/100`;
                info.appendChild(r);
            }

            li.appendChild(info);

            li.addEventListener('mousedown', e => {
                // Empêche le blur de l'input avant le click.
                e.preventDefault();
            });
            li.addEventListener('click', () => {
                if (!heroInput) return;
                heroInput.value = title;
                syncClearButton();
                hideSuggestions();
                clearTimeout(debounceTimer);
                loadResults(buildUrl());
            });

            suggestions.appendChild(li);
        });

        suggestions.hidden = false;
        activeSuggestIndex = -1;
    }

    function setActiveSuggestion(nextIdx) {
        if (!suggestions) return;
        const nodes = Array.from(suggestions.querySelectorAll('.search-suggestions__item'));
        if (nodes.length === 0) return;
        const clamped = Math.max(0, Math.min(nodes.length - 1, nextIdx));
        nodes.forEach((n, i) => n.setAttribute('aria-selected', i === clamped ? 'true' : 'false'));
        activeSuggestIndex = clamped;
        nodes[clamped].scrollIntoView({ block: 'nearest' });
    }

    async function loadSuggestions(q) {
        if (!suggestions) return;
        const trimmed = q.trim();
        if (trimmed.length < 2) {
            hideSuggestions();
            return;
        }

        // Annule la requête précédente si toujours en cours
        suggestAbortCtrl?.abort();
        suggestAbortCtrl = new AbortController();

        lastSuggestQuery = trimmed;

        try {
            const url = `${SEARCH_CFG.suggestUrl}?q=${encodeURIComponent(trimmed)}&limit=8`;
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json' },
                signal: suggestAbortCtrl.signal,
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();

            // Si l'utilisateur a déjà tapé autre chose, on ignore.
            if ((heroInput?.value ?? '').trim() !== lastSuggestQuery) return;

            renderSuggestions(data);
        } catch (err) {
            if (err?.name === 'AbortError') return;
            hideSuggestions();
        }
    }

    // ── Recherche live (hero) ──
    if (heroInput) {
        heroInput.addEventListener('input', () => {
            syncClearButton();

            // Suggestions (rapides)
            clearTimeout(suggestTimer);
            suggestTimer = setTimeout(() => {
                loadSuggestions(heroInput.value);
            }, 150);

            // Résultats complets : uniquement si la requête est "assez précise"
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const q = heroInput.value || '';
                if (!shouldLoadFullResults(q)) return;
                loadResults(buildUrl());
            }, DEBOUNCE_MS);
        });

        heroInput.addEventListener('keydown', e => {
            if (!suggestions || suggestions.hidden) {
                if (e.key === 'Enter') {
                    hideSuggestions();
                }
                return;
            }

            const items = suggestions.querySelectorAll('.search-suggestions__item');
            if (items.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActiveSuggestion(activeSuggestIndex + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActiveSuggestion(activeSuggestIndex - 1);
            } else if (e.key === 'Enter') {
                if (activeSuggestIndex >= 0) {
                    e.preventDefault();
                    items[activeSuggestIndex]?.click();
                } else {
                    hideSuggestions();
                }
            } else if (e.key === 'Escape') {
                hideSuggestions();
            }
        });

        heroInput.addEventListener('blur', () => {
            // Petit délai pour permettre le clic sur une suggestion.
            setTimeout(() => hideSuggestions(), 120);
        });
    }

    if (clearBtn && heroInput) {
        clearBtn.addEventListener('click', () => {
            heroInput.value = '';
            syncClearButton();
            hideSuggestions();
            clearTimeout(debounceTimer);
            loadResults(buildUrl());
        });
    }

    // ── Intercept soumission des deux formulaires ──
    [filtersForm, heroForm].forEach(form => {
        if (!form) return;
        form.addEventListener('submit', e => {
            e.preventDefault();
            clearTimeout(debounceTimer);
            loadResults(buildUrl());
        });
    });

    // ── Intercept changement de tri (sort-select dans la zone résultats) ──
    // Délégation : le #sort-select est remplacé à chaque AJAX
    document.addEventListener('change', e => {
        if (e.target.id === 'sort-select') {
            loadResults(buildUrl());
        }
    });

    // ── Intercept clics de pagination ──
    document.addEventListener('click', e => {
        const link = e.target.closest('.pagination__btn[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (href && href.startsWith('/search')) {
            e.preventDefault();
            loadResults(link.href);
        }
    });

    // ── Boutons « Réinitialiser » dans la zone résultats ──
    document.addEventListener('click', e => {
        const btn = e.target.closest('a[href="/search"], a[href^="/search?"]');
        if (!btn) return;
        // Uniquement les liens dans la zone résultats (pas le nav header)
        if (btn.closest('#search-results-area') || btn.closest('.search-sidebar')) {
            e.preventDefault();
            // Réinitialiser aussi les champs de la sidebar
            filtersForm.reset();
            if (heroInput) heroInput.value = '';
            syncClearButton();
            loadResults(btn.href);
        }
    });

    // ── Navigation navigateur (bouton Précédent / Suivant) ──
    window.addEventListener('popstate', () => {
        loadResults(window.location.href, { pushState: false });
    });

    // ─────────────────────────────────────────
    //  Chargement AJAX
    // ─────────────────────────────────────────

    let abortCtrl = null;

    async function loadResults(url, { pushState = true } = {}) {
        // Annuler la requête précédente si toujours en cours
        abortCtrl?.abort();
        abortCtrl = new AbortController();

        area.classList.add('is-loading');

        try {
            const res = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: abortCtrl.signal,
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const html = await res.text();

            // Remplace le contenu
            area.innerHTML = html;

            // Met à jour l'URL (sans rechargement)
            if (pushState) {
                history.pushState({ searchUrl: url }, '', url);
            }

            // Ré-applique le mode de vue courant sur le nouveau #results-grid et les boutons
            if (typeof window._applyViewMode === 'function') {
                window._applyViewMode(getCookie('view_mode') || 'grid');
            } else {
                applyCurrentViewMode();
            }

            // Ré-observe les images lazy du nouveau contenu
            LazyImages.observe(area);

            // Met à jour le badge FAB mobile
            updateFabBadge(url);

            // Défile vers la zone résultats (smooth, seulement si pas déjà visible)
            const areaRect = area.getBoundingClientRect();
            if (areaRect.top < 0) {
                area.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

        } catch (err) {
            if (err.name === 'AbortError') return; // requête annulée volontairement
            // Fallback : navigation complète en cas d'erreur réseau
            window.location.href = url;
        } finally {
            area.classList.remove('is-loading');
        }
    }

    // ─────────────────────────────────────────
    //  Construction de l'URL depuis les formulaires
    // ─────────────────────────────────────────

    function buildUrl() {
        const params = new URLSearchParams();

        // q toujours depuis le champ de recherche visible (source unique de vérité)
        const q = document.getElementById('search-main-input')?.value.trim() || '';
        if (q) params.set('q', q);

        // Tous les autres filtres depuis le formulaire sidebar
        // (FormData inclut aussi les éléments associés via form="filters-form")
        new FormData(filtersForm).forEach((v, k) => {
            const val = String(v).trim();
            if (k !== 'q' && val !== '' && val !== '0') {
                params.set(k, val);
            }
        });

        return '/search?' + params.toString();
    }

    // ─────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────

    function applyCurrentViewMode() {
        const grid = document.getElementById('results-grid');
        if (!grid) return;
        const mode  = getCookie('view_mode') || 'grid';
        const modes = ['grid', 'cards', 'list'];
        modes.forEach(m => grid.classList.remove('game-grid--' + m));
        grid.classList.add('game-grid--' + mode);
    }

    function updateFabBadge(url) {
        if (!fab) return;
        const sp = new URL(url, window.location.origin).searchParams;
        const filterKeys = ['platform', 'genre', 'year_from', 'year_to', 'rating_min'];
        const count = filterKeys.filter(k => {
            const v = sp.get(k);
            return v !== null && v !== '' && v !== '0';
        }).length;

        let badge = fab.querySelector('.search-filters-fab__badge');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'search-filters-fab__badge';
                fab.appendChild(badge);
            }
            badge.textContent = count;
        } else {
            badge?.remove();
        }
    }
})();

// ──────────────────────────────────────────────
//  Utilitaires partagés
// ──────────────────────────────────────────────

function coverSrc(url) {
    if (!url) return '';
    if (url.startsWith('/') || url.startsWith('http')) return url;
    return '/storage/images/igdb/' + url;
}

function esc(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function truncate(str, len) {
    return str && str.length > len ? str.slice(0, len) + '…' : (str ?? '');
}
