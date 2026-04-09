<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Middleware\AuthMiddleware;
use App\Services\CollectionService;

/**
 * POST /api/reviews/add
 * 
 * Validation : statut Terminé / 100% obligatoire pour ajouter un avis
 * 
 * Corps attendu (JSON) :
 * {
 *   "entry_id": int,
 *   "rating": int (1-10),
 *   "review_body": string (≥100 caractères)
 * }
 * 
 * Réponse standard :
 * - { "success": true, "data": { "review_id": int } }
 * - { "success": false, "error": { "code": "...", "message": "..." } }
 */
class ReviewController
{
    private const REVIEW_STATUSES = ['completed', 'hundred_percent'];

    public function add(): void
    {
        AuthMiddleware::requireAuth();

        $userId = AuthMiddleware::userId();
        if (!$userId) {
            $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Utilisateur non authentifié.']], 401);
        }

        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '{}', true);

        $entryId = (int) ($input['entry_id'] ?? 0);
        $rating = (int) ($input['rating'] ?? 0);
        $reviewBody = trim((string) ($input['review_body'] ?? ''));

        // Validation basique
        if ($entryId <= 0) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'entry_id invalide.']], 422);
        }

        if ($rating < 1 || $rating > 10) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'La note doit être entre 1 et 10.']], 422);
        }

        if (mb_strlen($reviewBody) < 100) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'L\'avis doit contenir au moins 100 caractères.']], 422);
        }

        try {
            $service = new CollectionService();
            
            // Vérifier que l'entrée appartient bien à l'utilisateur
            $entry = $service->getEntry($userId, $entryId);
            if (!$entry) {
                $this->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Entrée non trouvée.']], 404);
            }

            // Vérifier que le statut permet un avis
            if (!in_array($entry['status'] ?? '', self::REVIEW_STATUSES, true)) {
                $this->json(['success' => false, 'error' => [
                    'code' => 'INVALID_STATUS_FOR_REVIEW', 
                    'message' => 'Les avis sont réservés aux jeux terminés ou complétés à 100%.'
                ]], 422);
            }

            // Ajouter l'avis
            $service->addReview($userId, $entryId, $entry['game_id'], $rating, $reviewBody);

            $this->json(['success' => true, 'data' => ['entry_id' => $entryId]]);
        } catch (\Throwable $e) {
            error_log('Review add error: ' . $e->getMessage());
            $this->json(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Erreur lors de l\'ajout de l\'avis.']], 500);
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