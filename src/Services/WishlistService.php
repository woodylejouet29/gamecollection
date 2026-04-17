<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use App\Core\Logger;

class WishlistService
{
    private Client $http;
    private string $supabaseUrl;
    private string $serviceKey;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 8, 'http_errors' => false]);
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
    }

    /**
     * Vérifie si un jeu est dans la wishlist de l'utilisateur.
     */
    public function isInWishlist(string $userId, int $gameId): bool
    {
        $url = $this->supabaseUrl . '/rest/v1/wishlist'
            . '?user_id=eq.' . rawurlencode($userId)
            . '&game_id=eq.' . $gameId
            . '&select=id';

        $response = $this->http->get($url, [
            'headers' => $this->headers(),
        ]);

        $data = json_decode((string) $response->getBody(), true);
        return !empty($data) && is_array($data);
    }

    /**
     * Retourne les IDs des jeux présents dans la wishlist (batch).
     *
     * @param list<int> $gameIds
     * @return list<int>
     */
    public function wishlistedGameIds(string $userId, array $gameIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $gameIds), static fn($x) => $x > 0)));
        if (empty($ids)) return [];

        // Supabase PostgREST : in.(1,2,3)
        $in = implode(',', $ids);
        $url = $this->supabaseUrl . '/rest/v1/wishlist'
            . '?user_id=eq.' . rawurlencode($userId)
            . '&game_id=in.(' . $in . ')'
            . '&select=game_id';

        $response = $this->http->get($url, [
            'headers' => $this->headers(),
        ]);

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) return [];

        $out = [];
        foreach ($data as $row) {
            $gid = (int) ($row['game_id'] ?? 0);
            if ($gid > 0) $out[] = $gid;
        }
        return array_values(array_unique($out));
    }

    /**
     * Ajoute un jeu à la wishlist.
     */
    public function addToWishlist(string $userId, int $gameId): void
    {
        $url = $this->supabaseUrl . '/rest/v1/wishlist';

        $response = $this->http->post($url, [
            'headers' => $this->headers(),
            'json' => [
                'user_id' => $userId,
                'game_id' => $gameId,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 201 && $status !== 409) { // 409 = déjà présent (unique violation)
            throw new \RuntimeException('Failed to add to wishlist, HTTP ' . $status);
        }
    }

    /**
     * Retire un jeu de la wishlist.
     */
    public function removeFromWishlist(string $userId, int $gameId): void
    {
        $url = $this->supabaseUrl . '/rest/v1/wishlist'
            . '?user_id=eq.' . rawurlencode($userId)
            . '&game_id=eq.' . $gameId;

        $response = $this->http->delete($url, [
            'headers' => $this->headers(),
        ]);

        $status = $response->getStatusCode();
        if ($status !== 204 && $status !== 200) {
            throw new \RuntimeException('Failed to remove from wishlist, HTTP ' . $status);
        }
    }

    /**
     * Récupère les en-têtes d'authentification Supabase.
     */
    private function headers(): array
    {
        return [
            'apikey' => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'Content-Type' => 'application/json',
            'Prefer' => 'return=minimal',
        ];
    }
}