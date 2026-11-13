<?php

namespace App\Tests\Integration;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Geo\Coordinates;
use App\Geo\SearchLocation;
use App\Repository\TripRepository;
use App\Tests\Support\TestData;

/**
 * Recherche par ville ou dans un rayon de 5 km, sur le vrai MySQL (ST_Distance_Sphere).
 */
final class TripSearchTest extends DatabaseTestCase
{
    /** Centres des localités (OpenStreetMap). */
    private const CENTRES = [
        'Rabat' => ['34.021845', '-6.840893'],
        'Salé' => ['34.044889', '-6.814017'],
        'Témara' => ['33.928700', '-6.906300'],
        'Casablanca' => ['33.594514', '-7.620028'],
    ];

    /** @var array<string, City> */
    private array $cities = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::CENTRES as $name => [$latitude, $longitude]) {
            $this->cities[$name] = (new City())->setName($name)->setCentre($latitude, $longitude);
            $this->em->persist($this->cities[$name]);
        }

        $this->em->flush();
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $stops ville, latitude, longitude ; une heure entre chaque étape
     */
    private function trip(array $stops, string $departure = '2026-12-01 08:00'): Trip
    {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($departure, $utc);
        $last = \count($stops) - 1;
        $built = [];

        foreach ($stops as $index => [$city, $latitude, $longitude]) {
            $at = $start->modify("+$index hours");
            $built[] = new TripStop($this->cities[$city], 'Point '.$index, $latitude, $longitude, 0 === $index ? null : $at, $index === $last ? null : $at->modify('+10 minutes'));
        }

        $trip = (new Trip())->setVehicle(TestData::vehicle())->setSeatCapacity(3)->setFullPriceCents(10000);
        $trip->replaceItinerary($built, array_fill(0, $last, 50000));

        return $this->saveTrip($trip);
    }

    private function city(string $name): SearchLocation
    {
        [$latitude, $longitude] = self::CENTRES[$name];

        return new SearchLocation($name, $this->cities[$name], new Coordinates($latitude, $longitude));
    }

    /** @return list<array{trip: Trip, boarding: TripStop, alighting: TripStop, boardingMeters: ?int, alightingMeters: ?int}> */
    private function search(SearchLocation $from, SearchLocation $to): array
    {
        $utc = new \DateTimeZone('UTC');

        return self::getContainer()->get(TripRepository::class)->search(
            $from,
            $to,
            new \DateTimeImmutable('2026-12-01 00:00', $utc),
            new \DateTimeImmutable('2026-12-02 00:00', $utc),
            new \DateTimeImmutable('2026-11-30 12:00', $utc),
        );
    }

    public function testAStopInTheCityMatchesEvenFarFromItsCentre(): void
    {
        // Étape à Casablanca, à une dizaine de kilomètres du centre.
        $trip = $this->trip([['Casablanca', '33.520000', '-7.680000'], ['Rabat', '34.014000', '-6.836000']]);

        $results = $this->search($this->city('Casablanca'), $this->city('Rabat'));

        self::assertCount(1, $results);
        self::assertSame($trip->getId(), $results[0]['trip']->getId());
        self::assertGreaterThan(5000, $results[0]['boardingMeters']);
    }

    public function testAStopInANeighbouringLocalityWithinFiveKilometresMatches(): void
    {
        // Salé : à environ 3,6 km du centre de Rabat.
        $this->trip([['Salé', '34.044889', '-6.814017'], ['Casablanca', '33.589300', '-7.591000']]);

        $results = $this->search($this->city('Rabat'), $this->city('Casablanca'));

        self::assertCount(1, $results);
        self::assertSame('Salé', $results[0]['boarding']->getCity()->getName());
        self::assertEqualsWithDelta(3600, $results[0]['boardingMeters'], 300);
    }

    public function testAStopBeyondFiveKilometresInAnotherLocalityDoesNotMatch(): void
    {
        // Témara : à plus de 11 km du centre de Rabat.
        $this->trip([['Témara', '33.928700', '-6.906300'], ['Casablanca', '33.589300', '-7.591000']]);

        self::assertSame([], $this->search($this->city('Rabat'), $this->city('Casablanca')));
    }

    public function testAPlaceWithoutKnownCityIsSearchedByRadiusOnly(): void
    {
        $this->trip([['Rabat', '34.014000', '-6.836000'], ['Casablanca', '33.589300', '-7.591000']]);
        $agdal = new SearchLocation('Agdal', null, new Coordinates('33.998312', '-6.854291'));

        $results = $this->search($agdal, $this->city('Casablanca'));

        self::assertCount(1, $results);
        self::assertEqualsWithDelta(2450, $results[0]['boardingMeters'], 300);
    }

    public function testTheClosestPairOfStopsIsKeptOncePerTrip(): void
    {
        // Rabat-Ville puis Salé : les deux conviennent pour un départ de Rabat ; Rabat-Ville est plus proche.
        $this->trip([['Rabat', '34.014000', '-6.836000'], ['Salé', '34.044889', '-6.814017'], ['Casablanca', '33.589300', '-7.591000']]);

        $results = $this->search($this->city('Rabat'), $this->city('Casablanca'));

        self::assertCount(1, $results);
        self::assertSame(0, $results[0]['boarding']->getPosition());
        self::assertSame(2, $results[0]['alighting']->getPosition());
    }

    public function testTheDirectionOfTravelIsRespected(): void
    {
        $this->trip([['Rabat', '34.014000', '-6.836000'], ['Casablanca', '33.589300', '-7.591000']]);

        self::assertSame([], $this->search($this->city('Casablanca'), $this->city('Rabat')));
    }

    public function testResultsAreSortedByDistanceOfTheBoardingPoint(): void
    {
        $far = $this->trip([['Salé', '34.044889', '-6.814017'], ['Casablanca', '33.589300', '-7.591000']], '2026-12-01 07:00');
        $near = $this->trip([['Rabat', '34.020000', '-6.840000'], ['Casablanca', '33.589300', '-7.591000']], '2026-12-01 15:00');

        $results = $this->search($this->city('Rabat'), $this->city('Casablanca'));

        self::assertSame([$near->getId(), $far->getId()], array_map(static fn (array $row) => $row['trip']->getId(), $results));
    }

    public function testOnlyPublishedTripsLeavingOnTheDayAndStillToComeAreFound(): void
    {
        $cancelled = $this->trip([['Rabat', '34.014000', '-6.836000'], ['Casablanca', '33.589300', '-7.591000']]);
        $this->connection->executeStatement("UPDATE trip SET status = 'cancelled' WHERE id = ?", [$cancelled->getId()]);
        $this->trip([['Rabat', '34.014000', '-6.836000'], ['Casablanca', '33.589300', '-7.591000']], '2026-12-02 08:00');
        $this->trip([['Rabat', '34.014000', '-6.836000'], ['Casablanca', '33.589300', '-7.591000']], '2026-11-30 08:00');

        self::assertSame([], $this->search($this->city('Rabat'), $this->city('Casablanca')));
    }
}
