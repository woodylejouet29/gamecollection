<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Middleware\AuthMiddleware;
use App\Services\WishlistService;

/**
 * POST /api/wishlist/toggle
 * 
 * Corps attendu (JSON) :
 * {
 *   "game_id": int
 * }
 * 
 * Réponse :
 * - Si ajout : { "success": true, "data": { "action": "added" } }
 * - Si retrait : { "success": true, "data": { "action": "removed" } }
 * - Si erreur : { "success": false, "error": { "code": "...", "message": "..." } }
 */
class WishlistController
{
    public function toggle(): void
    {
        AuthMiddleware::requireAuth();

        $userId = AuthMiddleware::userId();
        if (!$userId) {
            $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Utilisateur non authentifié.']], 401);
        }

        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '{}', true);

        $gameId = (int) ($input['game_id'] ?? 0);
        if ($gameId <= 0) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'game_id invalide.']], 422);
        }

        try {
            $service = new WishlistService();
            $isInWishlist = $service->isInWishlist($userId, $gameId);

            if ($isInWishlist) {
                $service->removeFromWishlist($userId, $gameId);
                $action = 'removed';
            } else {
                $service->addToWishlist($userId, $gameId);
                $action = 'added';
            }

            $this->json(['success' => true, 'data' => ['action' => $action]]);
        } catch (\Throwable $e) {
            error_log('Wishlist toggle error: ' . $e->getMessage());
            $this->json(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Erreur interne.']], 500);
        }
    }

    /**
     * POST /api/wishlist/check
     *
     * Corps JSON : { "game_ids": [1,2,3] }
     * Réponse : { "success": true, "data": { "game_ids": [ ... ] } }
     */
    public function check(): void
    {
        AuthMiddleware::requireAuth();

        $userId = AuthMiddleware::userId();
        if (!$userId) {
            $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Utilisateur non authentifié.']], 401);
        }

        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '{}', true);

        $ids = $input['game_ids'] ?? [];
        if (!is_array($ids)) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'game_ids invalide.']], 422);
        }

        // Limite raisonnable (évite payloads énormes)
        $ids = array_slice($ids, 0, 200);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($x) => $x > 0)));

        try {
            $service = new WishlistService();
            $wishlisted = $service->wishlistedGameIds($userId, $ids);
            $this->json(['success' => true, 'data' => ['game_ids' => $wishlisted]]);
        } catch (\Throwable $e) {
            error_log('Wishlist check error: ' . $e->getMessage());
            $this->json(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Erreur interne.']], 500);
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