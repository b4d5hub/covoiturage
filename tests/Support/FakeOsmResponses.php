<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Remplace Nominatim et OSRM pendant les tests : aucune requête ne part vers les vrais services.
 *
 * Nominatim simulé : les localités ci-dessous (celles des données de démonstration, plus Salé
 * qui n'y figure pas) ; un point appartient à la localité la plus proche à moins de 12 km,
 * sinon il n'est dans aucune localité. Une latitude inférieure à 27 est hors du Maroc.
 */
final class FakeOsmResponses
{
    public const LOCALITIES = [
        'Agadir' => ['relation', 2529624, 30.420516, -9.583853],
        'Taroudant' => ['relation', 2835912, 30.470651, -8.877922],
        'Essaouira' => ['relation', 2821038, 31.511828, -9.762090],
        'Marrakech' => ['relation', 2799538, 31.625826, -7.989161],
        'Safi' => ['relation', 5935945, 32.299424, -9.239533],
        'El Jadida' => ['relation', 5935946, 33.243331, -8.498840],
        'Casablanca' => ['relation', 4072985, 33.594514, -7.620028],
        'Rabat' => ['relation', 2799215, 34.021845, -6.840893],
        'Meknès' => ['relation', 2561177, 33.898413, -5.532158],
        'Fès' => ['relation', 2799557, 34.034653, -5.016193],
        'Tanger' => ['relation', 2758781, 35.769491, -5.804468],
        'Béni Mellal' => ['relation', 2882613, 32.334193, -6.353335],
        'Salé' => ['relation', 2801066, 34.044889, -6.814017],
    ];

    /** Lieux précis trouvés par la recherche, en plus des localités. */
    public const PLACES = [
        'agdal' => ['node', 2184710850, 33.998312, -6.854291, 'Agdal', 'Agdal, Arrondissement Agdal-Riyad, Rabat'],
        'casa voyageurs' => ['node', 12173815285, 33.589697, -7.590187, 'Casa Voyageurs', 'Casa Voyageurs, Parvis de la Gare, Casablanca'],
        'gare routière' => ['node', 1000001, 30.413000, -9.557000, 'Gare routière', 'Gare routière, Agadir'],
    ];

    /** « ok », « notfound » ou « down » */
    public static string $mode = 'ok';

    /** @var list<array{url: string, headers: list<string>}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$mode = 'ok';
        self::$requests = [];
    }

    /** Nombre de requêtes envoyées à un service (« /search », « /reverse », « /route »). */
    public static function count(string $path): int
    {
        return \count(array_filter(self::$requests, static fn (array $request) => str_contains($request['url'], $path)));
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        self::$requests[] = ['url' => $url, 'headers' => $options['headers'] ?? []];

        if ('down' === self::$mode) {
            return new MockResponse('', ['http_code' => 503]);
        }

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        if (str_contains($url, '/search')) {
            return new MockResponse(json_encode('notfound' === self::$mode ? [] : self::search((string) $query['q'])));
        }

        if (str_contains($url, '/reverse')) {
            return new MockResponse(json_encode(self::reverse((float) $query['lat'], (float) $query['lon'], (int) $query['zoom'])));
        }

        // OSRM : un tronçon de 100 km, parcouru en une heure, entre chaque paire d'étapes consécutives.
        preg_match('#/driving/([^?]+)#', $url, $matches);
        $points = array_map(static fn (string $p) => array_map('floatval', explode(',', $p)), explode(';', urldecode($matches[1])));

        return new MockResponse(json_encode([
            'code' => 'Ok',
            'routes' => [[
                'legs' => array_fill(0, \count($points) - 1, ['distance' => 100000.4, 'duration' => 3600.2]),
                'geometry' => ['coordinates' => $points],
            ]],
        ]));
    }

    /** @return list<array<string, mixed>> */
    private static function search(string $text): array
    {
        $text = mb_strtolower(trim($text));

        foreach (self::PLACES as $key => [$type, $id, $lat, $lon, $name, $address]) {
            if (str_contains($text, $key)) {
                return [self::result($type, $id, $lat, $lon, $name, $address.', Maroc', 'amenity')];
            }
        }

        foreach (self::LOCALITIES as $name => [$type, $id, $lat, $lon]) {
            if (str_contains($text, mb_strtolower($name))) {
                return [self::result($type, $id, $lat, $lon, $name, $name.', Maroc', 'city')];
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private static function reverse(float $latitude, float $longitude, int $zoom): array
    {
        if ($latitude < 27) {
            return self::result('relation', 999, $latitude, $longitude, 'Ailleurs', 'Ailleurs', 'city') + ['address' => ['country_code' => 'dz']];
        }

        $nearest = null;
        $nearestKm = 12.0;

        foreach (self::LOCALITIES as $name => [$type, $id, $lat, $lon]) {
            $km = hypot(($lat - $latitude) * 111.3, ($lon - $longitude) * 111.3 * cos(deg2rad($latitude)));

            if ($km < $nearestKm) {
                [$nearest, $nearestKm] = [[$name, $type, $id, $lat, $lon], $km];
            }
        }

        if (null === $nearest) {
            // Point hors de toute localité : Nominatim renvoie la province.
            return self::result('relation', 998, $latitude, $longitude, 'Province', 'Province, Maroc', 'state') + ['address' => ['country_code' => 'ma']];
        }

        [$name, $type, $id, $lat, $lon] = $nearest;

        return $zoom >= 18
            ? self::result('node', 5000 + $id, $latitude, $longitude, 'Rue du Marché', 'Rue du Marché, '.$name.', Maroc', 'road') + ['address' => ['country_code' => 'ma']]
            : self::result($type, $id, $lat, $lon, $name, $name.', Maroc', 'city') + ['address' => ['country_code' => 'ma']];
    }

    /** @return array<string, mixed> */
    private static function result(string $type, int $id, float $lat, float $lon, string $name, string $display, string $addresstype): array
    {
        return [
            'osm_type' => $type, 'osm_id' => $id, 'lat' => (string) $lat, 'lon' => (string) $lon,
            'name' => $name, 'display_name' => $display, 'addresstype' => $addresstype,
        ];
    }
}
