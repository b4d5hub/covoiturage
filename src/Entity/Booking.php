<?php

namespace App\Entity;

use App\Enum\BookingStatus;
use App\Exception\DomainRuleException;
use App\Repository\BookingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Réservation d'une ou plusieurs places sur une portion de trajet.
 * Les montants sont figés à la réservation.
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Booking
{
    /** Délai minimal avant le départ de l'étape de montée pour annuler. */
    private const CANCELLATION_NOTICE = '-2 hours';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Trip $trip = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?User $passenger = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?TripStop $boardingStop = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?TripStop $alightingStop = null;

    #[ORM\Column]
    #[Assert\Positive]
    private ?int $seats = null;

    /** Prix d'une place pour cette portion, en centimes. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $seatPriceCents = null;

    /** Prix d'une place multiplié par le nombre de places, en centimes. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $totalPriceCents = null;

    #[ORM\Column(length: 20, enumType: BookingStatus::class)]
    private BookingStatus $status = BookingStatus::Confirmed;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return "Réservation n°".$this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(Trip $trip): static
    {
        $this->trip = $trip;

        return $this;
    }

    public function getPassenger(): ?User
    {
        return $this->passenger;
    }

    public function setPassenger(User $passenger): static
    {
        $this->passenger = $passenger;

        return $this;
    }

    public function getBoardingStop(): ?TripStop
    {
        return $this->boardingStop;
    }

    public function setBoardingStop(TripStop $boardingStop): static
    {
        $this->boardingStop = $boardingStop;

        return $this;
    }

    public function getAlightingStop(): ?TripStop
    {
        return $this->alightingStop;
    }

    public function setAlightingStop(TripStop $alightingStop): static
    {
        $this->alightingStop = $alightingStop;

        return $this;
    }

    public function getSeats(): ?int
    {
        return $this->seats;
    }

    public function setSeats(int $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    public function getSeatPriceCents(): ?int
    {
        return $this->seatPriceCents;
    }

    public function setSeatPriceCents(int $seatPriceCents): static
    {
        $this->seatPriceCents = $seatPriceCents;

        return $this;
    }

    public function getTotalPriceCents(): ?int
    {
        return $this->totalPriceCents;
    }

    public function setTotalPriceCents(int $totalPriceCents): static
    {
        $this->totalPriceCents = $totalPriceCents;

        return $this;
    }

    public function getStatus(): BookingStatus
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    public function isConfirmed(): bool
    {
        return BookingStatus::Confirmed === $this->status;
    }

    /** Annulation par le passager, possible jusqu'à la limite de deux heures incluse. */
    public function cancelByPassenger(\DateTimeImmutable $at): void
    {
        if ($at > $this->getCancellationDeadline()) {
            throw new DomainRuleException('L\'annulation n\'est plus possible à moins de deux heures du départ.');
        }

        $this->markCancelled($at);
    }

    /** Annulation entraînée par celle du trajet : le délai du passager ne s'applique pas. */
    public function cancelWithTrip(\DateTimeImmutable $at): void
    {
        $this->markCancelled($at);
    }

    private function markCancelled(\DateTimeImmutable $at): void
    {
        if (!$this->isConfirmed()) {
            throw new DomainRuleException('Cette réservation est déjà annulée.');
        }

        $this->status = BookingStatus::Cancelled;
        $this->cancelledAt = $at;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    /**
     * Dernier instant d'annulation par le passager. Le calcul se fait en UTC :
     * les deux heures restent deux heures réelles lors d'un changement d'heure au Maroc.
     */
    public function getCancellationDeadline(): \DateTimeImmutable
    {
        return $this->boardingStop->getDepartureAt()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->modify(self::CANCELLATION_NOTICE);
    }

    /** @return list<string> */
    private function routeViolations(): array
    {
        if (null === $this->trip || null === $this->boardingStop || null === $this->alightingStop) {
            return [];
        }

        if ($this->boardingStop->getTrip() !== $this->trip) {
            return ['L\'étape de montée doit appartenir au trajet réservé.'];
        }

        if ($this->alightingStop->getTrip() !== $this->trip) {
            return ['L\'étape de descente doit appartenir au trajet réservé.'];
        }

        if ($this->boardingStop->getPosition() >= $this->alightingStop->getPosition()) {
            return ['L\'étape de montée doit précéder l\'étape de descente.'];
        }

        return [];
    }

    #[Assert\Callback]
    public function validateRoute(ExecutionContextInterface $context): void
    {
        foreach ($this->routeViolations() as $message) {
            $context->addViolation($message);
        }
    }

    /** Dernier garde-fou : s'applique à tout chemin d'écriture passant par Doctrine. */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function assertRouteIsValid(): void
    {
        foreach ($this->routeViolations() as $message) {
            throw new DomainRuleException($message);
        }
    }
}
