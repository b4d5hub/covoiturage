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
        $this->client->request('GET', '/aide-saisie/coordonnees', ['city' => $this->cityId('Agadir'), 'address' => 'Gare']);

        self::assertResponseRedirects('/connexion');
        self::assertSame([], FakeOsmResponses::$requests);
    }

    public function testGeocodingReturnsCoordinatesAndIdentifiesTheApplication(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/coordonnees', ['city' => $this->cityId('Agadir'), 'address' => 'Gare routière']);

        self::assertResponseIsSuccessful();
        self::assertSame(['latitude' => '30.413000', 'longitude' => '-9.557000'], $this->json());
        self::assertCount(1, FakeOsmResponses::$requests);
        self::assertStringStartsWith($_ENV['NOMINATIM_URL'].'/search', FakeOsmResponses::$requests[0]['url']);
        self::assertContains('User-Agent: '.$_ENV['OSM_USER_AGENT'], FakeOsmResponses::$requests[0]['headers']);
    }

    public function testGeocodingRequiresACityAndAnAddress(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/coordonnees', ['city' => $this->cityId('Agadir'), 'address' => '  ']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], FakeOsmResponses::$requests);
    }

    public function testGeocodingFailureInvitesManualEntry(): void
    {
        FakeOsmResponses::$mode = 'down';
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/coordonnees', ['city' => $this->cityId('Agadir'), 'address' => 'Gare routière']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('saisissez les coordonnées', $this->json()['error']);
    }

    public function testRouteGivesDistancesAndSuggestedPrice(): void
    {
        $this->loginAs('youssef');
        $this->client->request('GET', '/aide-saisie/itineraire', ['points' => '30.413,-9.557;31.5155,-9.765;31.6385,-8.003']);

        self::assertResponseIsSuccessful();
        // Deux tronçons de 100 km à 0,50 MAD/km.
        self::assertSame(['distances' => [100000, 100000], 'totalMeters' => 200000, 'suggestedPriceCents' => 10000, 'suggestedPrice' => '100,00'], $this->json());
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

        self::assertSelectorExists('[data-geocode]');
        self::assertSelectorExists('[data-route]');
        self::assertSelectorTextContains('body', 'contributeurs OpenStreetMap');
        self::assertSelectorTextContains('body', '0,50 MAD par kilomètre');
        self::assertSame([], FakeOsmResponses::$requests, 'Aucun appel avant une action du conducteur');
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
}
