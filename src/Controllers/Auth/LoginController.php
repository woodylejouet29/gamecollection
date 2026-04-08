<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Middleware\AuthMiddleware;
use App\Core\RateLimiter;
use App\Core\View;
use App\Services\SupabaseAuth;
use App\Services\TurnstileService;

class LoginController
{
    public function show(): void
    {
        AuthMiddleware::requireGuest();

        View::render('auth/login', [
            'title'   => 'Connexion',
            'cssFile' => 'auth',
            'head'    => '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>',
        ]);
    }

    public function handle(): void
    {
        AuthMiddleware::requireGuest();
        Csrf::check();

        $ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimiter = new RateLimiter();

        // 10 tentatives par 15 minutes
        if (!$rateLimiter->attempt('login', $ip, 10, 900)) {
            $wait = ceil($rateLimiter->retryAfter('login', $ip, 900) / 60);
            Flash::error("Trop de tentatives. Réessayez dans {$wait} minute(s).", [], ['email' => $_POST['email'] ?? '']);
            View::redirect('/login');
        }

        // Turnstile
        if (!(new TurnstileService())->verify($_POST['cf-turnstile-response'] ?? '', $ip)) {
            Flash::error('Vérification anti-bot échouée. Veuillez réessayer.', [], ['email' => $_POST['email'] ?? '']);
            View::redirect('/login');
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);

        if ($email === '' || $password === '') {
            Flash::error('Veuillez remplir tous les champs.', [], ['email' => $email]);
            View::redirect('/login');
        }

        $auth   = new SupabaseAuth();
        $result = $auth->signIn($email, $password);

        if (!$result['success']) {
            Flash::error($result['error']['message'], [], ['email' => $email]);
            View::redirect('/login');
        }

        // Connexion réussie → réinitialiser le rate limiter
        $rateLimiter->reset('login', $ip);

        $_SESSION['auth'] = array_merge($result['data'], ['remember' => $remember]);
        session_regenerate_id(true);

        // Ajuster la durée du cookie APRÈS regenerate (session_set_cookie_params est
        // inutilisable une fois la session démarrée — on réécrit le cookie directement).
        if ($remember) {
            setcookie(
                session_name(),
                session_id(),
                [
                    'expires'  => time() + 30 * 24 * 3600,
                    'path'     => '/',
                    'secure'   => ($_ENV['APP_ENV'] ?? 'development') === 'production',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        Flash::success('Bon retour, ' . htmlspecialchars($result['data']['username']) . ' !');
        View::redirect('/');
    }

    public function logout(): void
    {
        $auth = AuthMiddleware::user();

        if ($auth) {
            (new SupabaseAuth())->signOut($auth['access_token'] ?? '');
        }

        AuthMiddleware::clear();

        // Supprimer le cookie de session
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        session_destroy();
        View::redirect('/');
    }
}
