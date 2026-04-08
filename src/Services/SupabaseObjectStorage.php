<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use GuzzleHttp\Client;
use Throwable;

/**
 * Upload d’objets binaires vers Supabase Storage (API REST, rôle service).
 */
final class SupabaseObjectStorage
{
    private Client $http;
    private string $supabaseUrl;
    private string $serviceKey;
    private string $bucket;

    public function __construct()
    {
        $this->http        = new Client(['timeout' => 60, 'http_errors' => false]);
        $this->supabaseUrl = rtrim($_ENV['SUPABASE_URL'] ?? '', '/');
        $this->serviceKey  = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';
        $this->bucket      = $_ENV['SUPABASE_STORAGE_BUCKET_IGDB'] ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->supabaseUrl !== '' && $this->serviceKey !== '' && $this->bucket !== '';
    }

    /**
     * URL publique (bucket configuré en lecture publique dans Supabase).
     */
    public function publicUrl(string $objectPath): string
    {
        $path = ltrim(str_replace('\\', '/', $objectPath), '/');
        $enc  = implode('/', array_map('rawurlencode', explode('/', $path)));

        return "{$this->supabaseUrl}/storage/v1/object/public/{$this->bucket}/{$enc}";
    }

    /**
     * Supprime un objet du bucket.
     */
    public function delete(string $objectPath): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $path = ltrim(str_replace('\\', '/', $objectPath), '/');
        $enc  = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url  = "{$this->supabaseUrl}/storage/v1/object/{$this->bucket}/{$enc}";

        try {
            $this->http->delete($url, [
                'headers' => [
                    'Authorization' => "Bearer {$this->serviceKey}",
                    'apikey'        => $this->serviceKey,
                ],
            ]);
        } catch (Throwable $e) {
            Logger::warning('SupabaseObjectStorage: delete exception', [
                'path'  => $objectPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return string|null URL publique si succès
     */
    public function upload(string $objectPath, string $binary, string $contentType = 'image/webp'): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $objectPath), '/');
        $enc  = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url  = "{$this->supabaseUrl}/storage/v1/object/{$this->bucket}/{$enc}";

        try {
            $res = $this->http->post($url, [
                'headers' => [
                    'Authorization'   => "Bearer {$this->serviceKey}",
                    'apikey'          => $this->serviceKey,
                    'Content-Type'    => $contentType,
                    'x-upsert'        => 'true',
                ],
                'body' => $binary,
            ]);
        } catch (Throwable $e) {
            Logger::warning('SupabaseObjectStorage: upload exception', ['path' => $objectPath, 'error' => $e->getMessage()]);

            return null;
        }

        $code = $res->getStatusCode();
        if ($code >= 400) {
            Logger::warning('SupabaseObjectStorage: upload HTTP erreur', [
                'path'   => $objectPath,
                'status' => $code,
                'body'   => substr((string) $res->getBody(), 0, 400),
            ]);

            return null;
        }

        return $this->publicUrl($path);
    }
}
