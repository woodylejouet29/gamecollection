<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Abréviations courtes des plateformes pour l'affichage dans les badges.
 * Utilisé comme fallback quand le champ `abbreviation` Supabase/IGDB est vide.
 */
class PlatformAbbreviations
{
    private const MAP = [
        // Sony
        'PlayStation 5'              => 'PS5',
        'PlayStation 4'              => 'PS4',
        'PlayStation 3'              => 'PS3',
        'PlayStation 2'              => 'PS2',
        'PlayStation'                => 'PS1',
        'PS Vita'                    => 'PS Vita',
        'PlayStation Vita'           => 'PS Vita',
        'PSP'                        => 'PSP',

        // Microsoft
        'Xbox Series X|S'            => 'X|S',
        'Xbox Series X'              => 'XSX',
        'Xbox Series S'              => 'XSS',
        'Xbox One'                   => 'ONE',
        'XONE'                         => 'ONE',
        'Xbox 360'                   => 'X360',
        'Xbox'                       => 'Xbox',

        // Nintendo – consoles de salon
        'Nintendo Switch'            => 'Switch',
        'Wii U'                      => 'Wii U',
        'Wii'                        => 'Wii',
        'GameCube'                   => 'NGC',
        'Nintendo 64'                => 'N64',
        'Super Nintendo'             => 'SNES',
        'Super Nintendo Entertainment System' => 'SNES',
        'NES'                        => 'NES',
        'Nintendo Entertainment System' => 'NES',

        // Nintendo – portables
        'Nintendo Switch 2'          => 'Switch 2',
        'Nintendo 3DS'               => '3DS',
        'Nintendo DS'                => 'DS',
        'Game Boy Advance'           => 'GBA',
        'Game Boy Color'             => 'GBC',
        'Game Boy'                   => 'GB',
        'Virtual Boy'                => 'VB',

        // PC / Mac / Linux
        'PC (Windows)'               => 'PC',
        'PC'                         => 'PC',
        'Mac'                        => 'Mac',
        'macOS'                      => 'Mac',
        'Linux'                      => 'Linux',

        // Sega
        'Mega Drive / Genesis'       => 'MD',
        'Mega Drive'                 => 'MD',
        'Genesis'                    => 'MD',
        'Dreamcast'                  => 'DC',
        'Saturn'                     => 'Saturn',
        'Sega Saturn'                => 'Saturn',
        'Game Gear'                  => 'GG',
        'Master System'              => 'SMS',
        'Sega Master System'         => 'SMS',
        'Sega 32X'                   => '32X',
        'Sega CD'                    => 'SCD',

        // SNK / NEC
        'Neo Geo'                    => 'Neo Geo',
        'TurboGrafx-16'              => 'TG-16',
        'PC Engine'                  => 'PCE',

        // Atari
        'Atari 2600'                 => 'A2600',
        'Atari 7800'                 => 'A7800',
        'Atari Jaguar'               => 'Jaguar',

        // Mobile
        'iOS'                        => 'iOS',
        'Android'                    => 'Android',
        'Windows Phone'              => 'WP',

        // Divers
        'Arcade'                     => 'Arcade',
        'Web browser'                => 'Web',
    ];

    /**
     * Retourne l'abréviation courte d'une plateforme.
     * Si le nom n'est pas répertorié, retourne le nom d'origine.
     */
    public static function get(string $name): string
    {
        return self::MAP[$name] ?? $name;
    }
}
