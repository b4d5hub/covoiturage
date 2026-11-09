<?php

namespace App\Tests\Integration;

use App\Entity\Trip;
use App\Enum\BookingStatus;
use App\Exception\DomainRuleException;
use App\Repository\BookingRepository;
use App\Service\TripCapacityManager;
use App\Tests\Support\TestData;
use Doctrine\DBAL\DriverManager;

final class OccupancyAndCapacityTest extends DatabaseTestCase
{
    private BookingRepository $bookings;
    private TripCapacityManager $capacity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookings = self::getContainer()->get(BookingRepository::class);
        $this->capacity = new TripCapacityManager($this->em, $this->bookings);
    }

    private function book(Trip $trip, int $boarding, int $alighting, int $seats): \App\Entity\Booking
    {
        $passenger = TestData::user();
        $this->em->persist($passenger);
        $booking = TestData::booking($trip, $passenger, $boarding, $alighting, $seats);
        $this->em->persist($booking);
        $this->em->flush();

        return $booking;
    }

    public function testOccupancyIsCalculatedPerSegment(): void
    {
        $trip = $this->saveTrip(TestData::trip());

        self::assertSame([0 => 0, 1 => 0], $this->bookings->occupiedSeatsBySegment($trip));

        $this->book($trip, 0, 1, 2);
        self::assertSame([0 => 2, 1 => 0], $this->bookings->occupiedSeatsBySegment($trip));

        $this->book($trip, 1, 2, 1);
        self::assertSame([0 => 2, 1 => 1], $this->bookings->occupiedSeatsBySegment($trip));

        $this->book($trip, 0, 2, 1);
        self::assertSame([0 => 3, 1 => 2], $this->bookings->occupiedSeatsBySegment($trip));
    }

    public function testCancelledBookingsDoNotOccupySeats(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $booking = $this->book($trip, 0, 2, 2);
        $booking->cancelByPassenger(new \DateTimeImmutable('2026-11-01'));
        $this->em->flush();

        self::assertSame([0 => 0, 1 => 0], $this->bookings->occupiedSeatsBySegment($trip));
    }

    public function testBookingsOfOtherTripsAreIgnored(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $this->book($this->saveTrip(TestData::trip()), 0, 2, 3);

        self::assertSame(0, $this->bookings->peakOccupiedSeats($trip));
    }

    public function testCapacityCanBeReducedDownToPeakOccupancy(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $this->book($trip, 0, 1, 2);

        $this->capacity->changeCapacity($trip, 2);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT seat_capacity FROM trip WHERE id = ?', [$trip->getId()]));
    }

    public function testCapacityCannotBeReducedBelowPeakOccupancy(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $this->book($trip, 0, 1, 2);

        try {
            $this->capacity->changeCapacity($trip, 1);
            self::fail('Exception attendue');
        } catch (DomainRuleException) {
        }

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT seat_capacity FROM trip WHERE id = ?', [$trip->getId()]));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testCapacityCannotExceedVehicleSeatsOnModification(): void
    {
        $trip = $this->saveTrip(TestData::trip(TestData::vehicle(seats: 3), 2));

        $this->expectException(DomainRuleException::class);
        $this->capacity->changeCapacity($trip, 4);
    }

    /**
     * Sous REPEATABLE READ, un SELECT ordinaire resterait sur l'instantané pris avant le verrou.
     */
    public function testOccupancyReadsCommittedDataDespiteAnEarlierSnapshot(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $passenger = TestData::user();
        $this->em->persist($passenger);
        $this->em->flush();

        $other = DriverManager::getConnection($this->connection->getParams());

        $this->connection->beginTransaction();

        try {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM booking'));

            $other->insert('booking', [
                'trip_id' => $trip->getId(),
                'passenger_id' => $passenger->getId(),
                'boarding_stop_id' => $trip->getStopAt(0)->getId(),
                'alighting_stop_id' => $trip->getStopAt(2)->getId(),
                'seats' => 2,
                'seat_price_cents' => 1000,
                'total_price_cents' => 2000,
                'status' => BookingStatus::Confirmed->value,
                'created_at' => '2026-12-01 07:00:00',
            ]);

            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM booking'), 'Le SELECT ordinaire doit rester sur son instantané');
            self::assertSame([0 => 2, 1 => 2], $this->bookings->occupiedSeatsBySegment($trip));
        } finally {
            $this->connection->rollBack();
            $other->close();
        }
    }
}
