<?php

namespace App\Service;

use App\Entity\Trip;
use App\Exception\DomainRuleException;
use App\Repository\BookingRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Modifie la capacité et le prix d'un trajet enregistré, sous le verrou du trajet.
 */
final class TripCapacityManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
    ) {
    }

    /**
     * Places et prix du trajet entier, modifiés ensemble sous le verrou du trajet. Le nouveau prix ne concerne
     * que les réservations à venir : les montants déjà enregistrés ne changent pas.
     */
    public function changeTerms(Trip $trip, int $seatCapacity, int $fullPriceCents): void
    {
        if ($fullPriceCents <= 0) {
            throw new DomainRuleException('Le prix doit être positif.');
        }

        $this->entityManager->getConnection()->transactional(function () use ($trip, $seatCapacity, $fullPriceCents): void {
            $this->entityManager->refresh($trip, LockMode::PESSIMISTIC_WRITE);

            if ($seatCapacity !== $trip->getSeatCapacity()) {
                $trip->changeSeatCapacity($seatCapacity, $this->bookings->peakOccupiedSeats($trip));
            }

            $trip->setFullPriceCents($fullPriceCents);
            $this->entityManager->flush();
        });
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
