<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Villes identifiées par leur objet OpenStreetMap, avec les coordonnées de leur centre,
 * et index sur les coordonnées des étapes pour la recherche dans un rayon.
 */
final class Version20261112170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Villes OpenStreetMap et recherche par rayon';
    }

    // Le DDL MySQL valide implicitement les transactions.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Deux localités distinctes peuvent porter le même nom : l'unicité passe sur l'identifiant OSM.
        $this->addSql('ALTER TABLE city DROP INDEX uniq_city_name, ADD INDEX idx_city_name (name)');
        $this->addSql('ALTER TABLE city ADD osm_type VARCHAR(8) DEFAULT NULL, ADD osm_id BIGINT DEFAULT NULL, ADD latitude NUMERIC(9, 6) DEFAULT NULL, ADD longitude NUMERIC(9, 6) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_city_osm ON city (osm_type, osm_id)');
        $this->addSql('CREATE INDEX idx_trip_stop_coordinates ON trip_stop (latitude, longitude)');

        foreach ([
            'chk_city_osm_type' => "osm_type IS NULL OR osm_type IN ('node', 'way', 'relation')",
            'chk_city_osm_pair' => '(osm_type IS NULL) = (osm_id IS NULL)',
            'chk_city_centre_pair' => '(latitude IS NULL) = (longitude IS NULL)',
            'chk_city_centre_range' => 'latitude IS NULL OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)',
        ] as $name => $condition) {
            $this->addSql(\sprintf('ALTER TABLE city ADD CONSTRAINT %s CHECK (%s)', $name, $condition));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['chk_city_osm_type', 'chk_city_osm_pair', 'chk_city_centre_pair', 'chk_city_centre_range'] as $name) {
            $this->addSql(\sprintf('ALTER TABLE city DROP CHECK %s', $name));
        }

        $this->addSql('DROP INDEX idx_trip_stop_coordinates ON trip_stop');
        $this->addSql('DROP INDEX uniq_city_osm ON city');
        $this->addSql('ALTER TABLE city DROP osm_type, DROP osm_id, DROP latitude, DROP longitude');
        $this->addSql('ALTER TABLE city DROP INDEX idx_city_name, ADD UNIQUE INDEX uniq_city_name (name)');
    }
}
