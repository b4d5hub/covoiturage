<?php

namespace App\Tests\Unit;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Entity\Trip;
use App\Enum\PaymentStatus;
use App\Exception\DomainRuleException;
use App\Tests\Support\TestData;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase
{
    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    #[TestWith([Trip::class, 'setStatus'])]
    #[TestWith([Trip::class, 'setCancelledAt'])]
    #[TestWith([Trip::class, 'setCompletedAt'])]
    #[TestWith([Booking::class, 'setStatus'])]
    #[TestWith([Booking::class, 'setCancelledAt'])]
    #[TestWith([Payment::class, 'setStatus'])]
    #[TestWith([Payment::class, 'setRefundedAt'])]
    public function testStatesCannotBeSetArbitrarily(string $class, string $method): void
    {
        self::assertFalse(method_exists($class, $method));
    }

    public function testCompletedTripCannotBeCancelled(): void
    {
        $trip = TestData::trip();
        $trip->complete($this->at('2026-12-01 14:00'));

        $this->expectException(DomainRuleException::class);
        $trip->cancel('Motif', $this->at('2026-11-20 10:00'));
    }

    public function testCompletedTripCannotBeCompletedAgain(): void
    {
        $trip = TestData::trip();
        $trip->complete($this->at('2026-12-01 14:00'));

        $this->expectException(DomainRuleException::class);
        $trip->complete($this->at('2026-12-02 14:00'));
    }

    public function testCompletionIsAllowedExactlyAtScheduledArrival(): void
    {
        $trip = TestData::trip();
        $trip->complete($this->at('2026-12-01 13:00'));

        self::assertNotNull($trip->getCompletedAt());
    }

    public function testRefundIsOneWay(): void
    {
        $payment = new Payment();
        $payment->refund($this->at('2026-11-20 10:00'));

        self::assertSame(PaymentStatus::Refunded, $payment->getStatus());
        $this->expectException(DomainRuleException::class);
        $payment->refund($this->at('2026-11-21 10:00'));
    }

    public function testCancellationWithTheTripIgnoresThePassengerDeadline(): void
    {
        $booking = TestData::booking(TestData::trip(), TestData::user(), 0, 1);

        $booking->cancelWithTrip($this->at('2026-12-01 06:59'));

        self::assertFalse($booking->isConfirmed());
    }
}
