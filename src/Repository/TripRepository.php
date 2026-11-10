<?php

namespace App\Repository;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\TripStatus;
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

    /**
     * Trajets publiés passant par la ville de départ puis par la ville d'arrivée,
     * avec un départ de la première dans l'intervalle donné et encore à venir.
     *
     * @return list<array{trip: Trip, boarding: TripStop, alighting: TripStop}>
     */
    public function search(City $from, City $to, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeImmutable $now): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t', 'boarding.id AS boardingId', 'alighting.id AS alightingId')
            ->join('t.stops', 'boarding')
            ->join('t.stops', 'alighting')
            ->andWhere('boarding.city = :from')
            ->andWhere('alighting.city = :to')
            ->andWhere('boarding.position < alighting.position')
            ->andWhere('boarding.departureAt >= :start')
            ->andWhere('boarding.departureAt < :end')
            ->andWhere('boarding.departureAt > :now')
            ->andWhere('t.status = :published')
            ->orderBy('boarding.departureAt')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('start', $start, Types::DATETIME_IMMUTABLE)
            ->setParameter('end', $end, Types::DATETIME_IMMUTABLE)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('published', TripStatus::Published)
            ->getQuery()
            ->getResult();

        $stops = $this->getEntityManager()->getRepository(TripStop::class);

        return array_map(static fn (array $row): array => [
            'trip' => $row[0],
            'boarding' => $stops->find($row['boardingId']),
            'alighting' => $stops->find($row['alightingId']),
        ], $rows);
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
