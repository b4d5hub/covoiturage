<?php

namespace App\Geo;

/**
 * Coordonnées géographiques, au format des colonnes DECIMAL(9,6).
 */
final readonly class Coordinates
{
    public function __construct(
        public string $latitude,
        public string $longitude,
    ) {
    }

    public static function fromFloats(float $latitude, float $longitude): self
    {
        return new self(\sprintf('%.6F', $latitude), \sprintf('%.6F', $longitude));
    }

    /** @return array{latitude: string, longitude: string} */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
