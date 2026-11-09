<?php

namespace App\Tests\Unit;

use App\Entity\Trip;
use App\Exception\DomainRuleException;
use App\Tests\Support\TestData;
use PHPUnit\Framework\TestCase;

final class TripDriverHistoryTest extends TestCase
{
    public function testDriverIsTheVehicleOwner(): void
    {
        $owner = TestData::user();

        self::assertSame($owner, TestData::trip(TestData::vehicle($owner))->getDriver());
    }

    public function testOwnerCanChangeWhileVehicleHasNoTrip(): void
    {
        $vehicle = TestData::vehicle();
        $newOwner = TestData::user();

        $vehicle->setOwner($newOwner);

        self::assertSame($newOwner, $vehicle->getOwner());
    }

    public function testOwnerCannotChangeOnceVehicleIsUsedByATrip(): void
    {
        $vehicle = TestData::vehicle();
        $trip = TestData::trip($vehicle);
        $driver = $trip->getDriver();

        try {
            $vehicle->setOwner(TestData::user());
            self::fail('Exception attendue');
        } catch (DomainRuleException) {
        }

        self::assertSame($driver, $trip->getDriver());
    }

    public function testSettingTheSameOwnerIsAllowed(): void
    {
        $owner = TestData::user();
        $vehicle = TestData::vehicle($owner);
        TestData::trip($vehicle);

        $vehicle->setOwner($owner);

        self::assertSame($owner, $vehicle->getOwner());
    }

    public function testTripVehicleCannotChange(): void
    {
        $trip = TestData::trip();

        $this->expectException(DomainRuleException::class);
        $trip->setVehicle(TestData::vehicle());
    }

    public function testSettingTheSameVehicleIsAllowed(): void
    {
        $vehicle = TestData::vehicle();
        $trip = TestData::trip($vehicle);

        $trip->setVehicle($vehicle);

        self::assertCount(1, $vehicle->getTrips());
    }

    public function testFreezeIsOneWayAndKeepsFirstDate(): void
    {
        $trip = new Trip();
        $first = new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'));

        $trip->freezeItinerary($first);
        $trip->freezeItinerary(new \DateTimeImmutable('2027-01-01 08:00:00', new \DateTimeZone('UTC')));

        self::assertTrue($trip->isItineraryFrozen());
        self::assertEquals($first, $trip->getItineraryFrozenAt());
    }

    public function testNoMethodCanResetTheFreeze(): void
    {
        self::assertFalse(method_exists(Trip::class, 'setItineraryFrozenAt'));

        $parameter = (new \ReflectionMethod(Trip::class, 'freezeItinerary'))->getParameters()[0];
        self::assertTrue($parameter->allowsNull());

        $trip = new Trip();
        $trip->freezeItinerary();
        $date = $trip->getItineraryFrozenAt();
        $trip->freezeItinerary(null);
        self::assertSame($date, $trip->getItineraryFrozenAt());
    }
}
