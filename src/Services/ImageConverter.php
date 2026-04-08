<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Throwable;

/**
 * Téléchargement d'images IGDB, conversion WebP, stockage Supabase ou local.
 *
 * `IGDB_IMAGES_TARGET` : `supabase` (recommandé, bucket `SUPABASE_STORAGE_BUCKET_IGDB`)
 *                      ou `local` (dossier `IGDB_IMAGES_PATH`).
 *
 * En mode supabase, si l'upload échoue pour une image, l'URL IGDB originale
 * est retournée en secours pour ne pas laisser la jaquette vide.
 */
class ImageConverter
{
    private Client $http;
    private string $storageDir;
    private string $publicPrefix;
    private int    $quality;
    private int    $concurrency;
    private string $target;

    private ?SupabaseObjectStorage $objectStorage = null;

    public function __construct()
    {
        $this->http         = new Client(['timeout' => 30, 'http_errors' => false, 'connect_timeout' => 10]);
        $this->storageDir   = rtrim(
            $_ENV['IGDB_IMAGES_PATH'] ?? dirname(__DIR__, 2) . '/storage/images/igdb',
            '/\\'
        );
        $this->publicPrefix = '/storage/images/igdb';
        $this->quality      = (int)($_ENV['IGDB_WEBP_QUALITY']        ?? 80);
        $this->concurrency  = (int)($_ENV['IGDB_DOWNLOAD_CONCURRENT']  ?? 20);
        $this->target       = strtolower(trim($_ENV['IGDB_IMAGES_TARGET'] ?? 'supabase'));

        if ($this->target === 'supabase') {
            $this->objectStorage = new SupabaseObjectStorage();
            if (!$this->objectStorage->isConfigured()) {
                Logger::warning(
                    'ImageConverter: IGDB_IMAGES_TARGET=supabase mais SUPABASE_STORAGE_BUCKET_IGDB / clés manquants'
                    . ' — retour au stockage local. Vérifiez .env.'
                );
                $this->target        = 'local';
                $this->objectStorage = null;
            } else {
                Logger::info('ImageConverter: mode Supabase Storage actif', [
                    'bucket' => $_ENV['SUPABASE_STORAGE_BUCKET_IGDB'] ?? '?',
                ]);
            }
        }

        if ($this->target === 'local' && !is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
            Logger::info('ImageConverter: mode local actif', ['dir' => $this->storageDir]);
        }
    }

    private function useSupabase(): bool
    {
        return $this->target === 'supabase' && $this->objectStorage !== null;
    }

    /**
     * Télécharge une image depuis $url et la convertit en WebP à $relativePath.
     *
     * @param  string $fallbackUrl URL originale retournée si la conversion/upload échoue.
     * @return string|null URL publique (Supabase ou locale), ou $fallbackUrl, ou null.
     */
    public function convertFromUrl(string $url, string $relativePath, string $fallbackUrl = ''): ?string
    {
        $results = $this->convertBatch([$relativePath => $url]);
        $result  = $results[$relativePath] ?? null;

        if ($result === null && $fallbackUrl !== '') {
            return $fallbackUrl;
        }

        return $result;
    }

    /**
     * Télécharge et convertit un lot d'images en parallèle.
     *
     * @param  array<string, string|null> $items  [relativePath => sourceUrl|null]
     * @return array<string, string|null>          [relativePath => publicUrl|null]
     */
    public function convertBatch(array $items, ?int $concurrency = null): array
    {
        $concurrency = $concurrency ?? $this->concurrency;
        $results     = [];
        $toDownload  = [];
        $originalUrls = [];  // conserve les URLs sources pour le fallback

        foreach ($items as $relativePath => $url) {
            if (!$url) {
                $results[$relativePath] = null;
                continue;
            }

            $originalUrls[$relativePath] = $url;

            // Mode local : ignorer si le fichier existe déjà
            if (!$this->useSupabase()) {
                $destPath = $this->absPath($relativePath);
                $destDir  = dirname($destPath);
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }
                if (is_file($destPath)) {
                    $results[$relativePath] = $this->publicPath($relativePath);
                    continue;
                }
            }

            $toDownload[$relativePath] = $url;
        }

        if ($toDownload === []) {
            return $results;
        }

        $chunks = array_chunk($toDownload, $concurrency, true);

        foreach ($chunks as $chunk) {
            $promises = [];
            foreach ($chunk as $relativePath => $url) {
                $promises[$relativePath] = $this->http->requestAsync('GET', $url);
            }

            $settled = PromiseUtils::settle($promises)->wait();

            foreach ($settled as $relativePath => $outcome) {
                if ($outcome['state'] !== 'fulfilled') {
                    Logger::warning('ImageConverter: téléchargement échoué', [
                        'path'  => $relativePath,
                        'error' => (string) ($outcome['reason'] ?? 'rejected'),
                    ]);
                    // Fallback : URL IGDB originale
                    $results[$relativePath] = $originalUrls[$relativePath] ?? null;
                    continue;
                }

                /** @var \Psr\Http\Message\ResponseInterface $response */
                $response = $outcome['value'];

                if ($response->getStatusCode() >= 400) {
                    $results[$relativePath] = $originalUrls[$relativePath] ?? null;
                    continue;
                }

                $imageData = (string) $response->getBody();
                if ($imageData === '') {
                    $results[$relativePath] = $originalUrls[$relativePath] ?? null;
                    continue;
                }

                try {
                    $webpBlob = $this->toWebpBlob($imageData);
                    if ($webpBlob === null || $webpBlob === '') {
                        $results[$relativePath] = $originalUrls[$relativePath] ?? null;
                        continue;
                    }

                    if ($this->useSupabase()) {
                        $public = $this->objectStorage->upload($relativePath, $webpBlob, 'image/webp');
                        // Si l'upload Supabase échoue, retourner l'URL IGDB originale
                        $results[$relativePath] = $public ?? ($originalUrls[$relativePath] ?? null);
                    } else {
                        $destPath = $this->absPath($relativePath);
                        $destDir  = dirname($destPath);
                        if (!is_dir($destDir)) {
                            @mkdir($destDir, 0755, true);
                        }
                        if (file_put_contents($destPath, $webpBlob) !== false) {
                            $results[$relativePath] = $this->publicPath($relativePath);
                        } else {
                            $results[$relativePath] = null;
                        }
                    }
                } catch (Throwable $e) {
                    Logger::warning('ImageConverter: échec traitement WebP', [
                        'path'  => $relativePath,
                        'error' => $e->getMessage(),
                    ]);
                    $results[$relativePath] = $originalUrls[$relativePath] ?? null;
                }
            }
        }

        foreach (array_keys($toDownload) as $relativePath) {
            if (!array_key_exists($relativePath, $results)) {
                $results[$relativePath] = null;
            }
        }

        return $results;
    }

    /**
     * Supprime une image (local uniquement — Supabase gère la déduplication via upsert).
     */
    public function delete(string $relativePath): void
    {
        if ($this->useSupabase()) {
            $this->objectStorage->delete($relativePath);
            return;
        }
        $path = $this->absPath($relativePath);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ──────────────────────────────────────────────
    //  Conversion WebP
    // ──────────────────────────────────────────────

    private function toWebpBlob(string $imageData): ?string
    {
        if (extension_loaded('imagick')) {
            return $this->toWebpBlobImagick($imageData);
        }

        if (extension_loaded('gd')) {
            return $this->toWebpBlobGd($imageData);
        }

        Logger::warning('ImageConverter: ni Imagick ni GD — envoi des octets bruts (non WebP)');
        return $imageData !== '' ? $imageData : null;
    }

    private function toWebpBlobImagick(string $imageData): ?string
    {
        try {
            $imagick = new \Imagick();
            $imagick->readImageBlob($imageData);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($this->quality);
            $imagick->stripImage();
            $blob = $imagick->getImageBlob();
            $imagick->destroy();

            return $blob !== false ? $blob : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function toWebpBlobGd(string $imageData): ?string
    {
        $img = @imagecreatefromstring($imageData);
        if ($img === false) {
            return null;
        }

        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        ob_start();
        $ok   = imagewebp($img, null, $this->quality);
        $blob = ob_get_clean();
        imagedestroy($img);

        return $ok && $blob !== false ? $blob : null;
    }

    // ──────────────────────────────────────────────
    //  Helpers chemins (mode local)
    // ──────────────────────────────────────────────

    private function absPath(string $relativePath): string
    {
        return $this->storageDir . '/' . ltrim($relativePath, '/\\');
    }

    private function publicPath(string $relativePath): string
    {
        return $this->publicPrefix . '/' . ltrim($relativePath, '/\\');
    }

    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    public function getTarget(): string
    {
        return $this->target;
    }
}
