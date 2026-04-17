<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Règle : un jeu n'est ajoutable à la collection qu'à partir de J-7 avant sa date de sortie
 * (inclus ce jour-là). Les jeux déjà sortis restent toujours éligibles.
 */
final class CollectionReleasePolicy
{
    public const DAYS_BEFORE_RELEASE = 7;

    /**
     * @param  string|null $releaseDateYmd Date SQL Y-m-d ou null
     * @return array{allowed: true}|array{allowed: false, code: non-empty-string, message: non-empty-string, opens_at?: string}
     */
    public static function checkAddAllowed(?string $releaseDateYmd, ?DateTimeImmutable $now = null): array
    {
        $tz  = self::appTimezone();
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0, 0);

        if ($releaseDateYmd === null || trim($releaseDateYmd) === '') {
            return [
                'allowed' => false,
                'code'    => 'RELEASE_DATE_UNKNOWN',
                'message' => 'Impossible d\'ajouter ce jeu à la collection sans date de sortie connue.',
            ];
        }

        $ymd = substr(trim($releaseDateYmd), 0, 10);
        $release = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $tz);
        if ($release === false) {
            return [
                'allowed' => false,
                'code'    => 'RELEASE_DATE_INVALID',
                'message' => 'Date de sortie invalide pour ce jeu.',
            ];
        }

        $release = $release->setTime(0, 0, 0);
        $windowStart = $release->modify('-' . self::DAYS_BEFORE_RELEASE . ' days');

        if ($today < $windowStart) {
            return [
                'allowed'   => false,
                'code'      => 'COLLECTION_TOO_EARLY',
                'message'   => 'Vous pourrez ajouter ce jeu à votre collection à partir du '
                    . $windowStart->format('d/m/Y')
                    . ' (7 jours avant sa sortie).',
                'opens_at'  => $windowStart->format('Y-m-d'),
            ];
        }

        return ['allowed' => true];
    }

    private static function appTimezone(): DateTimeZone
    {
        $name = $_ENV['APP_TIMEZONE'] ?? 'Europe/Paris';
        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
