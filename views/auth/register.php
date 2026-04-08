<?php
use App\Core\Csrf;
use App\Core\Flash;
?>
<section class="auth-page">
    <div class="auth-card">

        <div class="auth-card__header">
            <h1 class="auth-card__title">Créer un compte</h1>
            <p class="auth-card__sub">Rejoignez la communauté des collectionneurs</p>
        </div>

        <form action="/register" method="POST" enctype="multipart/form-data" class="auth-form" novalidate>
            <?= Csrf::field() ?>

            <!-- Avatar -->
            <div class="form-group avatar-group">
                <div class="avatar-preview" id="avatarPreview">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    </svg>
                </div>
                <div class="avatar-controls">
                    <p class="form-label">Photo de profil <span class="optional">(optionnel)</span></p>
                    <div class="avatar-tabs" role="tablist">
                        <button type="button" class="avatar-tab is-active" data-target="upload-pane" role="tab">Uploader</button>
                        <button type="button" class="avatar-tab" data-target="url-pane" role="tab">Par URL</button>
                    </div>
                    <div id="upload-pane" class="avatar-pane">
                        <label class="file-btn">
                            <input type="file" name="avatar" id="avatarFile" accept="image/*" class="sr-only">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Choisir une image
                        </label>
                        <small class="form-hint">JPG, PNG, WebP — max 2 Mo</small>
                    </div>
                    <div id="url-pane" class="avatar-pane" hidden>
                        <input type="url" name="avatar_url"
                               class="form-input <?= Flash::hasFieldError('avatar_url') ? 'is-error' : '' ?>"
                               placeholder="https://exemple.com/photo.jpg"
                               value="<?= Flash::old('avatar_url') ?>">
                    </div>
                </div>
            </div>

            <!-- Pseudo + Email -->
            <div class="form-row">
                <div class="form-group">
                    <label for="username" class="form-label">Pseudo <span class="required">*</span></label>
                    <input type="text" id="username" name="username" autocomplete="username" required
                           class="form-input <?= Flash::hasFieldError('username') ? 'is-error' : '' ?>"
                           placeholder="MonPseudo42"
                           value="<?= Flash::old('username') ?>">
                    <?php if ($e = Flash::fieldError('username')): ?>
                    <p class="form-error"><?= $e ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">E-mail <span class="required">*</span></label>
                    <input type="email" id="email" name="email" autocomplete="email" required
                           class="form-input <?= Flash::hasFieldError('email') ? 'is-error' : '' ?>"
                           placeholder="vous@exemple.fr"
                           value="<?= Flash::old('email') ?>">
                    <?php if ($e = Flash::fieldError('email')): ?>
                    <p class="form-error"><?= $e ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Mots de passe -->
            <div class="form-row">
                <div class="form-group">
                    <label for="password" class="form-label">Mot de passe <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" autocomplete="new-password" required
                               class="form-input <?= Flash::hasFieldError('password') ? 'is-error' : '' ?>"
                               placeholder="8+ car., 1 chiffre, 1 spécial">
                        <button type="button" class="input-eye" onclick="togglePwd('password')" aria-label="Afficher">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <?php if ($e = Flash::fieldError('password')): ?>
                    <p class="form-error"><?= $e ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirmation <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required
                               class="form-input <?= Flash::hasFieldError('confirm_password') ? 'is-error' : '' ?>"
                               placeholder="Répétez le mot de passe">
                        <button type="button" class="input-eye" onclick="togglePwd('confirm_password')" aria-label="Afficher">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <?php if ($e = Flash::fieldError('confirm_password')): ?>
                    <p class="form-error"><?= $e ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Plateformes -->
            <?php
            $oldPlatforms = Flash::oldArray('platforms');
            $catalogIdSet = array_flip(array_column($platforms, 'id'));
            $initialPlatformIds = array_values(array_unique(array_filter(
                array_map('intval', $oldPlatforms),
                static fn(int $id): bool => $id > 0 && isset($catalogIdSet[$id])
            )));
            $jsonCatalogFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
            ?>
            <div class="form-group">
                <label for="platform-picker-search" class="form-label">Plateformes possédées <span class="optional">(optionnel)</span></label>
                <?php if (empty($platforms)): ?>
                <p class="form-hint">Les plateformes ne peuvent pas être chargées pour le moment. Vous pourrez compléter votre profil plus tard.</p>
                <?php else: ?>
                <script type="application/json" id="platform-catalog-json"><?= json_encode($platforms, $jsonCatalogFlags) ?></script>
                <script type="application/json" id="platform-picker-initial"><?= json_encode($initialPlatformIds, $jsonCatalogFlags) ?></script>
                <div class="platform-picker" data-platform-picker>
                    <div class="platform-picker__field">
                        <input type="search"
                               id="platform-picker-search"
                               class="form-input platform-picker__input"
                               autocomplete="off"
                               spellcheck="false"
                               aria-autocomplete="list"
                               aria-controls="platform-picker-list"
                               aria-expanded="false"
                               placeholder="Rechercher une plateforme…">
                        <div class="platform-picker__list"
                             id="platform-picker-list"
                             role="listbox"
                             hidden
                             aria-label="Résultats"></div>
                    </div>
                    <div class="platform-picker__selected" id="platform-picker-selected" aria-live="polite"></div>
                    <div class="platform-picker__hidden" id="platform-picker-hidden"></div>
                </div>
                <p class="form-hint">Tapez quelques lettres puis choisissez dans la liste. Ajoutez autant de plateformes que nécessaire.</p>
                <?php endif; ?>
            </div>

            <!-- Genres -->
            <div class="form-group">
                <p class="form-label">Genres préférés <span class="optional">(optionnel)</span></p>
                <div class="chip-grid chip-grid--genres">
                    <?php
                    $oldGenres = Flash::oldArray('genres');
                    foreach ($genres as $g):
                    ?>
                    <label class="chip">
                        <input type="checkbox" name="genres[]" value="<?= $g['id'] ?>"
                               <?= in_array((string)$g['id'], $oldGenres, true) ? 'checked' : '' ?>>
                        <span class="chip__label"><?= htmlspecialchars($g['name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Turnstile -->
            <?php
            $turnstileSiteKey = $turnstileSiteKey ?? ($_ENV['TURNSTILE_SITE_KEY'] ?? '');
            ?>
            <?php if (!empty($turnstileSiteKey)): ?>
            <div class="form-group form-group--center">
                <div class="cf-turnstile"
                     data-sitekey="<?= htmlspecialchars($turnstileSiteKey) ?>"
                     data-theme="<?= ($_COOKIE['theme'] ?? 'dark') === 'light' ? 'light' : 'dark' ?>">
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn--primary btn--full">Créer mon compte</button>

            <p class="auth-form__footer">
                Déjà inscrit ? <a href="/login" class="link">Se connecter</a>
            </p>
        </form>
    </div>
</section>

<script>
function togglePwd(id) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}

// Prévisualisation de l'avatar
document.getElementById('avatarFile')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const preview = document.getElementById('avatarPreview');
        preview.innerHTML = `<img src="${e.target.result}" alt="Aperçu avatar">`;
    };
    reader.readAsDataURL(file);
});

// Onglets avatar
document.querySelectorAll('.avatar-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.avatar-tab').forEach(b => b.classList.remove('is-active'));
        document.querySelectorAll('.avatar-pane').forEach(p => p.hidden = true);
        btn.classList.add('is-active');
        const target = document.getElementById(btn.dataset.target);
        if (target) target.hidden = false;
    });
});

// Sélecteur plateformes (recherche + liste)
(function () {
    const jsonEl = document.getElementById('platform-catalog-json');
    const root = document.querySelector('[data-platform-picker]');
    if (!jsonEl || !root) return;

    let catalog;
    try {
        catalog = JSON.parse(jsonEl.textContent);
    } catch (e) {
        return;
    }
    if (!Array.isArray(catalog) || catalog.length === 0) return;

    const searchInput = root.querySelector('.platform-picker__input');
    const listEl = root.querySelector('.platform-picker__list');
    const selectedEl = root.querySelector('.platform-picker__selected');
    const hiddenWrap = root.querySelector('.platform-picker__hidden');
    const initialEl = document.getElementById('platform-picker-initial');

    const selected = new Map();
    const MAX_SHOWN = 45;
    let activeIdx = -1;
    let debounceT;

    function norm(s) {
        return String(s).normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
    }

    function mergeSearchFields(p) {
        return norm(p.name + ' ' + p.short);
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function updateActiveOptions(opts) {
        opts.forEach((o, i) => {
            o.classList.toggle('is-active', i === activeIdx);
            o.setAttribute('aria-selected', i === activeIdx ? 'true' : 'false');
        });
        if (opts[activeIdx]) {
            searchInput.setAttribute('aria-activedescendant', opts[activeIdx].id);
        } else {
            searchInput.removeAttribute('aria-activedescendant');
        }
    }

    function renderMatchList(items) {
        listEl.innerHTML = '';
        const slice = items.slice(0, MAX_SHOWN);
        activeIdx = slice.length ? 0 : -1;
        slice.forEach((p, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'platform-picker__option';
            btn.setAttribute('role', 'option');
            btn.dataset.id = String(p.id);
            btn.id = 'platform-opt-' + p.id;
            btn.innerHTML = '<span class="platform-picker__option-name">' + escapeHtml(p.name) + '</span>'
                + '<span class="platform-picker__option-short">' + escapeHtml(p.short) + '</span>';
            btn.addEventListener('click', () => pickPlatform(p.id));
            listEl.appendChild(btn);
        });
        if (items.length > MAX_SHOWN) {
            const more = document.createElement('div');
            more.className = 'platform-picker__list-more';
            more.textContent = '… et ' + (items.length - MAX_SHOWN) + ' autre(s) — affinez la recherche';
            listEl.appendChild(more);
        }
        const opts = listEl.querySelectorAll('.platform-picker__option');
        updateActiveOptions(opts);
    }

    function addSelection(p) {
        if (!p || selected.has(p.id)) return;
        selected.set(p.id, p);
        const hi = document.createElement('input');
        hi.type = 'hidden';
        hi.name = 'platforms[]';
        hi.value = String(p.id);
        hiddenWrap.appendChild(hi);
    }

    function renderTags() {
        selectedEl.innerHTML = '';
        selected.forEach((p) => {
            const tag = document.createElement('span');
            tag.className = 'platform-picker__tag';
            const text = document.createElement('span');
            text.className = 'platform-picker__tag-text';
            text.textContent = p.name;
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'platform-picker__tag-remove';
            rm.setAttribute('aria-label', 'Retirer ' + p.name.replace(/"/g, ''));
            rm.textContent = '\u00D7';
            rm.addEventListener('click', () => removePlatform(p.id));
            tag.appendChild(text);
            tag.appendChild(rm);
            selectedEl.appendChild(tag);
        });
    }

    function removePlatform(id) {
        selected.delete(id);
        hiddenWrap.querySelectorAll('input[name="platforms[]"]').forEach((inp) => {
            if (inp.value === String(id)) inp.remove();
        });
        renderTags();
        filterList(searchInput.value);
    }

    function openList() {
        listEl.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
    }

    function closeList() {
        listEl.hidden = true;
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
    }

    function filterList(q) {
        const nq = norm(q.trim());
        if (!nq) {
            closeList();
            return;
        }
        const items = catalog.filter((p) => !selected.has(p.id) && mergeSearchFields(p).includes(nq));
        if (items.length === 0) {
            closeList();
            return;
        }
        renderMatchList(items);
        openList();
    }

    function pickPlatform(id) {
        const p = catalog.find((x) => x.id === id);
        if (!p) return;
        addSelection(p);
        renderTags();
        searchInput.value = '';
        closeList();
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(debounceT);
        debounceT = setTimeout(() => filterList(searchInput.value), 100);
    });

    searchInput.addEventListener('keydown', (e) => {
        const opts = listEl.querySelectorAll('.platform-picker__option');
        if (e.key === 'Escape') {
            e.preventDefault();
            closeList();
            return;
        }
        if (listEl.hidden || !opts.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = Math.min(activeIdx + 1, opts.length - 1);
            updateActiveOptions(opts);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = Math.max(activeIdx - 1, 0);
            updateActiveOptions(opts);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0 && opts[activeIdx]) {
                pickPlatform(parseInt(opts[activeIdx].dataset.id, 10));
            }
        }
    });

    document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) closeList();
    });

    try {
        const raw = initialEl ? JSON.parse(initialEl.textContent) : [];
        const initialSet = new Set(
            (Array.isArray(raw) ? raw : []).map((x) => parseInt(x, 10)).filter((n) => !Number.isNaN(n))
        );
        catalog.forEach((p) => {
            if (initialSet.has(p.id)) addSelection(p);
        });
        renderTags();
    } catch (err) { /* ignore */ }
})();
</script>
