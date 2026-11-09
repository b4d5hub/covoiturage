<?php

namespace App\Time;

/**
 * Conversions entre l'heure du Maroc (saisie, affichage) et l'UTC (traitements, stockage).
 */
final class MoroccoTime
{
    public const ZONE = 'Africa/Casablanca';

    /** Convertit une heure saisie à l'heure du Maroc en instant UTC. */
    public static function toUtc(string $localDateTime): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($localDateTime, new \DateTimeZone(self::ZONE)))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Exprime un instant à l'heure du Maroc, pour l'affichage. */
    public static function toLocal(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone(self::ZONE));
    }
}
