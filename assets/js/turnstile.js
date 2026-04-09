/**
 * Cloudflare Turnstile — rendu explicite + garde à la soumission.
 * Évite le double chargement de api.js (déjà inclus en defer dans le <head> des pages auth).
 */
(function () {
    'use strict';

    const mount = document.querySelector('.turnstile-mount[data-sitekey]');
    if (!mount) return;

    const sitekey = mount.dataset.sitekey;
    if (!sitekey) return;

    const form = mount.closest('form');
    if (!form) return;

    const theme = mount.dataset.theme === 'light' ? 'light' : 'dark';
    const submitBtn = form.querySelector('button[type="submit"]');

    let widgetId = null;
    let errEl = null;

    function showMountError(msg) {
        if (!errEl) {
            errEl = document.createElement('p');
            errEl.className = 'form-error';
            errEl.setAttribute('role', 'alert');
            mount.insertAdjacentElement('afterend', errEl);
        }
        errEl.textContent = msg;
        errEl.hidden = false;
    }

    function clearMountError() {
        if (errEl) errEl.hidden = true;
    }

    function setSubmitEnabled(on) {
        if (submitBtn) submitBtn.disabled = !on;
    }

    function renderWidget() {
        if (typeof window.turnstile === 'undefined' || typeof window.turnstile.render !== 'function') {
            return false;
        }

        mount.innerHTML = '';
        clearMountError();

        try {
            widgetId = window.turnstile.render(mount, {
                sitekey,
                theme,
                language: 'fr',
                appearance: 'always',
                callback: function () {
                    clearMountError();
                    setSubmitEnabled(true);
                },
                'expired-callback': function () {
                    setSubmitEnabled(false);
                    showMountError('La vérification a expiré. Cochez à nouveau la case ci-dessus.');
                },
                'error-callback': function () {
                    setSubmitEnabled(false);
                    showMountError(
                        'Impossible d’afficher la vérification anti-bot. ' +
                            'Désactivez un bloqueur de contenu pour ce site ou essayez un autre navigateur.'
                    );
                },
            });
        } catch (e) {
            showMountError('Erreur lors du chargement de la vérification. Rechargez la page.');
            return false;
        }

        return true;
    }

    function waitForApi() {
        let n = 0;
        const t = setInterval(function () {
            n++;
            if (typeof window.turnstile !== 'undefined' && typeof window.turnstile.render === 'function') {
                clearInterval(t);
                setSubmitEnabled(false);
                if (!renderWidget()) {
                    setSubmitEnabled(true);
                }
            } else if (n > 120) {
                clearInterval(t);
                showMountError(
                    'Le script de vérification n’a pas pu se charger. Vérifiez votre connexion ou un bloqueur (uBlock, Brave, etc.).'
                );
                setSubmitEnabled(true);
            }
        }, 50);
    }

    form.addEventListener('submit', function (e) {
        if (widgetId === null) {
            e.preventDefault();
            showMountError('Veuillez patienter jusqu’à ce que la vérification anti-bot soit prête.');
            return;
        }
        const token = window.turnstile.getResponse(widgetId);
        if (!token) {
            e.preventDefault();
            showMountError('Veuillez valider la vérification anti-bot avant de continuer.');
        }
    });

    setSubmitEnabled(false);
    if (!renderWidget()) {
        waitForApi();
    }
})();
