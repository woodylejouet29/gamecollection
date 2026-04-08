// Bascule thème clair/sombre — sans flash (thème lu côté PHP via cookie)
function toggleTheme() {
    const html    = document.documentElement;
    const current = html.getAttribute('data-theme') || 'dark';
    const next    = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    document.cookie = `theme=${next};path=/;max-age=31536000;samesite=lax`;
}
