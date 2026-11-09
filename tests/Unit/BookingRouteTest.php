<?php

namespace App\Tests\Unit;

use App\Entity\Booking;
use App\Exception\DomainRuleException;
use App\Tests\Support\TestData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingRouteTest extends TestCase
{
    public function testValidPortionsPass(): void
    {
        $trip = TestData::trip();

        foreach ([[0, 1], [1, 2], [0, 2]] as [$boarding, $alighting]) {
            $booking = TestData::booking($trip, TestData::user(), $boarding, $alighting);
            self::assertCount(0, TestData::validator()->validate($booking));
            $booking->assertRouteIsValid();
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function wrongOrder(): iterable
    {
        yield 'même étape' => [1, 1];
        yield 'montée après descente' => [2, 0];
    }

    #[DataProvider('wrongOrder')]
    public function testBoardingMustPrecedeAlighting(int $boarding, int $alighting): void
    {
        $this->assertInvalid(TestData::booking(TestData::trip(), TestData::user(), $boarding, $alighting));
    }

    public function testBoardingStopFromAnotherTripIsRefused(): void
    {
        $booking = TestData::booking(TestData::trip(), TestData::user(), 0, 1);
        $booking->setBoardingStop(TestData::trip()->getStopAt(0));

        $this->assertInvalid($booking);
    }

    public function testAlightingStopFromAnotherTripIsRefused(): void
    {
        $booking = TestData::booking(TestData::trip(), TestData::user(), 0, 1);
        $booking->setAlightingStop(TestData::trip()->getStopAt(1));

        $this->assertInvalid($booking);
    }

    public function testBothStopsFromAnotherTripAreRefused(): void
    {
        $other = TestData::trip();
        $booking = TestData::booking(TestData::trip(), TestData::user(), 0, 1);
        $booking->setBoardingStop($other->getStopAt(0))->setAlightingStop($other->getStopAt(1));

        $this->assertInvalid($booking);
    }

    private function assertInvalid(Booking $booking): void
    {
        self::assertGreaterThan(0, TestData::validator()->validate($booking)->count());
        $this->expectException(DomainRuleException::class);
        $booking->assertRouteIsValid();
    }
}
