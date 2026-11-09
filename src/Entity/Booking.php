<?php

namespace App\Entity;

use App\Enum\BookingStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réservation d'une ou plusieurs places sur une portion de trajet.
 * Les montants sont figés à la réservation.
 */
#[ORM\Entity]
class Booking
{
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

    public function setStatus(BookingStatus $status): static
    {
        $this->status = $status;

        return $this;
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

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }
}
