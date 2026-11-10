<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Remplace Nominatim et OSRM pendant les tests : aucune requête ne part vers les vrais services.
 */
final class FakeOsmResponses
{
    /** « ok », « notfound » ou « down » */
    public static string $mode = 'ok';

    /** @var list<array{url: string, headers: list<string>}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$mode = 'ok';
        self::$requests = [];
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        self::$requests[] = ['url' => $url, 'headers' => $options['headers'] ?? []];

        if ('down' === self::$mode) {
            return new MockResponse('', ['http_code' => 503]);
        }

        if (str_contains($url, '/search')) {
            return new MockResponse('notfound' === self::$mode ? '[]' : '[{"lat":"30.4130","lon":"-9.5570"}]');
        }

        // OSRM : un tronçon de 100 km entre chaque paire d'étapes consécutives.
        preg_match('#/driving/([^?]+)#', $url, $matches);
        $points = array_map(static fn (string $p) => array_map('floatval', explode(',', $p)), explode(';', urldecode($matches[1])));

        return new MockResponse(json_encode([
            'code' => 'Ok',
            'routes' => [[
                'legs' => array_fill(0, \count($points) - 1, ['distance' => 100000.4]),
                'geometry' => ['coordinates' => $points],
            ]],
        ]));
    }
}
