<?php

declare(strict_types=1);

namespace App\Core;

class Flash
{
    private static ?array $data = null;

    public static function set(string $type, string $message, array $errors = [], array $old = []): void
    {
        $_SESSION['flash'] = compact('type', 'message', 'errors', 'old');
    }

    public static function success(string $message): void
    {
        self::set('success', $message);
    }

    public static function error(string $message, array $errors = [], array $old = []): void
    {
        self::set('error', $message, $errors, $old);
    }

    /** Retourne le message flash (type + texte) et vide la session. */
    public static function message(): ?array
    {
        self::load();
        return !empty(self::$data['message'])
            ? ['type' => self::$data['type'] ?? 'info', 'text' => self::$data['message']]
            : null;
    }

    /** Ancienne valeur d'un champ de formulaire (après erreur). */
    public static function old(string $field, string $default = ''): string
    {
        self::load();
        return htmlspecialchars((string)(self::$data['old'][$field] ?? $default), ENT_QUOTES, 'UTF-8');
    }

    /** Ancienne valeur tableau (cases à cocher). */
    public static function oldArray(string $field): array
    {
        self::load();
        $v = self::$data['old'][$field] ?? [];
        return is_array($v) ? array_map('strval', $v) : [];
    }

    /** Message d'erreur pour un champ précis. */
    public static function fieldError(string $field): string
    {
        self::load();
        return htmlspecialchars((string)(self::$data['errors'][$field] ?? ''), ENT_QUOTES, 'UTF-8');
    }

    public static function hasFieldError(string $field): bool
    {
        self::load();
        return !empty(self::$data['errors'][$field]);
    }

    private static function load(): void
    {
        if (self::$data === null) {
            self::$data = $_SESSION['flash'] ?? [];
            unset($_SESSION['flash']);
        }
    }
}
