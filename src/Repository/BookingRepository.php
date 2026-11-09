<?php

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Enum\BookingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Places occupées sur chaque segment, indexées par rang de segment.
     *
     * À appeler après avoir verrouillé le trajet. La lecture verrouillante (FOR SHARE)
     * voit le dernier état validé, y compris sous REPEATABLE READ après une lecture antérieure.
     *
     * @return array<int, int>
     */
    public function occupiedSeatsBySegment(Trip $trip): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT s.position AS segment_position, COALESCE(SUM(b.seats), 0) AS occupied
                FROM trip_segment s
                LEFT JOIN (
                    booking b
                    INNER JOIN trip_stop boarding ON boarding.id = b.boarding_stop_id
                    INNER JOIN trip_stop alighting ON alighting.id = b.alighting_stop_id
                ) ON b.trip_id = s.trip_id
                    AND b.status = :confirmed
                    AND boarding.position <= s.position
                    AND alighting.position > s.position
                WHERE s.trip_id = :trip
                GROUP BY s.position
                ORDER BY s.position
                FOR SHARE
                SQL,
            ['confirmed' => BookingStatus::Confirmed->value, 'trip' => $trip->getId()],
        );

        $occupied = [];

        foreach ($rows as $row) {
            $occupied[(int) $row['segment_position']] = (int) $row['occupied'];
        }

        return $occupied;
    }

    /** Occupation maximale parmi les segments du trajet. */
    public function peakOccupiedSeats(Trip $trip): int
    {
        return max([0, ...array_values($this->occupiedSeatsBySegment($trip))]);
    }
}
