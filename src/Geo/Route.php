<?php

namespace App\Geo;

/**
 * Itinéraire routier : distance et durée de chaque tronçon, et tracé à afficher sur la carte.
 */
final readonly class Route
{
    /**
     * @param list<int>                 $legMeters distance de chaque tronçon, en mètres entiers
     * @param list<array{float, float}> $path      points du tracé, en [latitude, longitude]
     * @param list<int>                 $legSeconds durée de chaque tronçon, en secondes ; vide si inconnue
     */
    public function __construct(
        public array $legMeters,
        public array $path,
        public array $legSeconds = [],
    ) {
    }

    public function totalMeters(): int
    {
        return array_sum($this->legMeters);
    }
}
