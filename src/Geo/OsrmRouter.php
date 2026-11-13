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
 * Distances routières et tracé avec OSRM, dans le respect de l'usage du serveur de démonstration :
 * une requête par seconde au plus, résultats mis en cache, User-Agent identifiant l'application.
 */
final class OsrmRouter
{
    private const CACHE_TTL = 604800; // 7 jours

    public function __construct(
        #[Target('osrm.client')]
        private readonly HttpClientInterface $client,
        private readonly CacheInterface $cache,
        #[Target('osrm')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Coordinates> $points étapes dans l'ordre du trajet
     *
     * @return Route|null null si aucun itinéraire n'est trouvé ou si le service est indisponible
     */
    public function route(array $points): ?Route
    {
        if (\count($points) < 2) {
            return null;
        }

        // OSRM attend « longitude,latitude ».
        $path = implode(';', array_map(static fn (Coordinates $c) => $c->longitude.','.$c->latitude, $points));

        try {
            $result = $this->cache->get('osrm_route_'.sha1($path), function (ItemInterface $item) use ($path): array {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->request($path);
            });
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('OSRM indisponible : {message}', ['message' => $exception->getMessage()]);

            return null;
        }

        // Les itinéraires mis en cache avant l'ajout des durées n'en ont pas.
        return [] === $result ? null : new Route($result['legs'], $result['path'], $result['durations'] ?? []);
    }

    /** @return array{legs?: list<int>, durations?: list<int>, path?: list<array{float, float}>} vide si aucun itinéraire */
    private function request(string $path): array
    {
        $this->limiter->create('osrm')->reserve(1)->wait();

        $data = $this->client->request('GET', '/route/v1/driving/'.$path, [
            // Tracé simplifié : suffisant pour la carte et beaucoup plus léger que le tracé complet.
            'query' => ['overview' => 'simplified', 'geometries' => 'geojson'],
        ])->toArray(false);

        if ('Ok' !== ($data['code'] ?? null) || !isset($data['routes'][0]['legs'])) {
            return [];
        }

        $route = $data['routes'][0];

        return [
            'legs' => array_map(static fn (array $leg): int => (int) round($leg['distance']), $route['legs']),
            'durations' => array_map(static fn (array $leg): int => (int) round($leg['duration'] ?? 0), $route['legs']),
            'path' => array_map(static fn (array $point): array => [round((float) $point[1], 5), round((float) $point[0], 5)], $route['geometry']['coordinates'] ?? []),
        ];
    }
}
