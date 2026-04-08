<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Logger centralisé — JSON structuré → table `logs` via l'API REST Supabase.
 * Repli automatique sur fichier si l'API est indisponible ou non configurée.
 * Utilise cURL natif pour éviter toute dépendance externe.
 */
class Logger
{
    public const DEBUG    = 'debug';
    public const INFO     = 'info';
    public const WARNING  = 'warning';
    public const ERROR    = 'error';
    public const CRITICAL = 'critical';

    private static array $levels = [
        self::DEBUG    => 0,
        self::INFO     => 1,
        self::WARNING  => 2,
        self::ERROR    => 3,
        self::CRITICAL => 4,
    ];

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    public static function exception(Throwable $e, string $prefix = '', array $context = []): void
    {
        $context['exception'] = [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'code'    => $e->getCode(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ];
        self::log(self::ERROR, ($prefix ? $prefix . ' — ' : '') . $e->getMessage(), $context);
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $minLevel = $_ENV['LOG_LEVEL'] ?? self::DEBUG;
        if ((self::$levels[$level] ?? 0) < (self::$levels[$minLevel] ?? 0)) {
            return;
        }

        $entry = [
            'level'      => $level,
            'message'    => $message,
            'context'    => $context,
            'created_at' => date('c'),
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'method'     => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri'        => $_SERVER['REQUEST_URI'] ?? null,
        ];

        $sentToApi = self::writeToApi($level, $message, $context);

        // Double écriture fichier si activée, ou si l'API a échoué
        if (!$sentToApi || ($_ENV['LOG_TO_FILE'] ?? 'false') === 'true') {
            self::writeToFile($entry);
        }
    }

    // ──────────────────────────────────────────────
    //  API REST Supabase (table `logs`)
    // ──────────────────────────────────────────────

    private static function writeToApi(string $level, string $message, array $context): bool
    {
        $url        = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $serviceKey = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';

        if ($url === '' || $serviceKey === '') {
            return false;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        try {
            $payload = json_encode([
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ch = curl_init("{$url}/rest/v1/logs");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 3,          // non-bloquant
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'apikey: ' . $serviceKey,
                    'Authorization: Bearer ' . $serviceKey,
                    'Prefer: return=minimal',
                ],
            ]);

            $httpCode = 0;
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode >= 200 && $httpCode < 300;
        } catch (Throwable) {
            return false;
        }
    }

    // ──────────────────────────────────────────────
    //  Repli fichier
    // ──────────────────────────────────────────────

    private static function writeToFile(array $entry): void
    {
        $path = $_ENV['LOG_FILE_PATH'] ?? __DIR__ . '/../../storage/logs/app.log';
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
