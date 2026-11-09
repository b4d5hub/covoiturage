<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial. Les contrôles CHECK sont écrits à la main : ils ne proviennent pas du mapping Doctrine.
 */
final class Version20261109185730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial du domaine et contrôles CHECK';
    }

    // Le DDL MySQL valide implicitement les transactions.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, seats INT NOT NULL, seat_price_cents INT NOT NULL, total_price_cents INT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, cancelled_at DATETIME DEFAULT NULL, trip_id INT NOT NULL, passenger_id INT NOT NULL, boarding_stop_id INT NOT NULL, alighting_stop_id INT NOT NULL, INDEX IDX_E00CEDDEA5BC2E0E (trip_id), INDEX IDX_E00CEDDE4502E565 (passenger_id), INDEX IDX_E00CEDDE501FDD12 (boarding_stop_id), INDEX IDX_E00CEDDEF9F2BF58 (alighting_stop_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE city (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, UNIQUE INDEX uniq_city_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, message LONGTEXT NOT NULL, created_at DATETIME NOT NULL, is_read TINYINT NOT NULL, recipient_id INT NOT NULL, INDEX IDX_BF5476CAE92F8F78 (recipient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, amount_cents INT NOT NULL, status VARCHAR(20) NOT NULL, paid_at DATETIME NOT NULL, refunded_at DATETIME DEFAULT NULL, booking_id INT NOT NULL, UNIQUE INDEX UNIQ_6D28840D3301C60 (booking_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE review (id INT AUTO_INCREMENT NOT NULL, rating INT NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, trip_id INT NOT NULL, author_id INT NOT NULL, target_id INT NOT NULL, UNIQUE INDEX uniq_review_direction (trip_id, author_id, target_id), INDEX IDX_794381C6A5BC2E0E (trip_id), INDEX IDX_794381C6F675F31B (author_id), INDEX IDX_794381C6158E0B66 (target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trip (id INT AUTO_INCREMENT NOT NULL, seat_capacity INT NOT NULL, full_price_cents INT NOT NULL, status VARCHAR(20) NOT NULL, itinerary_frozen_at DATETIME DEFAULT NULL, cancellation_reason LONGTEXT DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, vehicle_id INT NOT NULL, INDEX IDX_7656F53B545317D1 (vehicle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trip_segment (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, distance_meters INT NOT NULL, trip_id INT NOT NULL, UNIQUE INDEX uniq_trip_segment_position (trip_id, position), INDEX IDX_2E6DB0C8A5BC2E0E (trip_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trip_stop (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, meeting_address VARCHAR(255) NOT NULL, latitude NUMERIC(9, 6) NOT NULL, longitude NUMERIC(9, 6) NOT NULL, arrival_at DATETIME DEFAULT NULL, departure_at DATETIME DEFAULT NULL, trip_id INT NOT NULL, city_id INT NOT NULL, INDEX idx_trip_stop_city_departure (city_id, departure_at), UNIQUE INDEX uniq_trip_stop_position (trip_id, position), INDEX IDX_926E85DDA5BC2E0E (trip_id), INDEX IDX_926E85DD8BAC62AF (city_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vehicle (id INT AUTO_INCREMENT NOT NULL, brand VARCHAR(60) NOT NULL, model VARCHAR(60) NOT NULL, passenger_seats INT NOT NULL, owner_id INT NOT NULL, INDEX IDX_1B80E4867E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEA5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE4502E565 FOREIGN KEY (passenger_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE501FDD12 FOREIGN KEY (boarding_stop_id) REFERENCES trip_stop (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDEF9F2BF58 FOREIGN KEY (alighting_stop_id) REFERENCES trip_stop (id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D3301C60 FOREIGN KEY (booking_id) REFERENCES booking (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6A5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6F675F31B FOREIGN KEY (author_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C6158E0B66 FOREIGN KEY (target_id) REFERENCES app_user (id)');
        $this->addSql('ALTER TABLE trip ADD CONSTRAINT FK_7656F53B545317D1 FOREIGN KEY (vehicle_id) REFERENCES vehicle (id)');
        $this->addSql('ALTER TABLE trip_segment ADD CONSTRAINT FK_2E6DB0C8A5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id)');
        $this->addSql('ALTER TABLE trip_stop ADD CONSTRAINT FK_926E85DDA5BC2E0E FOREIGN KEY (trip_id) REFERENCES trip (id)');
        $this->addSql('ALTER TABLE trip_stop ADD CONSTRAINT FK_926E85DD8BAC62AF FOREIGN KEY (city_id) REFERENCES city (id)');
        $this->addSql('ALTER TABLE vehicle ADD CONSTRAINT FK_1B80E4867E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id)');

        $checks = [
            'vehicle' => [
                'chk_vehicle_passenger_seats' => 'passenger_seats > 0',
            ],
            'trip' => [
                'chk_trip_seat_capacity' => 'seat_capacity > 0',
                'chk_trip_full_price' => 'full_price_cents > 0',
            ],
            'trip_stop' => [
                'chk_trip_stop_position' => 'position >= 0',
                'chk_trip_stop_latitude' => 'latitude BETWEEN -90 AND 90',
                'chk_trip_stop_longitude' => 'longitude BETWEEN -180 AND 180',
                'chk_trip_stop_has_time' => 'arrival_at IS NOT NULL OR departure_at IS NOT NULL',
                'chk_trip_stop_time_order' => 'arrival_at IS NULL OR departure_at IS NULL OR departure_at >= arrival_at',
            ],
            'trip_segment' => [
                'chk_trip_segment_position' => 'position >= 0',
                'chk_trip_segment_distance' => 'distance_meters > 0',
            ],
            'booking' => [
                'chk_booking_seats' => 'seats > 0',
                'chk_booking_seat_price' => 'seat_price_cents >= 0',
                'chk_booking_total_price' => 'total_price_cents >= 0',
                'chk_booking_total_matches' => 'total_price_cents = seat_price_cents * seats',
                'chk_booking_distinct_stops' => 'boarding_stop_id <> alighting_stop_id',
            ],
            'payment' => [
                'chk_payment_amount' => 'amount_cents >= 0',
            ],
            'review' => [
                'chk_review_rating' => 'rating BETWEEN 1 AND 5',
                'chk_review_distinct_users' => 'author_id <> target_id',
            ],
        ];

        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $name, $expression));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEA5BC2E0E');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE4502E565');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE501FDD12');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDEF9F2BF58');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAE92F8F78');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D3301C60');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6A5BC2E0E');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6F675F31B');
        $this->addSql('ALTER TABLE review DROP FOREIGN KEY FK_794381C6158E0B66');
        $this->addSql('ALTER TABLE trip DROP FOREIGN KEY FK_7656F53B545317D1');
        $this->addSql('ALTER TABLE trip_segment DROP FOREIGN KEY FK_2E6DB0C8A5BC2E0E');
        $this->addSql('ALTER TABLE trip_stop DROP FOREIGN KEY FK_926E85DDA5BC2E0E');
        $this->addSql('ALTER TABLE trip_stop DROP FOREIGN KEY FK_926E85DD8BAC62AF');
        $this->addSql('ALTER TABLE vehicle DROP FOREIGN KEY FK_1B80E4867E3C61F9');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE booking');
        $this->addSql('DROP TABLE city');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE review');
        $this->addSql('DROP TABLE trip');
        $this->addSql('DROP TABLE trip_segment');
        $this->addSql('DROP TABLE trip_stop');
        $this->addSql('DROP TABLE vehicle');
    }
}
