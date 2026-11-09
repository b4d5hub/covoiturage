<?php

namespace App\Tests\Support;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Données fictives de test : Agadir – Marrakech – Casablanca par défaut.
 */
final class TestData
{
    private static int $sequence = 0;

    public static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public static function user(): User
    {
        $number = ++self::$sequence;

        return (new User())
            ->setEmail("utilisateur{$number}@example.test")
            ->setPassword('hash-fictif')
            ->setFirstName('Prénom')
            ->setLastName('Nom');
    }

    public static function vehicle(?User $owner = null, int $seats = 4): Vehicle
    {
        return (new Vehicle())
            ->setOwner($owner ?? self::user())
            ->setBrand('Dacia')
            ->setModel('Logan')
            ->setPassengerSeats($seats);
    }

    public static function city(string $name = 'Ville'): City
    {
        return (new City())->setName($name.' '.(++self::$sequence));
    }

    public static function stop(?string $arrival, ?string $departure, ?City $city = null): TripStop
    {
        $utc = new \DateTimeZone('UTC');

        return new TripStop(
            $city ?? self::city(),
            'Gare routière',
            '30.420000',
            '-9.590000',
            null === $arrival ? null : new \DateTimeImmutable($arrival, $utc),
            null === $departure ? null : new \DateTimeImmutable($departure, $utc),
        );
    }

    /** @return list<TripStop> */
    public static function threeStops(): array
    {
        return [
            self::stop(null, '2026-12-01 07:00', self::city('Agadir')),
            self::stop('2026-12-01 10:00', '2026-12-01 10:30', self::city('Marrakech')),
            self::stop('2026-12-01 13:00', null, self::city('Casablanca')),
        ];
    }

    public static function trip(?Vehicle $vehicle = null, int $capacity = 3): Trip
    {
        $trip = (new Trip())
            ->setVehicle($vehicle ?? self::vehicle())
            ->setSeatCapacity($capacity)
            ->setFullPriceCents(20000);
        $trip->replaceItinerary(self::threeStops(), [250000, 240000]);

        return $trip;
    }

    public static function booking(Trip $trip, User $passenger, int $boarding, int $alighting, int $seats = 1): Booking
    {
        return (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setBoardingStop($trip->getStopAt($boarding))
            ->setAlightingStop($trip->getStopAt($alighting))
            ->setSeats($seats)
            ->setSeatPriceCents(1000)
            ->setTotalPriceCents(1000 * $seats);
    }
}
