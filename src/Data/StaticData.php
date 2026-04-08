<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Données statiques utilisées avant l'intégration IGDB (Phase P2).
 * Les IDs correspondent aux identifiants IGDB officiels.
 */
class StaticData
{
    public static function platforms(): array
    {
        return [
            ['id' => 167, 'name' => 'PlayStation 5',         'short' => 'PS5'],
            ['id' => 48,  'name' => 'PlayStation 4',         'short' => 'PS4'],
            ['id' => 9,   'name' => 'PlayStation 3',         'short' => 'PS3'],
            ['id' => 8,   'name' => 'PlayStation 2',         'short' => 'PS2'],
            ['id' => 7,   'name' => 'PlayStation',           'short' => 'PS1'],
            ['id' => 46,  'name' => 'PS Vita',               'short' => 'PS Vita'],
            ['id' => 38,  'name' => 'PSP',                   'short' => 'PSP'],
            ['id' => 169, 'name' => 'Xbox Series X|S',       'short' => 'XSX'],
            ['id' => 49,  'name' => 'Xbox One',              'short' => 'Xbox One'],
            ['id' => 12,  'name' => 'Xbox 360',              'short' => 'X360'],
            ['id' => 11,  'name' => 'Xbox',                  'short' => 'Xbox'],
            ['id' => 130, 'name' => 'Nintendo Switch',       'short' => 'Switch'],
            ['id' => 37,  'name' => 'Nintendo 3DS',          'short' => '3DS'],
            ['id' => 20,  'name' => 'Nintendo DS',           'short' => 'DS'],
            ['id' => 41,  'name' => 'Wii U',                 'short' => 'Wii U'],
            ['id' => 5,   'name' => 'Wii',                   'short' => 'Wii'],
            ['id' => 21,  'name' => 'GameCube',              'short' => 'NGC'],
            ['id' => 18,  'name' => 'Nintendo 64',           'short' => 'N64'],
            ['id' => 19,  'name' => 'Super Nintendo',        'short' => 'SNES'],
            ['id' => 18,  'name' => 'NES',                   'short' => 'NES'],
            ['id' => 24,  'name' => 'Game Boy Advance',      'short' => 'GBA'],
            ['id' => 33,  'name' => 'Game Boy Color',        'short' => 'GBC'],
            ['id' => 22,  'name' => 'Game Boy',              'short' => 'GB'],
            ['id' => 6,   'name' => 'PC (Windows)',          'short' => 'PC'],
            ['id' => 14,  'name' => 'Mac',                   'short' => 'Mac'],
            ['id' => 3,   'name' => 'Linux',                 'short' => 'Linux'],
            ['id' => 29,  'name' => 'Mega Drive / Genesis',  'short' => 'MD'],
            ['id' => 23,  'name' => 'Dreamcast',             'short' => 'DC'],
        ];
    }

    public static function genres(): array
    {
        return [
            ['id' => 31, 'name' => 'Aventure'],
            ['id' => 12, 'name' => 'RPG'],
            ['id' => 5,  'name' => 'Shooter'],
            ['id' => 4,  'name' => 'Combat'],
            ['id' => 8,  'name' => 'Plateforme'],
            ['id' => 10, 'name' => 'Course'],
            ['id' => 14, 'name' => 'Sport'],
            ['id' => 15, 'name' => 'Stratégie'],
            ['id' => 16, 'name' => 'Stratégie au tour par tour'],
            ['id' => 13, 'name' => 'Simulation'],
            ['id' => 9,  'name' => 'Puzzle'],
            ['id' => 25, 'name' => 'Beat\'em up'],
            ['id' => 32, 'name' => 'Indépendant'],
            ['id' => 34, 'name' => 'Visual Novel'],
            ['id' => 7,  'name' => 'Musique / Rythme'],
            ['id' => 24, 'name' => 'Tactique'],
            ['id' => 2,  'name' => 'Point & Click'],
            ['id' => 33, 'name' => 'Arcade'],
            ['id' => 11, 'name' => 'Stratégie temps réel'],
            ['id' => 26, 'name' => 'Quiz / Trivia'],
            ['id' => 35, 'name' => 'MOBA'],
        ];
    }

    public static function genreNameById(int $id): string
    {
        foreach (self::genres() as $g) {
            if ($g['id'] === $id) {
                return $g['name'];
            }
        }
        return '';
    }

    public static function platformNameById(int $id): string
    {
        foreach (self::platforms() as $p) {
            if ($p['id'] === $id) {
                return $p['name'];
            }
        }
        return '';
    }
}
