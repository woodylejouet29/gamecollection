<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Throwable;

class UserProfileService
{
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
     * Profil par username (slug). Si $viewerUserId === id → profil "privé" (on peut afficher même si collection_public=false).
     *
     * @return array{user: array, platforms: list<array>, genres: list<string>}|null
     */
    public function getByUsername(string $username, ?string $viewerUserId = null): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $uRows = $this->get('/rest/v1/users', [
            'username' => 'eq.' . $username,
            'select'   => 'id,username,avatar_url,bio,collection_public,created_at',
            'limit'    => '1',
        ]);
        $u = $uRows[0] ?? null;
        if (!is_array($u) || empty($u['id'])) {
            return null;
        }

        $userId = (string) $u['id'];
        $isOwner = $viewerUserId !== null && $viewerUserId === $userId;

        if (!$isOwner && empty($u['collection_public'])) {
            // Respecte la visibilité (même si service_role bypass la RLS)
            return [
                'user' => array_merge($u, ['_private' => true]),
                'platforms' => [],
                'genres' => [],
            ];
        }

        $promises = [
            'platforms' => $this->http->getAsync($this->buildUrl('/rest/v1/user_platforms', [
                'user_id' => 'eq.' . $userId,
                'select'  => 'platforms(id,name,slug,abbreviation,generation)',
                'limit'   => '200',
            ]), ['headers' => $this->headers()]),
            'genres' => $this->http->getAsync($this->buildUrl('/rest/v1/user_genres', [
                'user_id' => 'eq.' . $userId,
                'select'  => 'genre_name',
                'limit'   => '200',
            ]), ['headers' => $this->headers()]),
        ];

        try {
            $settled = PromiseUtils::settle($promises)->wait();
        } catch (Throwable) {
            $settled = [];
        }

        $platsRows = $this->fromSettled($settled, 'platforms') ?? [];
        $genresRows = $this->fromSettled($settled, 'genres') ?? [];

        $platforms = [];
        foreach ($platsRows as $r) {
            if (!is_array($r)) continue;
            $p = $r['platforms'] ?? null;
            if (is_array($p) && !empty($p['id'])) {
                $platforms[] = $p;
            }
        }

        $genres = [];
        foreach ($genresRows as $r) {
            if (!is_array($r)) continue;
            $name = trim((string) ($r['genre_name'] ?? ''));
            if ($name !== '') $genres[] = $name;
        }

        return [
            'user'      => array_merge($u, ['_private' => false, '_is_owner' => $isOwner]),
            'platforms' => $platforms,
            'genres'    => $genres,
        ];
    }

    /**
     * Statistiques + derniers avis (publics ou owner).
     *
     * @return array{stats: array, last_reviews: list<array>}
     */
    public function getStatsAndReviews(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return ['stats' => [], 'last_reviews' => []];
        }

        $promises = [
            'entries_total' => $this->http->requestAsync('HEAD', $this->buildUrl('/rest/v1/collection_entries', [
                'user_id' => 'eq.' . $userId,
                'select'  => 'id',
            ]), ['headers' => array_merge($this->headers(), ['Prefer' => 'count=exact'])]),
            'entries_completed' => $this->http->requestAsync('HEAD', $this->buildUrl('/rest/v1/collection_entries', [
                'user_id' => 'eq.' . $userId,
                'status'  => 'in.(completed,hundred_percent)',
                'select'  => 'id',
            ]), ['headers' => array_merge($this->headers(), ['Prefer' => 'count=exact'])]),
            'entries_hundred' => $this->http->requestAsync('HEAD', $this->buildUrl('/rest/v1/collection_entries', [
                'user_id' => 'eq.' . $userId,
                'status'  => 'eq.hundred_percent',
                'select'  => 'id',
            ]), ['headers' => array_merge($this->headers(), ['Prefer' => 'count=exact'])]),
            'reviews' => $this->http->getAsync($this->buildUrl('/rest/v1/reviews', [
                'user_id' => 'eq.' . $userId,
                'select'  => 'rating',
                'limit'   => '5000',
            ]), ['headers' => $this->headers()]),
            'last_reviews' => $this->http->getAsync($this->buildUrl('/rest/v1/reviews', [
                'user_id' => 'eq.' . $userId,
                'select'  => 'rating,body,created_at,games(title,slug,cover_url)',
                'order'   => 'created_at.desc',
                'limit'   => '5',
            ]), ['headers' => $this->headers()]),
        ];

        try {
            $settled = PromiseUtils::settle($promises)->wait();
        } catch (Throwable $e) {
            Logger::warning('UserProfileService stats settle failed', ['error' => $e->getMessage()]);
            $settled = [];
        }

        $total = $this->countFromHeadSettled($settled, 'entries_total');
        $completed = $this->countFromHeadSettled($settled, 'entries_completed');
        $hundred = $this->countFromHeadSettled($settled, 'entries_hundred');

        $ratingsRows = $this->fromSettled($settled, 'reviews') ?? [];
        $ratings = [];
        foreach ($ratingsRows as $r) {
            if (!is_array($r)) continue;
            $val = $r['rating'] ?? null;
            if (is_numeric($val)) $ratings[] = (int) $val;
        }
        $avg = null;
        if (!empty($ratings)) {
            $avg = round(array_sum($ratings) / count($ratings), 1);
        }

        $lastReviews = $this->fromSettled($settled, 'last_reviews') ?? [];
        $last = [];
        foreach ($lastReviews as $r) {
            if (!is_array($r)) continue;
            $g = is_array($r['games'] ?? null) ? $r['games'] : [];
            $last[] = [
                'rating' => (int) ($r['rating'] ?? 0),
                'body'   => (string) ($r['body'] ?? ''),
                'created_at' => (string) ($r['created_at'] ?? ''),
                'game'   => [
                    'title' => (string) ($g['title'] ?? ''),
                    'slug'  => (string) ($g['slug'] ?? ''),
                    'cover_url' => (string) ($g['cover_url'] ?? ''),
                ],
            ];
        }

        return [
            'stats' => [
                'total_games' => $total,
                'completed_games' => $completed,
                'hundred_percent_games' => $hundred,
                'average_rating' => $avg,
            ],
            'last_reviews' => $last,
        ];
    }

    /**
     * Collection publique paginée (doit être appelée uniquement si profil public/owner déjà validé).
     *
     * @return array{entries:list<array>, total:int}
     */
    public function getCollectionEntries(string $userId, int $page = 1, int $perPage = 24): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(60, $perPage));

        $svc = new CollectionListService();
        // Reuse: même shape que /collection (cards)
        return $svc->getEntries($userId, ['list' => 'all', 'sort' => 'recent'], $page, $perPage);
    }

    // ─────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────

    private function get(string $path, array $params): array
    {
        $url = $this->buildUrl($path, $params);
        try {
            $res = $this->http->get($url, ['headers' => $this->headers()]);
            $data = json_decode((string) $res->getBody(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function buildUrl(string $path, array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return $this->supabaseUrl . $path . ($parts ? '?' . implode('&', $parts) : '');
    }

    private function headers(): array
    {
        return [
            'apikey'        => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
        ];
    }

    private function fromSettled(array $settled, string $key): ?array
    {
        if (!isset($settled[$key]) || $settled[$key]['state'] !== 'fulfilled') return null;
        $res = $settled[$key]['value'];
        $data = json_decode((string) $res->getBody(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        return is_array($data) ? $data : null;
    }

    private function countFromHeadSettled(array $settled, string $key): int
    {
        if (!isset($settled[$key]) || $settled[$key]['state'] !== 'fulfilled') return 0;
        $res = $settled[$key]['value'];
        $range = $res->getHeaderLine('Content-Range');
        if (preg_match('/\/(\d+)$/', $range, $m)) return (int) $m[1];
        return 0;
    }
}

