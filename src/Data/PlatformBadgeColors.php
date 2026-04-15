<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Couleurs de badges plateformes (auto + overrides).
 *
 * - Auto: couleur déterministe à partir de `slug` (ou id en fallback).
 * - Overrides: quelques familles "brand" (PlayStation, Xbox, Nintendo, PC/Steam…).
 *
 * Usage (HTML):
 *   <span class="platform-badge" style="<?= PlatformBadgeColors::style($id, $slug, $abbr, $name) ?>">PS5</span>
 *
 * Le CSS consomme :
 *   --pb-bg, --pb-fg, --pb-bd
 */
final class PlatformBadgeColors
{
    public static function style(
        int $platformId,
        ?string $slug = null,
        ?string $abbreviation = null,
        ?string $name = null
    ): string {
        $slug = self::norm($slug);
        $abbr = self::norm($abbreviation);
        $name = self::norm($name);

        // 1) Overrides (families)
        $ov = self::override($slug, $abbr, $name);
        if ($ov !== null) {
            [$bg, $fg, $bd] = $ov;
            return self::vars($bg, $fg, $bd);
        }

        // 2) Auto color (deterministic)
        $seed = $slug !== '' ? $slug : ('id:' . $platformId);
        $h = self::hashHue($seed);

        // Palette auto: assez saturée pour se distinguer, mais pas fluo.
        $s = 68;
        $l = 42;

        $bg = self::hsl($h, $s, $l);
        $fg = self::preferredTextColor($h, $s, $l);
        $bd = self::hsl($h, min(90, $s + 8), max(26, $l - 10));
        return self::vars($bg, $fg, $bd);
    }

    /** @return array{0:string,1:string,2:string}|null */
    private static function override(string $slug, string $abbr, string $name): ?array
    {
        $hay = trim($slug . ' ' . $abbr . ' ' . $name);

        // Sony PlayStation — bleu
        if (str_contains($hay, 'playstation') || preg_match('/\bps[1-5]\b/', $hay)) {
            return ['#2563eb', '#ffffff', 'rgba(37, 99, 235, .55)'];
        }

        // Xbox — vert
        if (str_contains($hay, 'xbox') || str_contains($hay, 'x360') || str_contains($hay, 'xsx')) {
            return ['#16a34a', '#ffffff', 'rgba(22, 163, 74, .55)'];
        }

        // Nintendo — rouge
        if (str_contains($hay, 'nintendo') || str_contains($hay, 'switch') || str_contains($hay, 'wii') || str_contains($hay, 'gamecube')) {
            return ['#dc2626', '#ffffff', 'rgba(220, 38, 38, .55)'];
        }

        // PC / Steam — sombre
        if (preg_match('/\bpc\b/', $hay) || str_contains($hay, 'windows') || str_contains($hay, 'steam')) {
            return ['#111827', '#ffffff', 'rgba(17, 24, 39, .55)'];
        }

        // Mac — gris
        if (str_contains($hay, 'mac')) {
            return ['#6b7280', '#ffffff', 'rgba(107, 114, 128, .55)'];
        }

        // Linux — ambre
        if (str_contains($hay, 'linux')) {
            return ['#b45309', '#ffffff', 'rgba(180, 83, 9, .55)'];
        }

        return null;
    }

    private static function norm(?string $s): string
    {
        return mb_strtolower(trim((string) $s));
    }

    private static function vars(string $bg, string $fg, string $bd): string
    {
        // Inline CSS vars keeps CSS small and supports all platforms automatically.
        $bg = htmlspecialchars($bg, ENT_QUOTES);
        $fg = htmlspecialchars($fg, ENT_QUOTES);
        $bd = htmlspecialchars($bd, ENT_QUOTES);
        return "--pb-bg: {$bg}; --pb-fg: {$fg}; --pb-bd: {$bd};";
    }

    private static function hashHue(string $seed): int
    {
        // Simple stable hash → hue 0..359
        $h = 0;
        $len = strlen($seed);
        for ($i = 0; $i < $len; $i++) {
            $h = ($h * 31 + ord($seed[$i])) & 0x7fffffff;
        }
        return $h % 360;
    }

    private static function hsl(int $h, int $s, int $l): string
    {
        return "hsl({$h} {$s}% {$l}%)";
    }

    private static function preferredTextColor(int $h, int $s, int $l): string
    {
        // Rough heuristic: for our palette, l <= 55 reads better with white.
        // If we ever increase lightness, flip to dark text.
        return $l > 58 ? '#111827' : '#ffffff';
    }
}

