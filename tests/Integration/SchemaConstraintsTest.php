<?php

namespace App\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Vérifie les contraintes en écrivant directement en SQL, sans passer par l'application.
 */
final class SchemaConstraintsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $now = "'2026-12-01 07:00:00'";
        $this->connection->executeStatement("INSERT INTO app_user (id, email, password, roles, first_name, last_name, created_at) VALUES
            (1, 'a@example.test', 'x', '[]', 'A', 'A', $now), (2, 'b@example.test', 'x', '[]', 'B', 'B', $now)");
        $this->connection->executeStatement("INSERT INTO city (id, name) VALUES (1, 'Agadir'), (2, 'Marrakech')");
        $this->connection->executeStatement('INSERT INTO vehicle (id, owner_id, brand, model, passenger_seats) VALUES (1, 1, \'Dacia\', \'Logan\', 4)');
        $this->connection->executeStatement("INSERT INTO trip (id, vehicle_id, seat_capacity, full_price_cents, status, created_at) VALUES (1, 1, 3, 20000, 'published', $now)");
        $this->connection->executeStatement("INSERT INTO trip_stop (id, trip_id, city_id, position, meeting_address, latitude, longitude, arrival_at, departure_at) VALUES
            (1, 1, 1, 0, 'Gare', 30.42, -9.59, NULL, $now), (2, 1, 2, 1, 'Gare', 31.63, -8.00, '2026-12-01 10:00:00', NULL)");
        $this->connection->executeStatement('INSERT INTO trip_segment (id, trip_id, position, distance_meters) VALUES (1, 1, 0, 250000)');
        $this->connection->executeStatement("INSERT INTO booking (id, trip_id, passenger_id, boarding_stop_id, alighting_stop_id, seats, seat_price_cents, total_price_cents, status, created_at) VALUES (1, 1, 2, 1, 2, 2, 1000, 2000, 'confirmed', $now)");
        $this->connection->executeStatement("INSERT INTO payment (id, booking_id, amount_cents, status, paid_at) VALUES (1, 1, 2000, 'paid', $now)");
        $this->connection->executeStatement("INSERT INTO review (id, trip_id, author_id, target_id, rating, created_at) VALUES (1, 1, 2, 1, 5, $now)");
        $this->connection->executeStatement("INSERT INTO notification (id, recipient_id, message, is_read, created_at) VALUES (1, 2, 'Message', 0, $now)");
    }

    /** @return iterable<string, array{string, string}> */
    public static function checkViolations(): iterable
    {
        yield 'places du véhicule' => ['chk_vehicle_passenger_seats', 'UPDATE vehicle SET passenger_seats = 0'];
        yield 'capacité du trajet' => ['chk_trip_seat_capacity', 'UPDATE trip SET seat_capacity = 0'];
        yield 'prix du trajet' => ['chk_trip_full_price', 'UPDATE trip SET full_price_cents = 0'];
        yield 'rang d\'étape' => ['chk_trip_stop_position', 'UPDATE trip_stop SET position = -1 WHERE id = 1'];
        yield 'latitude haute' => ['chk_trip_stop_latitude', 'UPDATE trip_stop SET latitude = 90.5 WHERE id = 1'];
        yield 'latitude basse' => ['chk_trip_stop_latitude', 'UPDATE trip_stop SET latitude = -90.5 WHERE id = 1'];
        yield 'longitude haute' => ['chk_trip_stop_longitude', 'UPDATE trip_stop SET longitude = 180.5 WHERE id = 1'];
        yield 'longitude basse' => ['chk_trip_stop_longitude', 'UPDATE trip_stop SET longitude = -180.5 WHERE id = 1'];
        yield 'étape sans horaire' => ['chk_trip_stop_has_time', 'UPDATE trip_stop SET departure_at = NULL WHERE id = 1'];
        yield 'départ avant arrivée' => ['chk_trip_stop_time_order', "UPDATE trip_stop SET departure_at = '2026-12-01 09:00:00' WHERE id = 2"];
        yield 'rang de segment' => ['chk_trip_segment_position', 'UPDATE trip_segment SET position = -1'];
        yield 'distance nulle' => ['chk_trip_segment_distance', 'UPDATE trip_segment SET distance_meters = 0'];
        yield 'places réservées' => ['chk_booking_seats', 'UPDATE booking SET seats = 0, total_price_cents = 0'];
        yield 'prix négatif' => ['chk_booking_seat_price', 'UPDATE booking SET seat_price_cents = -1, total_price_cents = -2'];
        // Un total négatif viole aussi la cohérence avec le prix : MySQL signale l'un ou l'autre contrôle.
        yield 'total négatif' => ['chk_booking_total_(price|matches)', 'UPDATE booking SET total_price_cents = -1'];
        yield 'total incohérent' => ['chk_booking_total_matches', 'UPDATE booking SET total_price_cents = 1999'];
        yield 'mêmes étapes' => ['chk_booking_distinct_stops', 'UPDATE booking SET alighting_stop_id = boarding_stop_id'];
        yield 'montant négatif' => ['chk_payment_amount', 'UPDATE payment SET amount_cents = -1'];
        yield 'note trop basse' => ['chk_review_rating', 'UPDATE review SET rating = 0'];
        yield 'note trop haute' => ['chk_review_rating', 'UPDATE review SET rating = 6'];
        yield 'auteur égal au destinataire' => ['chk_review_distinct_users', 'UPDATE review SET target_id = author_id'];
    }

    #[DataProvider('checkViolations')]
    public function testCheckConstraintRejects(string $constraint, string $sql): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches("/$constraint/");
        $this->connection->executeStatement($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function uniqueViolations(): iterable
    {
        yield 'e-mail' => ["UPDATE app_user SET email = 'a@example.test' WHERE id = 2"];
        yield 'ville' => ["UPDATE city SET name = 'Agadir' WHERE id = 2"];
        yield 'rang d\'étape' => ['UPDATE trip_stop SET position = 0 WHERE id = 2'];
        yield 'rang de segment' => ["INSERT INTO trip_segment (trip_id, position, distance_meters) VALUES (1, 0, 1000)"];
        yield 'second paiement' => ["INSERT INTO payment (booking_id, amount_cents, status, paid_at) VALUES (1, 2000, 'paid', '2026-12-01 07:00:00')"];
        yield 'second avis même sens' => ["INSERT INTO review (trip_id, author_id, target_id, rating, created_at) VALUES (1, 2, 1, 4, '2026-12-01 07:00:00')"];
    }

    #[DataProvider('uniqueViolations')]
    public function testUniqueConstraintRejects(string $sql): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignKeyViolations(): iterable
    {
        yield 'supprimer un compte référencé' => ['DELETE FROM app_user WHERE id = 1'];
        yield 'supprimer une ville référencée' => ['DELETE FROM city WHERE id = 1'];
        yield 'supprimer un véhicule référencé' => ['DELETE FROM vehicle WHERE id = 1'];
        yield 'supprimer un trajet référencé' => ['DELETE FROM trip WHERE id = 1'];
        yield 'supprimer une étape référencée' => ['DELETE FROM trip_stop WHERE id = 1'];
        yield 'supprimer une réservation payée' => ['DELETE FROM booking WHERE id = 1'];
        yield 'référence inexistante' => ['UPDATE trip SET vehicle_id = 999'];
    }

    #[DataProvider('foreignKeyViolations')]
    public function testForeignKeyRejects(string $sql): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->connection->executeStatement($sql);
    }

    /** @return iterable<string, array{string}> */
    public static function notNullViolations(): iterable
    {
        yield 'adresse' => ['UPDATE trip_stop SET meeting_address = NULL'];
        yield 'latitude' => ['UPDATE trip_stop SET latitude = NULL'];
        yield 'distance' => ['UPDATE trip_segment SET distance_meters = NULL'];
        yield 'montant de réservation' => ['UPDATE booking SET total_price_cents = NULL'];
        yield 'réservation du paiement' => ['UPDATE payment SET booking_id = NULL'];
        yield 'véhicule du trajet' => ['UPDATE trip SET vehicle_id = NULL'];
    }

    #[DataProvider('notNullViolations')]
    public function testNotNullRejects(string $sql): void
    {
        $this->expectException(NotNullConstraintViolationException::class);
        $this->connection->executeStatement($sql);
    }

    public function testTheOppositeReviewDirectionIsAllowed(): void
    {
        $inserted = $this->connection->executeStatement("INSERT INTO review (trip_id, author_id, target_id, rating, created_at) VALUES (1, 1, 2, 4, '2026-12-01 07:00:00')");

        self::assertSame(1, (int) $inserted);
    }
}
