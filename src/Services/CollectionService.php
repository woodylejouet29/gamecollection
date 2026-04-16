<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use App\Core\Logger;

/**
 * Opérations CRUD sur collection_entries, reviews et wishlist via Supabase REST.
 */
class CollectionService
{
    private Client $http;
    private string $supabaseUrl;
    private string $serviceKey;

    public function __construct()
    {
        $this->http        = new Client(['timeout' => 8, 'http_errors' => false]);
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey  = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
    }

    // ──────────────────────────────────────────────────────────────────
    //  Vérification doublon
    // ──────────────────────────────────────────────────────────────────

    /**
     * Vérifie si une entrée identique existe déjà dans la collection.
     *
     * Note PostgreSQL : deux valeurs NULL dans une contrainte UNIQUE ne
     * sont PAS considérées comme égales (comportement standard). Ainsi,
     * game_version_id = NULL peut avoir plusieurs entrées. La vérification
     * explicite ci-dessous reflète ce comportement.
     */
    public function isDuplicate(
        string  $userId,
        int     $gameId,
        int     $platformId,
        ?int    $gameVersionId,
        string  $region,
        string  $gameType
    ): bool {
        $url = $this->supabaseUrl . '/rest/v1/collection_entries'
            . '?user_id=eq.'     . rawurlencode($userId)
            . '&game_id=eq.'     . $gameId
            . '&platform_id=eq.' . $platformId
            . '&region=eq.'      . rawurlencode($region)
            . '&game_type=eq.'   . rawurlencode($gameType)
            . '&select=id'
            . '&limit=1';

        $url .= $gameVersionId !== null
            ? '&game_version_id=eq.' . $gameVersionId
            : '&game_version_id=is.null';

        $res  = $this->http->get($url, ['headers' => $this->headers()]);
        $body = json_decode((string) $res->getBody(), true);

        return is_array($body) && count($body) > 0;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Ajout d'une entrée collection
    // ──────────────────────────────────────────────────────────────────

    /**
     * Insère une ligne dans collection_entries.
     *
     * @param  string $userId
     * @param  array  $entry  Champs validés côté contrôleur.
     * @return array{success: bool, id?: int, code?: string, details?: mixed}
     */
    public function addEntry(string $userId, array $entry): array
    {
        $payload = array_filter([
            'user_id'           => $userId,
            'game_id'           => (int) $entry['game_id'],
            'platform_id'       => (int) $entry['platform_id'],
            'game_version_id'   => isset($entry['game_version_id']) && $entry['game_version_id'] !== '' && $entry['game_version_id'] !== null
                                    ? (int) $entry['game_version_id'] : null,
            'region'            => $entry['region'],
            'game_type'         => $entry['game_type'],
            'status'            => $entry['status'],
            'acquired_at'       => (isset($entry['acquired_at']) && $entry['acquired_at'] !== '') ? $entry['acquired_at'] : null,
            'price_paid'        => (isset($entry['price_paid']) && $entry['price_paid'] !== '') ? (float) $entry['price_paid'] : null,
            'play_time_minutes' => (isset($entry['play_time_minutes']) && $entry['play_time_minutes'] !== '') ? (int) $entry['play_time_minutes'] : null,
            'rank_position'     => (int) ($entry['rank_position'] ?? 0),
            'physical_condition'=> $entry['physical_condition'] ?? null,
            'condition_note'    => isset($entry['condition_note']) && trim($entry['condition_note']) !== '' ? trim($entry['condition_note']) : null,
            'has_box'           => isset($entry['has_box']) ? (bool) $entry['has_box'] : null,
            'has_manual'        => isset($entry['has_manual']) ? (bool) $entry['has_manual'] : null,
        ], fn($v) => $v !== null);

        // Toujours inclure game_version_id même si null (requis pour la contrainte UNIQUE)
        $payload['game_version_id'] = isset($entry['game_version_id']) && $entry['game_version_id'] !== '' && $entry['game_version_id'] !== null
            ? (int) $entry['game_version_id'] : null;

        $res    = $this->http->post(
            $this->supabaseUrl . '/rest/v1/collection_entries',
            ['headers' => $this->headers(), 'json' => $payload]
        );
        $status = $res->getStatusCode();
        $body   = json_decode((string) $res->getBody(), true);

        if ($status === 201 && is_array($body) && !empty($body)) {
            $entryId = (int) $body[0]['id'];

            // Photos (optionnel) : max 3 URLs, uniquement si jeu physique
            $photoUrls = $entry['photo_urls'] ?? [];
            if (is_array($photoUrls) && !empty($photoUrls)) {
                $this->insertCollectionPhotos($entryId, $photoUrls);
            }

            return ['success' => true, 'id' => $entryId];
        }

        if ($status === 409) {
            return ['success' => false, 'code' => 'DUPLICATE_ENTRY'];
        }

        Logger::warning('CollectionService::addEntry failed', [
            'status' => $status,
            'body'   => $body,
        ]);
        return ['success' => false, 'code' => 'VALIDATION_ERROR', 'details' => $body];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Récupération d'une entrée
    // ──────────────────────────────────────────────────────────────────

    /**
     * Récupère une entrée de collection par son ID pour un utilisateur donné.
     * Retourne null si non trouvée.
     */
    public function getEntry(string $userId, int $entryId): ?array
    {
        $url = $this->supabaseUrl . '/rest/v1/collection_entries'
            . '?user_id=eq.' . rawurlencode($userId)
            . '&id=eq.' . $entryId
            . '&select=id,game_id,status,platform_id,game_version_id,region,game_type'
            . '&limit=1';

        $response = $this->http->get($url, [
            'headers' => $this->headers(),
        ]);

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data) || empty($data)) {
            return null;
        }

        return $data[0];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Avis
    // ──────────────────────────────────────────────────────────────────

    /**
     * Insère (ou ignore si déjà présent) un avis lié à une entrée.
     * Le statut Terminé / 100% doit avoir été vérifié en amont.
     */
    public function addReview(string $userId, int $entryId, int $gameId, int $rating, string $body): void
    {
        $this->http->post(
            $this->supabaseUrl . '/rest/v1/reviews',
            [
                'headers' => array_merge($this->headers(), ['Prefer' => 'resolution=ignore-duplicates,return=minimal']),
                'json'    => [
                    'user_id'  => $userId,
                    'entry_id' => $entryId,
                    'game_id'  => $gameId,
                    'rating'   => $rating,
                    'body'     => $body,
                ],
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  Wishlist
    // ──────────────────────────────────────────────────────────────────

    /**
     * Retire le jeu de la wishlist de l'utilisateur (silencieux si absent).
     */
    public function removeFromWishlist(string $userId, int $gameId): void
    {
        $this->http->delete(
            $this->supabaseUrl . '/rest/v1/wishlist'
            . '?user_id=eq.' . rawurlencode($userId)
            . '&game_id=eq.' . $gameId,
            ['headers' => $this->headers()]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    //  Interne
    // ──────────────────────────────────────────────────────────────────

    private function headers(): array
    {
        return [
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ];
    }

    /**
     * Insère jusqu'à 3 photos pour une entrée (collection_photos).
     * Non bloquant : en cas d'échec, l'entrée reste créée.
     *
     * @param list<string> $urls
     */
    private function insertCollectionPhotos(int $entryId, array $urls): void
    {
        $rows = [];
        $seen = [];
        $order = 0;
        foreach ($urls as $u) {
            if ($order >= 3) break;
            $u = trim((string) $u);
            if ($u === '') continue;
            if (isset($seen[$u])) continue;
            $seen[$u] = true;

            $rows[] = [
                'entry_id'       => $entryId,
                'url'            => $u,
                'display_order'  => $order,
            ];
            $order++;
        }
        if (empty($rows)) return;

        try {
            $res = $this->http->post(
                $this->supabaseUrl . '/rest/v1/collection_photos',
                [
                    'headers' => array_merge($this->headers(), ['Prefer' => 'return=minimal']),
                    'json'    => $rows,
                ]
            );
            $status = (int) $res->getStatusCode();
            if ($status >= 400) {
                Logger::warning('CollectionService::insertCollectionPhotos Supabase error', [
                    'status' => $status,
                    'entry_id' => $entryId,
                    'body' => mb_substr((string) $res->getBody(), 0, 600),
                ]);
            }
        } catch (\Throwable $e) {
            Logger::warning('CollectionService::insertCollectionPhotos failed', [
                'entry_id' => $entryId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
