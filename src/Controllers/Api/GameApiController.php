<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\SearchService;

/**
 * Endpoints JSON utilisés par search.js.
 *
 *   GET /api/games/search?q=...&limit=8  → autocomplétion live
 *   GET /api/games/{id}                  → fiche complète pour la popup
 */
class GameApiController
{
    /**
     * Autocomplétion live : retourne un tableau JSON de jeux.
     */
    public function search(): void
    {
        $q     = trim($_GET['q'] ?? '');
        $limit = min(max(1, (int) ($_GET['limit'] ?? 8)), 20);

        $service = new SearchService();
        $results = $service->searchSuggest($q, $limit);

        $this->json(['success' => true, 'data' => $results]);
    }

    /**
     * Fiche complète d'un jeu (pour la popup de sélection de version/plateforme).
     */
    public function show(string $id): void
    {
        $gameId = (int) $id;

        if ($gameId <= 0) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Identifiant invalide']], 400);
            return;
        }

        $service = new SearchService();
        $game    = $service->getGame($gameId);

        if ($game === null) {
            $this->json(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Jeu introuvable']], 404);
            return;
        }

        $this->json(['success' => true, 'data' => $game]);
    }

    // ──────────────────────────────────────────────
    //  Helper
    // ──────────────────────────────────────────────

    private function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
