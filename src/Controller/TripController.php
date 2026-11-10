<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\TripStatus;
use App\Exception\DomainRuleException;
use App\Form\BookingRequestType;
use App\Repository\BookingRepository;
use App\Repository\ReviewRepository;
use App\Service\BookingService;
use App\Service\PortionPricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TripController extends AbstractController
{
    #[Route('/trajets/{id}', name: 'app_trip_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(
        Trip $trip,
        Request $request,
        BookingService $bookingService,
        BookingRepository $bookings,
        ReviewRepository $reviews,
        PortionPricing $pricing,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        $now = new \DateTimeImmutable();
        $stops = $trip->getStops()->toArray();
        usort($stops, static fn (TripStop $a, TripStop $b) => $a->getPosition() <=> $b->getPosition());

        $portions = [];

        foreach ($stops as $i => $boarding) {
            foreach (\array_slice($stops, $i + 1) as $alighting) {
                $portions[] = [
                    'boarding' => $boarding,
                    'alighting' => $alighting,
                    'seatPrice' => $pricing->seatPriceCents($trip, $boarding, $alighting),
                    'available' => $bookings->availableSeats($trip, $boarding, $alighting),
                    'open' => $boarding->getDepartureAt() > $now,
                ];
            }
        }

        $isOpen = TripStatus::Published === $trip->getStatus() && [] !== array_filter($portions, static fn (array $p) => $p['open']);
        $canBook = $isOpen && $user instanceof User && $user !== $trip->getDriver();

        $stopRepository = $entityManager->getRepository(TripStop::class);
        $form = $this->createForm(BookingRequestType::class, [
            'boarding' => $stopRepository->findOneBy(['id' => $request->query->getInt('depart'), 'trip' => $trip]),
            'alighting' => $stopRepository->findOneBy(['id' => $request->query->getInt('arrivee'), 'trip' => $trip]),
        ], ['trip' => $trip]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->denyAccessUnlessGranted('ROLE_USER');

            if ($form->isValid() && $canBook) {
                $data = $form->getData();

                try {
                    $booking = $bookingService->book($trip, $user, $data['boarding'], $data['alighting'], $data['seats']);
                    $this->addFlash('success', \sprintf(
                        'Réservation confirmée : %d place(s) de %s à %s. Paiement simulé de %s, aucun montant réel n\'est débité.',
                        $booking->getSeats(),
                        $booking->getBoardingStop()->getCity(),
                        $booking->getAlightingStop()->getCity(),
                        number_format($booking->getTotalPriceCents() / 100, 2, ',', ' ').' MAD',
                    ));

                    return $this->redirectToRoute('app_my_bookings');
                } catch (DomainRuleException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('trip/show.html.twig', [
            'trip' => $trip,
            'stops' => $stops,
            'portions' => $portions,
            'form' => $form,
            'isOpen' => $isOpen,
            'canBook' => $canBook,
            'driverRating' => $reviews->ratingOf($trip->getDriver()),
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }
}
