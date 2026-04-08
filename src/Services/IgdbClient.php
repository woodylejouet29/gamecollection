<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Client IGDB (API Apicalypse v4).
 *
 * Basé sur le style de IgdbFullSyncClient :
 *  - curl natif (sans Guzzle)
 *  - OAuth2 Twitch avec cache fichier
 *  - Rate limiting fenêtre glissante (4 req/s par défaut)
 *  - Cache JSON par clé lisible (ex. "games/2024/off0")
 *  - Circuit breaker CLOSED → OPEN → HALF-OPEN
 *  - Retry automatique avec back-off
 */
class IgdbClient
{
    private const TOKEN_CACHE_FILE = 'igdb_token.json';
    private const CB_STATE_FILE    = 'igdb_circuit_breaker.json';
    private const RATE_FILE        = 'igdb_rate_window.json';
    private const MAX_RETRIES      = 3;
    private const RETRY_DELAY_S    = 5;

    private string $clientId;
    private string $clientSecret;
    private string $cacheDir;
    private int    $cacheTtl;
    private int    $rateLimit;
    private int    $cbThreshold;
    private int    $cbTimeout;

    public function __construct()
    {
        $this->clientId     = $_ENV['IGDB_CLIENT_ID']     ?? '';
        $this->clientSecret = $_ENV['IGDB_CLIENT_SECRET'] ?? '';
        $this->cacheTtl     = (int)($_ENV['IGDB_CACHE_TTL']    ?? 86400);
        $this->rateLimit    = (int)($_ENV['IGDB_RATE_LIMIT']   ?? 4);
        $this->cbThreshold  = (int)($_ENV['IGDB_CB_THRESHOLD'] ?? 3);
        $this->cbTimeout    = (int)($_ENV['IGDB_CB_TIMEOUT']   ?? 3600);

        $this->cacheDir = rtrim(
            $_ENV['IGDB_CACHE_DIR'] ?? dirname(__DIR__, 2) . '/storage/cache/igdb',
            '/\\'
        );

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \RuntimeException(
                'IGDB_CLIENT_ID et IGDB_CLIENT_SECRET doivent être définis dans .env'
            );
        }
    }

    // ──────────────────────────────────────────────
    //  Point d'entrée principal
    // ──────────────────────────────────────────────

    /**
     * Exécute une requête Apicalypse sur un endpoint IGDB.
     *
     * @param  string $endpoint  ex. "games", "platforms", "release_dates"
     * @param  string $body      Corps Apicalypse (fields, where, limit, offset…)
     * @param  string $cacheKey  Clé lisible ex. "games/2024/off0" ; vide = hash SHA-256
     * @return array             Tableau de résultats (vide si circuit ouvert sans cache)
     */
    public function query(string $endpoint, string $body, string $cacheKey = ''): array
    {
        if ($this->isCircuitOpen()) {
            Logger::warning('IGDB circuit ouvert — basculement cache local', ['endpoint' => $endpoint]);
            return $this->getCachedFallback($endpoint, $body, $cacheKey);
        }

        $cached = $this->getFromCache($endpoint, $body, $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $this->throttle();

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            [$data, $httpCode, $error] = $this->curlPost(
                "https://api.igdb.com/v4/{$endpoint}",
                $body,
                $this->buildHeaders()
            );

            if ($error) {
                Logger::warning("IGDB curl error (tentative {$attempt}/" . self::MAX_RETRIES . ")", [
                    'endpoint' => $endpoint,
                    'error'    => $error,
                ]);
                sleep(self::RETRY_DELAY_S);
                continue;
            }

            if ($httpCode === 401) {
                Logger::warning('IGDB token expiré, renouvellement…');
                $this->invalidateToken();
                continue;
            }

            if ($httpCode === 429) {
                Logger::warning('IGDB rate limit (429), attente 10s…');
                sleep(10);
                continue;
            }

            if ($httpCode >= 400) {
                $this->recordFailure($endpoint);
                Logger::error('IGDB requête échouée', [
                    'endpoint' => $endpoint,
                    'status'   => $httpCode,
                    'response' => substr($data, 0, 500),
                ]);
                return $this->getCachedFallback($endpoint, $body, $cacheKey);
            }

            $parsed = json_decode($data, true);
            if (!is_array($parsed)) {
                $this->recordFailure($endpoint);
                Logger::error('IGDB réponse non-JSON', ['endpoint' => $endpoint, 'raw' => substr($data, 0, 200)]);
                return $this->getCachedFallback($endpoint, $body, $cacheKey);
            }

            $this->resetFailures();

            if (!empty($parsed)) {
                $this->saveToCache($endpoint, $body, $parsed, $cacheKey);
            }

            return $parsed;
        }

        $this->recordFailure($endpoint);
        return $this->getCachedFallback($endpoint, $body, $cacheKey);
    }

    // ──────────────────────────────────────────────
    //  Comptage (endpoint /count)
    // ──────────────────────────────────────────────

    /**
     * Retourne le nombre total de résultats pour une condition WHERE.
     * @param string $whereBody ex. "first_release_date >= 1704067200 & first_release_date < 1735689600"
     */
    public function count(string $endpoint, string $whereBody = ''): int
    {
        if ($this->isCircuitOpen()) {
            return 0;
        }

        $this->throttle();

        $body = $whereBody !== '' ? "where {$whereBody};" : '';

        [$response, $httpCode, $error] = $this->curlPost(
            "https://api.igdb.com/v4/{$endpoint}/count",
            $body,
            $this->buildHeaders()
        );

        if ($error || $httpCode >= 400) {
            Logger::warning('IgdbClient::count échoué', [
                'endpoint' => $endpoint,
                'status'   => $httpCode,
                'error'    => $error,
            ]);
            return 0;
        }

        $data = json_decode($response, true);
        return isset($data['count']) ? (int) $data['count'] : 0;
    }

    // ──────────────────────────────────────────────
    //  OAuth2 Twitch
    // ──────────────────────────────────────────────

    public function getAccessToken(): string
    {
        $tokenFile = $this->cacheDir . '/' . self::TOKEN_CACHE_FILE;

        if (is_file($tokenFile)) {
            $raw  = file_get_contents($tokenFile);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data) && ($data['expires_at'] ?? 0) > time() + 60) {
                return (string) $data['access_token'];
            }
        }

        $url = 'https://id.twitch.tv/oauth2/token?' . http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            throw new \RuntimeException("Échec auth Twitch (HTTP {$httpCode}): {$error} {$response}");
        }

        $body = json_decode($response, true);
        if (empty($body['access_token'])) {
            throw new \RuntimeException('Token Twitch absent de la réponse : ' . substr($response, 0, 300));
        }

        $data = [
            'access_token' => $body['access_token'],
            'expires_at'   => time() + (int)($body['expires_in'] ?? 3600),
        ];
        file_put_contents($tokenFile, json_encode($data), LOCK_EX);

        Logger::info('Token IGDB renouvelé', ['expires_at' => date('c', $data['expires_at'])]);

        return (string) $data['access_token'];
    }

    private function invalidateToken(): void
    {
        $tokenFile = $this->cacheDir . '/' . self::TOKEN_CACHE_FILE;
        if (is_file($tokenFile)) {
            @unlink($tokenFile);
        }
    }

    // ──────────────────────────────────────────────
    //  curl helper
    // ──────────────────────────────────────────────

    /**
     * @return array{0: string, 1: int, 2: string}  [body, httpCode, curlError]
     */
    private function curlPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $response = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = (string) curl_error($ch);
        curl_close($ch);

        return [$response, $httpCode, $error];
    }

    private function buildHeaders(): array
    {
        $token = $this->getAccessToken();
        return [
            "Client-ID: {$this->clientId}",
            "Authorization: Bearer {$token}",
            "Content-Type: text/plain",
        ];
    }

    // ──────────────────────────────────────────────
    //  Cache fichier JSON
    // ──────────────────────────────────────────────

    private function cacheFile(string $endpoint, string $body, string $cacheKey): string
    {
        if ($cacheKey !== '') {
            $path = $this->cacheDir . '/' . ltrim(str_replace('\\', '/', $cacheKey), '/') . '.json';
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            return $path;
        }

        return $this->cacheDir . '/' . hash('sha256', $endpoint . '|' . trim($body)) . '.json';
    }

    private function getFromCache(string $endpoint, string $body, string $cacheKey): ?array
    {
        $file = $this->cacheFile($endpoint, $body, $cacheKey);
        if (!is_file($file)) {
            return null;
        }

        $raw  = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($data) || !isset($data['payload'])) {
            return null;
        }

        if (($data['cached_at'] ?? 0) + $this->cacheTtl < time()) {
            @unlink($file);
            return null;
        }

        return is_array($data['payload']) ? $data['payload'] : null;
    }

    private function saveToCache(string $endpoint, string $body, array $payload, string $cacheKey): void
    {
        $file = $this->cacheFile($endpoint, $body, $cacheKey);
        file_put_contents($file, json_encode([
            'cached_at' => time(),
            'endpoint'  => $endpoint,
            'payload'   => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function getCachedFallback(string $endpoint, string $body, string $cacheKey): array
    {
        $file = $this->cacheFile($endpoint, $body, $cacheKey);
        if (!is_file($file)) {
            return [];
        }

        $raw  = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($data) || !isset($data['payload']) || !is_array($data['payload'])) {
            return [];
        }

        $age = time() - ($data['cached_at'] ?? 0);
        Logger::info('IGDB fallback cache local', ['endpoint' => $endpoint, 'cache_age_s' => $age]);

        return $data['payload'];
    }

    public function invalidateCache(string $endpoint, string $body, string $cacheKey = ''): void
    {
        $file = $this->cacheFile($endpoint, $body, $cacheKey);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    // ──────────────────────────────────────────────
    //  Circuit breaker
    // ──────────────────────────────────────────────

    private function cbFile(): string
    {
        return $this->cacheDir . '/' . self::CB_STATE_FILE;
    }

    private function readCbState(): array
    {
        $file = $this->cbFile();
        if (!is_file($file)) {
            return ['state' => 'closed', 'failures' => 0, 'opened_at' => null];
        }
        $raw  = file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : ['state' => 'closed', 'failures' => 0, 'opened_at' => null];
    }

    private function writeCbState(array $state): void
    {
        file_put_contents($this->cbFile(), json_encode($state), LOCK_EX);
    }

    private function isCircuitOpen(): bool
    {
        $state = $this->readCbState();

        if ($state['state'] === 'closed') {
            return false;
        }

        if ($state['state'] === 'open') {
            if (($state['opened_at'] ?? 0) + $this->cbTimeout < time()) {
                $state['state'] = 'half-open';
                $this->writeCbState($state);
                Logger::info('IGDB circuit breaker → HALF-OPEN (test en cours)');
                return false;
            }
            return true;
        }

        return false; // half-open : laisser passer une requête test
    }

    private function recordFailure(string $endpoint): void
    {
        $state = $this->readCbState();

        if ($state['state'] === 'half-open') {
            $state['state']     = 'open';
            $state['opened_at'] = time();
            $this->writeCbState($state);
            Logger::error('IGDB circuit breaker → OPEN (test HALF-OPEN échoué)', ['endpoint' => $endpoint]);
            return;
        }

        $state['failures'] = ($state['failures'] ?? 0) + 1;

        if ($state['failures'] >= $this->cbThreshold) {
            $state['state']     = 'open';
            $state['opened_at'] = time();
            Logger::critical('IGDB circuit breaker → OPEN', [
                'failures'  => $state['failures'],
                'endpoint'  => $endpoint,
                'reopen_at' => date('c', $state['opened_at'] + $this->cbTimeout),
            ]);
        }

        $this->writeCbState($state);
    }

    private function resetFailures(): void
    {
        $state = $this->readCbState();
        if ($state['state'] !== 'closed' || ($state['failures'] ?? 0) > 0) {
            $this->writeCbState(['state' => 'closed', 'failures' => 0, 'opened_at' => null]);
            if ($state['state'] !== 'closed') {
                Logger::info('IGDB circuit breaker → CLOSED');
            }
        }
    }

    public function getCircuitState(): array
    {
        return $this->readCbState();
    }

    // ──────────────────────────────────────────────
    //  Rate limiting (fenêtre glissante 1 seconde)
    // ──────────────────────────────────────────────

    private function throttle(): void
    {
        $file = $this->cacheDir . '/' . self::RATE_FILE;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $raw  = is_file($file) ? file_get_contents($file) : false;
            $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
            $data = is_array($data) ? $data : [];

            $now  = microtime(true);
            $data = array_values(array_filter($data, fn($t) => $t > $now - 1.0));

            if (count($data) < $this->rateLimit) {
                $data[] = $now;
                file_put_contents($file, json_encode($data), LOCK_EX);
                return;
            }

            $oldest = min($data);
            $waitMs = (int)(($oldest + 1.0 - $now) * 1_000) + 10;
            usleep(max($waitMs, 50) * 1_000);
        }
    }

    // ──────────────────────────────────────────────
    //  Accesseurs
    // ──────────────────────────────────────────────

    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }
}
