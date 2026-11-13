<?php

namespace App\Tests\Functional;

use App\Entity\Trip;
use App\Entity\User;
use App\Tests\Support\FakeOsmResponses;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Aide à la saisie et carte, avec de faux services OpenStreetMap.
 */
final class GeoAssistTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        FakeOsmResponses::reset();
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.app')->clear();

        $tester = new CommandTester((new Application(self::$kernel))->find('doctrine:fixtures:load'));
        $tester->execute([], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();
    }

    private function loginAs(string $key): void
    {
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => $key.'@covoiturage.test']));
    }

    private function cityId(string $name): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM city WHERE name = ?', [$name]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testHelpersRequireLogin(): void
    {
        $this->client->request('GET', '/aide-saisie/lieux', ['q' => 'Agdal']);
        self::assertResponseRedirects('/connexion');

        $this->client->request('GET', '/aide-saisie/localite', ['lat' => '34.014', 'lon' => '-6.836']);
        self::assertResponseRedirects('/connexion');

        self::assertSame([], FakeOsmResponses::$requests);
    }

    public function testPlaceSearchListsPlacesAndIdentifiesTheApplication(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/lieux', ['q' => 'Casa Voyageurs']);

        self::assertResponseIsSuccessful();
        self::assertSame(['places' => [[
            'name' => 'Casa Voyageurs',
            'address' => 'Casa Voyageurs, Parvis de la Gare, Casablanca',
            'latitude' => '33.589697',
            'longitude' => '-7.590187',
        ]]], $this->json());
        self::assertStringStartsWith($_ENV['NOMINATIM_URL'].'/search', FakeOsmResponses::$requests[0]['url']);
        self::assertContains('User-Agent: '.$_ENV['OSM_USER_AGENT'], FakeOsmResponses::$requests[0]['headers']);
    }

    public function testPlaceSearchNeedsAShortText(): void
    {
        $this->loginAs('youssef');

        foreach (['', ' a ', str_repeat('x', 201)] as $text) {
            $this->client->request('GET', '/aide-saisie/lieux', ['q' => $text]);
            self::assertResponseStatusCodeSame(400);
        }

        self::assertSame([], FakeOsmResponses::$requests);
    }

    public function testPlaceSearchFailureInvitesManualEntry(): void
    {
        FakeOsmResponses::$mode = 'down';
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/lieux', ['q' => 'Agdal']);

        self::assertResponseStatusCodeSame(503);
        self::assertStringContainsString('saisissez l\'adresse et les coordonnées', $this->json()['error']);
    }

    public function testLocalityOfAPointAndOfAClickOnTheMap(): void
    {
        $this->loginAs('youssef');

        $this->client->request('GET', '/aide-saisie/localite', ['lat' => '33.998312', 'lon' => '-6.854291']);
        self::assertResponseIsSuccessful();
        self::assertSame(['city' => 'Rabat', 'place' => null], $this->json());

        $this->client->request('GET', '/aide-saisie/localite', ['lat' => '34.05', 'lon' => '-6.81', 'describe' => '1']);
        self::assertSame(['city' => 'Salé', 'place' => ['name' => 'Rue du Marché', 'address' => 'Rue du Marché, Salé', 'latitude' => '34.050000', 'longitude' => '-6.810000']], $this->json());
        self::assertSame(3, FakeOsmResponses::count('/reverse'));
    }

    public function testLocalityRefusesAPointOutsideAnyMoroccanLocality(): void
    {
        $this->loginAs('youssef');

        foreach ([['32.9', '-4.0'], ['25.0', '-1.0']] as [$latitude, $longitude]) {
            $this->client->request('GET', '/aide-saisie/localite', ['lat' => $latitude, 'lon' => $longitude]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('aucune localité du Maroc', $this->json()['error']);
        }

        $this->client->request('GET', '/aide-saisie/localite', ['lat' => '95', 'lon' => '0']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testLocalityDoesNotRecordAnyCity(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/localite', ['lat' => '34.05', 'lon' => '-6.81']);

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM city WHERE name = 'Salé'"));
    }

    public function testRouteGivesDistancesAndSuggestedPrice(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/itineraire', ['points' => '30.413,-9.557;31.5155,-9.765;31.6385,-8.003']);

        self::assertResponseIsSuccessful();
        // Deux tronçons de 100 km à 0,50 MAD/km.
        $json = $this->json();
        self::assertSame(['distances' => [100000, 100000], 'totalMeters' => 200000, 'suggestedPriceCents' => 10000, 'suggestedPrice' => '100,00'], array_diff_key($json, ['path' => true, 'durations' => true, 'citiesAlong' => true]));
        // Durée de conduite de chaque tronçon, pour les horaires calculés par l'assistant ; pas de villes sans « along ».
        self::assertSame([3600, 3600], $json['durations']);
        self::assertSame([], $json['citiesAlong']);
        // Tracé à afficher par l'assistant, en [latitude, longitude].
        self::assertSame([30.413, -9.557], $json['path'][0]);
        self::assertContains('User-Agent: '.$_ENV['OSM_USER_AGENT'], FakeOsmResponses::$requests[0]['headers']);
    }

    public function testRouteRejectsInvalidPoints(): void
    {
        $this->loginAs('youssef');

        foreach (['30.4,-9.5', '95,-9.5;31,-8', 'abc;def', ''] as $points) {
            $this->client->request('GET', '/aide-saisie/itineraire', ['points' => $points]);
            self::assertResponseStatusCodeSame(400, $points);
        }

        self::assertSame([], FakeOsmResponses::$requests);
    }

    public function testRouteFailureInvitesManualEntry(): void
    {
        FakeOsmResponses::$mode = 'down';
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/itineraire', ['points' => '30.413,-9.557;31.6385,-8.003']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('saisissez les distances', $this->json()['error']);
    }

    public function testPublishFormOffersTheHelpersWithAttribution(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/conducteur/trajets/nouveau');

        self::assertSelectorExists('[data-place-finder] [data-place-search]');
        self::assertSelectorExists('[data-publish-map]');
        self::assertSelectorExists('[data-geo-assist][data-places-url="/aide-saisie/lieux"][data-locality-url="/aide-saisie/localite"][data-route-url="/aide-saisie/itineraire"]');
        self::assertSelectorTextContains('body', 'contributeurs OpenStreetMap');
        self::assertSelectorTextContains('body', '0,50 MAD par kilomètre');
        self::assertSame([], FakeOsmResponses::$requests, 'Aucun appel avant une action du conducteur');
    }

    /**
     * @param array<int, array{0: string, 1: string, 2?: string}> $points latitude, longitude et ville envoyée
     */
    private function publish(array $points): void
    {
        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau');
        $form = $crawler->filter('form[name="publish_trip"]')->form();
        $values = ['publish_trip[seatCapacity]' => 1, 'publish_trip[fullPriceCents]' => '80'];
        $day = (new \DateTimeImmutable('+6 days', new \DateTimeZone('Africa/Casablanca')))->format('Y-m-d');

        foreach ($points as $index => [$latitude, $longitude]) {
            $prefix = "publish_trip[itinerary][$index]";
            $values += [
                $prefix.'[meetingAddress]' => 'Point '.($index + 1),
                $prefix.'[latitude]' => $latitude,
                $prefix.'[longitude]' => $longitude,
                $prefix.'[city]' => isset($points[$index][2]) ? $this->cityId($points[$index][2]) : '',
            ];
            $values += 0 === $index ? [$prefix.'[departureAt]' => $day.'T08:00', $prefix.'[distanceToNextMeters]' => '20000'] : [$prefix.'[arrivalAt]' => $day.'T10:00'];
        }

        $this->client->submit($form, $values);
    }

    /** @return list<string> villes des étapes du dernier trajet publié */
    private function lastTripCities(): array
    {
        return $this->em->getConnection()->fetchFirstColumn(
            'SELECT c.name FROM trip_stop s JOIN city c ON c.id = s.city_id WHERE s.trip_id = (SELECT MAX(id) FROM trip) ORDER BY s.position',
        );
    }

    public function testPublishingDerivesEachStopCityFromItsPoint(): void
    {
        $this->loginAs('youssef');
        // La ville envoyée par le navigateur (Agadir) est ignorée : le point est à Rabat.
        $this->publish([['34.014', '-6.836', 'Agadir'], ['34.05', '-6.81']]);

        self::assertResponseRedirects();
        self::assertSame(['Rabat', 'Salé'], $this->lastTripCities());
        self::assertSame(
            ['relation', 2801066, '34.044889', '-6.814017'],
            array_values($this->em->getConnection()->fetchAssociative("SELECT osm_type, osm_id, latitude, longitude FROM city WHERE name = 'Salé'")),
        );
    }

    public function testALocalityIsRecordedOnlyOnce(): void
    {
        $this->loginAs('youssef');
        $this->publish([['34.014', '-6.836'], ['34.05', '-6.81']]);
        $this->publish([['34.05', '-6.81'], ['34.014', '-6.836']]);

        self::assertSame(['Salé', 'Rabat'], $this->lastTripCities());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM city WHERE osm_type = 'relation' AND osm_id = 2801066"));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM city WHERE name = 'Rabat'"));
    }

    public function testPublishingRefusesAPointOutsideAnyLocality(): void
    {
        $this->loginAs('youssef');
        $trips = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM trip');
        $this->publish([['34.014', '-6.836'], ['32.9', '-4.0']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Étape 2 : ce point n\'est situé dans aucune localité du Maroc');
        self::assertSame($trips, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM trip'));
    }

    public function testWhenTheMapIsDownTheCityChosenInTheListIsUsed(): void
    {
        FakeOsmResponses::$mode = 'down';
        $this->loginAs('youssef');
        $this->publish([['34.014', '-6.836', 'Rabat'], ['33.897', '-5.548', 'Meknès']]);

        self::assertResponseRedirects();
        self::assertSame(['Rabat', 'Meknès'], $this->lastTripCities());
        // Après un premier échec, les étapes suivantes n'attendent pas le service.
        self::assertSame(1, FakeOsmResponses::count('/reverse'));
    }

    public function testWhenTheMapIsDownACityMustBeChosen(): void
    {
        FakeOsmResponses::$mode = 'down';
        $this->loginAs('youssef');
        $this->publish([['34.014', '-6.836', 'Rabat'], ['33.897', '-5.548']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Étape 2 : la carte est indisponible, choisissez la ville dans la liste.');
    }

    private function tripPage(): string
    {
        $trip = $this->em->getRepository(Trip::class)->findOneBy([]);
        $this->client->request('GET', '/trajets/'.$trip->getId());
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler()->filter('#trip-map')->attr('data-map');
    }

    public function testTripPageShowsTheRoadRouteWithAttribution(): void
    {
        $map = json_decode($this->tripPage(), true);

        self::assertFalse($map['approximate']);
        self::assertNotEmpty($map['path']);
        self::assertSelectorTextContains('body', 'Tracé routier calculé par OSRM');
        self::assertSelectorExists('a[href="https://www.openstreetmap.org/copyright"]');
        self::assertSame($_ENV['MAP_TILE_URL'], $this->client->getCrawler()->filter('#trip-map')->attr('data-tiles'));
    }

    public function testTripPageFallsBackToStraightLinesWhenRoutingIsDown(): void
    {
        FakeOsmResponses::$mode = 'down';

        $map = json_decode($this->tripPage(), true);

        self::assertTrue($map['approximate']);
        self::assertCount(\count($map['stops']), $map['path']);
        self::assertSelectorTextContains('body', 'Tracé approximatif');
    }

    public function testTripRouteIsCachedAcrossPageViews(): void
    {
        $this->tripPage();
        $this->tripPage();

        self::assertCount(1, FakeOsmResponses::$requests);
    }

    public function testRouteSuggestsKnownCitiesAlongTheWay(): void
    {
        $this->loginAs('youssef');
        // Rabat → Meknès → Fès : Meknès est sur le tracé ; Salé, trop proche du départ, n'est pas proposée.
        $this->client->request('GET', '/aide-saisie/itineraire', ['points' => '34.0218,-6.8409;33.8984,-5.5322;34.0347,-5.0162', 'along' => '1']);

        self::assertResponseIsSuccessful();
        $names = array_column($this->json()['citiesAlong'], 'name');
        self::assertSame(['Meknès'], $names);
        self::assertEqualsWithDelta(0.7, $this->json()['citiesAlong'][0]['along'], 0.1);
        // Calcul local : un seul appel, à OSRM.
        self::assertCount(1, FakeOsmResponses::$requests);
    }

    public function testReturnTripIsPrefilledInReverse(): void
    {
        $this->loginAs('youssef');
        $trip = $this->em->getRepository(Trip::class)->createQueryBuilder('t')->getQuery()->getResult();
        $trip = array_values(array_filter($trip, static fn (Trip $t) => 'Agadir – Casablanca' === $t->getRouteLabel()))[0];
        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau', ['retour' => $trip->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#panel-route', 'Votre trajet retour');
        self::assertSame('Gare de Casa-Voyageurs', $crawler->filter('input[name="publish_trip[itinerary][0][meetingAddress]"]')->attr('value'));
        self::assertSame('Gare routière d\'Agadir', $crawler->filter('input[name="publish_trip[itinerary][3][meetingAddress]"]')->attr('value'));
        // Distance du premier tronçon du retour : celle du dernier tronçon de l'aller (Marrakech → Casablanca).
        self::assertSame((string) $trip->getSegmentAt(2)->getDistanceMeters(), $crawler->filter('input[name="publish_trip[itinerary][0][distanceToNextMeters]"]')->attr('value'));
        self::assertSame('', (string) $crawler->filter('input[name="publish_trip[itinerary][0][departureAt]"]')->attr('value'));
        self::assertSame((string) $trip->getSeatCapacity(), $crawler->filter('input[name="publish_trip[seatCapacity]"]')->attr('value'));
    }

    public function testReturnTripIsOnlyForTheDriversOwnTrips(): void
    {
        $trip = array_values(array_filter($this->em->getRepository(Trip::class)->findAll(), static fn (Trip $t) => 'Agadir – Casablanca' === $t->getRouteLabel()))[0];
        $this->loginAs('omar');
        $crawler = $this->client->request('GET', '/conducteur/trajets/nouveau', ['retour' => $trip->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('#panel-route', 'trajet retour');
        self::assertSame('', (string) $crawler->filter('input[name="publish_trip[itinerary][0][meetingAddress]"]')->attr('value'));
    }

    public function testDriverTripPageOffersTheReturnTrip(): void
    {
        $trip = array_values(array_filter($this->em->getRepository(Trip::class)->findAll(), static fn (Trip $t) => 'Agadir – Casablanca' === $t->getRouteLabel()))[0];
        $this->loginAs('youssef');
        $this->client->request('GET', '/trajets/'.$trip->getId());

        self::assertSelectorExists('a[href="/conducteur/trajets/nouveau?retour='.$trip->getId().'"]');
    }

    public function testPublishPageStartsWithTheRouteAndKeepsTheFullFormForFallback(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/conducteur/trajets/nouveau');

        // Assistant : trajet, arrêts, véhicule, prix, publication ; le formulaire complet reste dans la page.
        self::assertSame(['route', 'stops', 'vehicle', 'price', 'recap'], $this->client->getCrawler()->filter('[data-wizard] [data-panel]')->each(fn ($panel) => $panel->attr('data-panel')));
        self::assertSelectorTextContains('[data-panel="route"]', 'D\'où partez-vous ?');
        self::assertSelectorExists('[data-panel="route"] input[type="time"][data-wizard-time]');
        self::assertSelectorExists('[data-classic-form] [data-collection-list]');
        self::assertSelectorExists('[data-geo-assist][data-known-url="/aide-saisie/lieux-connus"]');
    }

    public function testKnownPlacesAreSuggestedWhileTypingWithoutAnyExternalCall(): void
    {
        $this->loginAs('youssef');
        // Sans accent ni majuscule : « fes » trouve la ville de Fès et la gare déjà utilisée par un trajet.
        $this->client->request('GET', '/aide-saisie/lieux-connus', ['q' => 'fes']);

        self::assertResponseIsSuccessful();
        $places = $this->json()['places'];
        self::assertSame(['kind' => 'city', 'name' => 'Fès', 'city' => 'Fès'], array_intersect_key($places[0], ['kind' => 1, 'name' => 1, 'city' => 1]));
        self::assertContains('Gare de Fès', array_column(array_filter($places, static fn (array $p) => 'point' === $p['kind']), 'name'));
        self::assertNotSame('', $places[0]['latitude']);
        // Aucune requête vers Nominatim pendant la frappe.
        self::assertSame([], FakeOsmResponses::$requests);
    }

    public function testKnownPlacesMatchMeetingPointsByName(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/lieux-connus', ['q' => 'bab douk']);

        $places = $this->json()['places'];
        self::assertSame([['kind' => 'point', 'name' => 'Gare routière de Bab Doukkala', 'city' => 'Marrakech']], array_map(static fn (array $p) => array_intersect_key($p, ['kind' => 1, 'name' => 1, 'city' => 1]), $places));
    }

    public function testKnownPlacesIgnoreTooShortTextAndWildcards(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/lieux-connus', ['q' => 'f']);
        self::assertSame(['places' => []], $this->json());

        // Les caractères génériques de LIKE sont pris au pied de la lettre.
        $this->client->request('GET', '/aide-saisie/lieux-connus', ['q' => '%%']);
        self::assertSame(['places' => []], $this->json());
    }

    public function testKnownPlacesRequireLogin(): void
    {
        $this->client->request('GET', '/aide-saisie/lieux-connus', ['q' => 'fes']);
        self::assertResponseRedirects('/connexion');
    }
}
