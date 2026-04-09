<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Middleware\AuthMiddleware;
use App\Services\SupabaseAuth;

/**
 * GET /api/user/profile
 * 
 * Récupère le profil de l'utilisateur connecté.
 * Optionnellement, peut récupérer un profil public via paramètre ?user_id=...
 * 
 * Réponse standard :
 * - { "success": true, "data": { "user": {...}, "stats": {...} } }
 * - { "success": false, "error": { "code": "...", "message": "..." } }
 */
class UserApiController
{
    public function profile(): void
    {
        // Vérifier si on demande un profil public
        $requestedUserId = $_GET['user_id'] ?? null;
        $currentUserId = AuthMiddleware::userId();

        // Si un user_id est spécifié, c'est une requête de profil public
        if ($requestedUserId && $requestedUserId !== $currentUserId) {
            $this->getPublicProfile($requestedUserId);
            return;
        }

        // Sinon, retourner le profil de l'utilisateur connecté
        AuthMiddleware::requireAuth();

        $userId = AuthMiddleware::userId();
        if (!$userId) {
            $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Utilisateur non authentifié.']], 401);
        }

        try {
            $auth = new SupabaseAuth();
            $userData = $auth->getUser($userId);

            if (!$userData) {
                $this->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Utilisateur non trouvé.']], 404);
            }

            // Récupérer les statistiques de l'utilisateur
            $stats = $this->getUserStats($userId);

            $this->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $userId,
                        'username' => $userData['username'] ?? '',
                        'email' => $userData['email'] ?? '',
                        'avatar_url' => $userData['avatar_url'] ?? '',
                        'created_at' => $userData['created_at'] ?? '',
                        'collection_public' => $userData['collection_public'] ?? false,
                        'platforms' => $userData['platforms'] ?? [],
                        'genres' => $userData['genres'] ?? [],
                    ],
                    'stats' => $stats
                ]
            ]);
        } catch (\Throwable $e) {
            error_log('User profile API error: ' . $e->getMessage());
            $this->json(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Erreur lors de la récupération du profil.']], 500);
        }
    }

    /**
     * Récupère un profil public (utilisateur non connecté ou autre utilisateur)
     */
    private function getPublicProfile(string $userId): void
    {
        try {
            $auth = new SupabaseAuth();
            $userData = $auth->getUser($userId);

            if (!$userData) {
                $this->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Utilisateur non trouvé.']], 404);
            }

            // Vérifier si la collection est publique
            $collectionPublic = $userData['collection_public'] ?? false;
            if (!$collectionPublic) {
                $this->json(['success' => false, 'error' => ['code' => 'PRIVATE_PROFILE', 'message' => 'Ce profil est privé.']], 403);
            }

            // Statistiques publiques seulement
            $stats = $this->getPublicUserStats($userId);

            $this->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $userId,
                        'username' => $userData['username'] ?? '',
                        'avatar_url' => $userData['avatar_url'] ?? '',
                        'created_at' => $userData['created_at'] ?? '',
                    ],
                    'stats' => $stats
                ]
            ]);
        } catch (\Throwable $e) {
            error_log('Public profile API error: ' . $e->getMessage());
            $this->json(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Erreur lors de la récupération du profil.']], 500);
        }
    }

    /**
     * Récupère les statistiques d'un utilisateur (pour l'utilisateur lui-même)
     */
    private function getUserStats(string $userId): array
    {
        // À implémenter : requêtes vers Supabase pour récupérer les stats
        // Pour l'instant, retourner des valeurs par défaut
        return [
            'total_games' => 0,
            'completed_games' => 0,
            'hundred_percent_games' => 0,
            'total_play_time_hours' => 0,
            'average_rating' => 0,
            'physical_count' => 0,
            'digital_count' => 0,
        ];
    }

    /**
     * Récupère les statistiques publiques d'un utilisateur
     */
    private function getPublicUserStats(string $userId): array
    {
        // À implémenter : stats publiques seulement
        return [
            'total_games' => 0,
            'completed_games' => 0,
            'average_rating' => 0,
        ];
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