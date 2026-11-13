<?php

namespace App\Controller;

use App\Geo\Coordinates;
use App\Geo\GeoServiceUnavailable;
use App\Geo\KnownPlaces;
use App\Geo\NominatimGeocoder;
use App\Geo\CitiesAlongRoute;
use App\Geo\OsrmRouter;
use App\Geo\PriceSuggester;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aide à la saisie d'un trajet. Pendant la frappe, seules les données de l'application sont consultées ;
 * Nominatim et OSRM ne sont appelés que sur action du conducteur (« Rechercher », clic sur la carte, étape suivante).
 * Les services externes sont interrogés côté serveur, avec cache et limitation de débit.
 */
#[IsGranted('ROLE_USER')]
final class GeoController extends AbstractController
{
    private const MAX_STOPS = 10;
    private const UNAVAILABLE = 'La carte est momentanément indisponible : saisissez l\'adresse et les coordonnées vous-même, et choisissez la ville dans la liste.';

    /**
     * Suggestions pendant la frappe : villes connues et points de rendez-vous déjà utilisés,
     * tirés de la base de l'application. Aucun service externe n'est appelé.
     */
    #[Route('/aide-saisie/lieux-connus', name: 'app_geo_known_places', methods: ['GET'])]
    public function knownPlaces(Request $request, KnownPlaces $known): JsonResponse
    {
        $text = trim($request->query->getString('q'));

        if (mb_strlen($text) < 2 || mb_strlen($text) > 200) {
            return $this->json(['places' => []]);
        }

        return $this->json(['places' => $known->search($text)]);
    }

    /** Lieux du Maroc correspondant au texte saisi : gares, adresses, quartiers, villes. */
    #[Route('/aide-saisie/lieux', name: 'app_geo_places', methods: ['GET'])]
    public function places(Request $request, NominatimGeocoder $geocoder): JsonResponse
    {
        $text = trim($request->query->getString('q'));

        if (mb_strlen($text) < 2 || mb_strlen($text) > 200) {
            return $this->json(['error' => 'Saisissez le nom d\'un lieu ou d\'une ville.'], 400);
        }

        try {
            $places = $geocoder->search($text);
        } catch (GeoServiceUnavailable) {
            return $this->json(['error' => self::UNAVAILABLE], 503);
        }

        return $this->json(['places' => array_map(static fn ($place) => $place->toArray(), $places)]);
    }

    /**
     * Localité contenant un point ; avec « describe », nom et adresse du lieu précis (clic sur la carte).
     * N'enregistre rien : la ville de l'étape est déterminée à nouveau par le serveur à la publication.
     */
    #[Route('/aide-saisie/localite', name: 'app_geo_locality', methods: ['GET'])]
    public function locality(Request $request, NominatimGeocoder $geocoder): JsonResponse
    {
        $point = $this->parsePoint($request->query->getString('lat'), $request->query->getString('lon'));

        if (null === $point) {
            return $this->json(['error' => 'Coordonnées invalides.'], 400);
        }

        try {
            $locality = $geocoder->localityAt($point);

            if (null === $locality) {
                return $this->json(['error' => 'Ce point n\'est situé dans aucune localité du Maroc : choisissez un autre lieu.'], 422);
            }

            $place = $request->query->getBoolean('describe') ? $geocoder->placeAt($point) : null;
        } catch (GeoServiceUnavailable) {
            return $this->json(['error' => self::UNAVAILABLE], 503);
        }

        return $this->json([
            'city' => $locality->name,
            'place' => null === $place ? null : ['name' => $place->name, 'address' => $place->address] + $point->toArray(),
        ]);
    }

    #[Route('/aide-saisie/itineraire', name: 'app_geo_route', methods: ['GET'])]
    public function route(Request $request, OsrmRouter $router, PriceSuggester $pricing, CitiesAlongRoute $citiesAlong): JsonResponse
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
            // Durées de conduite par tronçon : l'assistant en déduit les horaires des étapes, modifiables.
            'durations' => $route->legSeconds,
            'totalMeters' => $route->totalMeters(),
            'suggestedPriceCents' => $pricing->suggestCents($route->totalMeters()),
            'suggestedPrice' => \sprintf('%d,00', intdiv($pricing->suggestCents($route->totalMeters()), 100)),
            'path' => $route->path,
            // Villes connues le long du tracé, proposées comme arrêts : calcul local, sans appel externe.
            'citiesAlong' => $request->query->getBoolean('along') ? $citiesAlong->find($route->path) : [],
        ]);
    }

    private function parsePoint(string $latitude, string $longitude): ?Coordinates
    {
        if (!is_numeric($latitude) || !is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return null;
        }

        return Coordinates::fromFloats((float) $latitude, (float) $longitude);
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
            $point = 2 === \count($values) ? $this->parsePoint(trim($values[0]), trim($values[1])) : null;

            if (null === $point) {
                return null;
            }

            $points[] = $point;
        }

        return $points;
    }
}
