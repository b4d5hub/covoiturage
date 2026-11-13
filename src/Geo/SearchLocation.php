<?php

namespace App\Geo;

use App\Entity\City;

/**
 * Départ ou arrivée d'une recherche : la ville et/ou le point autour duquel chercher.
 */
final readonly class SearchLocation
{
    public function __construct(
        public string $label,
        public ?City $city,
        public ?Coordinates $point,
    ) {
    }
}
