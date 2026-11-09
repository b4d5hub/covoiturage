<?php

namespace App\Entity;

use App\Enum\TripStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Trajet publié. Le conducteur est le propriétaire du véhicule : il n'est pas stocké une seconde fois.
 */
#[ORM\Entity]
class Trip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Vehicle $vehicle = null;

    /** Places proposées sur chaque segment du trajet. */
    #[ORM\Column]
    #[Assert\Positive]
    private ?int $seatCapacity = null;

    /** Prix d'une place pour le trajet complet, en centimes de MAD. */
    #[ORM\Column]
    #[Assert\Positive]
    private ?int $fullPriceCents = null;

    #[ORM\Column(length: 20, enumType: TripStatus::class)]
    private TripStatus $status = TripStatus::Published;

    /** Renseignée à la première réservation confirmée, et jamais remise à zéro. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $itineraryFrozenAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cancellationReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** Point de départ du délai de dépôt des avis. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, TripStop> */
    #[ORM\OneToMany(targetEntity: TripStop::class, mappedBy: 'trip', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $stops;

    /** @var Collection<int, TripSegment> */
    #[ORM\OneToMany(targetEntity: TripSegment::class, mappedBy: 'trip', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $segments;

    /** @var Collection<int, Booking> */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'trip')]
    private Collection $bookings;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->stops = new ArrayCollection();
        $this->segments = new ArrayCollection();
        $this->bookings = new ArrayCollection();
    }

    public function __toString(): string
    {
        $first = $this->stops->first();
        $last = $this->stops->last();

        return ($first && $last) ? $first->getCity().' – '.$last->getCity() : 'Trajet';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVehicle(): ?Vehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(Vehicle $vehicle): static
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getDriver(): ?User
    {
        return $this->vehicle?->getOwner();
    }

    public function getSeatCapacity(): ?int
    {
        return $this->seatCapacity;
    }

    public function setSeatCapacity(int $seatCapacity): static
    {
        $this->seatCapacity = $seatCapacity;

        return $this;
    }

    public function getFullPriceCents(): ?int
    {
        return $this->fullPriceCents;
    }

    public function setFullPriceCents(int $fullPriceCents): static
    {
        $this->fullPriceCents = $fullPriceCents;

        return $this;
    }

    public function getStatus(): TripStatus
    {
        return $this->status;
    }

    public function setStatus(TripStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getItineraryFrozenAt(): ?\DateTimeImmutable
    {
        return $this->itineraryFrozenAt;
    }

    public function setItineraryFrozenAt(?\DateTimeImmutable $itineraryFrozenAt): static
    {
        $this->itineraryFrozenAt = $itineraryFrozenAt;

        return $this;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function setCancellationReason(?string $cancellationReason): static
    {
        $this->cancellationReason = $cancellationReason;

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

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, TripStop> */
    public function getStops(): Collection
    {
        return $this->stops;
    }

    public function addStop(TripStop $stop): static
    {
        if (!$this->stops->contains($stop)) {
            $this->stops->add($stop);
            $stop->setTrip($this);
        }

        return $this;
    }

    /** @return Collection<int, TripSegment> */
    public function getSegments(): Collection
    {
        return $this->segments;
    }

    public function addSegment(TripSegment $segment): static
    {
        if (!$this->segments->contains($segment)) {
            $this->segments->add($segment);
            $segment->setTrip($this);
        }

        return $this;
    }

    /** @return Collection<int, Booking> */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }
}
