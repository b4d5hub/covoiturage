<?php

namespace App\Tests\Unit;

use App\Exception\DomainRuleException;
use App\Tests\Support\TestData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CapacityRulesTest extends TestCase
{
    #[TestWith([0])]
    #[TestWith([-1])]
    public function testCapacityMustBePositive(int $capacity): void
    {
        $trip = TestData::trip(TestData::vehicle(seats: 4), $capacity);

        self::assertGreaterThan(0, TestData::validator()->validate($trip)->count());
        $this->expectException(DomainRuleException::class);
        $trip->assertRulesHold();
    }

    public function testCapacityCanEqualVehicleSeats(): void
    {
        $trip = TestData::trip(TestData::vehicle(seats: 4), 4);

        self::assertCount(0, TestData::validator()->validate($trip));
        $trip->assertRulesHold();
    }

    public function testCapacityCannotExceedVehicleSeatsAtCreation(): void
    {
        $trip = TestData::trip(TestData::vehicle(seats: 2), 3);

        self::assertGreaterThan(0, TestData::validator()->validate($trip)->count());
        $this->expectException(DomainRuleException::class);
        $trip->assertRulesHold();
    }

    /** @return iterable<string, array{int, int}> */
    public static function refusedChanges(): iterable
    {
        yield 'zéro' => [0, 0];
        yield 'négative' => [-1, 0];
        yield 'au-delà du véhicule' => [5, 0];
        yield 'sous l\'occupation' => [1, 2];
    }

    #[DataProvider('refusedChanges')]
    public function testRefusedCapacityChanges(int $capacity, int $occupied): void
    {
        $trip = TestData::trip(TestData::vehicle(seats: 4), 3);

        try {
            $trip->changeSeatCapacity($capacity, $occupied);
            self::fail('Exception attendue');
        } catch (DomainRuleException) {
        }

        self::assertSame(3, $trip->getSeatCapacity());
    }

    #[TestWith([2, 2])]
    #[TestWith([4, 2])]
    public function testAcceptedCapacityChanges(int $capacity, int $occupied): void
    {
        $trip = TestData::trip(TestData::vehicle(seats: 4), 3);

        $trip->changeSeatCapacity($capacity, $occupied);

        self::assertSame($capacity, $trip->getSeatCapacity());
    }

    public function testSavedTripCapacityCannotBypassOccupancyCheck(): void
    {
        $trip = TestData::trip();
        (new \ReflectionProperty($trip, 'id'))->setValue($trip, 1);

        $this->expectException(DomainRuleException::class);
        $trip->setSeatCapacity(1);
    }

    public function testVehicleSeatsCannotDropBelowATripCapacity(): void
    {
        $vehicle = TestData::vehicle(seats: 4);
        TestData::trip($vehicle, 3);
        $vehicle->setPassengerSeats(2);

        self::assertGreaterThan(0, TestData::validator()->validate($vehicle)->count());
        $this->expectException(DomainRuleException::class);
        $vehicle->assertRulesHold();
    }

    public function testVehicleSeatsCanDropToTheHighestTripCapacity(): void
    {
        $vehicle = TestData::vehicle(seats: 4);
        TestData::trip($vehicle, 3);
        $vehicle->setPassengerSeats(3);

        self::assertCount(0, TestData::validator()->validate($vehicle));
        $vehicle->assertRulesHold();
    }
}
