<?php



declare(strict_types=1);



namespace App\Data;



/**

 * Icônes SVG par genre (nom IGDB en anglais, comme en base / API).

 * Fichiers dans /public ou racine web selon déploiement : URL absolue depuis la racine du site.

 *

 * @see GenreTranslations

 */

final class GenreIcons

{

    /** @var array<string,string> nom IGDB (EN) → chemin URL /images/genres/*.svg */

    private const MAP = [

        'Adventure' => '/images/genres/aventure.svg',

        'Arcade' => '/images/genres/arcade.svg',

        'Card & Board Game' => '/images/genres/cartes.svg',

        'Fighting' => '/images/genres/combat.svg',

        'Hack and slash/Beat \'em up' => '/images/genres/hackslash.svg',

        'Indie' => '/images/genres/independant.svg',

        'MOBA' => '/images/genres/moba.svg',

        'Music' => '/images/genres/musique.svg',

        'Pinball' => '/images/genres/flipper.svg',

        'Platform' => '/images/genres/plateforme.svg',

        'Point-and-click' => '/images/genres/pointandclick.svg',

        'Puzzle' => '/images/genres/puzzle.svg',

        'Quiz/Trivia' => '/images/genres/quiz.svg',

        'Racing' => '/images/genres/course.svg',

        'Real Time Strategy (RTS)' => '/images/genres/strategiereel.svg',

        'Role-playing (RPG)' => '/images/genres/rpg.svg',

        'Shooter' => '/images/genres/shooter.svg',

        'Simulator' => '/images/genres/simulation.svg',

        'Sport' => '/images/genres/sport.svg',

        'Strategy' => '/images/genres/strategie.svg',

        'Tactical' => '/images/genres/tactique.svg',

        'Turn-based strategy (TBS)' => '/images/genres/tourpartour.svg',

        'Visual Novel' => '/images/genres/romanvisuel.svg',

    ];

    /**
     * Libellés historiques (ex. inscription / StaticData) → clé EN IGDB pour l’icône.
     *
     * @var array<string,string>
     */
    private const LEGACY_TO_EN = [
        "Beat'em up" => 'Hack and slash/Beat \'em up',
    ];

    /**
     * URL de l’icône : nom IGDB (EN), libellé FR de translate(), ou ancien nom StaticData.
     */
    public static function url(?string $igdbNameEn): ?string
    {
        $k = trim((string) $igdbNameEn);
        if ($k === '') {
            return null;
        }
        $en = GenreTranslations::resolveToIgdbEnglish($k);
        if ($en !== null) {
            return self::MAP[$en] ?? null;
        }
        $kn = str_replace(["\u{2019}", "\u{2018}", "\u{201A}"], "'", $k);
        $legacy = self::LEGACY_TO_EN[$k] ?? self::LEGACY_TO_EN[$kn] ?? null;
        if ($legacy !== null) {
            return self::MAP[$legacy] ?? null;
        }
        return null;
    }
}


