<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\View;
use App\Services\SupabaseAuth;

class AuthMiddleware
{
    /** Redirige vers /login si l'utilisateur n'est pas connecté. */
    public static function requireAuth(): void
    {
        if (empty($_SESSION['auth'])) {
            $_SESSION['flash'] = [
                'type'    => 'error',
                'message' => 'Vous devez être connecté pour accéder à cette page.',
                'errors'  => [],
                'old'     => [],
            ];
            View::redirect('/login');
        }

        // Rafraîchir le token si expiré (marge 60 s)
        $auth = &$_SESSION['auth'];
        if (!empty($auth['expires_at']) && time() >= ($auth['expires_at'] - 60)) {
            $result = (new SupabaseAuth())->refreshToken($auth['refresh_token'] ?? '');
            if ($result['success']) {
                $auth = array_merge($auth, $result['data']);
            } else {
                self::clear();
                View::redirect('/login');
            }
        }
    }

    /** Redirige vers /collection si l'utilisateur est déjà connecté. */
    public static function requireGuest(): void
    {
        if (!empty($_SESSION['auth'])) {
            View::redirect('/collection');
        }
    }

    /** Données de session de l'utilisateur courant, ou null. */
    public static function user(): ?array
    {
        return $_SESSION['auth'] ?? null;
    }

    public static function userId(): ?string
    {
        return $_SESSION['auth']['user_id'] ?? null;
    }

    public static function clear(): void
    {
        unset($_SESSION['auth']);
        session_regenerate_id(true);
    }
}
