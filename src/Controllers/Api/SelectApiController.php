<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Middleware\AuthMiddleware;
use App\Services\CollectionService;

/**
 * POST /api/select/check-duplicate
 *
 * Corps attendu (JSON) :
 * {
 *   "game_id":         int,
 *   "platform_id":     int,
 *   "game_version_id": int|null,
 *   "region":          string,
 *   "game_type":       string
 * }
 *
 * Réponse : { "success": true, "data": { "isDuplicate": bool } }
 */
class SelectApiController
{
    public function checkDuplicate(): void
    {
        AuthMiddleware::requireAuth();

        $userId = AuthMiddleware::userId();
        if (!$userId) {
            $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED']], 401);
        }

        $raw   = file_get_contents('php://input');
        $input = json_decode($raw ?: '{}', true);

        $gameId    = (int) ($input['game_id']    ?? 0);
        $platId    = (int) ($input['platform_id'] ?? 0);
        $versionId = (isset($input['game_version_id']) && $input['game_version_id'] !== '' && $input['game_version_id'] !== null)
                        ? (int) $input['game_version_id'] : null;
        $region    = $this->normalizeRegion((string) ($input['region']    ?? ''));
        $gameType  = in_array($input['game_type'] ?? '', ['physical', 'digital'], true)
                        ? $input['game_type'] : 'physical';

        if ($gameId <= 0 || $platId <= 0) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR']], 422);
        }

        $service     = new CollectionService();
        $isDuplicate = $service->isDuplicate($userId, $gameId, $platId, $versionId, $region, $gameType);

        $this->json(['success' => true, 'data' => ['isDuplicate' => $isDuplicate]]);
    }

    private function normalizeRegion(string $r): string
    {
        return match($r) {
            'PAL'    => 'PAL',
            'NTSC-U' => 'NTSC-U',
            'NTSC-J' => 'NTSC-J',
            'NTSC-K' => 'NTSC-K',
            default  => 'other',
        };
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
