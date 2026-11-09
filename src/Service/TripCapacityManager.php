<?php

namespace App\Service;

use App\Entity\Trip;
use App\Repository\BookingRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Modifie la capacité d'un trajet enregistré, sous le verrou du trajet.
 */
final class TripCapacityManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
    ) {
    }

    public function changeCapacity(Trip $trip, int $seatCapacity): void
    {
        $this->entityManager->getConnection()->transactional(function () use ($trip, $seatCapacity): void {
            $this->entityManager->refresh($trip, LockMode::PESSIMISTIC_WRITE);
            $trip->changeSeatCapacity($seatCapacity, $this->bookings->peakOccupiedSeats($trip));
            $this->entityManager->flush();
        });
    }
}
