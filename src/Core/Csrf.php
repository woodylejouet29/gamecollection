<?php

declare(strict_types=1);

namespace App\Core;

class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    public static function verify(?string $token): bool
    {
        $stored = $_SESSION[self::KEY] ?? null;
        return $stored && $token && hash_equals($stored, $token);
    }

    /** À appeler en tête de tout handler POST. Termine la requête si invalide. */
    public static function check(): void
    {
        if (!self::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Session expirée. <a href="javascript:history.back()">Retour</a>');
        }
    }
}
