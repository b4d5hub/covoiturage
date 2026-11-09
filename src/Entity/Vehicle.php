<?php

namespace App\Entity;

use App\Exception\DomainRuleException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Vehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'vehicles')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?User $owner = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private ?string $brand = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private ?string $model = null;

    /** Places proposables aux passagers, hors conducteur. */
    #[ORM\Column]
    #[Assert\Positive]
    private ?int $passengerSeats = null;

    /** @var Collection<int, Trip> */
    #[ORM\OneToMany(targetEntity: Trip::class, mappedBy: 'vehicle')]
    private Collection $trips;

    public function __construct()
    {
        $this->trips = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->brand.' '.$this->model;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    /**
     * Le conducteur d'un trajet est le propriétaire de son véhicule :
     * le propriétaire ne change plus dès qu'un trajet utilise le véhicule.
     */
    public function setOwner(User $owner): static
    {
        if (null !== $this->owner && $owner !== $this->owner && !$this->trips->isEmpty()) {
            throw new DomainRuleException('Le propriétaire d\'un véhicule utilisé par un trajet ne peut pas changer.');
        }

        $this->owner = $owner;

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(string $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getPassengerSeats(): ?int
    {
        return $this->passengerSeats;
    }

    public function setPassengerSeats(int $passengerSeats): static
    {
        $this->passengerSeats = $passengerSeats;

        return $this;
    }

    /** @return Collection<int, Trip> */
    public function getTrips(): Collection
    {
        return $this->trips;
    }

    /** @internal Appelée uniquement par Trip::setVehicle(). */
    public function registerTrip(Trip $trip): void
    {
        if (!$this->trips->contains($trip)) {
            $this->trips->add($trip);
        }
    }

    /** @return list<string> */
    private function violations(): array
    {
        foreach ($this->trips as $trip) {
            if (null !== $this->passengerSeats && $trip->getSeatCapacity() > $this->passengerSeats) {
                return ['Le nombre de places du véhicule ne peut pas être inférieur à la capacité d\'un trajet qui l\'utilise.'];
            }
        }

        return [];
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        foreach ($this->violations() as $message) {
            $context->buildViolation($message)->atPath('passengerSeats')->addViolation();
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
