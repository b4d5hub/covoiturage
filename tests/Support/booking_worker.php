<?php

/*
 * Processus indépendant utilisé par le test de concurrence : il prépare sa propre connexion,
 * attend le signal de départ commun, tente une réservation et écrit le résultat.
 */

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use App\Exception\DomainRuleException;
use App\Kernel;
use App\Service\BookingService;
use App\Service\PortionPricing;
use App\Service\SimulatedPayment;
use Symfony\Component\Dotenv\Dotenv;

// Une erreur fatale doit rester visible dans le rapport du test.
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if (null !== $error) {
        fwrite(STDERR, sprintf('%s (%s:%d)', $error['message'], $error['file'], $error['line']));
    }
});

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
(new Dotenv())->bootEnv($root.'/.env');

// Même configuration que la suite de tests, pour réutiliser son cache déjà construit.
$kernel = new Kernel('test', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();

// Préparation unique avant le lancement des processus concurrents : les fichiers de cache
// générés à la demande ne doivent pas être construits par plusieurs processus à la fois.
if ('--warm-up' === ($argv[1] ?? null)) {
    $em->getProxyFactory()->generateProxyClasses($em->getMetadataFactory()->getAllMetadata());
    exit(0);
}

[, $syncDir, $workerId, $tripId, $passengerId, $boarding, $alighting, $seats] = $argv;
$service = new BookingService($em, $em->getRepository(Booking::class), new PortionPricing(), new SimulatedPayment($em));

$trip = $em->find(Trip::class, (int) $tripId);
$passenger = $em->find(User::class, (int) $passengerId);
$boardingStop = $trip->getStopAt((int) $boarding);
$alightingStop = $trip->getStopAt((int) $alighting);
$now = new DateTimeImmutable('2026-11-20 10:00', new DateTimeZone('UTC'));

// Lecture hors transaction terminée : tous les processus sont prêts avant le départ commun.
touch("$syncDir/ready-$workerId");

$deadline = microtime(true) + 120;

while (!file_exists("$syncDir/go") && microtime(true) < $deadline) {
    usleep(1000);
}

try {
    $service->book($trip, $passenger, $boardingStop, $alightingStop, (int) $seats, $now);
    $result = 'booked';
} catch (DomainRuleException $exception) {
    $result = 'refused';
} catch (Throwable $exception) {
    $result = 'error: '.$exception->getMessage();
}

file_put_contents("$syncDir/result-$workerId", $result);
