<?php

namespace App\Geo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prix suggéré d'une place : distance routière × tarif au kilomètre, arrondi au dirham.
 * Une simple suggestion : le conducteur reste libre de fixer son prix.
 */
final class PriceSuggester
{
    public function __construct(
        #[Autowire(env: 'int:PRICE_PER_KM_CENTS')]
        private readonly int $centsPerKm,
    ) {
    }

    public function suggestCents(int $meters): int
    {
        // mètres × centimes/km / 1000 = centimes, arrondis à la centaine de centimes la plus proche.
        return intdiv($meters * $this->centsPerKm + 50000, 100000) * 100;
    }

    public function centsPerKm(): int
    {
        return $this->centsPerKm;
    }
}
