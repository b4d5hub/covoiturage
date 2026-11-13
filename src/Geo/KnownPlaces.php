<?php

namespace App\Geo;

use Doctrine\DBAL\Connection;

/**
 * Recherche pendant la frappe, dans les données de l'application seulement : villes connues
 * et points de rendez-vous déjà utilisés par des trajets publiés. Aucun appel à Nominatim,
 * dont la politique d'usage interdit l'autocomplétion.
 */
final class KnownPlaces
{
    private const CITY_LIMIT = 4;
    private const POINT_LIMIT = 6;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{kind: string, name: string, city: string, latitude: string, longitude: string}>
     */
    public function search(string $text): array
    {
        // Les colonnes comparent sans accents ni casse : « fes » trouve « Fès ».
        $like = '%'.addcslashes($text, '%_\\').'%';
        $prefix = addcslashes($text, '%_\\').'%';

        $cities = $this->connection->fetchAllAssociative(
            'SELECT name, latitude, longitude FROM city
             WHERE latitude IS NOT NULL AND name LIKE :like
             ORDER BY name LIKE :prefix DESC, name
             LIMIT '.self::CITY_LIMIT,
            ['like' => $like, 'prefix' => $prefix],
        );

        // Points de rendez-vous déjà choisis par des conducteurs, les plus utilisés d'abord.
        $points = $this->connection->fetchAllAssociative(
            'SELECT s.meeting_address AS name, c.name AS city, MIN(s.latitude) AS latitude, MIN(s.longitude) AS longitude, COUNT(*) AS uses
             FROM trip_stop s
             JOIN city c ON c.id = s.city_id
             WHERE s.meeting_address LIKE :like OR c.name LIKE :like
             GROUP BY s.meeting_address, c.name
             ORDER BY s.meeting_address LIKE :prefix DESC, uses DESC, s.meeting_address
             LIMIT '.self::POINT_LIMIT,
            ['like' => $like, 'prefix' => $prefix],
        );

        return [
            ...array_map(static fn (array $row) => [
                'kind' => 'city',
                'name' => (string) $row['name'],
                'city' => (string) $row['name'],
                'latitude' => (string) $row['latitude'],
                'longitude' => (string) $row['longitude'],
            ], $cities),
            ...array_map(static fn (array $row) => [
                'kind' => 'point',
                'name' => (string) $row['name'],
                'city' => (string) $row['city'],
                'latitude' => (string) $row['latitude'],
                'longitude' => (string) $row['longitude'],
            ], $points),
        ];
    }
}
