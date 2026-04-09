<?php

declare(strict_types=1);

// ──────────────────────────────────────────────
//  Assets "implicites" (évite du bruit console)
// ──────────────────────────────────────────────
// Les navigateurs demandent souvent /favicon.ico même si aucun <link rel="icon"> n'est défini.
// Si le fichier n'existe pas, Apache réécrit vers index.php (via .htaccess) → 404 + bruit.
if (($_SERVER['REQUEST_URI'] ?? '') === '/favicon.ico') {
    http_response_code(204);
    exit;
}

// Mesure globale de durée de requête (utilisé pour Server-Timing).
if (!defined('APP_REQ_START')) {
    define('APP_REQ_START', microtime(true));
}

// ──────────────────────────────────────────────
//  Autoload Composer
// ──────────────────────────────────────────────
require_once __DIR__ . '/vendor/autoload.php';

// ──────────────────────────────────────────────
//  Variables d'environnement (.env)
// ──────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// ──────────────────────────────────────────────
//  Middleware CSP (Content Security Policy)
// ──────────────────────────────────────────────
$cspMiddleware = new \App\Core\Middleware\CspMiddleware();
$cspMiddleware->handle();

// ──────────────────────────────────────────────
//  Gestion globale des erreurs
// ──────────────────────────────────────────────
$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use ($debug): bool {
    if ($debug) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    \App\Core\Logger::error($errstr, [
        'file' => $errfile,
        'line' => $errline,
        'code' => $errno,
    ]);
    return true;
});

set_exception_handler(function (\Throwable $e) use ($debug): void {
    \App\Core\Logger::exception($e);

    http_response_code(500);
    if ($debug) {
        echo '<pre><strong>' . get_class($e) . '</strong>: ' . htmlspecialchars($e->getMessage()) . "\n";
        echo htmlspecialchars($e->getTraceAsString()) . '</pre>';
    } else {
        require __DIR__ . '/errors/500.php';
    }
    exit(1);
});

// ──────────────────────────────────────────────
//  Session
// ──────────────────────────────────────────────
session_name($_ENV['SESSION_NAME'] ?? 'gameproject_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => ($_ENV['APP_ENV'] ?? 'development') === 'production',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ──────────────────────────────────────────────
//  Routage
// ──────────────────────────────────────────────
$router = new \App\Core\Router();
require_once __DIR__ . '/routes/web.php';
$router->dispatch();
