<?php

declare(strict_types=1);

namespace App\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array|string $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    public function post(string $path, callable|array|string $handler): void
    {
        $this->routes[] = ['POST', $path, $handler];
    }

    public function patch(string $path, callable|array|string $handler): void
    {
        $this->routes[] = ['PATCH', $path, $handler];
    }

    public function delete(string $path, callable|array|string $handler): void
    {
        $this->routes[] = ['DELETE', $path, $handler];
    }

    public function dispatch(): void
    {
        $dispatchStart = microtime(true);
        $routes = $this->routes;

        $dispatcher = simpleDispatcher(function (RouteCollector $r) use ($routes) {
            foreach ($routes as [$method, $path, $handler]) {
                $r->addRoute($method, $path, $handler);
            }
        });

        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = $_SERVER['REQUEST_URI'];

        // Retirer le query string et normaliser le chemin
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        // Si le projet n'est pas en racine (ex. /gameproject/public/…)
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }

        $uri = '/' . ltrim($uri, '/');

        $routeInfo = $dispatcher->dispatch($method, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                $this->handleError(404);
                break;

            case Dispatcher::METHOD_NOT_ALLOWED:
                $this->handleError(405);
                break;

            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $params  = $routeInfo[2];
                $this->call($handler, $params);
                break;
        }

        // Timing global côté serveur (TTFB approx) : utile pour distinguer PHP vs front.
        if (!headers_sent() && defined('APP_REQ_START')) {
            $totalMs    = (microtime(true) - APP_REQ_START) * 1000;
            $dispatchMs = (microtime(true) - $dispatchStart) * 1000;
            header(
                'Server-Timing: app;dur=' . number_format($totalMs, 1, '.', '')
                . ', router;dur=' . number_format($dispatchMs, 1, '.', ''),
                false
            );
        }
    }

    private function call(callable|array|string $handler, array $params): void
    {
        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
            return;
        }

        // Format ['ControllerClass', 'method'] ou 'ControllerClass@method'
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
        } elseif (is_array($handler)) {
            [$class, $method] = $handler;
        } else {
            $this->handleError(500);
            return;
        }

        if (!class_exists($class)) {
            Logger::error("Contrôleur introuvable : {$class}");
            $this->handleError(500);
            return;
        }

        $controller = new $class();

        if (!method_exists($controller, $method)) {
            Logger::error("Méthode introuvable : {$class}::{$method}");
            $this->handleError(500);
            return;
        }

        call_user_func_array([$controller, $method], $params);
    }

    private function handleError(int $code): void
    {
        http_response_code($code);

        $page = match ($code) {
            404 => __DIR__ . '/../../errors/404.php',
            405 => __DIR__ . '/../../errors/404.php',
            default => __DIR__ . '/../../errors/500.php',
        };

        if (file_exists($page)) {
            require $page;
        } else {
            echo "<h1>Erreur {$code}</h1>";
        }

        exit;
    }
}
