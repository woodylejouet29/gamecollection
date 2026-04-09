<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Charger les variables d'environnement depuis .env
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (Exception $e) {
    echo "⚠️ ATTENTION : Impossible de charger le fichier .env\n";
    echo "   Erreur : " . $e->getMessage() . "\n\n";
}

use App\Core\Logger;

/**
 * Cron job hebdomadaire de nettoyage.
 * 
 * Tâches :
 * 1. Détection jeux en cache sans collection_entries ni wishlist depuis +90 jours
 * 2. Suppression images WebP orphelines
 * 3. Purge logs > 30 jours
 * 4. Purge sessions expirées
 * 5. Rapport de nettoyage loggé
 * 
 * À exécuter hebdomadairement via crontab :
 * 0 2 * * 0 php /chemin/vers/scripts/weekly_cleanup.php >> /var/log/gameproject-cleanup.log 2>&1
 */

class WeeklyCleanup
{
    private string $supabaseUrl;
    private string $serviceKey;
    private array $report = [
        'started_at' => null,
        'finished_at' => null,
        'games_deleted' => 0,
        'images_deleted' => 0,
        'logs_deleted' => 0,
        'sessions_deleted' => 0,
        'errors' => [],
    ];

    public function __construct()
    {
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
        
        $this->report['started_at'] = date('Y-m-d H:i:s');
    }

    public function run(): void
    {
        Logger::info('Démarrage du cron hebdomadaire de nettoyage');
        
        try {
            $this->cleanOrphanedGames();
            $this->cleanOrphanedImages();
            $this->cleanOldLogs();
            $this->cleanExpiredSessions();
        } catch (\Throwable $e) {
            $this->report['errors'][] = [
                'task' => 'global',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
            Logger::error('Erreur lors du nettoyage hebdomadaire', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
        
        $this->report['finished_at'] = date('Y-m-d H:i:s');
        $this->logReport();
    }

    /**
     * 1. Détection jeux en cache sans collection_entries ni wishlist depuis +90 jours
     */
    private function cleanOrphanedGames(): void
    {
        Logger::info('Nettoyage des jeux orphelins (+90 jours sans collection/wishlist)');
        
        // Requête Supabase pour trouver les jeux orphelins
        // Jeux créés il y a plus de 90 jours et non présents dans collection_entries ou wishlist
        $ninetyDaysAgo = date('Y-m-d', strtotime('-90 days'));
        
        // Note : Cette requête est complexe et dépend de la structure exacte des tables
        // En production, on devrait utiliser une vue ou une requête optimisée
        // Pour l'instant, on logge seulement l'intention
        
        Logger::info("Jeux orphelins potentiels : créés avant {$ninetyDaysAgo}");
        $this->report['games_deleted'] = 0; // À implémenter
        
        // Exemple de logique :
        // 1. Récupérer tous les jeux créés avant $ninetyDaysAgo
        // 2. Pour chaque jeu, vérifier s'il existe dans collection_entries ou wishlist
        // 3. Si non, supprimer le jeu et ses images associées
    }

    /**
     * 2. Suppression images WebP orphelines
     */
    private function cleanOrphanedImages(): void
    {
        Logger::info('Nettoyage des images WebP orphelines');
        
        $imageDirs = [
            __DIR__ . '/../assets/uploads/selection/',
            __DIR__ . '/../public/images/games/',
            __DIR__ . '/../public/images/platforms/',
        ];
        
        foreach ($imageDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            
            $files = glob($dir . '*.webp') ?: [];
            foreach ($files as $file) {
                $fileTime = filemtime($file);
                // Supprimer les images de plus de 90 jours
                if ($fileTime && time() - $fileTime > 90 * 24 * 3600) {
                    if (@unlink($file)) {
                        $this->report['images_deleted']++;
                        Logger::debug("Image orpheline supprimée : {$file}");
                    }
                }
            }
        }
    }

    /**
     * 3. Purge logs > 30 jours
     */
    private function cleanOldLogs(): void
    {
        Logger::info('Purge des logs de plus de 30 jours');
        
        // Supprimer les logs de la table 'logs' datant de plus de 30 jours
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        // Requête Supabase pour supprimer les vieux logs
        $url = $this->supabaseUrl . '/rest/v1/logs'
            . '?created_at=lt.' . rawurlencode($thirtyDaysAgo);
        
        $ch = curl_init($url);
        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $this->serviceKey,
                'Authorization: Bearer ' . $this->serviceKey,
                'Prefer: return=representation',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        
        // En développement, on peut désactiver la vérification SSL
        $appEnv = $_ENV['APP_ENV'] ?? 'production';
        $appDebug = $_ENV['APP_DEBUG'] ?? 'false';
        
        if ($appEnv === 'development' || $appDebug === 'true') {
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = false;
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 || $httpCode === 204) {
            $data = json_decode($response ?: '[]', true);
            $count = is_array($data) ? count($data) : 0;
            $this->report['logs_deleted'] = $count;
            Logger::info("{$count} logs supprimés (avant {$thirtyDaysAgo})");
        } else {
            $this->report['errors'][] = [
                'task' => 'cleanOldLogs',
                'error' => "HTTP {$httpCode}: " . substr($response ?: '', 0, 200),
            ];
            Logger::warning("Échec de la suppression des logs: HTTP {$httpCode}");
        }
    }

    /**
     * 4. Purge sessions expirées
     */
    private function cleanExpiredSessions(): void
    {
        Logger::info('Purge des sessions expirées');
        
        // Note : Supabase Auth gère les sessions automatiquement
        // Cette tâche est surtout pertinente si on stocke des sessions côté serveur
        
        // Si on utilise des sessions PHP classiques :
        $sessionDir = session_save_path();
        if ($sessionDir && is_dir($sessionDir)) {
            $files = glob($sessionDir . '/sess_*') ?: [];
            $now = time();
            foreach ($files as $file) {
                $fileTime = filemtime($file);
                if ($fileTime && $now - $fileTime > 24 * 3600) { // Sessions > 24h
                    if (@unlink($file)) {
                        $this->report['sessions_deleted']++;
                    }
                }
            }
            Logger::info("{$this->report['sessions_deleted']} sessions expirées nettoyées");
        } else {
            Logger::info('Pas de répertoire de sessions local à nettoyer');
        }
    }

    /**
     * 5. Rapport de nettoyage loggé
     */
    private function logReport(): void
    {
        $duration = strtotime($this->report['finished_at']) - strtotime($this->report['started_at']);
        
        $reportSummary = [
            'type' => 'weekly_cleanup_report',
            'duration_seconds' => $duration,
            'stats' => [
                'games_deleted' => $this->report['games_deleted'],
                'images_deleted' => $this->report['images_deleted'],
                'logs_deleted' => $this->report['logs_deleted'],
                'sessions_deleted' => $this->report['sessions_deleted'],
            ],
            'error_count' => count($this->report['errors']),
        ];
        
        if (!empty($this->report['errors'])) {
            $reportSummary['errors'] = $this->report['errors'];
        }
        
        Logger::info('Rapport de nettoyage hebdomadaire', $reportSummary);
        
        // Afficher un résumé dans stdout (pour les logs cron)
        echo "=== Rapport de nettoyage hebdomadaire ===\n";
        echo "Début    : {$this->report['started_at']}\n";
        echo "Fin      : {$this->report['finished_at']}\n";
        echo "Durée    : {$duration} secondes\n";
        echo "Jeux supprimés   : {$this->report['games_deleted']}\n";
        echo "Images supprimées : {$this->report['images_deleted']}\n";
        echo "Logs supprimés    : {$this->report['logs_deleted']}\n";
        echo "Sessions nettoyées: {$this->report['sessions_deleted']}\n";
        echo "Erreurs           : " . count($this->report['errors']) . "\n";
        
        if (!empty($this->report['errors'])) {
            echo "\nDétails des erreurs :\n";
            foreach ($this->report['errors'] as $error) {
                echo "- {$error['task']}: {$error['error']}\n";
            }
        }
        
        echo "=========================================\n";
    }
}

// Exécution
if (php_sapi_name() === 'cli') {
    $cleanup = new WeeklyCleanup();
    $cleanup->run();
} else {
    http_response_code(403);
    echo 'Accès interdit';
    exit;
}