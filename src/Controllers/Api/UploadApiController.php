<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Middleware\AuthMiddleware;

/**
 * POST /api/uploads/selection-photo
 *
 * Multipart attendu:
 * - file: image/*
 *
 * Réponse:
 * - { success: true, url: "/assets/uploads/selection/xxx.webp" }
 */
final class UploadApiController
{
    private const MAX_BYTES = 5_000_000; // 5 Mo
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function uploadSelectionPhoto(): void
    {
        AuthMiddleware::requireAuth();

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name'])) {
            $this->json(['success' => false, 'error' => ['code' => 'NO_FILE']], 422);
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $this->json(['success' => false, 'error' => ['code' => 'UPLOAD_ERROR']], 422);
        }

        if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > self::MAX_BYTES) {
            $this->json(['success' => false, 'error' => ['code' => 'FILE_TOO_LARGE']], 422);
        }

        $tmp = (string) $file['tmp_name'];
        $mime = @mime_content_type($tmp) ?: '';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $this->json(['success' => false, 'error' => ['code' => 'INVALID_FILE_TYPE']], 422);
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'img',
        };

        $uploadDir = __DIR__ . '/../../../assets/uploads/selection/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            $this->json(['success' => false, 'error' => ['code' => 'UPLOAD_DIR_NOT_WRITABLE']], 500);
        }

        $userId = AuthMiddleware::userId() ?? 'anon';
        $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) ?: 'user';

        $filename = $safeUser . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $dest     = $uploadDir . $filename;

        if (!move_uploaded_file($tmp, $dest)) {
            $this->json(['success' => false, 'error' => ['code' => 'MOVE_FAILED']], 500);
        }

        $this->json(['success' => true, 'data' => ['url' => '/assets/uploads/selection/' . $filename]]);
    }

    private function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

