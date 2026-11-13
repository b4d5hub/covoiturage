<?php

namespace App\Geo;

use App\Entity\City;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Villes connues situées le long d'un tracé, proposées comme arrêts possibles lors de la publication.
 * Calcul local sur les centres des villes déjà enregistrées : aucun appel à un service externe.
 */
final class CitiesAlongRoute
{
    /** Distance maximale entre le centre d'une ville et le tracé. */
    public const MAX_DETOUR_METERS = 10000;

    /** Rayon autour du départ et de l'arrivée dans lequel une ville n'est pas proposée. */
    private const ENDPOINT_RADIUS_METERS = 12000;

    private const LIMIT = 8;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<array{float, float}> $path tracé en [latitude, longitude], du départ à l'arrivée
     *
     * @return list<array{name: string, latitude: string, longitude: string, along: float}> villes dans l'ordre du trajet ;
     *                                                                                       « along » va de 0 (départ) à 1 (arrivée)
     */
    public function find(array $path): array
    {
        if (\count($path) < 2) {
            return [];
        }

        // Distance cumulée à chaque point du tracé, pour situer chaque ville dans l'ordre du trajet.
        $cumulative = [0.0];
        for ($i = 1, $n = \count($path); $i < $n; ++$i) {
            $cumulative[] = $cumulative[$i - 1] + self::meters($path[$i - 1], $path[$i]);
        }
        $total = end($cumulative);

        if ($total <= 0) {
            return [];
        }

        $start = $path[0];
        $end = $path[\count($path) - 1];
        $found = [];

        /** @var City $city */
        foreach ($this->entityManager->getRepository(City::class)->findAll() as $city) {
            if (!$city->hasCentre()) {
                continue;
            }

            $centre = [(float) $city->getLatitude(), (float) $city->getLongitude()];

            if (self::meters($centre, $start) < self::ENDPOINT_RADIUS_METERS || self::meters($centre, $end) < self::ENDPOINT_RADIUS_METERS) {
                continue;
            }

            // Distance au tracé mesurée sur chaque tronçon, pas seulement aux points : le tracé simplifié
            // d'OSRM n'a qu'un point tous les quelques kilomètres.
            $best = \INF;
            $at = 0.0;
            for ($i = 1, $n = \count($path); $i < $n; ++$i) {
                [$distance, $fraction] = self::toSegment($centre, $path[$i - 1], $path[$i]);
                if ($distance < $best) {
                    $best = $distance;
                    $at = $cumulative[$i - 1] + $fraction * ($cumulative[$i] - $cumulative[$i - 1]);
                }
            }

            if ($best <= self::MAX_DETOUR_METERS) {
                $found[] = [
                    'name' => (string) $city->getName(),
                    'latitude' => (string) $city->getLatitude(),
                    'longitude' => (string) $city->getLongitude(),
                    'along' => round($at / $total, 4),
                ];
            }
        }

        usort($found, static fn (array $a, array $b) => $a['along'] <=> $b['along']);

        return \array_slice($found, 0, self::LIMIT);
    }

    /**
     * Distance d'un point à un tronçon, en projection locale (suffisante à l'échelle de quelques kilomètres),
     * et position du point le plus proche sur le tronçon, de 0 à 1.
     *
     * @return array{float, float}
     */
    private static function toSegment(array $p, array $a, array $b): array
    {
        $k = cos($p[0] * \M_PI / 180);
        $ax = ($a[1] - $p[1]) * $k;
        $ay = $a[0] - $p[0];
        $bx = ($b[1] - $p[1]) * $k;
        $by = $b[0] - $p[0];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $length = $dx * $dx + $dy * $dy;
        $t = $length > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $length)) : 0.0;
        $x = $ax + $t * $dx;
        $y = $ay + $t * $dy;

        // Un degré de latitude vaut environ 111,2 km.
        return [sqrt($x * $x + $y * $y) * 111195, $t];
    }

    /** Distance à vol d'oiseau, formule de haversine. */
    private static function meters(array $a, array $b): float
    {
        $rad = \M_PI / 180;
        $dLat = ($b[0] - $a[0]) * $rad;
        $dLon = ($b[1] - $a[1]) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($a[0] * $rad) * cos($b[0] * $rad) * sin($dLon / 2) ** 2;

        return 2 * 6371000 * asin(min(1.0, sqrt($h)));
    }
}
