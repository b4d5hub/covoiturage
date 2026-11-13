<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Trip;
use App\Entity\User;
use App\Exception\DomainRuleException;
use App\Repository\BookingRepository;
use App\Time\MoroccoTime;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annulation d'un trajet par son conducteur et passage à l'état « effectué ».
 */
final class TripLifecycleService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly SimulatedPayment $payments,
    ) {
    }

    /** Annule le trajet entier : réservations annulées, paiements remboursés, passagers notifiés. */
    public function cancel(Trip $trip, User $actor, string $reason, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $this->entityManager->getConnection()->transactional(function () use ($trip, $actor, $reason, $now): void {
            $this->entityManager->refresh($trip, LockMode::PESSIMISTIC_WRITE);

            if ($trip->getDriver() !== $actor) {
                throw new DomainRuleException('Seul le conducteur peut annuler son trajet.');
            }

            $trip->cancel($reason, $now);

            foreach ($this->bookings->findConfirmedForUpdate($trip) as $booking) {
                $booking->cancelWithTrip($now);
                $this->payments->refund($booking, $now);
                $this->entityManager->persist((new Notification())
                    ->setRecipient($booking->getPassenger())
                    ->setBooking($booking)
                    ->setCreatedAt($now)
                    ->setMessage(\sprintf(
                        'Le trajet %s du %s a été annulé par le conducteur. Motif : %s Votre paiement simulé a été intégralement remboursé.',
                        $trip,
                        MoroccoTime::toLocal($trip->getDepartureAt())->format('d/m/Y à H:i'),
                        $trip->getCancellationReason(),
                    )));
            }

            $this->entityManager->flush();
        });
    }

    public function complete(Trip $trip, User $actor, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $this->entityManager->getConnection()->transactional(function () use ($trip, $actor, $now): void {
            $this->entityManager->refresh($trip, LockMode::PESSIMISTIC_WRITE);

            if ($trip->getDriver() !== $actor && !\in_array('ROLE_ADMIN', $actor->getRoles(), true)) {
                throw new DomainRuleException('Seul le conducteur ou un administrateur peut marquer un trajet comme effectué.');
            }

            $trip->complete($now);
            $this->entityManager->flush();
        });
    }
}
