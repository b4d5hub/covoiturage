<?php

namespace App\Entity;

use App\Enum\PaymentStatus;
use App\Exception\DomainRuleException;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement simulé : aucune donnée bancaire n'est saisie ni stockée.
 */
#[ORM\Entity]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Booking $booking = null;

    #[ORM\Column]
    private ?int $amountCents = null;

    #[ORM\Column(length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::Paid;

    #[ORM\Column]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    public function __construct()
    {
        $this->paidAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function setBooking(Booking $booking): static
    {
        $this->booking = $booking;

        return $this;
    }

    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): static
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    /** Remboursement simulé et intégral, possible une seule fois. */
    public function refund(\DateTimeImmutable $at): void
    {
        if (PaymentStatus::Paid !== $this->status) {
            throw new DomainRuleException('Ce paiement est déjà remboursé.');
        }

        $this->status = PaymentStatus::Refunded;
        $this->refundedAt = $at;
    }

    public function getPaidAt(): \DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }
}
