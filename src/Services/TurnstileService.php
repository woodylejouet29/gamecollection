<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use GuzzleHttp\Client;
use Throwable;

class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    // Clé de test Cloudflare "always pass" — utilisée si aucune clé réelle n'est configurée
    private const TEST_SECRET = '1x0000000000000000000000000000000AA';

    public function verify(string $token, ?string $ip = null): bool
    {
        $env          = $_ENV['APP_ENV'] ?? 'development';
        $useRealInDev = ($_ENV['TURNSTILE_USE_REAL_IN_DEV'] ?? 'false') === 'true';

        $secret = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
        if ($env !== 'production' && !$useRealInDev) {
            $secret = self::TEST_SECRET;
        }

        if (empty($secret) || $secret === self::TEST_SECRET) {
            return true;
        }

        if (empty($token)) {
            Logger::warning('Turnstile : token absent');
            return false;
        }

        try {
            $http = new Client(['timeout' => 10, 'http_errors' => false]);

            $params = ['secret' => $secret, 'response' => $token];
            // remoteip est optionnel ; derrière proxy / IPv6 / CDN, une IP incorrecte peut faire échouer
            // la vérification sur certains navigateurs ou réseaux. N’envoyer que si explicitement demandé.
            $sendIp = filter_var(
                $_ENV['TURNSTILE_VERIFY_REMOTEIP'] ?? 'false',
                FILTER_VALIDATE_BOOLEAN
            );
            if ($sendIp && $ip !== null && $ip !== '') {
                $params['remoteip'] = $ip;
            }

            $res  = $http->post(self::VERIFY_URL, ['form_params' => $params]);
            $body = json_decode((string) $res->getBody(), true) ?? [];

            if (!($body['success'] ?? false)) {
                Logger::warning('Échec Turnstile', ['codes' => $body['error-codes'] ?? [], 'ip' => $ip]);
                return false;
            }

            return true;
        } catch (Throwable $e) {
            // En cas d'erreur réseau, on laisse passer pour ne pas bloquer les utilisateurs légitimes
            Logger::warning('Erreur réseau Turnstile', ['error' => $e->getMessage()]);
            return true;
        }
    }
}
