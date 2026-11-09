<?php

namespace App\Tests\Integration;

use App\Entity\Trip;
use App\Enum\BookingStatus;
use App\Enum\PaymentStatus;
use App\Exception\DomainRuleException;
use App\Tests\Support\TestData;
use PHPUnit\Framework\Attributes\TestWith;

final class BookingServiceTest extends ServiceTestCase
{
    private function trip(int $capacity = 3): Trip
    {
        return $this->saveTrip(TestData::trip(TestData::vehicle(seats: 4), $capacity));
    }

    private function assertRefused(string $message, callable $action): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage($message);
        $action();
    }

    public function testBookingCreatesPaymentFreezesItineraryAndFixesAmounts(): void
    {
        $trip = $this->trip();

        $booking = $this->book($trip, boarding: 0, alighting: 1, seats: 2);

        // 250 km sur 490 km, pour un trajet complet à 200 MAD.
        self::assertSame(10204, $booking->getSeatPriceCents());
        self::assertSame(20408, $booking->getTotalPriceCents());
        self::assertSame(BookingStatus::Confirmed, $booking->getStatus());
        self::assertSame(20408, (int) $this->sql('SELECT amount_cents FROM payment WHERE booking_id = ?', [$booking->getId()]));
        self::assertSame(PaymentStatus::Paid->value, $this->sql('SELECT status FROM payment WHERE booking_id = ?', [$booking->getId()]));
        self::assertNotNull($this->sql('SELECT itinerary_frozen_at FROM trip WHERE id = ?', [$trip->getId()]));
    }

    public function testLaterPriceChangeDoesNotAffectExistingBooking(): void
    {
        $trip = $this->trip();
        $booking = $this->book($trip);

        $trip->setFullPriceCents(99900);
        $this->em->flush();

        self::assertSame(20000, (int) $this->sql('SELECT total_price_cents FROM booking WHERE id = ?', [$booking->getId()]));
    }

    public function testSeatFreedAtAStopCanBeResold(): void
    {
        $trip = $this->trip(1);

        $this->book($trip, boarding: 0, alighting: 1);
        $this->book($trip, boarding: 1, alighting: 2);

        self::assertSame([0 => 1, 1 => 1], $this->bookingRepository->occupiedSeatsBySegment($trip));
    }

    public function testBookingFailsWhenOneSegmentOfThePortionIsFull(): void
    {
        $trip = $this->trip(1);
        $this->book($trip, boarding: 1, alighting: 2);

        $this->assertRefused('pas assez de places', fn () => $this->book($trip, boarding: 0, alighting: 2));
    }

    public function testSeveralSeatsNeedCapacityOnEverySegment(): void
    {
        $trip = $this->trip(3);
        $this->book($trip, boarding: 1, alighting: 2, seats: 2);

        $this->book($trip, boarding: 0, alighting: 1, seats: 3);
        $this->assertRefused('pas assez de places', fn () => $this->book($trip, boarding: 0, alighting: 2, seats: 2));
    }

    public function testLastSeatCanBeTakenButNotExceeded(): void
    {
        $trip = $this->trip(2);
        $this->book($trip, seats: 2);

        $this->assertRefused('pas assez de places', fn () => $this->book($trip));
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function testSeatQuantityMustBePositive(int $seats): void
    {
        $trip = $this->trip();

        $this->assertRefused('strictement positif', fn () => $this->book($trip, seats: $seats));
    }

    public function testDriverCannotBookOwnTrip(): void
    {
        $trip = $this->trip();

        $this->assertRefused('propre trajet', fn () => $this->book($trip, $trip->getDriver()));
    }

    public function testOnlyOneActiveBookingPerPassengerAndTrip(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $this->book($trip, $passenger, boarding: 0, alighting: 1);

        $this->assertRefused('réservation active', fn () => $this->book($trip, $passenger, boarding: 1, alighting: 2));
    }

    public function testPassengerCanBookAgainAfterCancelling(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $this->bookingService->cancelByPassenger($this->book($trip, $passenger), $passenger, $this->at('2026-11-21 10:00'));

        $this->book($trip, $passenger);

        self::assertSame(2, (int) $this->sql('SELECT COUNT(*) FROM booking WHERE trip_id = ?', [$trip->getId()]));
    }

    public function testBookingClosesAtBoardingStopDeparture(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();

        $this->assertRefused('déjà partie', fn () => $this->bookingService->book($trip, $passenger, $trip->getStopAt(0), $trip->getStopAt(1), 1, $this->at('2026-12-01 07:00')));
    }

    public function testIntermediateStopRemainsBookableAfterTheTripHasStarted(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();

        $booking = $this->bookingService->book($trip, $passenger, $trip->getStopAt(1), $trip->getStopAt(2), 1, $this->at('2026-12-01 09:00'));

        self::assertNotNull($booking->getId());
    }

    public function testCancelledTripCannotBeBooked(): void
    {
        $trip = $this->trip();
        $this->tripService->cancel($trip, $trip->getDriver(), 'Motif', $this->at('2026-11-20 10:00'));

        $this->assertRefused('plus ouvert', fn () => $this->book($trip));
    }

    public function testCompletedTripCannotBeBooked(): void
    {
        $trip = $this->trip();
        $this->tripService->complete($trip, $trip->getDriver(), $this->at('2026-12-01 14:00'));

        $this->assertRefused('plus ouvert', fn () => $this->book($trip));
    }

    public function testStopFromAnotherTripIsRefusedAndNothingIsSaved(): void
    {
        $trip = $this->trip();
        $other = $this->trip();
        $passenger = $this->passenger();

        try {
            $this->bookingService->book($trip, $passenger, $other->getStopAt(0), $trip->getStopAt(1), 1, $this->at('2026-11-20 10:00'));
            self::fail('Exception attendue');
        } catch (DomainRuleException) {
        }

        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM booking'));
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM payment'));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testBoardingAfterAlightingIsRefused(): void
    {
        $trip = $this->trip();

        $this->expectException(DomainRuleException::class);
        $this->book($trip, boarding: 2, alighting: 0);
    }

    public function testBookingFreezesItineraryPermanentlyEvenAfterCancellation(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $this->bookingService->cancelByPassenger($this->book($trip, $passenger), $passenger, $this->at('2026-11-21 10:00'));

        $reloaded = $this->reload(Trip::class, $trip->getId());
        self::assertTrue($reloaded->isItineraryFrozen());

        $this->expectException(DomainRuleException::class);
        $reloaded->replaceItinerary(TestData::threeStops(), [1, 2]);
    }

    public function testPassengerCancellationReleasesSeatsAndRefunds(): void
    {
        $trip = $this->trip(2);
        $passenger = $this->passenger();
        $booking = $this->book($trip, $passenger, seats: 2);

        $this->bookingService->cancelByPassenger($booking, $passenger, $this->at('2026-11-21 10:00'));

        self::assertSame([0 => 0, 1 => 0], $this->bookingRepository->occupiedSeatsBySegment($trip));
        self::assertSame('cancelled', $this->sql('SELECT status FROM booking WHERE id = ?', [$booking->getId()]));
        self::assertSame('refunded', $this->sql('SELECT status FROM payment WHERE booking_id = ?', [$booking->getId()]));
        self::assertSame('2026-11-21 10:00:00', $this->sql('SELECT refunded_at FROM payment WHERE booking_id = ?', [$booking->getId()]));
    }

    public function testPassengerCanCancelExactlyAtTheDeadline(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $booking = $this->book($trip, $passenger);

        $this->bookingService->cancelByPassenger($booking, $passenger, $this->at('2026-12-01 05:00:00'));

        self::assertSame(BookingStatus::Cancelled, $booking->getStatus());
    }

    public function testPassengerCannotCancelJustAfterTheDeadline(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $booking = $this->book($trip, $passenger);

        $this->assertRefused('moins de deux heures', fn () => $this->bookingService->cancelByPassenger($booking, $passenger, $this->at('2026-12-01 05:00:01')));
    }

    public function testDeadlineUsesTheBoardingStopNotTheTripDeparture(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $booking = $this->book($trip, $passenger, boarding: 1, alighting: 2);

        // Le trajet est déjà parti, mais l'étape de montée (10:30) reste à plus de deux heures.
        $this->bookingService->cancelByPassenger($booking, $passenger, $this->at('2026-12-01 08:30:00'));

        self::assertSame(BookingStatus::Cancelled, $booking->getStatus());
    }

    public function testOnlyThePassengerCanCancel(): void
    {
        $trip = $this->trip();
        $booking = $this->book($trip);
        $other = $this->passenger();

        $this->assertRefused('Seul le passager', fn () => $this->bookingService->cancelByPassenger($booking, $other, $this->at('2026-11-21 10:00')));
    }

    public function testBookingCannotBeCancelledTwice(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $booking = $this->book($trip, $passenger);
        $this->bookingService->cancelByPassenger($booking, $passenger, $this->at('2026-11-21 10:00'));

        $this->assertRefused('déjà annulée', fn () => $this->bookingService->cancelByPassenger($booking, $passenger, $this->at('2026-11-21 11:00')));
    }
}
