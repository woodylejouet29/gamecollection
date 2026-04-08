<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Throwable;

/**
 * Service de synchronisation IGDB → Supabase.
 *
 * Étapes recommandées :
 *   1. syncPlatforms()              — ~300 plateformes, quelques secondes
 *   2. syncGamesByYear($year)       — jeux d'une année (images WebP → Supabase Storage)
 *   3. syncAllYears($from, $to)     — boucle pluriannuelle 1950 → currentYear+3
 *   4. syncGameVersions()           — éditions/versions
 *   5. syncGamePlatformsForYear()   — liaisons jeu × plateforme par année
 *
 * Champs récupérés : GAME_FIELDS reprend l'intégralité de l'expansion IGDB
 * (identique à sync_igdb_full.php).
 *
 * Images : IGDB_IMAGES_TARGET=supabase → SupabaseObjectStorage (bucket igdb-assets).
 */
class IgdbSync
{
    /** Taille de page IGDB (limit) pour toutes les syncs. */
    private const IGDB_PAGE_SIZE = 100;

    /**
     * Catégories IGDB à EXCLURE (filtrage PHP).
     * Les valeurs protobuf par défaut (category=0 = main_game) ne sont pas
     * sérialisées par IGDB et ne matchent aucune condition WHERE côté API.
     * On récupère tout et on exclut : mods (5), forks (12), updates (14).
     */
    private const EXCLUDED_CATEGORIES = [5, 12, 14];

    /** Fichier de progression pour la sync annuelle. */
    private const YEARS_PROGRESS_FILE = 'sync_years_progress.json';

    /**
     * Champs IGDB ciblés — couvre toutes les données utiles sans sur-expansion.
     *
     * Principe : on utilise des champs précis plutôt que .* sur les sous-entités
     * volumineuses (company, platform, etc.) pour éviter que la réponse IGDB
     * dépasse la limite de taille et renvoie silencieusement [].
     * Les champs scalaires de chaque entité sont listés explicitement.
     */
    private const GAME_FIELDS = <<<'APICALYPSE'
fields
    aggregated_rating,
    aggregated_rating_count,
    alternative_names.name,
    alternative_names.comment,
    artworks.url,
    artworks.width,
    artworks.height,
    bundles.id,
    bundles.name,
    bundles.slug,
    bundles.summary,
    bundles.cover.url,
    category,
    checksum,
    collection.id,
    collection.name,
    collection.slug,
    cover.url,
    cover.width,
    cover.height,
    created_at,
    dlcs.id,
    dlcs.name,
    dlcs.slug,
    dlcs.summary,
    dlcs.cover.url,
    expanded_games,
    expansions.id,
    expansions.name,
    expansions.slug,
    expansions.summary,
    expansions.cover.url,
    external_games.category,
    external_games.uid,
    external_games.url,
    external_games.name,
    first_release_date,
    follows,
    forks,
    franchise.id,
    franchise.name,
    franchise.slug,
    franchises.id,
    franchises.name,
    franchises.slug,
    game_engines.id,
    game_engines.name,
    game_engines.slug,
    game_modes.id,
    game_modes.name,
    game_modes.slug,
    genres.id,
    genres.name,
    genres.slug,
    hypes,
    involved_companies.developer,
    involved_companies.publisher,
    involved_companies.porting,
    involved_companies.supporting,
    involved_companies.company.id,
    involved_companies.company.name,
    involved_companies.company.slug,
    involved_companies.company.country,
    involved_companies.company.description,
    keywords.id,
    keywords.name,
    keywords.slug,
    multiplayer_modes.campaigncoop,
    multiplayer_modes.dropin,
    multiplayer_modes.lancoop,
    multiplayer_modes.offlinecoop,
    multiplayer_modes.offlinecoopmax,
    multiplayer_modes.offlinemax,
    multiplayer_modes.onlinecoop,
    multiplayer_modes.onlinecoopmax,
    multiplayer_modes.onlinemax,
    multiplayer_modes.splitscreen,
    multiplayer_modes.splitscreenonline,
    name,
    parent_game,
    platforms.id,
    platforms.name,
    platforms.slug,
    platforms.abbreviation,
    platforms.generation,
    player_perspectives.id,
    player_perspectives.name,
    player_perspectives.slug,
    ports,
    rating,
    rating_count,
    release_dates.id,
    release_dates.date,
    release_dates.human,
    release_dates.region,
    release_dates.category,
    release_dates.platform.id,
    release_dates.platform.name,
    release_dates.platform.abbreviation,
    remakes.id,
    remakes.name,
    remakes.slug,
    remakes.summary,
    remakes.cover.url,
    remasters.id,
    remasters.name,
    remasters.slug,
    remasters.summary,
    remasters.cover.url,
    screenshots.url,
    screenshots.width,
    screenshots.height,
    similar_games,
    slug,
    standalone_expansions.id,
    standalone_expansions.name,
    standalone_expansions.slug,
    standalone_expansions.summary,
    standalone_expansions.cover.url,
    status,
    storyline,
    summary,
    tags,
    themes.id,
    themes.name,
    themes.slug,
    total_rating,
    total_rating_count,
    updated_at,
    url,
    version_parent,
    version_title,
    videos.video_id,
    videos.name,
    websites.category,
    websites.url,
    websites.trusted
APICALYPSE;

    private IgdbClient     $igdb;
    private Client         $http;
    private string         $supabaseUrl;
    private string         $serviceKey;
    private ImageConverter $converter;
    private string         $cacheDir;

    /** Caches en mémoire (chargés une seule fois par exécution). */
    private array $gameIdMap     = [];   // igdb_id → local id
    private array $platformIdMap = [];   // igdb_id → local id

    /**
     * IGDB renvoie `version_parent` parfois comme int, parfois comme objet {id: ...}.
     * On normalise en int pour stocker dans `games.version_parent_igdb_id`.
     */
    private function extractVersionParentIgdbId(mixed $val): ?int
    {
        if (is_int($val)) {
            return $val > 0 ? $val : null;
        }
        if (is_string($val) && ctype_digit($val)) {
            $n = (int) $val;
            return $n > 0 ? $n : null;
        }
        if (is_array($val)) {
            $id = $val['id'] ?? null;
            if (is_int($id)) {
                return $id > 0 ? $id : null;
            }
            if (is_string($id) && ctype_digit($id)) {
                $n = (int) $id;
                return $n > 0 ? $n : null;
            }
        }
        return null;
    }

    public function __construct()
    {
        $this->igdb        = new IgdbClient();
        // Supabase (PostgREST) peut dépasser 20s sous charge (timeouts 504).
        // On augmente le timeout pour éviter les cURL 28 lors des GET/POST batch.
        $this->http        = new Client([
            'timeout'         => 180,
            'connect_timeout' => 30,
            'http_errors'     => false,
        ]);
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey  = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
        $this->converter   = new ImageConverter();
        $this->cacheDir    = $this->igdb->getCacheDir();
    }

    // ──────────────────────────────────────────────
    //  1. Plateformes & accessoires
    // ──────────────────────────────────────────────

    /**
     * Synchronise toutes les plateformes IGDB.
     * Les logos sont convertis en WebP et uploadés sur Supabase Storage.
     */
    public function syncPlatforms(bool $withImages = true): array
    {
        $stats  = ['synced' => 0, 'failed' => 0, 'errors' => []];
        $offset = 0;
        $limit  = self::IGDB_PAGE_SIZE;

        Logger::info('IGDB sync: début platforms');

        do {
            $results = $this->igdb->query('platforms', implode(' ', [
                'fields id, name, slug, abbreviation,',
                '       platform_logo.url, generation, platform_family.name, category;',
                "limit {$limit};",
                "offset {$offset};",
                'sort id asc;',
            ]), "platforms/lim{$limit}/off{$offset}");

            if (empty($results)) {
                break;
            }

            // Télécharger les logos en parallèle si demandé
            $logoLocalMap = [];
            if ($withImages) {
                $logoItems = [];
                foreach ($results as $p) {
                    $logoUrl = $this->formatImageUrl($p['platform_logo']['url'] ?? null, 'thumb');
                    if ($logoUrl) {
                        $logoItems["platforms/{$p['id']}.webp"] = $logoUrl;
                    }
                }
                $logoLocalMap = $this->converter->convertBatch($logoItems);
            }

            $batch = [];
            foreach ($results as $p) {
                $logoUrl   = $this->formatImageUrl($p['platform_logo']['url'] ?? null, 'thumb');
                $localLogo = $logoLocalMap["platforms/{$p['id']}.webp"] ?? null;

                $batch[] = [
                    'igdb_id'      => (int) $p['id'],
                    'name'         => (string)($p['name'] ?? 'Inconnu'),
                    'slug'         => $p['slug']         ?? null,
                    'abbreviation' => $p['abbreviation'] ?? null,
                    'logo_url'     => $localLogo ?? $logoUrl,
                    'generation'   => isset($p['generation']) ? (int) $p['generation'] : null,
                    'manufacturer' => $p['platform_family']['name'] ?? null,
                ];
            }

            try {
                $this->upsertBatch('/platforms', $batch, 'igdb_id');
                $stats['synced'] += count($batch);
            } catch (Throwable $e) {
                $stats['failed'] += count($batch);
                $stats['errors'][] = "Batch platforms: " . $e->getMessage();
                Logger::exception($e, 'IgdbSync::syncPlatforms');
            }

            $offset += $limit;
        } while (count($results) === $limit);

        Logger::info('IGDB sync: fin platforms', $stats);
        return $stats;
    }

    // ──────────────────────────────────────────────
    //  2. Jeux — par année
    // ──────────────────────────────────────────────

    /**
     * Synchronise tous les jeux sortis durant l'année $year.
     * Utilise les GAME_FIELDS complets : un seul appel API récupère jeux,
     * jaquettes, sociétés, plateformes ET dates de sortie par région.
     *
     * Peuple automatiquement `game_platforms` à partir des `release_dates`
     * déjà présents dans la réponse — aucun appel API supplémentaire requis.
     *
     * @param bool          $withImages      Télécharge et convertit les jaquettes en WebP.
     * @param bool          $withPlatforms   Peuple aussi `game_platforms` depuis les release_dates
     *                                       déjà inclus dans la réponse (défaut : true).
     * @param callable|null $onBatch         Callback de progression :
     *                                       fn(int $batchNum, int|string $totalBatches,
     *                                          int $synced, int $total, int $year)
     */
    public function syncGamesByYear(
        int       $year,
        bool      $withImages    = true,
        bool      $withPlatforms = true,
        ?callable $onBatch       = null
    ): array {
        $yearStart = $this->yearToTimestamp($year, 1, 1);
        $yearEnd   = $this->yearToTimestamp($year + 1, 1, 1);

        $stats = [
            'synced'            => 0,
            'failed'            => 0,
            'year'              => $year,
            'game_platforms'    => 0,
            'errors'            => [],
        ];
        $offset = 0;
        $limit  = self::IGDB_PAGE_SIZE;

        $whereBody    = "first_release_date >= {$yearStart} & first_release_date < {$yearEnd}";
        echo "[" . date('Y-m-d H:i:s') . "]   IGDB count (année {$year})…\n";
        $total        = $this->igdb->count('games', $whereBody);
        echo "[" . date('Y-m-d H:i:s') . "]   IGDB count (année {$year}) = {$total}\n";
        $totalBatches = $total > 0 ? (int) ceil($total / $limit) : '?';

        Logger::info("IGDB sync: début games année {$year}", [
            'total_igdb'      => $total,
            'with_platforms'  => $withPlatforms,
        ]);

        // Charger la map des plateformes une seule fois si on peuple game_platforms
        if ($withPlatforms && empty($this->platformIdMap)) {
            $this->loadPlatformIdMap();
        }

        $fields = trim(self::GAME_FIELDS);

        do {
            echo "[" . date('Y-m-d H:i:s') . "]   IGDB query games {$year} offset {$offset} limit {$limit}…\n";
            $results = $this->igdb->query('games', implode("\n", [
                "{$fields};",
                "where first_release_date >= {$yearStart}",
                "  & first_release_date < {$yearEnd};",
                "limit {$limit};",
                "offset {$offset};",
                'sort first_release_date asc;',
            ]), "games/{$year}/lim{$limit}/off{$offset}");
            echo "[" . date('Y-m-d H:i:s') . "]   IGDB query OK (".count($results)." résultats)\n";

            if (empty($results)) {
                break;
            }

            $rawCount = count($results);

            // Filtre PHP : exclure mods (5), forks (12), updates (14)
            $results = array_values(array_filter(
                $results,
                fn($g) => !isset($g['category']) || !in_array((int) $g['category'], self::EXCLUDED_CATEGORIES, true)
            ));

            // Télécharger toutes les jaquettes du lot en parallèle → Supabase
            $coverLocalMap = [];
            if ($withImages) {
                $coverItems = [];
                foreach ($results as $g) {
                    $gid      = (int) $g['id'];
                    $coverUrl = $this->formatImageUrl($g['cover']['url'] ?? null, 'cover_big');
                    if ($coverUrl) {
                        $coverItems["games/{$gid}.webp"] = $coverUrl;
                    }
                }
                $coverLocalMap = $this->converter->convertBatch($coverItems);
            }

            $batch = [];
            foreach ($results as $g) {
                $gid = (int) $g['id'];

                // Developer / publisher depuis involved_companies déjà expandé
                $developer = null;
                $publisher = null;
                foreach ($g['involved_companies'] ?? [] as $ic) {
                    if (is_array($ic)) {
                        if (!empty($ic['developer']) && $developer === null) {
                            $developer = $ic['company']['name'] ?? null;
                        }
                        if (!empty($ic['publisher']) && $publisher === null) {
                            $publisher = $ic['company']['name'] ?? null;
                        }
                    }
                }

                $genres = array_map(
                    fn($gr) => is_array($gr) ? ['name' => $gr['name'] ?? ''] : ['name' => ''],
                    $g['genres'] ?? []
                );
                $screenshots = array_values(array_filter(array_map(
                    fn($s) => is_array($s) ? $this->formatImageUrl($s['url'] ?? null, 'screenshot_med') : null,
                    $g['screenshots'] ?? []
                )));
                $videos = array_values(array_filter(
                    array_map(fn($v) => is_array($v) ? ($v['video_id'] ?? null) : null, $g['videos'] ?? [])
                ));

                $coverUrl   = $this->formatImageUrl($g['cover']['url'] ?? null, 'cover_big');
                $localCover = $coverLocalMap["games/{$gid}.webp"] ?? null;

                $batch[] = [
                    'igdb_id'           => $gid,
                    'title'             => (string)($g['name'] ?? 'Inconnu'),
                    'slug'              => (string)($g['slug'] ?? "game-{$gid}"),
                    'synopsis'          => $g['summary']   ?? null,
                    'storyline'         => $g['storyline'] ?? null,
                    'cover_url'         => $localCover ?? $coverUrl,
                    'igdb_rating'       => isset($g['rating'])            ? round((float) $g['rating'], 2)            : null,
                    'igdb_rating_count' => isset($g['rating_count'])      ? (int) $g['rating_count']                  : null,
                    'aggregated_rating' => isset($g['aggregated_rating']) ? round((float) $g['aggregated_rating'], 2) : null,
                    'total_rating'      => isset($g['total_rating'])      ? round((float) $g['total_rating'], 2)      : null,
                    'release_date'      => isset($g['first_release_date']) ? date('Y-m-d', (int) $g['first_release_date']) : null,
                    'developer'         => $developer,
                    'publisher'         => $publisher,
                    // IMPORTANT: colonnes JSONB côté Postgres → envoyer des tableaux, pas des strings JSON.
                    // Sinon Postgres stocke une "string JSON" au lieu d'un vrai JSONB, ce qui casse les filtres (cs/@>).
                    'genres'            => array_values($genres),
                    'screenshots'       => $screenshots,
                    'videos'            => $videos,
                    // platform_ids (integer[]) est géré automatiquement par le trigger
                    // trg_sync_platform_ids (migration 005) après upsert de game_platforms.
                    'accessories'       => '[]',
                    // Même principe que genres/screenshots : objet JSON, pas une string JSON.
                    // Sinon Postgres stocke un JSONB « string » et les filtres PostgREST (raw_igdb_data->version_parent) ne matchent jamais.
                    'raw_igdb_data'     => $g,
                    // IMPORTANT PERF: colonne dédiée (indexable) pour retrouver rapidement les éditions
                    // liées à un jeu de base, sans WHERE sur JSONB.
                    'version_parent_igdb_id' => $this->extractVersionParentIgdbId($g['version_parent'] ?? null),
                    'cached_at'         => date('c'),
                ];
            }

            // ── Upsert des jeux ──────────────────────────────────────────────
            $batchNum = (int) ($offset / $limit) + 1;
            $parts    = array_chunk($batch, self::IGDB_PAGE_SIZE);
            foreach ($parts as $i => $part) {
                $subNum = $i + 1;
                try {
                    echo "[" . date('Y-m-d H:i:s') . "]   Supabase upsert /games (lot {$batchNum}.{$subNum} / " . count($parts) . ")…\n";
                    $this->upsertBatch('/games', $part, 'igdb_id');
                    echo "[" . date('Y-m-d H:i:s') . "]   Supabase upsert /games OK (lot {$batchNum}.{$subNum})\n";
                    $stats['synced'] += count($part);
                } catch (Throwable $e) {
                    foreach ($part as $row) {
                        try {
                            $this->upsertBatch('/games', [$row], 'igdb_id');
                            $stats['synced']++;
                        } catch (Throwable $e2) {
                            $stats['failed']++;
                            $stats['errors'][] = "Game {$row['igdb_id']}: " . $e2->getMessage();
                            Logger::exception($e2, 'IgdbSync::syncGamesByYear', [
                                'igdb_id' => $row['igdb_id'],
                                'year'    => $year,
                            ]);
                        }
                    }
                }
            }

            // ── Peuplement de game_platforms depuis release_dates déjà présents ──
            // Les release_dates sont inclus dans GAME_FIELDS (release_dates.*, release_dates.platform.*)
            // Aucun appel API supplémentaire n'est nécessaire.
            if ($withPlatforms && !empty($results)) {
                $igdbIds = array_map(fn($g) => (int) $g['id'], $results);
                $this->hydrateGameIdMapForIgdbIds($igdbIds);

                $gpBatch = $this->buildGamePlatformsFromGames($results);
                $gpBatch = $this->dedupeGamePlatformsBatch($gpBatch);

                if (!empty($gpBatch)) {
                    $parts = array_chunk($gpBatch, self::IGDB_PAGE_SIZE);
                    foreach ($parts as $i => $part) {
                        $subNum = $i + 1;
                        try {
                            echo "[" . date('Y-m-d H:i:s') . "]   Supabase upsert /game_platforms (lot {$batchNum}.{$subNum} / " . count($parts) . ")…\n";
                            $this->upsertBatch('/game_platforms', $part, 'game_id,platform_id,region');
                            echo "[" . date('Y-m-d H:i:s') . "]   Supabase upsert /game_platforms OK (lot {$batchNum}.{$subNum})\n";
                            $stats['game_platforms'] += count($part);
                        } catch (Throwable $e) {
                            // IMPORTANT: compter comme échec global pour ne pas afficher "succès"
                            $stats['failed'] += count($part);
                            $stats['errors'][] = "Batch game_platforms: " . $e->getMessage();
                            Logger::exception($e, 'IgdbSync::syncGamesByYear/game_platforms', ['year' => $year]);

                            // Fallback ligne par ligne pour sauver un maximum.
                            foreach ($part as $row) {
                                try {
                                    $this->upsertBatch('/game_platforms', [$row], 'game_id,platform_id,region');
                                    $stats['game_platforms']++;
                                } catch (Throwable $e2) {
                                    $stats['failed']++;
                                    $stats['errors'][] = "game_platforms game_id={$row['game_id']} platform_id={$row['platform_id']} region={$row['region']}: " . $e2->getMessage();
                                    Logger::exception($e2, 'IgdbSync::syncGamesByYear/game_platforms/row', ['year' => $year]);
                                }
                            }
                        }
                    }
                }
            }

            if ($onBatch !== null) {
                $batchNum = (int) ($offset / $limit) + 1;
                ($onBatch)($batchNum, $totalBatches, $stats['synced'], $total, $year);
            }

            $offset += $limit;
        } while ($rawCount === $limit);

        Logger::info("IGDB sync: fin games année {$year}", $stats);
        return $stats;
    }

    /**
     * Construit les lignes game_platforms à partir des release_dates
     * déjà expandés dans chaque jeu (pas d'appel API supplémentaire).
     */
    private function buildGamePlatformsFromGames(array $games): array
    {
        $batch = [];
        foreach ($games as $g) {
            $igdbGameId = (int) $g['id'];
            $gameId     = $this->gameIdMap[$igdbGameId] ?? null;
            if (!$gameId) {
                continue;
            }

            foreach ($g['release_dates'] ?? [] as $rd) {
                if (!is_array($rd)) {
                    continue;
                }

                $igdbPlatformId = is_array($rd['platform'] ?? null)
                    ? ($rd['platform']['id'] ?? null)
                    : ($rd['platform'] ?? null);

                $platformId = $igdbPlatformId
                    ? ($this->platformIdMap[(int) $igdbPlatformId] ?? null)
                    : null;

                if (!$platformId) {
                    continue;
                }

                $batch[] = [
                    'game_id'      => $gameId,
                    'platform_id'  => $platformId,
                    'region'       => $this->mapRegion($rd['region'] ?? null),
                    'release_date' => isset($rd['date']) ? date('Y-m-d', (int) $rd['date']) : null,
                ];
            }
        }
        return $batch;
    }

    // ──────────────────────────────────────────────
    //  3. Boucle pluriannuelle avec progression
    // ──────────────────────────────────────────────

    /**
     * Lance syncGamesByYear pour chaque année de $from à $to inclus.
     * Une année validée (0 failed) est sautée automatiquement sauf --force.
     *
     * @param callable|null $onYearDone fn(int $year, array $stats)
     * @param callable|null $onBatch    fn(int $batchNum, int|string $totalBatches, int $synced, int $total, int $year)
     */
    public function syncAllYears(
        int       $from          = 1950,
        int       $to            = 0,
        bool      $withImages    = true,
        bool      $force         = false,
        ?callable $onYearDone    = null,
        ?callable $onBatch       = null,
        bool      $withPlatforms = true
    ): array {
        if ($to === 0) {
            $to = (int) date('Y') + 3;
        }

        $totals = [
            'synced'         => 0,
            'failed'         => 0,
            'game_platforms' => 0,
            'years_done'     => 0,
            'years_skipped'  => 0,
        ];
        $progress = $this->loadYearsProgress();

        Logger::info("IGDB sync: boucle pluriannuelle {$from}→{$to}", [
            'with_images'    => $withImages,
            'with_platforms' => $withPlatforms,
            'force'          => $force,
        ]);

        for ($year = $from; $year <= $to; $year++) {
            if (!$force && isset($progress[$year]) && $progress[$year]['failed'] === 0) {
                $totals['years_skipped']++;
                continue;
            }

            $stats = $this->syncGamesByYear($year, $withImages, $withPlatforms, $onBatch);

            $progress[$year] = [
                'synced'  => $stats['synced'],
                'failed'  => $stats['failed'],
                'done_at' => date('c'),
            ];
            $this->saveYearsProgress($progress);

            $totals['synced']          += $stats['synced'];
            $totals['failed']          += $stats['failed'];
            $totals['game_platforms']  += $stats['game_platforms'] ?? 0;
            $totals['years_done']++;

            if ($onYearDone !== null) {
                ($onYearDone)($year, $stats);
            }
        }

        Logger::info('IGDB sync: fin boucle pluriannuelle', $totals);
        return $totals;
    }

    // ──────────────────────────────────────────────
    //  4. Versions de jeux
    // ──────────────────────────────────────────────

    /**
     * Synchronise la table `game_versions` depuis IGDB.
     * Nécessite que les jeux soient déjà présents en BDD.
     */
    public function syncGameVersions(): array
    {
        $stats  = ['synced' => 0, 'failed' => 0, 'errors' => []];
        $offset = 0;
        $limit  = self::IGDB_PAGE_SIZE;

        Logger::info('IGDB sync: début game_versions');
        $this->loadGameIdMap();

        do {
            $results = $this->igdb->query('game_versions', implode(' ', [
                // NOTE: sur l'endpoint IGDB `game_versions`, `cover` n'expose pas `url` (400 Invalid Field).
                // On récupère donc les métadonnées de version sans jaquette.
                'fields id, game.id, games.id, features.title, features.description;',
                "limit {$limit};",
                "offset {$offset};",
                'sort id asc;',
            ]));

            if (empty($results)) {
                break;
            }

            $batch = [];
            foreach ($results as $v) {
                $igdbGameId = is_array($v['game'] ?? null) ? ($v['game']['id'] ?? null) : ($v['game'] ?? null);
                if (!$igdbGameId && !empty($v['games']) && is_array($v['games'])) {
                    foreach ($v['games'] as $gg) {
                        $cand = is_array($gg) ? ($gg['id'] ?? null) : (is_int($gg) ? $gg : null);
                        if ($cand) {
                            $igdbGameId = $cand;
                            break;
                        }
                    }
                }
                $gameId     = $igdbGameId ? ($this->gameIdMap[(int) $igdbGameId] ?? null) : null;

                if (!$gameId) {
                    continue;
                }

                $batch[] = [
                    'game_id'     => $gameId,
                    'igdb_id'     => (int) $v['id'],
                    'name'        => $this->extractFeatureTitle($v['features'] ?? []),
                    'description' => $this->extractFeatureDescription($v['features'] ?? []),
                    'cover_url'   => null,
                    'is_dlc'      => false,
                ];
            }

            if (!empty($batch)) {
                try {
                    $this->upsertBatch('/game_versions', $batch, 'igdb_id');
                    $stats['synced'] += count($batch);
                } catch (Throwable $e) {
                    $stats['failed'] += count($batch);
                    $stats['errors'][] = "Batch game_versions: " . $e->getMessage();
                    Logger::exception($e, 'IgdbSync::syncGameVersions');
                }
            }

            $offset += $limit;
        } while (count($results) === $limit);

        Logger::info('IGDB sync: fin game_versions', $stats);
        return $stats;
    }

    /**
     * Synchronise `game_versions` uniquement pour les jeux dont la release_date
     * est comprise dans l'année $year (côté BDD Supabase).
     *
     * Utile pour tester rapidement que la récupération des versions fonctionne
     * sans parcourir toute la collection IGDB.
     */
    public function syncGameVersionsForYear(int $year): array
    {
        $stats = ['synced' => 0, 'failed' => 0, 'errors' => [], 'year' => $year, 'games' => 0];

        Logger::info("IGDB sync: début game_versions (année {$year})");

        $gamesForYear = $this->loadGameIdMapForYear($year); // igdb_id => local id
        $stats['games'] = count($gamesForYear);
        echo "[" . date('Y-m-d H:i:s') . "]   Jeux trouvés en BDD pour {$year} : " . number_format($stats['games'], 0, ',', ' ') . "\n";
        if ($gamesForYear === []) {
            Logger::info("IGDB sync: aucun jeu trouvé en BDD pour l'année {$year} (versions ignorées)");
            return $stats;
        }

        $igdbIds = array_keys($gamesForYear);
        $chunks  = array_chunk($igdbIds, 200);
        echo "[" . date('Y-m-d H:i:s') . "]   Requêtes IGDB game_versions : " . count($chunks) . " chunk(s)\n";

        foreach ($chunks as $chunk) {
            $inList = implode(',', $chunk);
            $offset = 0;
            $limit  = self::IGDB_PAGE_SIZE;

            do {
                echo "[" . date('Y-m-d H:i:s') . "]   IGDB query game_versions (chunk=" . count($chunk) . ", offset={$offset}, limit={$limit})…\n";
                $results = $this->igdb->query('game_versions', implode(' ', [
                    // NOTE: sur l'endpoint IGDB `game_versions`, `cover` n'expose pas `url` (400 Invalid Field).
                    'fields id, game.id, games.id, features.title, features.description;',
                    "where game = ({$inList}) | games = ({$inList});",
                    "limit {$limit};",
                    "offset {$offset};",
                    'sort id asc;',
                ]), "game_versions/year{$year}/lim{$limit}/off{$offset}/g" . hash('sha1', $inList));
                echo "[" . date('Y-m-d H:i:s') . "]   IGDB query OK (" . count($results) . " résultat(s))\n";

                if (empty($results)) {
                    break;
                }

                $batch = [];
                foreach ($results as $v) {
                    $igdbGameId = is_array($v['game'] ?? null) ? ($v['game']['id'] ?? null) : ($v['game'] ?? null);
                    if (!$igdbGameId && !empty($v['games']) && is_array($v['games'])) {
                        foreach ($v['games'] as $gg) {
                            $cand = is_array($gg) ? ($gg['id'] ?? null) : (is_int($gg) ? $gg : null);
                            if ($cand) {
                                $igdbGameId = $cand;
                                break;
                            }
                        }
                    }
                    if (!$igdbGameId) {
                        continue;
                    }
                    $gameId = $gamesForYear[(int) $igdbGameId] ?? null;
                    if (!$gameId) {
                        continue;
                    }

                    $batch[] = [
                        'game_id'     => $gameId,
                        'igdb_id'     => (int) $v['id'],
                        'name'        => $this->extractFeatureTitle($v['features'] ?? []),
                        'description' => $this->extractFeatureDescription($v['features'] ?? []),
                        'cover_url'   => null,
                        'is_dlc'      => false,
                    ];
                }

                if (!empty($batch)) {
                    try {
                        $this->upsertBatch('/game_versions', $batch, 'igdb_id');
                        $stats['synced'] += count($batch);
                    } catch (Throwable $e) {
                        $stats['failed'] += count($batch);
                        $stats['errors'][] = "Batch game_versions: " . $e->getMessage();
                        Logger::exception($e, 'IgdbSync::syncGameVersionsForYear', ['year' => $year]);
                    }
                }

                $offset += $limit;
            } while (count($results) === $limit);
        }

        Logger::info("IGDB sync: fin game_versions (année {$year})", $stats);
        return $stats;
    }

    /**
     * Charge (depuis Supabase) le mapping igdb_id → id des jeux sortis sur l'année.
     * Basé sur `release_date` (DATE) ; ignore les jeux sans release_date.
     */
    private function loadGameIdMapForYear(int $year): array
    {
        $map = [];
        $from = sprintf('%04d-01-01', $year);
        $to   = sprintf('%04d-01-01', $year + 1);

        $offset = 0;
        $limit  = 1000;

        do {
            $url = "{$this->supabaseUrl}/rest/v1/games"
                . "?select=id,igdb_id"
                . "&release_date=gte.{$from}"
                . "&release_date=lt.{$to}"
                . "&limit={$limit}"
                . "&offset={$offset}";

            $res  = $this->http->get($url, ['headers' => $this->restHeaders()]);
            $data = json_decode((string) $res->getBody(), true);
            $data = is_array($data) ? $data : [];

            foreach ($data as $row) {
                if (isset($row['igdb_id'], $row['id'])) {
                    $map[(int) $row['igdb_id']] = (int) $row['id'];
                }
            }

            $offset += $limit;
        } while (count($data) === $limit);

        return $map;
    }

    // ──────────────────────────────────────────────
    //  5. Jeux × Plateformes
    // ──────────────────────────────────────────────

    /**
     * Synchronise `game_platforms` depuis `release_dates` IGDB (parcours complet).
     */
    public function syncGamePlatforms(): array
    {
        Logger::info('IGDB sync: début game_platforms (parcours global)');
        return $this->syncGamePlatformsInternal(null);
    }

    /**
     * Synchronise `game_platforms` pour les release_dates d'une année précise.
     */
    public function syncGamePlatformsForYear(int $year): array
    {
        Logger::info("IGDB sync: début game_platforms (année {$year})");
        return $this->syncGamePlatformsInternal($year);
    }

    private const GAME_ID_MAP_CHUNK       = 200;
    private const GAME_ID_MAP_CONCURRENCY = 8;

    private function syncGamePlatformsInternal(?int $year): array
    {
        $stats  = ['synced' => 0, 'failed' => 0, 'errors' => []];
        $offset = 0;
        $limit  = self::IGDB_PAGE_SIZE;

        $this->gameIdMap = [];
        $this->loadPlatformIdMap();

        $yearStart = null;
        $yearEnd   = null;
        if ($year !== null) {
            $yearStart = $this->yearToTimestamp($year, 1, 1);
            $yearEnd   = $this->yearToTimestamp($year + 1, 1, 1);
        }

        do {
            $lines = ['fields id, game, platform, region, date;'];
            if ($year !== null) {
                $lines[] = "where date >= {$yearStart} & date < {$yearEnd};";
            }
            $lines[] = "limit {$limit};";
            $lines[] = "offset {$offset};";
            $lines[] = $year !== null ? 'sort date asc;' : 'sort id asc;';

            $cacheKey = $year !== null
                ? "release_dates/{$year}/lim{$limit}/off{$offset}"
                : "release_dates/lim{$limit}/off{$offset}";

            $results = $this->igdb->query('release_dates', implode(' ', $lines), $cacheKey);

            if (empty($results)) {
                break;
            }

            $this->hydrateGameIdMapForIgdbIds($this->extractIgdbGameIdsFromReleaseDates($results));

            $batch = $this->buildGamePlatformsBatch($results);
            $batch = $this->dedupeGamePlatformsBatch($batch);

            if (!empty($batch)) {
                $parts = array_chunk($batch, self::IGDB_PAGE_SIZE);
                foreach ($parts as $part) {
                    try {
                        $this->upsertBatch('/game_platforms', $part, 'game_id,platform_id,region');
                        $stats['synced'] += count($part);
                    } catch (Throwable $e) {
                        $stats['failed'] += count($part);
                        $stats['errors'][] = "Batch game_platforms: " . $e->getMessage();
                        Logger::exception($e, 'IgdbSync::syncGamePlatformsInternal');

                        foreach ($part as $row) {
                            try {
                                $this->upsertBatch('/game_platforms', [$row], 'game_id,platform_id,region');
                                $stats['synced']++;
                            } catch (Throwable $e2) {
                                $stats['failed']++;
                                $stats['errors'][] = "game_platforms game_id={$row['game_id']} platform_id={$row['platform_id']} region={$row['region']}: " . $e2->getMessage();
                                Logger::exception($e2, 'IgdbSync::syncGamePlatformsInternal/row');
                            }
                        }
                    }
                }
            }

            $offset += $limit;
        } while (count($results) === $limit);

        Logger::info('IGDB sync: fin game_platforms', $stats + ['year' => $year]);
        return $stats;
    }

    private function buildGamePlatformsBatch(array $results): array
    {
        $batch = [];
        foreach ($results as $rd) {
            $igdbGameId     = is_array($rd['game'] ?? null)     ? ($rd['game']['id']     ?? null) : ($rd['game']     ?? null);
            $igdbPlatformId = is_array($rd['platform'] ?? null) ? ($rd['platform']['id'] ?? null) : ($rd['platform'] ?? null);
            $gameId         = $igdbGameId     ? ($this->gameIdMap[(int) $igdbGameId]         ?? null) : null;
            $platformId     = $igdbPlatformId ? ($this->platformIdMap[(int) $igdbPlatformId] ?? null) : null;

            if (!$gameId || !$platformId) {
                continue;
            }

            $batch[] = [
                'game_id'      => $gameId,
                'platform_id'  => $platformId,
                'region'       => $this->mapRegion($rd['region'] ?? null),
                'release_date' => isset($rd['date']) ? date('Y-m-d', (int) $rd['date']) : null,
            ];
        }
        return $batch;
    }

    private function dedupeGamePlatformsBatch(array $batch): array
    {
        $merged = [];
        foreach ($batch as $row) {
            $key = $row['game_id'] . "\x1e" . $row['platform_id'] . "\x1e" . $row['region'];
            if (!isset($merged[$key])) {
                $merged[$key] = $row;
                continue;
            }
            $da   = $merged[$key]['release_date'] ?? null;
            $db   = $row['release_date']           ?? null;
            $hasA = $da !== null && $da !== '';
            $hasB = $db !== null && $db !== '';
            if (!$hasA && $hasB) {
                $merged[$key] = $row;
            } elseif ($hasA && $hasB && strcmp((string) $db, (string) $da) >= 0) {
                $merged[$key] = $row;
            }
        }
        return array_values($merged);
    }

    private function extractIgdbGameIdsFromReleaseDates(array $results): array
    {
        $ids = [];
        foreach ($results as $rd) {
            $id = is_array($rd['game'] ?? null) ? ($rd['game']['id'] ?? null) : ($rd['game'] ?? null);
            if ($id !== null && $id !== '') {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    private function hydrateGameIdMapForIgdbIds(array $igdbIds): void
    {
        $unique = [];
        foreach ($igdbIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($this->gameIdMap[$id])) {
                $unique[$id] = true;
            }
        }
        if ($unique === []) {
            return;
        }

        $chunks = array_chunk(array_keys($unique), self::GAME_ID_MAP_CHUNK);

        for ($w = 0; $w < count($chunks); $w += self::GAME_ID_MAP_CONCURRENCY) {
            $wave     = array_slice($chunks, $w, self::GAME_ID_MAP_CONCURRENCY);
            $promises = [];
            foreach ($wave as $chunk) {
                $inList     = implode(',', $chunk);
                $url        = "{$this->supabaseUrl}/rest/v1/games?select=id,igdb_id&igdb_id=in.({$inList})";
                $promises[] = $this->http->getAsync($url, ['headers' => $this->restHeaders()]);
            }

            $settled = PromiseUtils::settle($promises)->wait();
            foreach ($settled as $item) {
                if (($item['state'] ?? '') !== 'fulfilled') {
                    continue;
                }
                $data = json_decode((string) $item['value']->getBody(), true);
                foreach ((is_array($data) ? $data : []) as $row) {
                    if (isset($row['igdb_id'], $row['id'])) {
                        $this->gameIdMap[(int) $row['igdb_id']] = (int) $row['id'];
                    }
                }
            }
        }
    }

    // ──────────────────────────────────────────────
    //  Progression annuelle
    // ──────────────────────────────────────────────

    private function progressFile(): string
    {
        return $this->cacheDir . '/' . self::YEARS_PROGRESS_FILE;
    }

    public function loadYearsProgress(): array
    {
        $file = $this->progressFile();
        if (!is_file($file)) {
            return [];
        }
        $raw  = file_get_contents($file);
        $data = ($raw !== false) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private function saveYearsProgress(array $progress): void
    {
        file_put_contents(
            $this->progressFile(),
            json_encode($progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    public function resetYearsProgress(): void
    {
        $file = $this->progressFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function getYearsProgress(): array
    {
        return $this->loadYearsProgress();
    }

    // ──────────────────────────────────────────────
    //  Chargement des mappings en mémoire
    // ──────────────────────────────────────────────

    private function loadGameIdMap(): void
    {
        $this->gameIdMap = $this->loadIdMap('/games', 'igdb_id', 'id');
        Logger::debug('IGDB sync: gameIdMap chargé', ['count' => count($this->gameIdMap)]);
    }

    private function loadPlatformIdMap(): void
    {
        $this->platformIdMap = $this->loadIdMap('/platforms', 'igdb_id', 'id');
        Logger::debug('IGDB sync: platformIdMap chargé', ['count' => count($this->platformIdMap)]);
    }

    private function loadIdMap(string $table, string $keyField, string $valueField): array
    {
        $map    = [];
        $offset = 0;
        $limit  = 1000;

        do {
            $res  = $this->http->get(
                "{$this->supabaseUrl}/rest/v1{$table}?select={$keyField},{$valueField}&limit={$limit}&offset={$offset}",
                ['headers' => $this->restHeaders()]
            );
            $data = json_decode((string) $res->getBody(), true);
            $data = is_array($data) ? $data : [];

            foreach ($data as $row) {
                if (isset($row[$keyField], $row[$valueField])) {
                    $map[(int) $row[$keyField]] = (int) $row[$valueField];
                }
            }

            $offset += $limit;
        } while (count($data) === $limit);

        return $map;
    }

    // ──────────────────────────────────────────────
    //  Helpers Supabase (PostgREST)
    // ──────────────────────────────────────────────

    private function upsertBatch(string $path, array $rows, string $onConflict = ''): void
    {
        if (empty($rows)) {
            return;
        }

        $url = "{$this->supabaseUrl}/rest/v1{$path}";
        if ($onConflict !== '') {
            $url .= '?on_conflict=' . rawurlencode($onConflict);
        }

        $res    = $this->http->post($url, [
            'headers' => array_merge($this->restHeaders(), [
                'Prefer' => 'resolution=merge-duplicates,return=minimal',
            ]),
            'json' => $rows,
        ]);
        $status = $res->getStatusCode();

        if ($status >= 400) {
            throw new \RuntimeException(
                "Supabase batch upsert {$path} (" . count($rows) . " lignes) → HTTP {$status}: "
                . substr((string) $res->getBody(), 0, 300)
            );
        }
    }

    private function restHeaders(): array
    {
        return [
            'apikey'        => $this->serviceKey,
            'Authorization' => "Bearer {$this->serviceKey}",
            'Content-Type'  => 'application/json',
        ];
    }

    // ──────────────────────────────────────────────
    //  Helpers IGDB
    // ──────────────────────────────────────────────

    /**
     * Normalise une URL d'image IGDB vers HTTPS + taille demandée.
     * Format entrant : "//images.igdb.com/igdb/image/upload/t_thumb/co1234.jpg"
     */
    private function formatImageUrl(?string $url, string $size = 'cover_big'): ?string
    {
        if (!$url) {
            return null;
        }
        $url = preg_replace('~/t_[a-z_]+/~', "/t_{$size}/", $url) ?? $url;
        return str_starts_with($url, '//') ? 'https:' . $url : $url;
    }

    /**
     * Calcule un timestamp Unix, compatible dates antérieures à 1970.
     */
    private function yearToTimestamp(int $year, int $month, int $day): int
    {
        if ($year >= 1970) {
            return mktime(0, 0, 0, $month, $day, $year);
        }
        $diffYears = 1970 - $year;
        return (int) round(-$diffYears * 365.2425 * 86400);
    }

    /**
     * Convertit le code région IGDB en valeur ENUM PostgreSQL `game_region`.
     * Codes IGDB : 1=Europe, 2=North America, 3=Australia, 4=New Zealand,
     *              5=Japan, 6=China, 7=Asia, 8=Worldwide, 9=Korea, 10=Brazil
     */
    private function mapRegion(?int $region): string
    {
        return match ($region) {
            1       => 'PAL',
            3, 4    => 'PAL',
            2       => 'NTSC-U',
            5       => 'NTSC-J',
            9       => 'NTSC-K',
            default => 'other',
        };
    }

    private function extractFeatureTitle(array $features): string
    {
        foreach ($features as $f) {
            if (!empty($f['title'])) {
                return (string) $f['title'];
            }
        }
        return 'Édition Standard';
    }

    private function extractFeatureDescription(array $features): ?string
    {
        $parts = [];
        foreach ($features as $f) {
            if (!empty($f['description'])) {
                $parts[] = (string) $f['description'];
            }
        }
        return $parts ? implode("\n", $parts) : null;
    }

    public function getIgdbClient(): IgdbClient
    {
        return $this->igdb;
    }
}
