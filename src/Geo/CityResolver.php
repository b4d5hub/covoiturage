<?php

namespace App\Geo;

use App\Entity\City;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ville d'une étape, déterminée par le serveur à partir des coordonnées du point de rendez-vous.
 * La ville est identifiée par son objet OpenStreetMap : créée à sa première utilisation, réutilisée ensuite.
 */
final class CityResolver
{
    public function __construct(
        private readonly NominatimGeocoder $geocoder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Ville du Maroc contenant ce point, ou null si le point n'est dans aucune localité.
     *
     * @throws GeoServiceUnavailable
     */
    public function cityAt(Coordinates $point): ?City
    {
        $locality = $this->geocoder->localityAt($point);

        return null === $locality ? null : $this->cityFor($locality);
    }

    /** Ville déjà connue pour cette localité, sans en créer. */
    public function knownCity(Place $locality): ?City
    {
        return $this->entityManager->getRepository(City::class)->findOneBy(['osmType' => $locality->osmType, 'osmId' => $locality->osmId]);
    }

    private function cityFor(Place $locality): City
    {
        $city = $this->knownCity($locality);

        if (null !== $city) {
            return $city;
        }

        // Deux publications simultanées peuvent découvrir la même localité : l'index unique
        // sur l'identifiant OSM garantit qu'une seule ville est créée, l'autre requête la retrouve.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO city (name, osm_type, osm_id, latitude, longitude) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$locality->name, $locality->osmType, $locality->osmId, $locality->point->latitude, $locality->point->longitude],
        );

        return $this->knownCity($locality) ?? throw new \LogicException('Ville introuvable après son enregistrement.');
    }
}
