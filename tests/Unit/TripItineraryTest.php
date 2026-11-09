<?php

namespace App\Tests\Unit;

use App\Entity\Trip;
use App\Entity\TripSegment;
use App\Entity\TripStop;
use App\Exception\DomainRuleException;
use App\Tests\Support\TestData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TripItineraryTest extends TestCase
{
    private function assertTripInvalid(Trip $trip): void
    {
        self::assertGreaterThan(0, TestData::validator()->validate($trip)->count());
        $this->expectException(DomainRuleException::class);
        $trip->assertRulesHold();
    }

    private static function set(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }

    public function testValidItineraryPasses(): void
    {
        $trip = TestData::trip();

        self::assertCount(0, TestData::validator()->validate($trip));
        $trip->assertRulesHold();
    }

    public function testPositionsAreConsecutiveAndSegmentsAreNMinusOne(): void
    {
        $trip = TestData::trip();

        self::assertSame([0, 1, 2], $trip->getStops()->map(fn (TripStop $s) => $s->getPosition())->getValues());
        self::assertSame([0, 1], $trip->getSegments()->map(fn (TripSegment $s) => $s->getPosition())->getValues());
    }

    public function testSegmentConnectsStopsAtPAndPPlusOne(): void
    {
        $trip = TestData::trip();

        foreach ($trip->getSegments() as $segment) {
            self::assertSame($trip->getStopAt($segment->getPosition()), $segment->getFromStop());
            self::assertSame($trip->getStopAt($segment->getPosition() + 1), $segment->getToStop());
        }
    }

    public function testRejectsFewerThanTwoStops(): void
    {
        $this->expectException(DomainRuleException::class);
        (new Trip())->replaceItinerary([TestData::stop(null, '2026-12-01 07:00')], []);
    }

    /** @return iterable<string, array{list<int>}> */
    public static function invalidDistances(): iterable
    {
        yield 'trop peu de segments' => [[1000]];
        yield 'trop de segments' => [[1000, 1000, 1000]];
        yield 'aucun segment' => [[]];
        yield 'distance nulle' => [[1000, 0]];
        yield 'distance négative' => [[-5, 1000]];
    }

    /** @param list<int> $distances */
    #[DataProvider('invalidDistances')]
    public function testRejectsInvalidDistances(array $distances): void
    {
        $this->expectException(DomainRuleException::class);
        (new Trip())->replaceItinerary(TestData::threeStops(), $distances);
    }

    /** @return iterable<string, array{list<array{?string, ?string}>}> */
    public static function invalidSchedules(): iterable
    {
        yield 'arrivée sur la première étape' => [[['2026-12-01 06:00', '2026-12-01 07:00'], ['2026-12-01 10:00', null]]];
        yield 'pas de départ sur la première étape' => [[[null, null], ['2026-12-01 10:00', null]]];
        yield 'départ sur la dernière étape' => [[[null, '2026-12-01 07:00'], ['2026-12-01 10:00', '2026-12-01 11:00']]];
        yield 'pas d\'arrivée sur la dernière étape' => [[[null, '2026-12-01 07:00'], [null, null]]];
        yield 'étape intermédiaire sans arrivée' => [[[null, '2026-12-01 07:00'], [null, '2026-12-01 10:30'], ['2026-12-01 13:00', null]]];
        yield 'étape intermédiaire sans départ' => [[[null, '2026-12-01 07:00'], ['2026-12-01 10:00', null], ['2026-12-01 13:00', null]]];
        yield 'départ avant arrivée' => [[[null, '2026-12-01 07:00'], ['2026-12-01 10:30', '2026-12-01 10:00'], ['2026-12-01 13:00', null]]];
        yield 'horaires non croissants' => [[[null, '2026-12-01 07:00'], ['2026-12-01 06:00', '2026-12-01 06:30'], ['2026-12-01 13:00', null]]];
    }

    /** @param list<array{?string, ?string}> $times */
    #[DataProvider('invalidSchedules')]
    public function testRejectsInvalidSchedules(array $times): void
    {
        $stops = array_map(fn (array $t) => TestData::stop($t[0], $t[1]), $times);

        $this->expectException(DomainRuleException::class);
        (new Trip())->replaceItinerary($stops, array_fill(0, \count($stops) - 1, 1000));
    }

    public function testFailedEditLeavesItineraryUntouched(): void
    {
        $trip = TestData::trip();

        try {
            $trip->replaceItinerary(TestData::threeStops(), [1000]);
            self::fail('Exception attendue');
        } catch (DomainRuleException) {
        }

        self::assertCount(3, $trip->getStops());
        self::assertSame(250000, $trip->getSegments()->first()->getDistanceMeters());
    }

    public function testEditingUpdatesExistingRowsInPlace(): void
    {
        $trip = TestData::trip();
        $firstStop = $trip->getStopAt(0);
        $firstSegment = $trip->getSegments()->first();

        $trip->replaceItinerary(TestData::threeStops(), [111, 222]);

        self::assertSame($firstStop, $trip->getStopAt(0));
        self::assertSame($firstSegment, $trip->getSegments()->first());
        self::assertSame(111, $firstSegment->getDistanceMeters());
    }

    public function testEditingCanAddAndRemoveStopsWhileKeepingInvariants(): void
    {
        $trip = TestData::trip();

        $trip->replaceItinerary([
            TestData::stop(null, '2026-12-01 07:00'),
            TestData::stop('2026-12-01 09:00', '2026-12-01 09:10'),
            TestData::stop('2026-12-01 10:00', '2026-12-01 10:30'),
            TestData::stop('2026-12-01 13:00', null),
        ], [100, 200, 300]);
        self::assertCount(4, $trip->getStops());
        self::assertCount(3, $trip->getSegments());
        self::assertCount(0, TestData::validator()->validate($trip));

        $trip->replaceItinerary([
            TestData::stop(null, '2026-12-01 07:00'),
            TestData::stop('2026-12-01 13:00', null),
        ], [500]);
        self::assertCount(2, $trip->getStops());
        self::assertCount(1, $trip->getSegments());
        self::assertCount(0, TestData::validator()->validate($trip));
    }

    public function testEmptyTripIsInvalid(): void
    {
        $this->assertTripInvalid((new Trip())->setVehicle(TestData::vehicle())->setSeatCapacity(1)->setFullPriceCents(100));
    }

    public function testDetectsGapInStopPositions(): void
    {
        $trip = TestData::trip();
        self::set($trip->getStopAt(2), 'position', 5);
        $this->assertTripInvalid($trip);
    }

    public function testDetectsDuplicateStopPositions(): void
    {
        $trip = TestData::trip();
        self::set($trip->getStopAt(2), 'position', 1);
        $this->assertTripInvalid($trip);
    }

    public function testDetectsWrongSegmentCount(): void
    {
        $trip = TestData::trip();
        $trip->getSegments()->removeElement($trip->getSegments()->last());
        $this->assertTripInvalid($trip);
    }

    public function testDetectsGapInSegmentPositions(): void
    {
        $trip = TestData::trip();
        self::set($trip->getSegments()->last(), 'position', 4);
        $this->assertTripInvalid($trip);
    }

    public function testDetectsDuplicateSegmentPositions(): void
    {
        $trip = TestData::trip();
        self::set($trip->getSegments()->last(), 'position', 0);
        $this->assertTripInvalid($trip);
    }

    public function testDetectsNonPositiveSegmentDistance(): void
    {
        $trip = TestData::trip();
        self::set($trip->getSegments()->first(), 'distanceMeters', 0);
        $this->assertTripInvalid($trip);
    }

    public function testSegmentRefusesNonPositiveDistance(): void
    {
        $this->expectException(DomainRuleException::class);
        new TripSegment(new Trip(), 0, 0);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidCoordinates(): iterable
    {
        yield 'latitude trop haute' => ['90.000001', '0'];
        yield 'latitude trop basse' => ['-90.000001', '0'];
        yield 'longitude trop haute' => ['0', '180.000001'];
        yield 'longitude trop basse' => ['0', '-180.000001'];
    }

    #[DataProvider('invalidCoordinates')]
    public function testValidatesCoordinateRanges(string $latitude, string $longitude): void
    {
        $stop = new TripStop(TestData::city(), 'Gare routière', $latitude, $longitude, null, new \DateTimeImmutable('2026-12-01'));

        self::assertGreaterThan(0, TestData::validator()->validate($stop)->count());
    }

    public function testFrozenItineraryCannotBeEdited(): void
    {
        $trip = TestData::trip();
        $trip->freezeItinerary();

        $this->expectException(DomainRuleException::class);
        $trip->replaceItinerary(TestData::threeStops(), [1, 2]);
    }
}
