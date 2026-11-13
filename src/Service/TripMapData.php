<?php

namespace App\Service;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Geo\Coordinates;
use App\Geo\OsrmRouter;

/**
 * Données de la carte d'un trajet : étapes et tracé OSRM, ou lignes droites entre les étapes
 * si le service ne répond pas. Utilisé par la page du trajet et par l'accueil.
 */
final class TripMapData
{
    public function __construct(private readonly OsrmRouter $router)
    {
    }

    /**
     * @return array{stops: list<array{latitude: float, longitude: float, label: string}>, path: list<array{0: float, 1: float}>, approximate: bool}
     */
    public function of(Trip $trip): array
    {
        $stops = $trip->getStops()->toArray();
        usort($stops, static fn (TripStop $a, TripStop $b) => $a->getPosition() <=> $b->getPosition());

        $points = array_map(static fn (TripStop $stop) => new Coordinates($stop->getLatitude(), $stop->getLongitude()), $stops);
        $route = $this->router->route($points);

        return [
            'stops' => array_map(static fn (TripStop $stop) => [
                'latitude' => (float) $stop->getLatitude(),
                'longitude' => (float) $stop->getLongitude(),
                'label' => $stop->getCity().' – '.$stop->getMeetingAddress(),
            ], $stops),
            'path' => $route?->path ?: array_map(static fn (TripStop $stop) => [(float) $stop->getLatitude(), (float) $stop->getLongitude()], $stops),
            'approximate' => null === $route,
        ];
    }
}
