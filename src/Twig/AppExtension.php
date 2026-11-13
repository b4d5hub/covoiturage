<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Time\MoroccoTime;
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
            // Préposition « de » avec élision devant une voyelle ou un h : « d'Agadir », « de Rabat ».
            new TwigFilter('de', static fn (string $name): string => (preg_match('/^[aeiouyhàâäéèêëîïôöùûü]/iu', $name) ? 'd\'' : 'de ').$name),
            // Distance courte, au dixième de kilomètre : « 1,8 km ».
            new TwigFilter('short_km', static fn (int $meters): string => number_format($meters / 1000, 1, ',', ' ').' km'),
            // Date en toutes lettres, à l'heure du Maroc : « lundi 16 novembre », « 16 nov. ».
            new TwigFilter('fr_date', static function (?\DateTimeInterface $date, string $pattern = 'EEEE d MMMM'): string {
                if (null === $date) {
                    return '';
                }

                $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, MoroccoTime::ZONE, null, $pattern);

                return (string) $formatter->format($date);
            }),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications', function (): int {
                $user = $this->security->getUser();

                return $user instanceof User ? $this->notifications->countUnread($user) : 0;
            }),
            // Durée entre deux horaires : « 45 min », « 2 h 30 ».
            new TwigFunction('duration', static function (?\DateTimeInterface $from, ?\DateTimeInterface $to): string {
                if (null === $from || null === $to) {
                    return '';
                }

                $minutes = intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), 60);
                $hours = intdiv($minutes, 60);

                return match (true) {
                    0 === $hours => $minutes.' min',
                    0 === $minutes % 60 => $hours.' h',
                    default => \sprintf('%d h %02d', $hours, $minutes % 60),
                };
            }),
            new TwigFunction('initials', static fn (User $user): string => mb_strtoupper(mb_substr((string) $user->getFirstName(), 0, 1).mb_substr((string) $user->getLastName(), 0, 1))),
        ];
    }
}
