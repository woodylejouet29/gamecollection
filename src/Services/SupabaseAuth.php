<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use GuzzleHttp\Client;
use Throwable;

/**
 * Toutes les opérations Auth utilisent l'API Supabase Auth v1.
 * Toutes les opérations BDD utilisent l'API REST Supabase (PostgREST)
 * avec la clé service_role → pas besoin de connexion PDO directe.
 */
class SupabaseAuth
{
    private Client $http;
    private string $url;
    private string $anonKey;
    private string $serviceKey;

    public function __construct()
    {
        $this->url        = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->anonKey    = $_ENV['SUPABASE_ANON_KEY'] ?? '';
        $this->serviceKey = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
        $this->http       = new Client(['timeout' => 10, 'http_errors' => false]);
    }

    // ──────────────────────────────────────────────
    //  Inscription
    // ──────────────────────────────────────────────

    public function signUp(string $email, string $password, string $username, ?string $avatarUrl = null): array
    {
        try {
            if (!$this->isUsernameAvailable($username)) {
                return $this->err('USERNAME_TAKEN', 'Ce pseudo est déjà utilisé.');
            }

            $res  = $this->authPost('/signup', [
                'email'    => $email,
                'password' => $password,
                'data'     => ['username' => $username],
            ]);
            $body = $res['body'];

            if ($res['status'] >= 400) {
                $msg  = $body['msg'] ?? ($body['error_description'] ?? ($body['message'] ?? 'Erreur lors de l\'inscription.'));
                $code = str_contains(strtolower($msg), 'already') ? 'EMAIL_TAKEN' : 'SIGNUP_ERROR';
                if ($code === 'EMAIL_TAKEN') {
                    $msg = 'Cette adresse e-mail est déjà utilisée.';
                }
                return $this->err($code, $msg);
            }

            // Confirmation ON  → { "id": "…", "email": "…" }  (user à la racine)
            // Confirmation OFF → { "access_token": "…", "user": { "id": "…" } }
            $userObj           = $body['user'] ?? $body;
            $userId            = $userObj['id']         ?? null;
            $accessToken       = $body['access_token']  ?? null;
            $refreshToken      = $body['refresh_token'] ?? null;
            $expiresIn         = (int)($body['expires_in'] ?? 3600);
            $needsConfirmation = ($accessToken === null);

            if (!$userId) {
                $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true'
                    ? ' — Réponse Supabase : ' . json_encode($body)
                    : '';
                return $this->err('SIGNUP_ERROR', 'Identifiant utilisateur manquant dans la réponse Supabase.' . $debug);
            }

            // Créer/upsert le profil dans public.users via PostgREST
            $this->restPost('/users', [
                'id'         => $userId,
                'username'   => $username,
                'avatar_url' => $avatarUrl,
            ], ['Prefer' => 'resolution=merge-duplicates,return=minimal']);

            Logger::info('Inscription', [
                'user_id'            => $userId,
                'username'           => $username,
                'needs_confirmation' => $needsConfirmation,
            ]);

            return ['success' => true, 'data' => [
                'user_id'            => $userId,
                'email'              => $email,
                'username'           => $username,
                'avatar_url'         => $avatarUrl,
                'access_token'       => $accessToken,
                'refresh_token'      => $refreshToken,
                'expires_at'         => time() + $expiresIn,
                'needs_confirmation' => $needsConfirmation,
            ]];
        } catch (Throwable $e) {
            Logger::exception($e, 'SupabaseAuth::signUp');
            $msg = ($_ENV['APP_DEBUG'] ?? 'false') === 'true'
                ? 'Erreur interne : ' . $e->getMessage()
                : 'Erreur interne lors de l\'inscription.';
            return $this->err('INTERNAL_ERROR', $msg);
        }
    }

    // ──────────────────────────────────────────────
    //  Connexion
    // ──────────────────────────────────────────────

    public function signIn(string $email, string $password): array
    {
        try {
            $res  = $this->authPost('/token?grant_type=password', [
                'email'    => $email,
                'password' => $password,
            ]);
            $body = $res['body'];

            if ($res['status'] >= 400) {
                return $this->err('INVALID_CREDENTIALS', 'Email ou mot de passe incorrect.');
            }

            $userObj      = $body['user'] ?? $body;
            $userId       = $userObj['id']         ?? null;
            $accessToken  = $body['access_token']  ?? null;
            $refreshToken = $body['refresh_token'] ?? null;
            $expiresIn    = (int)($body['expires_in'] ?? 3600);

            if (!$userId) {
                return $this->err('SIGNIN_ERROR', 'Identifiant utilisateur manquant.');
            }

            // Récupérer le profil via PostgREST
            $profile = $this->restGet("/users?id=eq.{$userId}&select=username,avatar_url&limit=1");
            $profile = $profile[0] ?? [];

            Logger::info('Connexion', ['user_id' => $userId]);

            return ['success' => true, 'data' => [
                'user_id'       => $userId,
                'email'         => $email,
                'username'      => $profile['username']   ?? '',
                'avatar_url'    => $profile['avatar_url'] ?? null,
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at'    => time() + $expiresIn,
            ]];
        } catch (Throwable $e) {
            Logger::exception($e, 'SupabaseAuth::signIn');
            $msg = ($_ENV['APP_DEBUG'] ?? 'false') === 'true'
                ? 'Erreur interne : ' . $e->getMessage()
                : 'Erreur interne lors de la connexion.';
            return $this->err('INTERNAL_ERROR', $msg);
        }
    }

    // ──────────────────────────────────────────────
    //  Déconnexion
    // ──────────────────────────────────────────────

    public function signOut(string $accessToken): void
    {
        try {
            $this->http->post("{$this->url}/auth/v1/logout", [
                'headers' => [
                    'apikey'        => $this->anonKey,
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type'  => 'application/json',
                ],
            ]);
        } catch (Throwable $e) {
            Logger::warning('Erreur signOut', ['error' => $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────
    //  Rafraîchissement du token
    // ──────────────────────────────────────────────

    public function refreshToken(string $refreshToken): array
    {
        try {
            $res  = $this->authPost('/token?grant_type=refresh_token', [
                'refresh_token' => $refreshToken,
            ]);
            $body = $res['body'];

            if ($res['status'] >= 400) {
                return $this->err('REFRESH_ERROR', 'Session expirée, veuillez vous reconnecter.');
            }

            return ['success' => true, 'data' => [
                'access_token'  => $body['access_token']  ?? null,
                'refresh_token' => $body['refresh_token'] ?? $refreshToken,
                'expires_at'    => time() + (int)($body['expires_in'] ?? 3600),
            ]];
        } catch (Throwable $e) {
            Logger::exception($e, 'SupabaseAuth::refreshToken');
            return $this->err('INTERNAL_ERROR', 'Erreur de rafraîchissement.');
        }
    }

    // ──────────────────────────────────────────────
    //  Mise à jour email / mot de passe
    // ──────────────────────────────────────────────

    public function updateAuthEmail(string $accessToken, string $newEmail): array
    {
        return $this->updateAuthUser($accessToken, ['email' => $newEmail]);
    }

    public function updateAuthPassword(string $accessToken, string $newPassword): array
    {
        return $this->updateAuthUser($accessToken, ['password' => $newPassword]);
    }

    private function updateAuthUser(string $accessToken, array $payload): array
    {
        try {
            $res  = $this->http->put("{$this->url}/auth/v1/user", [
                'headers' => [
                    'apikey'        => $this->anonKey,
                    'Authorization' => "Bearer {$accessToken}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);
            $body = $this->decode($res);

            if ($res->getStatusCode() >= 400) {
                return $this->err('UPDATE_ERROR', $body['msg'] ?? 'Erreur de mise à jour.');
            }

            return ['success' => true];
        } catch (Throwable $e) {
            Logger::exception($e, 'SupabaseAuth::updateAuthUser');
            return $this->err('INTERNAL_ERROR', 'Erreur interne.');
        }
    }

    // ──────────────────────────────────────────────
    //  Profil BDD via PostgREST
    // ──────────────────────────────────────────────

    public function updateProfile(string $userId, array $fields): array
    {
        try {
            $allowed = ['username', 'avatar_url', 'bio', 'collection_public'];
            $payload = array_intersect_key($fields, array_flip($allowed));

            if (empty($payload)) {
                return ['success' => true];
            }

            if (array_key_exists('collection_public', $payload)) {
                $payload['collection_public'] = (bool) $payload['collection_public'];
            }

            $res = $this->http->patch("{$this->url}/rest/v1/users?id=eq.{$userId}", [
                'headers' => [
                    'apikey'        => $this->serviceKey,
                    'Authorization' => "Bearer {$this->serviceKey}",
                    'Content-Type'  => 'application/json',
                    'Prefer'        => 'return=minimal',
                ],
                'json' => $payload,
            ]);

            if ($res->getStatusCode() >= 400) {
                $body = $this->decode($res);
                return $this->err('DB_ERROR', $body['message'] ?? 'Erreur de mise à jour du profil.');
            }

            return ['success' => true];
        } catch (Throwable $e) {
            Logger::exception($e, 'SupabaseAuth::updateProfile');
            return $this->err('DB_ERROR', 'Erreur de mise à jour du profil.');
        }
    }

    public function savePreferences(string $userId, array $platformIds, array $genreIds, array $genreNames): void
    {
        try {
            // Supprimer les anciennes plateformes
            $this->http->delete("{$this->url}/rest/v1/user_platforms?user_id=eq.{$userId}", [
                'headers' => [
                    'apikey'        => $this->serviceKey,
                    'Authorization' => "Bearer {$this->serviceKey}",
                ],
            ]);

            if (!empty($platformIds)) {
                $rows = array_map(fn($pid) => ['user_id' => $userId, 'platform_id' => (int)$pid], $platformIds);
                $this->restPost('/user_platforms', $rows, ['Prefer' => 'resolution=merge-duplicates,return=minimal']);
            }

            // Supprimer les anciens genres
            $this->http->delete("{$this->url}/rest/v1/user_genres?user_id=eq.{$userId}", [
                'headers' => [
                    'apikey'        => $this->serviceKey,
                    'Authorization' => "Bearer {$this->serviceKey}",
                ],
            ]);

            if (!empty($genreIds)) {
                $rows = [];
                foreach ($genreIds as $i => $gid) {
                    $rows[] = [
                        'user_id'       => $userId,
                        'igdb_genre_id' => (int)$gid,
                        'genre_name'    => $genreNames[$i] ?? '',
                    ];
                }
                $this->restPost('/user_genres', $rows, ['Prefer' => 'resolution=merge-duplicates,return=minimal']);
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'SupabaseAuth::savePreferences');
        }
    }

    // ──────────────────────────────────────────────
    //  Disponibilité du pseudo
    // ──────────────────────────────────────────────

    public function isUsernameAvailable(string $username): bool
    {
        try {
            $encoded = urlencode($username);
            $rows    = $this->restGet("/users?username=ilike.{$encoded}&select=id&limit=1");
            return empty($rows);
        } catch (Throwable) {
            return true;
        }
    }

    // ──────────────────────────────────────────────
    //  Helpers internes
    // ──────────────────────────────────────────────

    /** Appel à l'API Supabase Auth v1. */
    private function authPost(string $path, array $json): array
    {
        $res  = $this->http->post("{$this->url}/auth/v1{$path}", [
            'headers' => ['apikey' => $this->anonKey, 'Content-Type' => 'application/json'],
            'json'    => $json,
        ]);
        return ['status' => $res->getStatusCode(), 'body' => $this->decode($res)];
    }

    /** POST vers PostgREST (service_role). */
    private function restPost(string $path, array $data, array $extraHeaders = []): void
    {
        $this->http->post("{$this->url}/rest/v1{$path}", [
            'headers' => array_merge([
                'apikey'        => $this->serviceKey,
                'Authorization' => "Bearer {$this->serviceKey}",
                'Content-Type'  => 'application/json',
            ], $extraHeaders),
            'json' => $data,
        ]);
    }

    /** GET vers PostgREST (service_role), retourne le tableau de résultats. */
    private function restGet(string $pathWithQuery): array
    {
        $res  = $this->http->get("{$this->url}/rest/v1{$pathWithQuery}", [
            'headers' => [
                'apikey'        => $this->serviceKey,
                'Authorization' => "Bearer {$this->serviceKey}",
            ],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        return is_array($data) ? $data : [];
    }

    private function decode($response): array
    {
        $json = json_decode((string) $response->getBody(), true);
        return is_array($json) ? $json : [];
    }

    private function err(string $code, string $message): array
    {
        return ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
