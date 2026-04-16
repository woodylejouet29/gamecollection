<?php
use App\Core\Csrf;
use App\Core\Flash;
$u = $user ?? [];
?>
<section class="profile-edit">
    <div class="container">
    <!-- En-tête du profil -->
    <div class="profile-header">
        <div class="profile-header__avatar-container">
            <div class="profile-header__avatar">
                <?php if (!empty($u['avatar_url'])): ?>
                    <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="Avatar de <?= htmlspecialchars($u['username'] ?? '') ?>" class="profile-header__avatar-img" id="avatar-preview">
                <?php else: ?>
                    <div class="profile-header__avatar-placeholder" id="avatar-preview">
                        <?= strtoupper(substr($u['username'] ?? 'U', 0, 1)) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="profile-header__info">
            <h1 class="profile-header__name"><?= htmlspecialchars($u['username'] ?? '') ?></h1>
            <p class="profile-header__email"><?= htmlspecialchars($u['email'] ?? '') ?></p>
        </div>
    </div>

    <!-- Navigation par onglets -->
    <nav class="profile-tabs" role="tablist" aria-label="Sections du profil">
        <button type="button" class="profile-tab profile-tab--active" data-tab="account" role="tab" aria-selected="true" id="tab-account">
            <span class="profile-tab__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </span>
            <span class="profile-tab__label">Compte</span>
        </button>
        <button type="button" class="profile-tab" data-tab="settings" role="tab" aria-selected="false" id="tab-settings">
            <span class="profile-tab__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </span>
            <span class="profile-tab__label">Paramètres</span>
        </button>
        <div class="profile-tabs__indicator" aria-hidden="true"></div>
    </nav>

    <!-- Contenu des onglets -->
    <div class="profile-content">
        <!-- Onglet Compte -->
        <div class="profile-panel profile-panel--active" data-panel="account" role="tabpanel" aria-labelledby="tab-account">
            <div class="profile-grid">
                <!-- Colonne gauche -->
                <div class="profile-col">
                    <!-- Section Avatar -->
                    <div class="profile-card">
                        <div class="profile-card__header">
                            <h2 class="profile-card__title">Photo de profil</h2>
                            <div class="profile-card__preview">
                                <div class="avatar-preview" id="avatar-update-preview">
                                    <?php if (!empty($u['avatar_url'])): ?>
                                        <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="Aperçu de l'avatar" class="avatar-preview__img">
                                    <?php else: ?>
                                        <div class="avatar-preview__placeholder">
                                            <?= strtoupper(substr($u['username'] ?? 'U', 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <form action="/edit-profile" method="POST" enctype="multipart/form-data" class="profile-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="avatar">

                            <div class="form-tabs" role="tablist" aria-label="Méthode de mise à jour de l'avatar">
                                <button type="button" class="form-tab form-tab--active" data-tab="upload" role="tab" aria-selected="true">
                                    Uploader
                                </button>
                                <button type="button" class="form-tab" data-tab="url" role="tab" aria-selected="false">
                                    Par URL
                                </button>
                            </div>

                            <div class="form-tab-content form-tab-content--active" data-tab="upload" role="tabpanel">
                                <div class="form-group">
                                    <label for="avatar-upload" class="file-upload-label">
                                        <span class="file-upload-label__icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                <polyline points="17 8 12 3 7 8"/>
                                                <line x1="12" y1="3" x2="12" y2="15"/>
                                            </svg>
                                        </span>
                                        <span class="file-upload-label__text">Choisir une image</span>
                                        <input type="file" id="avatar-upload" name="avatar" accept="image/*" class="file-upload-input">
                                    </label>
                                    <div class="form-hint" id="avatar-file-info">Aucun fichier sélectionné</div>
                                    <div class="form-hint">JPG, PNG, WebP — max 2 Mo</div>
                                </div>
                            </div>

                            <div class="form-tab-content" data-tab="url" role="tabpanel" hidden>
                                <div class="form-group">
                                    <label for="avatar-url" class="form-label">URL de l'image</label>
                                    <input type="url" id="avatar-url" name="avatar_url" class="form-input" 
                                           placeholder="https://exemple.com/avatar.jpg" autocomplete="off">
                                    <div class="form-hint">Entrez l'URL complète de votre image</div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary">Mettre à jour l'avatar</button>
                            </div>
                        </form>
                    </div>

                    <!-- Section Pseudo -->
                    <div class="profile-card">
                        <div class="profile-card__header">
                            <h2 class="profile-card__title">
                                Pseudo
                                <span class="rate-badge" data-tooltip="Modification limitée à 1 fois par heure">1×/heure</span>
                            </h2>
                        </div>
                        <form action="/edit-profile" method="POST" class="profile-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="username">
                            
                            <div class="form-group">
                                <label for="username" class="form-label">Nouveau pseudo</label>
                                <input type="text" id="username" name="username" class="form-input"
                                       value="<?= htmlspecialchars($u['username'] ?? '') ?>"
                                       placeholder="Votre nouveau pseudo" autocomplete="username" required
                                       data-validation="username">
                                <div class="form-feedback" id="username-feedback"></div>
                                <div class="form-hint">3–30 caractères : lettres, chiffres, _ et -</div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary">Modifier le pseudo</button>
                            </div>
                        </form>
                    </div>
                </div>

            <!-- Colonne droite -->
                <div class="profile-col">
                    <!-- Section Email -->
                    <div class="profile-card">
                        <div class="profile-card__header">
                            <h2 class="profile-card__title">
                                Adresse e-mail
                                <span class="rate-badge" data-tooltip="Modification limitée à 1 fois par heure">1×/heure</span>
                            </h2>
                        </div>
                        <form action="/edit-profile" method="POST" class="profile-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="email">
                            
                            <div class="form-group">
                                <label for="email" class="form-label">Nouvel e-mail</label>
                                <input type="email" id="email" name="email" class="form-input"
                                       value="<?= htmlspecialchars($u['email'] ?? '') ?>"
                                       placeholder="nouveau@exemple.com" autocomplete="email" required
                                       data-validation="email">
                                <div class="form-feedback" id="email-feedback"></div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary">Modifier l'e-mail</button>
                            </div>
                        </form>
                    </div>

                    <!-- Section Mot de passe -->
                    <div class="profile-card">
                        <div class="profile-card__header">
                            <h2 class="profile-card__title">
                                Mot de passe
                                <span class="rate-badge" data-tooltip="Modification limitée à 1 fois par heure">1×/heure</span>
                            </h2>
                        </div>
                        <form action="/edit-profile" method="POST" class="profile-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="password">
                            
                            <div class="form-group">
                                <label for="new-password" class="form-label">Nouveau mot de passe</label>
                                <div class="input-with-action">
                                    <input type="password" id="new-password" name="new_password" class="form-input"
                                           placeholder="8+ caractères, 1 chiffre, 1 spécial" 
                                           autocomplete="new-password" required
                                           data-validation="password">
                                    <button type="button" class="input-action" data-toggle-password="new-password" aria-label="Afficher le mot de passe">
                                        <svg class="input-action__icon input-action__icon--eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        <svg class="input-action__icon input-action__icon--eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" hidden>
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                            <line x1="1" y1="1" x2="23" y2="23"/>
                                        </svg>
                                    </button>
                                </div>
                                <div class="form-feedback" id="password-feedback"></div>
                                <div class="password-strength">
                                    <div class="password-strength__bar">
                                        <div class="password-strength__fill" id="password-strength-fill"></div>
                                    </div>
                                    <div class="password-strength__label" id="password-strength-label">Faible</div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm-password" class="form-label">Confirmer le mot de passe</label>
                                <div class="input-with-action">
                                    <input type="password" id="confirm-password" name="confirm_password" class="form-input"
                                           placeholder="Répétez le nouveau mot de passe" 
                                           autocomplete="new-password" required>
                                    <button type="button" class="input-action" data-toggle-password="confirm-password" aria-label="Afficher le mot de passe">
                                        <svg class="input-action__icon input-action__icon--eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        <svg class="input-action__icon input-action__icon--eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" hidden>
                                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                                            <line x1="1" y1="1" x2="23" y2="23"/>
                                        </svg>
                                    </button>
                                </div>
                                <div class="form-feedback" id="confirm-password-feedback"></div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary">Changer le mot de passe</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <!-- Onglet Paramètres -->
        <div class="profile-panel" data-panel="settings" role="tabpanel" aria-labelledby="tab-settings" hidden>
            <div class="profile-settings">
                <!-- Section Visibilité collection -->
                <div class="profile-card">
                    <div class="profile-card__header">
                        <h2 class="profile-card__title">Visibilité de la collection</h2>
                        <p class="profile-card__subtitle">Contrôlez qui peut voir votre collection de jeux</p>
                    </div>
                    
                    <form action="/edit-profile" method="POST" class="profile-form">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="settings">

                        <?php $isPublic = !empty($u['collection_public']); ?>

                        <div class="settings-options">
                            <label class="settings-option">
                                <input type="radio" name="collection_public" value="0" <?= !$isPublic ? 'checked' : '' ?> class="settings-option__input">
                                <div class="settings-option__card">
                                    <div class="settings-option__icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                                            <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                                            <line x1="12" y1="19" x2="12" y2="23"/>
                                            <line x1="8" y1="23" x2="16" y2="23"/>
                                        </svg>
                                    </div>
                                    <div class="settings-option__content">
                                        <h3 class="settings-option__title">Privée</h3>
                                        <p class="settings-option__description">
                                            Seul vous pouvez voir votre collection sur votre page publique. 
                                            Vos statistiques et jeux ne sont pas visibles par les autres membres.
                                        </p>
                                    </div>
                                    <div class="settings-option__check">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                    </div>
                                </div>
                            </label>

                            <label class="settings-option">
                                <input type="radio" name="collection_public" value="1" <?= $isPublic ? 'checked' : '' ?> class="settings-option__input">
                                <div class="settings-option__card">
                                    <div class="settings-option__icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                            <circle cx="9" cy="7" r="4"/>
                                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                        </svg>
                                    </div>
                                    <div class="settings-option__content">
                                        <h3 class="settings-option__title">Publique</h3>
                                        <p class="settings-option__description">
                                            Les autres membres peuvent voir votre collection et vos statistiques. 
                                            Partagez vos jeux préférés et découvrez des membres avec des goûts similaires.
                                        </p>
                                    </div>
                                    <div class="settings-option__check">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn--primary">Enregistrer les paramètres</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
// Gestion des onglets principaux (Compte / Paramètres)
document.addEventListener('DOMContentLoaded', function() {
    // Sélection des éléments
    const mainTabs = document.querySelectorAll('.profile-tab');
    const mainPanels = document.querySelectorAll('.profile-panel');
    const tabIndicator = document.querySelector('.profile-tabs__indicator');
    
    // Onglets d'avatar (Upload/URL)
    const avatarTabs = document.querySelectorAll('.form-tab');
    const avatarTabContents = document.querySelectorAll('.form-tab-content');
    
    // Gestionnaire pour les onglets principaux
    function switchMainTab(selectedTab) {
        // Mettre à jour l'état des onglets
        mainTabs.forEach(tab => {
            const isActive = tab === selectedTab;
            tab.classList.toggle('profile-tab--active', isActive);
            tab.setAttribute('aria-selected', isActive);
        });
        
        // Mettre à jour l'indicateur visuel
        if (tabIndicator) {
            const tabRect = selectedTab.getBoundingClientRect();
            const containerRect = selectedTab.closest('.profile-tabs').getBoundingClientRect();
            tabIndicator.style.width = `${tabRect.width}px`;
            tabIndicator.style.transform = `translateX(${tabRect.left - containerRect.left}px)`;
        }
        
        // Afficher/masquer les panels
        const targetPanel = selectedTab.dataset.tab;
        mainPanels.forEach(panel => {
            const isTarget = panel.dataset.panel === targetPanel;
            panel.classList.toggle('profile-panel--active', isTarget);
            panel.hidden = !isTarget;
        });
    }
    
    // Gestionnaire pour les onglets d'avatar
    function switchAvatarTab(selectedTab) {
        avatarTabs.forEach(tab => {
            const isActive = tab === selectedTab;
            tab.classList.toggle('form-tab--active', isActive);
            tab.setAttribute('aria-selected', isActive);
        });
        
        const targetTab = selectedTab.dataset.tab;
        avatarTabContents.forEach(content => {
            const isTarget = content.dataset.tab === targetTab;
            content.classList.toggle('form-tab-content--active', isTarget);
            content.hidden = !isTarget;
        });
    }
    
    // Écouteurs pour les onglets principaux
    mainTabs.forEach(tab => {
        tab.addEventListener('click', () => switchMainTab(tab));
    });
    
    // Écouteurs pour les onglets d'avatar
    avatarTabs.forEach(tab => {
        tab.addEventListener('click', () => switchAvatarTab(tab));
    });
    
    // Initialiser l'indicateur sur le premier onglet actif
    const activeMainTab = document.querySelector('.profile-tab--active');
    if (activeMainTab && tabIndicator) {
        setTimeout(() => switchMainTab(activeMainTab), 50);
    }
    
    // Gestion de l'upload d'avatar
    const avatarUpload = document.getElementById('avatar-upload');
    const avatarUrlInput = document.getElementById('avatar-url');
    const avatarPreview = document.getElementById('avatar-update-preview');
    const avatarFileInfo = document.getElementById('avatar-file-info');
    
    if (avatarUpload) {
        avatarUpload.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            // Mettre à jour les informations du fichier
            avatarFileInfo.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} Mo)`;
            
            // Vérifier la taille
            if (file.size > 2 * 1024 * 1024) {
                avatarFileInfo.textContent = 'Fichier trop volumineux (max 2 Mo)';
                avatarFileInfo.style.color = 'var(--error)';
                return;
            }
            
            // Vérifier le type
            const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!validTypes.includes(file.type)) {
                avatarFileInfo.textContent = 'Format non supporté (JPG, PNG, WebP, GIF uniquement)';
                avatarFileInfo.style.color = 'var(--error)';
                return;
            }
            
            avatarFileInfo.style.color = 'var(--success)';
            
            // Prévisualiser l'image
            const reader = new FileReader();
            reader.onload = function(e) {
                if (avatarPreview.querySelector('img')) {
                    avatarPreview.querySelector('img').src = e.target.result;
                } else {
                    const placeholder = avatarPreview.querySelector('.avatar-preview__placeholder');
                    if (placeholder) {
                        placeholder.remove();
                    }
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.alt = 'Aperçu de l\'avatar';
                    img.className = 'avatar-preview__img';
                    avatarPreview.appendChild(img);
                }
            };
            reader.readAsDataURL(file);
        });
    }
    
    // Mise à jour de l'aperçu depuis l'URL
    if (avatarUrlInput) {
        let urlPreviewTimeout;
        avatarUrlInput.addEventListener('input', function() {
            clearTimeout(urlPreviewTimeout);
            const url = this.value.trim();
            
            if (!url) return;
            
            urlPreviewTimeout = setTimeout(() => {
                // Vérifier si c'est une URL valide
                try {
                    new URL(url);
                } catch {
                    return;
                }
                
                // Mettre à jour l'aperçu
                if (avatarPreview.querySelector('img')) {
                    avatarPreview.querySelector('img').src = url;
                } else {
                    const placeholder = avatarPreview.querySelector('.avatar-preview__placeholder');
                    if (placeholder) {
                        placeholder.remove();
                    }
                    const img = document.createElement('img');
                    img.src = url;
                    img.alt = 'Aperçu de l\'avatar';
                    img.className = 'avatar-preview__img';
                    avatarPreview.appendChild(img);
                }
            }, 500);
        });
    }
    
    // Gestion de l'affichage des mots de passe
    document.querySelectorAll('[data-toggle-password]').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-toggle-password');
            const input = document.getElementById(targetId);
            if (!input) return;
            
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            
            // Basculer les icônes
            const eyeIcon = this.querySelector('.input-action__icon--eye');
            const eyeOffIcon = this.querySelector('.input-action__icon--eye-off');
            
            if (eyeIcon && eyeOffIcon) {
                eyeIcon.hidden = !isPassword;
                eyeOffIcon.hidden = isPassword;
            }
            
            // Mettre à jour l'aria-label
            this.setAttribute('aria-label', isPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        });
    });
    
    // Validation en temps réel du pseudo
    const usernameInput = document.getElementById('username');
    const usernameFeedback = document.getElementById('username-feedback');
    
    if (usernameInput && usernameFeedback) {
        usernameInput.addEventListener('input', function() {
            const value = this.value.trim();
            let isValid = true;
            let message = '';
            
            if (value.length < 3) {
                isValid = false;
                message = 'Le pseudo doit contenir au moins 3 caractères';
            } else if (value.length > 30) {
                isValid = false;
                message = 'Le pseudo ne peut pas dépasser 30 caractères';
            } else if (!/^[a-zA-Z0-9_-]+$/.test(value)) {
                isValid = false;
                message = 'Caractères autorisés : lettres, chiffres, _ et -';
            }
            
            usernameInput.classList.toggle('form-input--error', !isValid);
            usernameInput.classList.toggle('form-input--success', isValid && value.length > 0);
            usernameFeedback.textContent = message;
            usernameFeedback.className = `form-feedback ${isValid ? 'form-feedback--success' : 'form-feedback--error'}`;
        });
    }
    
    // Validation en temps réel de l'email
    const emailInput = document.getElementById('email');
    const emailFeedback = document.getElementById('email-feedback');
    
    if (emailInput && emailFeedback) {
        emailInput.addEventListener('input', function() {
            const value = this.value.trim();
            const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
            
            emailInput.classList.toggle('form-input--error', !isValid && value.length > 0);
            emailInput.classList.toggle('form-input--success', isValid);
            emailFeedback.textContent = isValid || value.length === 0 ? '' : 'Adresse e-mail invalide';
            emailFeedback.className = `form-feedback ${isValid ? 'form-feedback--success' : 'form-feedback--error'}`;
        });
    }
    
    // Validation et force du mot de passe
    const passwordInput = document.getElementById('new-password');
    const passwordFeedback = document.getElementById('password-feedback');
    const passwordStrengthFill = document.getElementById('password-strength-fill');
    const passwordStrengthLabel = document.getElementById('password-strength-label');
    const confirmPasswordInput = document.getElementById('confirm-password');
    const confirmPasswordFeedback = document.getElementById('confirm-password-feedback');
    
    function checkPasswordStrength(password) {
        let score = 0;
        
        // Longueur
        if (password.length >= 8) score += 1;
        if (password.length >= 12) score += 1;
        
        // Complexité
        if (/[a-z]/.test(password)) score += 1;
        if (/[A-Z]/.test(password)) score += 1;
        if (/[0-9]/.test(password)) score += 1;
        if (/[^a-zA-Z0-9]/.test(password)) score += 1;
        
        // Retourner le score (0-5)
        return Math.min(score, 5);
    }
    
    function updatePasswordStrength(password) {
        const strength = checkPasswordStrength(password);
        const percentage = (strength / 5) * 100;
        
        if (passwordStrengthFill) {
            passwordStrengthFill.style.width = `${percentage}%`;
            
            // Couleur en fonction de la force
            if (strength <= 1) {
                passwordStrengthFill.style.backgroundColor = 'var(--error)';
            } else if (strength <= 3) {
                passwordStrengthFill.style.backgroundColor = 'var(--warning)';
            } else {
                passwordStrengthFill.style.backgroundColor = 'var(--success)';
            }
        }
        
        if (passwordStrengthLabel) {
            const labels = ['Très faible', 'Faible', 'Moyen', 'Bon', 'Très bon', 'Excellent'];
            passwordStrengthLabel.textContent = labels[strength] || 'Faible';
        }
        
        return strength;
    }
    
    if (passwordInput && passwordFeedback) {
        passwordInput.addEventListener('input', function() {
            const value = this.value;
            const strength = updatePasswordStrength(value);
            
            let isValid = true;
            let message = '';
            
            if (value.length < 8) {
                isValid = false;
                message = 'Le mot de passe doit contenir au moins 8 caractères';
            } else if (!/[0-9]/.test(value)) {
                isValid = false;
                message = 'Le mot de passe doit contenir au moins un chiffre';
            } else if (!/[^a-zA-Z0-9]/.test(value)) {
                isValid = false;
                message = 'Le mot de passe doit contenir au moins un caractère spécial';
            }
            
            passwordInput.classList.toggle('form-input--error', !isValid && value.length > 0);
            passwordInput.classList.toggle('form-input--success', isValid && value.length > 0);
            passwordFeedback.textContent = message;
            passwordFeedback.className = `form-feedback ${isValid ? 'form-feedback--success' : 'form-feedback--error'}`;
            
            // Vérifier la correspondance si le champ de confirmation est rempli
            if (confirmPasswordInput && confirmPasswordInput.value) {
                checkPasswordMatch();
            }
        });
    }
    
    function checkPasswordMatch() {
        if (!passwordInput || !confirmPasswordInput || !confirmPasswordFeedback) return;
        
        const password = passwordInput.value;
        const confirm = confirmPasswordInput.value;
        
        if (confirm.length === 0) {
            confirmPasswordInput.classList.remove('form-input--error', 'form-input--success');
            confirmPasswordFeedback.textContent = '';
            return;
        }
        
        const matches = password === confirm;
        
        confirmPasswordInput.classList.toggle('form-input--error', !matches);
        confirmPasswordInput.classList.toggle('form-input--success', matches);
        confirmPasswordFeedback.textContent = matches ? 'Les mots de passe correspondent' : 'Les mots de passe ne correspondent pas';
        confirmPasswordFeedback.className = `form-feedback ${matches ? 'form-feedback--success' : 'form-feedback--error'}`;
    }
    
    if (confirmPasswordInput && confirmPasswordFeedback) {
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
    }
    
    // Tooltips pour les badges de limite
    document.querySelectorAll('[data-tooltip]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            const tooltipText = this.getAttribute('data-tooltip');
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = tooltipText;
            
            const rect = this.getBoundingClientRect();
            tooltip.style.position = 'fixed';
            tooltip.style.left = `${rect.left + rect.width / 2}px`;
            tooltip.style.top = `${rect.top - 10}px`;
            tooltip.style.transform = 'translate(-50%, -100%)';
            
            document.body.appendChild(tooltip);
            
            this._tooltip = tooltip;
        });
        
        element.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                this._tooltip.remove();
                this._tooltip = null;
            }
        });
    });
    
    // Validation des formulaires avant soumission
    document.querySelectorAll('.profile-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            let isValid = true;
            const action = this.querySelector('input[name="action"]')?.value;
            
            // Validation selon le type de formulaire
            switch (action) {
                case 'username':
                    const usernameInput = this.querySelector('input[name="username"]');
                    if (usernameInput) {
                        const username = usernameInput.value.trim();
                        if (username.length < 3 || username.length > 30 || !/^[a-zA-Z0-9_-]+$/.test(username)) {
                            isValid = false;
                            usernameInput.classList.add('form-input--error');
                            usernameInput.focus();
                        }
                    }
                    break;
                    
                case 'email':
                    const emailInput = this.querySelector('input[name="email"]');
                    if (emailInput) {
                        const email = emailInput.value.trim();
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailRegex.test(email)) {
                            isValid = false;
                            emailInput.classList.add('form-input--error');
                            emailInput.focus();
                        }
                    }
                    break;
                    
                case 'password':
                    const passwordInput = this.querySelector('input[name="new_password"]');
                    const confirmInput = this.querySelector('input[name="confirm_password"]');
                    
                    if (passwordInput && confirmInput) {
                        const password = passwordInput.value;
                        const confirm = confirmInput.value;
                        
                        // Validation basique du mot de passe
                        if (password.length < 8 || !/[0-9]/.test(password) || !/[^a-zA-Z0-9]/.test(password)) {
                            isValid = false;
                            passwordInput.classList.add('form-input--error');
                            passwordInput.focus();
                        }
                        
                        // Vérification de la correspondance
                        if (password !== confirm) {
                            isValid = false;
                            confirmInput.classList.add('form-input--error');
                            if (password.length >= 8) confirmInput.focus();
                        }
                    }
                    break;
                    
                case 'avatar':
                    const fileInput = this.querySelector('input[name="avatar"]');
                    const urlInput = this.querySelector('input[name="avatar_url"]');
                    const activeTab = this.querySelector('.form-tab--active');
                    
                    if (activeTab && activeTab.dataset.tab === 'upload') {
                        if (fileInput && fileInput.files.length === 0) {
                            isValid = false;
                            // Afficher un message d'erreur
                            const fileInfo = document.getElementById('avatar-file-info');
                            if (fileInfo) {
                                fileInfo.textContent = 'Veuillez sélectionner un fichier';
                                fileInfo.style.color = 'var(--error)';
                            }
                        }
                    } else if (activeTab && activeTab.dataset.tab === 'url') {
                        if (urlInput && !urlInput.value.trim()) {
                            isValid = false;
                            urlInput.classList.add('form-input--error');
                            urlInput.focus();
                        } else if (urlInput && urlInput.value.trim()) {
                            try {
                                new URL(urlInput.value.trim());
                            } catch {
                                isValid = false;
                                urlInput.classList.add('form-input--error');
                                urlInput.focus();
                            }
                        }
                    }
                    break;
            }
            
            if (!isValid) {
                e.preventDefault();
                
                // Afficher un message d'erreur général
                const firstError = this.querySelector('.form-input--error');
                if (firstError) {
                    firstError.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }
        });
    });
    
    // Réinitialiser les erreurs lorsqu'on modifie un champ
    document.querySelectorAll('.form-input').forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('form-input--error');
            
            // Réinitialiser les messages d'erreur spécifiques
            if (this.name === 'avatar') {
                const fileInfo = document.getElementById('avatar-file-info');
                if (fileInfo) {
                    fileInfo.style.color = '';
                    if (!this.files.length) {
                        fileInfo.textContent = 'Aucun fichier sélectionné';
                    }
                }
            }
        });
    });
});
</script>
    </div>
</section>
