<?php

namespace App\Controller;

use App\Entity\City;
use App\Geo\Coordinates;
use App\Geo\NominatimGeocoder;
use App\Geo\OsrmRouter;
use App\Geo\PriceSuggester;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aide à la saisie d'un trajet, appelée par le formulaire de publication sur clic du conducteur.
 * Les services externes sont interrogés côté serveur, avec cache et limitation de débit.
 */
#[IsGranted('ROLE_USER')]
final class GeoController extends AbstractController
{
    private const MAX_STOPS = 10;

    #[Route('/aide-saisie/coordonnees', name: 'app_geo_geocode', methods: ['GET'])]
    public function geocode(Request $request, EntityManagerInterface $entityManager, NominatimGeocoder $geocoder): JsonResponse
    {
        $city = $entityManager->find(City::class, $request->query->getInt('city'));
        $address = trim($request->query->getString('address'));

        if (null === $city || '' === $address || mb_strlen($address) > 255) {
            return $this->json(['error' => 'Choisissez une ville et indiquez l\'adresse du point de rendez-vous.'], 400);
        }

        $coordinates = $geocoder->geocode($address, $city);

        if (null === $coordinates) {
            return $this->json(['error' => 'Adresse introuvable ou service de géocodage indisponible : saisissez les coordonnées vous-même.'], 404);
        }

        return $this->json(['latitude' => $coordinates->latitude, 'longitude' => $coordinates->longitude]);
    }

    #[Route('/aide-saisie/itineraire', name: 'app_geo_route', methods: ['GET'])]
    public function route(Request $request, OsrmRouter $router, PriceSuggester $pricing): JsonResponse
    {
        $points = $this->parsePoints($request->query->getString('points'));

        if (null === $points) {
            return $this->json(['error' => 'Indiquez les coordonnées valides d\'au moins deux étapes.'], 400);
        }

        $route = $router->route($points);

        if (null === $route) {
            return $this->json(['error' => 'Itinéraire introuvable ou service de calcul indisponible : saisissez les distances vous-même.'], 404);
        }

        return $this->json([
            'distances' => $route->legMeters,
            'totalMeters' => $route->totalMeters(),
            'suggestedPriceCents' => $pricing->suggestCents($route->totalMeters()),
            'suggestedPrice' => \sprintf('%d,00', intdiv($pricing->suggestCents($route->totalMeters()), 100)),
        ]);
    }

    /** @return list<Coordinates>|null */
    private function parsePoints(string $raw): ?array
    {
        $parts = array_filter(explode(';', $raw), static fn (string $part) => '' !== trim($part));

        if (\count($parts) < 2 || \count($parts) > self::MAX_STOPS) {
            return null;
        }

        $points = [];

        foreach ($parts as $part) {
            $values = explode(',', $part);

            if (2 !== \count($values) || !is_numeric($values[0]) || !is_numeric($values[1])) {
                return null;
            }

            [$latitude, $longitude] = array_map('floatval', $values);

            if (abs($latitude) > 90 || abs($longitude) > 180) {
                return null;
            }

            $points[] = Coordinates::fromFloats($latitude, $longitude);
        }

        return $points;
    }
}
