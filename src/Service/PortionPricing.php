<?php

namespace App\Service;

use App\Entity\Trip;
use App\Entity\TripStop;

/**
 * Prix d'une portion, proportionnel à la distance routière, en arithmétique entière.
 */
final class PortionPricing
{
    /** Prix d'une place entre deux étapes, arrondi au centime le plus proche. */
    public function seatPriceCents(Trip $trip, TripStop $boarding, TripStop $alighting): int
    {
        $total = 0;
        $portion = 0;

        foreach ($trip->getSegments() as $segment) {
            $total += $segment->getDistanceMeters();

            if ($segment->getPosition() >= $boarding->getPosition() && $segment->getPosition() < $alighting->getPosition()) {
                $portion += $segment->getDistanceMeters();
            }
        }

        return intdiv(2 * $trip->getFullPriceCents() * $portion + $total, 2 * $total);
    }
}
