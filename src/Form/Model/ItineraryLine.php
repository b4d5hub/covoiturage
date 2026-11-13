<?php

namespace App\Form\Model;

use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;

/**
 * Ligne de formulaire : une étape et la distance jusqu'à l'étape suivante.
 */
final class ItineraryLine
{
    public ?City $city = null;
    public ?string $meetingAddress = null;
    public ?string $latitude = null;
    public ?string $longitude = null;
    public ?\DateTimeImmutable $arrivalAt = null;
    public ?\DateTimeImmutable $departureAt = null;
    public ?int $distanceToNextMeters = null;

    /** @return list<self> */
    public static function fromTrip(Trip $trip): array
    {
        $lines = [];

        foreach ($trip->getStops() as $stop) {
            $line = new self();
            $line->city = $stop->getCity();
            $line->meetingAddress = $stop->getMeetingAddress();
            $line->latitude = $stop->getLatitude();
            $line->longitude = $stop->getLongitude();
            $line->arrivalAt = $stop->getArrivalAt();
            $line->departureAt = $stop->getDepartureAt();
            $line->distanceToNextMeters = $trip->getSegmentAt($stop->getPosition())?->getDistanceMeters();
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Itinéraire du trajet retour : mêmes étapes et mêmes distances, dans l'ordre inverse, sans horaires.
     *
     * @return list<self>
     */
    public static function returnOf(Trip $trip): array
    {
        $lines = array_reverse(self::fromTrip($trip));
        $count = \count($lines);

        foreach ($lines as $index => $line) {
            $line->arrivalAt = null;
            $line->departureAt = null;
            // La distance vers l'étape suivante du retour est celle du tronçon aller entre ces deux étapes.
            $line->distanceToNextMeters = $index < $count - 1 ? $trip->getSegmentAt($count - 2 - $index)?->getDistanceMeters() : null;
        }

        return $lines;
    }

    public function isComplete(): bool
    {
        return null !== $this->city && null !== $this->meetingAddress && null !== $this->latitude && null !== $this->longitude;
    }

    public function toStop(): TripStop
    {
        return new TripStop($this->city, $this->meetingAddress, $this->latitude, $this->longitude, $this->arrivalAt, $this->departureAt);
    }
}
