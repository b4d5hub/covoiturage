<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Repository\BookingRepository;
use App\Repository\NotificationRepository;
use App\Repository\ReviewRepository;
use App\Repository\TripRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Profil public : prénom et initiale, notes et avis reçus. L'adresse e-mail n'est jamais affichée.
 */
final class ProfileController extends AbstractController
{
    #[Route('/profils/{id}', name: 'app_profile', requirements: ['id' => '\d+'])]
    public function show(User $user, ReviewRepository $reviews, TripRepository $trips, EntityManagerInterface $entityManager): Response
    {
        $tripsAsPassenger = (int) $entityManager->createQuery(
            'SELECT COUNT(b.id) FROM App\Entity\Booking b JOIN b.trip t WHERE b.passenger = :user AND b.status = :confirmed AND t.status = :completed',
        )->setParameters([
            'user' => $user,
            'confirmed' => BookingStatus::Confirmed,
            'completed' => TripStatus::Completed,
        ])->getSingleScalarResult();

        return $this->render('profile/show.html.twig', [
            'member' => $user,
            'rating' => $reviews->ratingOf($user),
            'reviews' => $reviews->findReceivedBy($user),
            'tripsAsDriver' => $trips->countForDriver($user, TripStatus::Completed),
            'tripsAsPassenger' => $tripsAsPassenger,
        ]);
    }

    /** Compte de l'utilisateur connecté : identité, adresse de connexion et raccourcis. Visible de lui seul. */
    #[Route('/mon-compte', name: 'app_account')]
    #[IsGranted('ROLE_USER')]
    public function account(ReviewRepository $reviews, TripRepository $trips, BookingRepository $bookings, NotificationRepository $notifications): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $now = new \DateTimeImmutable();
        $upcomingBookings = array_filter(
            $bookings->findForPassenger($user),
            static fn ($booking) => $booking->isConfirmed()
                && TripStatus::Published === $booking->getTrip()->getStatus()
                && $booking->getBoardingStop()->getDepartureAt() > $now,
        );
        $upcomingTrips = array_filter(
            $trips->findForDriver($user),
            static fn ($trip) => TripStatus::Published === $trip->getStatus() && $trip->getDepartureAt() > $now,
        );

        return $this->render('profile/account.html.twig', [
            'rating' => $reviews->ratingOf($user),
            'upcomingBookings' => \count($upcomingBookings),
            'upcomingTrips' => \count($upcomingTrips),
            'unread' => $notifications->countUnread($user),
        ]);
    }

    #[Route('/mon-profil', name: 'app_my_profile')]
    #[IsGranted('ROLE_USER')]
    public function mine(): Response
    {
        return $this->redirectToRoute('app_profile', ['id' => $this->getUser()->getId()]);
    }
}
