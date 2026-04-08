<?php

declare(strict_types=1);

namespace App\Core;

class RateLimiter
{
    private string $dir;

    public function __construct()
    {
        $this->dir = __DIR__ . '/../../storage/rate_limits';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Tente une action et retourne vrai si elle est autorisée.
     *
     * @param string $action  Identifiant de l'action (ex. 'login', 'register')
     * @param string $key     IP ou user_id
     * @param int    $max     Nombre max de tentatives autorisées
     * @param int    $window  Durée de la fenêtre en secondes
     */
    public function attempt(string $action, string $key, int $max, int $window): bool
    {
        $file = $this->path($action, $key);
        $data = $this->read($file);
        $now  = time();

        if ($data === null || ($now - $data['window_start']) >= $window) {
            $data = ['attempts' => 0, 'window_start' => $now];
        }

        $data['attempts']++;
        $this->write($file, $data);

        return $data['attempts'] <= $max;
    }

    /** Secondes restantes avant réinitialisation de la fenêtre. */
    public function retryAfter(string $action, string $key, int $window): int
    {
        $file = $this->path($action, $key);
        $data = $this->read($file);
        if ($data === null) {
            return 0;
        }
        return max(0, $window - (time() - $data['window_start']));
    }

    public function reset(string $action, string $key): void
    {
        $file = $this->path($action, $key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function path(string $action, string $key): string
    {
        return $this->dir . '/' . hash('sha256', $action . ':' . $key) . '.json';
    }

    private function read(string $file): ?array
    {
        if (!file_exists($file)) {
            return null;
        }
        $json = @file_get_contents($file);
        return $json ? json_decode($json, true) : null;
    }

    private function write(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}
