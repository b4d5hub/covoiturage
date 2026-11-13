<?php

namespace App\Tests\Unit;

use App\Geo\Coordinates;
use App\Geo\GeoServiceUnavailable;
use App\Geo\NominatimGeocoder;
use App\Geo\OsrmRouter;
use App\Geo\PriceSuggester;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class GeoServicesTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    private function limiter(string $id): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 second'], new InMemoryStorage());
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses, string $base): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->urls[] = $url;

            return array_shift($responses);
        }, $base);
    }

    /** @param list<MockResponse> $responses */
    private function geocoder(array $responses): NominatimGeocoder
    {
        return new NominatimGeocoder($this->client($responses, 'https://nominatim.example'), new ArrayAdapter(), $this->limiter('nominatim'), new NullLogger());
    }

    /** @param list<MockResponse> $responses */
    private function router(array $responses): OsrmRouter
    {
        return new OsrmRouter($this->client($responses, 'https://osrm.example'), new ArrayAdapter(), $this->limiter('osrm'), new NullLogger());
    }

    /** @param array<string, mixed> $extra */
    private static function nominatimResult(string $name, string $display, string $addresstype = 'city', array $extra = []): array
    {
        return $extra + ['osm_type' => 'relation', 'osm_id' => 2799215, 'lat' => '34.0218454', 'lon' => '-6.8408929', 'name' => $name, 'display_name' => $display, 'addresstype' => $addresstype];
    }

    /** @return array<string, string> */
    private function query(int $index = 0): array
    {
        parse_str((string) parse_url($this->urls[$index], \PHP_URL_QUERY), $query);

        return $query;
    }

    private function point(): Coordinates
    {
        return new Coordinates('34.014000', '-6.836000');
    }

    public function testPlaceSearchIsLimitedToMoroccoInFrenchAndCleansTheAddress(): void
    {
        $places = $this->geocoder([new MockResponse(json_encode([
            self::nominatimResult('Casa Voyageurs', 'Casa Voyageurs, Parvis de la Gare, Arrondissement des Roches noires, Pachalik de Casablanca, Préfecture de Casablanca, Casablanca-Settat, 20250, Maroc', 'railway', ['osm_type' => 'node', 'osm_id' => 7055155496, 'lat' => '33.5903571', 'lon' => '-7.5896645']),
        ]))])->search('  Casa   Voyageurs ');

        self::assertStringStartsWith('https://nominatim.example/search', $this->urls[0]);
        self::assertSame(['q' => 'Casa Voyageurs', 'format' => 'jsonv2', 'limit' => '5', 'countrycodes' => 'ma', 'accept-language' => 'fr'], $this->query());
        self::assertCount(1, $places);
        self::assertSame('Casa Voyageurs', $places[0]->name);
        self::assertSame('Casa Voyageurs, Parvis de la Gare', $places[0]->address);
        self::assertEquals(new Coordinates('33.590357', '-7.589664'), $places[0]->point);
        self::assertSame(['node', '7055155496'], [$places[0]->osmType, $places[0]->osmId]);
    }

    public function testDuplicatePlacesAreShownOnce(): void
    {
        $station = self::nominatimResult('Gare Routière', 'Gare Routière, Avenue Abderrahim Bouabid, Agadir, Souss-Massa, Maroc', 'amenity');
        $places = $this->geocoder([new MockResponse(json_encode([$station, ['osm_type' => 'way', 'osm_id' => 1] + $station, self::nominatimResult('Agadir', 'Agadir, Souss-Massa, Maroc')]))])->search('Gare routière Agadir');

        self::assertSame(['Gare Routière', 'Agadir'], array_map(static fn ($place) => $place->name, $places));
    }

    public function testSearchResultsAreCachedRegardlessOfCaseAndSpacing(): void
    {
        $geocoder = $this->geocoder([new MockResponse(json_encode([self::nominatimResult('Rabat', 'Rabat, Maroc')]))]);

        $geocoder->search('Rabat');
        $second = $geocoder->search('  RABAT ');

        self::assertCount(1, $this->urls);
        self::assertCount(1, $second);
    }

    public function testNoResultIsAnEmptyListAndIsCached(): void
    {
        $geocoder = $this->geocoder([new MockResponse('[]')]);

        self::assertSame([], $geocoder->search('Lieu inconnu'));
        self::assertSame([], $geocoder->search('Lieu inconnu'));
        self::assertCount(1, $this->urls);
    }

    public function testUnavailableServiceThrowsAndIsNotCached(): void
    {
        $geocoder = $this->geocoder([new MockResponse('', ['http_code' => 503]), new MockResponse(json_encode([self::nominatimResult('Rabat', 'Rabat, Maroc')]))]);

        try {
            $geocoder->search('Rabat');
            self::fail('Une indisponibilité doit être signalée.');
        } catch (GeoServiceUnavailable) {
        }

        self::assertCount(1, $geocoder->search('Rabat'));
        self::assertCount(2, $this->urls);
    }

    public function testNetworkFailureIsReportedAsUnavailable(): void
    {
        $this->expectException(GeoServiceUnavailable::class);

        $this->geocoder([new MockResponse('', ['error' => 'Connexion impossible'])])->localityAt($this->point());
    }

    public function testLocalityUsesReverseGeocodingAtTownLevel(): void
    {
        $locality = $this->geocoder([new MockResponse(json_encode(self::nominatimResult('Rabat', 'Rabat, Maroc', 'city', ['address' => ['country_code' => 'ma']])))])->localityAt($this->point());

        self::assertStringStartsWith('https://nominatim.example/reverse', $this->urls[0]);
        self::assertSame(['lat' => '34.014000', 'lon' => '-6.836000', 'zoom' => '10', 'format' => 'jsonv2', 'addressdetails' => '1', 'accept-language' => 'fr'], $this->query());
        self::assertSame('Rabat', $locality->name);
        self::assertSame(['relation', '2799215'], [$locality->osmType, $locality->osmId]);
        self::assertEquals(new Coordinates('34.021845', '-6.840893'), $locality->point);
    }

    #[TestWith(['Pachalik de Safi', 'Safi'])]
    #[TestWith(['pachalik d\'El Jadida', 'El Jadida'])]
    #[TestWith(['Commune rurale de Jouala', 'Jouala'])]
    #[TestWith(['Rabat', 'Rabat'])]
    public function testAdministrativePrefixesAreRemovedFromLocalityNames(string $name, string $expected): void
    {
        $locality = $this->geocoder([new MockResponse(json_encode(self::nominatimResult($name, $name.', Maroc', 'city', ['address' => ['country_code' => 'ma']])))])->localityAt($this->point());

        self::assertSame($expected, $locality->name);
    }

    #[TestWith(['state', 'ma'])]
    #[TestWith(['suburb', 'ma'])]
    #[TestWith(['city', 'dz'])]
    public function testPointOutsideAMoroccanLocalityHasNoLocality(string $addresstype, string $country): void
    {
        $result = self::nominatimResult('Ailleurs', 'Ailleurs', $addresstype, ['address' => ['country_code' => $country]]);

        self::assertNull($this->geocoder([new MockResponse(json_encode($result))])->localityAt($this->point()));
    }

    public function testUnableToGeocodeMeansNoLocalityAndIsCached(): void
    {
        $geocoder = $this->geocoder([new MockResponse('{"error":"Unable to geocode"}')]);

        self::assertNull($geocoder->localityAt($this->point()));
        self::assertNull($geocoder->localityAt($this->point()));
        self::assertCount(1, $this->urls);
    }

    public function testPlaceAtNamesTheExactPointClicked(): void
    {
        $place = $this->geocoder([new MockResponse(json_encode(self::nominatimResult('Gare de Rabat-Ville', 'Gare de Rabat-Ville, Avenue Mohammed V, Hassan, Rabat, Pachalik de Rabat, Rabat-Salé-Kénitra, 10000, Maroc', 'railway', ['address' => ['country_code' => 'ma']])))])->placeAt($this->point());

        self::assertSame('18', $this->query()['zoom']);
        self::assertSame('Gare de Rabat-Ville', $place->name);
        self::assertSame('Gare de Rabat-Ville, Avenue Mohammed V, Hassan, Rabat', $place->address);
    }

    public function testRequestsAreSpacedByAtLeastOneSecond(): void
    {
        $geocoder = $this->geocoder([new MockResponse('[]'), new MockResponse('[]')]);

        $start = microtime(true);
        $geocoder->search('Premier lieu');
        $geocoder->search('Second lieu');

        self::assertGreaterThanOrEqual(0.9, microtime(true) - $start);
    }

    public function testRouteUsesLongitudeLatitudeOrderAndReturnsLegs(): void
    {
        $body = json_encode(['code' => 'Ok', 'routes' => [[
            'legs' => [['distance' => 172999.6], ['distance' => 190000.4]],
            'geometry' => ['coordinates' => [[-9.557, 30.413], [-9.765, 31.515], [-8.003, 31.638]]],
        ]]]);

        $route = $this->router([new MockResponse($body)])->route([
            new Coordinates('30.413000', '-9.557000'),
            new Coordinates('31.515500', '-9.765000'),
            new Coordinates('31.638500', '-8.003000'),
        ]);

        self::assertStringContainsString('/route/v1/driving/-9.557000,30.413000;-9.765000,31.515500;-8.003000,31.638500', urldecode($this->urls[0]));
        parse_str((string) parse_url($this->urls[0], \PHP_URL_QUERY), $query);
        self::assertSame('simplified', $query['overview']);
        self::assertSame([173000, 190000], $route->legMeters);
        self::assertSame(363000, $route->totalMeters());
        self::assertSame([30.413, -9.557], $route->path[0]);
    }

    public function testNoRouteReturnsNull(): void
    {
        $route = $this->router([new MockResponse('{"code":"NoRoute"}', ['http_code' => 400])])->route([
            new Coordinates('30.4', '-9.5'),
            new Coordinates('31.6', '-8.0'),
        ]);

        self::assertNull($route);
    }

    public function testRouteNeedsAtLeastTwoPoints(): void
    {
        self::assertNull($this->router([])->route([new Coordinates('30.4', '-9.5')]));
        self::assertSame([], $this->urls);
    }

    public function testUnavailableRoutingReturnsNull(): void
    {
        $route = $this->router([new MockResponse('', ['http_code' => 503])])->route([
            new Coordinates('30.4', '-9.5'),
            new Coordinates('31.6', '-8.0'),
        ]);

        self::assertNull($route);
    }

    #[TestWith([250000, 12500])]
    #[TestWith([603000, 30200])]
    #[TestWith([1000, 100])]
    #[TestWith([999, 0])]
    #[TestWith([0, 0])]
    public function testSuggestedPriceIsRoundedToTheDirham(int $meters, int $expectedCents): void
    {
        self::assertSame($expectedCents, (new PriceSuggester(50))->suggestCents($meters));
    }
}
