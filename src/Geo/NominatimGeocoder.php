<?php

namespace App\Geo;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Recherche de lieux et géocodage inverse avec Nominatim, dans le respect de sa politique d'usage :
 * une requête par seconde au plus pour toute l'application, résultats mis en cache,
 * User-Agent identifiant l'application (client configuré dans config/packages/geo.yaml),
 * appel uniquement sur action explicite de l'utilisateur (pas d'autocomplétion).
 * Les recherches sont limitées au Maroc et les noms demandés en français.
 */
final class NominatimGeocoder
{
    private const CACHE_TTL = 2592000; // 30 jours

    /** Niveaux de Nominatim acceptés comme localité d'une étape. */
    private const LOCALITY_TYPES = ['city', 'town', 'village', 'municipality', 'hamlet'];

    /** Préfixes administratifs retirés du nom affiché (« Pachalik de Safi » devient « Safi »). */
    private const ADMINISTRATIVE_PREFIX = '/^(pachalik|commune(\s+urbaine|\s+rurale)?|municipalité|cercle|arrondissement)\s+(de\s+la\s+|de\s+l\'|du\s+|des\s+|de\s+|d\')?/iu';

    /** Régions du Maroc, retirées des adresses affichées. */
    private const REGIONS = [
        'Tanger-Tétouan-Al Hoceïma', 'L\'Oriental', 'Oriental', 'Fès-Meknès', 'Rabat-Salé-Kénitra', 'Béni Mellal-Khénifra',
        'Casablanca-Settat', 'Marrakech-Safi', 'Drâa-Tafilalet', 'Souss-Massa', 'Guelmim-Oued Noun',
        'Laâyoune-Sakia El Hamra', 'Dakhla-Oued Ed-Dahab',
    ];

    public function __construct(
        #[Target('nominatim.client')]
        private readonly HttpClientInterface $client,
        private readonly CacheInterface $cache,
        #[Target('nominatim')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Lieux du Maroc correspondant au texte saisi.
     *
     * @return list<Place>
     *
     * @throws GeoServiceUnavailable
     */
    public function search(string $text, int $limit = 5): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        $results = $this->cached('search_'.$limit.'_'.sha1(mb_strtolower($text)), '/search', [
            'q' => $text, 'format' => 'jsonv2', 'limit' => $limit, 'countrycodes' => 'ma', 'accept-language' => 'fr',
        ]);

        // Un même lieu peut figurer deux fois (point et contour d'une gare) : un seul résultat par nom et adresse.
        $places = [];

        foreach (array_filter(array_map(self::place(...), $results)) as $place) {
            $places[mb_strtolower($place->name.'|'.$place->address)] ??= $place;
        }

        return array_values($places);
    }

    /**
     * Lieu précis situé à ce point (adresse, bâtiment, rue), pour nommer un clic sur la carte.
     *
     * @throws GeoServiceUnavailable
     */
    public function placeAt(Coordinates $point): ?Place
    {
        $result = $this->reverse($point, 18);

        return 'ma' === ($result['address']['country_code'] ?? null) ? self::place($result) : null;
    }

    /**
     * Localité (ville, commune ou village) du Maroc contenant ce point, ou null s'il n'y en a pas.
     *
     * @throws GeoServiceUnavailable
     */
    public function localityAt(Coordinates $point): ?Place
    {
        $result = $this->reverse($point, 10);

        if ('ma' !== ($result['address']['country_code'] ?? null) || !\in_array($result['addresstype'] ?? null, self::LOCALITY_TYPES, true)) {
            return null;
        }

        $place = self::place($result);

        if (null === $place) {
            return null;
        }

        $name = trim(preg_replace(self::ADMINISTRATIVE_PREFIX, '', $place->name));

        return new Place(mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1), $place->address, $place->point, $place->osmType, $place->osmId);
    }

    /** @return array<string, mixed> */
    private function reverse(Coordinates $point, int $zoom): array
    {
        return $this->cached('reverse_'.$zoom.'_'.$point->latitude.'_'.$point->longitude, '/reverse', [
            'lat' => $point->latitude, 'lon' => $point->longitude, 'zoom' => $zoom,
            'format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => 'fr',
        ]);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function cached(string $key, string $path, array $query): array
    {
        try {
            return $this->cache->get('nominatim_'.$key, function (ItemInterface $item) use ($path, $query): array {
                $item->expiresAfter(self::CACHE_TTL);
                $this->limiter->create('nominatim')->reserve(1)->wait();

                $result = $this->client->request('GET', $path, ['query' => $query])->toArray();

                // « Unable to geocode » : aucun lieu à cet endroit, résultat conservé comme tel.
                return isset($result['error']) ? [] : $result;
            });
        } catch (ExceptionInterface $exception) {
            // Indisponibilité : rien n'est mis en cache, la saisie manuelle reste possible.
            $this->logger->warning('Nominatim indisponible : {message}', ['message' => $exception->getMessage()]);

            throw new GeoServiceUnavailable('Service de géocodage indisponible.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $result */
    private static function place(mixed $result): ?Place
    {
        if (!\is_array($result) || !isset($result['lat'], $result['lon'], $result['osm_type'], $result['osm_id']) || !\in_array($result['osm_type'], ['node', 'way', 'relation'], true)) {
            return null;
        }

        // display_name va du plus précis au plus général : on garde le début, sans découpage
        // administratif (pachalik, préfecture, région…), code postal ni pays.
        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string) ($result['display_name'] ?? ''))),
            static fn (string $part) => '' !== $part && 'Maroc' !== $part && !preg_match('/^\d+$/', $part)
                && !preg_match('/^(pachalik|préfecture|province|cercle|caïdat|arrondissement|commune|municipalité|région)\b/iu', $part)
                && !\in_array($part, self::REGIONS, true),
        ));
        $name = trim((string) ($result['name'] ?? '')) ?: ($parts[0] ?? '');

        if ('' === $name) {
            return null;
        }

        return new Place(
            mb_substr($name, 0, 100),
            mb_substr(implode(', ', \array_slice($parts, 0, 4)), 0, 255),
            Coordinates::fromFloats((float) $result['lat'], (float) $result['lon']),
            $result['osm_type'],
            (string) $result['osm_id'],
        );
    }
}
