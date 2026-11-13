<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Localité (ville, commune ou village) servant à la recherche.
 * Elle est identifiée par son objet OpenStreetMap : deux lieux d'une même localité
 * mènent toujours à la même ville, quel que soit le nom affiché.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_city_osm', columns: ['osm_type', 'osm_id'])]
#[ORM\Index(name: 'idx_city_name', columns: ['name'])]
class City
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    /** « node », « way » ou « relation » ; absent pour une ville saisie sans OpenStreetMap. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $osmType = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $osmId = null;

    /** Centre de la localité, point de recherche quand le passager saisit le nom de la ville. */
    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    private ?string $latitude = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    private ?string $longitude = null;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getOsmType(): ?string
    {
        return $this->osmType;
    }

    public function getOsmId(): ?string
    {
        return $this->osmId;
    }

    public function setOsm(string $type, string|int $id): static
    {
        $this->osmType = $type;
        $this->osmId = (string) $id;

        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setCentre(string $latitude, string $longitude): static
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return $this;
    }

    /** « relation 2799215 », pour le back-office. */
    public function getOsmReference(): string
    {
        return null === $this->osmType ? 'Aucun' : $this->osmType.' '.$this->osmId;
    }

    public function getCentreLabel(): string
    {
        return $this->hasCentre() ? $this->latitude.', '.$this->longitude : 'Inconnu';
    }

    public function hasCentre(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }
}
