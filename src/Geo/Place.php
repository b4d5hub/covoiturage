<?php

namespace App\Geo;

/**
 * Lieu renvoyé par Nominatim : un point de rendez-vous possible ou une localité.
 */
final readonly class Place
{
    public function __construct(
        public string $name,
        public string $address,
        public Coordinates $point,
        public string $osmType,
        public string $osmId,
    ) {
    }

    /** @return array{name: string, address: string, latitude: string, longitude: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'address' => $this->address, 'latitude' => $this->point->latitude, 'longitude' => $this->point->longitude];
    }
}
