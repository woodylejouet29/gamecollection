#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backfill ciblé : remplace les cover_url locaux par des URLs IGDB.
 *
 * Objectif : corriger les lignes games.cover_url du type "/storage/images/igdb/..."
 * sans resynchroniser tout le catalogue.
 *
 * Pré-requis .env :
 *  - SUPABASE_URL
 *  - SUPABASE_SERVICE_ROLE_KEY
 *  - IGDB_CLIENT_ID
 *  - IGDB_CLIENT_SECRET
 *
 * Usage :
 *  php scripts/igdb_backfill_local_covers.php
 *  php scripts/igdb_backfill_local_covers.php --dry-run
 *  php scripts/igdb_backfill_local_covers.php --limit=2000
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\IgdbClient;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

$supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
$serviceKey  = (string) ($_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '');

if ($supabaseUrl === '' || $serviceKey === '') {
    fwrite(STDERR, "SUPABASE_URL / SUPABASE_SERVICE_ROLE_KEY manquants dans .env\n");
    exit(1);
}

$rawArgs = array_slice($argv ?? [], 1);
$dryRun  = in_array('--dry-run', $rawArgs, true);
$limit   = null;
foreach ($rawArgs as $a) {
    if (str_starts_with($a, '--limit=')) {
        $limit = max(1, (int) substr($a, 8));
    }
}

function ts(): string { return date('Y-m-d H:i:s'); }

function supabaseHeaders(string $serviceKey): array
{
    return [
        'apikey: ' . $serviceKey,
        'Authorization: Bearer ' . $serviceKey,
        'Content-Type: application/json',
    ];
}

/**
 * GET JSON via curl.
 * @return array{0:int,1:string} [httpCode, body]
 */
function curlGet(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

/**
 * POST JSON via curl.
 * @return array{0:int,1:string} [httpCode, body]
 */
function curlPost(string $url, array $headers, string $json): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => $json,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function normalizeIgdbUrl(?string $url, string $size = 'cover_big'): ?string
{
    if (!$url) return null;
    $u = preg_replace('~/t_[a-z_]+/~', "/t_{$size}/", $url) ?? $url;
    if (str_starts_with($u, '//')) return 'https:' . $u;
    return $u;
}

/**
 * Récupère un igdb_id min/max via une requête indexée.
 */
function fetchIgdbIdEdge(string $supabaseUrl, array $headers, string $dir): int
{
    $dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';
    $url = $supabaseUrl . '/rest/v1/games'
        . '?select=igdb_id'
        . '&igdb_id=not.is.null'
        . '&order=igdb_id.' . $dir
        . '&limit=1';

    [$code, $body] = curlGet($url, $headers);
    if ($code >= 400) {
        fwrite(STDERR, "[" . ts() . "] Erreur Supabase GET edge igdb_id: HTTP {$code}: " . substr($body, 0, 300) . "\n");
        exit(1);
    }
    $rows = json_decode($body ?: '[]', true);
    if (!is_array($rows) || empty($rows[0]['igdb_id'])) {
        return 0;
    }
    return (int) $rows[0]['igdb_id'];
}

// 1) Lister les jeux à corriger (id + igdb_id + champs NOT NULL) — sans LIKE côté Supabase (évite timeouts)
$headers = supabaseHeaders($serviceKey);
$targets  = []; // list<array{id:int, igdb_id:int, title:string, slug:string}>

echo "[" . ts() . "] Scan Supabase: games.cover_url locaux…\n";

$minId = fetchIgdbIdEdge($supabaseUrl, $headers, 'asc');
$maxId = fetchIgdbIdEdge($supabaseUrl, $headers, 'desc');

if ($minId <= 0 || $maxId <= 0 || $maxId < $minId) {
    echo "[" . ts() . "] Aucun jeu trouvé (igdb_id invalide).\n";
    exit(0);
}

// Tranches d'igdb_id : requête rapide (index), filtrage du cover_url côté PHP.
// Ajustable si besoin : plus gros = moins d'appels, plus lourd en réponse JSON.
$windowSize = 20000;
// NB: PostgREST peut capper la taille effective (souvent 1000).
// On pagine donc sur la taille réellement renvoyée, pas sur la valeur demandée.
$pageSize   = 5000;

for ($start = $minId; $start <= $maxId; $start += $windowSize) {
    $end = min($maxId + 1, $start + $windowSize);
    $offset = 0;

    while (true) {
        $url = $supabaseUrl . '/rest/v1/games'
            . '?select=id,igdb_id,title,slug,cover_url'
            . '&igdb_id=gte.' . $start
            . '&igdb_id=lt.' . $end
            . '&order=igdb_id.asc'
            . '&limit=' . $pageSize
            . '&offset=' . $offset;

        [$code, $body] = curlGet($url, $headers);
        if ($code >= 400) {
            fwrite(STDERR, "[" . ts() . "] Erreur Supabase GET games tranche {$start}-{$end}: HTTP {$code}: " . substr($body, 0, 300) . "\n");
            exit(1);
        }

        $rows = json_decode($body ?: '[]', true);
        if (!is_array($rows) || $rows === []) {
            break;
        }

        foreach ($rows as $r) {
            $rowId = (int) ($r['id'] ?? 0);
            $id  = (int) ($r['igdb_id'] ?? 0);
            $title = (string) ($r['title'] ?? '');
            $slug  = (string) ($r['slug'] ?? '');
            $cov = (string) ($r['cover_url'] ?? '');
            $isLocal = ($cov !== '')
                && (str_starts_with($cov, '/storage/images/igdb/') || str_starts_with($cov, 'storage/images/igdb/'));
            if ($id > 0 && $isLocal) {
                if ($rowId > 0) {
                    // Inclure les champs NOT NULL pour que l'UPSERT ne viole pas les contraintes
                    // lors de la phase INSERT (même si un conflit `id` est attendu).
                    $targets[] = [
                        'id'      => $rowId,
                        'igdb_id' => $id,
                        'title'   => $title,
                        'slug'    => $slug,
                    ];
                }
                if ($limit !== null && count($targets) >= $limit) {
                    break 3;
                }
            }
        }

        // Important : PostgREST peut renvoyer moins que `limit` demandé même si la tranche
        // n'est pas terminée. On avance donc selon la taille réelle renvoyée.
        $offset += count($rows);
        if (count($rows) < 1) {
            break;
        }
    }

    echo "[" . ts() . "]   scanné igdb_id {$start} → " . ($end - 1) . " | trouvés: " . count($targets) . "\n";
}

if ($targets === []) {
    echo "[" . ts() . "] Aucun cover_url local à corriger.\n";
    exit(0);
}

echo "[" . ts() . "] Total à corriger: " . count($targets) . ($dryRun ? " (dry-run)\n" : "\n");

// 2) Requête IGDB par lots, puis upsert cover_url
$igdb = new IgdbClient();

$chunkSize = 500; // safe upper bound for IGDB limit
$updatedTotal = 0;

for ($i = 0; $i < count($targets); $i += $chunkSize) {
    $chunk = array_slice($targets, $i, $chunkSize);
    $igdbIds = array_values(array_unique(array_map(static fn($t) => (int) ($t['igdb_id'] ?? 0), $chunk)));
    $igdbIds = array_values(array_filter($igdbIds, static fn($n) => $n > 0));
    $inList = implode(',', $igdbIds);

    $body = implode(' ', [
        'fields id, cover.url;',
        "where id = ({$inList});",
        'limit ' . count($igdbIds) . ';',
    ]);

    $games = $igdb->query('games', $body, 'backfill/local-covers/off' . $i);
    if (!is_array($games)) $games = [];

    $rowIdByIgdbId = [];
    foreach ($chunk as $t) {
        $gid = (int) ($t['igdb_id'] ?? 0);
        $rid = (int) ($t['id'] ?? 0);
        $title = (string) ($t['title'] ?? '');
        $slug  = (string) ($t['slug'] ?? '');
        if ($gid > 0 && $rid > 0 && $title !== '' && $slug !== '') {
            $rowIdByIgdbId[$gid] = [
                'id'      => $rid,
                'igdb_id' => $gid,
                'title'   => $title,
                'slug'    => $slug,
            ];
        }
    }

    $coverByIgdbId = [];
    foreach ($games as $g) {
        $gid = (int) ($g['id'] ?? 0);
        if ($gid <= 0) continue;
        $coverByIgdbId[$gid] = normalizeIgdbUrl($g['cover']['url'] ?? null, 'cover_big');
    }

    $payload = [];
    // Construire un update pour chaque ligne cible :
    // - cover IGDB si disponible
    // - sinon NULL (pour éliminer tout chemin local)
    foreach ($chunk as $t) {
        $gid = (int) ($t['igdb_id'] ?? 0);
        if ($gid <= 0) continue;
        $row = $rowIdByIgdbId[$gid] ?? null;
        if (!is_array($row) || empty($row['id'])) continue;
        $cover = $coverByIgdbId[$gid] ?? null;
        $payload[] = [
            'id'        => (int) $row['id'],
            'igdb_id'   => (int) $row['igdb_id'],
            'title'     => (string) $row['title'],
            'slug'      => (string) $row['slug'],
            'cover_url' => $cover,
        ];
    }

    echo "[" . ts() . "] Lot " . (int)($i / $chunkSize + 1) . " : IGDB=" . count($igdbIds) . " → updates=" . count($payload) . "\n";

    if ($payload === [] || $dryRun) {
        continue;
    }

    // Upsert par clé primaire `id` : évite tout insert accidentel si `igdb_id` n'a pas
    // de contrainte unique en prod (config variable selon migrations).
    $upsertUrl = $supabaseUrl . '/rest/v1/games?on_conflict=id';
    $upsertHeaders = array_merge($headers, [
        'Prefer: resolution=merge-duplicates,return=minimal',
    ]);

    [$code, $resp] = curlPost($upsertUrl, $upsertHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
    if ($code >= 400) {
        fwrite(STDERR, "[" . ts() . "] Erreur Supabase upsert: HTTP {$code}: " . substr($resp, 0, 400) . "\n");
        exit(1);
    }

    $updatedTotal += count($payload);
}

echo "[" . ts() . "] Terminé. Lignes mises à jour: {$updatedTotal}\n";

