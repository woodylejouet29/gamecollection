<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use App\Core\Logger;
use Throwable;

/**
 * Service de lecture / écriture de la collection personnelle.
 *
 * Méthodes publiques :
 *   getEntries()       – liste paginée avec jointures (games, platforms, reviews)
 *   getStats()         – statistiques globales de la collection
 *   getFilterOptions() – plateformes et statuts disponibles pour les filtres
 *   updateEntry()      – PATCH d'une entrée + mise à jour de l'avis associé
 *   deleteEntry()      – DELETE d'une entrée (cascade reviews)
 *   exportAll()        – retourne toutes les entrées (sans pagination) pour export
 */
class CollectionListService
{
    // ──────────────────────────────────────────────────────────────────
    //  Tri supportés → mapping Supabase ORDER BY
    // ──────────────────────────────────────────────────────────────────
    private const SORT_MAP = [
        'recent'       => 'created_at.desc',
        'acquired_desc'=> 'acquired_at.desc.nullslast',
        'acquired_asc' => 'acquired_at.asc.nullslast',
        'rank_asc'     => 'rank_position.asc,created_at.desc',
        'price_desc'   => 'price_paid.desc.nullslast',
        'title_asc'    => 'games(title).asc.nullslast',
        'title_desc'   => 'games(title).desc.nullslast',
        // PostgREST ne peut pas trier directement sur un champ d'une relation 1-N embeddée (`reviews(...)`)
        // sans vue dédiée / jointure contrôlée. On trie donc sur une colonne déjà présente sur `games`.
        'rating_desc'  => 'games(igdb_rating).desc.nullslast',
    ];

    // Preset "listes personnelles fixes" → condition Supabase
    private const LIST_FILTERS = [
        'all'             => [],
        'playing'         => ['status' => 'eq.playing'],
        'finished'        => ['status' => 'in.(completed,hundred_percent)'],
        'hundred_percent' => ['status' => 'eq.hundred_percent'],
        'abandoned'       => ['status' => 'eq.abandoned'],
        'physical'        => ['game_type' => 'eq.physical'],
        'digital'         => ['game_type' => 'eq.digital'],
        'ranked'          => ['rank_position' => 'gt.0'],
    ];

    private const SELECT_ENTRIES = 'id,game_id,platform_id,game_version_id,region,game_type,'
        . 'status,acquired_at,price_paid,play_time_minutes,rank_position,'
        . 'physical_condition,condition_note,has_box,has_manual,created_at,'
        . 'games(id,title,slug,cover_url,igdb_rating,genres,developer,release_date),'
        . 'platforms(name,abbreviation),'
        . 'game_versions(name),'
        . 'reviews(rating,body)';

    private Client $http;
    private string $supabaseUrl;
    private string $serviceKey;

    public function __construct()
    {
        $this->http        = new Client(['timeout' => 10, 'http_errors' => false]);
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey  = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
    }

    // ──────────────────────────────────────────────────────────────────
    //  Lecture paginée
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  string $userId
     * @param  array  $filters  Clés : list, platform, status, game_type, region, condition, q
     * @param  int    $page
     * @param  int    $perPage
     * @return array{entries: array, total: int}
     */
    public function getEntries(string $userId, array $filters = [], int $page = 1, int $perPage = 24): array
    {
        $params = ['user_id' => 'eq.' . $userId];

        // Preset "liste" (s'applique en premier, peut être écrasé par les filtres manuels)
        $listKey = $filters['list'] ?? 'all';
        $listPreset = self::LIST_FILTERS[$listKey] ?? [];
        foreach ($listPreset as $k => $v) {
            $params[$k] = $v;
        }

        // Filtres manuels
        if (!empty($filters['platform'])) {
            $params['platform_id'] = 'eq.' . (int) $filters['platform'];
        }
        if (!empty($filters['status']) && empty($listPreset['status'])) {
            $params['status'] = 'eq.' . $filters['status'];
        }
        if (!empty($filters['game_type']) && empty($listPreset['game_type'])) {
            $params['game_type'] = 'eq.' . $filters['game_type'];
        }
        if (!empty($filters['region'])) {
            $params['region'] = 'eq.' . $filters['region'];
        }
        if (!empty($filters['condition'])) {
            $params['physical_condition'] = 'eq.' . $filters['condition'];
        }

        $sort   = self::SORT_MAP[$filters['sort'] ?? 'recent'] ?? 'created_at.desc';
        $offset = ($page - 1) * $perPage;

        $url = $this->buildUrl('/rest/v1/collection_entries', array_merge($params, [
            'select' => self::SELECT_ENTRIES,
            'order'  => $sort,
            'limit'  => $perPage,
            'offset' => $offset,
        ]));

        $res     = $this->http->get($url, [
            'headers' => array_merge($this->headers(), ['Prefer' => 'count=exact']),
        ]);
        $status  = (int) $res->getStatusCode();
        $raw     = (string) $res->getBody();
        $body    = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        // PostgREST peut renvoyer un objet d'erreur (ex: {"code":"PGRST118",...}) au lieu d'une liste.
        // Dans ce cas, on évite de faire array_map() dessus (sinon TypeError sur normalizeEntry()).
        if ($status >= 400 || !is_array($body) || (is_array($body) && !array_is_list($body))) {
            Logger::warning('CollectionListService::getEntries Supabase error', [
                'status' => $status,
                'url'    => $url,
                'body'   => is_string($raw) ? mb_substr($raw, 0, 600) : null,
            ]);
            return ['entries' => [], 'total' => 0];
        }
        $range   = $res->getHeaderLine('Content-Range');
        $total   = $this->parseTotalFromRange($range, count($body));

        // Filtrage titre côté PHP (pas de filtre ILIKE sur embedded table en REST simple)
        if (!empty($filters['q'])) {
            $q = mb_strtolower(trim($filters['q']));
            $body = array_values(array_filter($body, function ($e) use ($q) {
                $title = mb_strtolower((string) ($e['games']['title'] ?? ''));
                return str_contains($title, $q);
            }));
        }

        return [
            'entries' => array_map([$this, 'normalizeEntry'], $body),
            'total'   => $total,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Statistiques globales
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array{
     *   total: int,
     *   unique_games: int,
     *   physical_count: int,
     *   digital_count: int,
     *   total_value: float,
     *   total_play_minutes: int,
     *   statuses: array<string,int>
     * }
     */
    public function getStats(string $userId): array
    {
        $url  = $this->buildUrl('/rest/v1/collection_entries', [
            'user_id' => 'eq.' . $userId,
            'select'  => 'game_id,game_type,price_paid,play_time_minutes,status',
            'limit'   => '5000',
        ]);

        try {
            $res  = $this->http->get($url, ['headers' => $this->headers()]);
            $rows = json_decode((string) $res->getBody(), true) ?? [];
        } catch (Throwable) {
            $rows = [];
        }

        $stats = [
            'total'              => count($rows),
            'unique_games'       => 0,
            'physical_count'     => 0,
            'digital_count'      => 0,
            'total_value'        => 0.0,
            'total_play_minutes' => 0,
            'statuses'           => [],
        ];

        $gameIds = [];
        foreach ($rows as $r) {
            $gameIds[$r['game_id']] = true;
            if ($r['game_type'] === 'physical') $stats['physical_count']++;
            else                                $stats['digital_count']++;
            if (isset($r['price_paid']))         $stats['total_value']        += (float) $r['price_paid'];
            if (isset($r['play_time_minutes']))  $stats['total_play_minutes'] += (int)   $r['play_time_minutes'];
            $s = $r['status'] ?? 'owned';
            $stats['statuses'][$s] = ($stats['statuses'][$s] ?? 0) + 1;
        }
        $stats['unique_games'] = count($gameIds);

        return $stats;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Options de filtres
    // ──────────────────────────────────────────────────────────────────

    /**
     * Plateformes présentes dans la collection (pour le <select> de filtres).
     */
    public function getFilterOptions(string $userId): array
    {
        $url = $this->buildUrl('/rest/v1/collection_entries', [
            'user_id' => 'eq.' . $userId,
            'select'  => 'platform_id,platforms(id,name,abbreviation)',
            'limit'   => '5000',
        ]);

        try {
            $res  = $this->http->get($url, ['headers' => $this->headers()]);
            $rows = json_decode((string) $res->getBody(), true) ?? [];
        } catch (Throwable) {
            return ['platforms' => []];
        }

        $seen  = [];
        $plats = [];
        foreach ($rows as $r) {
            $pid = (int) ($r['platform_id'] ?? 0);
            if ($pid && !isset($seen[$pid])) {
                $seen[$pid] = true;
                $p = $r['platforms'] ?? [];
                $plats[] = [
                    'id'          => $pid,
                    'name'        => $p['name'] ?? "Plateforme #$pid",
                    'abbreviation'=> $p['abbreviation'] ?? '',
                ];
            }
        }
        usort($plats, fn($a, $b) => strcmp($a['name'], $b['name']));

        return ['platforms' => $plats];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Mise à jour d'une entrée
    // ──────────────────────────────────────────────────────────────────

    /**
     * Met à jour les champs modifiables d'une entrée.
     * Si rating + review_body sont fournis et le statut le permet, l'avis est inséré/mis à jour.
     *
     * @param  array $data  Clés : entry_id, status, rank_position, physical_condition,
     *                            play_time_minutes, rating, review_body
     */
    public function updateEntry(string $userId, array $data): array
    {
        $entryId = (int) ($data['entry_id'] ?? 0);
        if ($entryId <= 0) return ['success' => false, 'code' => 'INVALID_ENTRY'];

        // Récupérer l'entrée pour vérifier la propriété et le game_type
        $check = $this->getEntryById($userId, $entryId);
        if (!$check) return ['success' => false, 'code' => 'NOT_FOUND'];

        $patch = [];
        if (isset($data['status'])             && $data['status']             !== '') $patch['status']             = $data['status'];
        if (isset($data['rank_position']))                                            $patch['rank_position']      = (int) $data['rank_position'];
        if (isset($data['physical_condition']) && $data['physical_condition'] !== '') $patch['physical_condition'] = $data['physical_condition'];
        if (isset($data['play_time_minutes']))                                        $patch['play_time_minutes']  = $data['play_time_minutes'] !== '' ? (int) $data['play_time_minutes'] : null;

        if (!empty($patch)) {
            $res    = $this->http->patch(
                $this->supabaseUrl . '/rest/v1/collection_entries?id=eq.' . $entryId . '&user_id=eq.' . rawurlencode($userId),
                ['headers' => array_merge($this->headers(), ['Prefer' => 'return=minimal']), 'json' => $patch]
            );
            if ($res->getStatusCode() >= 400) {
                return ['success' => false, 'code' => 'UPDATE_FAILED'];
            }
        }

        // Avis — uniquement pour completed/hundred_percent
        $newStatus = $patch['status'] ?? ($check['status'] ?? '');
        if (in_array($newStatus, ['completed', 'hundred_percent'], true)
            && isset($data['rating']) && $data['rating'] !== ''
            && isset($data['review_body']) && mb_strlen(trim((string) $data['review_body'])) >= 100
        ) {
            $rating     = (int) $data['rating'];
            $reviewBody = trim((string) $data['review_body']);
            if ($rating >= 1 && $rating <= 10) {
                $this->upsertReview($userId, $entryId, (int) $check['game_id'], $rating, $reviewBody);
            }
        }

        return ['success' => true];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Suppression d'une entrée
    // ──────────────────────────────────────────────────────────────────

    public function deleteEntry(string $userId, int $entryId): bool
    {
        if ($entryId <= 0) return false;

        $res = $this->http->delete(
            $this->supabaseUrl . '/rest/v1/collection_entries?id=eq.' . $entryId . '&user_id=eq.' . rawurlencode($userId),
            ['headers' => array_merge($this->headers(), ['Prefer' => 'return=minimal'])]
        );
        return $res->getStatusCode() < 400;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Export complet
    // ──────────────────────────────────────────────────────────────────

    /**
     * Retourne toutes les entrées de l'utilisateur (jusqu'à 5 000) pour export.
     */
    public function exportAll(string $userId): array
    {
        $url = $this->buildUrl('/rest/v1/collection_entries', [
            'user_id' => 'eq.' . $userId,
            'select'  => self::SELECT_ENTRIES,
            'order'   => 'games(title).asc.nullslast',
            'limit'   => '5000',
        ]);

        try {
            $res  = $this->http->get($url, ['headers' => $this->headers()]);
            $body = json_decode((string) $res->getBody(), true) ?? [];
        } catch (Throwable) {
            return [];
        }

        return array_map([$this, 'normalizeEntry'], $body);
    }

    /**
     * Export filtré (mêmes filtres que /collection), sans pagination UI.
     * Récupère les entrées par batch pour éviter les timeouts.
     *
     * @return list<array>
     */
    public function exportFiltered(string $userId, array $filters = []): array
    {
        $params = ['user_id' => 'eq.' . $userId];

        // Preset "liste" (s'applique en premier, peut être écrasé par les filtres manuels)
        $listKey = $filters['list'] ?? 'all';
        $listPreset = self::LIST_FILTERS[$listKey] ?? [];
        foreach ($listPreset as $k => $v) {
            $params[$k] = $v;
        }

        // Filtres manuels (même logique que getEntries())
        if (!empty($filters['platform'])) {
            $params['platform_id'] = 'eq.' . (int) $filters['platform'];
        }
        if (!empty($filters['status']) && empty($listPreset['status'])) {
            $params['status'] = 'eq.' . $filters['status'];
        }
        if (!empty($filters['game_type']) && empty($listPreset['game_type'])) {
            $params['game_type'] = 'eq.' . $filters['game_type'];
        }
        if (!empty($filters['region'])) {
            $params['region'] = 'eq.' . $filters['region'];
        }
        if (!empty($filters['condition'])) {
            $params['physical_condition'] = 'eq.' . $filters['condition'];
        }

        $sort = self::SORT_MAP[$filters['sort'] ?? 'recent'] ?? 'created_at.desc';

        // Export: cap pour éviter explosions (aligné avec exportAll)
        $maxRows = 5000;
        $batch   = 1000;
        $offset  = 0;

        $out = [];

        do {
            $url = $this->buildUrl('/rest/v1/collection_entries', array_merge($params, [
                'select' => self::SELECT_ENTRIES,
                'order'  => $sort,
                'limit'  => (string) $batch,
                'offset' => (string) $offset,
            ]));

            try {
                $res  = $this->http->get($url, ['headers' => $this->headers()]);
                $raw  = (string) $res->getBody();
                $rows = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                $rows = is_array($rows) ? $rows : [];
            } catch (Throwable) {
                $rows = [];
            }

            if (empty($rows)) {
                break;
            }

            // Filtrage titre côté PHP (comme getEntries)
            if (!empty($filters['q'])) {
                $q = mb_strtolower(trim((string) $filters['q']));
                $rows = array_values(array_filter($rows, function ($e) use ($q) {
                    $title = mb_strtolower((string) ($e['games']['title'] ?? ''));
                    return $q === '' ? true : str_contains($title, $q);
                }));
            }

            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $out[] = $this->normalizeEntry($row);
                if (count($out) >= $maxRows) {
                    break 2;
                }
            }

            $offset += $batch;
        } while (true);

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers privés
    // ──────────────────────────────────────────────────────────────────

    private function getEntryById(string $userId, int $entryId): ?array
    {
        $url = $this->buildUrl('/rest/v1/collection_entries', [
            'id'      => 'eq.' . $entryId,
            'user_id' => 'eq.' . $userId,
            'select'  => 'id,game_id,game_type,status',
            'limit'   => '1',
        ]);
        $res  = $this->http->get($url, ['headers' => $this->headers()]);
        $rows = json_decode((string) $res->getBody(), true) ?? [];
        return $rows[0] ?? null;
    }

    private function upsertReview(string $userId, int $entryId, int $gameId, int $rating, string $body): void
    {
        $this->http->post(
            $this->supabaseUrl . '/rest/v1/reviews',
            [
                'headers' => array_merge($this->headers(), [
                    'Prefer' => 'resolution=merge-duplicates,return=minimal',
                ]),
                'json' => [
                    'user_id'  => $userId,
                    'entry_id' => $entryId,
                    'game_id'  => $gameId,
                    'rating'   => $rating,
                    'body'     => $body,
                ],
            ]
        );
    }

    /**
     * Normalise une ligne brute Supabase en tableau homogène.
     */
    private function normalizeEntry(array $row): array
    {
        $game     = is_array($row['games']         ?? null) ? $row['games']         : [];
        $platform = is_array($row['platforms']     ?? null) ? $row['platforms']     : [];
        $version  = is_array($row['game_versions'] ?? null) ? $row['game_versions'] : null;
        $reviews  = is_array($row['reviews']       ?? null) ? $row['reviews']       : [];
        $review   = !empty($reviews) ? (is_array($reviews[0] ?? null) ? $reviews[0] : null) : null;

        return [
            'id'                 => (int) ($row['id']                 ?? 0),
            'game_id'            => (int) ($row['game_id']            ?? 0),
            'platform_id'        => (int) ($row['platform_id']        ?? 0),
            'game_version_id'    => isset($row['game_version_id'])    ? (int) $row['game_version_id']    : null,
            'region'             => $row['region']             ?? '',
            'game_type'          => $row['game_type']          ?? 'physical',
            'status'             => $row['status']             ?? 'owned',
            'acquired_at'        => $row['acquired_at']        ?? null,
            'price_paid'         => isset($row['price_paid'])  ? (float) $row['price_paid']  : null,
            'play_time_minutes'  => isset($row['play_time_minutes']) ? (int) $row['play_time_minutes'] : null,
            'rank_position'      => (int) ($row['rank_position'] ?? 0),
            'physical_condition' => $row['physical_condition'] ?? null,
            'condition_note'     => $row['condition_note']     ?? null,
            'has_box'            => $row['has_box']    ?? null,
            'has_manual'         => $row['has_manual'] ?? null,
            'created_at'         => $row['created_at'] ?? '',
            'game'     => [
                'id'          => (int) ($game['id']   ?? 0),
                'title'       => $game['title']       ?? '—',
                'slug'        => $game['slug']        ?? '',
                'cover_url'   => $game['cover_url']   ?? '',
                'igdb_rating' => isset($game['igdb_rating']) ? (float) $game['igdb_rating'] : null,
                'genres'      => is_string($game['genres'] ?? null) ? json_decode($game['genres'], true) : ($game['genres'] ?? []),
                'developer'   => $game['developer']   ?? '',
                'release_date'=> $game['release_date']?? '',
            ],
            'platform' => [
                'name'        => $platform['name']         ?? '—',
                'abbreviation'=> $platform['abbreviation'] ?? '',
            ],
            'version' => $version ? ['name' => $version['name'] ?? ''] : null,
            'review'  => $review,
        ];
    }

    private function buildUrl(string $path, array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
        }
        return $this->supabaseUrl . $path . ($parts ? '?' . implode('&', $parts) : '');
    }

    private function parseTotalFromRange(string $range, int $fallback): int
    {
        if (preg_match('/\/(\d+)$/', $range, $m)) return (int) $m[1];
        return $fallback;
    }

    private function headers(): array
    {
        return [
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ];
    }
}
