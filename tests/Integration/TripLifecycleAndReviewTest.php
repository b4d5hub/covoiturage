<?php

namespace App\Tests\Integration;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Exception\DomainRuleException;
use App\Tests\Support\TestData;
use PHPUnit\Framework\Attributes\TestWith;

final class TripLifecycleAndReviewTest extends ServiceTestCase
{
    private const BEFORE = '2026-11-25 10:00';
    private const AFTER_ARRIVAL = '2026-12-01 14:00';

    private function assertRefused(string $message, callable $action): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage($message);
        $action();
    }

    private function trip(): Trip
    {
        return $this->saveTrip(TestData::trip());
    }

    /** @return array{Trip, User} trajet effectué et passager y ayant participé */
    private function completedTrip(): array
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $this->book($trip, $passenger);
        $this->tripService->complete($trip, $trip->getDriver(), $this->at(self::AFTER_ARRIVAL));

        return [$trip, $passenger];
    }

    public function testDriverCancellationCancelsRefundsAndNotifies(): void
    {
        $trip = $this->trip();
        $first = $this->book($trip);
        $second = $this->book($trip, seats: 2);

        $this->tripService->cancel($trip, $trip->getDriver(), 'Panne du véhicule.', $this->at(self::BEFORE));

        self::assertSame('cancelled', $this->sql('SELECT status FROM trip WHERE id = ?', [$trip->getId()]));
        self::assertSame('Panne du véhicule.', $this->sql('SELECT cancellation_reason FROM trip WHERE id = ?', [$trip->getId()]));
        self::assertSame(2, (int) $this->sql("SELECT COUNT(*) FROM booking WHERE status = 'cancelled'"));
        self::assertSame(2, (int) $this->sql("SELECT COUNT(*) FROM payment WHERE status = 'refunded' AND refunded_at IS NOT NULL"));
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM notification WHERE recipient_id = ? AND message LIKE ?', [$first->getPassenger()->getId(), '%Panne du véhicule.%']));
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM notification WHERE recipient_id = ? AND is_read = 0', [$second->getPassenger()->getId()]));
        self::assertSame([0 => 0, 1 => 0], $this->bookingRepository->occupiedSeatsBySegment($trip));
    }

    public function testNotificationShowsMoroccoTime(): void
    {
        $trip = $this->trip();
        $this->book($trip);

        $this->tripService->cancel($trip, $trip->getDriver(), 'Motif.', $this->at(self::BEFORE));

        // Départ à 07:00 UTC, soit 08:00 au Maroc en décembre.
        self::assertSame(1, (int) $this->sql("SELECT COUNT(*) FROM notification WHERE message LIKE '%01/12/2026 à 08:00%'"));
    }

    public function testPassengerWhoAlreadyCancelledIsNotNotifiedOrRefundedAgain(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $this->bookingService->cancelByPassenger($this->book($trip, $passenger), $passenger, $this->at('2026-11-21 10:00'));

        $this->tripService->cancel($trip, $trip->getDriver(), 'Motif.', $this->at(self::BEFORE));

        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM notification'));
        self::assertSame('2026-11-21 10:00:00', $this->sql('SELECT refunded_at FROM payment'));
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function testCancellationReasonIsMandatory(string $reason): void
    {
        $trip = $this->trip();

        $this->assertRefused('motif', fn () => $this->tripService->cancel($trip, $trip->getDriver(), $reason, $this->at(self::BEFORE)));
    }

    public function testOnlyTheDriverCanCancel(): void
    {
        $trip = $this->trip();
        $other = $this->passenger();

        $this->assertRefused('Seul le conducteur', fn () => $this->tripService->cancel($trip, $other, 'Motif', $this->at(self::BEFORE)));
    }

    public function testAdministratorCannotCancelInTheDriversPlace(): void
    {
        $trip = $this->trip();
        $admin = $this->passenger()->setRoles(['ROLE_ADMIN']);

        $this->assertRefused('Seul le conducteur', fn () => $this->tripService->cancel($trip, $admin, 'Motif', $this->at(self::BEFORE)));
    }

    #[TestWith(['2026-12-01 07:00'])]
    #[TestWith(['2026-12-01 09:00'])]
    public function testTripCannotBeCancelledOnceStarted(string $now): void
    {
        $trip = $this->trip();

        $this->assertRefused('déjà commencé', fn () => $this->tripService->cancel($trip, $trip->getDriver(), 'Motif', $this->at($now)));
    }

    public function testTripCanBeCancelledJustBeforeDeparture(): void
    {
        $trip = $this->trip();

        $this->tripService->cancel($trip, $trip->getDriver(), 'Motif', $this->at('2026-12-01 06:59:59'));

        self::assertSame(TripStatus::Cancelled, $trip->getStatus());
    }

    public function testTripCannotBeCancelledTwice(): void
    {
        $trip = $this->trip();
        $this->tripService->cancel($trip, $trip->getDriver(), 'Motif', $this->at(self::BEFORE));

        $this->assertRefused('Seul un trajet publié', fn () => $this->tripService->cancel($trip, $trip->getDriver(), 'Motif', $this->at(self::BEFORE)));
    }

    public function testDriverCanCompleteAfterArrival(): void
    {
        $trip = $this->trip();

        $this->tripService->complete($trip, $trip->getDriver(), $this->at(self::AFTER_ARRIVAL));

        self::assertSame('completed', $this->sql('SELECT status FROM trip WHERE id = ?', [$trip->getId()]));
    }

    public function testAdministratorCanComplete(): void
    {
        $trip = $this->trip();
        $admin = $this->passenger()->setRoles(['ROLE_ADMIN']);

        $this->tripService->complete($trip, $admin, $this->at(self::AFTER_ARRIVAL));

        self::assertSame(TripStatus::Completed, $trip->getStatus());
    }

    public function testOtherUsersCannotComplete(): void
    {
        $trip = $this->trip();
        $other = $this->passenger();

        $this->assertRefused('conducteur ou un administrateur', fn () => $this->tripService->complete($trip, $other, $this->at(self::AFTER_ARRIVAL)));
    }

    public function testTripCannotBeCompletedBeforeScheduledArrival(): void
    {
        $trip = $this->trip();

        $this->assertRefused('avant son arrivée', fn () => $this->tripService->complete($trip, $trip->getDriver(), $this->at('2026-12-01 12:59:59')));
    }

    public function testCancelledTripCannotBeCompleted(): void
    {
        $trip = $this->trip();
        $this->tripService->cancel($trip, $trip->getDriver(), 'Motif', $this->at(self::BEFORE));

        $this->assertRefused('Seul un trajet publié', fn () => $this->tripService->complete($trip, $trip->getDriver(), $this->at(self::AFTER_ARRIVAL)));
    }

    public function testPassengerAndDriverCanReviewEachOther(): void
    {
        [$trip, $passenger] = $this->completedTrip();

        $this->reviewService->submit($trip, $passenger, $trip->getDriver(), 5, 'Très bien', $this->at('2026-12-02 10:00'));
        $this->reviewService->submit($trip, $trip->getDriver(), $passenger, 4, null, $this->at('2026-12-02 10:00'));

        self::assertSame(2, (int) $this->sql('SELECT COUNT(*) FROM review'));
    }

    public function testReviewIsRefusedBeforeTheTripIsCompleted(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $this->book($trip, $passenger);

        $this->assertRefused('trajet effectué', fn () => $this->reviewService->submit($trip, $passenger, $trip->getDriver(), 5, null, $this->at(self::AFTER_ARRIVAL)));
    }

    public function testReviewIsRefusedOnCancelledTrip(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $this->book($trip, $passenger);
        $this->tripService->cancel($trip, $trip->getDriver(), 'Motif', $this->at(self::BEFORE));

        $this->assertRefused('trajet effectué', fn () => $this->reviewService->submit($trip, $passenger, $trip->getDriver(), 5, null, $this->at(self::AFTER_ARRIVAL)));
    }

    public function testReviewIsAcceptedOnTheLastDay(): void
    {
        [$trip, $passenger] = $this->completedTrip();

        $this->reviewService->submit($trip, $passenger, $trip->getDriver(), 5, null, $this->at('2026-12-15 14:00:00'));

        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM review'));
    }

    public function testReviewIsRefusedAfterFourteenDays(): void
    {
        [$trip, $passenger] = $this->completedTrip();

        $this->assertRefused('14 jours', fn () => $this->reviewService->submit($trip, $passenger, $trip->getDriver(), 5, null, $this->at('2026-12-15 14:00:01')));
    }

    public function testSecondReviewInTheSameDirectionIsRefused(): void
    {
        [$trip, $passenger] = $this->completedTrip();
        $this->reviewService->submit($trip, $passenger, $trip->getDriver(), 5, null, $this->at('2026-12-02 10:00'));

        $this->assertRefused('déjà été déposé', fn () => $this->reviewService->submit($trip, $passenger, $trip->getDriver(), 1, null, $this->at('2026-12-03 10:00')));
    }

    public function testPassengersCannotReviewEachOther(): void
    {
        $trip = $this->trip();
        $first = $this->passenger();
        $second = $this->passenger();
        $this->book($trip, $first);
        $this->book($trip, $second);
        $this->tripService->complete($trip, $trip->getDriver(), $this->at(self::AFTER_ARRIVAL));

        $this->assertRefused('entre un passager et le conducteur', fn () => $this->reviewService->submit($trip, $first, $second, 5, null, $this->at('2026-12-02 10:00')));
    }

    public function testNonParticipantCannotReviewTheDriver(): void
    {
        [$trip] = $this->completedTrip();
        $stranger = $this->passenger();

        $this->assertRefused('ayant effectué', fn () => $this->reviewService->submit($trip, $stranger, $trip->getDriver(), 5, null, $this->at('2026-12-02 10:00')));
    }

    public function testDriverCannotReviewSomeoneWhoDidNotTravel(): void
    {
        [$trip] = $this->completedTrip();
        $stranger = $this->passenger();

        $this->assertRefused('ayant effectué', fn () => $this->reviewService->submit($trip, $trip->getDriver(), $stranger, 5, null, $this->at('2026-12-02 10:00')));
    }

    public function testPassengerWhoCancelledCannotReview(): void
    {
        $trip = $this->trip();
        $passenger = $this->passenger();
        $this->bookingService->cancelByPassenger($this->book($trip, $passenger), $passenger, $this->at('2026-11-21 10:00'));
        $this->tripService->complete($trip, $trip->getDriver(), $this->at(self::AFTER_ARRIVAL));

        $this->assertRefused('ayant effectué', fn () => $this->reviewService->submit($trip, $passenger, $trip->getDriver(), 5, null, $this->at('2026-12-02 10:00')));
    }

    public function testNobodyCanReviewThemselves(): void
    {
        [$trip] = $this->completedTrip();

        $this->assertRefused('entre un passager et le conducteur', fn () => $this->reviewService->submit($trip, $trip->getDriver(), $trip->getDriver(), 5, null, $this->at('2026-12-02 10:00')));
    }

    #[TestWith([0])]
    #[TestWith([6])]
    public function testRatingMustBeBetweenOneAndFive(int $rating): void
    {
        [$trip, $passenger] = $this->completedTrip();

        $this->assertRefused('entre 1 et 5', fn () => $this->reviewService->submit($trip, $passenger, $trip->getDriver(), $rating, null, $this->at('2026-12-02 10:00')));
    }
}
