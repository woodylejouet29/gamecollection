<?php
$year = (int) date('Y');
?>

<footer class="site-footer" role="contentinfo">
    <div class="container site-footer__inner">

        <div class="site-footer__brand">
            <a href="/" class="site-footer__logo" aria-label="Accueil">
                <svg class="site-footer__logo-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M6 12h4m-2-2v4M15 11h.01M18 11h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <rect x="2" y="6" width="20" height="12" rx="3" stroke="currentColor" stroke-width="2"/>
                </svg>
                <span class="site-footer__logo-text">NomDuSite</span>
            </a>
            <p class="site-footer__tagline">Ta tagline ici</p>

            <div class="site-footer__social" aria-label="Réseaux sociaux">
                <a class="site-footer__social-link" href="#" aria-label="Twitter / X">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M18 2h3l-7 8 8 12h-6l-5-7-6 7H2l8-9L3 2h6l4 6 5-6Z" fill="currentColor"/>
                    </svg>
                </a>
                <a class="site-footer__social-link" href="#" aria-label="Facebook">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M14 9h3V6h-3c-2.21 0-4 1.79-4 4v3H7v3h3v8h3v-8h3l1-3h-4v-3c0-.55.45-1 1-1Z" fill="currentColor"/>
                    </svg>
                </a>
                <a class="site-footer__social-link" href="#" aria-label="Instagram">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Z" stroke="currentColor" stroke-width="2"/>
                        <path d="M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" stroke="currentColor" stroke-width="2"/>
                        <path d="M17.5 6.5h.01" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                </a>
            </div>
        </div>

        <div class="site-footer__cols" aria-label="Liens de pied de page">
            <details class="site-footer__col" open>
                <summary class="site-footer__col-title">Contact</summary>
                <div class="site-footer__col-body">
                    <a class="site-footer__link site-footer__link--btn" href="/contact">Contacter</a>
                    <a class="site-footer__link" href="#discord">Discord</a>
                </div>
            </details>

            <details class="site-footer__col" open>
                <summary class="site-footer__col-title">Support</summary>
                <div class="site-footer__col-body">
                    <span class="site-footer__link is-disabled" aria-disabled="true" tabindex="-1">FAQ (bientôt)</span>
                    <span class="site-footer__link is-disabled" aria-disabled="true" tabindex="-1">Signaler un bug (bientôt)</span>
                    <span class="site-footer__link is-disabled" aria-disabled="true" tabindex="-1">Aide (bientôt)</span>
                </div>
            </details>

            <details class="site-footer__col" open>
                <summary class="site-footer__col-title">Légal</summary>
                <div class="site-footer__col-body">
                    <a class="site-footer__link" href="/legal">Legal</a>
                    <a class="site-footer__link" href="/privacy">Privacy</a>
                    <a class="site-footer__link" href="/terms">Terms</a>
                    <a class="site-footer__link" href="/cookies">Cookies</a>
                </div>
            </details>
        </div>
    </div>

    <div class="site-footer__bar">
        <div class="container site-footer__bar-inner">
            <p class="site-footer__trust">
                Projet en développement — retours bienvenus.
            </p>
            <p class="site-footer__copy">© <?= $year ?> NomDuSite</p>
        </div>
    </div>

    <script>
        (() => {
            const closeFooterDetailsOnMobile = () => {
                if (!window.matchMedia || !window.matchMedia('(max-width: 900px)').matches) return;
                document.querySelectorAll('.site-footer__cols details.site-footer__col[open]')
                    .forEach((d) => d.removeAttribute('open'));
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', closeFooterDetailsOnMobile, { once: true });
            } else {
                closeFooterDetailsOnMobile();
            }
        })();
    </script>
</footer>
