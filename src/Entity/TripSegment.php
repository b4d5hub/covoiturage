<?php

namespace App\Entity;

use App\Exception\DomainRuleException;
use Doctrine\ORM\Mapping as ORM;

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
    private Trip $trip;

    #[ORM\Column]
    private int $position;

    /** Distance routière en mètres entiers. */
    #[ORM\Column]
    private int $distanceMeters;

    public function __construct(Trip $trip, int $position, int $distanceMeters)
    {
        $this->trip = $trip;
        $this->position = $position;
        $this->changeDistance($distanceMeters);
    }

    /** @internal Appelée uniquement par Trip::replaceItinerary(). */
    public function changeDistance(int $distanceMeters): void
    {
        if ($distanceMeters <= 0) {
            throw new DomainRuleException('La distance d\'un segment doit être strictement positive.');
        }

        $this->distanceMeters = $distanceMeters;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrip(): Trip
    {
        return $this->trip;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getFromStop(): ?TripStop
    {
        return $this->trip->getStopAt($this->position);
    }

    public function getToStop(): ?TripStop
    {
        return $this->trip->getStopAt($this->position + 1);
    }

    public function getDistanceMeters(): int
    {
        return $this->distanceMeters;
    }
}
