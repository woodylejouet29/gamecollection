<?php
$year = (int) date('Y');
?>

<footer class="site-footer" role="contentinfo">
    <div class="container site-footer__inner">

        <div class="site-footer__top">
            <a href="/" class="site-footer__logo" aria-label="Accueil">
                <img
                    class="site-footer__logo-img site-footer__logo-img--desktop-dark"
                    src="/images/logositeblanc.png"
                    alt="PlayShelf"
                    width="140"
                    height="36"
                    loading="lazy"
                    decoding="async"
                >
                <img
                    class="site-footer__logo-img site-footer__logo-img--desktop-light"
                    src="/images/logositenoir.png"
                    alt="PlayShelf"
                    width="140"
                    height="36"
                    loading="lazy"
                    decoding="async"
                >
                <img
                    class="site-footer__logo-img site-footer__logo-img--mobile-dark"
                    src="/images/logomobileblanc.png"
                    alt="PlayShelf"
                    width="36"
                    height="36"
                    loading="lazy"
                    decoding="async"
                >
                <img
                    class="site-footer__logo-img site-footer__logo-img--mobile-light"
                    src="/images/logomobilenoir.png"
                    alt="PlayShelf"
                    width="36"
                    height="36"
                    loading="lazy"
                    decoding="async"
                >
            </a>

            <nav class="site-footer__social" aria-label="Réseaux sociaux">
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
            </nav>
        </div>

        <nav aria-label="Liens de pied de page">
            <ul class="site-footer__links">
                <li><a class="site-footer__link" href="/contact">Contacter</a></li>
                <li aria-hidden="true" class="site-footer__links-sep">·</li>
                <li><a class="site-footer__link" href="#discord">Discord</a></li>
                <li aria-hidden="true" class="site-footer__links-sep">·</li>
                <li><span class="site-footer__link is-disabled" aria-disabled="true" tabindex="-1">FAQ (bientôt)</span></li>
                <li aria-hidden="true" class="site-footer__links-sep">·</li>
                <li><span class="site-footer__link is-disabled" aria-disabled="true" tabindex="-1">Signaler un bug (bientôt)</span></li>
                <li aria-hidden="true" class="site-footer__links-sep">·</li>
                <li><a class="site-footer__link" href="/legal">Legal</a></li>
                <li aria-hidden="true" class="site-footer__links-sep">·</li>
                <li><a class="site-footer__link" href="/privacy">Privacy</a></li>
                <li aria-hidden="true" class="site-footer__links-sep">·</li>
                <li><a class="site-footer__link" href="/terms">Terms</a></li>
                <li aria-hidden="true" class="site-footer__links-sep">·</li>
                <li><a class="site-footer__link" href="/cookies">Cookies</a></li>
            </ul>
        </nav>

        <div class="site-footer__bar">
            <p>Projet en développement — retours bienvenus.</p>
            <p>© <?= $year ?> PlayShelf</p>
        </div>

    </div>
</footer>