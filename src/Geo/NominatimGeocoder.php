<?php

namespace App\Geo;

use App\Entity\City;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Géocodage d'un point de rendez-vous avec Nominatim, dans le respect de sa politique d'usage :
 * une requête par seconde au plus pour toute l'application, résultats mis en cache,
 * User-Agent identifiant l'application (client configuré dans config/packages/geo.yaml),
 * appel uniquement sur action explicite de l'utilisateur (pas d'autocomplétion).
 */
final class NominatimGeocoder
{
    private const CACHE_TTL = 2592000; // 30 jours

    public function __construct(
        #[Target('nominatim.client')]
        private readonly HttpClientInterface $client,
        private readonly CacheInterface $cache,
        #[Target('nominatim')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Retourne null si l'adresse est introuvable ou si le service est indisponible. */
    public function geocode(string $address, City $city): ?Coordinates
    {
        $query = trim($address).', '.$city->getName().', Maroc';

        try {
            $result = $this->cache->get('nominatim_'.sha1(mb_strtolower($query)), function (ItemInterface $item) use ($query): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->request($query);
            });
        } catch (ExceptionInterface $exception) {
            // Indisponibilité : rien n'est mis en cache, la saisie manuelle reste possible.
            $this->logger->warning('Nominatim indisponible : {message}', ['message' => $exception->getMessage()]);

            return null;
        }

        return [] === $result ? null : Coordinates::fromFloats($result['lat'], $result['lon']);
    }

    /** @return array{lat?: float, lon?: float} vide si l'adresse est introuvable */
    private function request(string $query): array
    {
        $this->limiter->create('nominatim')->reserve(1)->wait();

        $results = $this->client->request('GET', '/search', [
            'query' => ['q' => $query, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'ma'],
        ])->toArray();

        if (!isset($results[0]['lat'], $results[0]['lon'])) {
            return [];
        }

        return ['lat' => (float) $results[0]['lat'], 'lon' => (float) $results[0]['lon']];
    }
}
