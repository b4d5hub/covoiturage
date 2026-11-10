<?php

namespace App\Tests\Integration;

use App\Tests\Support\TestData;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class LoadDemoDataIfEmptyCommandTest extends DatabaseTestCase
{
    private function runCommand(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:demo-data:load-if-empty'));
        $tester->execute([], ['interactive' => false]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    public function testLoadsDemoDataIntoAnEmptyDatabase(): void
    {
        $this->runCommand();

        self::assertSame(11, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_user'));
        self::assertSame(10, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM trip'));
    }

    public function testNeverTouchesADatabaseThatAlreadyHasAccounts(): void
    {
        $user = TestData::user();
        $this->em->persist($user);
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_user'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM trip'));
        self::assertStringContainsString('conservées', $tester->getDisplay());
    }
}
