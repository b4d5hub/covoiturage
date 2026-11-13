<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Exception\DomainRuleException;
use App\Form\BookingRequestType;
use App\Repository\BookingRepository;
use App\Repository\ReviewRepository;
use App\Service\BookingService;
use App\Service\PortionPricing;
use App\Service\TripMapData;
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
        TripMapData $mapData,
    ): Response {
        $user = $this->getUser();
        $now = new \DateTimeImmutable();
        $stops = $trip->getStops()->toArray();
        usort($stops, static fn (TripStop $a, TripStop $b) => $a->getPosition() <=> $b->getPosition());

        $portions = [];

        foreach ($stops as $i => $boarding) {
            foreach (\array_slice($stops, $i + 1) as $alighting) {
                $portions[$boarding->getId().'-'.$alighting->getId()] = [
                    'boarding' => $boarding,
                    'alighting' => $alighting,
                    'seatPrice' => $pricing->seatPriceCents($trip, $boarding, $alighting),
                    'available' => $bookings->availableSeats($trip, $boarding, $alighting),
                    'open' => $boarding->getDepartureAt() > $now,
                ];
            }
        }

        $isOpen = TripStatus::Published === $trip->getStatus() && [] !== array_filter($portions, static fn (array $p) => $p['open']);
        // Une seule réservation active par passager et par trajet : la page montre celle qui existe déjà.
        $myBooking = $user instanceof User
            ? $bookings->findOneBy(['trip' => $trip, 'passenger' => $user, 'status' => BookingStatus::Confirmed])
            : null;
        $canBook = $isOpen && $user instanceof User && $user !== $trip->getDriver() && null === $myBooking;

        $stopRepository = $entityManager->getRepository(TripStop::class);
        // Sans étapes demandées, le formulaire propose le trajet entier depuis la première étape encore à venir.
        $firstOpen = array_values(array_filter(\array_slice($stops, 0, -1), static fn (TripStop $stop) => $stop->getDepartureAt() > $now))[0] ?? $stops[0];
        $form = $this->createForm(BookingRequestType::class, [
            'boarding' => $stopRepository->findOneBy(['id' => $request->query->getInt('depart'), 'trip' => $trip]) ?? $firstOpen,
            'alighting' => $stopRepository->findOneBy(['id' => $request->query->getInt('arrivee'), 'trip' => $trip]) ?? end($stops),
        ], ['trip' => $trip]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->denyAccessUnlessGranted('ROLE_USER');

            if ($form->isValid() && $canBook) {
                $data = $form->getData();

                try {
                    $booking = $bookingService->book($trip, $user, $data['boarding'], $data['alighting'], $data['seats']);
                    $this->addFlash('success', \sprintf(
                        'Réservation confirmée : %d place%s %s à %s. Paiement simulé de %d,%02d MAD, aucun montant réel n\'est débité.',
                        $booking->getSeats(),
                        $booking->getSeats() > 1 ? 's' : '',
                        self::fromCity((string) $booking->getBoardingStop()->getCity()),
                        $booking->getAlightingStop()->getCity(),
                        intdiv($booking->getTotalPriceCents(), 100),
                        $booking->getTotalPriceCents() % 100,
                    ));

                    return $this->redirectToRoute('app_booking_show', ['id' => $booking->getId()]);
                } catch (DomainRuleException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        $selection = $form->getData();
        $selected = $portions[(($selection['boarding'] ?? null)?->getId() ?? '').'-'.(($selection['alighting'] ?? null)?->getId() ?? '')] ?? null;

        return $this->render('trip/show.html.twig', [
            'trip' => $trip,
            'stops' => $stops,
            'portions' => $portions,
            'selected' => $selected,
            'form' => $form,
            'isOpen' => $isOpen,
            'canBook' => $canBook,
            'myBooking' => $myBooking,
            'passengers' => $user instanceof User && $user === $trip->getDriver() ? $bookings->findConfirmedForTrip($trip) : [],
            // Prix et places de chaque portion, pour le total affiché avant la réservation.
            'portionsData' => array_map(static fn (array $p) => ['seatPriceCents' => $p['seatPrice'], 'available' => $p['open'] ? $p['available'] : 0, 'open' => $p['open']], $portions),
            'driverRating' => $reviews->ratingOf($trip->getDriver()),
            'map' => $mapData->of($trip),
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? 422 : 200));
    }

    /** « de Marrakech », mais « d'Agadir » devant une voyelle. */
    private static function fromCity(string $city): string
    {
        return 1 === preg_match('/^[AEIOUYÉÈÊaeiouyéèê]/u', $city) ? 'd\''.$city : 'de '.$city;
    }
}
