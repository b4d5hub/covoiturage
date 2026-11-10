<?php

namespace App\Repository;

use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /** @return list<Review> */
    public function findReceivedBy(User $user): array
    {
        return $this->findBy(['target' => $user], ['createdAt' => 'DESC']);
    }

    /** @return array{average: ?float, count: int} */
    public function ratingOf(User $user): array
    {
        $row = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) AS average', 'COUNT(r.id) AS total')
            ->andWhere('r.target = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();

        return ['average' => null === $row['average'] ? null : round((float) $row['average'], 1), 'count' => (int) $row['total']];
    }

    public function exists(Trip $trip, User $author, User $target): bool
    {
        return null !== $this->findOneBy(['trip' => $trip, 'author' => $author, 'target' => $target]);
    }
}
