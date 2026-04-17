/**
 * app.js — Modules JavaScript transversaux
 *
 * Modules :
 *   - MobileNav    : tiroir latéral (drawer) + backdrop (≤768px)
 *   - ViewToggle   : basculement grille/cartes/liste + cookie
 *   - FilterPanel  : ouverture/fermeture du panneau de filtres
 *   - SelectionBar : barre sticky multi-sélection + badges header/bottom-nav
 *   - FlashClose   : fermeture des messages flash
 */

// ──────────────────────────────────────────────
//  MobileNav — tiroir latéral (slide-in droite)
// ──────────────────────────────────────────────

(function MobileNav() {
  const burger      = document.getElementById('header-burger');
  const drawer      = document.getElementById('header-drawer');
  const backdrop    = document.getElementById('header-backdrop');
  const closeBtn    = document.getElementById('header-drawer-close');
  const iconOpen    = document.getElementById('burger-icon-open');
  const iconClose   = document.getElementById('burger-icon-close');

  if (!burger || !drawer) return;

  // — Ouverture / fermeture ——————————————————
  burger.addEventListener('click', () => toggleDrawer());
  if (closeBtn)  closeBtn.addEventListener('click',   () => closeDrawer());
  if (backdrop)  backdrop.addEventListener('click',   () => closeDrawer());

  // Fermer sur clic d'un lien interne au drawer
  drawer.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => closeDrawer());
  });

  // Fermer sur Escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeDrawer();
  });

  // — Swipe gauche pour fermer ———————————————
  let touchStartX = 0;
  let touchStartY = 0;

  drawer.addEventListener('touchstart', e => {
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
  }, { passive: true });

  drawer.addEventListener('touchend', e => {
    const dx = e.changedTouches[0].clientX - touchStartX;
    const dy = Math.abs(e.changedTouches[0].clientY - touchStartY);
    if (dx > 60 && dy < 40) closeDrawer();
  }, { passive: true });

  // — Helpers ————————————————————————————————
  function toggleDrawer() {
    const isOpen = drawer.classList.contains('is-open');
    isOpen ? closeDrawer() : openDrawer();
  }

  function openDrawer() {
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    if (backdrop) backdrop.classList.add('is-open');
    burger.setAttribute('aria-expanded', 'true');
    if (iconOpen)  iconOpen.style.display  = 'none';
    if (iconClose) iconClose.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    if (backdrop) backdrop.classList.remove('is-open');
    burger.setAttribute('aria-expanded', 'false');
    if (iconOpen)  iconOpen.style.display  = 'flex';
    if (iconClose) iconClose.style.display = 'none';
    document.body.style.overflow = '';
  }
})();

// ──────────────────────────────────────────────
//  ViewToggle
// ──────────────────────────────────────────────

(function ViewToggle() {
  const MODES = ['grid', 'cards', 'list'];

  // Applique le mode initial (les boutons PHP ont déjà is-active via cookie, mais la grille doit être synchronisée)
  applyMode(getCookie('view_mode') || 'grid');

  // Délégation d'événements : fonctionne même après remplacement AJAX du contenu
  document.addEventListener('click', e => {
    const btn = e.target.closest('.view-toggle__btn');
    if (!btn) return;
    const mode = btn.dataset.view;
    if (!MODES.includes(mode)) return;
    applyMode(mode);
    setCookie('view_mode', mode, 365);
  });

  function applyMode(mode) {
    const grid = document.getElementById('results-grid');
    if (grid) {
      MODES.forEach(m => grid.classList.remove('game-grid--' + m));
      grid.classList.add('game-grid--' + mode);
    }
    document.querySelectorAll('.view-toggle__btn').forEach(b => {
      const active = b.dataset.view === mode;
      b.classList.toggle('is-active', active);
      b.setAttribute('aria-pressed', String(active));
    });
  }

  // Exposé pour que AjaxSearch puisse re-synchroniser après rechargement
  window._applyViewMode = applyMode;
})();

// ──────────────────────────────────────────────
//  FilterPanel
// ──────────────────────────────────────────────

(function FilterPanel() {
  const toggleBtn = document.getElementById('filters-toggle-btn');
  const panel     = document.getElementById('filters-panel');

  if (!toggleBtn || !panel) return;

  toggleBtn.addEventListener('click', () => {
    const open = panel.classList.toggle('is-open');
    toggleBtn.classList.toggle('is-active', open);
    toggleBtn.setAttribute('aria-expanded', String(open));
    panel.setAttribute('aria-hidden', String(!open));
  });

  // Fermer sur Escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && panel.classList.contains('is-open')) {
      panel.classList.remove('is-open');
      toggleBtn.classList.remove('is-active');
      toggleBtn.setAttribute('aria-expanded', 'false');
      panel.setAttribute('aria-hidden', 'true');
    }
  });
})();

// ──────────────────────────────────────────────
//  SelectionBar
// ──────────────────────────────────────────────

(function SelectionBar() {
  const bar        = document.getElementById('selection-bar');
  const countEl    = document.getElementById('selection-count');
  const labelEl    = document.getElementById('selection-label');
  const clearBtn   = document.getElementById('selection-clear');
  const badge      = document.getElementById('header-pending-badge');
  const badgeBottom = document.getElementById('bottom-pending-badge');

  if (!bar) return;

  // Écouter les checkboxes classiques (pages catalogue, collection, etc.)
  document.addEventListener('change', e => {
    if (e.target.matches('.game-select-cb')) update();
  });

  // Écouter les ajouts/suppressions depuis localStorage (page /search et autres)
  window.addEventListener('selection:updated', update);

  // Synchroniser au chargement (en cas de rechargement de page)
  update();

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      // Décocher les checkboxes visibles
      document.querySelectorAll('.game-select-cb:checked').forEach(cb => {
        cb.checked = false;
      });
      // Vider le localStorage
      try { localStorage.removeItem('game_selection'); } catch {}
      update();
      window.dispatchEvent(new CustomEvent('selection:updated', { detail: { count: 0 } }));
    });
  }

  function storageCount() {
    try { return JSON.parse(localStorage.getItem('game_selection') || '[]').length; }
    catch { return 0; }
  }

  function update() {
    const fromCheckboxes = document.querySelectorAll('.game-select-cb:checked').length;
    const fromStorage    = storageCount();
    const selected       = fromCheckboxes + fromStorage;
    const visible        = selected > 0;

    // Empêche toute apparition à 0 (et évite un flash avant le 1er update)
    bar.hidden = !visible;
    bar.classList.toggle('is-visible', visible);
    bar.setAttribute('aria-hidden', String(!visible));

    if (countEl) countEl.textContent = selected;
    if (labelEl) labelEl.textContent = selected <= 1 ? ' jeu sélectionné' : ' jeux sélectionnés';

    [badge, badgeBottom].forEach(b => {
      if (!b) return;
      if (selected > 0) {
        b.textContent   = Math.min(selected, 99);
        b.style.display = '';
      } else {
        b.style.display = 'none';
      }
    });
  }
})();

// ──────────────────────────────────────────────
//  FlashClose
// ──────────────────────────────────────────────

(function FlashClose() {
  document.addEventListener('click', e => {
    if (e.target.closest('.flash__close')) {
      e.target.closest('.flash')?.remove();
    }
  });

  // Fermeture automatique des flash "success" après 5s
  document.querySelectorAll('.flash--success').forEach(el => {
    setTimeout(() => el.remove(), 5000);
  });
})();

// ──────────────────────────────────────────────
//  WishlistToggle — badge flamme sur cartes
// ──────────────────────────────────────────────

(function WishlistToggle() {
  async function postJson(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    // Si la requête est redirigée (ex: vers /login), on force la navigation.
    if (res.redirected) {
      window.location.href = res.url;
      return null;
    }

    let data = null;
    try { data = await res.json(); } catch { /* noop */ }
    return { res, data };
  }

  function disableOwnedBadges(root, ownedIds) {
    if (!ownedIds || ownedIds.size === 0) return;
    const scope = root && root.querySelectorAll ? root : document;
    const btns = Array.from(scope.querySelectorAll('[data-action="wishlist-toggle"]'));
    btns.forEach(btn => {
      const gid = parseInt(btn.getAttribute('data-game-id') || '0', 10) || 0;
      if (!gid) return;
      if (!ownedIds.has(gid)) return;
      btn.classList.add('is-disabled');
      btn.classList.remove('is-active');
      btn.classList.remove('is-busy');
      btn.disabled = true;
      btn.setAttribute('aria-disabled', 'true');
      btn.setAttribute('aria-pressed', 'false');
      btn.setAttribute('title', 'Déjà dans ma collection');
      btn.setAttribute('aria-label', 'Déjà dans ma collection');
    });
  }

  async function syncWishlistBadges(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const btns = Array.from(scope.querySelectorAll('[data-action="wishlist-toggle"]'));
    if (btns.length === 0) return;

    const ids = Array.from(new Set(
      btns.map(b => parseInt(b.getAttribute('data-game-id') || '0', 10)).filter(Boolean)
    ));
    if (ids.length === 0) return;

    try {
      const [wishOut, ownedOut] = await Promise.all([
        postJson('/api/wishlist/check', { game_ids: ids }),
        postJson('/api/collection/check', { game_ids: ids }),
      ]);

      if (wishOut && wishOut.res.ok && wishOut.data && wishOut.data.success === true) {
        const wishlisted = new Set((wishOut.data.data && wishOut.data.data.game_ids) ? wishOut.data.data.game_ids.map(Number) : []);
        btns.forEach(btn => {
          const gid = parseInt(btn.getAttribute('data-game-id') || '0', 10) || 0;
          if (!gid) return;
          const active = wishlisted.has(gid);
          btn.classList.toggle('is-active', active);
          btn.setAttribute('aria-pressed', String(active));
          const title = active ? 'Retirer de ma wishlist' : 'Ajouter à ma wishlist';
          btn.setAttribute('title', title);
          btn.setAttribute('aria-label', title);
        });
      }

      if (ownedOut && ownedOut.res.ok && ownedOut.data && ownedOut.data.success === true) {
        const owned = new Set((ownedOut.data.data && ownedOut.data.data.game_ids) ? ownedOut.data.data.game_ids.map(Number) : []);
        disableOwnedBadges(root, owned);
      }
    } catch {
      // silent: si l'utilisateur n'est pas connecté ou en cas d'erreur réseau, on ne bloque pas l'UI
    }
  }

  // Sync initial + après rechargements AJAX (search)
  document.addEventListener('DOMContentLoaded', () => { syncWishlistBadges(document); });
  window.addEventListener('wishlist:sync', (e) => { syncWishlistBadges(e?.detail?.root); });

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-action="wishlist-toggle"]');
    if (!btn) return;

    const rawId = btn.getAttribute('data-game-id') || btn.closest('[data-game-id]')?.getAttribute('data-game-id') || '0';
    const gameId = parseInt(rawId, 10) || 0;
    if (!gameId) return;

    if (btn.disabled) return;
    btn.disabled = true;
    btn.classList.add('is-busy');

    try {
      const out = await postJson('/api/wishlist/toggle', { game_id: gameId });
      if (!out) return;

      const { res, data } = out;
      if (!res.ok || !data || data.success !== true) {
        // Non authentifié : l'API peut répondre 302/HTML ou 401/JSON
        if (res.status === 401) window.location.href = '/login';
        throw new Error((data && data.error && data.error.message) ? data.error.message : 'Impossible de mettre à jour la wishlist.');
      }

      const action = data.data && data.data.action ? data.data.action : null; // added | removed
      const wishlisted = action === 'added'
        ? true
        : action === 'removed'
          ? false
          : !btn.classList.contains('is-active');

      btn.classList.toggle('is-active', wishlisted);
      btn.setAttribute('aria-pressed', String(wishlisted));

      const title = wishlisted ? 'Retirer de ma wishlist' : 'Ajouter à ma wishlist';
      btn.setAttribute('title', title);
      btn.setAttribute('aria-label', title);
    } catch (err) {
      alert(err && err.message ? err.message : 'Erreur.');
    } finally {
      btn.disabled = false;
      btn.classList.remove('is-busy');
    }
  });
})();

// ──────────────────────────────────────────────
//  HeaderProfileMenu — dropdown "Mon profil"
// ──────────────────────────────────────────────

(function HeaderProfileMenu() {
  const menu = document.getElementById('header-profile-menu');
  if (!menu) return;

  // Fermer au clic extérieur
  document.addEventListener('click', (e) => {
    if (!menu.open) return;
    if (e.target.closest('#header-profile-menu')) return;
    menu.open = false;
  });

  // Fermer sur Escape (sans interférer avec le drawer)
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && menu.open) {
      menu.open = false;
    }
  });

  // Fermer après clic sur un item
  menu.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => { menu.open = false; });
  });
})();

// ──────────────────────────────────────────────
//  Utilitaires cookies
// ──────────────────────────────────────────────

function getCookie(name) {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
  return match ? decodeURIComponent(match[1]) : null;
}

function setCookie(name, value, days) {
  const max = days * 86400;
  document.cookie = `${name}=${encodeURIComponent(value)};path=/;max-age=${max};samesite=lax`;
}
