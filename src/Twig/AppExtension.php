<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('mad', static fn (?int $cents): string => null === $cents ? '' : \sprintf('%s,%02d MAD', number_format(intdiv($cents, 100), 0, ',', ' '), $cents % 100)),
            new TwigFilter('km', static fn (int $meters): string => number_format($meters / 1000, 0, ',', ' ').' km'),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications', function (): int {
                $user = $this->security->getUser();

                return $user instanceof User ? $this->notifications->countUnread($user) : 0;
            }),
        ];
    }
}
