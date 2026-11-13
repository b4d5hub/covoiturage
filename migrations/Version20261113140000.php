<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une notification se rapporte à la réservation qu'elle concerne : la page ouverte depuis la
 * notification en dépend (réservation du passager, trajet du conducteur).
 */
final class Version20261113140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réservation concernée par une notification';
    }

    // Le DDL MySQL valide implicitement les transactions.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD booking_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA3301C60 FOREIGN KEY (booking_id) REFERENCES booking (id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA3301C60 ON notification (booking_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA3301C60');
        $this->addSql('DROP INDEX IDX_BF5476CA3301C60 ON notification');
        $this->addSql('ALTER TABLE notification DROP booking_id');
    }
}
