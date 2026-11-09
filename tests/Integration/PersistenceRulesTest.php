<?php

namespace App\Tests\Integration;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\Vehicle;
use App\Exception\DomainRuleException;
use App\Tests\Support\TestData;
use App\Time\MoroccoTime;

/**
 * Règles appliquées à tout chemin d'écriture passant par Doctrine.
 */
final class PersistenceRulesTest extends DatabaseTestCase
{
    public function testInvalidBookingRouteCannotBePersisted(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $other = $this->saveTrip(TestData::trip());
        $passenger = TestData::user();
        $this->em->persist($passenger);

        $booking = TestData::booking($trip, $passenger, 0, 1)->setBoardingStop($other->getStopAt(0));

        $this->expectException(DomainRuleException::class);
        $this->em->persist($booking);
    }

    public function testBookingCannotBeMovedToAnotherTripStop(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $other = $this->saveTrip(TestData::trip());
        $passenger = TestData::user();
        $this->em->persist($passenger);
        $booking = TestData::booking($trip, $passenger, 0, 1);
        $this->em->persist($booking);
        $this->em->flush();

        $booking->setAlightingStop($other->getStopAt(1));

        $this->expectException(DomainRuleException::class);
        $this->em->flush();
    }

    public function testTripAboveVehicleSeatsCannotBePersisted(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->saveTrip(TestData::trip(TestData::vehicle(seats: 2), 3));
    }

    public function testTripWithoutItineraryCannotBePersisted(): void
    {
        $vehicle = TestData::vehicle();
        $this->em->persist($vehicle->getOwner());
        $this->em->persist($vehicle);

        $this->expectException(DomainRuleException::class);
        $this->em->persist((new Trip())->setVehicle($vehicle)->setSeatCapacity(1)->setFullPriceCents(100));
    }

    public function testReducingVehicleSeatsBelowTripCapacityFailsOnFlush(): void
    {
        $id = $this->saveTrip(TestData::trip(TestData::vehicle(seats: 4), 3))->getVehicle()->getId();
        $vehicle = $this->reload(Vehicle::class, $id);
        $vehicle->setPassengerSeats(2);

        $this->expectException(DomainRuleException::class);
        $this->em->flush();
    }

    public function testOwnerChangeIsRefusedAfterReloadingFromDatabase(): void
    {
        $id = $this->saveTrip(TestData::trip())->getVehicle()->getId();
        $vehicle = $this->reload(Vehicle::class, $id);
        $newOwner = TestData::user();
        $this->em->persist($newOwner);

        $this->expectException(DomainRuleException::class);
        $vehicle->setOwner($newOwner);
    }

    public function testFreezeSurvivesReloadAndBlocksEditing(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $trip->freezeItinerary();
        $this->em->flush();

        $reloaded = $this->reload(Trip::class, $trip->getId());
        self::assertTrue($reloaded->isItineraryFrozen());

        $this->expectException(DomainRuleException::class);
        $reloaded->replaceItinerary(TestData::threeStops(), [1, 2]);
    }

    public function testEditingItineraryPersistsWithoutBreakingUniquePositions(): void
    {
        $id = $this->saveTrip(TestData::trip())->getId();

        $trip = $this->reload(Trip::class, $id);
        $stops = [
            TestData::stop(null, '2026-12-01 07:00'),
            TestData::stop('2026-12-01 08:00', '2026-12-01 08:10'),
            TestData::stop('2026-12-01 09:00', '2026-12-01 09:10'),
            TestData::stop('2026-12-01 13:00', null),
        ];

        foreach ($stops as $stop) {
            $this->em->persist($stop->getCity());
        }

        $trip->replaceItinerary($stops, [10, 20, 30]);
        $this->em->flush();

        $trip = $this->reload(Trip::class, $id);
        self::assertSame([0, 1, 2, 3], $trip->getStops()->map(fn ($s) => $s->getPosition())->getValues());
        self::assertSame([10, 20, 30], $trip->getSegments()->map(fn ($s) => $s->getDistanceMeters())->getValues());

        $stops = [TestData::stop(null, '2026-12-01 07:00'), TestData::stop('2026-12-01 13:00', null)];

        foreach ($stops as $stop) {
            $this->em->persist($stop->getCity());
        }

        $trip->replaceItinerary($stops, [99]);
        $this->em->flush();

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM trip_stop WHERE trip_id = ?', [$id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM trip_segment WHERE trip_id = ?', [$id]));
    }

    public function testDatesAreStoredInUtcWhateverTheirTimeZone(): void
    {
        $departure = MoroccoTime::toLocal(MoroccoTime::toUtc('2026-12-01 09:00'));
        self::assertSame('Africa/Casablanca', $departure->getTimezone()->getName());

        $trip = TestData::trip();
        $trip->freezeItinerary($departure);
        $this->saveTrip($trip);

        $raw = $this->connection->fetchOne('SELECT itinerary_frozen_at FROM trip WHERE id = ?', [$trip->getId()]);
        self::assertSame('2026-12-01 08:00:00', $raw);

        $reloaded = $this->reload(Trip::class, $trip->getId());
        self::assertSame('UTC', $reloaded->getItineraryFrozenAt()->getTimezone()->getName());
        self::assertEquals($departure, $reloaded->getItineraryFrozenAt());
    }

    public function testTheUtcTypeConvertsLocalDatesEvenWithoutEntityNormalisation(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $trip->setCancelledAt(MoroccoTime::toLocal(MoroccoTime::toUtc('2027-02-20 12:00')));
        $trip->setCompletedAt(MoroccoTime::toLocal(MoroccoTime::toUtc('2027-03-20 12:00')));
        $this->em->flush();

        $row = $this->connection->fetchAssociative('SELECT cancelled_at, completed_at FROM trip WHERE id = ?', [$trip->getId()]);

        self::assertSame('2027-02-20 12:00:00', $row['cancelled_at']);
        self::assertSame('2027-03-20 11:00:00', $row['completed_at']);
    }

    public function testBookingDeadlineSurvivesReload(): void
    {
        $trip = $this->saveTrip(TestData::trip());
        $passenger = TestData::user();
        $this->em->persist($passenger);
        $booking = TestData::booking($trip, $passenger, 1, 2);
        $this->em->persist($booking);
        $this->em->flush();

        $reloaded = $this->reload(Booking::class, $booking->getId());

        self::assertSame('2026-12-01 08:30', $reloaded->getCancellationDeadline()->format('Y-m-d H:i'));
    }
}
