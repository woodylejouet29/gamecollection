<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class View
{
    public static function render(string $template, array $data = [], string $layout = 'base'): void
    {
        extract($data, EXTR_SKIP);

        ob_start();
        $tplPath = __DIR__ . '/../../views/' . $template . '.php';
        if (!file_exists($tplPath)) {
            throw new RuntimeException("Vue introuvable : {$template}");
        }
        require $tplPath;
        $content = ob_get_clean();

        $layoutPath = __DIR__ . '/../../views/layouts/' . $layout . '.php';
        if (!file_exists($layoutPath)) {
            throw new RuntimeException("Layout introuvable : {$layout}");
        }
        require $layoutPath;
    }

    /**
     * Rend un template SANS layout — utilisé pour les réponses AJAX partielles.
     * Le contenu est envoyé directement dans le buffer de sortie courant.
     */
    public static function renderPartial(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);

        $tplPath = __DIR__ . '/../../views/' . $template . '.php';
        if (!file_exists($tplPath)) {
            http_response_code(404);
            echo '<div class="search-empty"><p>Erreur de chargement.</p></div>';
            return;
        }

        require $tplPath;
    }

    public static function redirect(string $url, int $code = 302): never
    {
        http_response_code($code);
        header('Location: ' . $url);
        exit;
    }

    public static function asset(string $path): string
    {
        $rel      = '/assets/' . ltrim($path, '/');
        $physical = __DIR__ . '/../../assets/' . ltrim($path, '/');
        $v        = file_exists($physical) ? filemtime($physical) : null;
        return $v ? $rel . '?v=' . $v : $rel;
    }

    public static function url(string $path = ''): string
    {
        $base = rtrim($_ENV['APP_URL'] ?? '', '/');
        return $base . '/' . ltrim($path, '/');
    }
}
