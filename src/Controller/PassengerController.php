<?php

namespace App\Controller;

use App\Entity\Booking;
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
 * Espace passager : réservations, annulations, avis à déposer et notifications.
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
        $items = [];

        foreach ($bookings->findForPassenger($user) as $booking) {
            $trip = $booking->getTrip();
            $items[] = [
                'booking' => $booking,
                'payment' => $entityManager->getRepository(Payment::class)->findOneBy(['booking' => $booking]),
                'canCancel' => $booking->isConfirmed() && TripStatus::Published === $trip->getStatus() && $now <= $booking->getCancellationDeadline(),
                'canReview' => $booking->isConfirmed()
                    && TripStatus::Completed === $trip->getStatus()
                    && $now <= $trip->getCompletedAt()->modify('+14 days')
                    && !$reviews->exists($trip, $user, $trip->getDriver()),
            ];
        }

        return $this->render('passenger/bookings.html.twig', ['items' => $items]);
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

        return $this->redirectToRoute('app_my_bookings');
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
}
