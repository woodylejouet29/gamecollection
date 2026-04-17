/**
 * game.js — Fiche jeu /game/{slug}
 *
 * Fonctionnalités :
 *   - Lightbox galerie de screenshots (clavier + souris)
 *   - Modale vidéo YouTube (iframe à la demande)
 *   - Toggle wishlist (POST /api/wishlist/toggle)
 *   - Modale "Ajouter à ma collection" → /select
 */

(function () {
    'use strict';

    const cfg = window.GAME_CONFIG || {};

    // ─────────────────────────────────────────────────────────────
    //  Onglets mobile (fiche jeu)
    // ─────────────────────────────────────────────────────────────

    const tabsRoot = document.querySelector('[data-game-tabs]');

    function setupGameTabs(root) {
        const tablist = root.querySelector('[role="tablist"]');
        const tabs = Array.from(root.querySelectorAll('[role="tab"][data-tab]'));
        const panels = Array.from(root.querySelectorAll('[role="tabpanel"][data-panel]'));
        if (!tablist || tabs.length === 0 || panels.length === 0) return;

        root.classList.add('is-enhanced');
        document.querySelector('.game-body')?.classList.add('has-game-tabs');

        const byKey = new Map(tabs.map(t => [t.dataset.tab, t]));
        const panelByKey = new Map(panels.map(p => [p.dataset.panel, p]));

        function setActive(key, { focus = false, updateHash = true } = {}) {
            const tab = byKey.get(key);
            const panel = panelByKey.get(key);
            if (!tab || !panel) return;

            tabs.forEach(t => {
                const active = t === tab;
                t.classList.toggle('is-active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
                t.tabIndex = active ? 0 : -1;
            });
            panels.forEach(p => {
                const active = p === panel;
                p.classList.toggle('is-active', active);
                if (active) {
                    p.removeAttribute('aria-hidden');
                } else {
                    p.setAttribute('aria-hidden', 'true');
                }
            });

            if (updateHash) {
                const hash = `#${encodeURIComponent(key)}`;
                if (window.location.hash !== hash) {
                    history.replaceState(null, '', hash);
                }
            }

            // Assure que l’onglet actif est visible dans la barre scrollable
            tab.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
            if (focus) tab.focus();
        }

        function keyFromHash() {
            const raw = (window.location.hash || '').replace(/^#/, '');
            if (!raw) return null;
            const decoded = decodeURIComponent(raw).toLowerCase();
            // alias possibles
            if (decoded === 'avis') return 'reviews';
            if (decoded === 'infos') return 'info';
            return decoded;
        }

        function initFromHashOrDefault() {
            const key = keyFromHash();
            if (key && byKey.has(key)) {
                setActive(key, { focus: false, updateHash: false });
                return;
            }
            // default : l’onglet marqué actif dans le HTML, sinon overview
            const htmlActive = tabs.find(t => t.classList.contains('is-active'))?.dataset.tab;
            setActive(htmlActive && byKey.has(htmlActive) ? htmlActive : 'overview', { focus: false, updateHash: false });
        }

        tablist.addEventListener('click', (e) => {
            const btn = e.target.closest('[role="tab"][data-tab]');
            if (!btn || !root.contains(btn)) return;
            setActive(btn.dataset.tab, { focus: false, updateHash: true });
        });

        tablist.addEventListener('keydown', (e) => {
            const current = e.target.closest('[role="tab"][data-tab]');
            if (!current) return;

            const idx = tabs.indexOf(current);
            if (idx < 0) return;

            let nextIdx = null;
            if (e.key === 'ArrowRight') nextIdx = (idx + 1) % tabs.length;
            if (e.key === 'ArrowLeft') nextIdx = (idx - 1 + tabs.length) % tabs.length;
            if (e.key === 'Home') nextIdx = 0;
            if (e.key === 'End') nextIdx = tabs.length - 1;
            if (nextIdx !== null) {
                e.preventDefault();
                const nextKey = tabs[nextIdx].dataset.tab;
                setActive(nextKey, { focus: true, updateHash: true });
                return;
            }

            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                setActive(current.dataset.tab, { focus: true, updateHash: true });
            }
        });

        window.addEventListener('hashchange', () => {
            const key = keyFromHash();
            if (key && byKey.has(key)) setActive(key, { focus: false, updateHash: false });
        });

        // Init
        initFromHashOrDefault();
    }

    if (tabsRoot) setupGameTabs(tabsRoot);

    // ─────────────────────────────────────────────────────────────
    //  Utilitaires
    // ─────────────────────────────────────────────────────────────

    /** Ouvre un élément .modal / .lightbox */
    function openOverlay(el) {
        if (!el) return;
        el.classList.add('is-open');
        el.removeAttribute('aria-hidden');
        document.body.style.overflow = 'hidden';
    }

    /** Ferme un élément .modal / .lightbox */
    function closeOverlay(el) {
        if (!el) return;
        el.classList.remove('is-open');
        el.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    /** Ferme toutes les overlays ouvertes */
    function closeAll() {
        document.querySelectorAll('.modal.is-open, .lightbox.is-open').forEach(closeOverlay);
    }

    // ─────────────────────────────────────────────────────────────
    //  Lightbox galerie
    // ─────────────────────────────────────────────────────────────

    const lightbox    = document.getElementById('lightbox');
    const lbImg       = document.getElementById('lightbox-img');
    const lbCounter   = document.getElementById('lightbox-counter');
    const lbClose     = document.getElementById('lightbox-close');
    const lbPrev      = document.getElementById('lightbox-prev');
    const lbNext      = document.getElementById('lightbox-next');
    const lbBackdrop  = document.getElementById('lightbox-backdrop');

    let lbThumbs = [];
    let lbIndex  = 0;

    function lbShow(index) {
        if (!lbThumbs.length) return;
        lbIndex = ((index % lbThumbs.length) + lbThumbs.length) % lbThumbs.length;

        const src = lbThumbs[lbIndex].dataset.full || lbThumbs[lbIndex].querySelector('img')?.src || '';

        lbImg.classList.add('is-loading');
        const tmp = new Image();
        tmp.onload = () => {
            lbImg.src = src;
            lbImg.alt = `Screenshot ${lbIndex + 1}`;
            lbImg.classList.remove('is-loading');
        };
        tmp.src = src;

        if (lbCounter) lbCounter.textContent = `${lbIndex + 1} / ${lbThumbs.length}`;
        if (lbPrev)    lbPrev.disabled = lbThumbs.length <= 1;
        if (lbNext)    lbNext.disabled = lbThumbs.length <= 1;

        openOverlay(lightbox);
        lbClose?.focus();
    }

    document.querySelectorAll('[data-lightbox-gallery]').forEach((galleryEl) => {
        galleryEl.addEventListener('click', (e) => {
            const btn = e.target.closest('.game-gallery__thumb');
            if (!btn) return;

            lbThumbs = Array.from(galleryEl.querySelectorAll('.game-gallery__thumb'));
            lbShow(parseInt(btn.dataset.index, 10) || 0);
        });
    });

    lbClose?.addEventListener('click',   () => closeOverlay(lightbox));
    lbBackdrop?.addEventListener('click', () => closeOverlay(lightbox));
    lbPrev?.addEventListener('click',    () => lbShow(lbIndex - 1));
    lbNext?.addEventListener('click',    () => lbShow(lbIndex + 1));

    // ─────────────────────────────────────────────────────────────
    //  Modale vidéo YouTube
    // ─────────────────────────────────────────────────────────────

    const videoModal   = document.getElementById('modal-video');
    const videoClose   = document.getElementById('modal-video-close');
    const videoBdrop   = document.getElementById('modal-video-backdrop');
    const videoWrap    = document.getElementById('video-embed-container');

    document.querySelectorAll('.game-video-card__thumb').forEach((btn) => {
        btn.addEventListener('click', () => {
            const ytId = btn.dataset.ytId;
            if (!ytId || !videoWrap) return;

            videoWrap.innerHTML = `
                <iframe
                    src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(ytId)}?autoplay=1&rel=0"
                    allow="autoplay; encrypted-media; fullscreen"
                    allowfullscreen
                    title="Vidéo YouTube">
                </iframe>`;
            openOverlay(videoModal);
        });
    });

    function closeVideoModal() {
        if (videoWrap) videoWrap.innerHTML = '';
        closeOverlay(videoModal);
    }

    videoClose?.addEventListener('click',  closeVideoModal);
    videoBdrop?.addEventListener('click',  closeVideoModal);

    // ─────────────────────────────────────────────────────────────
    //  Toggle wishlist
    // ─────────────────────────────────────────────────────────────

    const btnWishlist    = document.getElementById('btn-wishlist');
    const wishlistLabel  = btnWishlist?.querySelector('.wishlist-label');
    const wishlistIcon   = btnWishlist?.querySelector('.wishlist-icon');
    const wishlistCount  = document.getElementById('wishlist-count');

    if (btnWishlist && cfg.isLoggedIn) {
        let wishlisted = cfg.isWishlisted ?? false;
        let pending    = false;

        btnWishlist.addEventListener('click', async () => {
            if (pending) return;
            pending = true;
            btnWishlist.disabled = true;

            try {
                const res = await fetch('/api/wishlist/toggle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ game_id: cfg.gameId }),
                });

                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();

                wishlisted = data.wishlisted ?? !wishlisted;

                // Mise à jour UI
                btnWishlist.classList.toggle('is-active', wishlisted);
                if (wishlistIcon) wishlistIcon.setAttribute('fill', wishlisted ? 'currentColor' : 'none');
                if (wishlistLabel) wishlistLabel.textContent = wishlisted ? 'Dans ma wishlist' : "J'attends";

                if (wishlistCount !== null) {
                    const cur = parseInt(wishlistCount.textContent, 10) || 0;
                    const next = wishlisted ? cur + 1 : Math.max(0, cur - 1);
                    wishlistCount.textContent = next;
                    wishlistCount.style.display = next > 0 ? '' : 'none';
                }

            } catch (_) {
                // API non implémentée : feedback visuel temporaire uniquement
                wishlisted = !wishlisted;
                btnWishlist.classList.toggle('is-active', wishlisted);
                if (wishlistIcon) wishlistIcon.setAttribute('fill', wishlisted ? 'currentColor' : 'none');
                if (wishlistLabel) wishlistLabel.textContent = wishlisted ? 'Dans ma wishlist' : "J'attends";
            } finally {
                pending = false;
                btnWishlist.disabled = false;
            }
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  Modale "Ajouter à ma collection"
    // ─────────────────────────────────────────────────────────────

    const btnAddCollection  = document.getElementById('btn-add-collection');
    const modalCollection   = document.getElementById('modal-collection');
    const modalCollClose    = document.getElementById('modal-collection-close');
    const modalCollBackdrop = document.getElementById('modal-collection-backdrop');
    const platformList      = document.getElementById('modal-platform-list');

    btnAddCollection?.addEventListener('click', () => {
        if (btnAddCollection.disabled || cfg.collectionAddBlocked) return;
        openOverlay(modalCollection);
    });
    modalCollClose?.addEventListener('click',   () => closeOverlay(modalCollection));
    modalCollBackdrop?.addEventListener('click',() => closeOverlay(modalCollection));

    // Clic sur une plateforme → écriture localStorage + navigation vers /select
    platformList?.querySelectorAll('.modal__platform-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const platformId   = parseInt(btn.dataset.platformId, 10);
            const platformName = btn.dataset.platformName || btn.querySelector('.modal__platform-name')?.textContent?.trim() || '';

            if (!cfg.gameId || !platformId) return;

            const STORAGE_KEY = 'game_selection';

            // Lecture de la sélection existante
            let items = [];
            try { items = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); } catch { items = []; }

            // Vérification doublon (même jeu + même plateforme, region vide, physical par défaut)
            const already = items.some(i =>
                i.gameId === cfg.gameId &&
                i.platformId === platformId &&
                i.region === '' &&
                i.mediaType === 'physical'
            );

            if (!already) {
                items.push({
                    gameId:       cfg.gameId,
                    gameTitle:    cfg.gameTitle  || '',
                    gameCover:    cfg.gameCover  || '',
                    gameSlug:     cfg.gameSlug   || '',
                    platformId,
                    platformName,
                    region:       '',        // modifiable sur /select
                    mediaType:    'physical', // modifiable sur /select
                    addedAt:      new Date().toISOString(),
                });
                localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
                window.dispatchEvent(new CustomEvent('selection:updated', { detail: { count: items.length } }));
            }

            window.location.href = '/select';
        });
    });

    // ─────────────────────────────────────────────────────────────
    //  Clavier global
    // ─────────────────────────────────────────────────────────────

    document.addEventListener('keydown', (e) => {
        const lbOpen    = lightbox?.classList.contains('is-open');
        const modalOpen = modalCollection?.classList.contains('is-open')
                       || videoModal?.classList.contains('is-open');

        if (e.key === 'Escape') {
            if (lbOpen) {
                closeOverlay(lightbox);
            } else if (videoModal?.classList.contains('is-open')) {
                closeVideoModal();
            } else if (modalOpen) {
                closeAll();
            }
            return;
        }

        if (lbOpen) {
            if (e.key === 'ArrowLeft')  lbShow(lbIndex - 1);
            if (e.key === 'ArrowRight') lbShow(lbIndex + 1);
        }
    });

})();
