<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Payment;
use App\Exception\DomainRuleException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paiement simulé : aucune donnée bancaire et aucun prestataire externe.
 * Toujours appelé dans la transaction de l'opération qui le déclenche.
 */
final class SimulatedPayment
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function pay(Booking $booking, \DateTimeImmutable $at): Payment
    {
        $payment = (new Payment())
            ->setBooking($booking)
            ->setAmountCents($booking->getTotalPriceCents())
            ->setPaidAt($at);
        $this->entityManager->persist($payment);

        return $payment;
    }

    public function refund(Booking $booking, \DateTimeImmutable $at): void
    {
        $payment = $this->entityManager->getRepository(Payment::class)->findOneBy(['booking' => $booking]);

        if (null === $payment) {
            throw new DomainRuleException('Aucun paiement n\'est associé à cette réservation.');
        }

        $payment->refund($at);
    }
}
