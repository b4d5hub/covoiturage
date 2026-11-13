<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Tests\Support\FakeOsmResponses;
use App\Time\MoroccoTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Parcours des passagers et des conducteurs sur les données de démonstration.
 */
final class FrontOfficeTest extends WebTestCase
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

    private function loginAs(string $key): void
    {
        $this->client->loginUser($this->user($key));
    }

    private function trip(string $route, TripStatus $status = TripStatus::Published): Trip
    {
        foreach ($this->em->getRepository(Trip::class)->findAll() as $trip) {
            if ($trip->getRouteLabel() === $route && $trip->getStatus() === $status) {
                return $trip;
            }
        }

        self::fail("Trajet introuvable : $route");
    }

    private function sqlInt(string $sql, array $params = []): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql, $params);
    }

    private function localDay(int $days): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone(MoroccoTime::ZONE)))->modify("$days days")->format('Y-m-d');
    }

    private function cityId(string $name): int
    {
        return $this->sqlInt('SELECT id FROM city WHERE name = ?', [$name]);
    }

    private function search(string $from, string $to, string $date): void
    {
        $this->client->request('GET', '/', ['from' => $from, 'to' => $to, 'date' => $date]);
    }

    private function book(Trip $trip, int $boarding, int $alighting, int $seats): void
    {
        $crawler = $this->client->request('GET', '/trajets/'.$trip->getId());
        $this->client->submit($crawler->filter('form[name="booking_request"]')->form([
            'booking_request[boarding]' => $trip->getStopAt($boarding)->getId(),
            'booking_request[alighting]' => $trip->getStopAt($alighting)->getId(),
            'booking_request[seats]' => $seats,
        ]));
    }

    public function testHomePageListsUpcomingTrips(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Agadir – Casablanca');
    }

    public function testSearchFindsAPortionOfALongerTrip(): void
    {
        $this->search('Agadir', 'Essaouira', $this->localDay(3));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '1 trajet disponible');
        self::assertSelectorTextContains('body', 'Agadir → Essaouira');
        self::assertSelectorTextContains('body', 'Youssef A.');
    }

    public function testSearchRespectsTheDirectionOfTravel(): void
    {
        $this->search('Essaouira', 'Agadir', $this->localDay(3));

        self::assertSelectorTextContains('body', 'Aucun trajet');
    }

    public function testSearchUsesTheMoroccanDay(): void
    {
        $this->search('Agadir', 'Essaouira', $this->localDay(4));

        self::assertSelectorTextContains('body', 'Aucun trajet');
    }

    public function testSearchShowsWhenAPortionIsFull(): void
    {
        $this->search('Marrakech', 'Casablanca', $this->localDay(3));

        self::assertSelectorTextContains('body', 'Complet entre ces étapes');
    }

    public function testAKnownCityIsFoundWithoutAccentsOrCaseAndWithoutExternalCall(): void
    {
        FakeOsmResponses::reset();
        $this->search('  agadir ', 'ESSAOUIRA', $this->localDay(3));

        self::assertSelectorTextContains('body', '1 trajet disponible');
        self::assertSelectorTextContains('.section-head', 'Agadir → Essaouira');
        self::assertSame([], FakeOsmResponses::$requests);
    }

    public function testAPlaceIsSearchedOnNominatimThenAroundIt(): void
    {
        FakeOsmResponses::reset();
        self::getContainer()->get('cache.app')->clear();
        // Lieu simulé situé au point de rendez-vous d'Agadir.
        $this->search('Gare routière', 'Essaouira', $this->localDay(3));

        self::assertSelectorTextContains('body', '1 trajet disponible');
        self::assertSelectorTextContains('.section-head', 'Gare routière, Agadir → Essaouira');
        self::assertSame(1, FakeOsmResponses::count('/search'));
        self::assertSame(1, FakeOsmResponses::count('/reverse'));
    }

    public function testAnUnknownPlaceIsReportedBelowItsField(): void
    {
        FakeOsmResponses::reset();
        $this->search('Agadir', 'Lieu imaginaire', $this->localDay(3));

        self::assertSelectorTextContains('input[name="to"] + .form-error-below', 'Lieu introuvable au Maroc');
    }

    public function testWhenPlaceSearchIsDownACityNameStillWorks(): void
    {
        FakeOsmResponses::$mode = 'down';
        $this->search('Agadir', 'Lieu en panne', $this->localDay(3));
        self::assertSelectorTextContains('input[name="to"] + .form-error-below', 'indiquez le nom d\'une ville');

        $this->search('Agadir', 'Essaouira', $this->localDay(3));
        self::assertSelectorTextContains('body', '1 trajet disponible');
        FakeOsmResponses::reset();
    }

    public function testSearchRefusesTheSameCityTwice(): void
    {
        $this->search('Agadir', 'Agadir', $this->localDay(3));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'arrivée dans une autre ville que le départ');
    }

    public function testRegistrationCreatesALoggedInAccount(): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $this->client->submit($crawler->filter('form[name="registration"]')->form([
            'registration[firstName]' => 'Rania',
            'registration[lastName]' => 'Kabbaj',
            'registration[email]' => 'rania@covoiturage.test',
            'registration[plainPassword][first]' => 'mot-de-passe-test',
            'registration[plainPassword][second]' => 'mot-de-passe-test',
        ]));

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', 'Déconnexion');
        $hash = $this->em->getConnection()->fetchOne("SELECT password FROM app_user WHERE email = 'rania@covoiturage.test'");
        self::assertTrue(password_verify('mot-de-passe-test', $hash));
    }

    public function testRegistrationRefusesAnExistingEmail(): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $this->client->submit($crawler->filter('form[name="registration"]')->form([
            'registration[firstName]' => 'Autre',
            'registration[lastName]' => 'Personne',
            'registration[email]' => 'amine@covoiturage.test',
            'registration[plainPassword][first]' => 'mot-de-passe-test',
            'registration[plainPassword][second]' => 'mot-de-passe-test',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un compte existe déjà');
    }

    public function testDemoAccountCanLogInThroughTheForm(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->filter('form')->form([
            '_username' => 'amine@covoiturage.test',
            '_password' => $_ENV['DEMO_PASSWORD'] ?? getenv('DEMO_PASSWORD'),
        ]));

        self::assertResponseRedirects('http://localhost/');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->submit($crawler->filter('form')->form(['_username' => 'amine@covoiturage.test', '_password' => 'incorrect']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'incorrect');
    }

    public function testPrivateSpacesRequireLogin(): void
    {
        foreach (['/mes-reservations', '/conducteur', '/notifications', '/conducteur/trajets/nouveau'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/connexion', null, $url);
        }
    }

    public function testVisitorIsInvitedToLogInBeforeBooking(): void
    {
        $this->client->request('GET', '/trajets/'.$this->trip('Marrakech – Agadir')->getId());

        self::assertSelectorTextContains('body', 'Connectez-vous');
        self::assertSelectorNotExists('form[name="booking_request"]');
    }

    public function testPassengerBooksSeveralSeats(): void
    {
        $this->loginAs('hamza');
        $trip = $this->trip('Marrakech – Agadir');

        $this->book($trip, 0, 1, 2);

        self::assertMatchesRegularExpression('#^/mes-reservations/\d+$#', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Réservation confirmée');
        self::assertSame(36000, $this->sqlInt('SELECT total_price_cents FROM booking WHERE trip_id = ?', [$trip->getId()]));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM payment p JOIN booking b ON b.id = p.booking_id WHERE b.trip_id = ? AND p.status = 'paid'", [$trip->getId()]));
        self::assertSame(1, $this->sqlInt('SELECT itinerary_frozen_at IS NOT NULL FROM trip WHERE id = ?', [$trip->getId()]));
    }

    public function testFullPortionIsRefusedWithAMessage(): void
    {
        $this->loginAs('hamza');
        $trip = $this->trip('Agadir – Casablanca');
        $before = $this->sqlInt('SELECT COUNT(*) FROM booking');

        $this->book($trip, 2, 3, 1);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'pas assez de places');
        self::assertSame($before, $this->sqlInt('SELECT COUNT(*) FROM booking'));
    }

    public function testFreedSeatCanBeBookedOnTheNextPortion(): void
    {
        $this->loginAs('hamza');
        $trip = $this->trip('Agadir – Casablanca');

        // Amine descend à Essaouira : la place est libre d'Agadir à Essaouira seulement pour un second passager.
        $this->book($trip, 0, 1, 1);

        self::assertMatchesRegularExpression('#^/mes-reservations/\d+$#', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testDriverCannotBookOwnTrip(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/trajets/'.$this->trip('Agadir – Casablanca')->getId());

        self::assertSelectorTextContains('body', 'Vous êtes le conducteur');
        self::assertSelectorNotExists('form[name="booking_request"]');
    }

    public function testPassengerCancelsOwnBooking(): void
    {
        $this->loginAs('amine');
        $crawler = $this->client->request('GET', '/mes-reservations');
        $this->client->submit($crawler->filter('form[action$="/annuler"]')->first()->form());

        self::assertResponseRedirects('/mes-reservations');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Réservation annulée');
        self::assertSame(2, $this->sqlInt("SELECT COUNT(*) FROM booking b JOIN app_user u ON u.id = b.passenger_id WHERE u.email = 'amine@covoiturage.test' AND b.status = 'cancelled'"));
    }

    public function testPassengerCannotCancelSomeoneElsesBooking(): void
    {
        $bookingId = $this->sqlInt("SELECT b.id FROM booking b JOIN app_user u ON u.id = b.passenger_id WHERE u.email = 'zineb@covoiturage.test' AND b.status = 'confirmed' ORDER BY b.id LIMIT 1");
        $this->loginAs('amine');

        $this->client->request('POST', "/mes-reservations/$bookingId/annuler", ['_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('confirmed', $this->em->getConnection()->fetchOne('SELECT status FROM booking WHERE id = ?', [$bookingId]));
    }

    public function testNotificationsAreShownThenMarkedAsRead(): void
    {
        $this->loginAs('amine');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('nav .badge', '1');

        $this->client->request('GET', '/notifications');

        self::assertSelectorTextContains('body', 'Véhicule immobilisé pour réparation.');
        self::assertSelectorTextContains('body', 'Nouvelle');
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM notification n JOIN app_user u ON u.id = n.recipient_id WHERE u.email = 'amine@covoiturage.test' AND n.is_read = 0"));
    }

    public function testDriverSpaceShowsTripsAndPassengers(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/conducteur');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Agadir – Casablanca');
        self::assertSelectorTextContains('body', 'Nadia F.');
        self::assertSelectorTextContains('body', 'Historique');
    }

    public function testDriverCompletesATripAfterArrival(): void
    {
        $this->loginAs('salma');
        $trip = $this->trip('Rabat – Casablanca');
        $crawler = $this->client->request('GET', '/conducteur');
        $this->client->submit($crawler->filter('form[action$="/trajets/'.$trip->getId().'/terminer"]')->form());

        self::assertResponseRedirects('/conducteur');
        self::assertSame('completed', $this->em->getConnection()->fetchOne('SELECT status FROM trip WHERE id = ?', [$trip->getId()]));
    }

    public function testDriverCancelsATripWithAReason(): void
    {
        $this->loginAs('khadija');
        $trip = $this->trip('Casablanca – Fès');
        $crawler = $this->client->request('GET', '/conducteur');
        $form = $crawler->filter('form[action$="/trajets/'.$trip->getId().'/annuler"]')->form(['reason' => 'Imprévu familial.']);
        $this->client->submit($form);

        self::assertResponseRedirects('/conducteur');
        self::assertSame('cancelled', $this->em->getConnection()->fetchOne('SELECT status FROM trip WHERE id = ?', [$trip->getId()]));
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM booking WHERE trip_id = ? AND status = 'confirmed'", [$trip->getId()]));
        self::assertSame(2, $this->sqlInt("SELECT COUNT(*) FROM notification WHERE message LIKE '%Imprévu familial.%'"));
    }

    public function testCancellationWithoutReasonIsRefused(): void
    {
        $this->loginAs('khadija');
        $trip = $this->trip('Casablanca – Fès');
        $crawler = $this->client->request('GET', '/conducteur');
        $this->client->submit($crawler->filter('form[action$="/trajets/'.$trip->getId().'/annuler"]')->form(['reason' => '  ']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'motif d\'annulation est obligatoire');
        self::assertSame('published', $this->em->getConnection()->fetchOne('SELECT status FROM trip WHERE id = ?', [$trip->getId()]));
    }

    public function testOnlyTheDriverCanCancelATrip(): void
    {
        $trip = $this->trip('Casablanca – Fès');
        $this->loginAs('amine');

        $this->client->request('POST', '/conducteur/trajets/'.$trip->getId().'/annuler', ['reason' => 'x', '_token' => 'x']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewDriverAddsAVehicleThenPublishesATrip(): void
    {
        $this->loginAs('hamza');

        $this->client->request('GET', '/conducteur/trajets/nouveau');
        self::assertResponseRedirects('/conducteur/vehicules');

        $crawler = $this->client->request('GET', '/conducteur/vehicules');
        $this->client->submit($crawler->filter('form[name="vehicle"]')->form([
            'vehicle[brand]' => 'Hyundai',
            'vehicle[model]' => 'Accent',
            'vehicle[passengerSeats]' => 3,
        ]));
        self::assertResponseRedirects('/conducteur/vehicules');

        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau');
        $this->client->submit($crawler->filter('form[name="publish_trip"]')->form([
            'publish_trip[seatCapacity]' => 3,
            'publish_trip[fullPriceCents]' => '95,50',
            'publish_trip[itinerary][0][city]' => $this->cityId('Rabat'),
            'publish_trip[itinerary][0][meetingAddress]' => 'Gare de Rabat-Ville',
            'publish_trip[itinerary][0][latitude]' => '34.014',
            'publish_trip[itinerary][0][longitude]' => '-6.836',
            'publish_trip[itinerary][0][departureAt]' => $this->localDay(6).'T09:00',
            'publish_trip[itinerary][0][distanceToNextMeters]' => '140000',
            'publish_trip[itinerary][1][city]' => $this->cityId('Meknès'),
            'publish_trip[itinerary][1][meetingAddress]' => 'Gare de Meknès',
            'publish_trip[itinerary][1][latitude]' => '33.897',
            'publish_trip[itinerary][1][longitude]' => '-5.548',
            'publish_trip[itinerary][1][arrivalAt]' => $this->localDay(6).'T11:00',
        ]));

        self::assertResponseRedirects();
        $id = $this->sqlInt("SELECT t.id FROM trip t JOIN vehicle v ON v.id = t.vehicle_id WHERE v.brand = 'Hyundai'");
        self::assertSame(9550, $this->sqlInt('SELECT full_price_cents FROM trip WHERE id = ?', [$id]));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM trip_segment WHERE trip_id = ?', [$id]));
        self::assertSame(
            MoroccoTime::toUtc($this->localDay(6).' 09:00')->format('Y-m-d H:i:s'),
            $this->em->getConnection()->fetchOne('SELECT departure_at FROM trip_stop WHERE trip_id = ? AND position = 0', [$id]),
        );
    }

    public function testPublishingRefusesCapacityAboveTheVehicle(): void
    {
        $this->loginAs('youssef');
        $before = $this->sqlInt('SELECT COUNT(*) FROM trip');
        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau');
        $this->client->submit($crawler->filter('form[name="publish_trip"]')->form([
            'publish_trip[seatCapacity]' => 9,
            'publish_trip[fullPriceCents]' => '100',
            'publish_trip[itinerary][0][city]' => $this->cityId('Rabat'),
            'publish_trip[itinerary][0][meetingAddress]' => 'Gare',
            'publish_trip[itinerary][0][latitude]' => '34',
            'publish_trip[itinerary][0][longitude]' => '-6.8',
            'publish_trip[itinerary][0][departureAt]' => $this->localDay(6).'T09:00',
            'publish_trip[itinerary][0][distanceToNextMeters]' => '1000',
            'publish_trip[itinerary][1][city]' => $this->cityId('Fès'),
            'publish_trip[itinerary][1][meetingAddress]' => 'Gare',
            'publish_trip[itinerary][1][latitude]' => '34',
            'publish_trip[itinerary][1][longitude]' => '-5',
            'publish_trip[itinerary][1][arrivalAt]' => $this->localDay(6).'T11:00',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Ce véhicule ne propose que 4 places passagers.');
        self::assertSame($before, $this->sqlInt('SELECT COUNT(*) FROM trip'));
    }

    public function testDriverReviewsAPassengerOnce(): void
    {
        $this->loginAs('youssef');
        $trip = $this->trip('Marrakech – Casablanca', TripStatus::Completed);
        $target = $this->user('hamza');
        $url = '/trajets/'.$trip->getId().'/avis/'.$target->getId();

        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->filter('form[name="review"]')->form(['review[rating]' => '4', 'review[comment]' => 'Passager ponctuel.']));
        self::assertResponseRedirects('/conducteur');

        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->filter('form[name="review"]')->form(['review[rating]' => '5']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'déjà été déposé');
    }

    public function testPassengerSeesReviewLinkOnlyWithinFourteenDays(): void
    {
        $this->loginAs('mehdi');
        $this->client->request('GET', '/mes-reservations');
        self::assertSelectorTextContains('body', 'Noter le conducteur');

        $this->loginAs('amine');
        $this->client->request('GET', '/mes-reservations');
        self::assertSelectorTextNotContains('body', 'Noter le conducteur');
    }

    public function testExpiredReviewIsRefused(): void
    {
        $this->loginAs('amine');
        $trip = $this->trip('Marrakech – Béni Mellal', TripStatus::Completed);
        $crawler = $this->client->request('GET', '/trajets/'.$trip->getId().'/avis/'.$trip->getDriver()->getId());
        $this->client->submit($crawler->filter('form[name="review"]')->form(['review[rating]' => '5']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', '14 jours');
    }

    public function testProfileShowsRatingAndReviewsWithoutPersonalDetails(): void
    {
        $youssef = $this->user('youssef');
        $this->client->request('GET', '/profils/'.$youssef->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Youssef A.');
        self::assertSelectorTextContains('body', '4,5/5');
        self::assertSelectorTextContains('body', 'Conducteur ponctuel et très prudent.');
        self::assertStringNotContainsString('youssef@covoiturage.test', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Alami', $this->client->getResponse()->getContent());
    }
}
