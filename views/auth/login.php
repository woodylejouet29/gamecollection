<?php
use App\Core\Csrf;
use App\Core\Flash;
?>
<section class="auth-page">
    <div class="auth-card auth-card--narrow">

        <div class="auth-card__header">
            <h1 class="auth-card__title">Connexion</h1>
            <p class="auth-card__sub">Bon retour parmi nous</p>
        </div>

        <form action="/login" method="POST" class="auth-form" novalidate>
            <?= Csrf::field() ?>

            <div class="form-group">
                <label for="email" class="form-label">Adresse e-mail</label>
                <input type="email" id="email" name="email" autocomplete="email" required autofocus
                       class="form-input"
                       placeholder="vous@exemple.fr"
                       value="<?= Flash::old('email') ?>">
            </div>

            <div class="form-group">
                <div class="form-label-row">
                    <label for="password" class="form-label">Mot de passe</label>
                </div>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" autocomplete="current-password" required
                           class="form-input"
                           placeholder="Votre mot de passe">
                    <button type="button" class="input-eye" onclick="togglePwd('password')" aria-label="Afficher">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <label class="checkbox-label">
                <input type="checkbox" name="remember" value="1">
                <span class="checkbox-custom"></span>
                Rester connecté <span class="form-hint">(30 jours)</span>
            </label>

            <!-- Turnstile -->
            <?php if (!empty($_ENV['TURNSTILE_SITE_KEY'])): ?>
            <div class="form-group form-group--center">
                <div class="cf-turnstile"
                     data-sitekey="<?= htmlspecialchars($_ENV['TURNSTILE_SITE_KEY']) ?>"
                     data-theme="<?= ($_COOKIE['theme'] ?? 'dark') === 'light' ? 'light' : 'dark' ?>">
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn--primary btn--full">Se connecter</button>

            <p class="auth-form__footer">
                Pas encore inscrit ? <a href="/register" class="link">Créer un compte</a>
            </p>
        </form>
    </div>
</section>

<script>
function togglePwd(id) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}
</script>
