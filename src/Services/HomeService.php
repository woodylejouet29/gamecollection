<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use Throwable;

/**
 * Récupère les données de la page d'accueil depuis Supabase (REST API).
 * Toutes les requêtes sont mises en cache 1 heure dans storage/cache/home/.
 */
class HomeService
{
    /** Plateformes à exclure (IDs locaux, colonne `platforms.id`). */
    private const EXCLUDED_PLATFORM_IDS = [28, 32, 61, 62, 68, 183];
    /** Filet de sécurité si `igdb_id` est absent/null. */
    private const EXCLUDED_PLATFORM_NAMES = [
        'android',
        'ios',
        'blackberry os',
        'windows phone',
        'web browser',
        'windows mobile',
    ];
    private Client $http;
    private string $supabaseUrl;
    private string $serviceKey;
    private string $cacheDir;
    /**
     * TTL "fresh" par défaut (secondes). Certaines clés le surchargent.
     * Le "stale" permet de servir une valeur expirée pendant un refresh pour éviter les pics de latence.
     */
    private int    $cacheTtl = 3600;
    private int    $staleTtl = 86400; // 24h
    /** @var array<string, array{ms: float, cache: 'hit'|'miss'|'stale', ok: bool}> */
    private array  $timings = [];

    public function __construct()
    {
        $this->http        = new Client(['timeout' => 8, 'http_errors' => false]);
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey  = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
        $this->cacheDir    = dirname(__DIR__, 2) . '/storage/cache/home';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Retourne toutes les données nécessaires à la page d'accueil.
     */
    public function getHomeData(): array
    {
        $data = [
            // Les clés "datées" évitent les effets de bord (année/jour) et réduisent les recalculs inutiles.
            // 1er param = label Server-Timing (stable), cache_key = suffixée si nécessaire.
            'mostAwaitedGames' => $this->cached('most_awaited', fn() => $this->fetchMostAwaitedGames(), 3600, 86400, 'most_awaited_v2'),
            'mostAddedGames'   => $this->cached('most_added', fn() => $this->fetchMostAddedGames(), 3600, 86400, 'most_added_v1'),
            'topRatedGames'   => $this->cached('top_rated_year', fn() => $this->fetchTopRatedThisYear(), 21600, 259200, 'top_rated_year_' . date('Y')), // 6h fresh, 72h stale
            'todayGames'      => $this->cached('today_games', fn() => $this->fetchTodayGames(), 600, 21600, 'today_games_' . date('Y-m-d')), // 10m fresh, 6h stale
            'latestReviews'   => $this->cached('latest_reviews', fn() => $this->fetchLatestReviews(), 120, 1800),
            'genreHighlights' => $this->cached('genre_highlights', fn() => $this->fetchGenreHighlights(), 43200, 259200, 'genre_highlights_v2'), // 12h fresh, 72h stale
            // bump cache key après exclusion mobile/web
            'topPlatforms'    => $this->cached('top_platforms', fn() => $this->fetchTopPlatforms(), 86400, 604800, 'top_platforms_v3'),
            'stats'           => $this->cached('stats', fn() => $this->fetchStats(), 1800, 21600),
        ];

        // Métadonnées utiles pour diagnostiquer les lenteurs (non utilisées par la vue).
        $data['_meta'] = [
            'homeServiceTimings' => $this->timings,
        ];

        return $data;
    }

    // ──────────────────────────────────────────────
    //  Requêtes Supabase
    // ──────────────────────────────────────────────

    /** Jeux les mieux notés sortis cette année, triés par note desc. */
    private function fetchTopRatedThisYear(): array
    {
        $y = (int) date('Y');
        $from = "{$y}-01-01";
        $to   = "{$y}-12-31";

        return $this->get(
            "/rest/v1/games?select=id,title,slug,cover_url,igdb_rating,release_date,genres"
            . "&release_date=gte.{$from}&release_date=lte.{$to}"
            . "&cover_url=not.is.null&igdb_rating=not.is.null"
            . "&order=igdb_rating.desc.nullslast,release_date.desc.nullslast,id.desc&limit=16"
        );
    }

    /** Jeux à paraître dans les 18 prochains mois, triés par date asc. */
    private function fetchTodayGames(): array
    {
        $today = date('Y-m-d');

        return $this->get(
            "/rest/v1/games?select=id,title,slug,cover_url,igdb_rating,release_date"
            . "&release_date=eq.{$today}"
            . "&cover_url=not.is.null&order=igdb_rating.desc.nullslast,release_date.asc,id.asc&limit=32"
        );
    }

    /** 6 derniers avis avec le jeu et l'utilisateur associés. */
    private function fetchLatestReviews(): array
    {
        $rows = $this->get(
            "/rest/v1/reviews"
            . "?select=id,rating,body,created_at,games(id,title,slug,cover_url),users(username,avatar_url)"
            . "&order=created_at.desc&limit=6"
        );

        return array_map(static function (array $r): array {
            if (isset($r['body']) && mb_strlen($r['body']) > 155) {
                $r['body'] = mb_substr($r['body'], 0, 152) . '…';
            }
            return $r;
        }, $rows);
    }

    /**
     * Top 120 jeux notés ≥ 72, groupés par premier genre.
     * Retourne un tableau associatif [genre => [game, ...]] (max 6 genres × 6 jeux).
     */
    private function fetchGenreHighlights(): array
    {
        $games = $this->get(
            "/rest/v1/games?select=id,title,slug,cover_url,igdb_rating,genres"
            . "&igdb_rating=gte.72&cover_url=not.is.null"
            . "&order=igdb_rating.desc&limit=120"
        );

        $byGenre = [];
        foreach ($games as $g) {
            $decoded = is_string($g['genres']) ? json_decode($g['genres'], true) : $g['genres'];
            $first   = $decoded[0]['name'] ?? null;
            if (!$first) {
                continue;
            }
            $byGenre[$first]   ??= [];
            if (count($byGenre[$first]) < 6) {
                $byGenre[$first][] = $g;
            }
        }

        uasort($byGenre, static fn($a, $b) => count($b) - count($a));

        return array_slice($byGenre, 0, 6, true);
    }

    /** Plateformes récentes triées par génération décroissante. */
    private function fetchTopPlatforms(): array
    {
        $rows = $this->get(
            "/rest/v1/platforms?select=id,igdb_id,name,abbreviation,logo_url,generation"
            . "&generation=not.is.null&order=generation.desc&limit=50"
        );

        // Exclure les plateformes mobile/web (et garder 16 items après filtrage)
        $out = [];
        foreach ($rows as $p) {
            if (!is_array($p)) continue;
            $id = isset($p['id']) && is_numeric($p['id']) ? (int) $p['id'] : 0;
            if ($id > 0 && in_array($id, self::EXCLUDED_PLATFORM_IDS, true)) {
                continue;
            }
            $name = isset($p['name']) ? mb_strtolower(trim((string) $p['name'])) : '';
            if ($name !== '' && in_array($name, self::EXCLUDED_PLATFORM_NAMES, true)) {
                continue;
            }
            $out[] = $p;
            if (count($out) >= 16) break;
        }

        return $out;
    }

    /** Comptages globaux : membres, jeux, entrées collection, avis. */
    private function fetchStats(): array
    {
        $stats  = ['members' => 0, 'games' => 0, 'entries' => 0, 'reviews' => 0];
        // count=exact sur `games` peut dépasser le statement_timeout Supabase sur un gros catalogue.
        // count=estimated s’appuie sur les stats PostgreSQL (ANALYZE) : rapide et suffisant pour l’affichage.
        $tables = [
            'members' => ['/rest/v1/users', 'exact'],
            'games'   => ['/rest/v1/games', 'estimated'],
            'entries' => ['/rest/v1/collection_entries', 'exact'],
            'reviews' => ['/rest/v1/reviews', 'exact'],
        ];

        foreach ($tables as $key => [$path, $countMode]) {
            try {
                $res = $this->http->get($this->supabaseUrl . $path . '?select=id&limit=1', [
                    'headers' => array_merge($this->headers(), ["Prefer" => "count={$countMode}"]),
                ]);
                $range = $res->getHeaderLine('Content-Range');
                if ($range !== '') {
                    $parts = explode('/', $range);
                    $last  = end($parts);
                    if ($last !== false && ctype_digit((string) $last)) {
                        $stats[$key] = (int) $last;
                    }
                }
            } catch (Throwable) {}
        }

        return $stats;
    }

    /**
     * Jeux les plus ajoutés à la wishlist (toutes périodes).
     * Implémentation côté app (PostgREST n’expose pas toujours GROUP BY) :
     * - on récupère un échantillon large de lignes (game_id)
     * - on compte et on hydrate via `games`.
     *
     * @return list<array{id:int,title:string,slug:string,cover_url:?string,igdb_rating:float|int|null,release_date:?string,count:int}>
     */
    private function fetchMostAwaitedGames(): array
    {
        // "Les plus attendus" = wishlist + date de sortie non dépassée (>= aujourd'hui).
        // On prend un top plus large puis on filtre, pour garder ~16 items même si certains jeux sont déjà sortis.
        $today = date('Y-m-d');
        $rows = $this->fetchTopGamesByTableCounts('wishlist', 12000, 64);
        $rows = array_values(array_filter($rows, static function (array $g) use ($today): bool {
            $d = trim((string) ($g['release_date'] ?? ''));
            if ($d === '') return false; // pas de date => pas "attendu"
            return $d >= $today;
        }));
        return array_slice($rows, 0, 16);
    }

    /**
     * Jeux les plus ajoutés en collection (toutes périodes).
     *
     * @return list<array{id:int,title:string,slug:string,cover_url:?string,igdb_rating:float|int|null,release_date:?string,count:int}>
     */
    private function fetchMostAddedGames(): array
    {
        return $this->fetchTopGamesByTableCounts('collection_entries', 20000, 16);
    }

    /**
     * @return list<array{id:int,title:string,slug:string,cover_url:?string,igdb_rating:float|int|null,release_date:?string,count:int}>
     */
    private function fetchTopGamesByTableCounts(string $table, int $sampleLimit, int $topN): array
    {
        $sampleLimit = max(1, min(50000, $sampleLimit));
        $topN = max(1, min(32, $topN));

        $rows = $this->get(
            // On échantillonne en prenant les ajouts les plus récents (meilleure pertinence + payload stable).
            // `created_at` existe sur `wishlist` et `collection_entries`.
            "/rest/v1/{$table}?select=game_id&order=created_at.desc&limit={$sampleLimit}"
        );

        if (empty($rows)) {
            return [];
        }

        $counts = [];
        foreach ($rows as $r) {
            $gid = (int) ($r['game_id'] ?? 0);
            if ($gid <= 0) continue;
            $counts[$gid] = ($counts[$gid] ?? 0) + 1;
        }
        if (empty($counts)) {
            return [];
        }

        arsort($counts);
        $topIds = array_slice(array_keys($counts), 0, $topN);
        $idList = implode(',', array_map('intval', $topIds));

        // Hydrate via games
        $games = $this->get(
            "/rest/v1/games?select=id,title,slug,cover_url,igdb_rating,release_date"
            . "&id=in.({$idList})"
        );
        if (empty($games)) return [];

        $byId = [];
        foreach ($games as $g) {
            $id = (int) ($g['id'] ?? 0);
            if ($id <= 0) continue;
            $byId[$id] = $g;
        }

        $out = [];
        foreach ($topIds as $id) {
            if (!isset($byId[$id])) continue;
            $g = $byId[$id];
            $out[] = [
                'id'           => (int) ($g['id'] ?? 0),
                'title'        => (string) ($g['title'] ?? ''),
                'slug'         => (string) ($g['slug'] ?? ''),
                'cover_url'    => $g['cover_url'] ?? null,
                'igdb_rating'  => $g['igdb_rating'] ?? null,
                'release_date' => $g['release_date'] ?? null,
                'count'        => (int) ($counts[$id] ?? 0),
            ];
        }

        return $out;
    }

    // ──────────────────────────────────────────────
    //  Cache fichier (TTL 1h)
    // ──────────────────────────────────────────────

    private function cached(string $timingName, callable $fetch, ?int $ttl = null, ?int $staleTtl = null, ?string $cacheKey = null): mixed
    {
        $cacheKey ??= $timingName;
        $file = $this->cacheDir . '/' . $cacheKey . '.json';
        $lockFile = $this->cacheDir . '/' . $cacheKey . '.lock';
        $start = microtime(true);
        $hadCache = false;
        $cachedPayload = null;
        $cachedAt = null;
        $ttl ??= $this->cacheTtl;
        $staleTtl ??= $this->staleTtl;

        if (is_file($file)) {
            $hadCache = true;
            $raw  = file_get_contents($file);
            $data = $raw !== false ? json_decode($raw, true) : null;

            $cachedAt = is_array($data) ? (int) ($data['cached_at'] ?? 0) : null;
            if (is_array($data) && $cachedAt && ($cachedAt + $ttl) > time()) {
                $this->timings[$timingName] = [
                    'ms' => (microtime(true) - $start) * 1000,
                    'cache' => 'hit',
                    'ok' => true,
                ];
                return $data['payload'];
            }

            if (is_array($data) && array_key_exists('payload', $data)) {
                $cachedPayload = $data['payload'];
                $cachedAt = (int) ($data['cached_at'] ?? 0);
            }
        }

        // Si on a un cache expiré mais "stale" acceptable, on évite le dogpile :
        // - si un refresh est déjà en cours, on sert immédiatement le stale
        // - sinon on prend le lock et on refresh en exclu
        $canServeStale = $hadCache && $cachedAt !== null && $cachedAt > 0 && (($cachedAt + $ttl) <= time()) && (($cachedAt + $staleTtl) > time());
        $lockHandle = @fopen($lockFile, 'c');
        $haveLock = false;
        if (is_resource($lockHandle)) {
            $haveLock = @flock($lockHandle, LOCK_EX | LOCK_NB);
        }

        if ($canServeStale && !$haveLock) {
            $this->timings[$timingName] = [
                'ms' => (microtime(true) - $start) * 1000,
                'cache' => 'stale',
                'ok' => true,
            ];
            return $cachedPayload ?? [];
        }

        try {
            $payload = $fetch();
            $ok = true;
        } catch (Throwable) {
            $payload = null;
            $ok = false;
        }

        // Si la requête échoue et qu'on a déjà un cache, on sert l'ancien (stale) pour éviter
        // des lenteurs/intermittences (Supabase/ réseau) visibles côté utilisateur.
        if (!$ok && $hadCache) {
            $this->timings[$timingName] = [
                'ms' => (microtime(true) - $start) * 1000,
                'cache' => 'stale',
                'ok' => false,
            ];
            if ($haveLock && is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
            return $cachedPayload ?? [];
        }

        // On met en cache même les tableaux vides : ça évite de refaire les appels
        // en boucle quand une table est vide ou qu'un filtre renvoie 0 résultat.
        $toStore = is_array($payload) ? $payload : [];
        file_put_contents($file, json_encode([
            'cached_at' => time(),
            'payload'   => $toStore,
            'ok'        => $ok,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $this->timings[$timingName] = [
            'ms' => (microtime(true) - $start) * 1000,
            // Si on a servi du cache stale (refresh en cours), on aurait déjà return plus haut.
            // Ici, c'est donc un vrai miss (pas de cache valable) ou un refresh réussi.
            'cache' => 'miss',
            'ok' => $ok,
        ];

        if ($haveLock && is_resource($lockHandle)) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }

        return $toStore;
    }

    // ──────────────────────────────────────────────
    //  HTTP helpers
    // ──────────────────────────────────────────────

    private function get(string $path): array
    {
        try {
            $res  = $this->http->get($this->supabaseUrl . $path, ['headers' => $this->headers()]);
            $data = json_decode((string) $res->getBody(), true);
            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function headers(): array
    {
        return [
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
        ];
    }
}
