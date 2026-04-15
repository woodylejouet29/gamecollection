<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Throwable;

/**
 * Fiche jeu complète depuis Supabase.
 *
 * Retourne pour un slug donné :
 *   - Métadonnées du jeu (colonnes games + JSONB décodés)
 *   - Plateformes uniques associées
 *   - Dates de sortie par région
 *   - Versions / éditions / DLC
 *   - Avis utilisateurs
 *   - Compteur wishlist + statut personnel si userId fourni
 *   - Compteur d'entrées collection si userId fourni
 */
class GameService
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

    /**
     * @param  string      $slug
     * @param  string|null $userId  UUID de l'utilisateur connecté (pour vérifier wishlist perso)
     * @return array{
     *   game:               array,
     *   unique_platforms:   array,
     *   releases_by_region: array,
     *   versions:           array,
     *   reviews:            array,
     *   avg_review:         ?float,
     *   wishlist_count:     int,
     *   is_wishlisted:      bool,
     *   collection_count:   int
     * }|null
     */
    public function getBySlug(string $slug, ?string $userId = null): ?array
    {
        $games = $this->get(
            '/rest/v1/games'
            . '?slug=eq.'  . rawurlencode($slug)
            . '&select=id,igdb_id,title,slug,synopsis,storyline,cover_url'
            . ',igdb_rating,igdb_rating_count,aggregated_rating,total_rating'
            . ',release_date,developer,publisher,genres,screenshots,videos,version_parent_igdb_id'
            . '&limit=1'
        );

        if (empty($games)) {
            return null;
        }

        $game   = $games[0];
        $gameId = (int) $game['id'];

        // ── Requêtes parallèles ──────────────────────────────────────────────
        $promises = [
            'platforms' => $this->http->getAsync(
                $this->supabaseUrl
                . '/rest/v1/game_platforms?game_id=eq.' . $gameId
                . '&select=region,release_date,platforms(id,name,slug,abbreviation,generation)'
                . '&order=release_date.asc.nullslast',
                ['headers' => $this->headers()]
            ),
            'versions' => $this->http->getAsync(
                $this->supabaseUrl
                . '/rest/v1/game_versions?game_id=eq.' . $gameId
                . '&select=id,igdb_id,name,description,cover_url,is_dlc&order=is_dlc.asc,name.asc',
                ['headers' => $this->headers()]
            ),
            'reviews' => $this->http->getAsync(
                $this->supabaseUrl
                . '/rest/v1/reviews?game_id=eq.' . $gameId
                . '&select=rating,body,created_at,users(username,avatar_url)'
                . '&order=created_at.desc&limit=12',
                ['headers' => $this->headers()]
            ),
            'wishlist_count' => $this->http->getAsync(
                $this->supabaseUrl
                . '/rest/v1/wishlist?game_id=eq.' . $gameId . '&select=id',
                // `exact` devient coûteux dès que la table grossit.
                // `estimated` garde une UI fluide et réduit la charge DB.
                ['headers' => array_merge($this->headers(), ['Prefer' => 'count=estimated'])]
            ),
        ];

        if ($userId !== null) {
            $promises['wishlisted'] = $this->http->getAsync(
                $this->supabaseUrl
                . '/rest/v1/wishlist?game_id=eq.' . $gameId
                . '&user_id=eq.' . rawurlencode($userId) . '&limit=1',
                ['headers' => $this->headers()]
            );

            // Nombre d'entrées dans la collection pour ce jeu (un utilisateur peut l'avoir plusieurs fois).
            // HEAD = pas de body, mais Content-Range contient le total (fiable + léger).
            $promises['collection_count'] = $this->http->requestAsync(
                'HEAD',
                $this->supabaseUrl
                . '/rest/v1/collection_entries?select=id'
                . '&game_id=eq.' . $gameId
                . '&user_id=eq.' . rawurlencode($userId),
                ['headers' => array_merge($this->headers(), ['Prefer' => 'count=exact'])]
            );
        }

        $igdbId = (int) ($game['igdb_id'] ?? 0);
        $versionParentIgdbId = isset($game['version_parent_igdb_id']) && is_numeric($game['version_parent_igdb_id'])
            ? (int) $game['version_parent_igdb_id']
            : 0;

        $isEdition = $versionParentIgdbId > 0;
        $rootIgdbId = $isEdition ? $versionParentIgdbId : $igdbId;

        if ($rootIgdbId > 0) {
            $baseSibling = $this->supabaseUrl
                . '/rest/v1/games?select=id,igdb_id,title,slug,synopsis,cover_url'
                . '&id=neq.' . $gameId . '&';
            // IMPORTANT PERF: sur le projet v2, on stocke aussi `version_parent_igdb_id` (int) dans `games`
            // pour éviter les filtres JSONB coûteux.
            if ($isEdition) {
                // Sur une édition : afficher le jeu de base + les autres éditions (même root),
                // en excluant la ligne courante.
                $promises['version_siblings'] = $this->http->getAsync(
                    $baseSibling . 'or=('
                    . 'igdb_id.eq.' . $rootIgdbId
                    . ',version_parent_igdb_id.eq.' . $rootIgdbId
                    . ')',
                    ['headers' => $this->headers()]
                );
            } else {
                // Sur le jeu de base : afficher uniquement ses éditions.
                $promises['version_siblings'] = $this->http->getAsync(
                    $baseSibling . 'version_parent_igdb_id=eq.' . $rootIgdbId,
                    ['headers' => $this->headers()]
                );
            }
        }

        try {
            $settled = PromiseUtils::settle($promises)->wait();
        } catch (Throwable) {
            $settled = [];
        }

        $platforms    = $this->fromSettled($settled, 'platforms') ?? [];
        $versions     = $this->fromSettled($settled, 'versions')  ?? [];
        $reviews      = $this->fromSettled($settled, 'reviews')   ?? [];
        $isWishlisted = false;
        $wishlistCount = 0;
        $collectionCount = 0;

        if (isset($settled['wishlisted']) && $settled['wishlisted']['state'] === 'fulfilled') {
            $wData = json_decode((string) $settled['wishlisted']['value']->getBody(), true);
            $isWishlisted = is_array($wData) && !empty($wData);
        }

        if (isset($settled['wishlist_count']) && $settled['wishlist_count']['state'] === 'fulfilled') {
            $range = $settled['wishlist_count']['value']->getHeaderLine('Content-Range');
            if (preg_match('/\/(\d+)$/', $range, $m)) {
                $wishlistCount = (int) $m[1];
            }
        }

        if (isset($settled['collection_count']) && $settled['collection_count']['state'] === 'fulfilled') {
            $range = $settled['collection_count']['value']->getHeaderLine('Content-Range');
            if (preg_match('/\/(\d+)$/', $range, $m)) {
                $collectionCount = (int) $m[1];
            }
        }

        // ── Décoder les champs JSONB ─────────────────────────────────────────
        $game['genres']      = $this->jsonField($game['genres']      ?? null);
        $game['screenshots'] = $this->jsonField($game['screenshots'] ?? null);
        $game['videos']      = $this->jsonField($game['videos']      ?? null);
        // On ne stocke plus le JSON IGDB complet en base (raw_igdb_data), pour éviter l'explosion TOAST.
        // Les relations "DLC/expansions/remasters..." seront dérivées d'autres tables/colonnes dédiées.
        $game['raw_igdb'] = [];
        unset($game['raw_igdb_data']);
        // On n'affiche pas ce champ dans la vue, mais il est utile côté service pour construire le groupe.
        unset($game['version_parent_igdb_id']);

        // ── Plateformes uniques (sans doublons) ───────────────────────────────
        $uniquePlatforms = [];
        $seenIds         = [];
        foreach ($platforms as $gp) {
            $p = $gp['platforms'] ?? null;
            if ($p && !in_array($p['id'], $seenIds, true)) {
                $uniquePlatforms[] = $p;
                $seenIds[]         = $p['id'];
            }
        }

        // ── Dates de sortie groupées par région ───────────────────────────────
        $releasesByRegion = [];
        foreach ($platforms as $gp) {
            $p = $gp['platforms'] ?? null;
            if (!$p) continue;
            $region = $gp['region'] ?? 'other';
            $releasesByRegion[$region][] = [
                'platform' => $p,
                'date'     => $gp['release_date'] ?? null,
            ];
        }

        // ── Moyenne des avis ──────────────────────────────────────────────────
        $avgReview = null;
        if (!empty($reviews)) {
            $ratings = array_filter(
                array_column($reviews, 'rating'),
                fn($r) => is_numeric($r)
            );
            if (!empty($ratings)) {
                $avgReview = round(array_sum($ratings) / count($ratings), 1);
            }
        }

        $versions = $this->mergeVersionsWithIgdbRelations(
            $versions,
            $game['raw_igdb'],
            (int) ($game['igdb_id'] ?? 0),
            $this->collectVersionSiblingRefs($settled, $rootIgdbId > 0 ? $rootIgdbId : null)
        );

        return [
            'game'               => $game,
            'unique_platforms'   => $uniquePlatforms,
            'releases_by_region' => $releasesByRegion,
            'versions'           => $versions,
            'reviews'            => $reviews,
            'avg_review'         => $avgReview,
            'wishlist_count'     => $wishlistCount,
            'is_wishlisted'      => $isWishlisted,
            'collection_count'   => $collectionCount,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function fromSettled(array $settled, string $key): ?array
    {
        if (!isset($settled[$key]) || $settled[$key]['state'] !== 'fulfilled') {
            return null;
        }
        $data = json_decode(
            (string) $settled[$key]['value']->getBody(),
            true,
            512,
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        return is_array($data) ? $data : null;
    }

    /**
     * Autres lignes `games` dont raw_igdb_data.version_parent pointe vers ce jeu (éditions IGDB).
     *
     * @return list<array{igdb_id:int,name:string,slug:string,description:?string,cover_url:?string}>
     */
    private function collectVersionSiblingRefs(array $settled, ?int $rootIgdbId = null): array
    {
        $a   = $this->fromSettled($settled, 'version_siblings') ?? [];
        $b   = [];
        $map = [];
        foreach (array_merge($a, $b) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = $row;
        }

        $refs = [];
        foreach ($map as $row) {
            $name = trim((string) ($row['title'] ?? ''));
            if ($name === '') {
                continue;
            }
            $syn = $row['synopsis'] ?? null;
            $refs[] = [
                'igdb_id'     => (int) ($row['igdb_id'] ?? 0),
                'name'        => $name,
                'slug'        => trim((string) ($row['slug'] ?? '')),
                'description' => is_string($syn) && $syn !== '' ? $syn : null,
                'cover_url'   => isset($row['cover_url']) && is_string($row['cover_url']) && $row['cover_url'] !== ''
                    ? $row['cover_url']
                    : null,
            ];
        }

        // Tri: jeu de base en premier (si présent), puis autres éditions par nom.
        usort($refs, function (array $x, array $y) use ($rootIgdbId): int {
            $xi = (int) ($x['igdb_id'] ?? 0);
            $yi = (int) ($y['igdb_id'] ?? 0);
            if ($rootIgdbId !== null) {
                $xIsBase = $xi === $rootIgdbId;
                $yIsBase = $yi === $rootIgdbId;
                if ($xIsBase !== $yIsBase) {
                    return $xIsBase ? -1 : 1;
                }
            }
            $xn = mb_strtolower((string) ($x['name'] ?? ''));
            $yn = mb_strtolower((string) ($y['name'] ?? ''));
            return $xn <=> $yn;
        });

        return $refs;
    }

    private function jsonField(mixed $val): array
    {
        if (is_array($val))  return $val;
        if (!is_string($val) || $val === '' || $val === 'null') return [];
        return json_decode($val, true, 512, JSON_INVALID_UTF8_SUBSTITUTE) ?? [];
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

    /**
     * Fusionne game_versions (Supabase) avec les jeux liés IGDB stockés dans raw_igdb :
     * DLC / extensions (dlcs, expansions, standalone_expansions) et éditions dérivées
     * (remasters, remakes, contenus packagés bundles), plus les fiches « édition »
     * (Deluxe, etc.) trouvées en base via version_parent → ce jeu.
     *
     * Nécessite une synchro jeux avec GAME_FIELDS étendus (IgdbSync) pour avoir noms + jaquettes.
     *
     * @param list<array{igdb_id:int,name:string,slug:string,description:?string,cover_url:?string}> $versionParentSiblingRefs
     */
    private function mergeVersionsWithIgdbRelations(
        array $dbRows,
        array $rawIgdb,
        int $currentIgdbId,
        array $versionParentSiblingRefs = []
    ): array {
        $seen   = [];
        $merged = [];

        $markSeen = function (array $row) use (&$seen): void {
            $igdbId = isset($row['igdb_id']) ? (int) $row['igdb_id'] : 0;
            if ($igdbId > 0) {
                $seen['i:' . $igdbId] = true;
            }
            $n = mb_strtolower(trim((string) ($row['name'] ?? '')));
            if ($n !== '') {
                $d = !empty($row['is_dlc']) ? '1' : '0';
                $seen['n:' . $n . ':' . $d] = true;
            }
        };

        $isSeenIgdb = function (int $igdbId, string $name, bool $isDlc) use (&$seen): bool {
            if ($igdbId > 0 && isset($seen['i:' . $igdbId])) {
                return true;
            }
            $n = mb_strtolower(trim($name));
            if ($n !== '') {
                $d = $isDlc ? '1' : '0';
                if (isset($seen['n:' . $n . ':' . $d])) {
                    return true;
                }
            }

            return false;
        };

        foreach ($dbRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $merged[] = $row;
            $markSeen($row);
        }

        $editionGames = $this->dedupeIgdbGameRefs(array_merge(
            $this->normalizeIgdbGameRefs($rawIgdb['remasters'] ?? []),
            $this->normalizeIgdbGameRefs($rawIgdb['remakes'] ?? []),
            $this->normalizeIgdbGameRefs($rawIgdb['bundles'] ?? []),
            $versionParentSiblingRefs
        ));

        foreach ($editionGames as $g) {
            $gid = $g['igdb_id'];
            if ($currentIgdbId > 0 && $gid === $currentIgdbId) {
                continue;
            }
            if ($isSeenIgdb($gid, $g['name'], false)) {
                continue;
            }
            $row = [
                'name'        => $g['name'],
                'description' => $g['description'],
                'cover_url'   => $g['cover_url'],
                'is_dlc'      => false,
            ];
            if ($g['slug'] !== '') {
                $row['slug'] = $g['slug'];
            }
            $merged[] = $row;
            $markSeen(array_merge($row, ['igdb_id' => $gid]));
        }

        $dlcGames = $this->dedupeIgdbGameRefs(array_merge(
            $this->normalizeIgdbGameRefs($rawIgdb['dlcs'] ?? []),
            $this->normalizeIgdbGameRefs($rawIgdb['expansions'] ?? []),
            $this->normalizeIgdbGameRefs($rawIgdb['standalone_expansions'] ?? [])
        ));

        foreach ($dlcGames as $g) {
            $gid = $g['igdb_id'];
            if ($currentIgdbId > 0 && $gid === $currentIgdbId) {
                continue;
            }
            if ($isSeenIgdb($gid, $g['name'], true)) {
                continue;
            }
            $row = [
                'name'        => $g['name'],
                'description' => $g['description'],
                'cover_url'   => $g['cover_url'],
                'is_dlc'      => true,
            ];
            if ($g['slug'] !== '') {
                $row['slug'] = $g['slug'];
            }
            $merged[] = $row;
            $markSeen(array_merge($row, ['igdb_id' => $gid]));
        }

        return $merged;
    }

    /**
     * @return list<array{igdb_id:int,name:string,slug:string,description:?string,cover_url:?string}>
     */
    private function normalizeIgdbGameRefs(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                continue;
            }
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $cover = null;
            if (isset($item['cover']) && is_array($item['cover']) && !empty($item['cover']['url'])) {
                $cover = trim((string) $item['cover']['url']);
            }
            if ($cover === '') {
                $cover = null;
            }
            $summary = $item['summary'] ?? null;
            $desc    = is_string($summary) && $summary !== '' ? $summary : null;

            $out[] = [
                'igdb_id'     => (int) $item['id'],
                'name'        => $name,
                'slug'        => trim((string) ($item['slug'] ?? '')),
                'description' => $desc,
                'cover_url'   => $cover,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{igdb_id:int,name:string,slug:string,description:?string,cover_url:?string}> $items
     * @return list<array{igdb_id:int,name:string,slug:string,description:?string,cover_url:?string}>
     */
    private function dedupeIgdbGameRefs(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $it) {
            $id = $it['igdb_id'] ?? 0;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[]     = $it;
        }

        return $out;
    }
}
