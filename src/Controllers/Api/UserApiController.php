<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Middleware\AuthMiddleware;
use App\Services\UserProfileService;
use GuzzleHttp\Client;

/**
 * GET /api/user/profile
 * 
 * Récupère le profil de l'utilisateur connecté.
 * Optionnellement, peut récupérer un profil public via :
 *   - ?username=...
 *   - ?user_id=...
 * 
 * Réponse standard :
 * - { "success": true, "data": { "user": {...}, "stats": {...} } }
 * - { "success": false, "error": { "code": "...", "message": "..." } }
 */
class UserApiController
{
    public function profile(): void
    {
        $requestedUserId = isset($_GET['user_id']) ? trim((string) $_GET['user_id']) : '';
        $requestedUsername = isset($_GET['username']) ? trim((string) $_GET['username']) : '';
        $currentUserId = AuthMiddleware::userId();

        // Profil public par username
        if ($requestedUsername !== '') {
            $this->getPublicProfileByUsername($requestedUsername, $currentUserId);
            return;
        }

        // Profil public par user_id (compat)
        if ($requestedUserId !== '' && $requestedUserId !== $currentUserId) {
            $this->getPublicProfileById($requestedUserId, $currentUserId);
            return;
        }

        // Profil de l'utilisateur connecté
        $userId = AuthMiddleware::userId();
        if (!$userId) {
            $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Utilisateur non authentifié.']], 401);
        }

        $profile = $this->getUserRowById($userId);
        if (!$profile) {
            $this->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Utilisateur non trouvé.']], 404);
        }

        $svc = new UserProfileService();
        $statsBundle = $svc->getStatsAndReviews($userId);
        $prefs = $svc->getByUsername((string) ($profile['username'] ?? ''), $userId);

        $this->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $userId,
                    'username' => (string) ($profile['username'] ?? ''),
                    'email' => (string) (AuthMiddleware::user()['email'] ?? ''),
                    'avatar_url' => (string) ($profile['avatar_url'] ?? ''),
                    'bio' => (string) ($profile['bio'] ?? ''),
                    'created_at' => (string) ($profile['created_at'] ?? ''),
                    'collection_public' => (bool) ($profile['collection_public'] ?? false),
                    'platforms' => $prefs['platforms'] ?? [],
                    'genres' => $prefs['genres'] ?? [],
                ],
                'stats' => $statsBundle['stats'] ?? [],
            ]
        ]);
    }

    /**
     * Profil public par user_id (ou owner si viewer==id).
     */
    private function getPublicProfileById(string $userId, ?string $viewerUserId = null): void
    {
        $profile = $this->getUserRowById($userId);
        if (!$profile) {
            $this->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Utilisateur non trouvé.']], 404);
        }

        $isOwner = $viewerUserId !== null && $viewerUserId === $userId;
        if (!$isOwner && empty($profile['collection_public'])) {
            $this->json(['success' => false, 'error' => ['code' => 'PRIVATE_PROFILE', 'message' => 'Ce profil est privé.']], 403);
        }

        $svc = new UserProfileService();
        $statsBundle = $svc->getStatsAndReviews($userId);
        $prefs = $svc->getByUsername((string) ($profile['username'] ?? ''), $viewerUserId);

        $this->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => (string) ($profile['id'] ?? ''),
                    'username' => (string) ($profile['username'] ?? ''),
                    'avatar_url' => (string) ($profile['avatar_url'] ?? ''),
                    'bio' => (string) ($profile['bio'] ?? ''),
                    'created_at' => (string) ($profile['created_at'] ?? ''),
                    'collection_public' => (bool) ($profile['collection_public'] ?? false),
                    'platforms' => $prefs['platforms'] ?? [],
                    'genres' => $prefs['genres'] ?? [],
                ],
                'stats' => $statsBundle['stats'] ?? [],
            ]
        ]);
    }

    /**
     * Profil public par username (ou owner si viewer==id).
     */
    private function getPublicProfileByUsername(string $username, ?string $viewerUserId = null): void
    {
        $svc = new UserProfileService();
        $profileBundle = $svc->getByUsername($username, $viewerUserId);
        if ($profileBundle === null) {
            $this->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Utilisateur non trouvé.']], 404);
        }

        $user = $profileBundle['user'] ?? [];
        if (!empty($user['_private'])) {
            $this->json(['success' => false, 'error' => ['code' => 'PRIVATE_PROFILE', 'message' => 'Ce profil est privé.']], 403);
        }

        $userId = (string) ($user['id'] ?? '');
        $statsBundle = $svc->getStatsAndReviews($userId);

        $this->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $userId,
                    'username' => (string) ($user['username'] ?? ''),
                    'avatar_url' => (string) ($user['avatar_url'] ?? ''),
                    'bio' => (string) ($user['bio'] ?? ''),
                    'created_at' => (string) ($user['created_at'] ?? ''),
                    'collection_public' => (bool) ($user['collection_public'] ?? false),
                    'platforms' => $profileBundle['platforms'] ?? [],
                    'genres' => $profileBundle['genres'] ?? [],
                ],
                'stats' => $statsBundle['stats'] ?? [],
            ]
        ]);
    }

    private function getUserRowById(string $userId): ?array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }

        try {
            $http = new Client(['timeout' => 8, 'http_errors' => false]);
            $supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
            $serviceKey = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';

            $url = $supabaseUrl . '/rest/v1/users?id=eq.' . rawurlencode($userId)
                . '&select=id,username,avatar_url,bio,collection_public,created_at&limit=1';

            $res = $http->get($url, [
                'headers' => [
                    'apikey' => $serviceKey,
                    'Authorization' => 'Bearer ' . $serviceKey,
                ],
            ]);
            $rows = json_decode((string) $res->getBody(), true);
            if (!is_array($rows) || empty($rows) || !is_array($rows[0] ?? null)) {
                return null;
            }
            return $rows[0];
        } catch (\Throwable) {
            return null;
        }
    }

    private function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}