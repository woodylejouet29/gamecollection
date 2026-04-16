<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use GuzzleHttp\Client;
use Throwable;

/**
 * Liste paginée de la wishlist d'un utilisateur (avec jointure games).
 *
 * Filtres supportés (GET):
 *  - q (filtrage titre côté PHP)
 *  - platform (via games.platform_ids)
 *  - rating_min (via games.igdb_rating)
 *  - genre (filtrage côté PHP dans games.genres)
 *  - region (filtrage côté PHP via games.game_platforms[].region si embeddé)
 *
 * Tri :
 *  - par date de sortie la plus proche (release_date asc, nulls last)
 */
class WishlistListService
{
    private const SELECT = 'created_at,game_id,'
        . 'games(id,title,slug,cover_url,release_date,developer,igdb_rating,genres,platform_ids,'
        . 'game_platforms(region,platform_id,release_date))';

    private Client $http;
    private string $supabaseUrl;
    private string $serviceKey;

    public function __construct()
    {
        $this->http        = new Client(['timeout' => 10, 'http_errors' => false]);
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey  = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
    }

    /**
     * @return array{items: list<array>, total: int}
     */
    public function getItems(string $userId, array $filters = [], int $page = 1, int $perPage = 24): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(60, $perPage));
        $offset  = ($page - 1) * $perPage;

        $params = [
            'user_id' => 'eq.' . $userId,
            'select'  => self::SELECT,
            'order'   => 'games(release_date).asc.nullslast,created_at.desc',
            'limit'   => (string) $perPage,
            'offset'  => (string) $offset,
        ];

        // Filtre plateforme (performant via int[] + GIN)
        $platformId = (int) ($filters['platform'] ?? 0);
        if ($platformId > 0) {
            $params['games.platform_ids'] = 'cs.{' . $platformId . '}';
        }

        // Filtre note min (en base)
        $ratingMin = (int) ($filters['rating_min'] ?? 0);
        if ($ratingMin > 0) {
            $params['games.igdb_rating'] = 'gte.' . $ratingMin;
        }

        $url = $this->buildUrl('/rest/v1/wishlist', $params);

        try {
            $res = $this->http->get($url, [
                'headers' => array_merge($this->headers(), ['Prefer' => 'count=exact']),
            ]);
        } catch (Throwable $e) {
            Logger::warning('WishlistListService::getItems request failed', [
                'error' => $e->getMessage(),
            ]);
            return ['items' => [], 'total' => 0];
        }

        $status = (int) $res->getStatusCode();
        $raw    = (string) $res->getBody();
        $body   = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($status >= 400 || !is_array($body) || (is_array($body) && !array_is_list($body))) {
            Logger::warning('WishlistListService::getItems Supabase error', [
                'status' => $status,
                'url'    => $url,
                'body'   => mb_substr($raw, 0, 600),
            ]);
            return ['items' => [], 'total' => 0];
        }

        $range = $res->getHeaderLine('Content-Range');
        $total = $this->parseTotalFromRange($range, count($body));

        // Filtres côté PHP
        $q     = trim((string) ($filters['q'] ?? ''));
        $genre = trim((string) ($filters['genre'] ?? ''));
        $region= trim((string) ($filters['region'] ?? ''));

        if ($q !== '' || $genre !== '' || $region !== '') {
            $qLc = $q !== '' ? mb_strtolower($q) : '';
            $genreLc = $genre !== '' ? mb_strtolower($genre) : '';
            $regionUp = $region !== '' ? strtoupper($region) : '';

            $body = array_values(array_filter($body, static function ($row) use ($qLc, $genreLc, $regionUp) {
                $g = is_array($row['games'] ?? null) ? $row['games'] : [];
                if ($qLc !== '') {
                    $t = mb_strtolower((string) ($g['title'] ?? ''));
                    if (!str_contains($t, $qLc)) {
                        return false;
                    }
                }
                if ($genreLc !== '') {
                    $genres = $g['genres'] ?? [];
                    if (is_string($genres)) {
                        $genres = json_decode($genres, true) ?? [];
                    }
                    $genres = is_array($genres) ? $genres : [];
                    $names  = array_map(static fn($it) => mb_strtolower((string) (($it['name'] ?? '') ?: '')), $genres);
                    if (!in_array($genreLc, $names, true)) {
                        return false;
                    }
                }
                if ($regionUp !== '') {
                    $gps = $g['game_platforms'] ?? [];
                    if (!is_array($gps)) {
                        $gps = [];
                    }
                    $ok = false;
                    foreach ($gps as $gp) {
                        if (!is_array($gp)) continue;
                        $r = strtoupper((string) ($gp['region'] ?? ''));
                        if ($r === $regionUp) {
                            $ok = true;
                            break;
                        }
                    }
                    if (!$ok) {
                        return false;
                    }
                }
                return true;
            }));
        }

        // Normalisation
        $items = array_map([$this, 'normalizeRow'], $body);

        // Total exact non recalculé après filtre PHP : pour rester cohérent en UI,
        // on préfère afficher un total "page" si filtres PHP actifs.
        if ($q !== '' || $genre !== '' || $region !== '') {
            $total = count($items);
        }

        return ['items' => $items, 'total' => $total];
    }

    private function normalizeRow(array $row): array
    {
        $g = is_array($row['games'] ?? null) ? $row['games'] : [];
        return [
            'created_at' => $row['created_at'] ?? '',
            'game_id'    => (int) ($row['game_id'] ?? 0),
            'game'       => [
                'id'           => (int) ($g['id'] ?? 0),
                'title'        => (string) ($g['title'] ?? '—'),
                'slug'         => (string) ($g['slug'] ?? ''),
                'cover_url'    => (string) ($g['cover_url'] ?? ''),
                'release_date' => (string) ($g['release_date'] ?? ''),
                'developer'    => (string) ($g['developer'] ?? ''),
                'igdb_rating'  => isset($g['igdb_rating']) ? (float) $g['igdb_rating'] : null,
                'genres'       => $g['genres'] ?? [],
                'platform_ids' => $g['platform_ids'] ?? [],
            ],
        ];
    }

    private function buildUrl(string $path, array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
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
        ];
    }
}

