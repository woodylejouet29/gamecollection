/**
 * Règle alignée sur CollectionReleasePolicy (serveur) : ajout collection à partir de J-7 avant la sortie.
 * Fuseau : navigateur (minuit local), cohérent pour les utilisateurs FR.
 */
(function (global) {
    'use strict';

    var DAYS_BEFORE = 7;

    function parseLocalDate(ymd) {
        if (ymd == null) return null;
        var s = String(ymd).trim();
        if (s === '') return null;
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
        if (!m) return null;
        var y = +m[1], mo = +m[2] - 1, d = +m[3];
        var dt = new Date(y, mo, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo || dt.getDate() !== d) return null;
        return dt;
    }

    function startOfTodayLocal() {
        var n = new Date();
        return new Date(n.getFullYear(), n.getMonth(), n.getDate());
    }

    function formatFr(d) {
        return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });
    }

    /**
     * @param {string|null|undefined} releaseDateYmd
     * @returns {{ ok: true } | { ok: false, code: string, message: string, opensAt?: Date }}
     */
    function check(releaseDateYmd) {
        var today = startOfTodayLocal();
        if (releaseDateYmd == null || String(releaseDateYmd).trim() === '') {
            return {
                ok: false,
                code: 'RELEASE_DATE_UNKNOWN',
                message: 'Impossible d\'ajouter ce jeu à la collection sans date de sortie connue.',
            };
        }
        var release = parseLocalDate(releaseDateYmd);
        if (!release) {
            return {
                ok: false,
                code: 'RELEASE_DATE_INVALID',
                message: 'Date de sortie invalide pour ce jeu.',
            };
        }
        var windowStart = new Date(release.getTime());
        windowStart.setDate(windowStart.getDate() - DAYS_BEFORE);
        if (today < windowStart) {
            return {
                ok: false,
                code: 'COLLECTION_TOO_EARLY',
                message: 'Vous pourrez ajouter ce jeu à votre collection à partir du '
                    + formatFr(windowStart) + ' (7 jours avant sa sortie).',
                opensAt: windowStart,
            };
        }
        return { ok: true };
    }

    global.collectionReleaseGate = {
        DAYS_BEFORE: DAYS_BEFORE,
        check: check,
        formatFr: formatFr,
    };
})(typeof window !== 'undefined' ? window : globalThis);
