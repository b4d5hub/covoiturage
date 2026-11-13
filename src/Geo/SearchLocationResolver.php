<?php

namespace App\Geo;

use App\Entity\City;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Interprète le départ ou l'arrivée saisi par un passager : le nom d'une ville connue
 * est utilisé directement, sans appel externe ; sinon le lieu est cherché dans Nominatim.
 */
final class SearchLocationResolver
{
    public function __construct(
        private readonly NominatimGeocoder $geocoder,
        private readonly CityResolver $cities,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Null si le lieu est introuvable.
     *
     * @throws GeoServiceUnavailable seulement si le texte n'est pas une ville connue
     */
    public function resolve(string $text): ?SearchLocation
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        if ('' === $text) {
            return null;
        }

        // Comparaison sans accents ni casse (collation de la colonne) : « fes » trouve « Fès ».
        $known = $this->entityManager->getRepository(City::class)->findBy(['name' => $text]);

        if (1 === \count($known)) {
            $city = $known[0];

            return new SearchLocation((string) $city, $city, $city->hasCentre() ? new Coordinates($city->getLatitude(), $city->getLongitude()) : null);
        }

        $place = $this->geocoder->search($text, 1)[0] ?? null;

        if (null === $place) {
            return null;
        }

        try {
            $locality = $this->geocoder->localityAt($place->point);
        } catch (GeoServiceUnavailable) {
            // Lieu trouvé mais ville inconnue : la recherche se fait alors seulement dans le rayon.
            $locality = null;
        }

        $city = null === $locality ? null : $this->cities->knownCity($locality);
        $label = null === $locality || $locality->name === $place->name ? $place->name : $place->name.', '.$locality->name;

        return new SearchLocation($label, $city, $place->point);
    }
}
