<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tronçon entre deux étapes consécutives : unité de calcul des places et du prix.
 * Le segment de rang N relie l'étape de rang N à l'étape de rang N + 1.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_trip_segment_position', columns: ['trip_id', 'position'])]
class TripSegment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'segments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Trip $trip = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?TripStop $fromStop = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?TripStop $toStop = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private ?int $position = null;

    /** Distance routière en mètres entiers. */
    #[ORM\Column]
    #[Assert\Positive]
    private ?int $distanceMeters = null;

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

    public function getFromStop(): ?TripStop
    {
        return $this->fromStop;
    }

    public function setFromStop(TripStop $fromStop): static
    {
        $this->fromStop = $fromStop;

        return $this;
    }

    public function getToStop(): ?TripStop
    {
        return $this->toStop;
    }

    public function setToStop(TripStop $toStop): static
    {
        $this->toStop = $toStop;

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

    public function getDistanceMeters(): ?int
    {
        return $this->distanceMeters;
    }

    public function setDistanceMeters(int $distanceMeters): static
    {
        $this->distanceMeters = $distanceMeters;

        return $this;
    }
}
