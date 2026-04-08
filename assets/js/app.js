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
