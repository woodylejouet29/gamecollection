<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Traductions EN (IGDB) → FR.
 * On conserve les valeurs IGDB (EN) dans l'URL/DB, et on traduit uniquement à l'affichage.
 */
final class GenreTranslations
{
    /** @var array<string,string> */
    private const MAP = [
        'Adventure' => 'Aventure',
        'Arcade' => 'Arcade',
        'Card & Board Game' => 'Jeu de cartes & plateau',
        'Fighting' => 'Combat',
        'Hack and slash/Beat \'em up' => 'Hack’n slash / Beat’em up',
        'Indie' => 'Indépendant',
        'MOBA' => 'MOBA',
        'Music' => 'Musique / Rythme',
        'Pinball' => 'Flipper',
        'Platform' => 'Plateforme',
        'Point-and-click' => 'Point & Click',
        'Puzzle' => 'Puzzle',
        'Quiz/Trivia' => 'Quiz / Trivia',
        'Racing' => 'Course',
        'Real Time Strategy (RTS)' => 'Stratégie temps réel',
        'Role-playing (RPG)' => 'RPG',
        'Shooter' => 'Shooter',
        'Simulator' => 'Simulation',
        'Sport' => 'Sport',
        'Strategy' => 'Stratégie',
        'Tactical' => 'Tactique',
        'Turn-based strategy (TBS)' => 'Stratégie au tour par tour',
        'Visual Novel' => 'Roman visuel',
    ];

    public static function translate(?string $igdbNameEn): string
    {
        $k = trim((string) $igdbNameEn);
        if ($k === '') {
            return '';
        }
        return self::MAP[$k] ?? $k;
    }

    /**
     * Résout un libellé affiché ou stocké (EN IGDB, ou FR issu de translate())
     * vers la clé anglaise IGDB, si elle est connue.
     */
    public static function resolveToIgdbEnglish(?string $label): ?string
    {
        $k = trim((string) $label);
        if ($k === '') {
            return null;
        }
        $kn = self::normalizeGenreLabel($k);
        if (isset(self::MAP[$k])) {
            return $k;
        }
        if (isset(self::MAP[$kn])) {
            return $kn;
        }
        foreach (self::MAP as $en => $fr) {
            $frn = self::normalizeGenreLabel($fr);
            if ($fr === $k || $fr === $kn || $frn === $k || $frn === $kn) {
                return $en;
            }
        }
        return null;
    }

    private static function normalizeGenreLabel(string $s): string
    {
        return str_replace(["\u{2019}", "\u{2018}", "\u{201A}"], "'", $s);
    }
}

