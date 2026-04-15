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
}

