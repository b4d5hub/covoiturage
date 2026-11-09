<?php

namespace App\DataFixtures;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Service\BookingService;
use App\Service\ReviewService;
use App\Service\TripLifecycleService;
use App\Time\MoroccoTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données de démonstration : personnes fictives et points de rendez-vous publics
 * (coordonnées approximatives, distances routières arrondies).
 * Les dates sont relatives au jour du chargement, pour que la démonstration reste à jour.
 */
final class AppFixtures extends Fixture
{
    private const MEETING_POINTS = [
        'Agadir' => ['Gare routière d\'Agadir', '30.413000', '-9.557000'],
        'Taroudant' => ['Gare routière de Taroudant', '30.469000', '-8.876000'],
        'Essaouira' => ['Gare routière d\'Essaouira', '31.515500', '-9.765000'],
        'Marrakech' => ['Gare routière de Bab Doukkala', '31.638500', '-8.003000'],
        'Safi' => ['Gare routière de Safi', '32.290000', '-9.233000'],
        'El Jadida' => ['Gare routière d\'El Jadida', '33.245000', '-8.500000'],
        'Casablanca' => ['Gare de Casa-Voyageurs', '33.589300', '-7.591000'],
        'Rabat' => ['Gare de Rabat-Ville', '34.014000', '-6.836000'],
        'Meknès' => ['Gare de Meknès El Amir Abdelkader', '33.897000', '-5.548000'],
        'Fès' => ['Gare de Fès', '34.046000', '-5.003000'],
        'Tanger' => ['Gare de Tanger-Ville', '35.772000', '-5.786000'],
        'Béni Mellal' => ['Gare routière de Béni Mellal', '32.335000', '-6.360000'],
    ];

    /** @var array<string, City> */
    private array $cities = [];

    /** @var array<string, User> */
    private array $users = [];

    private string $passwordHash;

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly BookingService $bookings,
        private readonly TripLifecycleService $trips,
        private readonly ReviewService $reviews,
        #[Autowire(env: 'DEMO_PASSWORD')]
        private readonly string $demoPassword,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->createCities($manager);
        $this->createUsers($manager);

        $logan = $this->vehicle($manager, 'youssef', 'Dacia', 'Logan', 4);
        $lodgy = $this->vehicle($manager, 'khadija', 'Dacia', 'Lodgy', 6);
        $octavia = $this->vehicle($manager, 'omar', 'Skoda', 'Octavia', 4);
        $kangoo = $this->vehicle($manager, 'salma', 'Renault', 'Kangoo', 4);
        $this->vehicle($manager, 'omar', 'Peugeot', '308', 4);

        // Trajet à venir : les places sont revendues d'un segment à l'autre.
        $trip = $this->trip($manager, $logan, 3, 320, [
            ['Agadir', 3, null, '07:00'],
            ['Essaouira', 3, '09:30', '09:45'],
            ['Marrakech', 3, '12:30', '13:00'],
            ['Casablanca', 3, '15:45', null],
        ], [173, 190, 240]);
        $this->book($trip, 'amine', 0, 1, 1, -2);
        $this->book($trip, 'nadia', 1, 3, 2, -2);
        $this->book($trip, 'zineb', 2, 3, 1, -1);

        // Trajet à venir : une réservation complète, une partielle, une annulée par le passager.
        $trip = $this->trip($manager, $lodgy, 5, 240, [
            ['Casablanca', 5, null, '08:00'],
            ['Rabat', 5, '09:10', '09:30'],
            ['Meknès', 5, '11:15', '11:30'],
            ['Fès', 5, '12:30', null],
        ], [90, 140, 65]);
        $this->book($trip, 'hamza', 0, 3, 2, -3);
        $this->book($trip, 'imane', 1, 2, 1, -2);
        $this->cancelByPassenger($this->book($trip, 'mehdi', 0, 1, 1, -3), -1);

        // Trajets à venir sans réservation : itinéraire encore modifiable.
        $this->trip($manager, $octavia, 3, 180, [
            ['Marrakech', 7, null, '10:00'],
            ['Agadir', 7, '13:00', null],
        ], [250]);
        $this->trip($manager, $kangoo, 2, 210, [
            ['Casablanca', 4, null, '14:00'],
            ['El Jadida', 4, '15:15', '15:30'],
            ['Safi', 4, '17:30', null],
        ], [100, 150]);

        $trip = $this->trip($manager, $octavia, 3, 260, [
            ['Tanger', 9, null, '06:30'],
            ['Rabat', 9, '09:15', '09:30'],
            ['Casablanca', 9, '10:45', null],
        ], [250, 90]);
        $this->book($trip, 'salma', 1, 2, 1, -1);

        // Trajet annulé par le conducteur.
        $trip = $this->trip($manager, $kangoo, 3, 150, [
            ['Fès', 6, null, '09:00'],
            ['Rabat', 6, '12:00', null],
        ], [200]);
        $this->book($trip, 'amine', 0, 1, 1, -4);
        $this->book($trip, 'imane', 0, 1, 2, -3);
        $this->cancelByDriver($trip, 'Véhicule immobilisé pour réparation.', -1);

        // Trajets effectués, avec des avis dans les deux sens.
        $trip = $this->trip($manager, $logan, 3, 140, [
            ['Marrakech', -10, null, '08:00'],
            ['Casablanca', -10, '10:45', null],
        ], [240]);
        $this->book($trip, 'nadia', 0, 1, 1, -14);
        $this->book($trip, 'hamza', 0, 1, 2, -13);
        $this->complete($trip);
        $this->review($trip, 'nadia', 'youssef', 5, 'Conducteur ponctuel et très prudent.', -9);
        $this->review($trip, 'youssef', 'nadia', 5, 'Passagère agréable, à l\'heure au rendez-vous.', -9);
        $this->review($trip, 'hamza', 'youssef', 4, 'Bon trajet, un peu de retard au départ.', -8);

        $trip = $this->trip($manager, $lodgy, 4, 70, [
            ['Agadir', -5, null, '17:00'],
            ['Taroudant', -5, '18:15', null],
        ], [80]);
        $this->book($trip, 'zineb', 0, 1, 1, -8);
        $this->book($trip, 'mehdi', 0, 1, 1, -7);
        $this->complete($trip);
        $this->review($trip, 'zineb', 'khadija', 5, 'Voiture confortable, je recommande.', -4);
        $this->review($trip, 'khadija', 'zineb', 4, null, -4);

        $trip = $this->trip($manager, $octavia, 3, 195, [
            ['Marrakech', -20, null, '07:30'],
            ['Béni Mellal', -20, '10:15', null],
        ], [195]);
        $this->book($trip, 'amine', 0, 1, 1, -24);
        $this->complete($trip);
        $this->review($trip, 'amine', 'omar', 3, 'Trajet correct.', -18);

        // Trajet passé, pas encore marqué comme effectué par le conducteur.
        $trip = $this->trip($manager, $kangoo, 3, 60, [
            ['Rabat', -1, null, '08:00'],
            ['Casablanca', -1, '09:15', null],
        ], [90]);
        $this->book($trip, 'imane', 0, 1, 1, -3);

        $manager->flush();
    }

    private function createCities(ObjectManager $manager): void
    {
        foreach (array_keys(self::MEETING_POINTS) as $name) {
            $this->cities[$name] = (new City())->setName($name);
            $manager->persist($this->cities[$name]);
        }
    }

    private function createUsers(ObjectManager $manager): void
    {
        $this->passwordHash = $this->hasher->hashPassword(new User(), $this->demoPassword);

        $this->user($manager, 'admin', 'Admin', 'Démo', ['ROLE_ADMIN']);

        foreach ([
            'youssef' => ['Youssef', 'Alami'],
            'khadija' => ['Khadija', 'Bennani'],
            'omar' => ['Omar', 'Chraibi'],
            'salma' => ['Salma', 'Daoudi'],
            'amine' => ['Amine', 'El Fassi'],
            'nadia' => ['Nadia', 'Filali'],
            'hamza' => ['Hamza', 'Ghazali'],
            'zineb' => ['Zineb', 'Haddad'],
            'mehdi' => ['Mehdi', 'Idrissi'],
            'imane' => ['Imane', 'Jettou'],
        ] as $key => [$firstName, $lastName]) {
            $this->user($manager, $key, $firstName, $lastName);
        }
    }

    /** @param list<string> $roles */
    private function user(ObjectManager $manager, string $key, string $firstName, string $lastName, array $roles = []): void
    {
        $user = (new User())
            ->setEmail($key.'@covoiturage.test')
            ->setPassword($this->passwordHash)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles($roles);
        $manager->persist($user);
        $this->users[$key] = $user;
    }

    private function vehicle(ObjectManager $manager, string $owner, string $brand, string $model, int $seats): Vehicle
    {
        $vehicle = (new Vehicle())
            ->setOwner($this->users[$owner])
            ->setBrand($brand)
            ->setModel($model)
            ->setPassengerSeats($seats);
        $manager->persist($vehicle);

        return $vehicle;
    }

    /**
     * @param list<array{string, int, ?string, ?string}> $stops     ville, décalage en jours, arrivée et départ à l'heure du Maroc
     * @param list<int>                                  $distances distances des segments, en kilomètres
     */
    private function trip(ObjectManager $manager, Vehicle $vehicle, int $capacity, int $priceMad, array $stops, array $distances): Trip
    {
        $trip = (new Trip())
            ->setVehicle($vehicle)
            ->setSeatCapacity($capacity)
            ->setFullPriceCents($priceMad * 100);

        $trip->replaceItinerary(
            array_map(function (array $stop): TripStop {
                [$city, $days, $arrival, $departure] = $stop;
                [$address, $latitude, $longitude] = self::MEETING_POINTS[$city];

                return new TripStop(
                    $this->cities[$city],
                    $address,
                    $latitude,
                    $longitude,
                    null === $arrival ? null : $this->localDate($days, $arrival),
                    null === $departure ? null : $this->localDate($days, $departure),
                );
            }, $stops),
            array_map(static fn (int $km): int => $km * 1000, $distances),
        );

        $manager->persist($trip);
        $manager->flush();

        return $trip;
    }

    private function book(Trip $trip, string $passenger, int $boarding, int $alighting, int $seats, int $daysFromDeparture): Booking
    {
        $boardingStop = $trip->getStopAt($boarding);

        return $this->bookings->book(
            $trip,
            $this->users[$passenger],
            $boardingStop,
            $trip->getStopAt($alighting),
            $seats,
            $boardingStop->getDepartureAt()->modify($daysFromDeparture.' days'),
        );
    }

    private function cancelByPassenger(Booking $booking, int $daysFromDeparture): void
    {
        $this->bookings->cancelByPassenger(
            $booking,
            $booking->getPassenger(),
            $booking->getBoardingStop()->getDepartureAt()->modify($daysFromDeparture.' days'),
        );
    }

    private function cancelByDriver(Trip $trip, string $reason, int $daysFromDeparture): void
    {
        $this->trips->cancel($trip, $trip->getDriver(), $reason, $trip->getDepartureAt()->modify($daysFromDeparture.' days'));
    }

    private function complete(Trip $trip): void
    {
        $lastStop = $trip->getStopAt($trip->getStops()->count() - 1);
        $this->trips->complete($trip, $trip->getDriver(), $lastStop->getArrivalAt()->modify('+1 hour'));
    }

    private function review(Trip $trip, string $author, string $target, int $rating, ?string $comment, int $days): void
    {
        $this->reviews->submit($trip, $this->users[$author], $this->users[$target], $rating, $comment, $this->localDate($days, '12:00'));
    }

    private function localDate(int $days, string $time): \DateTimeImmutable
    {
        $day = (new \DateTimeImmutable('today', new \DateTimeZone(MoroccoTime::ZONE)))->modify($days.' days');

        return MoroccoTime::toUtc($day->format('Y-m-d').' '.$time);
    }
}
