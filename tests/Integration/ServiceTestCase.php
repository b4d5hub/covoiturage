<?php

namespace App\Tests\Integration;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Service\BookingService;
use App\Service\PortionPricing;
use App\Service\ReviewService;
use App\Service\SimulatedPayment;
use App\Service\TripLifecycleService;
use App\Tests\Support\TestData;

abstract class ServiceTestCase extends DatabaseTestCase
{
    protected BookingService $bookingService;
    protected TripLifecycleService $tripService;
    protected ReviewService $reviewService;
    protected BookingRepository $bookingRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingRepository = self::getContainer()->get(BookingRepository::class);
        $payments = new SimulatedPayment($this->em);
        $this->bookingService = new BookingService($this->em, $this->bookingRepository, new PortionPricing(), $payments);
        $this->tripService = new TripLifecycleService($this->em, $this->bookingRepository, $payments);
        $this->reviewService = new ReviewService($this->em, $this->bookingRepository);
    }

    protected function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    protected function passenger(): User
    {
        $user = TestData::user();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Réservation valide, effectuée avant le départ du trajet de test (le 1er décembre 2026). */
    protected function book(Trip $trip, ?User $passenger = null, int $boarding = 0, int $alighting = 2, int $seats = 1): Booking
    {
        return $this->bookingService->book(
            $trip,
            $passenger ?? $this->passenger(),
            $trip->getStopAt($boarding),
            $trip->getStopAt($alighting),
            $seats,
            $this->at('2026-11-20 10:00'),
        );
    }

    protected function sql(string $query, array $params = []): mixed
    {
        return $this->connection->fetchOne($query, $params);
    }
}
