/**
 * home.js — Scripts spécifiques à la page d'accueil
 *
 * Modules :
 *   - ScrollRows   : boutons prev/next sur les lignes de jeux horizontales
 *   - GenreTabs    : onglets de genre (affiche/cache les panneaux)
 *   - StatCounter  : animation de comptage des chiffres globaux
 */

// ──────────────────────────────────────────────
//  ScrollRows — navigation prev/next
// ──────────────────────────────────────────────

(function ScrollRows() {
  document.querySelectorAll('.games-scroll-wrapper').forEach(wrapper => {
    const scroll = wrapper.querySelector('.games-scroll');
    const prev   = wrapper.querySelector('.games-scroll-nav--prev');
    const next   = wrapper.querySelector('.games-scroll-nav--next');

    if (!scroll) return;

    const STEP = 520; // pixels défilés par clic

    function updateNav() {
      if (!prev || !next) return;
      prev.classList.toggle('is-hidden', scroll.scrollLeft <= 4);
      next.classList.toggle('is-hidden',
        scroll.scrollLeft + scroll.clientWidth >= scroll.scrollWidth - 4);
    }

    if (prev) prev.addEventListener('click', () => {
      scroll.scrollBy({ left: -STEP, behavior: 'smooth' });
    });
    if (next) next.addEventListener('click', () => {
      scroll.scrollBy({ left: STEP, behavior: 'smooth' });
    });

    scroll.addEventListener('scroll', updateNav, { passive: true });
    updateNav(); // état initial
  });
})();

// ──────────────────────────────────────────────
//  GenreTabs
// ──────────────────────────────────────────────

(function GenreTabs() {
  const container = document.querySelector('.genre-section');
  if (!container) return;

  const tabs   = container.querySelectorAll('.genre-tab');
  const panels = container.querySelectorAll('.genre-panel');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.target;

      tabs.forEach(t => t.classList.remove('is-active'));
      panels.forEach(p => p.classList.remove('is-active'));

      tab.classList.add('is-active');
      const panel = container.querySelector(`#${CSS.escape(target)}`);
      if (panel) panel.classList.add('is-active');
    });
  });
})();

// ──────────────────────────────────────────────
//  StatCounter — animation comptage
// ──────────────────────────────────────────────

(function StatCounter() {
  const elements = document.querySelectorAll('.stat-num[data-count]');
  if (!elements.length || !('IntersectionObserver' in window)) return;

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      observer.unobserve(entry.target);
      countUp(entry.target);
    });
  }, { threshold: 0.5 });

  elements.forEach(el => observer.observe(el));

  function countUp(el) {
    const target  = parseInt(el.dataset.count, 10) || 0;
    const duration = 1200;
    const start    = performance.now();
    const from     = 0;

    function step(now) {
      const elapsed  = now - start;
      const progress = Math.min(elapsed / duration, 1);
      const ease     = 1 - Math.pow(1 - progress, 3); // ease-out cubic
      const current  = Math.round(from + (target - from) * ease);

      el.textContent = target >= 10000
        ? current.toLocaleString('fr-FR')
        : current.toString();

      if (progress < 1) requestAnimationFrame(step);
    }

    requestAnimationFrame(step);
  }
})();
