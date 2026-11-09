<?php

namespace App\Tests\Unit;

use App\Entity\Booking;
use App\Entity\TripStop;
use App\Tests\Support\TestData;
use App\Time\MoroccoTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le Maroc passe à UTC+0 le 7 février 2027 à 02:00 UTC, puis à UTC+1 le 14 mars 2027 à 02:00 UTC.
 */
final class MoroccoTimeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function conversions(): iterable
    {
        yield 'heure normale UTC+1' => ['2026-11-20 08:00', '2026-11-20 07:00'];
        yield 'période UTC+0' => ['2027-02-20 08:00', '2027-02-20 08:00'];
        yield 'retour à UTC+1' => ['2027-03-20 08:00', '2027-03-20 07:00'];
    }

    #[DataProvider('conversions')]
    public function testLocalInputBecomesUtcAndBack(string $local, string $utc): void
    {
        $instant = MoroccoTime::toUtc($local);

        self::assertSame('UTC', $instant->getTimezone()->getName());
        self::assertSame($utc, $instant->format('Y-m-d H:i'));
        self::assertSame($local, MoroccoTime::toLocal($instant)->format('Y-m-d H:i'));
    }

    public function testRulesCoverTheExpectedClockChanges(): void
    {
        $transitions = (new \DateTimeZone(MoroccoTime::ZONE))->getTransitions(
            strtotime('2027-01-01 00:00:00 UTC'),
            strtotime('2027-04-01 00:00:00 UTC'),
        );

        self::assertSame(
            [[strtotime('2027-02-07 02:00:00 UTC'), 0], [strtotime('2027-03-14 02:00:00 UTC'), 3600]],
            array_map(fn (array $t) => [$t['ts'], $t['offset']], \array_slice($transitions, 1)),
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function deadlines(): iterable
    {
        // Départ local, limite UTC attendue, limite locale attendue.
        yield 'sans changement d\'heure' => ['2026-12-01 09:00', '2026-12-01 06:00', '2026-12-01 07:00'];
        yield 'passage à UTC+0 entre la limite et le départ' => ['2027-02-07 03:30', '2027-02-07 01:30', '2027-02-07 02:30'];
        yield 'passage à UTC+1 entre la limite et le départ' => ['2027-03-14 03:30', '2027-03-14 00:30', '2027-03-14 00:30'];
    }

    #[DataProvider('deadlines')]
    public function testCancellationDeadlineIsTwoRealHoursBeforeDeparture(string $localDeparture, string $utcDeadline, string $localDeadline): void
    {
        $departure = MoroccoTime::toUtc($localDeparture);
        $deadline = $this->bookingDepartingAt($departure)->getCancellationDeadline();

        self::assertSame(7200, $departure->getTimestamp() - $deadline->getTimestamp());
        self::assertSame($utcDeadline, $deadline->format('Y-m-d H:i'));
        self::assertSame($localDeadline, MoroccoTime::toLocal($deadline)->format('Y-m-d H:i'));
    }

    public function testDeadlineDoesNotDependOnTheZoneOfTheStoredDate(): void
    {
        $utc = MoroccoTime::toUtc('2027-02-07 03:30');
        $local = MoroccoTime::toLocal($utc);

        self::assertEquals(
            $this->bookingDepartingAt($utc)->getCancellationDeadline(),
            $this->bookingDepartingAt($local)->getCancellationDeadline(),
        );
    }

    private function bookingDepartingAt(\DateTimeImmutable $departure): Booking
    {
        $stop = new TripStop(TestData::city(), 'Gare routière', '30.42', '-9.59', null, $departure);

        return (new Booking())->setBoardingStop($stop);
    }
}
