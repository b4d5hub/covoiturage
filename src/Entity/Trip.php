<?php

namespace App\Entity;

use App\Enum\TripStatus;
use App\Exception\DomainRuleException;
use App\Repository\TripRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Trajet publié. Le conducteur est le propriétaire du véhicule : il n'est pas stocké une seconde fois.
 */
#[ORM\Entity(repositoryClass: TripRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Trip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'trips')]
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

    /** Renseignée une seule fois, par freezeItinerary(), et jamais remise à zéro. */
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
    #[Assert\Valid]
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
        $first = $this->getStopAt(0);
        $last = $this->getStopAt($this->stops->count() - 1);

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

    /** Le véhicule d'un trajet ne change pas : il détermine son conducteur. */
    public function setVehicle(Vehicle $vehicle): static
    {
        if (null !== $this->vehicle && $vehicle !== $this->vehicle) {
            throw new DomainRuleException('Le véhicule d\'un trajet existant ne peut pas changer.');
        }

        $this->vehicle = $vehicle;
        $vehicle->registerTrip($this);

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

    /** Capacité initiale. Un trajet enregistré se modifie par TripCapacityManager. */
    public function setSeatCapacity(int $seatCapacity): static
    {
        if (null !== $this->id && $seatCapacity !== $this->seatCapacity) {
            throw new DomainRuleException('La capacité d\'un trajet enregistré doit être modifiée en tenant compte de son occupation.');
        }

        $this->seatCapacity = $seatCapacity;

        return $this;
    }

    /**
     * @internal L'occupation doit être lue sous le verrou du trajet : voir TripCapacityManager.
     */
    public function changeSeatCapacity(int $seatCapacity, int $occupiedSeats): void
    {
        if ($seatCapacity <= 0) {
            throw new DomainRuleException('La capacité d\'un trajet doit être strictement positive.');
        }

        if ($seatCapacity > $this->vehicle?->getPassengerSeats()) {
            throw new DomainRuleException('La capacité d\'un trajet ne peut pas dépasser le nombre de places du véhicule.');
        }

        if ($seatCapacity < $occupiedSeats) {
            throw new DomainRuleException('La capacité d\'un trajet ne peut pas être inférieure aux places déjà occupées.');
        }

        $this->seatCapacity = $seatCapacity;
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

    public function getRouteLabel(): string
    {
        return (string) $this;
    }

    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    /** Annulation par le conducteur, possible uniquement avant le début du trajet. */
    public function cancel(string $reason, \DateTimeImmutable $at): void
    {
        if (TripStatus::Published !== $this->status) {
            throw new DomainRuleException('Seul un trajet publié peut être annulé.');
        }

        if ('' === trim($reason)) {
            throw new DomainRuleException('Le motif d\'annulation est obligatoire.');
        }

        if ($at >= $this->getDepartureAt()) {
            throw new DomainRuleException('Un trajet déjà commencé ne peut pas être annulé.');
        }

        $this->status = TripStatus::Cancelled;
        $this->cancellationReason = trim($reason);
        $this->cancelledAt = $at;
    }

    /** Un trajet ne peut être marqué effectué qu'après l'heure d'arrivée prévue à la dernière étape. */
    public function complete(\DateTimeImmutable $at): void
    {
        if (TripStatus::Published !== $this->status) {
            throw new DomainRuleException('Seul un trajet publié peut être marqué comme effectué.');
        }

        if ($at < $this->getStopAt($this->stops->count() - 1)->getArrivalAt()) {
            throw new DomainRuleException('Un trajet ne peut pas être marqué comme effectué avant son arrivée prévue.');
        }

        $this->status = TripStatus::Completed;
        $this->completedAt = $at;
    }

    public function getItineraryFrozenAt(): ?\DateTimeImmutable
    {
        return $this->itineraryFrozenAt;
    }

    public function isItineraryFrozen(): bool
    {
        return null !== $this->itineraryFrozenAt;
    }

    /** Gel définitif : un second appel conserve la date du premier. */
    public function freezeItinerary(?\DateTimeImmutable $at = null): void
    {
        $this->itineraryFrozenAt ??= ($at ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
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

    /** Départ du trajet : heure de départ de la première étape. */
    public function getDepartureAt(): ?\DateTimeImmutable
    {
        return $this->getStopAt(0)?->getDepartureAt();
    }

    public function getStopAt(int $position): ?TripStop
    {
        foreach ($this->stops as $stop) {
            if ($stop->getPosition() === $position) {
                return $stop;
            }
        }

        return null;
    }

    /** @return Collection<int, TripSegment> */
    public function getSegments(): Collection
    {
        return $this->segments;
    }

    /** @return Collection<int, Booking> */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }

    /**
     * Seul point de modification de l'itinéraire : les rangs des étapes et des segments
     * sont déduits de l'ordre fourni, et les lignes existantes sont mises à jour sur place.
     *
     * @param list<TripStop> $stops           étapes dans l'ordre du trajet, non encore rattachées
     * @param list<int>      $distancesMeters distance de chaque segment, dans le même ordre
     */
    public function replaceItinerary(array $stops, array $distancesMeters): void
    {
        if ($this->isItineraryFrozen()) {
            throw new DomainRuleException('L\'itinéraire est gelé : le trajet doit être annulé puis publié de nouveau.');
        }

        $stops = array_values($stops);
        $distancesMeters = array_values($distancesMeters);
        $stopCount = \count($stops);

        if ($stopCount < 2) {
            throw new DomainRuleException('Un trajet doit comporter au moins deux étapes.');
        }

        if (\count($distancesMeters) !== $stopCount - 1) {
            throw new DomainRuleException('Un trajet de N étapes doit comporter exactement N − 1 segments.');
        }

        foreach ($distancesMeters as $distance) {
            if ($distance <= 0) {
                throw new DomainRuleException('La distance d\'un segment doit être strictement positive.');
            }
        }

        foreach (self::scheduleViolations($stops) as $message) {
            throw new DomainRuleException($message);
        }

        foreach ($stops as $position => $stop) {
            $existing = $this->getStopAt($position);

            if (null !== $existing) {
                $existing->copyDetailsFrom($stop);
            } else {
                $stop->attachTo($this, $position);
                $this->stops->add($stop);
            }
        }

        foreach ($this->stops->toArray() as $stop) {
            if ($stop->getPosition() >= $stopCount) {
                $this->stops->removeElement($stop);
            }
        }

        foreach ($distancesMeters as $position => $distance) {
            $existing = $this->getSegmentAt($position);

            if (null !== $existing) {
                $existing->changeDistance($distance);
            } else {
                $this->segments->add(new TripSegment($this, $position, $distance));
            }
        }

        foreach ($this->segments->toArray() as $segment) {
            if ($segment->getPosition() >= $stopCount - 1) {
                $this->segments->removeElement($segment);
            }
        }
    }

    public function getSegmentAt(int $position): ?TripSegment
    {
        foreach ($this->segments as $segment) {
            if ($segment->getPosition() === $position) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * @param list<TripStop> $stops étapes dans l'ordre du trajet
     *
     * @return list<string>
     */
    private static function scheduleViolations(array $stops): array
    {
        $last = \count($stops) - 1;

        foreach ($stops as $index => $stop) {
            $arrival = $stop->getArrivalAt();
            $departure = $stop->getDepartureAt();

            if ((0 === $index) !== (null === $arrival)) {
                return ['Seule la première étape n\'a pas d\'heure d\'arrivée.'];
            }

            if (($index === $last) !== (null === $departure)) {
                return ['Seule la dernière étape n\'a pas d\'heure de départ.'];
            }

            if (null !== $arrival && null !== $departure && $departure < $arrival) {
                return ['Le départ d\'une étape ne peut pas précéder son arrivée.'];
            }

            if ($index > 0 && $arrival <= $stops[$index - 1]->getDepartureAt()) {
                return ['Les horaires des étapes doivent se suivre dans l\'ordre du trajet.'];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function itineraryViolations(): array
    {
        $stops = $this->stops->toArray();
        $segments = $this->segments->toArray();
        usort($stops, static fn (TripStop $a, TripStop $b) => $a->getPosition() <=> $b->getPosition());
        usort($segments, static fn (TripSegment $a, TripSegment $b) => $a->getPosition() <=> $b->getPosition());

        if (\count($stops) < 2) {
            return ['Un trajet doit comporter au moins deux étapes.'];
        }

        if (array_map(static fn (TripStop $s) => $s->getPosition(), $stops) !== array_keys($stops)) {
            return ['Les rangs des étapes doivent être uniques et consécutifs à partir de 0.'];
        }

        if (\count($segments) !== \count($stops) - 1) {
            return ['Un trajet de N étapes doit comporter exactement N − 1 segments.'];
        }

        if (array_map(static fn (TripSegment $s) => $s->getPosition(), $segments) !== array_keys($segments)) {
            return ['Les rangs des segments doivent être uniques et consécutifs à partir de 0.'];
        }

        foreach ($segments as $segment) {
            if ($segment->getDistanceMeters() <= 0) {
                return ['La distance d\'un segment doit être strictement positive.'];
            }
        }

        return self::scheduleViolations($stops);
    }

    /** @return list<string> */
    private function violations(): array
    {
        $violations = $this->itineraryViolations();

        if (null !== $this->seatCapacity && $this->seatCapacity <= 0) {
            $violations[] = 'La capacité d\'un trajet doit être strictement positive.';
        }

        if (null !== $this->seatCapacity && null !== $this->vehicle && $this->seatCapacity > $this->vehicle->getPassengerSeats()) {
            $violations[] = 'La capacité d\'un trajet ne peut pas dépasser le nombre de places du véhicule.';
        }

        return $violations;
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        foreach ($this->violations() as $message) {
            $context->addViolation($message);
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function assertRulesHold(): void
    {
        foreach ($this->violations() as $message) {
            throw new DomainRuleException($message);
        }
    }
}
