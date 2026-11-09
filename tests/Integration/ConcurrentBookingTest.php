<?php

namespace App\Tests\Integration;

use App\Entity\Trip;
use App\Tests\Support\TestData;

/**
 * Plusieurs processus PHP indépendants réservent au même instant, sur le vrai MySQL.
 */
final class ConcurrentBookingTest extends ServiceTestCase
{
    private const WORKER = __DIR__.'/../Support/booking_worker.php';

    private static bool $warmedUp = false;

    private function warmUp(): void
    {
        if (self::$warmedUp) {
            return;
        }

        $process = proc_open([\PHP_BINARY, self::WORKER, '--warm-up'], [], $pipes, null, ['APP_ENV' => 'test'] + getenv());
        self::assertIsResource($process);
        self::assertSame(0, proc_close($process), 'Préparation du cache impossible');
        self::$warmedUp = true;
    }

    /**
     * @param list<array{int, int, int}> $requests montée, descente et nombre de places de chaque processus
     *
     * @return list<string> résultat de chaque processus
     */
    private function bookConcurrently(Trip $trip, array $requests): array
    {
        $this->warmUp();

        $syncDir = sys_get_temp_dir().'/covoiturage-'.bin2hex(random_bytes(6));
        mkdir($syncDir);
        $passengers = array_map(fn () => $this->passenger()->getId(), $requests);
        $tripId = $trip->getId();
        $this->em->clear();

        $processes = [];

        foreach ($requests as $index => [$boarding, $alighting, $seats]) {
            $arguments = array_map('escapeshellarg', [\PHP_BINARY, self::WORKER, $syncDir, (string) $index, (string) $tripId, (string) $passengers[$index], (string) $boarding, (string) $alighting, (string) $seats]);
            $command = implode(' ', $arguments).'; echo $? > '.escapeshellarg("$syncDir/exit-$index");
            $process = proc_open($command, [1 => ['file', "$syncDir/out-$index", 'w'], 2 => ['file', "$syncDir/err-$index", 'w']], $pipes, null, ['APP_ENV' => 'test'] + getenv());
            self::assertIsResource($process);
            $processes[] = $process;
        }

        try {
            $this->waitFor(fn () => \count(glob("$syncDir/ready-*")) === \count($requests), $syncDir);
            touch("$syncDir/go");
            $this->waitFor(fn () => \count(glob("$syncDir/result-*")) === \count($requests), $syncDir);

            return array_map(fn (int $index) => file_get_contents("$syncDir/result-$index"), array_keys($requests));
        } finally {
            array_map('proc_terminate', $processes);
            array_map('proc_close', $processes);
            array_map('unlink', glob("$syncDir/*"));
            rmdir($syncDir);
        }
    }

    private function waitFor(callable $condition, string $syncDir): void
    {
        $deadline = microtime(true) + 150;

        while (!$condition()) {
            // Un processus terminé sans résultat ne répondra plus : inutile d'attendre.
            $stopped = array_filter(glob("$syncDir/exit-*"), fn (string $file) => !file_exists(str_replace('/exit-', '/result-', $file)));

            if ([] !== $stopped || microtime(true) > $deadline) {
                $codes = array_map(fn (string $file) => basename($file).'='.trim(file_get_contents($file)), glob("$syncDir/exit-*"));
                $output = array_map('file_get_contents', [...glob("$syncDir/err-*"), ...glob("$syncDir/out-*")]);

                self::fail('Processus de test interrompus. Codes de sortie : '.implode(', ', $codes).'. Sorties : '.implode(' | ', array_filter($output)));
            }

            usleep(20000);
        }
    }

    public function testOnlyOnePassengerGetsTheLastSeat(): void
    {
        $trip = $this->saveTrip(TestData::trip(TestData::vehicle(seats: 4), 1));

        $results = $this->bookConcurrently($trip, array_fill(0, 8, [0, 2, 1]));

        self::assertSame(['booked' => 1, 'refused' => 7], $this->counts($results), implode(' | ', $results));
        self::assertSame(1, (int) $this->sql("SELECT COALESCE(SUM(seats), 0) FROM booking WHERE status = 'confirmed'"));
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM payment'));
    }

    /**
     * L'itinéraire est déjà gelé : le trajet n'est plus mis à jour par la réservation,
     * et seul le verrou empêche les réservations concurrentes de se chevaucher.
     */
    public function testLastSeatOfAnAlreadyBookedTripGoesToOnlyOnePassenger(): void
    {
        $trip = $this->saveTrip(TestData::trip(TestData::vehicle(seats: 4), 2));
        $this->book($trip);

        $results = $this->bookConcurrently($trip, array_fill(0, 8, [0, 2, 1]));

        self::assertSame(['booked' => 1, 'refused' => 7], $this->counts($results), implode(' | ', $results));
        self::assertSame(2, (int) $this->sql("SELECT SUM(seats) FROM booking WHERE status = 'confirmed'"));
    }

    public function testSeveralSeatRequestsNeverExceedCapacity(): void
    {
        $trip = $this->saveTrip(TestData::trip(TestData::vehicle(seats: 4), 4));

        $results = $this->bookConcurrently($trip, array_fill(0, 8, [0, 2, 3]));

        self::assertSame(['booked' => 1, 'refused' => 7], $this->counts($results), implode(' | ', $results));
        self::assertSame(3, (int) $this->sql("SELECT SUM(seats) FROM booking WHERE status = 'confirmed'"));
    }

    public function testOverlappingPortionsNeverOverbookAnySegment(): void
    {
        $trip = $this->saveTrip(TestData::trip(TestData::vehicle(seats: 4), 2));

        $results = $this->bookConcurrently($trip, [[0, 1, 1], [1, 2, 1], [0, 2, 1], [0, 1, 1], [1, 2, 1], [0, 2, 1], [0, 2, 2], [0, 1, 2]]);

        self::assertSame([], array_filter($results, fn (string $r) => str_starts_with($r, 'error')), implode(' | ', $results));

        foreach ($this->bookingRepository->occupiedSeatsBySegment($trip) as $occupied) {
            self::assertLessThanOrEqual(2, $occupied);
        }
    }

    public function testNonOverlappingPortionsBothSucceedOnASingleSeat(): void
    {
        $trip = $this->saveTrip(TestData::trip(TestData::vehicle(seats: 4), 1));

        $results = $this->bookConcurrently($trip, [[0, 1, 1], [1, 2, 1]]);

        self::assertSame(['booked' => 2], $this->counts($results), implode(' | ', $results));
    }

    /** @param list<string> $results */
    private function counts(array $results): array
    {
        $counts = array_count_values($results);
        ksort($counts);

        return $counts;
    }
}
