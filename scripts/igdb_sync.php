#!/usr/bin/env php
<?php

/**
 * Script CLI de synchronisation IGDB → Supabase.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  ÉTAPES RECOMMANDÉES                                                    │
 * │                                                                         │
 * │  1. php scripts/igdb_sync.php --platforms                               │
 * │     → ~300 plateformes + logos WebP → Supabase  (quelques secondes)    │
 * │     → À faire EN PREMIER : les plateformes doivent exister avant        │
 * │       les liaisons jeu × plateforme.                                    │
 * │                                                                         │
 * │  2. php scripts/igdb_sync.php --year=2024                               │
 * │     → jeux de l'année + jaquettes WebP + game_platforms en une passe   │
 * │     → Toutes les données (dates de sortie, plateformes par région,      │
 * │       sociétés, notes…) sont récupérées en un seul appel IGDB.         │
 * │                                                                         │
 * │  3. php scripts/igdb_sync.php --games                                   │
 * │     → tous les jeux 1950 → currentYear+3 (plusieurs heures)            │
 * │     → Inclut automatiquement game_platforms pour chaque année           │
 * │     → Ajouter --no-images pour ne sync que les métadonnées              │
 * │                                                                         │
 * │  4. php scripts/igdb_sync.php --versions                                │
 * │     → éditions (GOTY, Deluxe, etc.)                                     │
 * │                                                                         │
 * │  Note : --game-platforms reste disponible pour le backfill              │
 * │  (ex. si de nouvelles plateformes sont ajoutées après une sync games).  │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * OPTIONS :
 *   --platforms          Synchronise plateformes + logos
 *   --games              Synchronise TOUS les jeux (1950 → currentYear+3)
 *   --year=YYYY          Synchronise une seule année (+ release_dates par défaut)
 *   --from-year=YYYY     Début de plage d'années (défaut: 1950)
 *   --to-year=YYYY       Fin de plage d'années   (défaut: currentYear+3)
 *   --versions           Synchronise game_versions (éditions)
 *   --game-platforms     Synchronise toutes les game_platforms
 *   --no-game-platforms  Avec --year: ne pas enchaîner les release_dates
 *   --all                Enchaîne: platforms + games + versions + game-platforms
 *   --no-images          Désactive le téléchargement WebP (métadonnées seules)
 *   --force              Resynchronise les années déjà validées
 *   --reset              Vide le cache IGDB + fichier de progression, puis quitte
 *   --status             Affiche la progression et quitte
 *
 * CRON (tous les jours à 3h00) :
 *   0 3 * * * php /var/www/gameproject/scripts/igdb_sync.php --games --versions \
 *             --game-platforms >> /var/log/igdb_sync.log 2>&1
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Services\IgdbSync;
use App\Core\Logger;

// ──────────────────────────────────────────────
//  Bootstrap
// ──────────────────────────────────────────────

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

// ──────────────────────────────────────────────
//  Parser les arguments
// ──────────────────────────────────────────────

$rawArgs = array_slice($argv ?? [], 1);

$opt = [
    'platforms'         => false,
    'games'             => false,
    'versions'          => false,
    'versions_year'     => null,
    'game_platforms'    => false,
    'no_game_platforms' => false,
    'all'               => false,
    'with_images'       => true,
    'force'             => false,
    'reset'             => false,
    'status'            => false,
    'year'              => null,
    'from_year'         => 1950,
    'to_year'           => (int) date('Y') + 3,
];

foreach ($rawArgs as $arg) {
    match (true) {
        $arg === '--platforms'         => $opt['platforms']         = true,
        $arg === '--games'             => $opt['games']             = true,
        $arg === '--versions'          => $opt['versions']          = true,
        $arg === '--game-platforms'    => $opt['game_platforms']    = true,
        $arg === '--no-game-platforms' => $opt['no_game_platforms'] = true,
        $arg === '--all'               => $opt['all']               = true,
        $arg === '--no-images'         => $opt['with_images']       = false,
        $arg === '--force'             => $opt['force']             = true,
        $arg === '--reset'             => $opt['reset']             = true,
        $arg === '--status'            => $opt['status']            = true,
        str_starts_with($arg, '--year=')      => $opt['year']      = (int) substr($arg, 7),
        str_starts_with($arg, '--versions-year=') => $opt['versions_year'] = (int) substr($arg, 16),
        str_starts_with($arg, '--from-year=') => $opt['from_year'] = (int) substr($arg, 12),
        str_starts_with($arg, '--to-year=')   => $opt['to_year']   = (int) substr($arg, 10),
        default => null,
    };
}

if ($opt['all']) {
    $opt['platforms'] = $opt['games'] = $opt['versions'] = $opt['game_platforms'] = true;
}

// ──────────────────────────────────────────────
//  Instanciation du service
// ──────────────────────────────────────────────

$sync = new IgdbSync();

// ──────────────────────────────────────────────
//  --status
// ──────────────────────────────────────────────

if ($opt['status']) {
    $progress = $sync->getYearsProgress();

    if (empty($progress)) {
        echo "Aucune synchronisation d'années trouvée.\n";
        exit(0);
    }

    $done = $partial = 0;
    foreach ($progress as $data) {
        $data['failed'] === 0 ? $done++ : $partial++;
    }

    $years   = array_keys($progress);
    $minYear = min($years);
    $maxYear = max($years);

    echo "══════════════════════════════════════\n";
    echo " Progression synchronisation IGDB\n";
    echo "══════════════════════════════════════\n";
    printf(" Plage couverte    : %d → %d\n", $minYear, $maxYear);
    printf(" Années terminées  : %d / %d\n", $done, count($progress));
    printf(" Années partielles : %d\n", $partial);
    echo "──────────────────────────────────────\n";

    $notDone = [];
    for ($y = $opt['from_year']; $y <= $opt['to_year']; $y++) {
        if (!isset($progress[$y])) {
            $notDone[] = $y;
        } elseif ($progress[$y]['failed'] > 0) {
            $notDone[] = "{$y}(partiel:{$progress[$y]['failed']} échecs)";
        }
    }
    if ($notDone) {
        echo " Manquantes/partielles :\n";
        foreach (array_chunk($notDone, 10) as $chunk) {
            echo "   " . implode(', ', $chunk) . "\n";
        }
    } else {
        echo " Toutes les années sont synchronisées.\n";
    }
    echo "══════════════════════════════════════\n";
    exit(0);
}

// ──────────────────────────────────────────────
//  --reset : vide le cache et la progression
// ──────────────────────────────────────────────

if ($opt['reset']) {
    $cacheDir = $_ENV['IGDB_CACHE_DIR'] ?? BASE_PATH . '/storage/cache/igdb';
    $realDir  = realpath($cacheDir) ?: $cacheDir;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    $deleted = 0;
    foreach ($files as $file) {
        if ($file->isFile()) {
            @unlink($file->getRealPath());
            $deleted++;
        } elseif ($file->isDir()) {
            @rmdir($file->getRealPath());
        }
    }

    $sync->resetYearsProgress();
    echo "[" . ts() . "] Cache vidé ({$deleted} fichiers supprimés) et progression réinitialisée.\n";
    exit(0);
}

// ──────────────────────────────────────────────
//  Vérification qu'au moins une action est demandée
// ──────────────────────────────────────────────

$hasAction = $opt['platforms'] || $opt['games'] || $opt['versions']
    || $opt['game_platforms'] || $opt['year'] !== null || $opt['versions_year'] !== null;

if (!$hasAction) {
    echo <<<USAGE
Usage : php scripts/igdb_sync.php [OPTIONS]

  --platforms            Plateformes + logos (~secondes)
  --games                Tous les jeux 1950→currentYear+3 (~heures)
  --year=YYYY            Une seule année (+ release_dates, sauf --no-game-platforms)
  --from-year=YYYY       Début de plage (défaut: 1950)
  --to-year=YYYY         Fin de plage   (défaut: currentYear+3)
  --versions             game_versions (~30 min)
  --versions-year=YYYY   game_versions pour les jeux de l'année (test rapide)
  --game-platforms       Toutes les game_platforms (après --games)
  --no-game-platforms    Avec --year : pas de sync release_dates
  --all                  Enchaîne tout
  --no-images            Désactive le téléchargement WebP
  --force                Resynchronise les années déjà validées
  --reset                Vide le cache + progression (sans sync)
  --status               Affiche la progression

USAGE;
    exit(1);
}

// ──────────────────────────────────────────────
//  Gestion du retry
// ──────────────────────────────────────────────

$retryFile  = BASE_PATH . '/storage/cache/igdb/sync_retry.json';
$maxRetries = (int)($_ENV['IGDB_SYNC_MAX_RETRIES'] ?? 24);

$retryState = loadRetry($retryFile);

if (!$opt['force'] && $retryState['attempts'] >= $maxRetries) {
    echo "[" . ts() . "] ABANDON — max retries atteint ({$maxRetries}). Utilisez --force.\n";
    Logger::critical("IGDB sync: abandon après {$maxRetries} tentatives", $retryState);
    exit(2);
}

// ──────────────────────────────────────────────
//  Exécution
// ──────────────────────────────────────────────

$globalSuccess = true;
$results       = [];

echo "\n[" . ts() . "] ══════════════════════════════════════════\n";
echo "[" . ts() . "] Synchronisation IGDB → Supabase\n";
echo "[" . ts() . "] Images WebP : " . ($opt['with_images'] ? 'OUI (→ Supabase Storage)' : 'NON (--no-images)') . "\n";
echo "[" . ts() . "] Tentative   : " . ($retryState['attempts'] + 1) . "/{$maxRetries}\n";
echo "[" . ts() . "] ══════════════════════════════════════════\n\n";

Logger::info('IGDB sync: démarrage', [
    'options' => array_filter($opt, fn($v) => $v !== false && $v !== null),
    'attempt' => $retryState['attempts'] + 1,
]);

// ── Plateformes ──────────────────────────────
if ($opt['platforms']) {
    echo "[" . ts() . "] ▶ PLATEFORMES & LOGOS\n";
    $r                    = $sync->syncPlatforms($opt['with_images']);
    $results['platforms'] = $r;
    printStats('platforms', $r);
    if ($r['failed'] > 0) {
        $globalSuccess = false;
    }
    echo "\n";
}

// ── Jeux — année précise ─────────────────────
if ($opt['year'] !== null) {
    $year          = (int) $opt['year'];
    $withPlatforms = !$opt['no_game_platforms'];

    echo "[" . ts() . "] ▶ JEUX — année {$year}";
    echo $withPlatforms ? " (+ game_platforms intégré)\n" : " (sans game_platforms)\n";

    $r = $sync->syncGamesByYear(
        $year,
        $opt['with_images'],
        $withPlatforms,
        function (int $batchNum, int|string $totalBatches, int $synced, int $total, int $y): void {
            $pct   = $total > 0 ? sprintf('%5.1f%%', $synced / $total * 100) : '  ?  ';
            $total = $total > 0 ? number_format($total, 0, ',', ' ') : '?';
            printf("[%s]   Lot %3d/%-3s — %s / %s  (%s)\n",
                ts(), $batchNum, $totalBatches, number_format($synced, 0, ',', ' '), $total, $pct);
        }
    );
    $results["games_{$year}"] = $r;
    printStats("games {$year}", $r);
    if ($r['failed'] > 0) {
        $globalSuccess = false;
    }
    echo "\n";
}

// ── Jeux — toutes les années ─────────────────
if ($opt['games'] && $opt['year'] === null) {
    $from = $opt['from_year'];
    $to   = $opt['to_year'];

    echo "[" . ts() . "] ▶ JEUX — {$from} → {$to}" . ($opt['force'] ? ' (--force)' : '') . "\n";
    echo "[" . ts() . "]   Images WebP : " . ($opt['with_images'] ? 'oui' : 'non') . "\n\n";

    $batchCb = function (int $batchNum, int|string $totalBatches, int $synced, int $total, int $year): void {
        $pct   = $total > 0 ? sprintf('%5.1f%%', $synced / $total * 100) : '  ?  ';
        $total = $total > 0 ? number_format($total, 0, ',', ' ') : '?';
        printf("[%s]   %d · Lot %3d/%-3s — %s / %s  (%s)\n",
            ts(), $year, $batchNum, $totalBatches, number_format($synced, 0, ',', ' '), $total, $pct);
    };

    $yearCb = function (int $year, array $stats) use (&$globalSuccess): void {
        $icon = $stats['failed'] === 0 ? '✓' : '⚠';
        printf("[%s]   %s %d terminé : %s jeux, %d game_platforms, %d échecs\n",
            ts(), $icon, $year,
            number_format($stats['synced'], 0, ',', ' '),
            $stats['game_platforms'] ?? 0,
            $stats['failed']
        );
        if ($stats['failed'] > 0) {
            $globalSuccess = false;
        }
    };

    $totals          = $sync->syncAllYears($from, $to, $opt['with_images'], $opt['force'], $yearCb, $batchCb, !$opt['no_game_platforms']);
    $results['games'] = $totals;
    echo "\n";
    printf("[%s]   Total : %d sync, %d échecs, %d game_platforms, %d années traitées, %d sautées\n",
        ts(), $totals['synced'], $totals['failed'], $totals['game_platforms'] ?? 0, $totals['years_done'], $totals['years_skipped']);
    echo "\n";
}

// ── Versions ─────────────────────────────────
if ($opt['versions_year'] !== null) {
    $vy = (int) $opt['versions_year'];
    echo "[" . ts() . "] ▶ VERSIONS DE JEUX — année {$vy}\n";
    $r                             = $sync->syncGameVersionsForYear($vy);
    $results["game_versions_{$vy}"] = $r;
    printStats("game_versions {$vy}", $r);
    if ($r['failed'] > 0) {
        $globalSuccess = false;
    }
    echo "\n";
} elseif ($opt['versions']) {
    echo "[" . ts() . "] ▶ VERSIONS DE JEUX\n";
    $r                        = $sync->syncGameVersions();
    $results['game_versions'] = $r;
    printStats('game_versions', $r);
    if ($r['failed'] > 0) {
        $globalSuccess = false;
    }
    echo "\n";
}

// ── Jeux × Plateformes (parcours global) ─────
if ($opt['game_platforms'] && $opt['year'] === null) {
    echo "[" . ts() . "] ▶ JEUX × PLATEFORMES (release_dates, tout IGDB)\n";
    $r                         = $sync->syncGamePlatforms();
    $results['game_platforms'] = $r;
    printStats('game_platforms', $r);
    if ($r['failed'] > 0) {
        $globalSuccess = false;
    }
    echo "\n";
}

// ──────────────────────────────────────────────
//  Mise à jour retry + rapport final
// ──────────────────────────────────────────────

$retryState['last_run'] = date('c');

if ($globalSuccess) {
    $retryState['attempts']     = 0;
    $retryState['last_success'] = date('c');
    $retryState['last_error']   = null;

    echo "[" . ts() . "] ══════════════════════════════════════════\n";
    echo "[" . ts() . "] Synchronisation complète réussie\n";
    echo "[" . ts() . "] ══════════════════════════════════════════\n";

    Logger::info('IGDB sync: succès', $results);
    $exitCode = 0;
} else {
    $retryState['attempts']++;
    $retryState['last_error'] = date('c');
    $remaining = $maxRetries - $retryState['attempts'];

    echo "[" . ts() . "] ══════════════════════════════════════════\n";
    if ($retryState['attempts'] >= $maxRetries) {
        echo "[" . ts() . "] ÉCHEC — max retries atteint ({$maxRetries})\n";
        Logger::critical('IGDB sync: échec définitif', $results);
        $exitCode = 2;
    } else {
        echo "[" . ts() . "] Sync partielle — tentative {$retryState['attempts']}/{$maxRetries} ({$remaining} restantes)\n";
        Logger::warning('IGDB sync: échec partiel', [
            'attempt' => $retryState['attempts'],
            'results' => $results,
        ]);
        $exitCode = 1;
    }
    echo "[" . ts() . "] ══════════════════════════════════════════\n";
}

saveRetry($retryFile, $retryState);

echo "[" . ts() . "] Fin\n\n";
exit($exitCode);

// ──────────────────────────────────────────────
//  Helpers
// ──────────────────────────────────────────────

function ts(): string
{
    return date('Y-m-d H:i:s');
}

function printStats(string $label, array $r): void
{
    $synced = $r['synced'] ?? 0;
    $failed = $r['failed'] ?? 0;
    $errors = $r['errors'] ?? [];

    printf("[%s]   → %d synchronisés, %d échecs\n", ts(), $synced, $failed);

    foreach (array_slice($errors, 0, 5) as $err) {
        printf("[%s]   ✗ %s\n", ts(), $err);
    }
    if (count($errors) > 5) {
        printf("[%s]   … et %d autre(s) erreur(s) dans les logs.\n", ts(), count($errors) - 5);
    }
}

function loadRetry(string $file): array
{
    $defaults = ['attempts' => 0, 'last_success' => null, 'last_error' => null, 'last_run' => null];
    if (!is_file($file)) {
        return $defaults;
    }
    $raw  = file_get_contents($file);
    $data = ($raw !== false) ? json_decode($raw, true) : null;
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

function saveRetry(string $file, array $state): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
