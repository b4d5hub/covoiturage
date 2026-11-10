<?php

namespace App\Tests\Unit;

use App\Entity\City;
use App\Geo\Coordinates;
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

    private function city(): City
    {
        return (new City())->setName('Agadir');
    }

    public function testGeocodingQueriesMoroccoAndReturnsCoordinates(): void
    {
        $result = $this->geocoder([new MockResponse('[{"lat":"30.413","lon":"-9.557"}]')])->geocode('Gare routière', $this->city());

        self::assertEquals(new Coordinates('30.413000', '-9.557000'), $result);
        parse_str((string) parse_url($this->urls[0], \PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://nominatim.example/search', $this->urls[0]);
        self::assertSame('Gare routière, Agadir, Maroc', $query['q']);
        self::assertSame('ma', $query['countrycodes']);
        self::assertSame('1', $query['limit']);
    }

    public function testGeocodingResultsAreCached(): void
    {
        $geocoder = $this->geocoder([new MockResponse('[{"lat":"30.413","lon":"-9.557"}]')]);

        $geocoder->geocode('Gare routière', $this->city());
        $second = $geocoder->geocode('  GARE ROUTIÈRE ', $this->city());

        self::assertCount(1, $this->urls);
        self::assertNotNull($second);
    }

    public function testAddressNotFoundReturnsNullAndIsCached(): void
    {
        $geocoder = $this->geocoder([new MockResponse('[]')]);

        self::assertNull($geocoder->geocode('Adresse inconnue', $this->city()));
        self::assertNull($geocoder->geocode('Adresse inconnue', $this->city()));
        self::assertCount(1, $this->urls);
    }

    public function testUnavailableServiceReturnsNullAndIsNotCached(): void
    {
        $geocoder = $this->geocoder([new MockResponse('', ['http_code' => 503]), new MockResponse('[{"lat":"30.4","lon":"-9.5"}]')]);

        self::assertNull($geocoder->geocode('Gare routière', $this->city()));
        self::assertNotNull($geocoder->geocode('Gare routière', $this->city()));
        self::assertCount(2, $this->urls);
    }

    public function testNetworkFailureReturnsNull(): void
    {
        $geocoder = $this->geocoder([new MockResponse('', ['error' => 'Connexion impossible'])]);

        self::assertNull($geocoder->geocode('Gare routière', $this->city()));
    }

    public function testRequestsAreSpacedByAtLeastOneSecond(): void
    {
        $geocoder = $this->geocoder([new MockResponse('[]'), new MockResponse('[]')]);

        $start = microtime(true);
        $geocoder->geocode('Première adresse', $this->city());
        $geocoder->geocode('Seconde adresse', $this->city());

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
