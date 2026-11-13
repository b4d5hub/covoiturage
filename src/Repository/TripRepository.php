<?php

namespace App\Repository;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Geo\SearchLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trip>
 */
class TripRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trip::class);
    }

    /** Rayon de recherche autour du point saisi, en mètres. */
    public const SEARCH_RADIUS_METERS = 5000;

    /**
     * Trajets publiés dont une étape de montée est dans la ville de départ ou à 5 km au plus
     * du point de départ, puis une étape de descente dans la ville d'arrivée ou à 5 km au plus
     * du point d'arrivée ; le départ de la montée a lieu dans l'intervalle donné et est encore à venir.
     * Pour chaque trajet, la paire d'étapes la plus proche des deux points est retenue.
     * Résultats triés par distance du point de montée, puis par heure de départ.
     *
     * @return list<array{trip: Trip, boarding: TripStop, alighting: TripStop, boardingMeters: ?int, alightingMeters: ?int}>
     */
    public function search(SearchLocation $from, SearchLocation $to, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $now): array
    {
        $parameters = [
            'published' => TripStatus::Published->value,
            'start' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end' => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
        [$boardingMatch, $boardingDistance] = self::nearCondition('b', 'from', $from, $parameters);
        [$alightingMatch, $alightingDistance] = self::nearCondition('a', 'to', $to, $parameters);

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT b.trip_id, b.id AS boarding_id, a.id AS alighting_id, b.departure_at,
                    $boardingDistance AS boarding_meters, $alightingDistance AS alighting_meters
             FROM trip_stop b
             JOIN trip_stop a ON a.trip_id = b.trip_id AND a.position > b.position
             JOIN trip t ON t.id = b.trip_id
             WHERE t.status = :published
               AND b.departure_at >= :start AND b.departure_at < :end AND b.departure_at > :now
               AND ($boardingMatch) AND ($alightingMatch)",
            $parameters,
        );

        // Une seule paire d'étapes par trajet : la plus proche des deux points recherchés.
        $best = [];

        foreach ($rows as $row) {
            $score = (float) ($row['boarding_meters'] ?? 0) + (float) ($row['alighting_meters'] ?? 0);

            if (!isset($best[$row['trip_id']]) || $score < $best[$row['trip_id']]['score']) {
                $best[$row['trip_id']] = $row + ['score' => $score];
            }
        }

        usort($best, static fn (array $x, array $y) => [(float) ($x['boarding_meters'] ?? 0), $x['departure_at'], (int) $x['trip_id']]
            <=> [(float) ($y['boarding_meters'] ?? 0), $y['departure_at'], (int) $y['trip_id']]);

        $stops = $this->getEntityManager()->getRepository(TripStop::class);

        return array_map(fn (array $row): array => [
            'trip' => $this->find($row['trip_id']),
            'boarding' => $stops->find($row['boarding_id']),
            'alighting' => $stops->find($row['alighting_id']),
            'boardingMeters' => null === $row['boarding_meters'] ? null : (int) round((float) $row['boarding_meters']),
            'alightingMeters' => null === $row['alighting_meters'] ? null : (int) round((float) $row['alighting_meters']),
        ], $best);
    }

    /**
     * Condition « dans la ville ou dans le rayon » pour une étape, et expression de sa distance au point.
     * Un rectangle sur la latitude et la longitude (indexées) écarte d'abord les étapes lointaines.
     *
     * @param array<string, scalar> $parameters
     *
     * @return array{string, string}
     */
    private static function nearCondition(string $alias, string $prefix, SearchLocation $location, array &$parameters): array
    {
        $conditions = [];
        $distance = 'NULL';

        if (null !== $location->city) {
            $conditions[] = "$alias.city_id = :{$prefix}_city";
            $parameters[$prefix.'_city'] = $location->city->getId();
        }

        if (null !== $location->point) {
            $latitude = (float) $location->point->latitude;
            $longitude = (float) $location->point->longitude;
            $latitudeDelta = self::SEARCH_RADIUS_METERS / 111320;
            $longitudeDelta = self::SEARCH_RADIUS_METERS / (111320 * max(0.01, cos(deg2rad($latitude))));

            $distance = "ST_Distance_Sphere(POINT($alias.longitude, $alias.latitude), POINT(:{$prefix}_lon, :{$prefix}_lat))";
            $conditions[] = "($alias.latitude BETWEEN :{$prefix}_lat_min AND :{$prefix}_lat_max
                AND $alias.longitude BETWEEN :{$prefix}_lon_min AND :{$prefix}_lon_max
                AND $distance <= :radius)";
            $parameters += [
                $prefix.'_lat' => $latitude,
                $prefix.'_lon' => $longitude,
                $prefix.'_lat_min' => $latitude - $latitudeDelta,
                $prefix.'_lat_max' => $latitude + $latitudeDelta,
                $prefix.'_lon_min' => $longitude - $longitudeDelta,
                $prefix.'_lon_max' => $longitude + $longitudeDelta,
                'radius' => self::SEARCH_RADIUS_METERS,
            ];
        }

        return [[] === $conditions ? '0 = 1' : implode(' OR ', $conditions), $distance];
    }

    /** @return list<Trip> prochains départs publiés */
    public function findUpcoming(\DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.stops', 'first', 'WITH', 'first.position = 0')
            ->andWhere('t.status = :published')
            ->andWhere('first.departureAt > :now')
            ->orderBy('first.departureAt')
            ->setParameter('published', TripStatus::Published)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Trip> trajets dont l'utilisateur est le conducteur */
    public function findForDriver(User $driver): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.vehicle', 'v')
            ->join('t.stops', 'first', 'WITH', 'first.position = 0')
            ->andWhere('v.owner = :driver')
            ->orderBy('first.departureAt', 'DESC')
            ->setParameter('driver', $driver)
            ->getQuery()
            ->getResult();
    }

    public function countForDriver(User $driver, TripStatus $status): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.vehicle', 'v')
            ->andWhere('v.owner = :driver')
            ->andWhere('t.status = :status')
            ->setParameter('driver', $driver)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
