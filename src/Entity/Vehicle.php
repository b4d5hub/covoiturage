<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
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

    public function setOwner(User $owner): static
    {
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
}
