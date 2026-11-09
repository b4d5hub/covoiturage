<?php

namespace App\Service;

use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Exception\DomainRuleException;
use App\Repository\BookingRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dépôt des avis entre passagers et conducteur, après un trajet effectué.
 */
final class ReviewService
{
    private const REVIEW_PERIOD = '+14 days';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingRepository $bookings,
    ) {
    }

    public function submit(Trip $trip, User $author, User $target, int $rating, ?string $comment = null, ?\DateTimeImmutable $now = null): Review
    {
        $now ??= new \DateTimeImmutable();

        return $this->entityManager->getConnection()->transactional(function () use ($trip, $author, $target, $rating, $comment, $now): Review {
            $this->entityManager->refresh($trip, LockMode::PESSIMISTIC_WRITE);
            $this->assertCanReview($trip, $author, $target, $rating, $now);

            $comment = null === $comment ? null : trim($comment);
            $review = (new Review())
                ->setTrip($trip)
                ->setAuthor($author)
                ->setTarget($target)
                ->setRating($rating)
                ->setComment('' === $comment ? null : $comment)
                ->setCreatedAt($now);

            $this->entityManager->persist($review);
            $this->entityManager->flush();

            return $review;
        });
    }

    private function assertCanReview(Trip $trip, User $author, User $target, int $rating, \DateTimeImmutable $now): void
    {
        if (TripStatus::Completed !== $trip->getStatus()) {
            throw new DomainRuleException('Un avis ne peut être déposé qu\'après un trajet effectué.');
        }

        if ($now > $trip->getCompletedAt()->modify(self::REVIEW_PERIOD)) {
            throw new DomainRuleException('Le délai de 14 jours pour déposer un avis est dépassé.');
        }

        if ($rating < 1 || $rating > 5) {
            throw new DomainRuleException('La note doit être comprise entre 1 et 5.');
        }

        $driver = $trip->getDriver();

        if ($author === $target || ($author !== $driver && $target !== $driver)) {
            throw new DomainRuleException('Un avis ne peut être déposé qu\'entre un passager et le conducteur.');
        }

        if (!$this->bookings->hasConfirmedBooking($trip, $author === $driver ? $target : $author)) {
            throw new DomainRuleException('Seul un passager ayant effectué ce trajet peut être concerné par un avis.');
        }

        if (null !== $this->entityManager->getRepository(Review::class)->findOneBy(['trip' => $trip, 'author' => $author, 'target' => $target])) {
            throw new DomainRuleException('Un avis a déjà été déposé pour cette personne sur ce trajet.');
        }
    }
}
