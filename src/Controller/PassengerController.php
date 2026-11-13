<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Exception\DomainRuleException;
use App\Repository\BookingRepository;
use App\Repository\NotificationRepository;
use App\Repository\ReviewRepository;
use App\Service\BookingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace passager : réservations, annulations, avis à déposer, et notifications de tout utilisateur.
 */
#[IsGranted('ROLE_USER')]
final class PassengerController extends AbstractController
{
    #[Route('/mes-reservations', name: 'app_my_bookings')]
    public function bookings(BookingRepository $bookings, ReviewRepository $reviews, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $now = new \DateTimeImmutable();
        $sections = ['upcoming' => [], 'history' => []];

        foreach ($bookings->findForPassenger($user) as $booking) {
            $item = $this->describe($booking, $user, $now, $reviews, $entityManager);
            $sections[$item['isUpcoming'] ? 'upcoming' : 'history'][] = $item;
        }

        // Les prochains départs d'abord, dans l'ordre chronologique.
        usort($sections['upcoming'], static fn (array $a, array $b) => $a['booking']->getBoardingStop()->getDepartureAt() <=> $b['booking']->getBoardingStop()->getDepartureAt());

        return $this->render('passenger/bookings.html.twig', $sections);
    }

    #[Route('/mes-reservations/{id}', name: 'app_booking_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Booking $booking, ReviewRepository $reviews, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($booking->getPassenger() !== $user) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('passenger/booking.html.twig', [
            'item' => $this->describe($booking, $user, new \DateTimeImmutable(), $reviews, $entityManager),
        ]);
    }

    /**
     * Ce qu'affichent la liste et le détail d'une réservation : état, paiement simulé et actions permises.
     *
     * @return array<string, mixed>
     */
    private function describe(Booking $booking, User $user, \DateTimeImmutable $now, ReviewRepository $reviews, EntityManagerInterface $entityManager): array
    {
        $trip = $booking->getTrip();
        $isReviewable = $booking->isConfirmed() && TripStatus::Completed === $trip->getStatus();
        $hasReviewed = $isReviewable && $reviews->exists($trip, $user, $trip->getDriver());

        return [
            'booking' => $booking,
            'isUpcoming' => $booking->isConfirmed()
                && TripStatus::Published === $trip->getStatus()
                && $booking->getBoardingStop()->getDepartureAt() > $now,
            'status' => self::statusOf($booking, $now),
            'payment' => $entityManager->getRepository(Payment::class)->findOneBy(['booking' => $booking]),
            'canCancel' => $booking->isConfirmed() && TripStatus::Published === $trip->getStatus() && $now <= $booking->getCancellationDeadline(),
            'canReview' => $isReviewable && !$hasReviewed && $now <= $trip->getCompletedAt()->modify('+14 days'),
            // État de l'avis, pour expliquer pourquoi le bouton n'est pas proposé.
            'review' => match (true) {
                !$isReviewable => null,
                $hasReviewed => 'done',
                $now > $trip->getCompletedAt()->modify('+14 days') => 'expired',
                default => 'open',
            },
        ];
    }

    /** Un seul état lisible par réservation, qui tient compte de l'état du trajet. */
    private static function statusOf(Booking $booking, \DateTimeImmutable $now): string
    {
        $trip = $booking->getTrip();

        return match (true) {
            TripStatus::Cancelled === $trip->getStatus() => 'Annulée par le conducteur',
            !$booking->isConfirmed() => 'Annulée',
            TripStatus::Completed === $trip->getStatus() => 'Effectuée',
            $booking->getBoardingStop()->getDepartureAt() <= $now => 'Départ passé',
            default => 'Confirmée',
        };
    }

    #[Route('/mes-reservations/{id}/annuler', name: 'app_booking_cancel', methods: ['POST'])]
    public function cancel(Booking $booking, Request $request, BookingService $bookingService): Response
    {
        if ($booking->getPassenger() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('cancel-booking-'.$booking->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        try {
            $bookingService->cancelByPassenger($booking, $this->getUser());
            $this->addFlash('success', 'Réservation annulée : vos places sont libérées et le paiement simulé est remboursé.');
        } catch (DomainRuleException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        // Depuis la page de la réservation, on y revient ; depuis la liste, on reste sur la liste.
        return 'show' === $request->request->getString('_back')
            ? $this->redirectToRoute('app_booking_show', ['id' => $booking->getId()])
            : $this->redirectToRoute('app_my_bookings');
    }

    #[Route('/notifications', name: 'app_notifications')]
    public function notifications(NotificationRepository $notifications): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $items = array_map(static fn ($notification) => ['notification' => $notification, 'isNew' => !$notification->isRead()], $notifications->findFor($user));
        $notifications->markAllRead($user);

        return $this->render('passenger/notifications.html.twig', ['items' => $items]);
    }

    /** Aperçu affiché sous la cloche : les dernières notifications, sans les marquer comme lues. */
    #[Route('/notifications/apercu', name: 'app_notifications_preview', methods: ['GET'])]
    public function notificationsPreview(NotificationRepository $notifications): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('passenger/notifications_preview.html.twig', [
            'items' => $notifications->findLatestFor($user, 5),
            'unread' => $notifications->countUnread($user),
        ]);
    }

    /** Ouverture d'une notification : marquée comme lue, puis la page qui la concerne. */
    #[Route('/notifications/{id}/ouvrir', name: 'app_notification_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function openNotification(Notification $notification, EntityManagerInterface $entityManager): Response
    {
        if ($notification->getRecipient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $notification->setIsRead(true);
        $entityManager->flush();

        $booking = $notification->getBooking();

        return match (true) {
            null === $booking => $this->redirectToRoute('app_notifications'),
            // Le passager retrouve sa réservation, le conducteur le trajet concerné dans son espace.
            $booking->getPassenger() === $this->getUser() => $this->redirectToRoute('app_booking_show', ['id' => $booking->getId()]),
            $booking->getTrip()->getDriver() === $this->getUser() => $this->redirect($this->generateUrl('app_driver').'#trajet-'.$booking->getTrip()->getId()),
            default => $this->redirectToRoute('app_notifications'),
        };
    }
}
