<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Étape d'un trajet : la ville sert à la recherche, les coordonnées à la carte et à l'itinéraire.
 * Une étape ne se modifie que par Trip::replaceItinerary(), qui maintient la cohérence de l'itinéraire.
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
    private City $city;

    /** Rang de l'étape dans le trajet, à partir de 0. */
    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $meetingAddress;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    #[Assert\Range(min: -90, max: 90)]
    private string $latitude;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6)]
    #[Assert\Range(min: -180, max: 180)]
    private string $longitude;

    /** En UTC. Absente pour la première étape. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $arrivalAt;

    /** En UTC. Absente pour la dernière étape. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $departureAt;

    public function __construct(
        City $city,
        string $meetingAddress,
        string $latitude,
        string $longitude,
        ?\DateTimeImmutable $arrivalAt,
        ?\DateTimeImmutable $departureAt,
    ) {
        $this->city = $city;
        $this->meetingAddress = $meetingAddress;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->arrivalAt = $arrivalAt?->setTimezone(new \DateTimeZone('UTC'));
        $this->departureAt = $departureAt?->setTimezone(new \DateTimeZone('UTC'));
    }

    public function __toString(): string
    {
        return $this->city.' – '.$this->meetingAddress;
    }

    /** @internal Appelée uniquement par Trip::replaceItinerary(). */
    public function attachTo(Trip $trip, int $position): void
    {
        $this->trip = $trip;
        $this->position = $position;
    }

    /** @internal Appelée uniquement par Trip::replaceItinerary(). */
    public function copyDetailsFrom(self $other): void
    {
        $this->city = $other->city;
        $this->meetingAddress = $other->meetingAddress;
        $this->latitude = $other->latitude;
        $this->longitude = $other->longitude;
        $this->arrivalAt = $other->arrivalAt;
        $this->departureAt = $other->departureAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function getCity(): City
    {
        return $this->city;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getMeetingAddress(): string
    {
        return $this->meetingAddress;
    }

    public function getLatitude(): string
    {
        return $this->latitude;
    }

    public function getLongitude(): string
    {
        return $this->longitude;
    }

    public function getArrivalAt(): ?\DateTimeImmutable
    {
        return $this->arrivalAt;
    }

    public function getDepartureAt(): ?\DateTimeImmutable
    {
        return $this->departureAt;
    }
}
