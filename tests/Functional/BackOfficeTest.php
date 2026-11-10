<?php

namespace App\Tests\Functional;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BackOfficeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $tester = new CommandTester((new Application(self::$kernel))->find('doctrine:fixtures:load'));
        $tester->execute([], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
    }

    private function user(string $key): User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $key.'@covoiturage.test']);
    }

    private function loginAsAdmin(): void
    {
        $this->client->loginUser($this->user('admin'));
    }

    private function trip(callable $filter): Trip
    {
        foreach ($this->em->getRepository(Trip::class)->findAll() as $trip) {
            if ($filter($trip)) {
                return $trip;
            }
        }

        self::fail('Trajet introuvable');
    }

    private function sqlInt(string $sql): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql);
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/admin/trip');

        self::assertResponseRedirects('/connexion');
    }

    public function testLoginPageIsDisplayed(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
    }

    public function testNonAdministratorIsRefused(): void
    {
        $this->client->loginUser($this->user('amine'));
        $this->client->request('GET', '/admin/trip');

        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{string}> */
    public static function indexPages(): iterable
    {
        foreach (['trip', 'city', 'vehicle', 'booking', 'payment', 'user', 'review', 'notification'] as $page) {
            yield $page => [$page];
        }
    }

    #[DataProvider('indexPages')]
    public function testAdministratorCanListEachObject(string $page): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/'.$page);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Inaccessible', $this->client->getResponse()->getContent());
    }

    #[DataProvider('indexPages')]
    public function testAdministratorCanOpenDetailOfEachObject(string $page): void
    {
        $this->loginAsAdmin();
        $table = 'user' === $page ? 'app_user' : $page;
        $id = $this->sqlInt("SELECT MIN(id) FROM $table");
        $this->client->request('GET', "/admin/$page/$id");

        self::assertResponseIsSuccessful();
    }

    public function testStatesAndRoutesAreDisplayedInFrench(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/admin/trip');

        self::assertSelectorTextContains('body', 'Publié');
        self::assertStringContainsString('Agadir – Casablanca', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Published', $this->client->getResponse()->getContent());
    }

    public function testDatesAreDisplayedAtMoroccoTime(): void
    {
        $this->loginAsAdmin();
        $trip = $this->trip(fn (Trip $t) => 2 === $t->getStops()->count());
        $this->client->request('GET', '/admin/trip/'.$trip->getId());

        $local = $trip->getDepartureAt()->setTimezone(new \DateTimeZone('Africa/Casablanca'))->format('d/m/Y H:i');
        self::assertStringContainsString($local, $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function readOnlyWrites(): iterable
    {
        yield 'réservation' => ['/admin/booking/new'];
        yield 'paiement' => ['/admin/payment/new'];
        yield 'avis' => ['/admin/review/new'];
        yield 'notification' => ['/admin/notification/new'];
    }

    #[DataProvider('readOnlyWrites')]
    public function testRuleDrivenObjectsCannotBeCreatedByHand(string $url): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testVehicleUsedByATripHasALockedOwner(): void
    {
        $this->loginAsAdmin();
        $vehicle = $this->em->getRepository(Trip::class)->findAll()[0]->getVehicle();
        $crawler = $this->client->request('GET', '/admin/vehicle/'.$vehicle->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('select[name="Vehicle[owner]"]')->attr('disabled'));
    }

    public function testVehicleSeatsBelowATripCapacityAreRefused(): void
    {
        $this->loginAsAdmin();
        $vehicle = $this->trip(fn (Trip $t) => 3 === $t->getSeatCapacity())->getVehicle();
        $id = $vehicle->getId();
        $crawler = $this->client->request('GET', "/admin/vehicle/$id/edit");
        $form = $crawler->filter('form[name="Vehicle"]')->form(['Vehicle[passengerSeats]' => 1]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'ne peut pas être inférieur');
        self::assertGreaterThan(1, $this->sqlInt("SELECT passenger_seats FROM vehicle WHERE id = $id"));
    }

    /** @return array<string, mixed> */
    private function validTripValues(int $vehicleId, int $cityA, int $cityB, string $distance = '250000'): array
    {
        return [
            'Trip[vehicle]' => $vehicleId,
            'Trip[seatCapacity]' => 2,
            'Trip[fullPriceCents]' => '199,50',
            'Trip[itinerary][0][city]' => $cityA,
            'Trip[itinerary][0][meetingAddress]' => 'Gare routière',
            'Trip[itinerary][0][latitude]' => '30.41',
            'Trip[itinerary][0][longitude]' => '-9.55',
            'Trip[itinerary][0][departureAt]' => '2026-12-15T08:00',
            'Trip[itinerary][0][distanceToNextMeters]' => $distance,
            'Trip[itinerary][1][city]' => $cityB,
            'Trip[itinerary][1][meetingAddress]' => 'Gare de Marrakech',
            'Trip[itinerary][1][latitude]' => '31.63',
            'Trip[itinerary][1][longitude]' => '-8.00',
            'Trip[itinerary][1][arrivalAt]' => '2026-12-15T11:00',
        ];
    }

    private function submitNewTrip(string $distance = '250000', ?int $vehicleSeatsAbove = null): void
    {
        $vehicle = $this->em->getRepository(Vehicle::class)->findOneBy([]);
        $cities = $this->em->getRepository(City::class)->findAll();
        $crawler = $this->client->request('GET', '/admin/trip/new');
        self::assertResponseIsSuccessful();

        $this->client->submit(
            $crawler->filter('form[name="Trip"]')->form(),
            $this->validTripValues($vehicle->getId(), $cities[0]->getId(), $cities[1]->getId(), $distance),
        );
    }

    public function testTripIsCreatedWithItineraryInUtcAndPriceInCents(): void
    {
        $this->loginAsAdmin();
        $before = $this->sqlInt('SELECT COUNT(*) FROM trip');

        $this->submitNewTrip();

        self::assertResponseRedirects();
        self::assertSame($before + 1, $this->sqlInt('SELECT COUNT(*) FROM trip'));

        $id = $this->sqlInt('SELECT MAX(id) FROM trip');
        self::assertSame(19950, $this->sqlInt("SELECT full_price_cents FROM trip WHERE id = $id"));
        self::assertSame(2, $this->sqlInt("SELECT COUNT(*) FROM trip_stop WHERE trip_id = $id"));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM trip_segment WHERE trip_id = $id"));
        self::assertSame('2026-12-15 07:00:00', $this->em->getConnection()->fetchOne("SELECT departure_at FROM trip_stop WHERE trip_id = $id AND position = 0"));
    }

    public function testTripWithoutSegmentDistanceIsRefused(): void
    {
        $this->loginAsAdmin();
        $before = $this->sqlInt('SELECT COUNT(*) FROM trip');

        $this->submitNewTrip('');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'strictement positive');
        self::assertSame($before, $this->sqlInt('SELECT COUNT(*) FROM trip'));
    }

    public function testFrozenTripKeepsItsItineraryButAcceptsAPriceChange(): void
    {
        $this->loginAsAdmin();
        $trip = $this->trip(fn (Trip $t) => $t->isItineraryFrozen() && 4 === $t->getStops()->count());
        $id = $trip->getId();
        $address = $trip->getStopAt(0)->getMeetingAddress();
        $crawler = $this->client->request('GET', "/admin/trip/$id/edit");

        self::assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('input[name="Trip[itinerary][0][meetingAddress]"]')->attr('disabled'));
        self::assertNotNull($crawler->filter('select[name="Trip[vehicle]"]')->attr('disabled'));

        $this->client->submit($crawler->filter('form[name="Trip"]')->form(['Trip[fullPriceCents]' => '350']));

        self::assertResponseRedirects();
        self::assertSame(35000, $this->sqlInt("SELECT full_price_cents FROM trip WHERE id = $id"));
        self::assertSame($address, $this->em->getConnection()->fetchOne("SELECT meeting_address FROM trip_stop WHERE trip_id = $id AND position = 0"));
    }

    public function testUnfrozenTripItineraryCanBeEdited(): void
    {
        $this->loginAsAdmin();
        $trip = $this->trip(fn (Trip $t) => !$t->isItineraryFrozen() && 2 === $t->getStops()->count());
        $id = $trip->getId();
        $crawler = $this->client->request('GET', "/admin/trip/$id/edit");
        $this->client->submit($crawler->filter('form[name="Trip"]')->form(['Trip[itinerary][0][meetingAddress]' => 'Nouveau point public']));

        self::assertResponseRedirects();
        self::assertSame('Nouveau point public', $this->em->getConnection()->fetchOne("SELECT meeting_address FROM trip_stop WHERE trip_id = $id AND position = 0"));
    }

    public function testCapacityBelowOccupancyIsRefusedWithAMessage(): void
    {
        $this->loginAsAdmin();
        $trip = $this->trip(fn (Trip $t) => $t->isItineraryFrozen() && 4 === $t->getStops()->count() && 3 === $t->getSeatCapacity());
        $id = $trip->getId();
        $crawler = $this->client->request('GET', "/admin/trip/$id/edit");
        $this->client->followRedirects();
        $this->client->submit($crawler->filter('form[name="Trip"]')->form(['Trip[newCapacity]' => 1]));

        self::assertSelectorTextContains('body', 'Capacité inchangée');
        self::assertSame(3, $this->sqlInt("SELECT seat_capacity FROM trip WHERE id = $id"));
    }

    public function testCapacityCanBeRaisedWithinVehicleSeats(): void
    {
        $this->loginAsAdmin();
        $trip = $this->trip(fn (Trip $t) => $t->isItineraryFrozen() && 4 === $t->getStops()->count() && 3 === $t->getSeatCapacity());
        $id = $trip->getId();
        $crawler = $this->client->request('GET', "/admin/trip/$id/edit");
        $this->client->submit($crawler->filter('form[name="Trip"]')->form(['Trip[newCapacity]' => 4]));

        self::assertSame(4, $this->sqlInt("SELECT seat_capacity FROM trip WHERE id = $id"));
    }

    public function testNewAccountPasswordIsHashed(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/admin/user/new');
        $this->client->submit($crawler->filter('form[name="User"]')->form([
            'User[firstName]' => 'Compte',
            'User[lastName]' => 'Test',
            'User[email]' => 'nouveau@covoiturage.test',
            'User[plainPassword]' => 'motdepasse-de-test',
        ]));

        self::assertResponseRedirects();
        $hash = $this->em->getConnection()->fetchOne("SELECT password FROM app_user WHERE email = 'nouveau@covoiturage.test'");
        self::assertNotSame('motdepasse-de-test', $hash);
        self::assertTrue(password_verify('motdepasse-de-test', $hash));
    }

    public function testDeletingAReferencedCityIsRefusedCleanly(): void
    {
        $this->loginAsAdmin();
        $id = $this->sqlInt('SELECT MIN(city_id) FROM trip_stop');
        $crawler = $this->client->request('GET', '/admin/city');
        $token = $crawler->filter('form[method="post"] input[name="token"]')->count() ? $crawler->filter('form[method="post"] input[name="token"]')->attr('value') : '';
        $this->client->request('POST', "/admin/city/$id/delete", ['token' => $token]);

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM city WHERE id = $id"));
    }
}
