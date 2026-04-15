<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Middleware\AuthMiddleware;
use App\Core\RateLimiter;
use App\Core\View;
use App\Data\StaticData;
use App\Services\SearchService;
use App\Services\SupabaseAuth;
use App\Services\TurnstileService;

class RegisterController
{
    public function show(): void
    {
        AuthMiddleware::requireGuest();

        $search      = new SearchService();
        $platforms   = $search->getPlatformsCatalogForRegistration();
        $env         = $_ENV['APP_ENV'] ?? 'development';
        $useRealInDev = ($_ENV['TURNSTILE_USE_REAL_IN_DEV'] ?? 'false') === 'true';

        $siteKey     = $_ENV['TURNSTILE_SITE_KEY'] ?? '';
        if ($env !== 'production' && !$useRealInDev) {
            // En dev, on privilégie la clé de test pour éviter les erreurs "invalid domain/sitekey".
            $siteKey = '1x00000000000000000000AA';
        }

        View::render('auth/register', [
            'title'     => 'Créer un compte',
            'cssFile'   => 'auth',
            'head'      => $siteKey !== ''
                ? '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" defer></script>'
                : '',
            'turnstileSiteKey' => $siteKey,
            'platforms' => $platforms,
            'genres'    => StaticData::genres(),
        ]);
    }

    public function handle(): void
    {
        AuthMiddleware::requireGuest();
        Csrf::check();

        $ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimiter = new RateLimiter();

        // 5 tentatives par 15 minutes
        if (!$rateLimiter->attempt('register', $ip, 5, 900)) {
            $wait = ceil($rateLimiter->retryAfter('register', $ip, 900) / 60);
            Flash::error("Trop de tentatives. Réessayez dans {$wait} minute(s).", [], $_POST);
            View::redirect('/register');
        }

        // Turnstile
        if (!(new TurnstileService())->verify($_POST['cf-turnstile-response'] ?? '', $ip)) {
            Flash::error('Vérification anti-bot échouée. Veuillez réessayer.', [], $_POST);
            View::redirect('/register');
        }

        // Validation des champs
        $errors = $this->validate($_POST);
        if (!empty($errors)) {
            Flash::error('Veuillez corriger les erreurs ci-dessous.', $errors, $_POST);
            View::redirect('/register');
        }

        // Avatar
        $avatarUrl = $this->handleAvatar($_POST['avatar_url'] ?? '', $_FILES['avatar'] ?? []);

        // Inscription Supabase
        $auth   = new SupabaseAuth();
        $result = $auth->signUp(
            trim($_POST['email']),
            $_POST['password'],
            trim($_POST['username']),
            $avatarUrl
        );

        if (!$result['success']) {
            $code       = $result['error']['code'] ?? '';
            $fieldError = match ($code) {
                'USERNAME_TAKEN' => ['username' => $result['error']['message']],
                'EMAIL_TAKEN'    => ['email'    => $result['error']['message']],
                default          => [],
            };
            Flash::error($result['error']['message'], $fieldError, $_POST);
            View::redirect('/register');
        }

        $userId = $result['data']['user_id'];

        // Préférences plateformes / genres (déjà validées dans validate())
        $catalog     = (new SearchService())->getPlatformsCatalogForRegistration();
        $platformIds = $this->normalizedPlatformIdsFromPost($_POST, $catalog);
        $genreIds    = $this->normalizedGenreIdsFromPost($_POST);
        $genreNames  = array_map(fn(int $id) => StaticData::genreNameById($id), $genreIds);
        $auth->savePreferences($userId, $platformIds, $genreIds, $genreNames);

        // Confirmation d'e-mail requise (paramètre Supabase activé)
        if (!empty($result['data']['needs_confirmation'])) {
            Flash::success(
                'Compte créé ! Un e-mail de confirmation a été envoyé à '
                . htmlspecialchars($result['data']['email'])
                . '. Cliquez sur le lien pour activer votre compte.'
            );
            View::redirect('/login');
        }

        // Connexion directe (confirmation désactivée dans Supabase)
        $_SESSION['auth'] = $result['data'];
        session_regenerate_id(true);

        Flash::success('Bienvenue ' . htmlspecialchars($result['data']['username']) . ' ! Votre compte est créé.');
        View::redirect('/collection');
    }

    // ──────────────────────────────────────────────
    //  Validation
    // ──────────────────────────────────────────────

    private function validate(array $data): array
    {
        $errors = [];

        $username = trim($data['username'] ?? '');
        if ($username === '') {
            $errors['username'] = 'Le pseudo est obligatoire.';
        } elseif (!preg_match('/^[a-zA-Z0-9_\-]{3,30}$/', $username)) {
            $errors['username'] = 'Le pseudo doit contenir 3–30 caractères (lettres, chiffres, _ et -).';
        }

        $email = trim($data['email'] ?? '');
        if ($email === '') {
            $errors['email'] = 'L\'adresse e-mail est obligatoire.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }

        $password = $data['password'] ?? '';
        if ($password === '') {
            $errors['password'] = 'Le mot de passe est obligatoire.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Minimum 8 caractères.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Le mot de passe doit contenir au moins un chiffre.';
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors['password'] = 'Le mot de passe doit contenir au moins un caractère spécial.';
        }

        $confirm = $data['confirm_password'] ?? '';
        if ($confirm === '') {
            $errors['confirm_password'] = 'Veuillez confirmer le mot de passe.';
        } elseif ($password !== $confirm) {
            $errors['confirm_password'] = 'Les mots de passe ne correspondent pas.';
        }

        $catalog      = (new SearchService())->getPlatformsCatalogForRegistration();
        $platformIds  = $this->normalizedPlatformIdsFromPost($data, $catalog);
        if ($catalog !== [] && $platformIds === []) {
            $errors['platforms'] = 'Sélectionnez au moins une plateforme possédée.';
        }

        $genreIds = $this->normalizedGenreIdsFromPost($data);
        if ($genreIds === []) {
            $errors['genres'] = 'Sélectionnez au moins un genre préféré.';
        }

        return $errors;
    }

    /**
     * @param array<int, array{id:int, ...}> $catalog
     * @return list<int>
     */
    private function normalizedPlatformIdsFromPost(array $data, array $catalog): array
    {
        $allowedIds = array_fill_keys(array_column($catalog, 'id'), true);
        $raw        = array_map('intval', (array) ($data['platforms'] ?? []));

        return array_values(array_unique(array_filter(
            $raw,
            static fn(int $id): bool => $id > 0 && isset($allowedIds[$id])
        )));
    }

    /** @return list<int> */
    private function normalizedGenreIdsFromPost(array $data): array
    {
        $allowed = array_fill_keys(array_column(StaticData::genres(), 'id'), true);
        $raw     = array_map('intval', (array) ($data['genres'] ?? []));

        return array_values(array_unique(array_filter(
            $raw,
            static fn(int $id): bool => $id > 0 && isset($allowed[$id])
        )));
    }

    // ──────────────────────────────────────────────
    //  Gestion de l'avatar
    // ──────────────────────────────────────────────

    private function handleAvatar(string $url, array $file): ?string
    {
        if (!empty($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            return $this->processUpload($file);
        }

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }

    private function processUpload(array $file): ?string
    {
        if ($file['size'] > 2 * 1024 * 1024) {
            return null;
        }

        $mime    = mime_content_type($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            return null;
        }

        $uploadDir = __DIR__ . '/../../../assets/uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Si GD n'est pas disponible (XAMPP), on sauvegarde l'upload tel quel (sans conversion)
        // afin de ne pas bloquer l'inscription.
        $canConvert = function_exists('imagecreatefromstring')
            && function_exists('imagewebp')
            && function_exists('imagescale')
            && function_exists('imagecrop');

        if (!$canConvert) {
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                default      => 'img',
            };

            $filename = bin2hex(random_bytes(12)) . '.' . $ext;
            $dest     = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                return null;
            }

            return '/assets/uploads/avatars/' . $filename;
        }

        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) {
            return null;
        }

        $img = imagecreatefromstring($raw);
        if (!$img) {
            return null;
        }

        $w    = imagesx($img);
        $h    = imagesy($img);
        $size = min($w, $h);

        $cropped = imagecrop($img, [
            'x'      => (int)(($w - $size) / 2),
            'y'      => (int)(($h - $size) / 2),
            'width'  => $size,
            'height' => $size,
        ]);

        $final    = $cropped ?: $img;
        $resized  = imagescale($final, 200, 200);
        $filename = bin2hex(random_bytes(12)) . '.webp';
        $dest     = $uploadDir . $filename;

        imagewebp($resized ?: $final, $dest, 85);
        imagedestroy($img);

        return '/assets/uploads/avatars/' . $filename;
    }
}
