<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Time\MoroccoTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Corrections issues de la revue de l'interface : messages, formulaires, navigation, accessibilité.
 */
final class InterfaceTest extends WebTestCase
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

    private function loginAs(string $key): void
    {
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => $key.'@covoiturage.test']));
    }

    private function trip(string $route): Trip
    {
        foreach ($this->em->getRepository(Trip::class)->findAll() as $trip) {
            if ($trip->getRouteLabel() === $route && TripStatus::Published === $trip->getStatus()) {
                return $trip;
            }
        }

        self::fail("Trajet introuvable : $route");
    }

    private function cityId(string $name): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM city WHERE name = ?', [$name]);
    }

    private function localDay(int $days): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone(MoroccoTime::ZONE)))->modify("$days days")->format('Y-m-d');
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

    private function body(): string
    {
        return $this->client->getResponse()->getContent();
    }

    public function testBookingConfirmationUsesCorrectFrench(): void
    {
        $this->loginAs('hamza');

        $this->book($this->trip('Agadir – Casablanca'), 0, 1, 1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '1 place d\'Agadir à Essaouira.');

        $this->book($this->trip('Marrakech – Agadir'), 0, 1, 2);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '2 places de Marrakech à Agadir.');
    }

    public function testRefusalSaysHowManySeatsRemain(): void
    {
        $this->loginAs('hamza');

        $this->book($this->trip('Agadir – Casablanca'), 1, 2, 2);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'il en reste 1');
    }

    public function testRefusalSaysWhenAPortionIsFull(): void
    {
        $this->loginAs('hamza');

        $this->book($this->trip('Agadir – Casablanca'), 2, 3, 1);

        self::assertSelectorTextContains('body', 'elle est complète');
    }

    public function testTripPageGivesPortionDataForTheLiveTotal(): void
    {
        $this->loginAs('hamza');
        $trip = $this->trip('Agadir – Casablanca');
        $crawler = $this->client->request('GET', '/trajets/'.$trip->getId());

        $portions = json_decode($crawler->filter('form[name="booking_request"]')->attr('data-portions'), true);
        $key = $trip->getStopAt(0)->getId().'-'.$trip->getStopAt(1)->getId();

        self::assertSame(['seatPriceCents' => 9181, 'available' => 2, 'open' => true], $portions[$key]);
        self::assertSelectorExists('[data-booking-total][aria-live="polite"]');
    }

    public function testSignInRestoresTheSelectedPortionAfterAnInvalidPassword(): void
    {
        $trip = $this->trip('Casablanca – Safi');
        $boarding = $trip->getStopAt(0)->getId();
        $alighting = $trip->getStopAt(1)->getId();
        $query = "?depart=$boarding&arrivee=$alighting";
        $path = '/trajets/'.$trip->getId().$query;

        $page = $this->client->request('GET', $path);
        self::assertSelectorTextContains('#reserver .escale-fare', '84,00');
        $this->client->click($page->selectLink('Se connecter pour réserver')->link());
        self::assertSelectorTextContains('.escale-handoff', 'Gare routière d\'El Jadida');

        $credentials = ['_username' => 'hamza@covoiturage.test', '_password' => 'incorrect'];
        $this->client->submitForm('Se connecter', $credentials);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.escale-handoff', 'El Jadida');

        $credentials['_password'] = $_ENV['DEMO_PASSWORD'] ?? getenv('DEMO_PASSWORD');
        $this->client->submitForm('Se connecter', $credentials);
        self::assertStringEndsWith($path.'#reserver', $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertSame((string) $alighting, $page->filter('#booking_request_alighting option[selected]')->attr('value'));
        self::assertSelectorTextContains('[data-booking-total]', 'Paiement simulé');
        self::assertSelectorTextContains('[data-summary="total"]', '84,00');
    }

    public function testFullPortionDoesNotOfferSignInForThatPortion(): void
    {
        $trip = $this->trip('Agadir – Casablanca');
        $boarding = $trip->getStopAt(2)->getId();
        $alighting = $trip->getStopAt(3)->getId();
        $this->client->request('GET', '/trajets/'.$trip->getId()."?depart=$boarding&arrivee=$alighting");

        self::assertSelectorTextContains('#reserver .status', 'Complet entre ces étapes');
        self::assertSelectorNotExists('#reserver a[href^="/connexion"]');
        self::assertSelectorTextContains('#reserver a[href="#itineraire"]', 'Voir les autres étapes');
    }

    public function testRegistrationReturnsToTheSelectedPortion(): void
    {
        $trip = $this->trip('Casablanca – Safi');
        $boarding = $trip->getStopAt(1)->getId();
        $alighting = $trip->getStopAt(2)->getId();
        $query = "?depart=$boarding&arrivee=$alighting";
        $path = '/trajets/'.$trip->getId().$query;

        $page = $this->client->request('GET', $path);
        $this->client->click($page->selectLink('créez un compte')->link());
        self::assertSelectorTextContains('.escale-handoff', 'El Jadida');

        $temporary = bin2hex(random_bytes(16));
        $this->client->submitForm('Créer mon compte', [
            'registration[firstName]' => 'Nora',
            'registration[lastName]' => 'Démo',
            'registration[email]' => 'nora-escale@covoiturage.test',
            'registration[plainPassword][first]' => $temporary,
            'registration[plainPassword][second]' => $temporary,
        ]);
        self::assertResponseRedirects($path.'#reserver');
        $page = $this->client->followRedirect();
        self::assertSame((string) $boarding, $page->filter('#booking_request_boarding option[selected]')->attr('value'));
        self::assertSelectorTextContains('[data-summary="portion"]', 'El Jadida → Safi');
    }

    public function testTripPageHidesInternalStatusAndLabelsItsTables(): void
    {
        $this->client->request('GET', '/trajets/'.$this->trip('Marrakech – Agadir')->getId());

        self::assertSelectorNotExists('.trip-head .status');
        self::assertCount(1, $this->client->getCrawler()->filter('table caption'));
        self::assertSelectorExists('th[scope="row"]');
    }

    public function testPublishFormReportsEveryErrorAtOnce(): void
    {
        $this->loginAs('youssef');
        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau');
        $this->client->submit($crawler->filter('form[name="publish_trip"]')->form([
            'publish_trip[seatCapacity]' => 9,
            'publish_trip[fullPriceCents]' => 'abc',
            'publish_trip[itinerary][0][city]' => $this->cityId('Rabat'),
            'publish_trip[itinerary][0][meetingAddress]' => 'Gare',
            'publish_trip[itinerary][0][latitude]' => '34',
            'publish_trip[itinerary][0][longitude]' => '-6.8',
            'publish_trip[itinerary][1][city]' => $this->cityId('Fès'),
            'publish_trip[itinerary][1][meetingAddress]' => 'Gare',
            'publish_trip[itinerary][1][latitude]' => '34',
            'publish_trip[itinerary][1][longitude]' => '-5',
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = $this->body();

        foreach ([
            'Saisissez un montant valide',
            'Ce véhicule ne propose que 4 places passagers.',
            'Étape 1 : indiquez l&#039;heure de départ.',
            'Étape 1 : indiquez la distance jusqu&#039;à l&#039;étape suivante, en mètres.',
            'Étape 2 : indiquez l&#039;heure d&#039;arrivée.',
            'Le trajet n\'a pas été publié',
        ] as $message) {
            self::assertStringContainsString($message, $body);
        }
    }

    public function testPublishFormChecksTheOrderOfTimes(): void
    {
        $this->loginAs('youssef');
        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau');
        $this->client->submit($crawler->filter('form[name="publish_trip"]')->form([
            'publish_trip[seatCapacity]' => 3,
            'publish_trip[fullPriceCents]' => '100',
            'publish_trip[itinerary][0][city]' => $this->cityId('Rabat'),
            'publish_trip[itinerary][0][meetingAddress]' => 'Gare',
            'publish_trip[itinerary][0][latitude]' => '34',
            'publish_trip[itinerary][0][longitude]' => '-6.8',
            'publish_trip[itinerary][0][departureAt]' => $this->localDay(6).'T12:00',
            'publish_trip[itinerary][0][distanceToNextMeters]' => '1000',
            'publish_trip[itinerary][1][city]' => $this->cityId('Fès'),
            'publish_trip[itinerary][1][meetingAddress]' => 'Gare',
            'publish_trip[itinerary][1][latitude]' => '34',
            'publish_trip[itinerary][1][longitude]' => '-5',
            'publish_trip[itinerary][1][arrivalAt]' => $this->localDay(6).'T10:00',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Étape 2 : l&#039;arrivée doit suivre le départ de l&#039;étape précédente.', $this->body());
    }

    public function testPublishFormNumbersItsStops(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/conducteur/trajets/nouveau');

        self::assertSelectorTextContains('[data-collection-list] fieldset:nth-of-type(1) legend', 'Étape 1');
        self::assertSelectorTextContains('[data-collection-list] fieldset:nth-of-type(2) legend', 'Étape 2');
        self::assertSelectorExists('[data-stop-field="arrival"]');
    }

    public function testSearchRefusesPastDates(): void
    {
        $crawler = $this->client->request('GET', '/', ['from' => 'Agadir', 'to' => 'Essaouira', 'date' => $this->localDay(-1)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'à partir d\'aujourd\'hui');
        self::assertSame($this->localDay(0), $crawler->filter('input[name="date"]')->attr('min'));
    }

    public function testSearchErrorIsShownBelowItsField(): void
    {
        $crawler = $this->client->request('GET', '/', ['from' => 'Agadir', 'to' => 'Agadir', 'date' => $this->localDay(3)]);

        self::assertCount(1, $crawler->filter('input[name="to"] + .form-error-below li'));
    }

    public function testEmptySearchSuggestsNearbyDays(): void
    {
        $crawler = $this->client->request('GET', '/', ['from' => 'Essaouira', 'to' => 'Agadir', 'date' => $this->localDay(3)]);

        $links = $crawler->filter('a[href*="date="]')->each(fn ($a) => $a->attr('href'));
        self::assertNotEmpty(array_filter($links, fn (string $href) => str_contains($href, 'date='.$this->localDay(2))));
        self::assertNotEmpty(array_filter($links, fn (string $href) => str_contains($href, 'date='.$this->localDay(4))));
    }

    public function testUpcomingCardsShowSeatsOnTheWholeRoute(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('body', 'Complet du départ à l\'arrivée');
        self::assertSelectorTextContains('body', '3 places libres du départ à l\'arrivée');
    }

    public function testBookingsAreSplitWithOneClearStatus(): void
    {
        $this->loginAs('nadia');
        $this->client->request('GET', '/mes-reservations');

        self::assertSelectorTextContains('body', 'À venir');
        self::assertSelectorTextContains('body', 'Historique');
        self::assertSelectorTextContains('body', 'Effectuée');
        self::assertSelectorTextNotContains('body', 'Trajet effectué');

        $this->loginAs('amine');
        $this->client->request('GET', '/mes-reservations');
        self::assertSelectorTextContains('body', 'Annulée par le conducteur');
    }

    public function testCancellationPromptMentionsTheRefund(): void
    {
        $this->loginAs('amine');
        $crawler = $this->client->request('GET', '/mes-reservations');

        self::assertStringContainsString('remboursé', $crawler->filter('form[action$="/annuler"]')->first()->attr('data-confirm'));
    }

    public function testNavigationMarksTheCurrentPage(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('nav a[aria-current="page"]', 'Rechercher');

        $this->loginAs('hamza');
        $this->client->request('GET', '/mes-reservations');
        self::assertSelectorTextContains('nav a[aria-current="page"]', 'Mes réservations');
        self::assertSelectorTextContains('nav', 'Mon profil public');
    }

    public function testEveryPageHasASkipLink(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorExists('a.skip-link[href="#contenu"]');
        self::assertSelectorExists('main#contenu');
    }

    public function testRegistrationExplainsThePasswordRuleAndHelpsAutofill(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        self::assertSelectorTextContains('body', '8 caractères au minimum');
        self::assertSame('given-name', $crawler->filter('input[name="registration[firstName]"]')->attr('autocomplete'));
        self::assertSame('family-name', $crawler->filter('input[name="registration[lastName]"]')->attr('autocomplete'));
        self::assertSame('email', $crawler->filter('input[name="registration[email]"]')->attr('autocomplete'));
        self::assertSame('new-password', $crawler->filter('input[name="registration[plainPassword][first]"]')->attr('autocomplete'));
    }

    public function testTripPageDrawsTheRouteLineAndLinksEachOpenPortion(): void
    {
        $this->loginAs('hamza');
        $trip = $this->trip('Agadir – Casablanca');
        $crawler = $this->client->request('GET', '/trajets/'.$trip->getId());

        $stops = $crawler->filter('[data-route-line] [data-stop-id]');
        self::assertCount($trip->getStops()->count(), $stops);
        self::assertSame((string) $trip->getStopAt(0)->getId(), $stops->first()->attr('data-stop-id'));
        self::assertSame('Agadir', $stops->first()->attr('data-city'));

        // Le tableau des prix précise les points de rendez-vous, pas seulement les villes.
        $points = $trip->getStopAt(0)->getMeetingAddress().' → '.$trip->getStopAt(1)->getMeetingAddress();
        self::assertSelectorTextContains('#itineraire details#portions tbody tr:first-child .portion-points', $points);

        // Chaque portion ouverte et non complète préremplit le formulaire, même sans JavaScript.
        $first = $trip->getStopAt(0)->getId();
        $second = $trip->getStopAt(1)->getId();
        self::assertSelectorExists(\sprintf('a[data-pick-portion][href*="depart=%d"][href*="arrivee=%d"]', $first, $second));

        // La portion Essaouira → Marrakech est complète : aucun lien pour la choisir.
        $third = $trip->getStopAt(2)->getId();
        $fourth = $trip->getStopAt(3)->getId();
        self::assertSelectorNotExists(\sprintf('a[data-pick-portion][data-boarding="%d"][data-alighting="%d"]', $third, $fourth));
    }

    public function testPortionLinkPreselectsTheBookingForm(): void
    {
        $this->loginAs('hamza');
        $trip = $this->trip('Agadir – Casablanca');
        $crawler = $this->client->request('GET', '/trajets/'.$trip->getId(), ['depart' => $trip->getStopAt(1)->getId(), 'arrivee' => $trip->getStopAt(2)->getId()]);

        self::assertSame((string) $trip->getStopAt(1)->getId(), $crawler->filter('select[name="booking_request[boarding]"] option[selected]')->attr('value'));
        self::assertSame((string) $trip->getStopAt(2)->getId(), $crawler->filter('select[name="booking_request[alighting]"] option[selected]')->attr('value'));
    }

    public function testInvalidFieldsAreLinkedToTheirErrorMessages(): void
    {
        $crawler = $this->client->request('GET', '/inscription');
        $this->client->submit($crawler->filter('form[name="registration"]')->form([
            'registration[firstName]' => 'Sara',
            'registration[lastName]' => 'Test',
            'registration[email]' => 'pas-une-adresse',
            'registration[plainPassword][first]' => 'motdepasse',
            'registration[plainPassword][second]' => 'motdepasse',
        ]));

        $email = $this->client->getCrawler()->filter('input[name="registration[email]"]');
        self::assertSame('true', $email->attr('aria-invalid'));
        self::assertStringContainsString('registration_email_errors', (string) $email->attr('aria-describedby'));
        self::assertSelectorExists('#registration_email_errors li');
        self::assertSelectorExists('.field--invalid input[name="registration[email]"]');
    }

    public function testDriverDashboardExplainsFrozenItineraryAndShowsSegmentOccupancy(): void
    {
        $this->loginAs('youssef');
        $crawler = $this->client->request('GET', '/conducteur');

        self::assertSelectorTextContains('body', 'Itinéraire verrouillé');
        self::assertSelectorExists('.meter[role="img"][aria-label="3 places occupées sur 3"]');
        self::assertStringContainsString('remboursés', (string) $crawler->filter('form[action$="/annuler"]')->first()->attr('data-confirm'));
    }

    public function testTripWithoutBookingIsNotPresentedAsFrozen(): void
    {
        $trip = $this->trip('Marrakech – Agadir');
        self::assertFalse($trip->isItineraryFrozen());

        $this->loginAs('omar');
        $crawler = $this->client->request('GET', '/conducteur');
        $card = $crawler->filter('article')->reduce(fn ($article) => str_contains($article->filter('h3')->text(), 'Marrakech – Agadir'));

        self::assertCount(1, $card);
        self::assertStringContainsString('Aucun passager pour le moment', $card->text());
        self::assertStringNotContainsString('Itinéraire verrouillé', $card->text());
    }

    public function testPublishFormOffersStopToolsAndVehicleCapacity(): void
    {
        $this->loginAs('youssef');
        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau');

        self::assertSame('4', $crawler->filter('select[name="publish_trip[vehicle]"] option[data-seats]')->first()->attr('data-seats'));
        self::assertCount(2, $crawler->filter('[data-collection-list] [data-move="-1"]'));
        self::assertCount(2, $crawler->filter('[data-collection-list] [data-collection-remove]'));
        self::assertSelectorExists('[data-recap]');
        self::assertSelectorTextContains('body', 'ne pourra plus être modifié');
        self::assertSame('', $crawler->filter('select[name="publish_trip[itinerary][0][city]"] option')->first()->attr('value'));
    }

    public function testBookingsExplainWhyAReviewIsNoLongerPossible(): void
    {
        // Sans son avis, Amine a dépassé les 14 jours pour noter le trajet Marrakech – Béni Mellal.
        $amine = $this->em->getRepository(User::class)->findOneBy(['email' => 'amine@covoiturage.test']);
        $this->em->getConnection()->executeStatement('DELETE FROM review WHERE author_id = ?', [$amine->getId()]);

        $this->loginAs('amine');
        $this->client->request('GET', '/mes-reservations');

        self::assertSelectorTextContains('body', 'Délai de 14 jours pour donner un avis dépassé');
        self::assertSelectorTextNotContains('body', 'Noter le conducteur');
    }

    public function testBookingsShowWhenAReviewWasAlreadyGiven(): void
    {
        $this->loginAs('mehdi');
        $crawler = $this->client->request('GET', '/mes-reservations');
        $this->client->click($crawler->selectLink('Noter le conducteur')->link());
        $this->client->submit($this->client->getCrawler()->filter('form[name="review"]')->form(['review[rating]' => '5']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Avis déposé');
        self::assertSelectorTextNotContains('body', 'Noter le conducteur');
    }

    public function testSearchResultsShowDurationAndDriverRating(): void
    {
        $day = MoroccoTime::toLocal($this->trip('Agadir – Casablanca')->getDepartureAt())->format('Y-m-d');
        $this->client->request('GET', '/', ['from' => 'Agadir', 'to' => 'Essaouira', 'date' => $day]);

        self::assertSelectorExists('.result .escale-duration');
        self::assertSelectorTextContains('.result .rating', '4,5');
    }

    public function testAccountMenuIsAnAccessibleDisclosure(): void
    {
        $this->loginAs('hamza');
        $this->client->request('GET', '/');

        self::assertSelectorExists('nav [data-menu-button][aria-expanded="false"][aria-controls="account-menu"]');
        self::assertSelectorExists('#account-menu a[href="/conducteur/vehicules"]');
        self::assertSelectorTextContains('nav .nav-bell', 'Notifications');
    }

    public function testBackOfficeUsesTheApplicationColoursAndLinksBackToTheSite(): void
    {
        $this->loginAs('admin');
        $this->client->request('GET', '/admin/trip');

        self::assertSelectorExists('link[href*="css/admin.css"]');
        self::assertSelectorTextContains('body', 'Retour au site');
    }

    public function testTripPageProposesTheWholeRouteByDefault(): void
    {
        // Zineb n'a pas de réservation sur ce trajet.
        $this->loginAs('zineb');
        $trip = $this->trip('Casablanca – Fès');
        $crawler = $this->client->request('GET', '/trajets/'.$trip->getId());
        $last = $trip->getStops()->count() - 1;

        self::assertSame((string) $trip->getStopAt(0)->getId(), $crawler->filter('select[name="booking_request[boarding]"] option[selected]')->attr('value'));
        self::assertSame((string) $trip->getStopAt($last)->getId(), $crawler->filter('select[name="booking_request[alighting]"] option[selected]')->attr('value'));
        self::assertSelectorTextContains('label[for="booking_request_boarding"]', 'Je monte à');
    }

    public function testSearchSetsFullTripsApart(): void
    {
        $day = MoroccoTime::toLocal($this->trip('Agadir – Casablanca')->getDepartureAt())->format('Y-m-d');
        $this->client->request('GET', '/', ['from' => 'Marrakech', 'to' => 'Casablanca', 'date' => $day]);

        self::assertSelectorTextContains('#results-title', 'Aucune place disponible');
        self::assertSelectorTextContains('.results-subhead', 'Complets');
        self::assertSelectorExists('.result.is-unavailable');
    }

    public function testDriverSeesTheirOwnTripFlaggedInSearch(): void
    {
        $this->loginAs('youssef');
        $day = MoroccoTime::toLocal($this->trip('Agadir – Casablanca')->getDepartureAt())->format('Y-m-d');
        $this->client->request('GET', '/', ['from' => 'Agadir', 'to' => 'Essaouira', 'date' => $day]);

        self::assertSelectorTextContains('.result.is-unavailable', 'Votre trajet');
        self::assertSelectorTextContains('.result.is-unavailable', 'Voir mon trajet');
        self::assertSelectorNotExists('.result.is-unavailable a[href*="depart="]');
    }

    public function testHomeNeverFeaturesAFullTrip(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorExists('.next-departure');
        self::assertSelectorTextNotContains('.next-departure', 'Complet');
    }

    public function testPublishFormFoldsTechnicalFieldsUnlessTheyHaveErrors(): void
    {
        $this->loginAs('youssef');
        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau');
        self::assertSelectorExists('[data-stop-advanced] input[name="publish_trip[itinerary][0][latitude]"]');
        self::assertNull($crawler->filter('[data-stop-advanced]')->first()->attr('open'));

        $this->client->submit($crawler->filter('form[name="publish_trip"]')->form([
            'publish_trip[seatCapacity]' => 3,
            'publish_trip[fullPriceCents]' => '100',
        ]));
        self::assertNotNull($this->client->getCrawler()->filter('[data-stop-advanced]')->first()->attr('open'));
    }

    public function testHomeDrawsTheFeaturedTripOnAMap(): void
    {
        $crawler = $this->client->request('GET', '/');

        $map = json_decode((string) $crawler->filter('#home-map')->attr('data-map'), true);
        self::assertNotEmpty($map['path']);
        self::assertGreaterThanOrEqual(2, \count($map['stops']));
        self::assertSelectorExists('.hero-map-credit a[href="https://www.openstreetmap.org/copyright"]');
        self::assertSelectorExists('a.next-departure');
    }

    public function testMobileBookingBarIsOnlyOfferedToPassengers(): void
    {
        $trip = $this->trip('Casablanca – Fès');

        // Zineb n'a pas de réservation sur ce trajet.
        $this->loginAs('zineb');
        $this->client->request('GET', '/trajets/'.$trip->getId());
        self::assertSelectorExists('[data-mobile-book-bar] a[href="#reserver"]');

        $this->loginAs($trip->getDriver()->getEmail() === 'khadija@covoiturage.test' ? 'khadija' : 'youssef');
        $this->client->request('GET', '/trajets/'.$trip->getId());
        self::assertSelectorNotExists('[data-mobile-book-bar]');
    }

    public function testTheTypefacesAreServedByTheApplication(): void
    {
        $dir = self::getContainer()->getParameter('kernel.project_dir');
        $css = (string) file_get_contents($dir.'/public/css/escale-tokens.css');

        foreach (['Chivo' => 'Chivo', 'RethinkSans' => 'RethinkSans'] as $file => $licence) {
            self::assertStringContainsString('url("../fonts/'.$file.'-Variable.ttf")', $css);
            self::assertFileExists($dir.'/public/fonts/'.$file.'-Variable.ttf');
            self::assertFileExists($dir.'/public/fonts/OFL-'.$licence.'.txt');
        }

        // Aucune police déclarée sans fichier, ni fichier de police inutilisé.
        self::assertFileDoesNotExist($dir.'/public/fonts/PlusJakartaSans-Variable.ttf');
    }

    public function testTripPageShowsAnExistingBookingInsteadOfTheForm(): void
    {
        $this->loginAs('hamza');
        $trip = $this->trip('Casablanca – Fès');
        $this->client->request('GET', '/trajets/'.$trip->getId());

        self::assertSelectorNotExists('form[name="booking_request"]');
        self::assertSelectorNotExists('[data-mobile-book-bar]');
        self::assertSelectorTextContains('#booking-title', 'Votre réservation');
        self::assertSelectorExists('.my-booking a[href^="/mes-reservations/"]');
    }

    public function testBookingPageIsATicketWithPaymentDates(): void
    {
        $this->loginAs('hamza');
        $this->book($this->trip('Marrakech – Agadir'), 0, 1, 1);
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Marrakech → Agadir');
        self::assertSelectorTextContains('body', 'Réservation confirmée');
        self::assertSelectorTextContains('.receipt-state', 'Paiement simulé le');
        self::assertSelectorExists('form[action$="/annuler"] input[name="_back"][value="show"]');
    }

    public function testCancellingFromTheBookingPageReturnsToIt(): void
    {
        $this->loginAs('hamza');
        $this->book($this->trip('Marrakech – Agadir'), 0, 1, 1);
        $crawler = $this->client->followRedirect();
        $url = $this->client->getRequest()->getPathInfo();

        $this->client->submit($crawler->filter('form[action$="/annuler"]')->form());

        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.receipt-state', 'Remboursé intégralement le');
        self::assertSelectorTextContains('body', 'Réservation annulée le');
    }

    public function testBookingPageIsPrivate(): void
    {
        $this->loginAs('hamza');
        $this->book($this->trip('Marrakech – Agadir'), 0, 1, 1);
        $url = (string) $this->client->getResponse()->headers->get('Location');

        $this->loginAs('nadia');
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDriverChangesSeatsAndPriceBeforeDeparture(): void
    {
        $this->loginAs('omar');
        $trip = $this->trip('Marrakech – Agadir');
        $crawler = $this->client->request('GET', '/conducteur');
        $form = $crawler->filter(\sprintf('form[name="terms_%d"]', $trip->getId()))->form([
            \sprintf('terms_%d[seatCapacity]', $trip->getId()) => 2,
            \sprintf('terms_%d[fullPriceCents]', $trip->getId()) => '199,50',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/conducteur#trajet-'.$trip->getId());
        $this->em->clear();
        $saved = $this->em->getRepository(Trip::class)->find($trip->getId());
        self::assertSame(2, $saved->getSeatCapacity());
        self::assertSame(19950, $saved->getFullPriceCents());
    }

    public function testDriverCannotGoBelowBookedSeats(): void
    {
        $this->loginAs('youssef');
        $trip = $this->trip('Agadir – Casablanca');
        $crawler = $this->client->request('GET', '/conducteur');
        $this->client->submit($crawler->filter(\sprintf('form[name="terms_%d"]', $trip->getId()))->form([
            \sprintf('terms_%d[seatCapacity]', $trip->getId()) => 1,
        ]));
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'inférieure aux places déjà occupées');
        $this->em->clear();
        self::assertSame(3, $this->em->getRepository(Trip::class)->find($trip->getId())->getSeatCapacity());
    }

    public function testOnlyTheDriverCanChangeTheTerms(): void
    {
        $trip = $this->trip('Marrakech – Agadir');
        $this->loginAs('hamza');
        $this->client->request('POST', '/conducteur/trajets/'.$trip->getId().'/conditions', [
            'terms_'.$trip->getId() => ['seatCapacity' => 1, 'fullPriceCents' => '1'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDriverSeesPassengersOnTheirTripPage(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/trajets/'.$this->trip('Agadir – Casablanca')->getId());

        self::assertSelectorTextContains('#booking-title', 'Vos passagers');
        self::assertSelectorTextContains('.booking-panel', 'Nadia F.');
        self::assertSelectorExists('.booking-panel a[href$="#trajet-'.$this->trip('Agadir – Casablanca')->getId().'"]');
    }

    public function testAccountPageShowsWhoIsLoggedIn(): void
    {
        $this->client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');

        $this->loginAs('hamza');
        $this->client->request('GET', '/mon-compte');
        self::assertSelectorTextContains('.account-head', 'hamza@covoiturage.test');
        self::assertSelectorTextContains('#account-menu', 'hamza@covoiturage.test');
        self::assertSelectorTextNotContains('#account-menu', 'Passager et conducteur');
    }

    public function testCitySearchDoesNotShowTheDistanceToTheCityCentre(): void
    {
        $day = MoroccoTime::toLocal($this->trip('Agadir – Casablanca')->getDepartureAt())->format('Y-m-d');
        $this->client->request('GET', '/', ['from' => 'Agadir', 'to' => 'Essaouira', 'date' => $day]);

        self::assertSelectorExists('.result');
        self::assertSelectorNotExists('.result .leg-near');
    }

    public function testPriceGridIsFoldedInsideTheItinerary(): void
    {
        $this->client->request('GET', '/trajets/'.$this->trip('Agadir – Casablanca')->getId());

        // Plus de carte « Prix entre les étapes » à part : la grille complète est repliée dans l'itinéraire.
        self::assertSelectorNotExists('#portions-title');
        self::assertSelectorTextContains('#itineraire h2', 'Itinéraire et prix');
        self::assertSelectorTextContains('#itineraire details.more-portions summary', 'Voir tous les prix');
        self::assertCount(6, $this->client->getCrawler()->filter('#itineraire details.more-portions tbody tr'));
    }

    public function testEachStopShowsItsPriceAndSeatsFromTheChosenBoarding(): void
    {
        $this->loginAs('zineb');
        $trip = $this->trip('Casablanca – Fès');
        $crawler = $this->client->request('GET', '/trajets/'.$trip->getId());
        $offers = $crawler->filter('#itineraire [data-stop-offer]');

        // Montée proposée à Casablanca : rien au départ, prix et places depuis Casablanca ensuite.
        self::assertSame('', trim($offers->eq(0)->text()));
        self::assertStringContainsString('73,22 MAD', $offers->eq(1)->text());
        self::assertStringContainsString('3 places libres', $offers->eq(1)->text());
        self::assertStringContainsString('240,00 MAD', $offers->eq(3)->text());

        // « Descendre ici » mène au même trajet avec la descente choisie, même sans JavaScript.
        $link = $offers->eq(1)->filter('a[data-pick-alighting]');
        self::assertSame('/trajets/'.$trip->getId().'?depart='.$trip->getStopAt(0)->getId().'&arrivee='.$trip->getStopAt(1)->getId().'#reserver', $link->attr('href'));
        // L'étape de descente déjà choisie n'a pas de lien.
        self::assertCount(0, $offers->eq(3)->filter('a'));
    }

    public function testStopsBeforeTheBoardingOfferToBoardThere(): void
    {
        $this->loginAs('zineb');
        $trip = $this->trip('Casablanca – Fès');
        $crawler = $this->client->request('GET', '/trajets/'.$trip->getId(), ['depart' => $trip->getStopAt(1)->getId(), 'arrivee' => $trip->getStopAt(3)->getId()]);
        $offers = $crawler->filter('#itineraire [data-stop-offer]');

        self::assertSame('Monter ici', trim($offers->eq(0)->filter('a[data-pick-boarding]')->text()));
        self::assertStringContainsString('depart='.$trip->getStopAt(0)->getId().'&arrivee='.$trip->getStopAt(3)->getId(), (string) $offers->eq(0)->filter('a')->attr('href'));
        // Prix recalculés depuis Rabat.
        self::assertStringNotContainsString('240,00 MAD', $offers->eq(3)->text());
    }

    public function testStopActionsAreHiddenForTheDriverAndForAnExistingBooking(): void
    {
        $trip = $this->trip('Casablanca – Fès');

        $this->loginAs('hamza');
        $this->client->request('GET', '/trajets/'.$trip->getId());
        self::assertSelectorNotExists('#itineraire .rl-offer-pick');

        $this->loginAs('khadija');
        $this->client->request('GET', '/trajets/'.$trip->getId());
        self::assertSelectorNotExists('#itineraire .rl-offer-pick');
    }

    public function testDriverIsNotifiedOfANewBookingAndOpensTheTrip(): void
    {
        $trip = $this->trip('Marrakech – Agadir');
        $this->loginAs('hamza');
        $this->book($trip, 0, 1, 2);

        $this->loginAs('omar');
        $crawler = $this->client->request('GET', '/notifications/apercu');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notif-item.is-new', 'Nouvelle réservation : Hamza G. a réservé 2 places de Marrakech à Agadir');
        self::assertSelectorTextContains('.notif-all', 'Voir toutes les notifications');

        // L'aperçu ne marque rien comme lu ; ouvrir la notification la marque et mène au trajet.
        $link = $crawler->filter('.notif-item.is-new')->first()->attr('href');
        self::assertMatchesRegularExpression('#^/notifications/\d+/ouvrir$#', $link);
        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notification n JOIN app_user u ON u.id = n.recipient_id WHERE u.email = ? AND n.is_read = 0', ['omar@covoiturage.test']);
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/conducteur#trajet-'.$trip->getId());
        $after = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notification n JOIN app_user u ON u.id = n.recipient_id WHERE u.email = ? AND n.is_read = 0', ['omar@covoiturage.test']);
        self::assertSame($before - 1, $after);
    }

    public function testPassengerOpensTheCancellationNotificationOnTheirBooking(): void
    {
        $this->loginAs('amine');
        $crawler = $this->client->request('GET', '/notifications');
        $link = $crawler->filter('a.notification-link')->first();

        self::assertStringContainsString('Voir ma réservation', $link->text());
        $this->client->request('GET', $link->attr('href'));
        self::assertMatchesRegularExpression('#^/mes-reservations/\d+$#', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testOnlyTheRecipientCanOpenANotification(): void
    {
        $this->loginAs('amine');
        $crawler = $this->client->request('GET', '/notifications');
        $link = $crawler->filter('a.notification-link')->first()->attr('href');

        $this->loginAs('nadia');
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBellOpensAPreviewWithALinkToAllNotifications(): void
    {
        $this->client->request('GET', '/notifications/apercu');
        self::assertResponseRedirects('/connexion');

        $this->loginAs('amine');
        $this->client->request('GET', '/');
        self::assertSelectorExists('[data-notif][data-src="/notifications/apercu"] a.nav-bell[href="/notifications"][aria-controls="notif-panel"]');
        self::assertSelectorExists('#notif-panel[hidden]');
    }

    public function testPreviewListsNotificationsEvenWhenAllAreRead(): void
    {
        $this->loginAs('amine');
        // La page complète marque tout comme lu.
        $this->client->request('GET', '/notifications');

        $crawler = $this->client->request('GET', '/notifications/apercu');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.notif-item')->count());
        self::assertLessThanOrEqual(5, $crawler->filter('.notif-item')->count());
        self::assertCount(0, $crawler->filter('.notif-item.is-new'));
        self::assertSelectorTextContains('.notif-all', 'Voir toutes les notifications');
    }

    public function testPublicFilesCarryTheirVersion(): void
    {
        $crawler = $this->client->request('GET', '/');

        $script = $crawler->filter('script[src^="/js/app.js"]')->attr('src');
        self::assertSame('/js/app.js?v='.filemtime(\dirname(__DIR__, 2).'/public/js/app.js'), $script);
        self::assertMatchesRegularExpression('#^/css/app\.css\?v=\d+$#', $crawler->filter('link[href^="/css/app.css"]')->attr('href'));
    }

    public function testDriverIsNotifiedWhenAPassengerCancels(): void
    {
        $trip = $this->trip('Marrakech – Agadir');
        $this->loginAs('hamza');
        $this->book($trip, 0, 1, 2);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[action$="/annuler"]')->form());

        $this->loginAs('omar');
        $crawler = $this->client->request('GET', '/notifications/apercu');
        $latest = $crawler->filter('.notif-item')->first();
        self::assertStringContainsString('Annulation : Hamza G. a annulé sa réservation de 2 places de Marrakech à Agadir', $latest->text());
        self::assertStringContainsString('Ces places sont de nouveau disponibles.', $latest->text());

        $this->client->request('GET', $latest->attr('href'));
        self::assertResponseRedirects('/conducteur#trajet-'.$trip->getId());
    }
}
