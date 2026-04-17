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
    private const RESULTS_CACHE_VERSION = 6;
    // bump pour invalider le cache plateformes après filtrage
    private const FILTER_PLATFORMS_CACHE_KEY = 'filter_platforms_v5';
    /** Plateformes à exclure (IDs locaux, colonne `platforms.id`). */
    private const EXCLUDED_PLATFORM_IDS = [28, 32, 61, 62, 68, 183];
    /** Filet de sécurité si `igdb_id` est absent/null dans une réponse. */
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
     * @param  array{q?:string, platform?:int, genre?:string, rating_min?:int, sort?:'all'|'recent'|'upcoming'|'rating'} $filters
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
    public function searchCursor(array $filters, ?string $cursor = null, int $perPage = 24, string $countMode = 'estimated'): array
    {
        $cacheKey = 'results/' . md5(json_encode([
            'v'      => self::RESULTS_CACHE_VERSION,
            'mode'   => 'cursor',
            'f'      => $filters,
            'cursor' => $cursor,
            'pp'     => $perPage,
            'count'  => $countMode,
        ]));

        return $this->cached($cacheKey, fn() => $this->doSearchCursor($filters, $cursor, $perPage, $countMode), 300);
    }

    /** Exécute la requête Supabase réelle (appelé uniquement si le cache est froid). */
    private function doSearchOffset(array $filters, int $page, int $perPage): array
    {
        $promise = $this->buildSearchPromiseOffset($filters, $page, $perPage);
        try {
            $response = $promise->wait();
        } catch (Throwable $e) {
            Logger::warning('Supabase search request failed', [
                'mode' => 'offset',
                'filters' => $filters,
                'page' => $page,
                'per_page' => $perPage,
                'exception' => get_class($e) . ': ' . $e->getMessage(),
            ]);
            return ['games' => [], 'total' => 0, '_error' => true];
        }

        $result = $this->processCountResponse($response);
        // Retry 1x sur vide "suspect" (souvent lié à un hic réseau/proxy)
        if ($this->shouldRetryEmptyResult($filters, $result)) {
            usleep(150000); // 150ms
            try {
                // Fallback: réduire la page pour diminuer la charge côté Supabase
                $retryPerPage = min($perPage, 12);
                // Et ne pas recalculer le total (count) : souvent la partie la plus coûteuse.
                $response2 = $this->buildSearchPromiseOffset($filters, $page, $retryPerPage, 'none')->wait();
                $result2 = $this->processCountResponse($response2);
                if (!empty($result2['games']) || (int)($result2['total'] ?? 0) > 0 || !empty($result2['_error'])) {
                    return $result2;
                }
            } catch (Throwable) {
                // Laisser le 1er résultat (vide) : on ne veut pas boucler.
            }
        }

        return $result;
    }

    /**
     * Recherche cursor (keyset). On renvoie aussi le cursor suivant (basé sur le dernier item).
     *
     * @return array{games: array, total: int, next_cursor: ?string}
     */
    private function doSearchCursor(array $filters, ?string $cursor, int $perPage, string $countMode): array
    {
        $promise = $this->buildSearchPromiseCursor($filters, $cursor, $perPage, $countMode);
        try {
            $response = $promise->wait();
        } catch (Throwable $e) {
            Logger::warning('Supabase search request failed', [
                'mode' => 'cursor',
                'filters' => $filters,
                'cursor' => $cursor,
                'per_page' => $perPage,
                'exception' => get_class($e) . ': ' . $e->getMessage(),
            ]);
            return ['games' => [], 'total' => 0, 'next_cursor' => null, '_error' => true];
        }

        $result = $this->processCountResponse($response);

        // Retry 1x sur vide "suspect" (souvent sur certains filtres plateformes).
        if ($this->shouldRetryEmptyResult($filters, $result) && ($cursor === null || $cursor === '')) {
            usleep(150000); // 150ms
            try {
                // Fallback: réduire la page pour diminuer la charge côté Supabase
                $retryPerPage = min($perPage, 12);
                // Et ne pas recalculer le total (count) : souvent la partie la plus coûteuse.
                $response2 = $this->buildSearchPromiseCursor($filters, $cursor, $retryPerPage, 'none')->wait();
                $result2 = $this->processCountResponse($response2);
                if (!empty($result2['games']) || (int)($result2['total'] ?? 0) > 0 || !empty($result2['_error'])) {
                    $result = $result2;
                    $countMode = 'none';
                }
            } catch (Throwable) {
                // Ne pas boucler. On garde $result.
            }
        }

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
            '_error'      => !empty($result['_error']),
            '_count_mode' => $countMode,
        ];
    }

    /**
     * Détecte un "vide suspect" : pas d'erreur, mais 0 résultat alors qu'on a des filtres.
     * Sur certains timeouts/proxys, on reçoit une réponse 200 vide; un retry suffit souvent.
     */
    private function shouldRetryEmptyResult(array $filters, array $result): bool
    {
        if (!empty($result['_error'])) {
            return false;
        }
        $games = $result['games'] ?? null;
        $total = (int) ($result['total'] ?? 0);
        $isEmpty = $total === 0 && (!is_array($games) || empty($games));
        if (!$isEmpty) {
            return false;
        }

        // On ne retry que si l'utilisateur a demandé quelque chose de "spécifique".
        $platforms = $filters['platforms'] ?? [];
        $genres    = $filters['genres'] ?? [];
        $q         = trim((string) ($filters['q'] ?? ''));
        $ratingMin = (int) ($filters['rating_min'] ?? 0);

        return (is_array($platforms) && !empty($platforms))
            || (is_array($genres) && !empty($genres))
            || ($q !== '' && mb_strlen($q, 'UTF-8') >= 2)
            || ($ratingMin > 0);
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
        $sort = $sort !== '' ? $sort : 'all';

        $payload = ['s' => $sort, 'id' => (int) ($row['id'] ?? 0)];
        if ($payload['id'] <= 0) {
            return null;
        }

        switch ($sort) {
            case 'all':
                break;
            case 'rating':
                $payload['r'] = isset($row['igdb_rating']) ? (float) $row['igdb_rating'] : null;
                if ($payload['r'] === null) return null;
                break;
            case 'upcoming':
            case 'release_asc':
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

        $sort = $sort !== '' ? $sort : (string) ($data['s'] ?? 'all');

        // PostgREST n'a pas de comparaison tuple portable (a,b) < (c,d),
        // donc on encode une disjonction OR en `or=(...)`.
        return match ($sort) {
            'all' => '&id=lt.' . $id,
            'upcoming', 'oldest', 'release_asc' => (
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

    /** Construit une expression OR interne (sans "or=(...)" et sans "&") pour les genres. */
    private function buildGenresOrExpr(array $genres): ?string
    {
        $names = [];
        foreach ($genres as $g) {
            $g = trim((string) $g);
            if ($g !== '') $names[] = $g;
        }
        $names = array_values(array_unique($names));
        if (count($names) < 2) {
            return null;
        }

        $parts = [];
        foreach ($names as $g) {
            $genreJson = json_encode([['name' => $g]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $parts[] = 'genres.cs.' . rawurlencode($genreJson);
        }
        return implode(',', $parts);
    }

    /** Construit le querystring de filtre genres (sans gérer le cursor). */
    private function buildGenresFilterQs(array $genres): string
    {
        $names = [];
        foreach ($genres as $g) {
            $g = trim((string) $g);
            if ($g !== '') $names[] = $g;
        }
        $names = array_values(array_unique($names));
        if (empty($names)) {
            return '';
        }

        if (count($names) === 1) {
            $genreJson = rawurlencode(json_encode([['name' => $names[0]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return "&genres=cs.{$genreJson}";
        }

        $orExpr = $this->buildGenresOrExpr($names);
        return $orExpr ? ('&or=(' . $orExpr . ')') : '';
    }

    /**
     * Construit le filtre release_date à partir de bornes normalisées (YYYY-MM-DD).
     *
     * @return array{qs:string, orExpr:?string}
     *   - qs: filtres AND simples (&release_date=gte...&release_date=lte...)
     *   - orExpr: expression interne pour or(...) quand "inclure inconnues" est activé
     */
    private function buildReleaseDateFilter(array $filters): array
    {
        $from = trim((string) ($filters['release_from'] ?? ''));
        $to   = trim((string) ($filters['release_to'] ?? ''));
        $includeUnknown = !empty($filters['release_include_unknown']);

        $hasFrom = $from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from);
        $hasTo   = $to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);
        if (!$hasFrom && !$hasTo) {
            return ['qs' => '', 'orExpr' => null];
        }

        if ($includeUnknown) {
            $parts = [];
            if ($hasFrom) $parts[] = 'release_date.gte.' . rawurlencode($from);
            if ($hasTo)   $parts[] = 'release_date.lte.' . rawurlencode($to);

            $rangeExpr = '';
            if (count($parts) === 2) {
                $rangeExpr = 'and(' . implode(',', $parts) . ')';
            } elseif (count($parts) === 1) {
                $rangeExpr = $parts[0];
            }
            if ($rangeExpr === '') {
                return ['qs' => '', 'orExpr' => null];
            }
            return [
                'qs' => '',
                'orExpr' => 'release_date.is.null,' . $rangeExpr,
            ];
        }

        $qs = '';
        if ($hasFrom) $qs .= '&release_date=gte.' . rawurlencode($from);
        if ($hasTo)   $qs .= '&release_date=lte.' . rawurlencode($to);
        return ['qs' => $qs, 'orExpr' => null];
    }

    /**
     * Split une chaîne qui contient potentiellement "&or=(...)" en [prefix, orExpr].
     *
     * @return array{0:string,1:?string}
     */
    private function splitOrFilter(string $filter): array
    {
        $p = strpos($filter, '&or=(');
        if ($p === false) {
            return [$filter, null];
        }
        $prefix = substr($filter, 0, $p);
        $expr = substr($filter, $p + 5); // retire "&or=("
        if (str_ends_with($expr, ')')) {
            $expr = substr($expr, 0, -1);
        }
        $expr = trim($expr);
        return [$prefix, $expr !== '' ? $expr : null];
    }

    /**
     * Masquer les DLC/Extensions dans la recherche.
     *
     * On ne stocke plus le payload IGDB complet (`raw_igdb_data`) en base, pour éviter l'explosion TOAST.
     * On filtre donc via des colonnes dédiées (légères) :
     *  - parent_game_igdb_id IS NULL  → exclut DLC/expansions liés à un parent
     *  - igdb_category NOT IN (...)   → second filet de sécurité
     */
    private function buildNonDlcFilters(): string
    {
        // On garde uniquement les jeux sans parent, et on exclut quelques catégories "non-main".
        // IGDB category: 0=main_game, 1=dlc_addon, 2=expansion, 4=standalone_expansion, 13=pack.
        return '&parent_game_igdb_id=is.null'
            . '&igdb_category=not.in.(1,2,4,13)';
    }

    /** Construit la promesse Guzzle pour la recherche (OFFSET, legacy). */
    private function buildSearchPromiseOffset(
        array $filters,
        int $page,
        int $perPage,
        string $countMode = 'estimated'
    ): \GuzzleHttp\Promise\PromiseInterface
    {
        // Champs légers requis (on ne ramène plus raw_igdb_data).
        $select = 'id,title,slug,cover_url,igdb_rating,release_date,genres,developer,platform_ids,igdb_category,parent_game_igdb_id';

        // IMPORTANT PERF: le filtre plateforme via jointure `game_platforms!inner(...)` peut time-out
        // sur certaines plateformes. On privilégie `games.platform_ids int[]` (migration 005),
        // qui permet un filtre rapide via index GIN.

        $offset = max(0, ($page - 1) * $perPage);
        $rangeEnd = $offset + max(0, $perPage - 1);
        $genres = $filters['genres'] ?? [];
        $genres = is_array($genres) ? $genres : [];
        $genresOr = $this->buildGenresOrExpr($genres);
        $genresQs = $genresOr === null ? $this->buildGenresFilterQs($genres) : '';

        $release = $this->buildReleaseDateFilter($filters);
        $releaseQs = $release['orExpr'] === null ? ($release['qs'] ?? '') : '';

        $orExprs = [];
        if ($genresOr !== null) $orExprs[] = $genresOr;
        if (!empty($release['orExpr'])) $orExprs[] = (string) $release['orExpr'];

        $qs = $this->buildFilters($filters)
            . $genresQs
            . $releaseQs
            . $this->buildNonDlcFilters();

        if (count($orExprs) === 1) {
            $qs .= '&or=(' . $orExprs[0] . ')';
        } elseif (count($orExprs) >= 2) {
            $qs .= '&and=(' . implode(',', array_map(static fn($e) => 'or(' . $e . ')', $orExprs)) . ')';
        }

        $qs .= '&order=' . $this->resolveOrder($filters['sort'] ?? '')
            . "&limit={$perPage}&offset={$offset}";

        $url = $this->supabaseUrl . '/rest/v1/games?select=' . $select . $qs;

        $hasGenres = !empty($filters['genres']) && is_array($filters['genres']);
        $hasPlatforms = !empty($filters['platforms']) && is_array($filters['platforms']);
        // Le filtre JSONB genres sans index GIN peut être lent.
        // Certaines plateformes peuvent aussi déclencher des réponses "dégradées" sous charge.
        $timeout = $hasGenres ? 25 : ($hasPlatforms ? 15 : 8);

        $headers = $this->headers();
        if ($countMode === 'none') {
            $headers['Prefer'] = 'count=none';
            // Pas besoin de Range si on ne demande pas le total.
        } else {
            // `planned` peut devenir très lent sur de gros volumes.
            // `estimated` privilégie la rapidité (au prix d'un total moins précis).
            $headers['Prefer'] = 'count=estimated';
            // Force PostgREST à renvoyer Content-Range (utile pour $total).
            $headers['Range-Unit'] = 'items';
            $headers['Range']      = "{$offset}-{$rangeEnd}";
        }

        return $this->http->getAsync($url, [
            'headers' => $headers,
            'timeout' => $timeout,
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
    private function buildSearchPromiseCursor(
        array $filters,
        ?string $cursor,
        int $perPage,
        string $countMode = 'estimated'
    ): \GuzzleHttp\Promise\PromiseInterface
    {
        $select = 'id,title,slug,cover_url,igdb_rating,release_date,genres,developer,platform_ids,igdb_category,parent_game_igdb_id';

        $sort = (string) ($filters['sort'] ?? '');
        $order = $this->resolveOrder($sort);

        $cursorFilter = $this->buildCursorFilter($sort, $cursor);
        $genres = $filters['genres'] ?? [];
        $genres = is_array($genres) ? $genres : [];
        $genresOr = $this->buildGenresOrExpr($genres);
        $genresQs = $genresOr === null ? $this->buildGenresFilterQs($genres) : '';

        $release = $this->buildReleaseDateFilter($filters);
        $releaseQs = $release['orExpr'] === null ? ($release['qs'] ?? '') : '';

        [$cursorPrefix, $cursorOr] = $this->splitOrFilter($cursorFilter);

        $orExprs = [];
        if (!empty($cursorOr)) $orExprs[] = (string) $cursorOr;
        if ($genresOr !== null) $orExprs[] = $genresOr;
        if (!empty($release['orExpr'])) $orExprs[] = (string) $release['orExpr'];

        $qs = $this->buildFilters($filters)
            . $genresQs
            . $releaseQs
            . $this->buildNonDlcFilters()
            . $cursorPrefix;

        if (count($orExprs) === 1) {
            $qs .= '&or=(' . $orExprs[0] . ')';
        } elseif (count($orExprs) >= 2) {
            $qs .= '&and=(' . implode(',', array_map(static fn($e) => 'or(' . $e . ')', $orExprs)) . ')';
        }

        $qs .= '&order=' . $order
            . "&limit={$perPage}";

        // Range 0..perPage-1 : suffit pour Content-Range + total estimé, sans OFFSET.
        $rangeEnd = max(0, $perPage - 1);

        $url = $this->supabaseUrl . '/rest/v1/games?select=' . $select . $qs;

        $hasGenres = !empty($filters['genres']) && is_array($filters['genres']);
        $hasPlatforms = !empty($filters['platforms']) && is_array($filters['platforms']);
        $timeout = $hasGenres ? 25 : ($hasPlatforms ? 15 : 8);

        $headers = $this->headers();
        if ($countMode === 'none') {
            $headers['Prefer'] = 'count=none';
        } else {
            $headers['Prefer'] = 'count=estimated';
            $headers['Range-Unit'] = 'items';
            $headers['Range']      = "0-{$rangeEnd}";
        }

        return $this->http->getAsync($url, [
            'headers' => $headers,
            'timeout' => $timeout,
        ]);
    }


    /** Parse la réponse Guzzle avec Content-Range et retourne [games, total]. */
    private function processCountResponse(?object $response): array
    {
        if ($response === null) {
            return ['games' => [], 'total' => 0, '_error' => true];
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

        // Un body vide avec une réponse HTTP "OK" arrive parfois en cas de timeout/proxy.
        // On le traite comme une erreur temporaire, pour éviter un faux "Aucun résultat".
        if (trim($raw) === '') {
            Logger::error('Supabase search empty body', [
                'status' => method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : null,
                'content_range' => method_exists($response, 'getHeaderLine') ? $response->getHeaderLine('Content-Range') : null,
            ]);
            return ['games' => [], 'total' => 0, '_error' => true];
        }

        if (method_exists($response, 'getStatusCode')) {
            $code = (int) $response->getStatusCode();
            if ($code >= 400) {
                Logger::error('Supabase search error', [
                    'status' => $code,
                    'content_range' => method_exists($response, 'getHeaderLine') ? $response->getHeaderLine('Content-Range') : null,
                    'body' => mb_substr($raw, 0, 800),
                ]);
                // On signale une erreur applicative (pour ne pas afficher un faux "0 résultat").
                return ['games' => [], 'total' => 0, '_error' => true];
            }
        }

        $data  = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($data === null && trim($raw) !== '' && json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Supabase search JSON decode error', [
                'json_error' => json_last_error_msg(),
                'body' => mb_substr($raw, 0, 800),
            ]);
            return ['games' => [], 'total' => 0, '_error' => true];
        }
        $range = $response->getHeaderLine('Content-Range');

        $total = 0;
        if (preg_match('/\/(\d+)$/', $range, $m)) {
            $total = (int) $m[1];
        }

        $games = is_array($data) ? $data : [];
        $games = $this->filterOutDlcGames($games);

        // Si on n'a aucun Content-Range ET aucune donnée, c'est très souvent une réponse "dégradée"
        // (proxy/timeout). On préfère afficher une erreur temporaire plutôt qu'un faux "Aucun résultat".
        if ($range === '' && empty($games)) {
            Logger::error('Supabase search missing Content-Range', [
                'status' => method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : null,
                'body' => mb_substr($raw, 0, 200),
            ]);
            return ['games' => [], 'total' => 0, '_error' => true];
        }

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

            $parent = $g['parent_game_igdb_id'] ?? null;
            if (is_numeric($parent) && (int) $parent > 0) {
                continue;
            }

            $cat = $g['igdb_category'] ?? null;
            if (is_numeric($cat) && in_array((int) $cat, [1, 2, 4, 13], true)) {
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
            . "developer,publisher,genres&id=eq.{$id}&limit=1"
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
        $platformKey = self::FILTER_PLATFORMS_CACHE_KEY;

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
                $this->supabaseUrl . '/rest/v1/platforms?select=id,igdb_id,name,abbreviation,generation'
                // Inclut aussi les plateformes sans génération (ex: PC) et trie consoles d'abord.
                . '&order=generation.desc.nullslast,name.asc&limit=500',
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
            $cachedPlatforms = is_array($data) ? $this->filterExcludedPlatforms($data) : [];
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
            'platforms' => $this->cached(self::FILTER_PLATFORMS_CACHE_KEY, fn() => $this->fetchPlatformOptions()),
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
                '/rest/v1/platforms?select=id,igdb_id,name,abbreviation,generation'
                . '&order=name.asc&limit=500'
            );
            $raw = $this->filterExcludedPlatforms(is_array($raw) ? $raw : []);
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
        $rows = $this->get(
            '/rest/v1/platforms?select=id,igdb_id,name,abbreviation,generation'
            . '&order=generation.desc.nullslast,name.asc&limit=500'
        );
        return $this->filterExcludedPlatforms(is_array($rows) ? $rows : []);
    }

    /** @param list<array> $rows */
    private function filterExcludedPlatforms(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0 && in_array($id, self::EXCLUDED_PLATFORM_IDS, true)) {
                continue;
            }

            // Filet de sécurité : au cas où des IDs changent dans une autre DB
            $name = isset($row['name']) ? mb_strtolower(trim((string) $row['name'])) : '';
            if ($name !== '' && in_array($name, self::EXCLUDED_PLATFORM_NAMES, true)) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
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

        // Filtre plateformes : via `platform_ids int[]` sur games (migration 005).
        // Sémantique : "au moins une des plateformes sélectionnées" (overlap).
        $platforms = $filters['platforms'] ?? [];
        if (is_array($platforms) && !empty($platforms)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $platforms), static fn($x) => $x > 0)));
            if (!empty($ids)) {
                $qs .= "&platform_ids=ov.{" . implode(',', $ids) . "}";
            }
        }

        // Filtre genres géré plus haut (car conflit possible avec la pagination cursor `or=(...)`).

        // Tri "Plus récents" et "Prochaines sorties" : bornes relatives à aujourd'hui
        // (le mode "all" / défaut n'applique aucune borne : inclut jeux futurs et sans date)
        if ($sort === 'recent') {
            $qs .= '&release_date=lte.' . date('Y-m-d');
        } elseif ($sort === 'upcoming') {
            $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
            $qs .= '&release_date=gte.' . $tomorrow;
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
            'all', ''    => 'id.desc',
            'recent'     => 'release_date.desc.nullslast,id.desc',
            'upcoming'   => 'release_date.asc.nullslast,id.asc',
            'release_asc'=> 'release_date.asc.nullslast,id.asc',
            'rating'     => 'igdb_rating.desc.nullslast,id.desc',
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
