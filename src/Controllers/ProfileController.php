<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Middleware\AuthMiddleware;
use App\Core\RateLimiter;
use App\Core\View;
use App\Services\SupabaseAuth;

class ProfileController
{
    public function show(): void
    {
        AuthMiddleware::requireAuth();

        View::render('profile/edit', [
            'title'   => 'Mon profil',
            'cssFile' => 'edit-profile',
            'user'    => AuthMiddleware::user(),
        ]);
    }

    public function update(): void
    {
        AuthMiddleware::requireAuth();
        Csrf::check();

        $action = $_POST['action'] ?? '';

        match ($action) {
            'username' => $this->updateUsername(),
            'email'    => $this->updateEmail(),
            'password' => $this->updatePassword(),
            'avatar'   => $this->updateAvatar(),
            'settings' => $this->updateSettings(),
            default    => View::redirect('/edit-profile'),
        };
    }

    // ──────────────────────────────────────────────
    //  Changement de pseudo
    // ──────────────────────────────────────────────

    private function updateUsername(): void
    {
        $userId = AuthMiddleware::userId();
        $rl     = new RateLimiter();

        if (!$rl->attempt('change_username', $userId, 1, 3600)) {
            Flash::error('Modification du pseudo limitée à 1 fois par heure.');
            View::redirect('/edit-profile');
        }

        $username = trim($_POST['username'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_\-]{3,30}$/', $username)) {
            Flash::error('Pseudo invalide (3–30 caractères : lettres, chiffres, _ et -).');
            View::redirect('/edit-profile');
        }

        $auth = new SupabaseAuth();

        if (!$auth->isUsernameAvailable($username)) {
            Flash::error('Ce pseudo est déjà pris.');
            View::redirect('/edit-profile');
        }

        $result = $auth->updateProfile($userId, ['username' => $username]);

        if (!$result['success']) {
            Flash::error('Erreur lors de la mise à jour du pseudo.');
            View::redirect('/edit-profile');
        }

        $_SESSION['auth']['username'] = $username;
        Flash::success('Pseudo mis à jour avec succès.');
        View::redirect('/edit-profile');
    }

    // ──────────────────────────────────────────────
    //  Changement d'email
    // ──────────────────────────────────────────────

    private function updateEmail(): void
    {
        $userId = AuthMiddleware::userId();
        $rl     = new RateLimiter();

        if (!$rl->attempt('change_email', $userId, 1, 3600)) {
            Flash::error('Modification de l\'e-mail limitée à 1 fois par heure.');
            View::redirect('/edit-profile');
        }

        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::error('Adresse e-mail invalide.');
            View::redirect('/edit-profile');
        }

        $auth   = AuthMiddleware::user();
        $result = (new SupabaseAuth())->updateAuthEmail($auth['access_token'], $email);

        if (!$result['success']) {
            Flash::error($result['error']['message'] ?? 'Erreur lors du changement d\'e-mail.');
            View::redirect('/edit-profile');
        }

        $_SESSION['auth']['email'] = $email;
        Flash::success('E-mail mis à jour. Vérifiez votre boîte pour confirmer la nouvelle adresse.');
        View::redirect('/edit-profile');
    }

    // ──────────────────────────────────────────────
    //  Changement de mot de passe
    // ──────────────────────────────────────────────

    private function updatePassword(): void
    {
        $userId = AuthMiddleware::userId();
        $rl     = new RateLimiter();

        if (!$rl->attempt('change_password', $userId, 1, 3600)) {
            Flash::error('Modification du mot de passe limitée à 1 fois par heure.');
            View::redirect('/edit-profile');
        }

        $newPassword = $_POST['new_password']     ?? '';
        $confirm     = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 8) {
            Flash::error('Le nouveau mot de passe doit contenir au moins 8 caractères.');
            View::redirect('/edit-profile');
        }

        if (!preg_match('/[0-9]/', $newPassword) || !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
            Flash::error('Le mot de passe doit contenir au moins un chiffre et un caractère spécial.');
            View::redirect('/edit-profile');
        }

        if ($newPassword !== $confirm) {
            Flash::error('Les mots de passe ne correspondent pas.');
            View::redirect('/edit-profile');
        }

        $auth   = AuthMiddleware::user();
        $result = (new SupabaseAuth())->updateAuthPassword($auth['access_token'], $newPassword);

        if (!$result['success']) {
            Flash::error($result['error']['message'] ?? 'Erreur lors du changement de mot de passe.');
            View::redirect('/edit-profile');
        }

        Flash::success('Mot de passe mis à jour avec succès.');
        View::redirect('/edit-profile');
    }

    // ──────────────────────────────────────────────
    //  Changement d'avatar
    // ──────────────────────────────────────────────

    private function updateAvatar(): void
    {
        $userId    = AuthMiddleware::userId();
        $avatarUrl = null;

        $file = $_FILES['avatar'] ?? [];
        if (!empty($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $avatarUrl = $this->processAvatarUpload($file);
            if (!$avatarUrl) {
                Flash::error('Image invalide (JPG, PNG, WebP — max 2 Mo).');
                View::redirect('/edit-profile');
            }
        } elseif (!empty($_POST['avatar_url'])) {
            $url = trim($_POST['avatar_url']);
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                Flash::error('URL d\'avatar invalide.');
                View::redirect('/edit-profile');
            }
            $avatarUrl = $url;
        }

        if (!$avatarUrl) {
            View::redirect('/edit-profile');
        }

        $result = (new SupabaseAuth())->updateProfile($userId, ['avatar_url' => $avatarUrl]);

        if (!$result['success']) {
            Flash::error('Erreur lors de la mise à jour de l\'avatar.');
            View::redirect('/edit-profile');
        }

        $_SESSION['auth']['avatar_url'] = $avatarUrl;
        Flash::success('Avatar mis à jour avec succès.');
        View::redirect('/edit-profile');
    }

    private function processAvatarUpload(array $file): ?string
    {
        if ($file['size'] > 2 * 1024 * 1024) {
            return null;
        }

        $mime    = mime_content_type($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            return null;
        }

        $uploadDir = __DIR__ . '/../../assets/uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

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

        $w       = imagesx($img);
        $h       = imagesy($img);
        $size    = min($w, $h);
        $cropped = imagecrop($img, [
            'x'      => (int)(($w - $size) / 2),
            'y'      => (int)(($h - $size) / 2),
            'width'  => $size,
            'height' => $size,
        ]);
        $resized = imagescale($cropped ?: $img, 200, 200);

        $filename = bin2hex(random_bytes(12)) . '.webp';
        imagewebp($resized ?: $img, $uploadDir . $filename, 85);
        imagedestroy($img);

        return '/assets/uploads/avatars/' . $filename;
    }

    // ──────────────────────────────────────────────
    //  Paramètres : visibilité collection
    // ──────────────────────────────────────────────

    private function updateSettings(): void
    {
        $userId = AuthMiddleware::userId();
        if (!$userId) {
            View::redirect('/login');
        }

        $collectionPublic = ($_POST['collection_public'] ?? '0') === '1';

        $result = (new SupabaseAuth())->updateProfile($userId, [
            'collection_public' => $collectionPublic,
        ]);

        if (!$result['success']) {
            Flash::error('Erreur lors de la mise à jour des paramètres.');
            View::redirect('/edit-profile');
        }

        Flash::success($collectionPublic
            ? 'Votre collection est maintenant publique.'
            : 'Votre collection est maintenant privée.'
        );
        View::redirect('/edit-profile');
    }
}
