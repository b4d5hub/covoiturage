<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Exception\DomainRuleException;
use App\Repository\BookingRepository;
use App\Time\MoroccoTime;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création et annulation des réservations, sous le verrou du trajet :
 * deux opérations concurrentes sur un même trajet s'exécutent l'une après l'autre.
 */
final class BookingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
        private readonly PortionPricing $pricing,
        private readonly SimulatedPayment $payments,
    ) {
    }

    public function book(Trip $trip, User $passenger, TripStop $boarding, TripStop $alighting, int $seats, ?\DateTimeImmutable $now = null): Booking
    {
        $now ??= new \DateTimeImmutable();

        return $this->entityManager->getConnection()->transactional(function () use ($trip, $passenger, $boarding, $alighting, $seats, $now): Booking {
            $this->entityManager->refresh($trip, LockMode::PESSIMISTIC_WRITE);

            $this->assertCanBook($trip, $passenger, $boarding, $seats, $now);
            $this->assertSeatsAvailable($trip, $boarding, $alighting, $seats);

            $seatPrice = $this->pricing->seatPriceCents($trip, $boarding, $alighting);
            $booking = (new Booking())
                ->setTrip($trip)
                ->setPassenger($passenger)
                ->setBoardingStop($boarding)
                ->setAlightingStop($alighting)
                ->setSeats($seats)
                ->setSeatPriceCents($seatPrice)
                ->setTotalPriceCents($seatPrice * $seats)
                ->setCreatedAt($now);

            $this->entityManager->persist($booking);
            $this->payments->pay($booking, $now);
            $trip->freezeItinerary($now);
            // Le conducteur est prévenu dans l'application de chaque réservation reçue.
            $this->entityManager->persist((new Notification())
                ->setRecipient($trip->getDriver())
                ->setBooking($booking)
                ->setCreatedAt($now)
                ->setMessage(\sprintf(
                    'Nouvelle réservation : %s a réservé %d place%s de %s à %s sur votre trajet %s du %s.',
                    $passenger->getDisplayName(),
                    $seats,
                    $seats > 1 ? 's' : '',
                    $boarding->getCity(),
                    $alighting->getCity(),
                    $trip,
                    MoroccoTime::toLocal($trip->getDepartureAt())->format('d/m/Y à H:i'),
                )));
            $this->entityManager->flush();

            return $booking;
        });
    }

    public function cancelByPassenger(Booking $booking, User $actor, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $this->entityManager->getConnection()->transactional(function () use ($booking, $actor, $now): void {
            $this->entityManager->refresh($booking->getTrip(), LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($booking, LockMode::PESSIMISTIC_WRITE);

            if ($booking->getPassenger() !== $actor) {
                throw new DomainRuleException('Seul le passager peut annuler sa réservation.');
            }

            $booking->cancelByPassenger($now);
            $this->payments->refund($booking, $now);
            // Le conducteur est prévenu : les places sont de nouveau proposées aux autres passagers.
            $trip = $booking->getTrip();
            $this->entityManager->persist((new Notification())
                ->setRecipient($trip->getDriver())
                ->setBooking($booking)
                ->setCreatedAt($now)
                ->setMessage(\sprintf(
                    'Annulation : %s a annulé sa réservation de %d place%s de %s à %s sur votre trajet %s du %s. Ces places sont de nouveau disponibles.',
                    $booking->getPassenger()->getDisplayName(),
                    $booking->getSeats(),
                    $booking->getSeats() > 1 ? 's' : '',
                    $booking->getBoardingStop()->getCity(),
                    $booking->getAlightingStop()->getCity(),
                    $trip,
                    MoroccoTime::toLocal($trip->getDepartureAt())->format('d/m/Y à H:i'),
                )));
            $this->entityManager->flush();
        });
    }

    private function assertCanBook(Trip $trip, User $passenger, TripStop $boarding, int $seats, \DateTimeImmutable $now): void
    {
        if (TripStatus::Published !== $trip->getStatus()) {
            throw new DomainRuleException('Ce trajet n\'est plus ouvert à la réservation.');
        }

        if ($trip->getDriver() === $passenger) {
            throw new DomainRuleException('Un conducteur ne peut pas réserver son propre trajet.');
        }

        if ($seats <= 0) {
            throw new DomainRuleException('Le nombre de places doit être strictement positif.');
        }

        if ($now >= $boarding->getDepartureAt()) {
            throw new DomainRuleException('Cette étape est déjà partie.');
        }

        if ($this->bookings->hasConfirmedBooking($trip, $passenger)) {
            throw new DomainRuleException('Vous avez déjà une réservation active sur ce trajet.');
        }
    }

    private function assertSeatsAvailable(Trip $trip, TripStop $boarding, TripStop $alighting, int $seats): void
    {
        $free = $trip->getSeatCapacity();

        foreach ($this->bookings->occupiedSeatsBySegment($trip) as $position => $occupied) {
            if ($position >= $boarding->getPosition() && $position < $alighting->getPosition()) {
                $free = min($free, $trip->getSeatCapacity() - $occupied);
            }
        }

        if ($free < $seats) {
            throw new DomainRuleException(0 >= $free
                ? 'Il n\'y a pas assez de places disponibles sur cette portion : elle est complète.'
                : \sprintf('Il n\'y a pas assez de places disponibles sur cette portion : il en reste %d.', $free));
        }
    }
}
