<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Stockage optionnel du synopsis en zstd (colonne BYTEA côté Postgres, format hex PostgREST en JSON).
 * Si l’extension PHP zstd est absente, la synchro garde le synopsis en TEXT classique.
 */
final class ZstdSynopsis
{
    /**
     * Valeur à envoyer dans le JSON PostgREST pour BYTEA (préfixe \x + hex), ou null.
     */
    public static function compressForSupabase(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        if (!function_exists('zstd_compress')) {
            return null;
        }
        $level = (int) ($_ENV['SYNOPSIS_ZSTD_LEVEL'] ?? 3);
        $level = max(1, min(22, $level));
        $bin = @zstd_compress($plain, $level);
        if ($bin === false) {
            return null;
        }

        return '\\x' . bin2hex($bin);
    }

    /**
     * Décode pour affichage / SEO : d’abord synopsis_zstd, sinon synopsis TEXT (legacy).
     */
    public static function expandFromRow(array $row): ?string
    {
        $z = $row['synopsis_zstd'] ?? null;
        if (is_string($z) && $z !== '') {
            $decoded = self::expandFromHexEsc($z);
            if ($decoded !== null && $decoded !== '') {
                return $decoded;
            }
        }

        $plain = $row['synopsis'] ?? null;
        if (is_string($plain) && $plain !== '') {
            return $plain;
        }

        return null;
    }

    /**
     * Remplace synopsis par le texte décompressé et retire synopsis_zstd du tableau exposé aux vues.
     */
    public static function hydrateGameRow(array &$row): void
    {
        $expanded = self::expandFromRow($row);
        $row['synopsis'] = $expanded;
        unset($row['synopsis_zstd']);
    }

    public static function expandFromHexEsc(string $hexEsc): ?string
    {
        if (strlen($hexEsc) < 4 || !str_starts_with($hexEsc, '\x')) {
            return null;
        }
        $hex = substr($hexEsc, 2);
        if ($hex === '' || (strlen($hex) % 2) !== 0 || !ctype_xdigit($hex)) {
            return null;
        }
        $bin = @hex2bin($hex);
        if ($bin === false || $bin === '') {
            return null;
        }
        if (!function_exists('zstd_uncompress')) {
            return null;
        }
        $out = @zstd_uncompress($bin);

        return $out === false ? null : $out;
    }
}
