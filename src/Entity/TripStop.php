<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Étape d'un trajet : la ville sert à la recherche, les coordonnées à la carte et à l'itinéraire.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_trip_stop_position', columns: ['trip_id', 'position'])]
#[ORM\Index(name: 'idx_trip_stop_city_departure', columns: ['city_id', 'departure_at'])]
class TripStop
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'stops')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Trip $trip = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?City $city = null;

    /** Rang de l'étape dans le trajet, à partir de 0. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $position = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $meetingAddress = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    #[Assert\NotBlank]
    #[Assert\Range(min: -90, max: 90)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    #[Assert\NotBlank]
    #[Assert\Range(min: -180, max: 180)]
    private ?string $longitude = null;

    /** Absente pour la première étape. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $arrivalAt = null;

    /** Absente pour la dernière étape. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $departureAt = null;

    public function __toString(): string
    {
        return $this->city.' – '.$this->meetingAddress;
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

    public function getCity(): ?City
    {
        return $this->city;
    }

    public function setCity(City $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getMeetingAddress(): ?string
    {
        return $this->meetingAddress;
    }

    public function setMeetingAddress(string $meetingAddress): static
    {
        $this->meetingAddress = $meetingAddress;

        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(string $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(string $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getArrivalAt(): ?\DateTimeImmutable
    {
        return $this->arrivalAt;
    }

    public function setArrivalAt(?\DateTimeImmutable $arrivalAt): static
    {
        $this->arrivalAt = $arrivalAt;

        return $this;
    }

    public function getDepartureAt(): ?\DateTimeImmutable
    {
        return $this->departureAt;
    }

    public function setDepartureAt(?\DateTimeImmutable $departureAt): static
    {
        $this->departureAt = $departureAt;

        return $this;
    }
}
