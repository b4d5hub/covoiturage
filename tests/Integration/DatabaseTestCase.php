<?php

namespace App\Tests\Integration;

use App\Entity\Trip;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests sur le vrai schéma MySQL, issu de la migration, vidé avant chaque test.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->em->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->connection->createSchemaManager()->introspectTableNames() as $name) {
            if ('doctrine_migration_versions' !== $name->getUnqualifiedName()->getValue()) {
                $this->connection->executeStatement('TRUNCATE TABLE '.$name->getUnqualifiedName()->getValue());
            }
        }

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    protected function saveTrip(Trip $trip): Trip
    {
        foreach ($trip->getStops() as $stop) {
            $this->em->persist($stop->getCity());
        }

        $this->em->persist($trip->getDriver());
        $this->em->persist($trip->getVehicle());
        $this->em->persist($trip);
        $this->em->flush();

        return $trip;
    }

    protected function reload(string $class, int $id): object
    {
        $this->em->clear();

        return $this->em->find($class, $id);
    }
}
