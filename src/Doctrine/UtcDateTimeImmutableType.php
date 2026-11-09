<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * Garantit le stockage en UTC, quel que soit le fuseau de la date reçue.
 * Sans cette conversion, une date à l'heure du Maroc serait écrite telle quelle.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(new \DateTimeZone('UTC'));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $date = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), $value, new \DateTimeZone('UTC'));

        if (false === $date) {
            throw ConversionException::conversionFailed($value, self::class);
        }

        return $date;
    }
}
