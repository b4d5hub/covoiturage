<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnread(User $user): int
    {
        return $this->count(['recipient' => $user, 'isRead' => false]);
    }

    /** @return list<Notification> */
    public function findFor(User $user): array
    {
        return $this->findBy(['recipient' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<Notification> les plus récentes, pour l'aperçu de la cloche */
    public function findLatestFor(User $user, int $limit): array
    {
        return $this->findBy(['recipient' => $user], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    public function markAllRead(User $user): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', ':read')
            ->andWhere('n.recipient = :user')
            ->setParameter('read', true)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
