<?php
use App\Core\Csrf;
use App\Core\Flash;
$u = $user ?? [];
?>
<section class="profile-page">
    <div class="profile-header">
        <div class="profile-header__avatar">
            <?php if (!empty($u['avatar_url'])): ?>
                <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="Avatar" class="profile-avatar">
            <?php else: ?>
                <span class="profile-avatar-placeholder">
                    <?= strtoupper(substr($u['username'] ?? 'U', 0, 1)) ?>
                </span>
            <?php endif; ?>
        </div>
        <div>
            <h1 class="profile-header__name"><?= htmlspecialchars($u['username'] ?? '') ?></h1>
            <p class="profile-header__email"><?= htmlspecialchars($u['email'] ?? '') ?></p>
        </div>
    </div>

    <div class="profile-sections">

        <!-- ── Avatar ── -->
        <div class="profile-section">
            <h2 class="profile-section__title">Photo de profil</h2>
            <form action="/edit-profile" method="POST" enctype="multipart/form-data" class="profile-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="avatar">

                <div class="avatar-update-row">
                    <div class="avatar-tabs" role="tablist">
                        <button type="button" class="avatar-tab is-active" data-target="av-upload" role="tab">Uploader</button>
                        <button type="button" class="avatar-tab" data-target="av-url" role="tab">Par URL</button>
                    </div>
                    <div id="av-upload" class="avatar-pane">
                        <label class="file-btn">
                            <input type="file" name="avatar" accept="image/*" class="sr-only">
                            Choisir une image
                        </label>
                        <small class="form-hint">JPG, PNG, WebP — max 2 Mo</small>
                    </div>
                    <div id="av-url" class="avatar-pane" hidden>
                        <input type="url" name="avatar_url" class="form-input" placeholder="https://…">
                    </div>
                </div>

                <button type="submit" class="btn btn--primary btn--sm">Mettre à jour l'avatar</button>
            </form>
        </div>

        <!-- ── Pseudo ── -->
        <div class="profile-section">
            <h2 class="profile-section__title">
                Pseudo
                <span class="rate-badge">1×/heure</span>
            </h2>
            <form action="/edit-profile" method="POST" class="profile-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="username">
                <div class="form-row">
                    <div class="form-group">
                        <input type="text" name="username" class="form-input"
                               value="<?= htmlspecialchars($u['username'] ?? '') ?>"
                               placeholder="Nouveau pseudo" autocomplete="off" required>
                        <p class="form-hint">3–30 caractères : lettres, chiffres, _ et -</p>
                    </div>
                    <button type="submit" class="btn btn--primary btn--sm">Modifier</button>
                </div>
            </form>
        </div>

        <!-- ── Email ── -->
        <div class="profile-section">
            <h2 class="profile-section__title">
                Adresse e-mail
                <span class="rate-badge">1×/heure</span>
            </h2>
            <form action="/edit-profile" method="POST" class="profile-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="email">
                <div class="form-row">
                    <div class="form-group">
                        <input type="email" name="email" class="form-input"
                               value="<?= htmlspecialchars($u['email'] ?? '') ?>"
                               placeholder="Nouvel e-mail" autocomplete="email" required>
                    </div>
                    <button type="submit" class="btn btn--primary btn--sm">Modifier</button>
                </div>
            </form>
        </div>

        <!-- ── Mot de passe ── -->
        <div class="profile-section">
            <h2 class="profile-section__title">
                Mot de passe
                <span class="rate-badge">1×/heure</span>
            </h2>
            <form action="/edit-profile" method="POST" class="profile-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="password">

                <div class="form-group">
                    <label for="new_password" class="form-label">Nouveau mot de passe</label>
                    <div class="input-wrapper">
                        <input type="password" id="new_password" name="new_password" class="form-input"
                               placeholder="8+ car., 1 chiffre, 1 spécial" autocomplete="new-password" required>
                        <button type="button" class="input-eye" onclick="togglePwd('new_password')" aria-label="Afficher">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirmation</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                               placeholder="Répétez le nouveau mot de passe" autocomplete="new-password" required>
                        <button type="button" class="input-eye" onclick="togglePwd('confirm_password')" aria-label="Afficher">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn--primary btn--sm">Changer le mot de passe</button>
            </form>
        </div>

    </div>
</section>

<script>
function togglePwd(id) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}
document.querySelectorAll('.avatar-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        btn.closest('.profile-section, .auth-card').querySelectorAll('.avatar-tab').forEach(b => b.classList.remove('is-active'));
        btn.closest('.profile-section, .auth-card').querySelectorAll('.avatar-pane').forEach(p => p.hidden = true);
        btn.classList.add('is-active');
        const target = document.getElementById(btn.dataset.target);
        if (target) target.hidden = false;
    });
});
</script>
