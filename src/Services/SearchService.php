<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\PlatformAbbreviations;
use App\Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Throwable;

/**
 * Requêtes de recherche vers Supabase (REST API).
 *
 * Fonctionnalités :
 *   - search()        : recherche paginée avec filtres multiples, retourne total + jeux
 *   - searchSuggest() : autocomplétion rapide (≤ 8 résultats)
 *   - getGame()       : fiche complète d'un jeu (plateformes + versions)
 *   - getFilterOptions() : liste des plateformes & genres pour les <select>
 */
class SearchService
{
    private const RESULTS_CACHE_VERSION = 5;

    private Client $http;
    private string $supabaseUrl;
    private string $serviceKey;
    private string $cacheDir;
    private int    $cacheTtl = 86400; // 24h pour les options de filtres

    public function __construct()
    {
        $this->http        = new Client(['timeout' => 8, 'http_errors' => false]);
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey  = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
        $this->cacheDir    = dirname(__DIR__, 2) . '/storage/cache/search';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    // ──────────────────────────────────────────────
    //  Recherche principale
    // ──────────────────────────────────────────────

    /**
     * Recherche paginée avec filtres.
     * Résultats mis en cache 5 min par combinaison de paramètres.
     *
     * @param  array{q?:string, platform?:int, genre?:string, year_from?:string,
     *                year_to?:string, rating_min?:int, sort?:string} $filters
     * @return array{games: array, total: int}
     */
    public function search(array $filters, int $page = 1, int $perPage = 24): array
    {
        // Mode historique (OFFSET) conservé pour compat, mais déconseillé à gros volumes.
        $cacheKey = 'results/' . md5(json_encode([
            'v'    => self::RESULTS_CACHE_VERSION,
            'mode' => 'offset',
            'f'    => $filters,
            'p'    => $page,
            'pp'   => $perPage,
        ]));

        return $this->cached($cacheKey, fn() => $this->doSearchOffset($filters, $page, $perPage), 300);
    }

    /**
     * Recherche keyset pagination : pas d'OFFSET, URL avec cursor.
     *
     * @return array{games: array, total: int, next_cursor: ?string}
     */
    public function searchCursor(array $filters, ?string $cursor = null, int $perPage = 24): array
    {
        $cacheKey = 'results/' . md5(json_encode([
            'v'      => self::RESULTS_CACHE_VERSION,
            'mode'   => 'cursor',
            'f'      => $filters,
            'cursor' => $cursor,
            'pp'     => $perPage,
        ]));

        return $this->cached($cacheKey, fn() => $this->doSearchCursor($filters, $cursor, $perPage), 300);
    }

    /** Exécute la requête Supabase réelle (appelé uniquement si le cache est froid). */
    private function doSearchOffset(array $filters, int $page, int $perPage): array
    {
        $promise = $this->buildSearchPromiseOffset($filters, $page, $perPage);
        try {
            $response = $promise->wait();
        } catch (Throwable) {
            return ['games' => [], 'total' => 0];
        }

        return $this->processCountResponse($response);
    }

    /**
     * Recherche cursor (keyset). On renvoie aussi le cursor suivant (basé sur le dernier item).
     *
     * @return array{games: array, total: int, next_cursor: ?string}
     */
    private function doSearchCursor(array $filters, ?string $cursor, int $perPage): array
    {
        $promise = $this->buildSearchPromiseCursor($filters, $cursor, $perPage);
        try {
            $response = $promise->wait();
        } catch (Throwable) {
            return ['games' => [], 'total' => 0, 'next_cursor' => null];
        }

        $result = $this->processCountResponse($response);
        $games  = $result['games'] ?? [];

        $next = null;
        if (is_array($games) && !empty($games)) {
            $last = $games[count($games) - 1];
            if (is_array($last)) {
                $next = $this->encodeCursorFromRow($filters['sort'] ?? '', $last);
            }
        }

        return [
            'games'       => is_array($games) ? $games : [],
            'total'       => (int) ($result['total'] ?? 0),
            'next_cursor' => $next,
        ];
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $raw): string
    {
        $b64 = strtr($raw, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($b64, true);
        return $out === false ? '' : $out;
    }

    /**
     * Encode le cursor "next" à partir de la dernière ligne.
     * On stocke uniquement ce qui est nécessaire au keyset selon le tri.
     */
    private function encodeCursorFromRow(string $sort, array $row): ?string
    {
        $sort = $sort !== '' ? $sort : 'recent';

        $payload = ['s' => $sort, 'id' => (int) ($row['id'] ?? 0)];
        if ($payload['id'] <= 0) {
            return null;
        }

        switch ($sort) {
            case 'rating':
                $payload['r'] = isset($row['igdb_rating']) ? (float) $row['igdb_rating'] : null;
                if ($payload['r'] === null) return null;
                break;
            case 'title_asc':
            case 'title_desc':
                $t = (string) ($row['title'] ?? '');
                if ($t === '') return null;
                $payload['t'] = $t;
                break;
            case 'upcoming':
            case 'oldest':
            case 'recent':
            default:
                $d = (string) ($row['release_date'] ?? '');
                if ($d === '') return null;
                $payload['d'] = $d;
                break;
        }

        return $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Construit le filtre keyset PostgREST à partir du cursor (page suivante uniquement).
     * Important : on force NOT NULL sur la colonne de tri principale pour éviter les pièges de NULLS LAST.
     */
    private function buildCursorFilter(string $sort, ?string $cursor): string
    {
        if ($cursor === null || $cursor === '') {
            return '';
        }
        $raw = $this->base64UrlDecode($cursor);
        if ($raw === '') {
            return '';
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['id'])) {
            return '';
        }
        $id = (int) $data['id'];
        if ($id <= 0) {
            return '';
        }

        $sort = $sort !== '' ? $sort : (string) ($data['s'] ?? 'recent');

        // PostgREST n'a pas de comparaison tuple portable (a,b) < (c,d),
        // donc on encode une disjonction OR en `or=(...)`.
        return match ($sort) {
            'upcoming', 'oldest' => (
                isset($data['d']) && is_string($data['d']) && $data['d'] !== ''
                    ? '&release_date=not.is.null'
                      . '&or=('
                      . 'release_date.gt.' . rawurlencode($data['d'])
                      . ',and(release_date.eq.' . rawurlencode($data['d']) . ',id.gt.' . $id . ')'
                      . ')'
                    : ''
            ),
            'rating' => (
                isset($data['r']) && is_numeric($data['r'])
                    ? '&igdb_rating=not.is.null'
                      . '&or=('
                      . 'igdb_rating.lt.' . rawurlencode((string) $data['r'])
                      . ',and(igdb_rating.eq.' . rawurlencode((string) $data['r']) . ',id.lt.' . $id . ')'
                      . ')'
                    : ''
            ),
            'title_asc' => (
                isset($data['t']) && is_string($data['t']) && $data['t'] !== ''
                    ? '&or=('
                      . 'title.gt.' . rawurlencode($data['t'])
                      . ',and(title.eq.' . rawurlencode($data['t']) . ',id.lt.' . $id . ')'
                      . ')'
                    : ''
            ),
            'title_desc' => (
                isset($data['t']) && is_string($data['t']) && $data['t'] !== ''
                    ? '&or=('
                      . 'title.lt.' . rawurlencode($data['t'])
                      . ',and(title.eq.' . rawurlencode($data['t']) . ',id.lt.' . $id . ')'
                      . ')'
                    : ''
            ),
            'recent' => (
                isset($data['d']) && is_string($data['d']) && $data['d'] !== ''
                    ? '&release_date=not.is.null'
                      . '&or=('
                      . 'release_date.lt.' . rawurlencode($data['d'])
                      . ',and(release_date.eq.' . rawurlencode($data['d']) . ',id.lt.' . $id . ')'
                      . ')'
                    : ''
            ),
            default => (
                isset($data['d']) && is_string($data['d']) && $data['d'] !== ''
                    ? '&release_date=not.is.null'
                      . '&or=('
                      . 'release_date.lt.' . rawurlencode($data['d'])
                      . ',and(release_date.eq.' . rawurlencode($data['d']) . ',id.lt.' . $id . ')'
                      . ')'
                    : ''
            ),
        };
    }

    /**
     * Masquer les DLC/Extensions dans la recherche.
     *
     * Important: PostgREST ne supporte pas toujours les chemins JSON (`->`/`->>`) dans les arbres logiques `or/and`.
     * On utilise donc `jsonb contains` (@>) via `cs.` et on nie la condition :
     *   raw_igdb_data NOT @> {"category": X}
     *
     * IGDB category: 1=dlc_addon, 2=expansion, 4=standalone_expansion.
     */
    private function buildNonDlcFilters(): string
    {
        $field = 'raw_igdb_data';
        $mk = static fn(int $cat): string => rawurlencode(json_encode(['category' => $cat], JSON_UNESCAPED_SLASHES));

        // AND logique: un jeu est gardé s'il n'est dans AUCUNE de ces catégories.
        return '&' . $field . '=not.cs.' . $mk(1)
             . '&' . $field . '=not.cs.' . $mk(2)
             . '&' . $field . '=not.cs.' . $mk(4);
    }

    /** Construit la promesse Guzzle pour la recherche (OFFSET, legacy). */
    private function buildSearchPromiseOffset(array $filters, int $page, int $perPage): \GuzzleHttp\Promise\PromiseInterface
    {
        // On inclut raw_igdb_data pour filtrer les DLC côté PHP de façon fiable
        // (même si raw_igdb_data a été stocké en JSONB "string" pour certains enregistrements historiques).
        $select = 'id,title,slug,cover_url,igdb_rating,release_date,genres,developer,platform_ids,raw_igdb_data';

        // IMPORTANT PERF: le filtre plateforme via jointure `game_platforms!inner(...)` peut time-out
        // sur certaines plateformes. On privilégie `games.platform_ids int[]` (migration 005),
        // qui permet un filtre rapide via index GIN.

        $offset = max(0, ($page - 1) * $perPage);
        $rangeEnd = $offset + max(0, $perPage - 1);
        $qs     = $this->buildFilters($filters)
                . $this->buildNonDlcFilters()
                . '&order=' . $this->resolveOrder($filters['sort'] ?? '')
                . "&limit={$perPage}&offset={$offset}";

        $url = $this->supabaseUrl . '/rest/v1/games?select=' . $select . $qs;

        return $this->http->getAsync($url, [
            // `planned` peut devenir très lent sur de gros volumes.
            // `estimated` privilégie la rapidité (au prix d'un total moins précis).
            'headers' => array_merge($this->headers(), [
                'Prefer' => 'count=estimated',
                // Force PostgREST à renvoyer Content-Range (utile pour $total).
                'Range-Unit' => 'items',
                'Range'      => "{$offset}-{$rangeEnd}",
            ]),
        ]);
    }

    /**
     * Compat: construit la promesse de recherche utilisée par `searchWithOptions()`.
     * On privilégie la pagination cursor (page 1), et on retombe en OFFSET pour pages > 1.
     */
    private function buildSearchPromise(array $filters, int $page, int $perPage): \GuzzleHttp\Promise\PromiseInterface
    {
        if ($page <= 1) {
            return $this->buildSearchPromiseCursor($filters, null, $perPage);
        }

        return $this->buildSearchPromiseOffset($filters, $page, $perPage);
    }

    /** Construit la promesse Guzzle pour la recherche (CURSOR/keyset). */
    private function buildSearchPromiseCursor(array $filters, ?string $cursor, int $perPage): \GuzzleHttp\Promise\PromiseInterface
    {
        $select = 'id,title,slug,cover_url,igdb_rating,release_date,genres,developer,platform_ids,raw_igdb_data';

        $sort = (string) ($filters['sort'] ?? '');
        $order = $this->resolveOrder($sort);

        $cursorFilter = $this->buildCursorFilter($sort, $cursor);

        $qs = $this->buildFilters($filters)
            . $this->buildNonDlcFilters()
            . $cursorFilter
            . '&order=' . $order
            . "&limit={$perPage}";

        // Range 0..perPage-1 : suffit pour Content-Range + total estimé, sans OFFSET.
        $rangeEnd = max(0, $perPage - 1);

        $url = $this->supabaseUrl . '/rest/v1/games?select=' . $select . $qs;

        return $this->http->getAsync($url, [
            'headers' => array_merge($this->headers(), [
                'Prefer' => 'count=estimated',
                'Range-Unit' => 'items',
                'Range' => "0-{$rangeEnd}",
            ]),
        ]);
    }


    /** Parse la réponse Guzzle avec Content-Range et retourne [games, total]. */
    private function processCountResponse(?object $response): array
    {
        if ($response === null) {
            return ['games' => [], 'total' => 0];
        }

        $body = $response->getBody();
        $raw  = '';

        // Lecture robuste du flux (compat async + curseur non garanti).
        if (is_object($body) && method_exists($body, 'isSeekable') && $body->isSeekable()) {
            $body->rewind();
        }
        if (is_object($body) && method_exists($body, 'getContents')) {
            $raw = $body->getContents();
            if ($raw === '' && method_exists($body, 'isSeekable') && $body->isSeekable()) {
                $body->rewind();
                $raw = $body->getContents();
            }
        }
        if ($raw === '') {
            $raw = (string) $body;
        }

        if (method_exists($response, 'getStatusCode')) {
            $code = (int) $response->getStatusCode();
            if ($code >= 400) {
                Logger::error('Supabase search error', [
                    'status' => $code,
                    'content_range' => method_exists($response, 'getHeaderLine') ? $response->getHeaderLine('Content-Range') : null,
                    'body' => mb_substr($raw, 0, 800),
                ]);
            }
        }

        $data  = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        $range = $response->getHeaderLine('Content-Range');

        $total = 0;
        if (preg_match('/\/(\d+)$/', $range, $m)) {
            $total = (int) $m[1];
        }

        $games = is_array($data) ? $data : [];
        $games = $this->filterOutDlcGames($games);

        return ['games' => $games, 'total' => $total];
    }

    /**
     * Exclut les DLC/expansions de la liste de jeux.
     * IGDB category: 1=dlc_addon, 2=expansion, 4=standalone_expansion.
     *
     * @param array $games
     * @return array
     */
    private function filterOutDlcGames(array $games): array
    {
        $out = [];

        foreach ($games as $g) {
            if (!is_array($g)) {
                continue;
            }

            $raw = $g['raw_igdb_data'] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                $raw = is_array($decoded) ? $decoded : null;
            }

            $cat = null;
            if (is_array($raw) && array_key_exists('category', $raw)) {
                $cat = is_numeric($raw['category']) ? (int) $raw['category'] : null;
            }

            // Filtre principal : IGDB lie les DLC à un jeu via `parent_game`.
            // Exemple: "Starfield: Shattered Space" a parent_game=96437.
            if (is_array($raw) && array_key_exists('parent_game', $raw) && $raw['parent_game'] !== null) {
                continue;
            }

            // Filtre secondaire (si jamais category est présent).
            // IGDB category: 1=dlc_addon, 2=expansion, 4=standalone_expansion, 13=pack.
            if ($cat !== null && in_array($cat, [1, 2, 4, 13], true)) {
                continue;
            }

            $out[] = $g;
        }

        return $out;
    }

    /**
     * Autocomplétion rapide : retourne jusqu'à $limit jeux correspondant à $q.
     */
    public function searchSuggest(string $q, int $limit = 8): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }

        $escaped = rawurlencode('%' . $q . '%');
        $url = "/rest/v1/games?select=id,title,slug,cover_url,igdb_rating"
             . "&title=ilike.{$escaped}"
             . "&order=igdb_rating.desc.nullslast"
             . "&limit={$limit}";

        return $this->get($url);
    }

    // ──────────────────────────────────────────────
    //  Fiche jeu (popup)
    // ──────────────────────────────────────────────

    /**
     * Retourne la fiche complète d'un jeu avec ses plateformes et versions disponibles.
     */
    public function getGame(int $id): ?array
    {
        // Informations principales
        $games = $this->get(
            "/rest/v1/games?select=id,title,slug,cover_url,igdb_rating,release_date,"
            . "synopsis,developer,publisher,genres&id=eq.{$id}&limit=1"
        );

        if (empty($games)) {
            return null;
        }

        $game = $games[0];

        // Plateformes disponibles (avec nom)
        $game['platforms'] = $this->get(
            "/rest/v1/game_platforms?select=id,platform_id,region,release_date,"
            . "platforms(id,name,abbreviation)"
            . "&game_id=eq.{$id}&order=platform_id.asc"
        );

        // Versions (éditions spéciales, etc.)
        $game['versions'] = $this->get(
            "/rest/v1/game_versions?select=id,title,description"
            . "&game_id=eq.{$id}&order=title.asc"
        );

        return $game;
    }

    // ──────────────────────────────────────────────
    //  Options de filtres (mis en cache 24h)
    // ──────────────────────────────────────────────

    /**
     * Exécute la recherche ET les options de filtres en parallèle (Guzzle async).
     * Si les deux sont dans le cache fichier, aucune requête HTTP n'est effectuée.
     *
     * @return array{result: array{games:array, total:int}, options: array{platforms:array, genres:string[]}}
     */
    public function searchWithOptions(array $filters, int $page, int $perPage): array
    {
        $resultKey   = 'results/' . md5(json_encode([
            'v'  => self::RESULTS_CACHE_VERSION,
            'f'  => $filters,
            'p'  => $page,
            'pp' => $perPage,
        ]));
        $platformKey = 'filter_platforms';

        // Lire les deux caches en même temps
        $cachedResult    = $this->readCache($resultKey, 300);
        $cachedPlatforms = $this->readCache($platformKey, $this->cacheTtl);

        // Cas optimal : tout est en cache → zéro requête HTTP
        if ($cachedResult !== null && $cachedPlatforms !== null) {
            return [
                'result'  => $cachedResult,
                'options' => ['platforms' => $cachedPlatforms, 'genres' => $this->fetchGenreOptions()],
            ];
        }

        // Cas cold (partiel ou total) : requêtes HTTP en parallèle via Guzzle async
        $promises = [];

        if ($cachedResult === null) {
            $promises['result'] = $this->buildSearchPromise($filters, $page, $perPage);
        }
        if ($cachedPlatforms === null) {
            $promises['platforms'] = $this->http->getAsync(
                $this->supabaseUrl . '/rest/v1/platforms?select=id,name,abbreviation,generation'
                . '&generation=not.is.null&order=generation.desc,name.asc&limit=200',
                ['headers' => $this->headers()]
            );
        }

        try {
            $resolved = PromiseUtils::unwrap($promises);
        } catch (Throwable) {
            $resolved = [];
        }

        // Traiter résultat de recherche
        if ($cachedResult === null) {
            $cachedResult = $this->processCountResponse($resolved['result'] ?? null);
            if (!empty($cachedResult['games'])) {
                $this->writeCache($resultKey, $cachedResult);
            }
        }

        // Traiter plateformes
        if ($cachedPlatforms === null) {
            $body = $resolved['platforms'] ?? null;
            $data = $body ? json_decode((string) $body->getBody(), true) : [];
            $cachedPlatforms = is_array($data) ? $data : [];
            if (!empty($cachedPlatforms)) {
                $this->writeCache($platformKey, $cachedPlatforms);
            }
        }

        return [
            'result'  => $cachedResult  ?? ['games' => [], 'total' => 0],
            'options' => ['platforms' => $cachedPlatforms ?? [], 'genres' => $this->fetchGenreOptions()],
        ];
    }

    /**
     * Retourne les listes de plateformes et de genres pour les <select> de filtres.
     *
     * @return array{platforms: array, genres: string[]}
     */
    public function getFilterOptions(): array
    {
        return [
            'platforms' => $this->cached('filter_platforms', fn() => $this->fetchPlatformOptions()),
            'genres'    => $this->fetchGenreOptions(),
        ];
    }

    /**
     * Catalogue complet des plateformes pour l’inscription (sans filtre generation, limite 500).
     *
     * @return list<array{id:int, name:string, short:string}>
     */
    public function getPlatformsCatalogForRegistration(): array
    {
        $catalog = $this->cached('register_platforms_catalog', function (): array {
            $raw = $this->get(
                '/rest/v1/platforms?select=id,name,abbreviation,generation'
                . '&order=name.asc&limit=500'
            );
            $out = [];
            foreach ($raw as $row) {
                if (!isset($row['id'], $row['name'])) {
                    continue;
                }
                $out[] = $this->normalizePlatformCatalogRow($row);
            }
            return $out;
        });

        return is_array($catalog) ? $catalog : [];
    }

    // ──────────────────────────────────────────────
    //  Requêtes Supabase privées
    // ──────────────────────────────────────────────

    private function normalizePlatformCatalogRow(array $row): array
    {
        $id   = (int) $row['id'];
        $name = (string) $row['name'];
        $abbr = isset($row['abbreviation']) ? trim((string) $row['abbreviation']) : '';
        $short = $abbr !== '' ? $abbr : PlatformAbbreviations::get($name);
        if ($short === $name && mb_strlen($name, 'UTF-8') > 28) {
            $short = mb_substr($name, 0, 26, 'UTF-8') . '…';
        }

        return ['id' => $id, 'name' => $name, 'short' => $short];
    }

    private function fetchPlatformOptions(): array
    {
        return $this->get(
            '/rest/v1/platforms?select=id,name,abbreviation,generation'
            . '&generation=not.is.null&order=generation.desc,name.asc&limit=200'
        );
    }

    /**
     * Liste statique des genres IGDB officiels.
     * Ces genres sont stables et ne nécessitent aucun appel API.
     */
    private function fetchGenreOptions(): array
    {
        return [
            'Adventure', 'Arcade', 'Card & Board Game', 'Fighting',
            'Hack and slash/Beat \'em up', 'Indie', 'MOBA', 'Music',
            'Pinball', 'Platform', 'Point-and-click', 'Puzzle', 'Quiz/Trivia',
            'Racing', 'Real Time Strategy (RTS)', 'Role-playing (RPG)',
            'Shooter', 'Simulator', 'Sport', 'Strategy',
            'Tactical', 'Turn-based strategy (TBS)', 'Visual Novel',
        ];
    }

    // ──────────────────────────────────────────────
    //  Construction des filtres
    // ──────────────────────────────────────────────

    private function buildFilters(array $filters): string
    {
        $qs = '';
        $sort = (string) ($filters['sort'] ?? '');

        // Recherche textuelle
        if (!empty($filters['q'])) {
            $escaped = rawurlencode('%' . $filters['q'] . '%');
            $qs .= "&title=ilike.{$escaped}";
        }

        // Filtre plateforme : via `platform_ids int[]` sur games (migration 005).
        if (!empty($filters['platform'])) {
            $id = (int) $filters['platform'];
            $qs .= "&platform_ids=cs.{" . $id . "}";
        }

        // Filtre genre (JSONB contains)
        if (!empty($filters['genre'])) {
            $genreJson = rawurlencode(json_encode([['name' => $filters['genre']]]));
            $qs .= "&genres=cs.{$genreJson}";
        }

        // Plage d'années
        if (!empty($filters['year_from'])) {
            $y   = (int) $filters['year_from'];
            $qs .= "&release_date=gte.{$y}-01-01";
        }
        if (!empty($filters['year_to'])) {
            $y   = (int) $filters['year_to'];
            $qs .= "&release_date=lte.{$y}-12-31";
        }

        // Tri "Plus récents" et "Prochaines sorties" : bornes relatives à aujourd'hui
        // (sans écraser une plage d'années explicitement demandée).
        $hasYearRange = !empty($filters['year_from']) || !empty($filters['year_to']);
        if (!$hasYearRange) {
            if ($sort === 'recent') {
                $qs .= '&release_date=lte.' . date('Y-m-d');
            } elseif ($sort === 'upcoming') {
                $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
                $qs .= '&release_date=gte.' . $tomorrow;
            }
        }

        // Note minimale
        if (isset($filters['rating_min']) && (int)$filters['rating_min'] > 0) {
            $r   = (int) $filters['rating_min'];
            $qs .= "&igdb_rating=gte.{$r}";
        }

        return $qs;
    }

    private function resolveOrder(string $sort): string
    {
        return match ($sort) {
            'upcoming'   => 'release_date.asc.nullslast,id.asc',
            'oldest'     => 'release_date.asc.nullslast,id.asc',
            'rating'     => 'igdb_rating.desc.nullslast,id.desc',
            'title_asc'  => 'title.asc,id.desc',
            'title_desc' => 'title.desc,id.desc',
            default      => 'release_date.desc.nullslast,id.desc',
        };
    }

    // ──────────────────────────────────────────────
    //  Cache fichier — helpers
    // ──────────────────────────────────────────────

    /** Lit un cache fichier. Retourne la payload si valide, null sinon. */
    private function readCache(string $key, int $ttl): mixed
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        if (!is_file($file)) return null;

        $raw  = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (is_array($data) && ($data['cached_at'] ?? 0) + $ttl > time()) {
            return $data['payload'];
        }
        return null;
    }

    /** Écrit une payload dans le cache fichier. */
    private function writeCache(string $key, mixed $payload): void
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        $dir  = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        file_put_contents($file, json_encode([
            'cached_at' => time(),
            'payload'   => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    // ──────────────────────────────────────────────
    //  Cache fichier (TTL par appel)
    // ──────────────────────────────────────────────

    /**
     * @param int $ttl  TTL en secondes (null = valeur par défaut 24h)
     */
    private function cached(string $key, callable $fetch, int $ttl = null): mixed
    {
        $ttl  = $ttl ?? $this->cacheTtl;
        $file = $this->cacheDir . '/' . $key . '.json';

        // Créer les sous-répertoires si besoin (ex. results/abc123.json)
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (is_file($file)) {
            $raw  = file_get_contents($file);
            $data = $raw !== false ? json_decode($raw, true) : null;

            if (is_array($data) && ($data['cached_at'] ?? 0) + $ttl > time()) {
                return $data['payload'];
            }
        }

        try {
            $payload = $fetch();
        } catch (Throwable) {
            $payload = [];
        }

        // Éviter de "figer" un faux vide: si une requête timeout/échoue, on obtient souvent
        // ['games'=>[], 'total'=>0]. Ce payload n'est pas utile à mettre en cache (ça casse le filtre plateforme).
        $shouldCache = !empty($payload);
        if (is_array($payload) && array_key_exists('games', $payload) && array_key_exists('total', $payload)) {
            $games = $payload['games'] ?? null;
            $total = (int) ($payload['total'] ?? 0);
            $shouldCache = $total > 0 || (is_array($games) && !empty($games));
        }

        if ($shouldCache) {
            file_put_contents($file, json_encode([
                'cached_at' => time(),
                'payload'   => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }

        return $payload ?? [];
    }

    // ──────────────────────────────────────────────
    //  HTTP helpers
    // ──────────────────────────────────────────────

    /**
     * GET avec Prefer: count=<mode> → retourne [data[], total].
     *
     * @param  string $countMode  'planned' (rapide, estimé) ou 'exact' (lent, précis)
     * @return array{0: array, 1: int}
     */
    private function getWithCount(string $path, string $countMode = 'planned'): array
    {
        try {
            $res = $this->http->get($this->supabaseUrl . $path, [
                'headers' => array_merge($this->headers(), ['Prefer' => "count={$countMode}"]),
            ]);

            $data  = json_decode((string) $res->getBody(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            $range = $res->getHeaderLine('Content-Range');

            // Content-Range: 0-23/1450  ou  */0
            $total = 0;
            if (preg_match('/\/(\d+)$/', $range, $m)) {
                $total = (int) $m[1];
            }

            return [is_array($data) ? $data : [], $total];
        } catch (Throwable) {
            return [[], 0];
        }
    }

    private function get(string $path): array
    {
        try {
            $res  = $this->http->get($this->supabaseUrl . $path, ['headers' => $this->headers()]);
            $data = json_decode((string) $res->getBody(), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
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
