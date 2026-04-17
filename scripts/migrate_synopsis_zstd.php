#!/usr/bin/env php
<?php

/**
 * Backfill : synopsis TEXT → synopsis_zstd (zstd) + synopsis NULL pour gagner de la place.
 *
 * Prérequis :
 *   - Migration SQL `database/012_games_synopsis_zstd.sql` appliquée sur Supabase
 *   - Extension PHP zstd (PECL) : `php -m` doit lister `zstd`
 *   - Variables .env : SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY
 *
 * Usage :
 *   php scripts/migrate_synopsis_zstd.php --dry-run
 *   php scripts/migrate_synopsis_zstd.php
 *
 * Options :
 *   --dry-run         Estimation du nombre de lignes (GET + Prefer: count=exact ; peut timeout)
 *   --skip-count      Avec --dry-run : pas de requête de comptage
 *   --limit=N          Taille d’un lot GET initiale (défaut 25 ; descendre à 10 si timeout malgré l’index)
 *   --concurrency=N    PATCH en parallèle (défaut 8 ; baisser si 502 Cloudflare)
 *   --retries=N        Tentatives GET simples (dry-run ; défaut 6)
 *   --get-attempts=N   Tentatives max. par page en mode adaptatif (défaut 15)
 *   --patch-retries=N Tentatives par sous-lot PATCH (502/503/504/429…) (défaut 10)
 *   --throttle-ms=N    Pause après chaque sous-lot PATCH réussi (défaut 40 ms)
 *
 * Pagination : `id=gt.{cursor}` + order=id.asc (évite de rescanner depuis le début de la table).
 * Retries GET : 500 / 57014 / statement timeout → baisse automatique du limit (÷2, min. 5) puis retry.
 * Recommandé : appliquer database/013_games_synopsis_zstd_backfill_index.sql sur Supabase avant ce script.
 * Retries PATCH : 408, 429, 500–504, 520, 522, 524 + erreurs réseau → même sous-lot rejoué.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Services\ZstdSynopsis;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Psr\Http\Message\ResponseInterface;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

$dryRun         = in_array('--dry-run', $argv, true);
$skipCount      = in_array('--skip-count', $argv, true);
$limit          = 25;
$concurrency    = 8;
$maxRetries      = 6;
$maxGetAttempts  = 15;
$maxPatchRetries = 10;
$throttleMs      = 40;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
    if (preg_match('/^--concurrency=(\d+)$/', $arg, $m)) {
        $concurrency = max(1, (int) $m[1]);
    }
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $concurrency = max(1, (int) $m[1]);
    }
    if (preg_match('/^--retries=(\d+)$/', $arg, $m)) {
        $maxRetries = max(1, (int) $m[1]);
    }
    if (preg_match('/^--get-attempts=(\d+)$/', $arg, $m)) {
        $maxGetAttempts = max(1, (int) $m[1]);
    }
    if (preg_match('/^--patch-retries=(\d+)$/', $arg, $m)) {
        $maxPatchRetries = max(1, (int) $m[1]);
    }
    if (preg_match('/^--throttle-ms=(\d+)$/', $arg, $m)) {
        $throttleMs = max(0, (int) $m[1]);
    }
}

if (!function_exists('zstd_compress')) {
    fwrite(STDERR, "Erreur : l’extension PHP `zstd` est requise (PECL). Installez-la puis relancez.\n");
    exit(1);
}

$url = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
$key = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
if ($url === '' || $key === '') {
    fwrite(STDERR, "Erreur : SUPABASE_URL et SUPABASE_SERVICE_ROLE_KEY doivent être définis.\n");
    exit(1);
}

$http = new Client(['timeout' => 180, 'http_errors' => false]);
$headers = [
    'apikey'        => $key,
    'Authorization' => "Bearer {$key}",
    'Content-Type'  => 'application/json',
];

$basePath = '/rest/v1/games?synopsis=not.is.null&synopsis_zstd=is.null&select=id,synopsis';

function isStatementTimeout(ResponseInterface $res): bool
{
    if ($res->getStatusCode() !== 500) {
        return false;
    }
    $b = (string) $res->getBody();

    return str_contains($b, '57014')
        || str_contains($b, 'statement timeout')
        || str_contains($b, 'canceling statement');
}

/**
 * GET page courante ; sur statement timeout, divise `$fetchLimit` (min. 5) puis réessaie.
 */
function httpGetGamesPageAdaptive(
    Client $http,
    string $apiBaseUrl,
    string $basePath,
    int $lastId,
    array $headers,
    int $maxAttempts,
    int &$fetchLimit
): ResponseInterface {
    $fetchLimit = max(5, $fetchLimit);
    $last       = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $pageUrl = $apiBaseUrl . $basePath . '&id=gt.' . $lastId . '&order=id.asc&limit=' . $fetchLimit;
        $res     = $http->get($pageUrl, ['headers' => $headers]);
        $last    = $res;
        if ($res->getStatusCode() === 200) {
            return $res;
        }

        if (isStatementTimeout($res)) {
            if ($fetchLimit > 5) {
                $fetchLimit = max(5, (int) ($fetchLimit / 2));
                fwrite(STDERR, "GET statement timeout → limit={$fetchLimit}, retry ({$attempt}/{$maxAttempts})…\n");
                sleep(2);
                continue;
            }
            $sleep = min(45, 3 * $attempt * $attempt);
            fwrite(STDERR, "GET timeout (limit={$fetchLimit}), attente {$sleep}s ({$attempt}/{$maxAttempts})…\n");
            sleep($sleep);
            continue;
        }

        break;
    }

    return $last ?? throw new \RuntimeException('httpGetGamesPageAdaptive: aucune réponse');
}

/**
 * GET simple avec retries (comptage dry-run, etc.).
 */
function httpGetWithRetry(Client $http, string $fullUrl, array $headers, int $maxRetries): ResponseInterface
{
    $attempt = 0;
    $last    = null;
    while ($attempt < $maxRetries) {
        $attempt++;
        $res  = $http->get($fullUrl, ['headers' => $headers]);
        $last = $res;
        if ($res->getStatusCode() === 200) {
            return $res;
        }
        if (isStatementTimeout($res) && $attempt < $maxRetries) {
            $sleep = min(30, 2 * $attempt * $attempt);
            fwrite(STDERR, "GET timeout (tentative {$attempt}/{$maxRetries}), nouvel essai dans {$sleep}s…\n");
            sleep($sleep);
            continue;
        }
        break;
    }

    return $last ?? throw new \RuntimeException('httpGetWithRetry: aucune réponse');
}

function isRetryablePatchHttpCode(int $code): bool
{
    if ($code === 408 || $code === 429) {
        return true;
    }
    if ($code >= 500 && $code <= 504) {
        return true;
    }
    if ($code === 520 || $code === 522 || $code === 524) {
        return true;
    }

    return false;
}

function isRetryablePatchException(mixed $reason): bool
{
    if (!$reason instanceof Throwable) {
        return false;
    }
    $msg = strtolower($reason->getMessage());

    return str_contains($msg, 'connection')
        || str_contains($msg, 'reset')
        || str_contains($msg, 'timeout')
        || str_contains($msg, 'curl error');
}

/**
 * Exécute un sous-lot de PATCH en parallèle ; en cas d’erreur transitoire (502 Cloudflare, etc.), rejoue le même lot.
 *
 * @param list<array{id:int,synopsis_zstd:string}> $zstdSlice
 * @param list<int>                                $clearIds   synopsis '' → NULL
 */
function patchBatchWithRetry(
    Client $http,
    string $url,
    array $headers,
    array $zstdSlice,
    array $clearIds,
    int $maxPatchRetries,
    int $throttleMs
): void {
    $runZstd = static function (Client $http, string $url, array $headers, array $slice): array {
        $promises = [];
        foreach ($slice as $p) {
            $promises[] = $http->patchAsync(
                $url . '/rest/v1/games?id=eq.' . (int) $p['id'],
                [
                    'headers' => $headers,
                    'json'    => [
                        'synopsis'      => null,
                        'synopsis_zstd' => $p['synopsis_zstd'],
                    ],
                ]
            );
        }

        return PromiseUtils::settle($promises)->wait();
    };

    $runClear = static function (Client $http, string $url, array $headers, array $ids): array {
        $promises = [];
        foreach ($ids as $eid) {
            $promises[] = $http->patchAsync(
                $url . '/rest/v1/games?id=eq.' . (int) $eid,
                ['headers' => $headers, 'json' => ['synopsis' => null]]
            );
        }

        return PromiseUtils::settle($promises)->wait();
    };

    /** @return array{ok:bool, retry:bool, fatal:?string} */
    $analyzeSettled = static function (array $settled): array {
        foreach ($settled as $item) {
            if (($item['state'] ?? '') !== 'fulfilled') {
                $reason = $item['reason'] ?? null;
                if (isRetryablePatchException($reason)) {
                    return ['ok' => false, 'retry' => true, 'fatal' => null];
                }
                $msg = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;

                return ['ok' => false, 'retry' => false, 'fatal' => 'PATCH async failed: ' . $msg];
            }
            $code = $item['value']->getStatusCode();
            if ($code < 400) {
                continue;
            }
            if (isRetryablePatchHttpCode($code)) {
                return ['ok' => false, 'retry' => true, 'fatal' => null];
            }

            return [
                'ok'    => false,
                'retry' => false,
                'fatal' => 'PATCH failed: ' . $code . ' ' . substr((string) $item['value']->getBody(), 0, 400),
            ];
        }

        return ['ok' => true, 'retry' => false, 'fatal' => null];
    };

    if ($zstdSlice === [] && $clearIds === []) {
        return;
    }

    if ($zstdSlice !== []) {
        for ($attempt = 1; ; $attempt++) {
            $settled = $runZstd($http, $url, $headers, $zstdSlice);
            $st      = $analyzeSettled($settled);
            if ($st['ok']) {
                break;
            }
            if ($st['fatal'] !== null) {
                fwrite(STDERR, $st['fatal'] . "\n");
                exit(1);
            }
            if ($attempt >= $maxPatchRetries) {
                fwrite(STDERR, "PATCH zstd : abandon après {$maxPatchRetries} tentatives (502/503/504, etc.).\n");
                exit(1);
            }
            $sleep = min(90, 2 * $attempt * $attempt);
            fwrite(STDERR, "PATCH zstd : erreur transitoire, nouvel essai {$attempt}/{$maxPatchRetries} dans {$sleep}s…\n");
            sleep($sleep);
        }
        if ($throttleMs > 0) {
            usleep($throttleMs * 1000);
        }
    }

    if ($clearIds !== []) {
        for ($attempt = 1; ; $attempt++) {
            $settled = $runClear($http, $url, $headers, $clearIds);
            $st      = $analyzeSettled($settled);
            if ($st['ok']) {
                break;
            }
            if ($st['fatal'] !== null) {
                fwrite(STDERR, $st['fatal'] . "\n");
                exit(1);
            }
            if ($attempt >= $maxPatchRetries) {
                fwrite(STDERR, "PATCH synopsis vide : abandon après {$maxPatchRetries} tentatives.\n");
                exit(1);
            }
            $sleep = min(90, 2 * $attempt * $attempt);
            fwrite(STDERR, "PATCH synopsis vide : retry {$attempt}/{$maxPatchRetries} dans {$sleep}s…\n");
            sleep($sleep);
        }
        if ($throttleMs > 0) {
            usleep($throttleMs * 1000);
        }
    }
}

if ($dryRun) {
    if ($skipCount) {
        echo "Dry-run (--skip-count) : pas de comptage. Sans cette option, une estimation est faite via GET + Prefer: count=exact.\n";
        exit(0);
    }
    $countUrl = $url . $basePath . '&limit=1';
    $res      = httpGetWithRetry(
        $http,
        $countUrl,
        array_merge($headers, ['Prefer' => 'count=exact']),
        $maxRetries
    );
    if ($res->getStatusCode() !== 200) {
        fwrite(STDERR, 'Comptage impossible (HTTP ' . $res->getStatusCode() . '). Essayez --skip-count ou --limit=50 sur la migration réelle. Corps : '
            . substr((string) $res->getBody(), 0, 200) . "\n");
        exit(1);
    }
    $cr = $res->getHeaderLine('Content-Range');
    $total = 0;
    if (preg_match('/\/(\d+)$/', $cr, $m)) {
        $total = (int) $m[1];
    }
    echo "Lignes éligibles (estimation) : {$total}\n";
    exit(0);
}

$updated    = 0;
$cleared    = 0;
$lastId     = 0;
$fetchLimit = max(5, $limit);

while (true) {
    $res = httpGetGamesPageAdaptive($http, $url, $basePath, $lastId, $headers, $maxGetAttempts, $fetchLimit);
    if ($res->getStatusCode() !== 200) {
        fwrite(STDERR, 'GET games failed: ' . $res->getStatusCode() . ' ' . substr((string) $res->getBody(), 0, 400) . "\n"
            . "Astuce : exécutez database/013_games_synopsis_zstd_backfill_index.sql sur Supabase, puis relancez avec --limit=15\n");
        exit(1);
    }

    $rows = json_decode((string) $res->getBody(), true);
    if (!is_array($rows) || $rows === []) {
        break;
    }

    $maxRowId = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rid = (int) ($row['id'] ?? 0);
        if ($rid > $maxRowId) {
            $maxRowId = $rid;
        }
    }
    if ($maxRowId <= $lastId) {
        fwrite(STDERR, "Erreur : curseur id bloqué (lastId={$lastId}).\n");
        exit(1);
    }
    $lastId = $maxRowId;

    $payload  = [];
    $emptyIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id  = (int) ($row['id'] ?? 0);
        $syn = $row['synopsis'] ?? null;
        if ($id <= 0) {
            continue;
        }
        if (!is_string($syn) || $syn === '') {
            if (is_string($syn) && $syn === '') {
                $emptyIds[] = $id;
            }
            continue;
        }
        $hex = ZstdSynopsis::compressForSupabase($syn);
        if ($hex === null) {
            continue;
        }
        $payload[] = ['id' => $id, 'synopsis_zstd' => $hex];
    }

    for ($i = 0; $i < count($payload); $i += $concurrency) {
        $slice = array_slice($payload, $i, $concurrency);
        patchBatchWithRetry($http, $url, $headers, $slice, [], $maxPatchRetries, $throttleMs);
        $updated += count($slice);
        echo "Migrés (cumul) : {$updated}\n";
    }

    if ($emptyIds !== []) {
        for ($j = 0; $j < count($emptyIds); $j += $concurrency) {
            $eslice = array_slice($emptyIds, $j, $concurrency);
            patchBatchWithRetry($http, $url, $headers, [], $eslice, $maxPatchRetries, $throttleMs);
            $cleared += count($eslice);
        }
    }

    if ($payload === [] && $emptyIds === [] && count($rows) >= $fetchLimit) {
        fwrite(STDERR, "Avertissement : lot sans action ; curseur avancé à id={$lastId}. Si ça se répète en boucle, arrêtez (données inattendues).\n");
    }
}

echo "Terminé. Migrés (zstd) : {$updated}, synopsis '' → NULL : {$cleared}\n";
